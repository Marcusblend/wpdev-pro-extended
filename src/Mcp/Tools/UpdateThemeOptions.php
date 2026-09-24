<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentAssets;
use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\Permissions;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\ThemeOptionsReader;
use ProExtended\Settings\ThemeOptionsWriter;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class UpdateThemeOptions implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
        private readonly ThemeOptionsReader $reader,
    ) {}

    public function name(): string
    {
        return 'update_theme_options';
    }

    public function description(): string
    {
        return 'Write Theme Options. options is a map of key => value; get_theme_options lists the keys, their labels, current values and defaults. Each key is saved the way the Theme Options panel saves it (the before and after save actions fire, then the generated styles are purged), and every key is backed up first so restore_settings can put it back. A key this site does not register is refused, as are the keys another tool owns — Global CSS and JS, the palette, fonts — and the breakpoint and stack keys, which would reinterpret stored element data. Responsive variants ("<key>_bp_data4_4") are allowed alongside their key. Twig is switched on here: {"cs_twig_enabled": true}, with its sub-toggles (cs_twig_extension_wordpress, cs_twig_extension_html_extra, cs_twig_extension_string_extra, cs_twig_extension_directory_loader, cs_twig_extension_debug, cs_twig_autoescape, cs_twig_cache); cs_twig_extension_advanced, which lets Twig run any PHP function, is refused. Twig templates are cs_twig_templates, a list of {"id", "title", "template"} checked before it is written (include one with {% include \'cs-template:<id>\' %}; get_native_reference section "twig" lists them). Site-wide Custom Assets are cs_custom_scripts ({src, id, type, deps, ver, async, defer, nomodule, in_footer}) and cs_custom_styles ({src, id, rel, media}): https URLs only, checked and completed with the builder\'s defaults, and they need unfiltered_html and Cornerstone\'s global.document_assets permission. Run with dry_run: true first: it reports the before and after of every key without writing.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['options'],
            'properties' => [
                'options' => [
                    'type'        => ['object', 'string'],
                    'description' => 'Theme option key => value. Accepts a JSON string.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report the diff and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['options', 'dry_run'], 'arguments');

        $arguments = JsonArgs::decode($arguments, ['options']);
        $options = Args::object($arguments, 'options');
        $dryRun = Args::bool($arguments, 'dry_run', false);

        if ($options === null || $options === []) {
            throw new \InvalidArgumentException('Pass options: a map of theme option key => value.');
        }

        $snapshot = $this->reader->snapshot();
        $plan = ThemeOptionsWriter::plan($options, $snapshot['keys'], $snapshot['values']);

        if ($plan['errors'] !== []) {
            throw new \InvalidArgumentException("These options could not be written:\n- " . implode("\n- ", $plan['errors']));
        }

        // Site-wide Custom Assets load external scripts on every page.
        if (ThemeOptionsWriter::touchesDocumentAssets($plan['writes'])) {
            if (! current_user_can('unfiltered_html')) {
                throw new ToolPermissionException('Writing cs_custom_scripts or cs_custom_styles requires the unfiltered_html capability.');
            }

            if ((new Permissions())->userCan(DocumentAssets::PERMISSION) === false) {
                throw new ToolPermissionException(sprintf('Cornerstone denies "%s" to this user, which Custom Assets need.', DocumentAssets::PERMISSION));
            }
        }

        $result = [
            'dry_run'   => $dryRun,
            'changes'   => array_map(static fn (array $write): array => [
                'key'        => $write['key'],
                'from'       => self::show($write['key'], $write['from']),
                'to'         => self::show($write['key'], $write['to']),
                'responsive' => $write['responsive'],
            ], $plan['writes']),
            'unchanged' => $plan['unchanged'],
        ];

        if ($plan['writes'] === []) {
            $result['note'] = 'Every key already holds the value given, so nothing would change.';

            return $result;
        }

        if ($dryRun) {
            return $result;
        }

        $warnings = [];
        $written = [];
        $backups = [];

        foreach ($plan['writes'] as $write) {
            // Back up before each write, so a key can be put back on its own.
            $backup = $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . $write['key'], 'update_theme_options');
            $backups[$write['key']] = $backup['backup_id'] ?? null;

            $outcome = $this->gateway->updateThemeOption($write['key'], $write['to']);
            $written[] = $write['key'];
            $warnings = array_merge($warnings, $outcome['warnings']);
            $result['write_path'] = $outcome['path'];
            $result['purge'] = $outcome['purge'];
        }

        $result['backups'] = $backups;
        $result['written'] = $written;

        if ($warnings !== []) {
            $result['warnings'] = array_values(array_unique($warnings));
        }

        return $result;
    }

    /**
     * A value as it can safely be reported: secrets and code by shape only.
     */
    private static function show(string $key, mixed $value): mixed
    {
        if (preg_match(ThemeOptionsReader::SECRET_PATTERN, $key)) {
            return ['redacted' => true, 'set' => $value !== null && $value !== ''];
        }

        if (is_array($value)) {
            return ['type' => 'array', 'count' => count($value)];
        }

        if (is_string($value) && strlen($value) > ThemeOptionsReader::MAX_VALUE_BYTES) {
            return ['type' => 'string', 'bytes' => strlen($value)];
        }

        return $value;
    }

    public function annotations(): array
    {
        return Annotations::write('Update Theme Options', true, false);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
