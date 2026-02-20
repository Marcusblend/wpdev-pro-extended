# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
