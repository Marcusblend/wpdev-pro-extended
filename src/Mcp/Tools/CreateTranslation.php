<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Site\Languages;
use ProExtended\Support\Args;

final class CreateTranslation implements ToolInterface, AnnotatedToolInterface
{
    /** Meta Cornerstone keeps per document, copied so the translation opens with the same content. */
    private const COPIED_META = ['_cornerstone_data', '_cornerstone_settings', '_cs_template_data'];

    public function __construct(private readonly DocumentGateway $gateway) {}

    public function name(): string
    {
        return 'create_translation';
    }

    public function description(): string
    {
        return 'Create a WPML translation of a Cornerstone page or document: a copy in the target language, joined to the source\'s translation group so WPML treats them as the same content. The copy starts as a draft with the source\'s elements and settings, ready to have its text translated. Only works while WPML is active; get_site_info reports the site\'s languages. Run with dry_run: true first.';
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
                    'description' => 'Optional. Default: draft.',
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

        // And publishing the copy is publishing, whatever it is a copy of.
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

        $newTitle = $title ?? $post->post_title;

        if ($dryRun) {
            return [
                'dry_run'         => true,
                'source_post_id'  => $postId,
                'source_language' => $existing['language'],
                'language'        => $language,
                'title'           => $newTitle,
                'status'          => $status,
                'doc_type'        => $this->gateway->docTypeForPost($post),
                'would_copy'      => array_values(array_filter(self::COPIED_META, static fn (string $key): bool => get_post_meta($postId, $key, true) !== '')),
            ];
        }

        $newId = wp_insert_post([
            'post_title'   => $newTitle,
            'post_type'    => $post->post_type,
            'post_status'  => $post->post_type === 'page' || $post->post_type === 'post' ? $status : $post->post_status,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_parent'  => $post->post_parent,
            'menu_order'   => $post->menu_order,
        ], true);

        if (is_wp_error($newId)) {
            throw new \RuntimeException('The translation could not be created: ' . $newId->get_error_message());
        }

        $newId = (int) $newId;
        $copied = [];

        foreach (self::COPIED_META as $key) {
            $value = get_post_meta($postId, $key, true);

            if ($value === '' || $value === false) {
                continue;
            }

            update_post_meta($newId, $key, wp_slash($value));
            $copied[] = $key;
        }

        $trid = apply_filters('wpml_element_trid', null, $postId, 'post_' . $post->post_type);

        do_action('wpml_set_element_language_details', [
            'element_id'           => $newId,
            'element_type'         => 'post_' . $post->post_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $existing['language'],
        ]);

        $after = Languages::forPost($newId, $post->post_type) ?? [];

        return [
            'created'         => true,
            'post_id'         => $newId,
            'source_post_id'  => $postId,
            'source_language' => $existing['language'],
            'language'        => $after['language'] ?? $language,
            'joined'          => ($after['language'] ?? null) === $language,
            'title'           => $newTitle,
            'copied_meta'     => $copied,
            'edit_url'        => get_edit_post_link($newId, 'raw'),
        ];
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
