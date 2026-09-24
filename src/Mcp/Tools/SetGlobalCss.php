<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Css\CssBlocks;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Support\Args;
use ProExtended\Support\LineDiff;

final class SetGlobalCss implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['operation', 'name', 'css', 'position', 'confirm_replace_all', 'dry_run'];
    private const OPERATIONS = ['upsert_block', 'remove_block', 'replace_all'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'set_global_css';
    }

    public function description(): string
    {
        return 'Last resort for styling: check get_element_schema first and set the element\'s own keys, which stay editable in the builder and can be bound to a parameter or global variable. Use this for what no element setting covers — site-wide rules, selectors that cross elements, @media or @supports blocks. Edit Global CSS (Theme Options → CSS) through named blocks Pro Extended manages (/* pe:begin <name> */ … /* pe:end <name> */). upsert_block creates or replaces a block, remove_block deletes one; CSS outside the blocks is never changed. replace_all replaces the whole stylesheet and needs confirm_replace_all: true. Refuses unbalanced braces/comments, "</style", "<script", "<?" and marker text; warns on backgrounds without a text color, remote @import and heavy !important use. Returns a diff, sizes and a backup_id. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['operation'],
            'properties' => [
                'operation' => [
                    'type'        => 'string',
                    'enum'        => self::OPERATIONS,
                    'description' => 'What to do.',
                ],
                'name' => [
                    'type'        => 'string',
                    'pattern'     => '^[a-z0-9][a-z0-9-]{0,62}$',
                    'description' => 'Block name (upsert_block, remove_block).',
                ],
                'css' => [
                    'type'        => 'string',
                    'description' => 'The block\'s CSS (upsert_block) or the whole stylesheet (replace_all).',
                ],
                'position' => [
                    'type'        => 'string',
                    'enum'        => ['end', 'start'],
                    'description' => 'Where a new block goes. Default: end.',
                ],
                'confirm_replace_all' => [
                    'type'        => 'boolean',
                    'description' => 'Required (true) for replace_all.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return the diff and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        if (! current_user_can('edit_css')) {
            throw new ToolPermissionException('set_global_css requires the edit_css capability.');
        }

        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $operation = Args::enum($arguments, 'operation', self::OPERATIONS, null);

        if ($operation === null) {
            throw new \InvalidArgumentException('"operation" is required.');
        }

        $name = Args::string($arguments, 'name', null, 100);
        $css = Args::string($arguments, 'css', null);
        $position = Args::enum($arguments, 'position', ['end', 'start'], 'end') ?? 'end';
        $confirm = Args::bool($arguments, 'confirm_replace_all', false);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        $key = $this->gateway->globalCssKey();
        $current = $this->gateway->getThemeOption($key);
        $before = is_string($current) ? $current : '';
        $warnings = [];

        switch ($operation) {
            case 'upsert_block':
                if ($name === null) {
                    throw new \InvalidArgumentException('upsert_block needs "name".');
                }

                if ($css === null) {
                    throw new \InvalidArgumentException('upsert_block needs "css".');
                }

                CssBlocks::assertName($name);
                $this->assertValid($css);
                $after = CssBlocks::upsert($before, $name, $css, $position)['css'];
                $warnings = CssBlocks::warnings($css);
                break;

            case 'remove_block':
                if ($name === null) {
                    throw new \InvalidArgumentException('remove_block needs "name".');
                }

                if ($css !== null) {
                    throw new \InvalidArgumentException('remove_block does not take "css".');
                }

                $after = CssBlocks::remove($before, $name)['css'];
                break;

            default: // replace_all
                if (! $confirm) {
                    throw new \InvalidArgumentException('replace_all replaces the whole stylesheet, including CSS outside the managed blocks. Pass confirm_replace_all: true.');
                }

                if ($css === null) {
                    throw new \InvalidArgumentException('replace_all needs "css".');
                }

                if ($name !== null) {
                    throw new \InvalidArgumentException('replace_all does not take "name".');
                }

                $this->assertValid($css);
                $after = $css;
                $warnings = CssBlocks::warnings($css);
                break;
        }

        if (strlen($after) > CssBlocks::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Global CSS would be %d bytes; the limit is %d.',
                strlen($after),
                CssBlocks::MAX_BYTES
            ));
        }

        if ($operation !== 'replace_all' && CssBlocks::outside($after) !== CssBlocks::outside($before)) {
            throw new \RuntimeException('The edit would change CSS outside the managed blocks; nothing was written.');
        }

        $result = [
            'operation'    => $operation,
            'name'         => $name,
            'option_key'   => $key,
            'changed'      => $after !== $before,
            'bytes_before' => strlen($before),
            'bytes_after'  => strlen($after),
            'diff'         => LineDiff::unified($before, $after),
            'blocks'       => array_column(CssBlocks::parse($after)['blocks'], 'name'),
            'dry_run'      => $dryRun,
            'backup_id'    => null,
            'write_path'   => null,
            'warnings'     => $warnings,
        ];

        if ($dryRun || $after === $before) {
            return $result;
        }

        $backup = $this->backups->backup('global_css', 'set_global_css');
        $write = $this->gateway->updateThemeOption($key, $after);

        $stored = $this->gateway->getThemeOption($key);

        if (! is_string($stored) || $stored !== $after) {
            $write['warnings'][] = 'The stored Global CSS differs from what was written; check for a filter on the option.';
        }

        $result['backup_id'] = $backup['backup_id'];
        $result['write_path'] = $write['path'];
        $result['warnings'] = array_merge($warnings, $write['warnings']);

        return $result;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValid(string $css): void
    {
        $errors = CssBlocks::inputErrors($css);

        if ($errors !== []) {
            throw new \InvalidArgumentException('Refused: ' . implode(' ', $errors));
        }

        if (strlen($css) > CssBlocks::MAX_BYTES) {
            throw new \InvalidArgumentException('The CSS is larger than 256 KB.');
        }
    }

    public function annotations(): array
    {
        return Annotations::write('Set Global CSS', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
