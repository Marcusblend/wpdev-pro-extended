<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutOutline;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;

final class GetLayout implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'get_layout';
    }

    public function description(): string
    {
        return 'Get the full Cornerstone layout data for a specific post. Returns the JSON structure with post metadata, checksum, and the element tree. For large documents pass summary: true to get an outline (paths, types, labels, child counts) and the document size, then pass path to fetch one subtree (update_layout dot paths such as "0._modules.1" or "regions.top.0"; an element ID such as "e5" in component documents).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to retrieve layout data from.',
                ],
                'summary' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return an outline instead of the data. Default: false.',
                ],
                'max_depth' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 50,
                    'description' => 'Optional. Outline depth (with summary). Default: 4.',
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => 'Optional. Return only the subtree at this path (dot path, or an element ID in component documents).',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId = (int) ($arguments['post_id'] ?? 0);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        $summary  = Args::bool($arguments, 'summary', false);
        $path     = Args::string($arguments, 'path', null, 500);
        $maxDepth = Args::int($arguments, 'max_depth', 4, 1, 50) ?? 4;

        $envelope = $this->layouts->get($postId);

        if (! $summary && ($path === null || $path === '')) {
            return $envelope;
        }

        $data = $envelope['data'];
        unset($envelope['data']);

        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $envelope['bytes'] = is_string($encoded) ? strlen($encoded) : 0;

        if ($path !== null && $path !== '') {
            $envelope['path'] = $path;
        }

        if ($summary) {
            $outline = LayoutOutline::outline($data, $maxDepth, $path);
            $envelope['max_depth'] = $maxDepth;
            $envelope['truncated'] = $outline['truncated'];
            $envelope['element_count'] = LayoutOutline::countElements($data);
            $envelope['outline'] = $outline['nodes'];

            if (is_array($data) && isset($data['settings']) && is_array($data['settings'])) {
                $envelope['settings_keys'] = array_keys($data['settings']);
            }

            return $envelope;
        }

        $envelope['data'] = LayoutOutline::subtree($data, (string) $path);

        return $envelope;
    }

    public function annotations(): array
    {
        return Annotations::read('Get Layout');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
