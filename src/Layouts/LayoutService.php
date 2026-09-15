<?php

declare(strict_types=1);

namespace ProExtended\Layouts;

/**
 * Service for reading, writing, backing up, and restoring Cornerstone layout data.
 *
 * Handles all three Cornerstone storage formats:
 *   - Pages:         `_cornerstone_data` post meta (JSON string)
 *   - Layouts:       `post_content` JSON with `regions.*` nested tree
 *   - Global Blocks: `post_content` JSON with `elements` flat map
 *
 * Critical patterns:
 *   - `update_post_meta()` calls `wp_unslash()` internally, corrupting JSON.
 *     Always use `wp_slash(wp_json_encode($data))` before saving.
 *   - Raw backups use `$wpdb` directly to preserve exact encoding.
 *   - `_bp_data` values MUST be sequential 5-element arrays (null-padded).
 */
final class LayoutService
{
    /**
     * Maximum number of backups retained per post.
     */
    public const MAX_BACKUPS = 10;

    /**
     * Cornerstone layout post types.
     */
    public const LAYOUT_POST_TYPES = [
        'cs_header',
        'cs_footer',
        'cs_layout_single',
        'cs_layout_archive',
        'cs_global_block',
    ];

    /**
     * All post types that can have Cornerstone data.
     */
    public const ALL_POST_TYPES = [
        'page',
        'post',
        ...self::LAYOUT_POST_TYPES,
    ];

    /**
     * Get Cornerstone layout data for a post.
     *
     * @return array{
     *     post_id: int,
     *     post_title: string,
     *     post_name: string,
     *     post_type: string,
     *     post_status: string,
     *     source: string,
     *     data: mixed,
     *     checksum: string,
     * }
     *
     * @throws \InvalidArgumentException If the post does not exist.
     * @throws \RuntimeException         If no Cornerstone data is found.
     */
    public function get(int $postId): array
    {
        $post = get_post($postId);

        if (! $post) {
            throw new \InvalidArgumentException(
                sprintf('Post %d does not exist.', $postId)
            );
        }

        $source = $this->detectSource($post);
        $data   = $this->readData($post, $source);

        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'post_id'     => $post->ID,
            'post_title'  => $post->post_title,
            'post_name'   => $post->post_name,
            'post_type'   => $post->post_type,
            'post_status' => $post->post_status,
            'source'      => $source,
            'data'        => $data,
            'checksum'    => hash('sha256', $json ?: ''),
        ];
    }

    /**
     * Save Cornerstone layout data to a post.
     *
     * Uses `wp_slash()` to prevent `update_post_meta()` from corrupting JSON.
     */
    public function save(int $postId, mixed $data): bool
    {
        $post = get_post($postId);

        if (! $post) {
            throw new \InvalidArgumentException(
                sprintf('Post %d does not exist.', $postId)
            );
        }

        $source = $this->detectSource($post);

        return $this->writeData($post, $source, $data);
    }

    /**
     * Create a timestamped backup of Cornerstone data.
     *
     * Uses `$wpdb` directly to preserve exact encoding.
     *
     * @return string Backup ID (timestamp).
     */
    public function backup(int $postId): string
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $post = get_post($postId);

        if (! $post) {
            throw new \InvalidArgumentException(
                sprintf('Post %d does not exist.', $postId)
            );
        }

        $source = $this->detectSource($post);

        if ($source === 'post_meta') {
            // Read raw meta value via $wpdb to preserve exact encoding.
            $rawData = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_cornerstone_data' LIMIT 1",
                $postId
            ));
        } else {
            $rawData = $post->post_content;
        }

        if ($rawData === null || $rawData === '') {
            throw new \RuntimeException(
                sprintf('No Cornerstone data to back up for post %d.', $postId)
            );
        }

        $backups = get_post_meta($postId, '_pe_layout_backups', true);
        if (! is_array($backups)) {
            $backups = [];
        }

        $backupId = $this->uniqueBackupId($backups);

        $backups[$backupId] = [
            'source'     => $source,
            'data'       => $rawData,
            'created_at' => gmdate('c'),
        ];

        // Keep only the most recent backups.
        if (count($backups) > self::MAX_BACKUPS) {
            $backups = array_slice($backups, -self::MAX_BACKUPS, null, true);
        }

        // CRITICAL: update_post_meta() runs wp_unslash() over the value, which
        // strips the escaping out of the raw JSON held in each backup and makes
        // it unparseable on restore. wp_slash() cancels that out.
        update_post_meta($postId, '_pe_layout_backups', wp_slash($backups));

        return $backupId;
    }

    /**
     * Restore Cornerstone data from a backup.
     *
     * @param string|null $backupId Specific backup ID, or null for latest.
     */
    public function restore(int $postId, ?string $backupId = null): bool
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (! get_post($postId)) {
            throw new \InvalidArgumentException(
                sprintf('Post %d does not exist.', $postId)
            );
        }

        $backups = get_post_meta($postId, '_pe_layout_backups', true);

        if (! is_array($backups) || empty($backups)) {
            throw new \RuntimeException(
                sprintf('No backups found for post %d.', $postId)
            );
        }

        if ($backupId === null) {
            // Use the latest backup.
            $backupId = (string) array_key_last($backups);
        }

        if (! isset($backups[$backupId])) {
            throw new \RuntimeException(
                sprintf('Backup "%s" not found for post %d.', $backupId, $postId)
            );
        }

        $backup = $backups[$backupId];
        $rawData = $backup['data'];
        $source  = $backup['source'];

        if ($source === 'post_meta') {
            // Write directly via $wpdb to preserve exact encoding. $wpdb->update()
            // only touches an existing row, so insert when the meta is absent —
            // otherwise restoring onto a post whose data was deleted silently
            // does nothing and still reports success.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_cornerstone_data'",
                $postId
            ));

            if ($exists > 0) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => $rawData],
                    ['post_id' => $postId, 'meta_key' => '_cornerstone_data'],
                    ['%s'],
                    ['%d', '%s']
                );
            } else {
                $wpdb->insert(
                    $wpdb->postmeta,
                    [
                        'post_id'    => $postId,
                        'meta_key'   => '_cornerstone_data',
                        'meta_value' => $rawData,
                    ],
                    ['%d', '%s', '%s']
                );
            }

            // $wpdb bypasses the object cache, so the old value would keep being
            // served from it on sites running Redis/Memcached.
            wp_cache_delete($postId, 'post_meta');

            // post_content holds the compiled HTML that Cornerstone actually
            // renders. Restoring the builder data alone leaves the *previous*
            // layout on the front end while the builder shows the restored one.
            $this->renderToPostContent($postId);
        } else {
            $wpdb->update(
                $wpdb->posts,
                ['post_content' => $rawData],
                ['ID' => $postId],
                ['%s'],
                ['%d']
            );
            clean_post_cache($postId);
        }

        // Clear TSS cache.
        delete_post_meta($postId, '_cs_generated_tss');

        return true;
    }

    /**
     * List all posts with Cornerstone data.
     *
     * @param string[] $types Post types to query.
     * @return array<int, array{id: int, title: string, type: string, status: string, modified: string}>
     */
    public function listAll(array $types = []): array
    {
        if (empty($types)) {
            $types = self::ALL_POST_TYPES;
        }

        $results = [];

        // Layout post types (stored in post_content).
        $layoutTypes = array_intersect($types, self::LAYOUT_POST_TYPES);

        if (! empty($layoutTypes)) {
            $posts = get_posts([
                'post_type'      => $layoutTypes,
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            foreach ($posts as $post) {
                $results[] = [
                    'id'       => $post->ID,
                    'title'    => $post->post_title,
                    'type'     => $post->post_type,
                    'status'   => $post->post_status,
                    'modified' => $post->post_modified_gmt,
                ];
            }
        }

        // Pages/posts with _cornerstone_data meta.
        $contentTypes = array_intersect($types, ['page', 'post']);

        if (! empty($contentTypes)) {
            $posts = get_posts([
                'post_type'      => $contentTypes,
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => -1,
                'meta_key'       => '_cornerstone_data',
                'meta_compare'   => 'EXISTS',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            foreach ($posts as $post) {
                $results[] = [
                    'id'       => $post->ID,
                    'title'    => $post->post_title,
                    'type'     => $post->post_type,
                    'status'   => $post->post_status,
                    'modified' => $post->post_modified_gmt,
                ];
            }
        }

        return $results;
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    /**
     * Build a backup ID that does not collide with an existing one.
     *
     * time() has one-second resolution, so a deploy and an update landing in the
     * same second would otherwise overwrite each other's restore point.
     *
     * @param array<string|int, mixed> $existing
     */
    private function uniqueBackupId(array $existing): string
    {
        $base = (string) time();
        $id = $base;
        $suffix = 1;

        while (isset($existing[$id])) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }

        return $id;
    }

    /**
     * Compile a post's layout data into rendered HTML and store it in post_content.
     *
     * Cornerstone's FrontEnd service looks for the "<!-- cs-content -->" prefix in
     * post_content to decide whether to render the page.
     */
    private function renderToPostContent(int $postId): void
    {
        if (! function_exists('cs_render_document_html_with_comment')) {
            return;
        }

        $renderedHtml = cs_render_document_html_with_comment($postId);

        if (empty($renderedHtml)) {
            return;
        }

        wp_update_post([
            'ID'           => $postId,
            'post_content' => wp_slash($renderedHtml),
        ]);
    }

    /**
     * Detect which storage format a post uses.
     *
     * @return 'post_meta'|'post_content'
     */
    private function detectSource(\WP_Post $post): string
    {
        if (in_array($post->post_type, self::LAYOUT_POST_TYPES, true)) {
            return 'post_content';
        }

        return 'post_meta';
    }

    /**
     * Read Cornerstone data from the appropriate source.
     */
    private function readData(\WP_Post $post, string $source): mixed
    {
        if ($source === 'post_meta') {
            $raw = get_post_meta($post->ID, '_cornerstone_data', true);

            if (empty($raw)) {
                throw new \RuntimeException(
                    sprintf('No Cornerstone data found in _cornerstone_data for post %d.', $post->ID)
                );
            }

            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    sprintf('Invalid JSON in _cornerstone_data for post %d: %s', $post->ID, json_last_error_msg())
                );
            }

            return $data;
        }

        // post_content source
        $raw = $post->post_content;

        if (empty($raw)) {
            throw new \RuntimeException(
                sprintf('No Cornerstone data found in post_content for post %d.', $post->ID)
            );
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('Invalid JSON in post_content for post %d: %s', $post->ID, json_last_error_msg())
            );
        }

        return $data;
    }

    /**
     * Write Cornerstone data to the appropriate source.
     */
    private function writeData(\WP_Post $post, string $source, mixed $data): bool
    {
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode layout data as JSON.');
        }

        if ($source === 'post_meta') {
            // CRITICAL: wp_slash() prevents update_post_meta() from corrupting JSON.
            $existing = get_post_meta($post->ID, '_cornerstone_data', true);
            $result = update_post_meta($post->ID, '_cornerstone_data', wp_slash($json));

            // update_post_meta() returns false both on failure and when the value
            // is unchanged. Deploying an identical layout is a success, not a
            // failure, so disambiguate the two.
            if ($result === false) {
                $result = ($existing === $json);
            }

            // Ensure Cornerstone settings meta exists — this is required for rendering.
            // Without it, Cornerstone won't recognize the page as a Cornerstone page.
            $existingSettings = get_post_meta($post->ID, '_cornerstone_settings', true);
            if (empty($existingSettings)) {
                $defaultSettings = wp_json_encode([
                    'customCSS'       => '',
                    'customJS'        => '',
                    'layoutSingle'    => 'default',
                    'layoutHeader'    => 'default',
                    'layoutFooter'    => 'default',
                    'responsive_text' => [],
                ]);
                update_post_meta($post->ID, '_cornerstone_settings', wp_slash($defaultSettings));
            }

            // Ensure page template is set for Pro theme rendering.
            $currentTemplate = get_post_meta($post->ID, '_wp_page_template', true);
            if (empty($currentTemplate) || $currentTemplate === 'default') {
                update_post_meta($post->ID, '_wp_page_template', 'template-blank-4.php');
            }

            // Compile layout data into rendered HTML and store in post_content.
            $this->renderToPostContent($post->ID);

            // Track last save timestamp.
            update_post_meta($post->ID, '_cs_last_save', time());
        } else {
            $result = wp_update_post([
                'ID'           => $post->ID,
                'post_content' => wp_slash($json),
            ]);
            $result = ($result !== 0 && ! is_wp_error($result));
        }

        // Clear TSS cache so Cornerstone regenerates compiled CSS.
        delete_post_meta($post->ID, '_cs_generated_tss');

        return (bool) $result;
    }
}
