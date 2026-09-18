# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`list_dynamic_content`**: the Dynamic Content tokens this site has — every group and field, the token each writes (`{{dc:post:title}}`) and the named arguments a field takes, with an example. Which groups exist depends on what is installed, and a token for a field the site lacks renders as nothing rather than erroring, so the catalog is worth having before writing one
- **Editing operations on `update_layout`**: `move` (with `to`, the path it lands at), `duplicate` (a copy beside it, without its ids, since two elements cannot claim one), `wrap` (into the element in `value`), `unwrap` (leaving its children where it was) and `prefab` (one of Cornerstone's prefab elements by `group` and `name`, already configured). A move into the element's own subtree is refused, and a failed move puts the element back rather than leaving it nowhere
- **`render_preview`**: renders elements to HTML without saving — either elements passed in, or part of a stored layout by `post_id` and `path` — against a chosen post, so loopers, conditions and tokens resolve as they would on the front end. A looper with no results, a condition that hides its element and a token that resolves to nothing all save without complaint; this is where they become visible, and the result says when the render came out empty. Defaults are applied at every level (an element rendered without them emits broken markup) and Cornerstone's own notices for the bookkeeping keys a loose subtree lacks are kept out of the response
- **`get_write_journal`**: what the plugin has changed on this site, newest first — tool, user, time, what it touched and a short summary. Every write is recorded, dry runs included and filterable, where the settings backups only cover the few things they hold

## [1.3.0] - 2026-09-18

"Site Systems": Pro Extended can now write the parts of a site a build actually starts with — menus and Pro headers, the template library, Theme Options, global variables and parameters, page-level layout overrides and components — and, following the Inspector, it can author elements through their own settings rather than a block of CSS. Existing tools keep their names, required inputs and result shapes, with one documented exception: `get_element_schema` returns the control surface by default and its previous output under `format: "raw"`.

### Added

- **`create_menu` and `update_menu`**: navigation menus with items, order, nesting, theme locations and the anchor graphic meta Cornerstone's navigation elements read. Operations run in order and can refer to items added earlier in the same call by `ref`; removal needs `force`, locations change only when named, and `dry_run` reports what would be written
- **Header presets on `create_document`** (`preset`, `preset_options`): `header.simple`, `header.mega` and `mega_menu_panel` build bar to container to navigation, with a dropdown panel of link columns for a mega menu. Generated elements are stamped, so a collapsed navigation does not fall back to the legacy off-canvas behaviour
- **`get_element_schema` control surface**: the settings an element type has, grouped as the builder's Inspector groups them — tab, panel, label, control type, accepted values, defaults, and the flat key or keys each control writes, including `named_keys` for controls that write several at once (`font_size` to `text_font_size`). `writes` says whether a setting changes style, markup or both. `search` narrows to matching controls. Cornerstone assembles this only in a builder context, so the plugin enters one on this read path, once per request, and caches the result
- **`elements` in `clear_cache`**: drops the cached element definitions and control surfaces, which a Cornerstone update changes
- **`create_component`**: makes a reusable component from elements, or lifts a subtree out of an existing layout with `from_document` and `path`. `exports` names the elements an instance may edit (each gets `_c_export`, `_c_id` and `_label`), `slots` the ones an instance may fill, and `parameters` writes a `_p_json` schema so values can be driven by name. The top-level element is exported automatically — a component document holds exported elements rather than a wrapper, so without that it would save and never appear in the library — and the elements are written as the flat `e0`/`e1` map Cornerstone's component registry scans, not as a nested tree
- **`list_prefabs`**: Cornerstone's prefab elements by group, and one prefab's element values ready to insert with `update_layout`. They arrive configured and stamped, which is a better starting point than an element with defaults
- **Cornerstone permission parity**: every tool that writes or reads through Cornerstone now declares the Cornerstone permission its work needs beside its WordPress capability, and both are checked. A site can give a role `edit_posts` and still remove its Cornerstone access; without this a tool would write what the builder itself would refuse. A denial names the failing key and says which check it was
- **`get_platform_baseline`**: a fingerprint of the Cornerstone platform — versions, element types and their migration versions, document types, theme option keys, dynamic content groups, looper providers and permissions — compared against the last stored one. The diff says which element types appeared, which migrations moved and which token groups are new, which is how a Themeco release gets noticed; `save: true` records the current fingerprint
- **Extension awareness** (read only): `get_platform_baseline` reports each known Cornerstone extension — Data Tables, Forms, Charts, SiteDrive, ACF Pro and The Events Calendar — with its version and which of the elements, token groups, looper providers and post types it brings the site actually registered. No package URL, licence or key is ever reported
- **WPML awareness**: `get_site_info` reports the site's languages, default and current; `list_layouts` and `list_templates` query every language the way Cornerstone's own document queries do and carry each row's language and its translations; `create_translation` copies a page or document into another language and joins it to the source's translation group. All of it is inert when WPML is not active
- **Page-level settings on `update_document_settings`**: a page (or any content type) now takes `layoutSingle`, `layoutHeader`, `layoutFooter`, `customCSS` and `customJS`. An override is `"default"`, `"none"` or a document ID, and the referenced document has to exist and be of the matching type — otherwise the page quietly falls back. Settings are written as Cornerstone writes them, merged into `_cornerstone_settings` and then rebuilt into `post_content`, so the rendered page picks the override up immediately
- **`set_variables`**: Cornerstone Global Variables, added, changed or removed. Each becomes a CSS custom property on `:root`, so an element set to `var(--name)` follows the variable instead of carrying a copy of the value. Takes a `name => value` map or a list with per-breakpoint values, reports each variable's property and `var()` reference, and backs the list up first
- **`set_global_parameters`**: the site's parameter schema (`cs_global_parameter_json`) and values (`cs_global_parameter_data`), so element controls can be bound globally. Per-breakpoint values need `_bp_base` — without it Cornerstone stores them and renders nothing — so it is added when missing and said so in a warning; a tag from another site, and a responsive value with no parameter behind it, are flagged too
- **`update_theme_options`**: writes Theme Options the way the panel does — the before and after save actions fire, then the generated styles are purged — backing up each key first. A key the site does not register is refused, as are the keys another tool owns (Global CSS and JS, the palette, fonts) and the breakpoint and stack keys, which would reinterpret every element's stored data. Responsive variants (`<key>_bp_data4_4`) are allowed beside their key, and `dry_run` reports the before and after of each
- **Theme option backups**: `restore_settings` and `list_settings_backups` take `option:<theme option name>` alongside the four named settings, so a single option can be put back
- **Template library tools**: `list_templates` (by kind — block, preset or document — identifier or title), `get_template` (with the content Cornerstone stores: `elements` for a block or document, `atts` for a preset), `create_template` (from `content`, or `from_document` to capture an existing layout), `export_tco` and `import_tco`. A template identifier is checked against the site's element and document types before Cornerstone is asked to build one, because `Template::create()` fatals on a type it does not know. A preset's `atts` are typed with the element they belong to, without which Cornerstone's migrations drop every setting. `export_tco` drives Cornerstone's own exporter, so the archive matches the builder's; `import_tco` describes the archive and writes nothing until `confirm: true`, skipping entries this site cannot take rather than failing the rest
- **`preset` operation on `update_layout`**: applies a saved preset's settings to the element at a path (`{"op": "preset", "path": "0._modules.1", "preset": 122}`, an ID or the preset's exact title). The element keeps its content, id and children and takes the preset's styling keys; a preset for a different element type is refused, as is a template that is not a preset
- **`css-over-control` lint warning**: a `css` declaration that sets a property the element already has a style setting for is reported with the key to set instead, since a setting stays editable in the builder and can be bound to a parameter or global variable while a css block cannot. Only properties the element really has a control for are named, and a property with no setting (or an element the registry cannot describe) is left alone

### Fixed

- **Max package names printed blank**: Cornerstone stores no title on most `x_max_plugins` entries, so `get_site_info` reported every Max product with an empty name. A package now takes the name the entry carries, or its slug read as words
- **`set_fonts` refused any edit to a Google font while Google Fonts were off**, including a title-only change, because Cornerstone drops Google families from its font list when the feature is disabled. The font list is only consulted when the family itself changes

### Changed

- **`get_element_schema` returns the control surface by default.** The previous output — Cornerstone's full definition and defaults — is still available with `format: "raw"`

## [1.2.0] - 2026-09-17

"Builder Parity": Pro Extended now writes pages the way Cornerstone's builder does, gives new elements the markers the builder gives them, and warns about element data Cornerstone would render differently than intended. It also reports the site's Cornerstone feature switches, reads Theme Options, and removes palette and font entries safely. Existing tools keep their names, required inputs and result shapes (new optional inputs and additional result keys only).

### Added

- **`get_theme_options`** (tool 26): the Theme Options panel sections with key counts and changed counts, and for a section, a list of keys, a search or `changed_only`, each option's control label, value, default, changed flag, designation and per-breakpoint values. Secret-looking values are redacted, Global CSS/JS and large values are reported by size, and the breakpoint tag (site and stored) is reported
- **`stamp_new`** on `create_page`, `create_document` and `update_layout` (default `true`, `add` values only) and on `deploy_layout` (default `false`): missing `_m` migration markers (versions read from the live element registry) and `_bp_base` breakpoint tags are added to new elements; existing markers are never changed, and responsive data written for another breakpoint set keeps its own tag. Responses report `stamped`
- **Coded validator warnings** (`ElementLint`): missing or mismatched markers, `_bp_data` keys, lengths and base slots; classic, deprecated, v2 row/column and internal types; `show_condition` shape, unknown rules and string post IDs; looper flags, unregistered providers and providers whose feature is off; unclosed, multi-line or unquoted Dynamic Content tokens and tokens that read outside input; `custom_atts`, `_p_json` and `_p_data` types; table cell text, spans and tags and table section tags; container links without an href, nested links; background layers whose advanced switch is off. Validation results gain `issues` (code, `update_layout` path, element type, message), `codes` and `issue_count`; `warnings` summarizes the issues per code and element type, and the existing structural warnings gain codes (`unknown-element`, `invalid-child`, `component-instance`)
- **`get_site_info` `features`**: content storage mode, Twig, External API (with a description of its allowlist, never its entries), CSV, WPML, WooCommerce, ACF, the Max products the site knows about (whitelisted fields only, never package URLs) and the caller's Cornerstone permissions; `breakpoints.tag`. `wp pe doctor` prints the same information
- **`remove` and `force`** on `set_colors` and `set_fonts`: entries (and groups) are removed by `_id`, dropped from group `children`, and first looked up across page element data and settings, documents, templates, theme options and other palette entries; an entry still in use is removed only with `force: true`, the response lists every use, and the last font cannot be removed
- **`create_page`** reports `write_path` and `stamped`
- **Tests**: unit tests for the stamper, the lint checks (a fixture per code), the Max summary, the Theme Options reader, item removal and reference patterns; smoke tests for page saves (including shortcode storage with `local=1`), stamps, warning codes, the feature report, Theme Options reads and removal

### Changed

- **Page writes go through Cornerstone's `Document::save()`** (`create_page`, `deploy_layout`, `update_layout`), so `Content::updateElements()` runs as in the builder: `_cornerstone_override` is cleared, `cornerstone_before_save_content`, `cornerstone_after_save_content` and `cs_save_document` fire once, and `post_content` is rebuilt in the site's storage mode (rendered HTML, or `[cs_content]` shortcodes on sites where `cs_document_build_as_html` is off). The per-request document cache is cleared before and after. The 1.1 direct write remains the fallback and now also clears the override flag
- **`restore_layout` on a page** rebuilds `post_content` the way Cornerstone's storage migration does, in the site's storage mode
- **`set_fonts`** without `fonts`, `config` or `remove` now reports `Pass "fonts", "config", "remove", or a combination.`

### Fixed

- **`_cs_last_save` was never set on API-path page saves**: Cornerstone only sets it from a listener that exists during its own REST requests. Pro Extended now sets it after every page save
- **Backslashes in page titles were lost on save**: `Content::save()` hands post fields to `wp_update_post()` unslashed; the title and excerpt are now passed slashed so they are stored unchanged
- **New elements rendered with legacy defaults**: elements created by 1.0 and 1.1 had no `_m` or `_bp_base`, so Cornerstone filled old defaults (a 96px bar, header navs as toggles) and rewrote grid, row and cell layouts. New elements are now stamped; `deploy_layout` warns about unmarked elements

## [1.1.0] - 2026-09-17

"Site Foundations": the MCP server can now create the rest of a site's foundation — headers, footers, component documents and layouts, the global palette and fonts, Global CSS and media — with dry runs, automatic backups and the same cache clearing Cornerstone's builder does. Existing tools keep their names, input properties and success result shapes (new optional properties and additional result keys only).

### Added

- **11 new MCP tools (25 in total)**:
  - `create_document` — create a header, footer, component document, or single/archive layout, with optional settings and layout data; `if_not_exists` (default true) makes retries safe, and a warning names any other document already assigned to `site:entire-site` at the same priority
  - `update_document_settings` — change a document's settings, title or (components) slug without touching its elements (they are saved exactly as stored, without Cornerstone's load-time element migrations); reports before/after per key, and `restore_layout` puts the title and slug back
  - `list_components` — the component registry the builder uses (component ID, label, source document, library group, prefab, children/slots, parameter groups), plus duplicate `_c_id` errors
  - `get_global_css` / `set_global_css` — Global CSS through named `pe:begin`/`pe:end` blocks; CSS outside the blocks is never changed; unsafe or unbalanced CSS is refused and risky CSS is flagged (background without color, remote `@import`, heavy `!important`)
  - `set_colors` / `set_fonts` — add or update palette colors and global fonts by `_id`, keeping every key not changed (including `locked`); `allow_locked` updates a starter kit's locked slots without breaking references; `set_fonts` derives `name`, `stack` and weights the way Cornerstone does and merges font settings
  - `upload_media` — images and web fonts from HTTPS URLs (size-capped, private addresses refused) or small base64 payloads, with alt text enforcement, hash-based dedupe, an opt-in SVG allowlist that refuses (never strips) unsafe content, and a per-call time budget
  - `list_menus` — menus with locations and `menu:<id>` references
  - `list_settings_backups` / `restore_settings` — the last 10 backups of colors, fonts, font config and Global CSS, each stored in its own non-autoloaded option with its existence and autoload state
- **Cornerstone adapter** (`ProExtended\Cornerstone\DocumentGateway`): the single, guarded entry point into Cornerstone internals, with a direct-write fallback and a `pe_force_fallback` filter for testing. Write responses report `write_path` (`cornerstone-api` or `fallback`)
- **`dry_run`** on every new write tool, and `warnings` on every new tool response
- **`get_layout`**: `summary` (an outline with paths, types, labels and child counts, plus the document's size and element count) and `path` (one subtree, or an element and its descendants in component documents), for documents too large to return whole
- **`create_page`**: `parent_id`, `menu_order`, `template` (validated against the theme's page templates) and `excerpt`, plus `if_not_exists` (an existing page with the same slug and parent is returned; a draft without a slug matches on its title)
- **`list_layouts`**: `doc_type` on every row; component documents also report `format` (component or legacy), `library_group`, `document_visibility` and `component_count`
- **`clear_cache`**: `include` (`tss`, `generated_styles`, `components`, `assignments`, `host`), and a report of what ran and what was unavailable
- **`get_site_info`**: a `health` block (permalinks, application passwords, `blog_public`, breakpoints, capabilities, Global CSS key, adapter entry points, component registry errors, host cache purging, Pro Extended settings, environment type, tool registration errors)
- **`wp pe doctor`**: the health checks as pass/warn/fail lines; exits non-zero only when a check fails; `--format=json` prints only the JSON report
- **Validator**: warnings for component instances whose `component_id` is not in the registry, or that set top-level `_p_data` keys the component does not declare
- **`pe_allow_skip_validation`** option and filter (default `'1'`): `'0'` refuses `skip_validation: true`
- **MCP annotations** (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) on every tool through the optional `AnnotatedToolInterface`, a top-level `title` in `tools/list`, and short usage `instructions` in the `initialize` result
- **`pe_mcp_register_tools`** action for registering additional tools
- **Tests**: plain-PHP unit tests (`php tests/unit/run.php`) and WP-CLI smoke suites (`tests/smoke/`) that exercise every tool on both write paths
- **`.gitattributes`** so release archives leave out tests and tooling

### Changed

- **Tool failures are MCP results with `isError: true`**, not JSON-RPC errors, so the model sees the reason. JSON-RPC errors remain for an unknown tool, a missing tool name and a failed `requiredCapability()` check. `wp pe mcp call` prints such results and exits with status 1
- **Header, footer, layout and component writes go through Cornerstone's Document API**, firing `cs_save_document`, `cs_save_{type}` and `cs_purge_tmp` as the builder does. `deploy_layout` on these documents is a full replace of the shape `get_layout` returns: settings the payload leaves out return to their defaults, while the title, slug, custom scripts/styles and a single/archive layout's `layout_type` (which decides its post type) are kept. Payloads in any other shape are refused instead of being stored. Legacy global blocks and legacy `cs_layout` posts keep the direct write
- **Page writes keep their write path** and then fire `cs_save_document`, which refreshes the last-save timestamps and the Google Fonts request cache like a builder save (`_cs_last_save` now uses Cornerstone's timestamp format)
- **Writes that could be damaged or abused without `unfiltered_html` are refused**: Cornerstone document writes (WordPress filters post_content for such users, breaking the JSON), `customCSS`/`customJS`, and Raw Content elements. `wp pe layout import` and write tools in `wp pe mcp call` therefore need `--user`
- **Each tool registers on its own**: a tool that fails to load is logged and skipped, and `tools/list` and every other tool keep working
- **README**: Claude Desktop setup through `mcp-remote`, a Prerequisites section, the new tools, reference formats, options and commands

### Fixed

- **Stale component registry and assignment rules**: `deploy_layout`, `update_layout` and `restore_layout` wrote `cs_*` documents directly and only deleted `_cs_generated_tss`, so a deployed component document did not reach the registry and header/footer/layout assignment changes stayed cached until something else purged them. Writes and restores now run Cornerstone's save hooks (or clear the same caches directly on the fallback path)
- **`create_page` stored unvalidated layouts and left pages behind**: `layout_data` is now validated before the page is inserted, and a page whose layout fails to save is removed again. `wp pe layout import` validates too
- **Some layout post types were read and written as page meta**: `cs_layout`, `cs_layout_single_wc` and `cs_layout_archive_wc` are now always detected as `post_content` documents
- **Object and array arguments sent as JSON strings** were refused by `deploy_layout` (and silently dropped by `create_page`). A shared decoder now normalizes them before validation in every tool that takes objects or arrays; a string that is not a JSON object or array is an error. Thanks to [#1](https://github.com/renandadalte/wpdev-pro-extended/pull/1) for identifying the problem and the Claude Desktop configuration fix
- **`get_layout` overflowed client result limits** on large component documents (see `summary` and `path`)
- **A site-wide `clear_cache` cleared too little**: it now also purges generated styles and Cornerstone's temporary caches by default, and uses the metadata API so persistent object caches are cleared too
- **`list_fonts` reported an empty font config**: Cornerstone stores it slashed and the options API does not unslash; both forms are now decoded
- **Stale generated styles with a persistent object cache**: Cornerstone deletes generated-style and component-map meta with `$wpdb`; Pro Extended now clears the post meta cache of every affected post after a purge

## [1.0.4] - 2026-09-15

### Fixed

- **`list_layouts` hid every header, footer and global block**: Cornerstone stores layout documents under its own `tco-data` post status, but `LayoutService::listAll()` filtered on `['publish', 'draft', 'private']`, so all of them were excluded — a site with two headers and a footer reported `count: 0` for `cs_header`. The query now derives its status list from `get_post_stati()` minus the internal statuses (`trash`, `auto-draft`, `inherit`), which also picks up custom statuses registered later. `get_layout` could read these documents by ID the whole time; only discovery was broken

## [1.0.3] - 2026-09-15

### Fixed

- **Validator failed open (introduced in 1.0.2)**: the new envelope resolution treated any shape it did not recognise as `valid: true` with zero errors, so `deploy_layout` wrote payloads that 1.0.0-alpha correctly rejected — including the sparse `_bp_data` that causes `TypeError: t[i] is not iterable` in Cornerstone's editor. An associative `{ id: element }` map is now validated as a flat element map instead of being waved through, and `skip_validation` remains the deliberate way to bypass checking. A validator that passes what it cannot read is worse than no validator
- **Validation warnings were discarded**: `deploy_layout` read only `valid` and dropped the warning list on the success path, so a fallback or partial validation never reached the caller. Warnings are now merged into the response's `warnings` array

## [1.0.2] - 2026-09-15

### Fixed

- **Validation rejected headers, footers and layout templates**: `HierarchyValidator` assumed a bare element list, but `cs_header`, `cs_footer` and `cs_layout_*` wrap their trees in a `regions` envelope and `cs_global_block` may nest its map under `elements`. The validator treated the envelope key as an element and failed every one of those post types — which made `deploy_layout` unable to write them. It now resolves all three storage shapes, reports errors per region, and skips (with a warning, never a hard failure) any envelope it does not recognise, so an unknown shape can't block a write

- **Backup corruption (data loss)**: `LayoutService::backup()` wrote the backup array through `update_post_meta()` without `wp_slash()`. `update_metadata()` applies `wp_unslash()` recursively, stripping the escaping out of the stored JSON — so any layout containing a non-ASCII character, a quote, or a URL was written to the backup unparseable, and `restore_layout` then wrote that broken JSON back to the post
- **Restore left pages half-restored**: `LayoutService::restore()` wrote `_cornerstone_data` via `$wpdb` without invalidating the meta cache (stale reads on sites with a persistent object cache) and without recompiling `post_content`, so the front end kept rendering the *previous* layout while the builder showed the restored one. It also silently no-opped when the meta row did not exist
- **Cornerstone false negative**: the availability check ran on `plugins_loaded`, but WordPress loads themes *after* that hook — so the "Cornerstone is not available" notice appeared on every request even when Cornerstone was working. Moved to `admin_init` and the message no longer claims features are disabled, which was never true
- **Closure serialization**: `SchemaExtractor::getAllDefinitions()` passed raw element definitions to `set_transient()`, throwing "Serialization of 'Closure' is not allowed" and taking out `list_elements`, `get_element_schema`, `validate_layout`, `deploy_layout` and both schema resources. Closures are now replaced with a placeholder before caching *and* before returning, and a failed cache write degrades to no caching instead of a fatal ([#2](https://github.com/renandadalte/wpdev-pro-extended/issues/2))
- **`get_site_info` fatal over REST**: called `get_plugin_data()`, which lives in `wp-admin/includes/plugin.php` and is not loaded on REST requests; now included explicitly
- **Deploy reported failure on a no-op**: `update_post_meta()` returns `false` both on error and when the value is unchanged, so redeploying an identical layout reported `deployed: false`
- **Backup ID collisions**: IDs came from `time()`, so a deploy and an update in the same second overwrote each other's restore point
- **Missing post check**: `backup()` passed a null post to `detectSource()`, and callers swallowed the resulting `TypeError` — so an invalid `post_id` silently proceeded with no backup
- **Dropped null defaults**: `SchemaExtractor::getDefaults()` used `isset()`, discarding any element property whose default value is `null`

### Changed

- **`update_layout` is now atomic**: operations are applied in memory and the layout is written only if *all* of them succeed — previously a partial application was saved and reported success with the errors nested inside
- **`update_layout` now validates**: the patched result is checked before writing (`skip_validation` opts out), closing the path by which patch operations could write the sparse `_bp_data` that crashes Cornerstone's editor. Validation only blocks when the patch is what broke the layout — if the stored data was already invalid the write proceeds with a warning, so the tool can still be used to repair it
- **`update_layout` returns one consistent shape**: every exit point returns the same keys (`updated`, `post_id`, `backup_id`, `operations_applied`, `operations_total`, `validation`, `warnings`, `errors`) instead of three different key sets, and a failed write now says so in `errors` rather than reporting `updated: false` with no reason
- **`restore()` checks its database writes**: the return values of `$wpdb->update()`/`$wpdb->insert()` were discarded and the method returned `true` unconditionally, so a failed restore reported success
- **Backup failures are reported**: `deploy_layout` and `update_layout` return a `warnings` array instead of silently discarding a failed backup

## [1.0.0-alpha] - 2026-02-20

### Added

- **Plugin scaffold**: PSR-4 autoloading, Pro Theme dependency guard, activation/deactivation hooks
- **MCP Server**: JSON-RPC 2.0 router with Streamable HTTP transport (`POST /wp-json/pro-extended/v1/mcp`)
- **MCP Read Tools** (8):
  - `list_elements` — list all 148 Cornerstone element types with groups and valid children
  - `get_element_schema` — get full element definition with properties, defaults, and options
  - `list_layouts` — list all Cornerstone layouts (pages, headers, footers, global blocks)
  - `get_layout` — get complete layout JSON with metadata and SHA-256 checksum
  - `validate_layout` — validate layout structure against hierarchy rules and `_bp_data` format
  - `list_colors` — get the global color palette
  - `list_fonts` — get registered font definitions and configuration
  - `get_site_info` — get WordPress, theme, Cornerstone versions and breakpoint config
- **MCP Write Tools** (6):
  - `create_page` — create a new page with optional Cornerstone layout data
  - `deploy_layout` — write layout data to a post with auto-backup and validation
  - `backup_layout` — create timestamped backup (up to 10 per post)
  - `restore_layout` — restore from a previous backup
  - `clear_cache` — clear Cornerstone TSS cache (per-post or global)
  - `update_layout` — apply patch operations (add/remove/update elements)
- **MCP Resources** (3):
  - `pe://schema/elements` — full cached element definitions
  - `pe://schema/hierarchy` — valid parent-child relationship map
  - `pe://colors/palette` — current color palette
- **Core Services**:
  - `LayoutService` — read/write/backup/restore for all 3 Cornerstone storage formats
  - `SchemaExtractor` — element definitions with transient caching
  - `HierarchyValidator` — layout validation (hierarchy, `_bp_data`, flat map references)
- **WP-CLI Commands**:
  - `wp pe layout list|export|import|backup|restore`
  - `wp pe mcp test|tools|call`
- **Security**: Application Password auth, per-tool capability checks, input validation, auto-backup before writes, zero external dependencies

### Fixed

- **Layout rendering**: `LayoutService.save()` now compiles layout data into HTML via `cs_render_document_html_with_comment()` and stores in `post_content` — required for Cornerstone to render pages
- **Layout rendering**: `LayoutService.save()` now sets `_cornerstone_settings` meta (layout/header/footer defaults) — required for Cornerstone to recognize pages
- **Layout rendering**: `LayoutService.save()` now sets `_wp_page_template` to `template-blank-4.php` — required for Pro theme rendering
- **Validation crash**: `HierarchyValidator.validateParentChild()` now handles wildcard `"*"` in `valid_children` (was passing string to `in_array()`)

### Changed

- **Autoloader**: Replaced Composer dependency with a built-in PSR-4 autoloader — plugin now works immediately after activation, no terminal access required
