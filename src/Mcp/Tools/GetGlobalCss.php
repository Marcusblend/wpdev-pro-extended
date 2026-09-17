<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Css\CssBlocks;
use ProExtended\Support\Args;

final class GetGlobalCss implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['name', 'full'];

    public function __construct(
        private readonly DocumentGateway $gateway,
    ) {}

    public function name(): string
    {
        return 'get_global_css';
    }

    public function description(): string
    {
        return 'Read Global CSS (Theme Options → CSS): the option key, its size and the Pro Extended managed blocks (name, size, line range). Pass name to get one block\'s CSS, or full: true for the whole stylesheet.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Optional. Return this managed block\'s CSS.',
                ],
                'full' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return the whole stylesheet. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $name = Args::string($arguments, 'name', null, 100);
        $full = Args::bool($arguments, 'full', false);

        $key = $this->gateway->globalCssKey();
        $css = $this->gateway->getThemeOption($key);
        $css = is_string($css) ? $css : '';
        $parsed = CssBlocks::parse($css);

        $result = [
            'option_key' => $key,
            'bytes'      => strlen($css),
            'lines'      => $css === '' ? 0 : substr_count($css, "\n") + 1,
            'blocks'     => array_map(static fn(array $block): array => [
                'name'       => $block['name'],
                'bytes'      => $block['bytes'],
                'line_start' => $block['line_start'],
                'line_end'   => $block['line_end'],
            ], $parsed['blocks']),
            'errors'     => $parsed['errors'],
        ];

        if ($name !== null) {
            $found = null;

            foreach ($parsed['blocks'] as $block) {
                if ($block['name'] === $name) {
                    $found = $block;
                    break;
                }
            }

            if ($found === null) {
                throw new \InvalidArgumentException(sprintf('There is no managed block named "%s".', $name));
            }

            $result['block'] = [
                'name'       => $found['name'],
                'css'        => $found['content'],
                'bytes'      => $found['bytes'],
                'line_start' => $found['line_start'],
                'line_end'   => $found['line_end'],
            ];
        }

        if ($full) {
            $result['css'] = $css;
        }

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::read('Get Global CSS');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
