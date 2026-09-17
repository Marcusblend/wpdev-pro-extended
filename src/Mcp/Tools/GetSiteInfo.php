<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Site\Health;

final class GetSiteInfo implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly ?Health $health = null,
    ) {}

    public function name(): string
    {
        return 'get_site_info';
    }

    public function description(): string
    {
        return 'Get WordPress site information including versions, theme details, breakpoint configuration, and active plugins, plus a health block (permalinks, application passwords, capabilities, Cornerstone adapter, component registry, cache purging, settings).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object) [],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $theme = wp_get_theme();

        // Breakpoint configuration.
        $bpBase = (int) get_option('x_breakpoint_base', 4);
        $bpRanges = get_option('x_breakpoint_ranges', []);

        // `get_plugin_data()` lives in wp-admin/includes/plugin.php, which
        // WordPress does not load on REST requests. Without this the tool only
        // works when some other plugin happens to have included that file.
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Active plugins (names only, no paths).
        $activePlugins = get_option('active_plugins', []);
        $pluginNames = [];
        foreach ($activePlugins as $plugin) {
            $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
            $pluginNames[] = $pluginData['Name'] ?? basename($plugin, '.php');
        }

        $info = [
            'site_url'        => get_site_url(),
            'home_url'        => get_home_url(),
            'wordpress'       => get_bloginfo('version'),
            'php'             => phpversion(),
            'theme'           => [
                'name'     => $theme->get('Name'),
                'version'  => $theme->get('Version'),
                'template' => $theme->get_template(),
                'is_child' => $theme->get_template() !== $theme->get_stylesheet(),
            ],
            'cornerstone'     => [
                'available'  => function_exists('cornerstone'),
                'version'    => defined('CS_VERSION') ? CS_VERSION : null,
            ],
            'pro_extended'    => [
                'version' => PE_VERSION,
            ],
            'breakpoints'     => [
                'base'   => $bpBase,
                'ranges' => $bpRanges,
                'total'  => $bpBase + 1,
            ],
            'active_plugins'  => $pluginNames,
        ];

        if ($this->health !== null) {
            $info['health'] = $this->health->report();
        }

        return $info;
    }

    public function annotations(): array
    {
        return Annotations::read('Get Site Info');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
