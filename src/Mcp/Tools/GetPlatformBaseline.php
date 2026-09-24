<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Mcp\ToolPermissionException;

use ProExtended\Site\PlatformBaseline;
use ProExtended\Site\PlatformSnapshot;
use ProExtended\Support\Args;

final class GetPlatformBaseline implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly PlatformSnapshot $snapshot) {}

    public function name(): string
    {
        return 'get_platform_baseline';
    }

    public function description(): string
    {
        return 'Fingerprint the Cornerstone platform this site runs — versions, element types and their migration versions, document types, theme option keys, dynamic content groups, looper providers, permissions, and the native registries get_native_reference reads (Twig functions, filters and tests; condition rules; looper providers; parameter types; null while one cannot be read, e.g. Twig while it is off) — and compare it with the last stored fingerprint. This is how a Themeco release is noticed: the diff says which element types appeared, which migrations moved, which theme options or token groups are new. Pass save: true to store the current fingerprint as the one future calls compare against. Read-only otherwise.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'save' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Store this fingerprint as the new baseline. Default: false.',
                ],
                'include_lists' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Include the full lists as well as the diff, which is large. Default: false.',
                ],
                'include_extensions' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Also report what each known Cornerstone extension contributes. Default: true.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['save', 'include_lists', 'include_extensions'], 'arguments');

        $save = Args::bool($arguments, 'save', false);
        $includeLists = Args::bool($arguments, 'include_lists', false);
        $includeExtensions = Args::bool($arguments, 'include_extensions', true);

        $current = $this->snapshot->take();
        $stored = get_option(PlatformBaseline::OPTION, null);
        $stored = is_array($stored) ? $stored : null;

        $diff = PlatformBaseline::diff($stored, $current);

        $result = [
            'cornerstone_version' => $current['cornerstone_version'],
            'pro_version'         => $current['pro_version'],
            'wordpress_version'   => $current['wordpress_version'],
            'php_version'         => $current['php_version'],
            'breakpoint_tag'      => $current['breakpoint_tag'],
            'counts'              => [
                'element_types'          => count($current['element_types']),
                'document_types'         => count($current['document_types']),
                'theme_option_keys'      => count($current['theme_option_keys']),
                'dynamic_content_groups' => count($current['dynamic_content_groups']),
                'looper_types'           => count($current['looper_types']),
                'permissions'            => count($current['permissions']),
            ] + array_map(
                static fn (mixed $list): ?int => is_array($list) ? count($list) : null,
                array_intersect_key($current, array_flip(PlatformBaseline::NATIVE_KEYS))
            ),
            'baseline'            => $stored === null ? null : [
                'taken_at'            => $stored['taken_at'] ?? null,
                'cornerstone_version' => $stored['cornerstone_version'] ?? null,
                'pro_version'         => $stored['pro_version'] ?? null,
            ],
            'drifted'             => $diff['drifted'],
            'changes'             => $diff['changes'],
            'summary'             => $stored === null
                ? 'No baseline stored yet; call again with save: true to record this one.'
                : PlatformBaseline::summarize($diff['changes']),
        ];

        if ($includeExtensions) {
            $result['extensions'] = $this->snapshot->extensions();
        }

        if ($includeLists) {
            $result['lists'] = [
                'element_types'          => $current['element_types'],
                'document_types'         => $current['document_types'],
                'dynamic_content_groups' => $current['dynamic_content_groups'],
                'looper_types'           => $current['looper_types'],
                'permissions'            => $current['permissions'],
            ] + array_intersect_key($current, array_flip(PlatformBaseline::NATIVE_KEYS));
        }

        if ($save) {
            // Storing the baseline overwrites the drift history every later
            // call is compared against, so it is not something a plain editor
            // does by passing a flag. The tool stays readable at edit_posts;
            // only the write asks for more.
            if (! current_user_can('manage_options')) {
                throw new ToolPermissionException(
                    'Saving the platform baseline requires the manage_options capability. '
                    . 'Call without save: true to read the current platform without storing it.'
                );
            }

            update_option(PlatformBaseline::OPTION, $current, false);
            $result['saved'] = true;
            $result['saved_at'] = $current['taken_at'];
        }

        return $result;
    }

    public function annotations(): array
    {
        // Not read-only: save: true writes the stored baseline. A client that
        // auto-approves read-only tools would otherwise overwrite the drift
        // history this tool exists to provide, without asking.
        return Annotations::write('Get Platform Baseline', false, true);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
