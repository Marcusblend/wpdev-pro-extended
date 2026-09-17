<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\ComponentScanner;
use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Support\Args;

final class ListComponents implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['document_id', 'search', 'include_parameters', 'refresh'];

    public function __construct(
        private readonly DocumentGateway $gateway,
    ) {}

    public function name(): string
    {
        return 'list_components';
    }

    public function description(): string
    {
        return 'List the Cornerstone components the builder can use (the same registry): component_id (use it as "component_id" in {"_type": "component"} instances), label, source document, library group, prefab flag, whether it accepts children, slot IDs and parameter groups. include_parameters: true adds the full parameter tree. Also returns registry errors such as duplicate _c_id values.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'document_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Only components defined in this component document.',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional. Case-insensitive match on label or component_id.',
                ],
                'include_parameters' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Include each component\'s decoded parameter tree. Default: false.',
                ],
                'refresh' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Purge Cornerstone\'s caches and rebuild the registry first (needs manage_options). Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $documentId = Args::int($arguments, 'document_id', null, 1);
        $search = Args::string($arguments, 'search', null, 200);
        $includeParameters = Args::bool($arguments, 'include_parameters', false);
        $refresh = Args::bool($arguments, 'refresh', false);

        if ($refresh && ! current_user_can('manage_options')) {
            throw new ToolPermissionException('refresh: true requires the manage_options capability.');
        }

        $registry = $this->gateway->componentRegistry($refresh);
        $titles = [];
        $rows = [];

        foreach ($registry['components'] as $componentId => $component) {
            if (! is_array($component)) {
                continue;
            }

            $docId = (int) ($component['doc'] ?? 0);

            if ($documentId !== null && $docId !== $documentId) {
                continue;
            }

            $rootId = (string) ($component['root'] ?? '');
            $root = is_array($component['data'][$rootId] ?? null) ? $component['data'][$rootId] : [];
            $label = (string) ($root['_label'] ?? '');

            if ($search !== null && $search !== '' && stripos($label, $search) === false && stripos((string) $componentId, $search) === false) {
                continue;
            }

            $titles[$docId] ??= (string) get_post_field('post_title', $docId);
            $docSettings = $registry['doc_settings']['c' . $docId] ?? [];
            $pJson = $root['_p_json'] ?? null;

            $row = [
                'component_id'        => (string) $componentId,
                'label'               => $label,
                'element_type'        => $root['_type'] ?? null,
                'document_id'         => $docId,
                'document_title'      => $titles[$docId],
                'library_group'       => $docSettings[2] ?? '',
                'document_visibility' => $docSettings[3] ?? '',
                'prefab'              => ! empty($root['_c_prefab']),
                'accepts_children'    => ! empty($component['children']),
                'slots'               => array_values(array_map('strval', (array) ($component['slots'] ?? []))),
                'parameter_groups'    => ComponentScanner::declaredParameters($pJson) ?? [],
            ];

            if ($includeParameters) {
                $row['parameters'] = ComponentScanner::parameterTree($pJson);
            }

            $rows[] = $row;
        }

        usort($rows, static fn(array $a, array $b): int => [$a['document_id'], $a['label']] <=> [$b['document_id'], $b['label']]);

        return [
            'count'      => count($rows),
            'source'     => $registry['source'],
            'refreshed'  => $refresh,
            'components' => $rows,
            'errors'     => $registry['errors'],
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Components');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
