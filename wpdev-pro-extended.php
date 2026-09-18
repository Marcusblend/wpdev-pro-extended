<?php

/**
 * Plugin Name: Pro Extended
 * Plugin URI:  https://github.com/renandadalte/wpdev-pro-extended
 * Description: Extends Pro Theme / Cornerstone with MCP Server, Design Tokens, Element Defaults, and developer utilities.
 * Version:     1.3.0
 * Author:      Renan Dadalte
 * Author URI:  https://github.com/renandadalte
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpdev-pro-extended
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────
define('PE_VERSION', '1.3.0');
define('PE_FILE', __FILE__);
define('PE_DIR', plugin_dir_path(__FILE__));
define('PE_URL', plugin_dir_url(__FILE__));
define('PE_BASENAME', plugin_basename(__FILE__));

// ─── Autoloader (built-in PSR-4) ─────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $prefix = 'ProExtended\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = PE_DIR . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ─── Theme Dependency Guard ──────────────────────────────────────────────────

/**
 * Check if the Pro Theme (or a child theme of Pro) is active.
 */
function pe_is_pro_theme_active(): bool
{
    $theme = wp_get_theme();

    // Check current theme or parent theme
    $template = $theme->get_template();

    return $template === 'pro';
}

/**
 * Deactivate this plugin and show an admin notice.
 */
function pe_deactivate_with_notice(): void
{
    deactivate_plugins(PE_BASENAME);

    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Pro Extended has been deactivated because the Pro Theme is not active. Please activate Pro Theme (or a child theme of Pro) first.',
            'wpdev-pro-extended'
        );
        echo '</p></div>';
    });
}

// Block activation if Pro Theme is not active.
register_activation_hook(__FILE__, function (): void {
    if (! pe_is_pro_theme_active()) {
        deactivate_plugins(PE_BASENAME);
        wp_die(
            esc_html__('Pro Extended requires the Pro Theme (or a child theme) to be active.', 'wpdev-pro-extended'),
            esc_html__('Plugin Activation Error', 'wpdev-pro-extended'),
            ['back_link' => true]
        );
    }
});

// Deactivate if user switches away from Pro Theme.
add_action('switch_theme', function (): void {
    if (! pe_is_pro_theme_active()) {
        pe_deactivate_with_notice();
    }
});

// ─── Bootstrap ───────────────────────────────────────────────────────────────

// Bail early if Pro Theme is not active (defensive check for edge cases).
if (! pe_is_pro_theme_active()) {
    return;
}

/**
 * Global accessor for the plugin instance.
 */
function pro_extended(): ProExtended\Plugin
{
    static $instance = null;

    if ($instance === null) {
        $instance = new ProExtended\Plugin();
    }

    return $instance;
}

// Initialize on plugins_loaded (priority 20) so the REST routes and CLI
// commands are registered before the request is routed.
add_action('plugins_loaded', function (): void {
    pro_extended()->boot();
}, 20);

// Cornerstone ships with the Pro Theme, and WordPress loads themes *after*
// `plugins_loaded` fires — so no priority on that hook can observe it. Checking
// there reported Cornerstone missing on every request. `admin_init` runs after
// the theme is loaded, and an admin notice is only ever seen in the admin.
add_action('admin_init', function (): void {
    if (function_exists('cornerstone')) {
        return;
    }

    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'Pro Extended: Cornerstone is not available, so the element schema and layout validation tools will return empty results.',
            'wpdev-pro-extended'
        );
        echo '</p></div>';
    });
});
