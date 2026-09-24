<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\SchemaExtractor;
use ProExtended\Media\MediaImporter;
use ProExtended\Media\SvgValidator;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\StoredList;
use ProExtended\Support\Args;
use ProExtended\Templates\TcoManifest;
use ProExtended\Templates\TemplateGateway;

final class ImportTco implements ToolInterface, AnnotatedToolInterface
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly TemplateGateway $templates,
        private readonly SchemaExtractor $schema,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'import_tco';
    }

    public function description(): string
    {
        return 'Import a .tco archive with Cornerstone\'s own importer, the one the builder uses: templates go into the library; headers, footers, layouts, components and pages are created as documents; the global colors and fonts it carries are merged in by id; bundled images are added to the Media Library and every image reference in the imported content is re-pointed at this site\'s copy; terms are created or reused by name. Menus and a full-site export\'s Global CSS are not imported. Pass the archive as base64 in tco. Without confirm: true nothing is written and the response describes the archive: what it would add or replace, what is ignored, and anything that blocks the import (an entry type this site does not have, code the current user may not write, an SVG, images without upload_files). A blocked archive imports nothing. Colors and fonts are backed up first; imported documents are created live, as the builder\'s import creates them.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['tco'],
            'properties' => [
                'tco' => [
                    'type'        => 'string',
                    'description' => 'The .tco archive, base64 encoded (what export_tco returns).',
                ],
                'confirm' => [
                    'type'        => 'boolean',
                    'description' => 'Required to write. Without it the archive is only described. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['tco', 'confirm'], 'arguments');

        if (! $this->templates->available()) {
            throw new \RuntimeException('This site has no Cornerstone template library.');
        }

        $encoded = (string) Args::string($arguments, 'tco', null, 0, false);
        $confirm = Args::bool($arguments, 'confirm', false);

        $raw = base64_decode($encoded, true);

        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException('tco is not base64. Pass the string export_tco returns.');
        }

        if (strlen($raw) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('The archive is %d bytes, over the %d byte limit.', strlen($raw), self::MAX_BYTES));
        }

        $path = wp_tempnam('pe-import.tco');

        if (! is_string($path) || $path === '' || file_put_contents($path, $raw) === false) {
            throw new \RuntimeException('The archive could not be written to a temporary file.');
        }

        try {
            // The describe half: read and measure the archive, and work out
            // what Cornerstone's importer would do with it. The importer itself
            // cannot describe without writing.
            $archive = TemplateGateway::readArchive($path);
            $plan = TcoManifest::plan(
                $archive,
                $this->siteFacts(),
                static fn (array $content): ?string => TemplateGateway::codeReason($content),
                static fn (string $bytes): array => SvgValidator::validate($bytes)
            );
            $plan = $this->annotate($plan);

            if (! $confirm) {
                return [
                    'confirmed' => false,
                    'files'     => $archive['files'],
                    'plan'      => $plan,
                    'note'      => $plan['blocking'] === []
                        ? 'Nothing was written. Call again with confirm: true to import.'
                        : 'Nothing was written, and confirm: true would be refused until everything under blocking is resolved.',
                ];
            }

            if ($plan['blocking'] !== []) {
                throw new \InvalidArgumentException('The archive was not imported; nothing was written. ' . implode(' ', array_map(
                    static fn (array $row): string => sprintf('%s: %s', $row['what'], $row['reason']),
                    $plan['blocking']
                )));
            }

            if (! function_exists('cs_import_tco')) {
                throw new \RuntimeException('This version of Cornerstone has no cs_import_tco(), so the archive cannot be imported natively. Import it in the builder instead.');
            }

            // Cornerstone merges colors and fonts by _id, replacing any the
            // site already has; keep what was there so restore_settings can
            // put it back.
            $backups = [];

            foreach (['colors', 'fonts'] as $key) {
                if ($plan[$key]['count'] > 0) {
                    $backups[$key] = $this->backups->backup($key, 'import_tco')['backup_id'];
                }
            }

            $before = $this->highestPostId();

            try {
                $last = cs_import_tco($path);
            } catch (\Throwable $e) {
                $created = $this->postsSince($before);

                throw new \RuntimeException(sprintf(
                    'Cornerstone\'s importer stopped: %s. %s',
                    $e->getMessage(),
                    $created === []
                        ? 'Nothing was created.'
                        : 'Created before it stopped: ' . implode(', ', array_map(static fn (array $post): string => sprintf('%s %d', $post['post_type'], $post['id']), $created)) . '.'
                ), 0, $e);
            }

            return [
                'confirmed'        => true,
                'created'          => $this->postsSince($before),
                'last_document_id' => is_numeric($last) ? (int) $last : null,
                'colors'           => $plan['colors'],
                'fonts'            => $plan['fonts'],
                'backups'          => $backups,
                'ignored'          => $plan['ignored'],
                'warnings'         => $plan['warnings'],
            ];
        } finally {
            @unlink($path);
        }
    }

    /**
     * The facts about this site the plan is checked against.
     *
     * @return array<string, mixed>
     */
    private function siteFacts(): array
    {
        return [
            'element_types'  => $this->elementTypes(),
            'document_types' => $this->templates->documentTypes(),
            'taxonomies'     => array_values(array_map('strval', get_taxonomies())),
            'can_upload'     => current_user_can('upload_files'),
            'svg_allowed'    => MediaImporter::svgAllowed(),
            'color_ids'      => $this->storedIds('cornerstone_color_items'),
            'font_ids'       => $this->storedIds('cornerstone_font_items'),
        ];
    }

    /**
     * Add what only the database knows: which terms exist already, and which
     * documents were imported before (Cornerstone reuses those by signature).
     *
     * @param  array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function annotate(array $plan): array
    {
        foreach ($plan['terms'] as $i => $term) {
            $plan['terms'][$i]['exists'] = $term['taxonomy'] !== '' && taxonomy_exists($term['taxonomy'])
                && get_term_by('name', $term['name'], $term['taxonomy']) instanceof \WP_Term;
        }

        $reused = array_filter(array_column($plan['terms'], 'exists'));

        if ($reused !== []) {
            $plan['warnings'][] = sprintf('%d term(s) already exist by name; Cornerstone reuses them and overwrites their slug and description.', count($reused));
        }

        foreach ($plan['documents'] as $i => $document) {
            $plan['documents'][$i]['existing_id'] = is_string($document['signature']) ? $this->importedPost($document['signature']) : null;
        }

        return $plan;
    }

    /**
     * The post Cornerstone's importer recorded under a signature
     * (`_cs_import`), which it reuses instead of creating another.
     */
    private function importedPost(string $signature): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cs_import' AND meta_value = %s LIMIT 1",
            $signature
        ));

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return string[]
     */
    private function storedIds(string $option): array
    {
        try {
            $items = StoredList::listFrom($this->backups->readRaw($option), $option);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) && is_scalar($item['_id'] ?? null) ? (string) $item['_id'] : '', $items)));
    }

    private function highestPostId(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
    }

    /**
     * Posts created since the import started: its templates, documents and
     * media.
     *
     * @return array<int, array{id: int, post_type: string, title: string}>
     */
    private function postsSince(int $after): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('revision', 'nav_menu_item') ORDER BY ID ASC LIMIT 500",
            $after
        ));

        return array_map(static fn (object $row): array => [
            'id'        => (int) $row->ID,
            'post_type' => (string) $row->post_type,
            'title'     => (string) $row->post_title,
        ], is_array($rows) ? $rows : []);
    }

    /**
     * @return string[]
     */
    private function elementTypes(): array
    {
        $types = [];

        foreach ($this->schema->getElementList() as $element) {
            if (is_string($element['type'] ?? null) && $element['type'] !== '') {
                $types[] = $element['type'];
            }
        }

        return $types;
    }

    public function annotations(): array
    {
        // Destructive: Cornerstone's importer replaces colors and fonts with
        // the same _id, overwrites the slug and description of a term with the
        // same name, and with the "replace" strategy the content of a document
        // or template it imported before. Not idempotent: a template imported
        // twice is added twice, the second time with a numbered title.
        return Annotations::write('Import .tco', true, false);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
