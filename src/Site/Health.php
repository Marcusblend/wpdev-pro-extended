<?php

declare(strict_types=1);

namespace ProExtended\Site;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\Server;
use ProExtended\Media\MediaImporter;
use ProExtended\Support\SkipValidation;

/**
 * Checks that decide whether Pro Extended can work on this site.
 */
final class Health
{
    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly HostCache $hostCache,
        private readonly ?Server $server = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $structure = (string) get_option('permalink_structure', '');

        try {
            $registry = $this->gateway->componentRegistry();
            $components = [
                'source' => $registry['source'],
                'count'  => count($registry['components']),
                'errors' => $registry['errors'],
            ];
        } catch (\Throwable $e) {
            $components = ['source' => null, 'count' => null, 'errors' => ['The component registry could not be read: ' . $e->getMessage()]];
        }

        $host = $this->hostCache->availability();

        return [
            'cornerstone_available'        => function_exists('cornerstone'),
            'permalinks'                   => [
                'structure'    => $structure,
                'pretty'       => $structure !== '',
                'mcp_endpoint' => $structure !== ''
                    ? rest_url('pro-extended/v1/mcp')
                    : add_query_arg('rest_route', '/pro-extended/v1/mcp', home_url('/')),
            ],
            'application_passwords_in_use' => class_exists('WP_Application_Passwords') && \WP_Application_Passwords::is_in_use(),
            'blog_public'                  => (string) get_option('blog_public'),
            'breakpoint_ranges_saved'      => $this->optionExists('x_breakpoint_ranges'),
            'current_user'                 => [
                'id'              => get_current_user_id(),
                'unfiltered_html' => current_user_can('unfiltered_html'),
                'edit_css'        => current_user_can('edit_css'),
            ],
            'global_css_key'               => $this->gateway->globalCssKey(),
            'cornerstone_adapter'          => $this->gateway->capabilities(),
            'component_registry'           => $components,
            'host_cache'                   => [
                'host'            => $host['host'],
                'purge_available' => $host['methods'] !== [],
                'methods'         => $host['methods'],
            ],
            'settings'                     => [
                'pe_allow_skip_validation' => SkipValidation::setting(),
                'pe_allow_svg_uploads'     => MediaImporter::svgAllowed() ? '1' : '0',
            ],
            'environment_type'             => wp_get_environment_type(),
            'tool_registration_errors'     => $this->server !== null ? (object) $this->server->getRegistrationErrors() : (object) [],
            'features'                     => Features::report(),
        ];
    }

    /**
     * The report as pass/warn/fail lines.
     *
     * @param  array<string, mixed>|null $report
     * @return array<int, array{status: string, check: string, detail: string}>
     */
    public function checks(?array $report = null): array
    {
        $r = $report ?? $this->report();
        $lines = [];

        $add = static function (string $status, string $check, string $detail) use (&$lines): void {
            $lines[] = ['status' => $status, 'check' => $check, 'detail' => $detail];
        };

        $r['cornerstone_available']
            ? $add('pass', 'Cornerstone', 'available')
            : $add('fail', 'Cornerstone', 'not available (activate the Pro theme)');

        $r['permalinks']['pretty']
            ? $add('pass', 'Permalinks', $r['permalinks']['structure'])
            : $add('fail', 'Permalinks', 'Plain permalinks: /wp-json/ serves the homepage. Choose a pretty structure, or use ' . $r['permalinks']['mcp_endpoint']);

        $r['application_passwords_in_use']
            ? $add('pass', 'Application passwords', 'in use')
            : $add('fail', 'Application passwords', 'not in use yet: WordPress ignores Basic auth until one is created in Users → Profile');

        $errors = (array) $r['tool_registration_errors'];
        $errors === []
            ? $add('pass', 'MCP tools', 'all registered')
            : $add('fail', 'MCP tools', 'not registered: ' . implode('; ', array_map(static fn($k, $v) => $k . ' (' . $v . ')', array_keys($errors), $errors)));

        $adapter = (array) $r['cornerstone_adapter'];
        $missing = array_keys(array_filter($adapter, static fn($v, $k) => $k !== 'force_fallback_enabled' && ! $v, ARRAY_FILTER_USE_BOTH));
        $missing === []
            ? $add('pass', 'Cornerstone adapter', 'all entry points available')
            : $add('warn', 'Cornerstone adapter', 'unavailable (fallback used): ' . implode(', ', $missing));

        if (! empty($adapter['force_fallback_enabled'])) {
            $add('warn', 'Cornerstone adapter', 'pe_force_fallback is on: writes skip the Document API');
        }

        $r['breakpoint_ranges_saved']
            ? $add('pass', 'Breakpoints', 'ranges saved')
            : $add('warn', 'Breakpoints', 'ranges never saved (save Theme Options once so _bp_data matches the site)');

        $r['settings']['pe_allow_skip_validation'] === '0'
            ? $add('pass', 'skip_validation', 'locked (pe_allow_skip_validation = 0)')
            : $add('warn', 'skip_validation', 'allowed (pe_allow_skip_validation = 1)');

        $r['current_user']['unfiltered_html']
            ? $add('pass', 'unfiltered_html', 'user ' . $r['current_user']['id'] . ' has it')
            : $add('warn', 'unfiltered_html', 'user ' . $r['current_user']['id'] . ' lacks it: document writes will be refused');

        $r['current_user']['edit_css']
            ? $add('pass', 'edit_css', 'user ' . $r['current_user']['id'] . ' has it')
            : $add('warn', 'edit_css', 'user ' . $r['current_user']['id'] . ' lacks it: Global CSS writes will be refused');

        $registryErrors = (array) ($r['component_registry']['errors'] ?? []);
        $registryErrors === []
            ? $add('pass', 'Component registry', sprintf('%s components', (string) ($r['component_registry']['count'] ?? '?')))
            : $add('warn', 'Component registry', implode(' ', $registryErrors));

        $r['host_cache']['purge_available']
            ? $add('pass', 'Host cache', ($r['host_cache']['host'] ?? 'host') . ': ' . implode(', ', $r['host_cache']['methods']))
            : $add('warn', 'Host cache', 'WP Engine cache purging is not available');

        foreach (Features::checks((array) ($r['features'] ?? [])) as $line) {
            $lines[] = $line;
        }

        $add('info', 'Environment', (string) $r['environment_type']);
        $add('info', 'Global CSS option', (string) $r['global_css_key']);
        $add('info', 'Search engines', $r['blog_public'] === '0' ? 'discouraged (blog_public = 0)' : 'allowed (blog_public = 1)');
        $add('info', 'SVG uploads', $r['settings']['pe_allow_svg_uploads'] === '1' ? 'allowed' : 'off');

        return $lines;
    }

    private function optionExists(string $option): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            $option
        )) > 0;
    }
}
