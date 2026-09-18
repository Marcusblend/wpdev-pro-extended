<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\Renderer;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class RenderPreview implements ToolInterface, AnnotatedToolInterface
{
    /** Keep a result passable through one tool response. */
    private const MAX_BYTES = 400000;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'render_preview';
    }

    public function description(): string
    {
        return 'Render elements to HTML without saving anything, so what they actually produce can be checked before or instead of a save. Pass elements, or post_id with an optional path to render part of a stored layout ("0._modules.1"). for_post sets the post the render happens against, which is what loopers, conditions and tokens read — a looper with no results, a condition that hides everything and a token that resolves to nothing all save without complaint and are only visible here. Tokens are expanded unless expand_tokens is false. Strictly read-only.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'elements' => [
                    'type'        => ['array', 'string'],
                    'description' => 'Elements to render. Accepts a JSON string.',
                ],
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Render a stored layout instead of passing elements.',
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => 'Optional. With post_id, the subtree to render ("0._modules.1"). Default: the whole layout.',
                ],
                'for_post' => [
                    'type'        => 'integer',
                    'description' => 'Optional. The post to render against. Default: post_id when given.',
                ],
                'expand_tokens' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Expand Dynamic Content tokens. Default: true.',
                ],
                'max_bytes' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Truncate the HTML at this many bytes. Default: 400000.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['elements', 'post_id', 'path', 'for_post', 'expand_tokens', 'max_bytes'], 'arguments');

        $arguments = JsonArgs::decode($arguments, ['elements']);

        $postId = Args::int($arguments, 'post_id', null, 1);
        $path = Args::string($arguments, 'path', null, 500);
        $forPost = Args::int($arguments, 'for_post', null, 1);
        $expand = Args::bool($arguments, 'expand_tokens', true);
        $maxBytes = Args::int($arguments, 'max_bytes', self::MAX_BYTES, 1000, self::MAX_BYTES) ?? self::MAX_BYTES;
        $elements = $arguments['elements'] ?? null;

        if ($elements !== null && $postId !== null) {
            throw new \InvalidArgumentException('Pass elements or post_id, not both.');
        }

        if ($elements === null && $postId === null) {
            throw new \InvalidArgumentException('Pass elements, or post_id to render a stored layout.');
        }

        $source = 'elements';

        if ($postId !== null) {
            $elements = $this->fromPost($postId, $path);
            $source = $path === null || $path === '' ? sprintf('post %d', $postId) : sprintf('post %d at %s', $postId, $path);
        }

        if (! is_array($elements) || $elements === []) {
            throw new \InvalidArgumentException('There were no elements to render.');
        }

        $rendered = $this->renderer->render(array_values($elements), $forPost ?? $postId, $expand);

        $html = $rendered['html'];
        $truncated = false;

        if (strlen($html) > $maxBytes) {
            $html = substr($html, 0, $maxBytes);
            $truncated = true;
        }

        $result = [
            'source'          => $source,
            'context_post_id' => $rendered['context_post_id'],
            'expanded'        => $rendered['expanded'],
            'bytes'           => $rendered['bytes'],
            'truncated'       => $truncated,
            'empty'           => trim(strip_tags($rendered['html'])) === '',
            'html'            => $html,
        ];

        if ($rendered['warnings'] !== []) {
            $result['warnings'] = $rendered['warnings'];
        }

        if ($result['empty']) {
            $result['note'] = 'The elements rendered no visible text. A looper with no results, a condition that hides its element, or a token that resolves to nothing all look like this.';
        }

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    private function fromPost(int $postId, ?string $path): array
    {
        $envelope = $this->layouts->get($postId);
        $data = $envelope['data'] ?? null;

        if (! is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Post %d has no Cornerstone layout to render.', $postId));
        }

        if (isset($data['regions']) && is_array($data['regions'])) {
            $flat = [];

            foreach ($data['regions'] as $region) {
                foreach ((array) $region as $element) {
                    $flat[] = $element;
                }
            }

            $data = $flat;
        } elseif (isset($data['elements']) && is_array($data['elements'])) {
            // A component document stores a flat map; render what hangs off
            // its region rather than the root and region wrappers.
            $map = $data['elements'];
            $flat = [];

            foreach ($map as $element) {
                if (is_array($element) && ($element['_parent'] ?? null) === 'e1') {
                    $flat[] = $this->expandMap($map, $element);
                }
            }

            $data = $flat;
        }

        if ($path === null || $path === '') {
            return array_values($data);
        }

        $node = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element in post %d.', $path, $postId));
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element in post %d.', $path, $postId));
        }

        return [$node];
    }

    /**
     * Turn a flat component map back into a tree for rendering.
     *
     * @param  array<string, mixed> $map
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function expandMap(array $map, array $element): array
    {
        $children = [];

        foreach ((array) ($element['_modules'] ?? []) as $childId) {
            $child = is_string($childId) ? ($map[$childId] ?? null) : $childId;

            if (is_array($child)) {
                $children[] = $this->expandMap($map, $child);
            }
        }

        $element['_modules'] = $children;

        return $element;
    }

    public function annotations(): array
    {
        return Annotations::read('Render Preview');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
