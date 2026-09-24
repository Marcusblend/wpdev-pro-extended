<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\DocumentSettings;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Elements\ElementTree;
use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutOutline;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class CreateDocument implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['type', 'title', 'slug', 'settings', 'layout_data', 'if_not_exists', 'dry_run', 'stamp_new'];

    private readonly DocumentGateway $gateway;

    public function __construct(
        private readonly LayoutService $layouts,
        private readonly HierarchyValidator $validator,
        private readonly ?ElementContext $elements = null,
    ) {
        $this->gateway = $layouts->gateway();
    }

    public function name(): string
    {
        return 'create_document';
    }

    public function description(): string
    {
        return 'Create a Cornerstone header, footer, component document, or single/archive layout. Optional settings (assignments, assignment_priority, multi_region, header_enabled, footer_enabled, library_group, document_visibility, customCSS, customJS) and layout_data in the shape get_layout returns ({"settings", "regions"} for layouts, {"elements", "settings"} for components). Each type renders only its own regions (' . $this->regionSummary() . '); a region the type does not render is an error, because its elements would be saved and never shown. There are no built-in header presets: save a finished header as a document template with create_template and pass get_template\'s content as layout_data, or start from a list_prefabs prefab. With if_not_exists (default true) an existing document of the same type and title is returned instead. Use dry_run: true to preview.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['type', 'title'],
            'properties' => [
                'type' => [
                    'type'        => 'string',
                    'enum'        => $this->gateway->availableTypes(),
                    'description' => 'Document type.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Document title.',
                ],
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Optional. Slug (components only).',
                ],
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Optional. assignments ([{group, condition, value}]), assignment_priority, multi_region (headers), header_enabled/footer_enabled (single/archive layouts), library_group/document_visibility (components), customCSS/customJS (need unfiltered_html).',
                ],
                'layout_data' => [
                    'type'        => ['object', 'array'],
                    'description' => 'Optional. The document data, in the shape get_layout returns in "data". The keys of "regions" must be regions the type renders: ' . $this->regionSummary() . '. A component takes {"elements": {"e0": {"_type": "root", ...}, ...}} instead.',
                ],
                'if_not_exists' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return an existing document with the same type and exact title instead of creating one. Default: true.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be created and write nothing. Default: false.',
                ],
                'stamp_new' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Give every element in layout_data the migration (_m) and breakpoint (_bp_base) markers Cornerstone gives new elements, where missing. Default: true.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['settings', 'layout_data']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $type = Args::enum($arguments, 'type', $this->gateway->availableTypes(), null);

        if ($type === null) {
            throw new \InvalidArgumentException('"type" is required.');
        }

        $docType = $this->gateway->docTypeForType($type);
        $isComponent = $this->gateway->isComponentDocType($docType);

        $title = Args::string($arguments, 'title', null, 200, false);

        if ($title === null) {
            throw new \InvalidArgumentException('"title" is required.');
        }

        $title = sanitize_text_field($title);
        $slug = Args::string($arguments, 'slug', null, 200);

        if ($slug !== null && ! $isComponent) {
            throw new \InvalidArgumentException('"slug" can only be set for component documents.');
        }

        $slug = $slug !== null ? sanitize_title($slug) : '';
        $ifNotExists = Args::bool($arguments, 'if_not_exists', true);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $stampNew = Args::bool($arguments, 'stamp_new', true);
        $stamped = null;
        $warnings = [];

        $settings = DocumentSettings::validate($docType, Args::object($arguments, 'settings') ?? []);
        $layoutData = Args::object($arguments, 'layout_data');

        [$elements, $dataSettings] = $this->readLayoutData($docType, $layoutData, $warnings);
        $settings = array_merge($dataSettings, $settings);

        if (DocumentSettings::hasCode($settings) && ! current_user_can('unfiltered_html')) {
            throw new ToolPermissionException('Setting customCSS or customJS requires the unfiltered_html capability.');
        }

        if ($elements !== null && ! current_user_can('unfiltered_html') && ElementTree::containsRawContent($elements)) {
            throw new ToolPermissionException('Adding Raw Content elements requires the unfiltered_html capability.');
        }

        if ($elements !== null && $stampNew && $this->elements !== null) {
            $stamper = $this->elements->stamper();
            $elements = $isComponent ? $stamper->stampFlat($elements) : $stamper->stampRegions($elements);
            $stamped = $stamper->counts();
        }

        if ($elements !== null) {
            $validation = $this->validator->validate(
                $isComponent ? ['elements' => $elements] : ['regions' => $elements],
                $isComponent ? 'flat' : 'inline'
            );

            if (! $validation->valid) {
                throw new \InvalidArgumentException('layout_data failed validation; nothing was created. ' . implode(' ', $validation->errors));
            }

            $warnings = array_merge($warnings, $validation->warnings);
        }

        $postType = (string) $this->gateway->postTypeForDocType($docType);

        if ($ifNotExists) {
            $existing = $this->findByTitle($postType, $title);

            if ($existing !== null) {
                return [
                    'created'     => false,
                    'dry_run'     => $dryRun,
                    'document_id' => $existing,
                    'post_type'   => $postType,
                    'doc_type'    => $docType,
                    'title'       => (string) get_post_field('post_title', $existing),
                    'edit_url'    => $this->gateway->editUrl($existing),
                    'backup_id'   => null,
                    'write_path'  => null,
                    'warnings'    => array_merge($warnings, [sprintf('A %s titled "%s" already exists; nothing was written.', $type, $title)]),
                    'stamped'     => null,
                ];
            }
        }

        $warnings = array_merge($warnings, $this->entireSiteWarnings($docType, $settings));

        if ($dryRun) {
            $defaults = $this->gateway->storedDefaults($docType);

            if (str_starts_with($docType, 'layout:')) {
                $defaults['layout_type'] = $this->gateway->layoutTypeFor($docType);
            }

            return [
                'created'      => false,
                'dry_run'      => true,
                'document_id'  => null,
                'post_type'    => $postType,
                'doc_type'     => $docType,
                'title'        => $title,
                'slug'         => $isComponent ? $slug : null,
                'would_create' => [
                    'settings'      => array_merge($defaults, $settings),
                    'element_count' => $elements === null ? 0 : LayoutOutline::countElements($isComponent ? ['elements' => $elements] : ['regions' => $elements]),
                ],
                'backup_id'    => null,
                'write_path'   => $this->gateway->apiAvailable() ? DocumentGateway::PATH_API : DocumentGateway::PATH_FALLBACK,
                'warnings'     => $warnings,
                'stamped'      => $stamped,
            ];
        }

        $result = $this->gateway->createDocument($docType, [
            'title'    => $title,
            'slug'     => $slug,
            'settings' => $settings,
            'elements' => $elements,
        ]);

        $output = [
            'created'     => true,
            'dry_run'     => false,
            'document_id' => $result['id'],
            'post_type'   => $result['post_type'],
            'doc_type'    => $result['doc_type'],
            'title'       => $result['title'],
        ];

        if ($isComponent) {
            $output['slug'] = (string) get_post_field('post_name', $result['id']);
        }

        $editUrl = $this->gateway->editUrl($result['id']);

        if ($editUrl !== null) {
            $output['edit_url'] = $editUrl;
        }

        return $output + [
            'backup_id'  => null,
            'write_path' => $result['path'],
            'warnings'   => array_merge($warnings, $result['warnings']),
            'stamped'    => $stamped,
        ];
    }

    /**
     * Pull elements and storable settings out of layout_data.
     *
     * @param  array<string, mixed>|null $data
     * @param  string[]                  $warnings
     * @return array{0: array<mixed>|null, 1: array<string, mixed>}
     */
    private function readLayoutData(string $docType, ?array $data, array &$warnings): array
    {
        if ($data === null) {
            return [null, []];
        }

        $isComponent = $this->gateway->isComponentDocType($docType);

        if ($isComponent) {
            if (isset($data['elements']) && is_array($data['elements'])) {
                $elements = $data['elements'];
            } elseif (isset($data['e0'])) {
                $elements = $data;
                $data = [];
            } else {
                throw new \InvalidArgumentException('layout_data for a component must be {"elements": {"e0": ...}, "settings": {...}}.');
            }

            if (! isset($elements['e0']) || ! is_array($elements['e0']) || ($elements['e0']['_type'] ?? null) !== 'root') {
                throw new \InvalidArgumentException('Component elements need a root element "e0" with _type "root".');
            }
        } else {
            $elements = $data['regions'] ?? null;

            if (! is_array($elements) || ($elements !== [] && array_is_list($elements))) {
                throw new \InvalidArgumentException('layout_data for a header, footer or layout must be {"settings": {...}, "regions": {"<region>": [...]}}.');
            }

            DocumentGateway::assertRegionsRendered($docType, $elements, $this->gateway->renderedRegions($docType));

            foreach ($elements as $name => $region) {
                if (! is_array($region) || ($region !== [] && ! array_is_list($region))) {
                    throw new \InvalidArgumentException(sprintf('Region "%s" must be a list of elements.', (string) $name));
                }
            }
        }

        $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];
        $allowed = DocumentSettings::allowedKeys($docType);
        $identity = ['general_title', 'general_post_title', 'general_post_name', 'layout_type'];
        $kept = [];
        $dropped = [];

        foreach ($settings as $key => $value) {
            $key = (string) $key;

            if (in_array($key, $identity, true)) {
                continue;
            }

            if (in_array($key, $allowed, true)) {
                $kept[$key] = $value;
            } elseif ($isComponent) {
                // Cornerstone stores every component setting as given.
                $kept[$key] = $value;
            } else {
                $dropped[] = $key;
            }
        }

        if ($dropped !== []) {
            $warnings[] = 'Ignored settings Cornerstone does not store for this document type: ' . implode(', ', $dropped) . '.';
        }

        $validated = DocumentSettings::validate($docType, array_intersect_key($kept, array_flip($allowed)));

        return [$elements, array_merge($kept, $validated)];
    }

    /**
     * The regions each layout type on this site renders, for the descriptions:
     * "header: top, right, bottom, left; footer: footer; ...".
     */
    private function regionSummary(): string
    {
        $parts = [];

        foreach ($this->gateway->availableTypes() as $type) {
            $docType = $this->gateway->docTypeForType($type);

            if ($this->gateway->isComponentDocType($docType)) {
                continue;
            }

            $parts[] = $type . ': ' . implode(', ', $this->gateway->renderedRegions($docType));
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed> $settings
     * @return string[]
     */
    private function entireSiteWarnings(string $docType, array $settings): array
    {
        $claims = false;

        foreach ((array) ($settings['assignments'] ?? []) as $rule) {
            if (is_array($rule) && ($rule['condition'] ?? null) === 'site:entire-site') {
                $claims = true;
            }
        }

        if (! $claims) {
            return [];
        }

        $priority = (int) ($settings['assignment_priority'] ?? 0);
        $others = $this->gateway->documentsClaiming($docType, 'site:entire-site', $priority);

        if ($others === []) {
            return [];
        }

        return [sprintf(
            'Also assigned to site:entire-site at priority %d: %s. Cornerstone uses the one that sorts first (by priority, then title).',
            $priority,
            implode(', ', array_map(static fn(array $d): string => sprintf('#%d "%s"', $d['id'], $d['title']), $others))
        )];
    }

    private function findByTitle(string $postType, string $title): ?int
    {
        $ids = get_posts([
            'post_type'        => $postType,
            'post_status'      => 'tco-data',
            'title'            => $title,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);

        return $ids ? (int) $ids[0] : null;
    }

    public function annotations(): array
    {
        return Annotations::write('Create Document', false, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
