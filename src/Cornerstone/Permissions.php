<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Cornerstone's own permissions, beside WordPress's capabilities.
 *
 * A site can give an editor edit_posts and still take Cornerstone access away
 * from them — the builder checks its own permission keys, and a role with
 * "layout" removed cannot touch headers and footers however many WordPress
 * capabilities it has. A tool that checked only the capability would let that
 * user write what the builder would refuse, which is the kind of difference
 * that only shows up as a support call.
 *
 * The map lives here rather than on each tool so the whole policy can be read
 * at once, and so a tool without an entry is obviously ungated rather than
 * accidentally so.
 */
final class Permissions
{
    /**
     * Tool name => the Cornerstone permission its work needs.
     *
     * Every registered tool is either here or in EXEMPT, and a unit test holds
     * the two lists to the registry, so a new tool has to be placed in one of
     * them on purpose.
     */
    public const TOOL_KEYS = [
        // Documents and layouts.
        'create_page'              => 'content.page',
        'create_document'          => 'layout',
        'deploy_layout'            => 'layout',
        'update_layout'            => 'layout',
        'update_document_settings' => 'layout',
        'restore_layout'           => 'layout',
        'backup_layout'            => 'layout',
        'list_layouts'             => 'layout',
        'get_layout'               => 'layout',

        // Components.
        'list_components'          => 'component',
        'create_component'         => 'component',

        // Globals.
        'set_colors'               => 'global.colors',
        'set_fonts'                => 'global.fonts',
        'set_global_css'           => 'global.edit_custom_css',
        'get_global_css'           => 'global.theme_options',
        'get_theme_options'        => 'global.theme_options',
        'update_theme_options'     => 'global.theme_options',
        'set_variables'            => 'global.variables',
        'set_global_parameters'    => 'global.theme_options',
        'create_snapshot'          => 'global.theme_options',
        'restore_snapshot'         => 'global.theme_options',
        'list_settings_backups'    => 'global',
        'restore_settings'         => 'global',
        // Cornerstone's own dashboard saves the allowlist with manage_options
        // alone, which the tool requires too; "global" is the family closest
        // to it, so a role denied Cornerstone's global settings cannot widen
        // what the site's loopers may call.
        'set_api_allowlist'        => 'global',

        // Library.
        'list_templates'           => 'template',
        'get_template'             => 'template',
        'create_template'          => 'template.manage_library',
        'export_tco'               => 'template.manage_library',
        'import_tco'               => 'template.manage_library',

        // Elements, and what the builder offers while editing them.
        'list_elements'            => 'element-library',
        'get_element_schema'       => 'element-library',
        'validate_layout'          => 'element-library',
        'list_prefabs'             => 'element-library',
        'list_dynamic_content'     => 'element-library',
        'render_preview'           => 'element-library',
    ];

    /**
     * Tools deliberately not gated by a Cornerstone permission, and why.
     */
    public const EXEMPT = [
        'list_menus'            => 'WordPress menus, governed by WordPress capabilities.',
        'create_menu'           => 'WordPress menus, governed by WordPress capabilities.',
        'update_menu'           => 'WordPress menus, governed by WordPress capabilities.',
        'upload_media'          => 'The WordPress Media Library, governed by upload_files.',
        'get_site_info'         => 'Site information; it reports the caller\'s Cornerstone permissions rather than needing one.',
        'get_platform_baseline' => 'A fingerprint of the platform (versions, registries, key names), not of the site\'s content.',
        'get_write_journal'     => 'Pro Extended\'s own record of its writes, not Cornerstone data.',
        'clear_cache'           => 'Clears caches and changes no content; Cornerstone clears its own from the dashboard with manage_options alone.',
        'list_colors'           => 'The palette every builder user sees in a colour picker; changing it (set_colors) is gated.',
        'list_fonts'            => 'The fonts every builder user sees in a font picker; changing them (set_fonts) is gated.',
        // Pending: the translation work gates this per document type and moves
        // it into TOOL_KEYS; until then it keeps its WordPress checks.
        'create_translation'    => 'Checks edit rights on the source post and the post type\'s publish capability itself.',
    ];

    /**
     * Whether the current user has a Cornerstone permission.
     *
     * Null means the question could not be asked — Cornerstone is not loaded,
     * or its permission service is not the shape we expect — in which case the
     * caller should let the WordPress capability decide rather than refuse.
     */
    public function userCan(string $key): ?bool
    {
        if ($key === '' || ! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone('Permissions');

            if (! is_object($service) || ! method_exists($service, 'userCan')) {
                return null;
            }

            return (bool) $service->userCan($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Cornerstone permission a tool needs, if any.
     */
    public static function forTool(string $tool): ?string
    {
        return self::TOOL_KEYS[$tool] ?? null;
    }

    /**
     * Why a tool is refused, or null when it is allowed.
     */
    public function denialFor(string $tool): ?string
    {
        $key = self::forTool($tool);

        if ($key === null) {
            return null;
        }

        return $this->userCan($key) === false
            ? sprintf(
                'Cornerstone denies "%s" to this user, which is what "%s" needs. The WordPress capability is not the only check: Cornerstone keeps its own permissions, and the builder would refuse this too.',
                $key,
                $tool
            )
            : null;
    }
}
