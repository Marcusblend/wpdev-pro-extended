<?php

declare(strict_types=1);

namespace ProExtended\Templates;

/**
 * Reads and writes Cornerstone's template library.
 *
 * Templates are `cs_template` posts (plus the legacy `cs_user_templates`) whose
 * post_content names an identifier and whose `_cs_template_data` meta holds the
 * content: `elements` for a block or document, `atts` for a preset. Everything
 * goes through Cornerstone's own Template class so that migrations run and the
 * data is stored exactly as the builder stores it.
 *
 * Nothing here creates a template for a type Cornerstone does not know:
 * Template::create() fatals on one, so the caller checks with
 * TemplateIdentifier::problems() first.
 */
final class TemplateGateway
{
    private const CLASS_NAME = '\Themeco\Cornerstone\Templating\Template';

    /**
     * Whether this site has the template library at all.
     */
    public function available(): bool
    {
        return function_exists('cornerstone') && class_exists(self::CLASS_NAME);
    }

    /**
     * Document types the resolver allows a template for.
     *
     * @return string[]
     */
    public function documentTypes(): array
    {
        if (! function_exists('cornerstone')) {
            return [];
        }

        try {
            $allowed = cornerstone('Resolver')->getAllowedDocTypes();
        } catch (\Throwable) {
            return [];
        }

        return is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : [];
    }

    /**
     * Every template, newest first, optionally narrowed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $identifier = null, ?string $search = null, int $limit = 200): array
    {
        $args = [
            'post_type'        => ['cs_template', 'cs_user_templates'],
            'post_status'      => ['tco-data', 'publish'],
            'orderby'          => 'title',
            'order'            => 'ASC',
            'posts_per_page'   => $limit,
            'cs_all_wpml'      => true,
            'suppress_filters' => false,
        ];

        if ($identifier !== null && $identifier !== '') {
            $args['meta_key'] = '_cs_template_identifier';
            $args['meta_value'] = $identifier;
        }

        if ($search !== null && $search !== '') {
            $args['s'] = $search;
        }

        $rows = [];

        foreach (get_posts(\ProExtended\Site\Languages::allLanguages($args)) as $post) {
            $row = $this->summarize($post);

            if ($row === null) {
                continue;
            }

            $language = \ProExtended\Site\Languages::forPost((int) $post->ID, $post->post_type);

            if ($language !== null) {
                $row += $language;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One template with its stored content.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            return null;
        }

        $row = $this->summarize($post);

        if ($row === null) {
            return null;
        }

        $template = $this->locate($post);

        if ($template !== null) {
            $serialized = $template->serializeFull();
            $row['content'] = is_array($serialized) ? ($serialized['meta'] ?? null) : null;
        }

        if (! isset($row['content']) || $row['content'] === null) {
            $row['content'] = $this->storedMeta($id);
        }

        return $row;
    }

    /**
     * Create a template.
     *
     * @param  array<string, mixed> $content The template's meta: elements for a
     *                                       block or document, atts for a preset.
     * @return array<string, mixed>
     */
    public function create(string $type, string $subType, string $title, array $content, string $preview = ''): array
    {
        $class = self::CLASS_NAME;
        $template = $class::create($type, $subType);

        if ($template === null) {
            throw new \RuntimeException(sprintf('Cornerstone would not build a "%s" template for "%s" on this site.', $type, $subType));
        }

        $template->setTitle($title);

        if ($preview !== '') {
            $template->setPreview($preview);
        }

        if ($content !== []) {
            $template->setMeta($content);
        }

        $saved = $template->save();
        $id = (int) $template->get_id();

        return [
            'id'         => $id,
            'title'      => $title,
            'identifier' => TemplateIdentifier::format($type, $subType),
            'kind'       => TemplateIdentifier::kind($type, $subType),
            'saved'      => is_array($saved),
        ];
    }

    /**
     * Delete a template permanently.
     */
    public function delete(int $id): bool
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post || ! in_array($post->post_type, ['cs_template', 'cs_user_templates'], true)) {
            return false;
        }

        $template = $this->locate($post);

        if ($template === null) {
            return (bool) wp_delete_post($id, true);
        }

        return (bool) $template->delete();
    }

    /**
     * A .tco archive of the given templates or documents, base64 encoded.
     *
     * Cornerstone's own exporter does the work, so the archive is byte-for-byte
     * the one the builder produces: dependencies, colours, fonts and components
     * resolved the same way.
     *
     * @param  int[] $ids
     * @return array{tco: string, bytes: int, group: string}
     */
    public function export(array $ids, string $group = 'template', bool $excludeThumbnails = true): array
    {
        if (! function_exists('cornerstone')) {
            throw new \RuntimeException('Cornerstone is not available on this site.');
        }

        $zip = cornerstone('Templates')
            ->createExport()
            ->setOption('excludeThumbnails', $excludeThumbnails)
            ->add($ids, $group)
            ->organize()
            ->archive();

        if (is_wp_error($zip)) {
            throw new \RuntimeException('The export failed: ' . $zip->get_error_message());
        }

        $path = (string) $zip;
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('The export produced no readable archive.');
        }

        return [
            'tco'   => base64_encode($contents),
            'bytes' => strlen($contents),
            'group' => $group,
        ];
    }

    /**
     * What a .tco archive holds, without writing anything.
     *
     * @return array{entries: array<int, array<string, mixed>>, files: string[]}
     */
    public function readArchive(string $path): array
    {
        if (! class_exists('\ZipArchive')) {
            throw new \RuntimeException('This site has no ZipArchive support, so a .tco cannot be read.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The file could not be opened as a .tco archive.');
        }

        $files = [];
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[] = $name;

            if (! str_ends_with(strtolower($name), '.json')) {
                continue;
            }

            $raw = $zip->getFromIndex($i);

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $entries[] = ['file' => $name, 'data' => $decoded];
            }
        }

        $zip->close();

        return ['entries' => $entries, 'files' => $files];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summarize(\WP_Post $post): ?array
    {
        $identifier = (string) get_post_meta($post->ID, '_cs_template_identifier', true);

        if ($identifier === '' && $post->post_type === 'cs_user_templates') {
            $identifier = TemplateIdentifier::format(TemplateIdentifier::ELEMENT, TemplateIdentifier::MULTI);
        }

        if ($identifier === '') {
            $decoded = json_decode((string) $post->post_content, true);
            $identifier = is_array($decoded) && is_string($decoded['identifier'] ?? null) ? $decoded['identifier'] : '';
        }

        if ($identifier === '') {
            return null;
        }

        try {
            $parts = TemplateIdentifier::parse($identifier);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return [
            'id'         => (int) $post->ID,
            'title'      => (string) $post->post_title,
            'identifier' => $identifier,
            'type'       => $parts['type'],
            'sub_type'   => $parts['sub_type'],
            'kind'       => TemplateIdentifier::kind($parts['type'], $parts['sub_type']),
            'legacy'     => $post->post_type === 'cs_user_templates',
            'modified'   => (string) $post->post_modified_gmt,
        ];
    }

    private function locate(\WP_Post $post): ?object
    {
        $class = self::CLASS_NAME;

        try {
            $template = $class::locate($post);
        } catch (\Throwable) {
            return null;
        }

        return is_object($template) ? $template : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedMeta(int $id): ?array
    {
        if (! function_exists('cs_get_serialized_post_meta')) {
            return null;
        }

        $meta = cs_get_serialized_post_meta($id, '_cs_template_data', true);

        return is_array($meta) ? $meta : null;
    }
}
