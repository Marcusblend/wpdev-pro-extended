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
     * A tool that is absent from this map is not gated by Cornerstone, either
     * because it reads something WordPress already governs (menus, media, site
     * information) or because it only reports.
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

        // Components.
        'list_components'          => 'component',

        // Globals.
        'set_colors'               => 'global.colors',
        'set_fonts'                => 'global.fonts',
        'set_global_css'           => 'global.theme_options',
        'get_global_css'           => 'global.theme_options',
        'get_theme_options'        => 'global.theme_options',
        'update_theme_options'     => 'global.theme_options',
        'set_variables'            => 'global.variables',
        'set_global_parameters'    => 'global.theme_options',

        // Library.
        'list_templates'           => 'template',
        'get_template'             => 'template',
        'create_template'          => 'template.manage_library',
        'export_tco'               => 'template.manage_library',
        'import_tco'               => 'template.manage_library',

        // Elements.
        'list_elements'            => 'element-library',
        'get_element_schema'       => 'element-library',
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
