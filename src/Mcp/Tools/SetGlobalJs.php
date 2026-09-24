<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Js\JsBlocks;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Support\Args;
use ProExtended\Support\LineDiff;

final class SetGlobalJs implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['operation', 'name', 'js', 'position', 'confirm_replace_all', 'dry_run'];
    private const OPERATIONS = ['upsert_block', 'remove_block', 'replace_all'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'set_global_js';
    }

    public function description(): string
    {
        return 'Last resort for behaviour: first use a native element or effect (sticky bars, off-canvas or collapsed navigation, accordion, tabs, scroll effects, Cornerstone Forms) — this is the home for the few lines of script a build cannot avoid, never a plugin or wp_head output. Edits Global JS (Theme Options → JS), which Cornerstone prints in its own script tag, through named blocks Pro Extended manages (// pe:begin <name> … // pe:end <name>, each on its own line). upsert_block creates or replaces a block, remove_block deletes one; script outside the blocks is never changed. replace_all replaces the whole script and needs confirm_replace_all: true. Needs manage_options and unfiltered_html. Refuses "<script", "</script", "<?" and marker text; warns on "{{"/"{%" (Cornerstone runs Global JS through Dynamic Content and Twig), eval and document.write. Returns a diff, sizes and a backup_id (restore_settings with key "option:<option_key>"). Run with dry_run: true first.';
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
                'js' => [
                    'type'        => 'string',
                    'description' => 'The block\'s JavaScript (upsert_block) or the whole script (replace_all). No <script> tags.',
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
        if (! current_user_can('unfiltered_html')) {
            throw new ToolPermissionException('set_global_js requires the unfiltered_html capability.');
        }

        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $operation = Args::enum($arguments, 'operation', self::OPERATIONS, null);

        if ($operation === null) {
            throw new \InvalidArgumentException('"operation" is required.');
        }

        $name = Args::string($arguments, 'name', null, 100);
        $js = Args::string($arguments, 'js', null);
        $position = Args::enum($arguments, 'position', ['end', 'start'], 'end') ?? 'end';
        $confirm = Args::bool($arguments, 'confirm_replace_all', false);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        $key = $this->gateway->globalJsKey();
        $current = $this->gateway->getThemeOption($key);
        $before = is_string($current) ? $current : '';

        $plan = self::plan($before, $operation, $name, $js, $position, $confirm);
        $after = $plan['after'];

        $result = [
            'operation'    => $operation,
            'name'         => $name,
            'option_key'   => $key,
            'changed'      => $after !== $before,
            'bytes_before' => strlen($before),
            'bytes_after'  => strlen($after),
            'diff'         => LineDiff::unified($before, $after),
            'blocks'       => array_column(JsBlocks::parse($after)['blocks'], 'name'),
            'dry_run'      => $dryRun,
            'backup_id'    => null,
            'write_path'   => null,
            'warnings'     => $plan['warnings'],
        ];

        if ($dryRun || $after === $before) {
            return $result;
        }

        $backup = $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . $key, 'set_global_js');
        $write = $this->gateway->updateThemeOption($key, $after);

        $stored = $this->gateway->getThemeOption($key);

        if (! is_string($stored) || $stored !== $after) {
            $write['warnings'][] = 'The stored Global JS differs from what was written; check for a filter on the option.';
        }

        $result['backup_id'] = $backup['backup_id'];
        $result['restore_key'] = SettingsBackups::OPTION_KEY_PREFIX . $key;
        $result['write_path'] = $write['path'];
        $result['warnings'] = array_merge($plan['warnings'], $write['warnings']);

        return $result;
    }

    /**
     * Work out the new script for an operation. Pure, so the refusals are
     * unit-tested.
     *
     * @return array{after: string, warnings: string[]}
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function plan(string $before, string $operation, ?string $name, ?string $js, string $position = 'end', bool $confirm = false): array
    {
        $warnings = [];

        switch ($operation) {
            case 'upsert_block':
                if ($name === null) {
                    throw new \InvalidArgumentException('upsert_block needs "name".');
                }

                if ($js === null) {
                    throw new \InvalidArgumentException('upsert_block needs "js".');
                }

                JsBlocks::assertName($name);
                self::assertValid($js);
                $after = JsBlocks::upsert($before, $name, $js, $position)['js'];
                $warnings = JsBlocks::warnings($js);
                break;

            case 'remove_block':
                if ($name === null) {
                    throw new \InvalidArgumentException('remove_block needs "name".');
                }

                if ($js !== null) {
                    throw new \InvalidArgumentException('remove_block does not take "js".');
                }

                $after = JsBlocks::remove($before, $name)['js'];
                break;

            case 'replace_all':
                if (! $confirm) {
                    throw new \InvalidArgumentException('replace_all replaces the whole script, including JavaScript outside the managed blocks. Pass confirm_replace_all: true.');
                }

                if ($js === null) {
                    throw new \InvalidArgumentException('replace_all needs "js".');
                }

                if ($name !== null) {
                    throw new \InvalidArgumentException('replace_all does not take "name".');
                }

                self::assertValid($js);
                $after = $js;
                $warnings = JsBlocks::warnings($js);
                break;

            default:
                throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', $operation));
        }

        if (strlen($after) > JsBlocks::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('Global JS would be %d bytes; the limit is %d.', strlen($after), JsBlocks::MAX_BYTES));
        }

        if ($operation !== 'replace_all' && JsBlocks::outside($after) !== JsBlocks::outside($before)) {
            throw new \RuntimeException('The edit would change JavaScript outside the managed blocks; nothing was written.');
        }

        return ['after' => $after, 'warnings' => $warnings];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function assertValid(string $js): void
    {
        $errors = JsBlocks::inputErrors($js);

        if ($errors !== []) {
            throw new \InvalidArgumentException('Refused: ' . implode(' ', $errors));
        }
    }

    public function annotations(): array
    {
        return Annotations::write('Set Global JS', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
