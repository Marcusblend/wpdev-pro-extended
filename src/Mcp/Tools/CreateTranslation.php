<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Site\Languages;
use ProExtended\Support\Args;

final class CreateTranslation implements ToolInterface, AnnotatedToolInterface
{
    /**
     * Cornerstone meta that is not copied: caches Cornerstone rebuilds on the
     * next render or save, and records that belong to the one post they were
     * written for. Every other `_cornerstone_*` and `_cs_*` key the source
     * holds is copied, so a key a later Cornerstone release adds travels with
     * the translation without this list having to learn it.
     */
    public const NOT_COPIED_META = [
        '_cs_generated_styles',  // styles cache
        '_cs_generated_tss',     // styles cache
        '_cs_component_map',     // component usage cache
        '_cs_states_cache',      // post-states cache (PostStates::META_STATE_KEY)
        '_cs_last_save',         // when this post was last saved in the builder
        '_cs_import',            // the signature the template importer finds this post by
        '_cs_attachment_import', // the same, for imported media
    ];

    /** WordPress meta copied with Cornerstone's. The featured image is mapped separately. */
    public const COPIED_WP_META = ['_wp_page_template'];

    /**
     * Cornerstone keeps its own documents (headers, footers, layouts,
     * components, templates) under the `tco-data` status, which is what
     * "published" means for them; Document::getPublishStatusType().
     */
    public const DOCUMENT_LIVE_STATUS = 'tco-data';

    public function __construct(private readonly DocumentGateway $gateway) {}

    public function name(): string
    {
        return 'create_translation';
    }

    public function description(): string
    {
        return 'Create a WPML translation of a Cornerstone page or document: a copy in the target language, joined to the source\'s translation group so WPML treats them as the same content. The copy carries the source\'s elements, settings, Cornerstone meta, page template, featured image and terms (each term and the image swapped for its target-language translation where WPML has one), ready to have its text translated. It starts as a draft whatever the source\'s status; a draft header, footer, layout or component is not assigned or rendered until it is published (status: "publish", or a save in Cornerstone). Publishing needs the post type\'s publish capability. Only works while WPML is active; get_site_info reports the site\'s languages. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id', 'language'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The page or document to translate.',
                ],
                'language' => [
                    'type'        => 'string',
                    'description' => 'Target language code, as get_site_info reports it (for example "es").',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Optional. Title for the translation. Default: the source title.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['draft', 'publish'],
                    'description' => 'Optional. Default: draft, for every post type. "publish" needs the post type\'s publish capability; for a Cornerstone document it stores the document live (Cornerstone\'s tco-data status).',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be created and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['post_id', 'language', 'title', 'status', 'dry_run'], 'arguments');

        if (! Languages::active()) {
            throw new \RuntimeException('WPML is not active on this site, so there are no translations to create.');
        }

        $postId = Args::int($arguments, 'post_id', null, 1) ?? 0;
        $language = (string) Args::string($arguments, 'language', null, 20, false);
        $title = Args::string($arguments, 'title', null, 200);
        $status = (string) (Args::string($arguments, 'status', 'draft', 20) ?? 'draft');
        $dryRun = Args::bool($arguments, 'dry_run', false);

        if (! in_array($status, ['draft', 'publish'], true)) {
            throw new \InvalidArgumentException('status must be "draft" or "publish".');
        }

        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $postId));
        }

        // A translation copies the source's content, layout data and settings
        // wholesale, so it hands the caller everything in the source. Being
        // able to edit that particular post is the price of copying it —
        // `edit_posts` alone would let anyone duplicate a private draft.
        if (! current_user_can('edit_post', $postId)) {
            throw new ToolPermissionException(sprintf(
                'You do not have permission to edit post %d, so it cannot be translated.',
                $postId
            ));
        }

        // And publishing the copy is publishing, whatever it is a copy of. The
        // copy's status comes from `status` for every post type — never from
        // the source — so this check is the only way a live copy is made.
        if ($status === 'publish') {
            $typeObject = get_post_type_object($post->post_type);
            $publishCap = is_object($typeObject) && isset($typeObject->cap->publish_posts)
                ? (string) $typeObject->cap->publish_posts
                : 'publish_posts';

            if (! current_user_can($publishCap)) {
                throw new ToolPermissionException(sprintf(
                    'Publishing a translation requires the "%s" capability. Create it as a draft instead.',
                    $publishCap
                ));
            }
        }

        // A Cornerstone document keeps its JSON in post_content, which
        // WordPress runs through kses for a user without unfiltered_html; a
        // page built in Cornerstone carries the source's custom CSS, JS and
        // Raw Content in its meta. Both are refused the way every other
        // document write is.
        if (self::isDocumentType($post->post_type) || get_post_meta($postId, '_cornerstone_data', true) !== '') {
            $this->gateway->assertCanWriteDocuments();
        }

        // Cornerstone's own permission for what is being copied, beside the
        // WordPress capabilities above: a role can keep edit_posts and lose
        // Cornerstone's layout or component access, and a translation must
        // not write what the builder would refuse the same user.
        $cornerstoneKey = self::cornerstonePermission($post->post_type);

        if ($cornerstoneKey !== null && (new \ProExtended\Cornerstone\Permissions())->userCan($cornerstoneKey) === false) {
            throw new ToolPermissionException(sprintf(
                'Cornerstone\'s "%s" permission is off for your role, so this %s cannot be translated.',
                $cornerstoneKey,
                $post->post_type
            ));
        }

        $report = Languages::report();
        $codes = array_column($report['languages'] ?? [], 'code');

        if ($codes !== [] && ! in_array($language, $codes, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a language on this site (%s).', $language, implode(', ', $codes)));
        }

        $existing = Languages::forPost($postId, $post->post_type) ?? ['language' => null, 'translations' => []];

        foreach ($existing['translations'] as $translation) {
            if (($translation['language'] ?? null) === $language) {
                throw new \InvalidArgumentException(sprintf(
                    'Post %d already has a "%s" translation: post %d. Edit that one instead.',
                    $postId,
                    $language,
                    (int) $translation['post_id']
                ));
            }
        }

        if ($existing['language'] === $language) {
            throw new \InvalidArgumentException(sprintf('Post %d is already in "%s".', $postId, $language));
        }

        // Without a translation group there is nothing to join: WPML would
        // start a new group for the copy and leave it unconnected to the
        // source. Cornerstone's own translation endpoint refuses the same case.
        $trid = apply_filters('wpml_element_trid', null, $postId, 'post_' . $post->post_type);

        if (empty($trid)) {
            throw new \InvalidArgumentException(sprintf(
                'WPML has no translation group for post %d, so a copy could not be joined to it. Either WPML is not set to translate "%s" posts, or the post has not been saved since WPML was activated. Nothing was created.',
                $postId,
                $post->post_type
            ));
        }

        $newTitle = $title ?? $post->post_title;
        $postStatus = self::postStatus($post->post_type, $status);
        $metaKeys = self::copiedMetaKeys(array_keys((array) get_post_meta($postId)));

        if ($dryRun) {
            return [
                'dry_run'         => true,
                'source_post_id'  => $postId,
                'source_language' => $existing['language'],
                'language'        => $language,
                'title'           => $newTitle,
                'status'          => $status,
                'post_status'     => $postStatus,
                'doc_type'        => $this->gateway->docTypeForPost($post),
                'would_copy'      => array_values(array_filter($metaKeys, static fn (string $key): bool => get_post_meta($postId, $key, true) !== '')),
                'featured_image'  => (int) get_post_thumbnail_id($post) ?: null,
                'terms'           => $this->sourceTerms($post),
            ];
        }

        // WPML's own duplication (the `wpml_make_post_duplicates` action) is
        // not used, for three reasons:
        //  - It makes a WPML *duplicate*, not a translation: WPML marks the
        //    copy as a duplicate of the original and overwrites it from the
        //    original every time the original is saved, until someone turns
        //    it into an independent translation in WPML's editor. A copy made
        //    to have its text translated would lose that text on the next
        //    save of the source.
        //  - What it does with each meta key depends on the site's WPML
        //    custom-field settings and version, and nothing that can be
        //    checked without a WPML install confirms it re-slashes values the
        //    way Cornerstone's JSON needs (Cornerstone writes them through
        //    cs_update_serialized_post_meta(), which calls wp_slash()).
        //  - Cornerstone does not rely on it either: its own translation
        //    endpoint (Services\Wpml::createTranslation) inserts the post
        //    itself and joins it to the group with
        //    set_element_language_details, as this tool does. It clones the
        //    document through Document::update(['clone' => ...])->save(),
        //    which cannot be used here because save() forces a document live
        //    (tco-data) and a page to the source's status.
        // So the copy is made by hand, through the same slash-and-guard path
        // as every other raw document write.
        $newId = $this->gateway->insertRaw($post->post_content, self::postFields($post, $newTitle, $postStatus, $this->translatedParent($post, $language)));

        do_action('wpml_set_element_language_details', [
            'element_id'           => $newId,
            'element_type'         => 'post_' . $post->post_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $existing['language'],
        ]);

        // Meta and terms go on after the language is set, so WPML already
        // knows which language the post is in when their hooks run.
        $copied = [];

        foreach ($metaKeys as $key) {
            $value = get_post_meta($postId, $key, true);

            if ($value === '' || $value === false) {
                continue;
            }

            update_post_meta($newId, $key, wp_slash($value));
            $copied[] = $key;
        }

        $thumbnail = $this->copyThumbnail($post, $newId, $language);
        $terms = $this->copyTerms($post, $newId, $language);

        $after = Languages::forPost($newId, $post->post_type) ?? [];

        return [
            'created'         => true,
            'post_id'         => $newId,
            'source_post_id'  => $postId,
            'source_language' => $existing['language'],
            'language'        => $after['language'] ?? $language,
            'joined'          => ($after['language'] ?? null) === $language,
            'title'           => $newTitle,
            'post_status'     => $postStatus,
            'copied_meta'     => $copied,
            'featured_image'  => $thumbnail,
            'terms'           => $terms,
            'edit_url'        => get_edit_post_link($newId, 'raw'),
        ];
    }

    /**
     * Whether a post type is one of Cornerstone's own documents.
     */
    /**
     * The Cornerstone permission that governs a post type, or null when
     * Cornerstone has none for it.
     */
    public static function cornerstonePermission(string $postType): ?string
    {
        return match (true) {
            $postType === 'cs_global_block'   => 'component',
            str_starts_with($postType, 'cs_') => 'layout',
            $postType === 'page'              => 'content.page',
            $postType === 'post'              => 'content.post',
            default                           => null,
        };
    }

    public static function isDocumentType(string $postType): bool
    {
        return str_starts_with($postType, 'cs_');
    }

    /**
     * The post_status a translation is stored with.
     *
     * Drafts stay drafts for every post type. A published Cornerstone
     * document is stored under `tco-data`, which is the only status
     * Cornerstone assigns and renders documents from.
     */
    public static function postStatus(string $postType, string $requested): string
    {
        if ($requested !== 'publish') {
            return 'draft';
        }

        return self::isDocumentType($postType) ? self::DOCUMENT_LIVE_STATUS : 'publish';
    }

    /**
     * Which of the source's meta keys a translation copies: every Cornerstone
     * key except the caches and per-post records, plus the page template.
     *
     * @param  array<int|string, mixed> $keys The source's meta keys.
     * @return string[]
     */
    public static function copiedMetaKeys(array $keys): array
    {
        $copied = [];

        foreach ($keys as $key) {
            if (! is_string($key) || in_array($key, self::NOT_COPIED_META, true)) {
                continue;
            }

            if (str_starts_with($key, '_cornerstone_') || str_starts_with($key, '_cs_') || in_array($key, self::COPIED_WP_META, true)) {
                $copied[] = $key;
            }
        }

        $copied = array_values(array_unique($copied));
        sort($copied);

        return $copied;
    }

    /**
     * Swap each id for its translation where one exists, keeping the source's
     * id where it does not.
     *
     * @param  int[]                    $ids
     * @param  callable(int): mixed     $lookup Returns the translated id, or null/0 when there is none.
     * @return array{ids: int[], translated: int}
     */
    public static function translateIds(array $ids, callable $lookup): array
    {
        $out = [];
        $translated = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            $match = $lookup($id);
            $match = is_numeric($match) ? (int) $match : 0;

            if ($match > 0 && $match !== $id) {
                $out[] = $match;
                $translated++;
            } else {
                $out[] = $id;
            }
        }

        return ['ids' => array_values(array_unique($out)), 'translated' => $translated];
    }

    /**
     * The post fields of the translation, slashed for wp_insert_post(); the
     * content is added and slashed by DocumentGateway::insertRaw().
     *
     * @return array<string, mixed>
     */
    public static function postFields(object $source, string $title, string $postStatus, int $parent): array
    {
        return [
            'post_title'   => wp_slash($title),
            'post_type'    => (string) $source->post_type,
            'post_status'  => $postStatus,
            'post_excerpt' => wp_slash((string) $source->post_excerpt),
            'post_parent'  => $parent,
            'menu_order'   => (int) $source->menu_order,
        ];
    }

    /**
     * The source's parent, or its translation in the target language.
     */
    private function translatedParent(\WP_Post $post, string $language): int
    {
        $parent = (int) $post->post_parent;

        if ($parent <= 0) {
            return 0;
        }

        return self::translateIds([$parent], static fn (int $id): mixed => apply_filters('wpml_object_id', $id, $post->post_type, false, $language))['ids'][0] ?? $parent;
    }

    /**
     * Set the source's featured image on the translation, swapped for the
     * image's own translation when WPML Media has made one.
     */
    private function copyThumbnail(\WP_Post $post, int $newId, string $language): ?int
    {
        $thumbnail = (int) get_post_thumbnail_id($post);

        if ($thumbnail <= 0) {
            return null;
        }

        $thumbnail = self::translateIds([$thumbnail], static fn (int $id): mixed => apply_filters('wpml_object_id', $id, 'attachment', false, $language))['ids'][0] ?? $thumbnail;

        update_post_meta($newId, '_thumbnail_id', $thumbnail);

        return $thumbnail;
    }

    /**
     * The source's term ids, by taxonomy.
     *
     * @return array<string, int[]>
     */
    private function sourceTerms(\WP_Post $post): array
    {
        $terms = [];

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $ids = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);

            if (is_array($ids) && $ids !== []) {
                $terms[(string) $taxonomy] = array_map('intval', $ids);
            }
        }

        return $terms;
    }

    /**
     * Give the translation the source's terms: each term's translation in the
     * target language where WPML has one, the source's own term where not.
     *
     * @return array<string, array{ids: int[], translated: int}>
     */
    private function copyTerms(\WP_Post $post, int $newId, string $language): array
    {
        $copied = [];

        foreach ($this->sourceTerms($post) as $taxonomy => $ids) {
            $mapped = self::translateIds($ids, static fn (int $id): mixed => apply_filters('wpml_object_id', $id, $taxonomy, false, $language));
            $result = wp_set_object_terms($newId, $mapped['ids'], $taxonomy);

            if (! is_wp_error($result)) {
                $copied[$taxonomy] = $mapped;
            }
        }

        return $copied;
    }

    public function annotations(): array
    {
        return Annotations::write('Create Translation', false, true);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
