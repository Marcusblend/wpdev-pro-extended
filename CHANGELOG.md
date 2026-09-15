# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
