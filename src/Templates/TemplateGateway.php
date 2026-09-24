<?php

declare(strict_types=1);

namespace ProExtended\Templates;

use ProExtended\Cornerstone\DocumentSettings;
use ProExtended\Elements\ElementTree;
use ProExtended\Mcp\ToolPermissionException;

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
        self::assertMayStoreCode($content);

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

    /** The largest a single member may expand to. */
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;

    /** The largest everything in one archive may expand to together. */
    public const MAX_EXTRACTED_BYTES = 32 * 1024 * 1024;

    /**
     * What a .tco archive holds, without writing anything.
     *
     * Only the describe half of an import: the write is Cornerstone's own
     * cs_import_tco(), which extracts every member itself, so every member —
     * images as well as JSON — is held to the size limits here first. The
     * JSON members are decoded (the manifest separately) and SVG members are
     * returned raw so the caller can validate them; other members are only
     * measured.
     *
     * @return array{files: string[], manifest: array<mixed>|null, entries: array<string, mixed>, svg: array<string, string>, bytes: int}
     */
    public static function readArchive(string $path): array
    {
        if (! class_exists('\ZipArchive')) {
            throw new \RuntimeException('This site has no ZipArchive support, so a .tco cannot be read.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The file could not be opened as a .tco archive.');
        }

        $tooLarge = static function () use ($zip): \RuntimeException {
            $zip->close();

            return new \RuntimeException(sprintf(
                'The archive expands to more than %d MB, or holds a file over %d MB, so it was not read.',
                (int) (self::MAX_EXTRACTED_BYTES / (1024 * 1024)),
                (int) (self::MAX_ENTRY_BYTES / (1024 * 1024))
            ));
        };

        $files = [];
        $manifest = null;
        $entries = [];
        $svg = [];
        $extracted = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[] = $name;

            // The caller's size limit is on the *compressed* file, which says
            // nothing about what it expands to: a few hundred KB of zeros
            // inflates to gigabytes and takes the request down with it. Check
            // the declared size of every member before anything is extracted
            // — here or by Cornerstone's importer afterwards — and keep a
            // running total so a thousand small members cannot add up to the
            // same thing.
            $stat = $zip->statIndex($i);
            $declared = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

            if ($declared > self::MAX_ENTRY_BYTES || $extracted + $declared > self::MAX_EXTRACTED_BYTES) {
                throw $tooLarge();
            }

            $lower = strtolower($name);
            $isJson = str_ends_with($lower, '.json');
            $isSvg = str_ends_with($lower, '.svg');

            if (! $isJson && ! $isSvg) {
                $extracted += $declared;
                continue;
            }

            $raw = $zip->getFromIndex($i);
            $raw = is_string($raw) ? $raw : '';

            // statIndex() reports what the archive claims; this is what it
            // actually produced.
            $extracted += max($declared, strlen($raw));

            if (strlen($raw) > self::MAX_ENTRY_BYTES || $extracted > self::MAX_EXTRACTED_BYTES) {
                throw $tooLarge();
            }

            if ($isSvg) {
                $svg[$name] = $raw;
                continue;
            }

            $decoded = $raw === '' ? null : json_decode($raw, true);

            if (! is_array($decoded)) {
                continue;
            }

            if ($name === 'manifest.json') {
                $manifest = $decoded;
            } else {
                $entries[$name] = $decoded;
            }
        }

        $zip->close();

        return ['files' => $files, 'manifest' => $manifest, 'entries' => $entries, 'svg' => $svg, 'bytes' => $extracted];
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

    /**
     * Refuse to store code the author is not trusted to write.
     *
     * A template is applied later, by whoever opens the library, so code inside
     * one runs with that person's privileges rather than the author's.
     * `create_document` refuses customCSS, customJS and Raw Content from a user
     * without `unfiltered_html`; anything that lands in the same document has
     * to be held to the same rule, or the template library becomes the way
     * around it. The check lives here rather than in the tools so that every
     * caller passes it — `create_template`, `import_tco`, and whatever is
     * written next.
     *
     * @param array<string, mixed> $content
     *
     * @throws ToolPermissionException
     */
    public static function assertMayStoreCode(array $content): void
    {
        $reason = self::codeReason($content);

        if ($reason !== null) {
            throw new ToolPermissionException($reason);
        }
    }

    /**
     * Why this template content may not be stored, or null when it may.
     *
     * Separate from the assertion so a batch import can skip one entry and
     * report why, rather than failing the whole archive.
     *
     * @param array<string, mixed> $content
     */
    public static function codeReason(array $content): ?string
    {
        if (current_user_can('unfiltered_html')) {
            return null;
        }

        $settings = $content['settings'] ?? null;

        if (is_array($settings) && DocumentSettings::hasCode($settings)) {
            return 'Storing a template whose settings set customCSS or customJS requires the unfiltered_html capability.';
        }

        foreach (['elements', 'regions', 'atts'] as $key) {
            if (isset($content[$key]) && ElementTree::containsRawContent($content[$key])) {
                return 'Storing a template that contains Raw Content elements requires the unfiltered_html capability.';
            }
        }

        return null;
    }
}
