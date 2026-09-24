<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\ComponentScanner;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class CreateComponent implements ToolInterface, AnnotatedToolInterface
{
    private readonly \ProExtended\Cornerstone\DocumentGateway $gateway;

    public function __construct(
        private readonly LayoutService $layouts,
        private readonly HierarchyValidator $validator,
        private readonly ElementContext $elements,
    ) {
        $this->gateway = $layouts->gateway();
    }

    public function name(): string
    {
        return 'create_component';
    }

    public function description(): string
    {
        return 'Make a reusable component from a set of elements, or from part of an existing layout. exports names the settings a person editing an instance may change: each is a path into the elements ("0", "0._modules.1") with an id and a label, and those elements are marked so the builder shows them. slots are the paths where an instance can put its own content. parameters is a _p_json schema, so a component\'s values can be driven by named parameters rather than edited per instance. Pass elements, or from_document with an optional path to lift a subtree out of a page or document. After saving, every export is looked up in Cornerstone\'s component registry; if one is missing the new document is deleted and the call fails with the reason. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'The component\'s name in the library.',
                ],
                'elements' => [
                    'type'        => ['array', 'string'],
                    'description' => 'The component\'s elements. Accepts a JSON string.',
                ],
                'from_document' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Take the elements from this post instead.',
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => 'Optional. With from_document, the subtree to lift ("0._modules.1"). Default: everything.',
                ],
                'exports' => [
                    'type'        => 'array',
                    'description' => 'Optional. [{path, id, label}] — elements whose settings an instance may change.',
                ],
                'slots' => [
                    'type'        => 'array',
                    'description' => 'Optional. [{path, id}] — elements an instance may fill with its own content.',
                ],
                'parameters' => [
                    'type'        => ['object', 'string'],
                    'description' => 'Optional. A _p_json parameter schema for the component.',
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
        Args::rejectUnknown($arguments, ['title', 'elements', 'from_document', 'path', 'exports', 'slots', 'parameters', 'dry_run'], 'arguments');

        $arguments = JsonArgs::decode($arguments, ['elements', 'parameters']);

        $title = (string) Args::string($arguments, 'title', null, 200, false);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $fromDocument = Args::int($arguments, 'from_document', null, 1);
        $path = Args::string($arguments, 'path', null, 500);
        $exports = Args::list($arguments, 'exports', 0, 200) ?? [];
        $slots = Args::list($arguments, 'slots', 0, 200) ?? [];
        $parameters = Args::object($arguments, 'parameters');
        $elements = $arguments['elements'] ?? null;

        if ($elements !== null && $fromDocument !== null) {
            throw new \InvalidArgumentException('Pass elements or from_document, not both.');
        }

        if ($fromDocument !== null) {
            $elements = $this->lift($fromDocument, $path);
        }

        if (! is_array($elements) || $elements === []) {
            throw new \InvalidArgumentException('Pass elements, or from_document to take them from an existing layout.');
        }

        $elements = array_values($elements);
        $marked = [];

        // Every element the library offers is itself an export: a component
        // document holds one or more exported elements, not a wrapper with a
        // component id of its own. So unless the caller exported the top-level
        // element explicitly, it is exported here — otherwise the component is
        // saved and never appears in the library.
        $exports = $this->withRootExport($exports, $title);

        foreach ($this->normalizeMarks($exports, 'exports', true) as $mark) {
            $this->mark($elements, $mark['path'], [
                '_c_export' => true,
                '_c_id'     => $mark['id'],
                '_label'    => $mark['label'],
            ]);
            $marked[] = ['kind' => 'export', 'path' => $mark['path'], 'id' => $mark['id'], 'label' => $mark['label']];
        }

        foreach ($this->normalizeMarks($slots, 'slots', false) as $mark) {
            $this->mark($elements, $mark['path'], [
                '_c_slot' => true,
                '_c_id'   => $mark['id'],
            ]);
            $marked[] = ['kind' => 'slot', 'path' => $mark['path'], 'id' => $mark['id']];
        }

        if ($parameters !== null && $parameters !== []) {
            $encoded = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! is_string($encoded)) {
                throw new \InvalidArgumentException('parameters could not be encoded as a _p_json schema.');
            }

            $elements[0]['_p_json'] = $encoded;
        }

        $declared = $parameters === null ? null : ComponentScanner::declaredParameters($elements[0]['_p_json'] ?? null);

        // Stamp first, so validation reports what would really be saved rather
        // than markers the save would have added anyway.
        $elements = $this->elements->stamper()->stampTree($elements);

        $result = $this->validator->validate($elements, 'inline');
        $validation = $result->toArray();

        if (! $result->valid) {
            return [
                'created'    => false,
                'validation' => $validation,
                'note'       => 'The elements did not validate, so nothing was created.',
            ];
        }

        if ($dryRun) {
            return [
                'dry_run'    => true,
                'title'      => $title,
                'elements'   => count($elements),
                'marked'     => $marked,
                'parameters' => $declared,
                'validation' => $validation,
            ];
        }

        // A component document stores a flat map — e0 root, e1 region, then the
        // elements by id — not a nested tree, and Cornerstone's component
        // registry only scans that shape. A nested tree saves without complaint
        // and never appears in the library.
        $created = $this->gateway->createDocument('custom:component', [
            'title'    => $title,
            'elements' => self::flatten($elements),
        ]);

        $documentId = (int) ($created['id'] ?? 0);

        // Saving is not the same as being offered in the library: a shape the
        // registry does not recognise, or an export id another document
        // already claims, saves without complaint and never appears. Rebuild
        // the registry the builder reads and look for every export; anything
        // missing means the document is removed again and the call fails.
        $exportIds = array_values(array_map(
            static fn (array $mark): string => $mark['id'],
            array_filter($marked, static fn (array $mark): bool => $mark['kind'] === 'export')
        ));
        $registry = $this->gateway->componentRegistry(true);
        $missing = self::unregisteredExports($exportIds, $documentId, (array) ($registry['components'] ?? []));

        if ($missing !== []) {
            if ($documentId > 0) {
                wp_delete_post($documentId, true);
            }

            $this->gateway->componentRegistry(true);

            throw new \RuntimeException(self::unregisteredMessage($documentId, $missing, (array) ($registry['errors'] ?? [])));
        }

        return [
            'created'      => true,
            'document_id'  => $created['id'] ?? null,
            'title'        => $title,
            'doc_type'     => 'custom:component',
            'elements'     => count($elements),
            'marked'       => $marked,
            'parameters'   => $declared,
            'validation'   => $validation,
            'registered'   => ['source' => $registry['source'] ?? null, 'exports' => $exportIds],
        ];
    }

    /**
     * The exports of a new component document that the registry does not
     * list for that document: absent, or claimed by another document.
     *
     * @param  string[]                 $exportIds
     * @param  array<int|string, mixed> $components The registry's components, keyed by _c_id.
     * @return array<int, array{id: string, registered_to: int|null}>
     */
    public static function unregisteredExports(array $exportIds, int $documentId, array $components): array
    {
        $missing = [];

        foreach ($exportIds as $id) {
            $entry = $components[$id] ?? null;
            $doc = is_array($entry) && is_numeric($entry['doc'] ?? null) ? (int) $entry['doc'] : null;

            if ($documentId <= 0 || $doc !== $documentId) {
                $missing[] = ['id' => (string) $id, 'registered_to' => $doc];
            }
        }

        return $missing;
    }

    /**
     * Why a component that saved was rolled back.
     *
     * @param array<int, array{id: string, registered_to: int|null}> $missing
     * @param string[]                                              $registryErrors
     */
    public static function unregisteredMessage(int $documentId, array $missing, array $registryErrors): string
    {
        $ids = array_map(static fn (array $row): string => $row['registered_to'] === null
            ? sprintf('"%s"', $row['id'])
            : sprintf('"%s" (Cornerstone has it from document %d instead)', $row['id'], $row['registered_to']), $missing);

        $message = sprintf(
            'The component saved as document %d, but Cornerstone\'s component registry does not list %s for it, so it would never appear in the library. The document was deleted again; nothing was left behind.',
            $documentId,
            implode(', ', $ids)
        );

        $errors = array_values(array_filter(array_map('strval', $registryErrors)));

        if ($errors !== []) {
            $message .= ' The registry reports: ' . implode(' ', $errors);
        }

        return $message . ' Check the elements with validate_layout and the export ids against list_components.';
    }

    /**
     * Take a subtree out of an existing layout.
     *
     * @return array<int, mixed>
     */
    private function lift(int $postId, ?string $path): array
    {
        $envelope = $this->layouts->get($postId);
        $data = $envelope['data'] ?? null;

        if (! is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Post %d has no Cornerstone layout.', $postId));
        }

        if (isset($data['regions']) && is_array($data['regions'])) {
            $flat = [];

            foreach ($data['regions'] as $region) {
                foreach ((array) $region as $element) {
                    $flat[] = $element;
                }
            }

            $data = $flat;
        }

        if ($path === null || $path === '') {
            return $data;
        }

        $node = $this->at($data, $path);

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element in post %d.', $path, $postId));
        }

        return [$node];
    }

    /**
     * @param  array<int|string, mixed> $data
     */
    private function at(array $data, string $path): mixed
    {
        $node = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param  array<int, mixed> $marks
     * @return array<int, array{path: string, id: string, label: string}>
     */
    private function normalizeMarks(array $marks, string $label, bool $needsLabel): array
    {
        $clean = [];
        $seen = [];

        foreach ($marks as $index => $mark) {
            if (! is_array($mark)) {
                throw new \InvalidArgumentException(sprintf('%s[%d] must be an object with a path and an id.', $label, $index));
            }

            $path = (string) ($mark['path'] ?? '');
            $id = (string) ($mark['id'] ?? '');

            if ($path === '' || $id === '') {
                throw new \InvalidArgumentException(sprintf('%s[%d] needs a path and an id.', $label, $index));
            }

            if (isset($seen[$id])) {
                throw new \InvalidArgumentException(sprintf('%s[%d]: the id "%s" is used twice; each must be unique within the component.', $label, $index, $id));
            }

            $seen[$id] = true;

            $clean[] = [
                'path'  => $path,
                'id'    => $id,
                'label' => (string) ($mark['label'] ?? ($needsLabel ? $id : '')),
            ];
        }

        return $clean;
    }

    /**
     * @param array<int, mixed>    $elements
     * @param array<string, mixed> $keys
     */
    private function mark(array &$elements, string $path, array $keys): void
    {
        $segments = explode('.', $path);
        $node = &$elements;

        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
            }

            $node = &$node[$segment];
        }

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        foreach ($keys as $key => $value) {
            $node[$key] = $value;
        }

        unset($node);
    }

    /**
     * Make sure the top-level element is exported.
     *
     * @param  array<int, mixed> $exports
     * @return array<int, mixed>
     */
    private function withRootExport(array $exports, string $title): array
    {
        foreach ($exports as $export) {
            if (is_array($export) && (string) ($export['path'] ?? '') === '0') {
                return $exports;
            }
        }

        array_unshift($exports, [
            'path'  => '0',
            'id'    => $this->componentId($title),
            'label' => $title,
        ]);

        return $exports;
    }

    /**
     * A component id that no component document is already using.
     */
    private function componentId(string $title): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', ' ', $title) ?? $title;
        $base = lcfirst(str_replace(' ', '', ucwords(strtolower(trim($base)))));
        $base = $base === '' ? 'component' : substr($base, 0, 40);

        $taken = [];

        try {
            $registry = $this->gateway->componentRegistry(false);

            foreach (array_keys((array) ($registry['components'] ?? [])) as $id) {
                $taken[(string) $id] = true;
            }
        } catch (\Throwable) {
            // With no registry to check against, a suffix still avoids the
            // common case of two components made from the same title.
            $taken = [];
        }

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($n = 2; $n < 500; $n++) {
            $candidate = $base . $n;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return $base . substr(md5((string) microtime(true)), 0, 6);
    }

    /**
     * Turn a nested element tree into the flat map a component document holds.
     *
     * @param  array<int, mixed> $elements
     * @return array<string, array<string, mixed>>
     */
    public static function flatten(array $elements): array
    {
        $map = [
            'e0' => ['_id' => 'e0', '_type' => 'root', '_modules' => ['e1']],
            'e1' => ['_id' => 'e1', '_type' => 'region', '_region' => 'content', '_modules' => [], '_parent' => 'e0'],
        ];

        $next = 2;

        $walk = static function (array $nodes, string $parent) use (&$walk, &$map, &$next): array {
            $ids = [];

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $id = 'e' . $next++;
                $children = is_array($node['_modules'] ?? null) ? $node['_modules'] : [];
                unset($node['_modules']);

                $node['_id'] = $id;
                $node['_parent'] = $parent;
                $node['_region'] = 'content';
                $node['_modules'] = [];

                $map[$id] = $node;
                $map[$id]['_modules'] = $walk($children, $id);

                $ids[] = $id;
            }

            return $ids;
        };

        $map['e1']['_modules'] = $walk(array_values($elements), 'e1');

        return $map;
    }

    public function annotations(): array
    {
        return Annotations::write('Create Component', false, true);
    }

    public function requiredCapability(): string
    {
        // This writes a cs_global_block document with caller-supplied element
        // data, the same object `create_document` creates — and a component
        // renders wherever it is inserted, site-wide. Two doors to the same
        // thing cannot have different locks.
        return 'manage_options';
    }
}
