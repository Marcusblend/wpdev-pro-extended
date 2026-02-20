<?php

/**
 * Plugin Name: Pro Extended
 * Plugin URI:  https://github.com/renandadalte/wpdev-pro-extended
 * Description: Extends Pro Theme / Cornerstone with MCP Server, Design Tokens, Element Defaults, and developer utilities.
 * Version:     1.0.0-alpha
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
define('PE_VERSION', '1.0.0-alpha');
define('PE_FILE', __FILE__);
define('PE_DIR', plugin_dir_path(__FILE__));
define('PE_URL', plugin_dir_url(__FILE__));
define('PE_BASENAME', plugin_basename(__FILE__));

// ─── Autoloader ──────────────────────────────────────────────────────────────
$autoloader = PE_DIR . 'vendor/autoload.php';

if (! file_exists($autoloader)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Pro Extended: Composer autoloader not found. Run `composer dump-autoload` in the plugin directory.', 'wpdev-pro-extended');
        echo '</p></div>';
    });
    return;
}

require_once $autoloader;

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

// Initialize on plugins_loaded (priority 20 to ensure Pro Theme is loaded).
add_action('plugins_loaded', function (): void {
    // Extra safety check: ensure Cornerstone is available.
    if (! function_exists('cornerstone')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Pro Extended: Cornerstone is not available. Some features will be disabled.', 'wpdev-pro-extended');
            echo '</p></div>';
        });
    }

    pro_extended()->boot();
}, 20);
