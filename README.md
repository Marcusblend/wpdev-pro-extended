# Pro Extended (PE)

> Extends [Pro Theme](https://theme.co/pro) and Cornerstone page builder with an **MCP Server**, developer tools, and CLI commands — enabling AI agents to read, create, and manage Cornerstone layouts programmatically.

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![License: GPL-2.0+](https://img.shields.io/badge/License-GPL--2.0%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![MCP 2025-03-26](https://img.shields.io/badge/MCP-2025--03--26-5A67D8)](https://modelcontextprotocol.io/)

[![Ko-fi](https://img.shields.io/badge/Support_this_project-ff5e5b?logo=ko-fi&logoColor=white)](https://ko-fi.com/renandadalte)

---

## What is this?

**Pro Extended** connects your WordPress site (running [Pro Theme](https://theme.co/pro) / Cornerstone) to AI assistants like **Claude**, **ChatGPT**, **Gemini**, and others via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/).

In practice, this means you can ask your AI assistant to:

- 📄 **Create pages** with full Cornerstone layouts
- 🎨 **Read and modify** existing layouts, headers, footers, and global blocks
- 🔍 **Inspect** element schemas, colors, fonts, and site configuration
- 💾 **Backup and restore** layouts with zero risk of data loss

No coding experience required — if you can install a WordPress plugin and follow a few configuration steps, you're ready to go.

---

## Quick Start

### Requirements

- WordPress 6.5+
- Pro Theme 6.x+ (or a child theme of Pro)
- PHP 8.1+

### Prerequisites

Check these before connecting a client (`wp pe doctor --user=<ID>` checks them for you):

- **Pretty permalinks.** With *Plain* permalinks, `/wp-json/...` returns the homepage. Choose any other structure under **Settings → Permalinks**, or point your client at `https://your-site.com/?rest_route=/pro-extended/v1/mcp` instead.
- **An application password created the normal way** (Users → Profile → Application Passwords). WordPress ignores Basic auth until the first application password has been created through that screen, which sets its internal "in use" flag.
- **An administrator account that has the `unfiltered_html` capability.** WordPress filters post content for users without it, which damages Cornerstone's stored JSON, so Pro Extended refuses document writes for them. Administrators have it on single-site installs unless `DISALLOW_UNFILTERED_HTML` is set. Global CSS tools also need `edit_css`.

### Installation

#### Option A — Upload via WordPress Admin (easiest)

1. [**Download the latest release**](https://github.com/renandadalte/wpdev-pro-extended/archive/refs/heads/main.zip) as a `.zip` file.
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Select the downloaded `.zip` file and click **Install Now**.
4. After installation, click **Activate Plugin**.

That's it — no terminal, no extra steps. The plugin includes a built-in autoloader.

#### Option B — Git Clone (for developers)

```bash
# Clone into your plugins directory
cd wp-content/plugins/
git clone https://github.com/renandadalte/wpdev-pro-extended.git

# Activate via WP-CLI
wp plugin activate wpdev-pro-extended
```

### Connecting an MCP Client

To allow your AI assistant to interact with your WordPress site, you need two things:

1. **An Application Password** — Go to your WordPress admin → **Users → Your Profile** → scroll down to **Application Passwords**. Enter a name (e.g., "MCP") and click **Add New Application Password**. Copy the generated password.

2. **A Base64-encoded credential string** — The MCP server uses HTTP [Basic Authentication](https://developer.mozilla.org/en-US/docs/Web/HTTP/Authentication#basic_authentication_scheme). You need to encode your `username:password` pair in Base64.

   **How to generate your Base64 string:**

   ```bash
   # macOS / Linux:
   echo -n "your_wp_username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64
   ```

   ```powershell
   # Windows (PowerShell):
   [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("your_wp_username:xxxx xxxx xxxx xxxx xxxx xxxx"))
   ```

   Or use any online Base64 encoder — just encode the string `your_wp_username:your_application_password` (with the colon separator).

3. **Add the server to your IDE's MCP configuration:**

   <details>
   <summary><strong>Antigravity (Google Gemini)</strong></summary>

   Edit `~/.gemini/antigravity/mcp_config.json`:

   ```json
   {
     "mcpServers": {
       "pro-extended": {
         "serverUrl": "https://your-site.com/wp-json/pro-extended/v1/mcp",
         "headers": {
           "Authorization": "Basic YOUR_BASE64_STRING_HERE"
         }
       }
     }
   }
   ```

   > **Note**: Antigravity uses `serverUrl` (camelCase) instead of `url`.

   </details>

   <details>
   <summary><strong>Cursor</strong></summary>

   Edit `.cursor/mcp.json` in your project root (or global settings):

   ```json
   {
     "mcpServers": {
       "pro-extended": {
         "url": "https://your-site.com/wp-json/pro-extended/v1/mcp",
         "headers": {
           "Authorization": "Basic YOUR_BASE64_STRING_HERE"
         }
       }
     }
   }
   ```

   </details>

   <details>
   <summary><strong>Claude Desktop</strong></summary>

   Claude Desktop's `claude_desktop_config.json` does **not** accept a bare `url` + `headers` remote entry — those are skipped on launch with a *"Some MCP servers could not be loaded"* notice. Bridge the remote endpoint through [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) instead (requires [Node.js](https://nodejs.org/), which provides `npx`).

   Edit `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

   ```json
   {
     "mcpServers": {
       "pro-extended": {
         "command": "npx",
         "args": [
           "mcp-remote",
           "https://your-site.com/wp-json/pro-extended/v1/mcp",
           "--header",
           "Authorization:Basic YOUR_BASE64_STRING_HERE"
         ]
       }
     }
   }
   ```

   > **Note**: Keep `Authorization:Basic YOUR_BASE64_STRING_HERE` as a single argument. `mcp-remote` splits each `--header` value on the first colon only, so the `Basic ` scheme (and the space after it) is preserved. Restart Claude Desktop after editing the file.

   </details>

   <details>
   <summary><strong>VS Code (GitHub Copilot / Other MCP Clients)</strong></summary>

   Edit `.vscode/mcp.json` in your project root:

   ```json
   {
     "servers": {
       "pro-extended": {
         "url": "https://your-site.com/wp-json/pro-extended/v1/mcp",
         "headers": {
           "Authorization": "Basic YOUR_BASE64_STRING_HERE"
         }
       }
     }
   }
   ```

   > **Note**: VS Code uses `servers` instead of `mcpServers` as the root key.

   </details>

> [!IMPORTANT]
> Replace `YOUR_BASE64_STRING_HERE` with the Base64-encoded string you generated in step 2. **Do not paste your username and password directly** — it must be the Base64-encoded version.

4. Restart your IDE. Your AI agent can now list elements, read layouts, create pages, and more.

### WP-CLI Usage

```bash
wp pe doctor --user=1                                   # Check permalinks, auth, Cornerstone, capabilities
wp pe mcp test                                          # Verify MCP server
wp pe mcp tools                                         # List all 25 tools
wp pe mcp call get_site_info --user=1                   # Call any tool
wp pe layout list                                       # List all layouts
wp pe layout export 42 --output=homepage.json           # Export layout to JSON
wp pe layout import homepage.json --user=1              # Validate and import (needs a user)
wp pe layout backup 42                                  # Create backup
wp pe layout restore 42                                 # Restore from backup
```

Commands that write (`wp pe layout import`, and `wp pe mcp call` for any tool that is not read-only) must run with `--user=<ID>` of a user who has `unfiltered_html`. `wp pe mcp call` exits with status 1 when the tool reports an error.

---

## Capabilities

### MCP Tools (26)

| Tool | Type | Description |
|------|------|-------------|
| `list_elements` | Read | List all Cornerstone element types with groups and valid children |
| `get_element_schema` | Read | Get full schema for a specific element type (properties, defaults, options) |
| `list_layouts` | Read | List all layouts (pages, headers, footers, layouts, component documents) with their doc type |
| `get_layout` | Read | Get complete layout JSON with metadata and checksum; `summary` returns an outline, `path` one subtree |
| `validate_layout` | Read | Validate layout structure, hierarchy, `_bp_data` and component instances, plus coded warnings for element data Cornerstone would render differently than intended |
| `list_colors` | Read | Get the Cornerstone global color palette |
| `list_fonts` | Read | Get global fonts and the font configuration |
| `get_site_info` | Read | Versions, breakpoints, active plugins, a `features` block (content storage, Twig, External API, CSV, WPML, WooCommerce, ACF, Max products, your Cornerstone permissions) and a `health` block |
| `list_components` | Read | The component registry the builder uses (IDs, labels, slots, parameters, errors) |
| `get_global_css` | Read | Global CSS size and managed blocks, one block, or the whole stylesheet |
| `list_menus` | Read | Navigation menus with locations and `menu:<id>` references |
| `list_settings_backups` | Read | Backups of colors, fonts, font config and Global CSS |
| `get_theme_options` | Read | Theme Options panel sections, and option values, defaults and responsive values (secrets redacted) |
| `create_page` | Write | Create a page (parent, order, template, excerpt), optionally with validated layout data (new elements are stamped) |
| `deploy_layout` | Write | Write layout data to a post (auto-backup + validation; full replace for documents) |
| `backup_layout` | Write | Create a timestamped backup (up to 10 per post) |
| `restore_layout` | Write | Restore from a backup |
| `clear_cache` | Write | Clear TSS, generated styles, the component registry, assignment rules and/or the host cache |
| `update_layout` | Write | Apply patch operations (add/remove/update elements) |
| `create_document` | Write | Create a header, footer, component document, or single/archive layout |
| `update_document_settings` | Write | Change a document's settings, title or slug without touching its elements |
| `set_global_css` | Write | Create, replace or remove named Global CSS blocks (or replace the whole stylesheet) |
| `set_colors` | Write | Add, update or remove palette colors by `_id` (removal checks where each color is used) |
| `set_fonts` | Write | Add, update or remove global fonts by `_id` (removal checks where each font is used), and merge font settings |
| `upload_media` | Write | Add images and web fonts (and, when allowed, SVGs) from HTTPS URLs or base64 |
| `restore_settings` | Write | Put back a colors, fonts, font config or Global CSS backup |

Every tool declares MCP annotations (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`), and the server's `initialize` result includes short usage instructions for the model.

#### Writes, backups and dry runs

- Every write backs up first. Layout and document writes are restored with `restore_layout`; colors, fonts, font config and Global CSS with `restore_settings` (the last 10 backups per key are kept).
- The new write tools accept `dry_run: true`, which reports exactly what would change and writes nothing (not even a backup). Their responses include `warnings`, `backup_id` (or `backup_ids`) and `write_path`.
- `write_path` is `cornerstone-api` when the write went through Cornerstone's own Document API (so its component registry, assignment rules and generated styles are refreshed exactly as the builder does), and `fallback` when Pro Extended wrote the same data directly and cleared those caches itself.
- Page writes (`create_page`, `deploy_layout`, `update_layout`) go through `Document::save()` like a builder save: the `_cornerstone_override` flag is cleared, `cornerstone_before_save_content` / `cornerstone_after_save_content` and `cs_save_document` fire once, and `post_content` is rebuilt in the site's storage mode (rendered HTML, or `[cs_content]` shortcodes on sites that use them). As in the builder, the children of tabs, accordions and maps keep the `_id` Cornerstone gives them.
- `deploy_layout` on a header, footer, layout or component document is a full replace of the shape `get_layout` returns. Settings the payload leaves out return to their defaults; the title, slug, a document's custom scripts/styles and a single/archive layout's type are kept.
- A tool that fails while running returns a normal result with `isError: true` and the reason. Unknown tools, a missing tool name and missing capabilities are JSON-RPC errors.
- Object and array arguments may also be sent as JSON strings; they are decoded before validation.

#### New elements and warning codes

Cornerstone gives every element it creates two markers: `_m` (`{"e": N}`, the element's migration version) and `_bp_base` (the site's breakpoint tag, `"4_4"` on a default Pro site). An element without them is treated as legacy content: a bar without `_m` is 96px tall instead of 100px, a collapsed nav in a header bar turns into a toggle, and a grid without `_bp_base` gets forced 4/2/1 columns.

- `create_page`, `create_document` and `update_layout` (for `add` operations) add missing markers to the elements they write (`stamp_new`, default `true`). Existing markers are never changed.
- `deploy_layout` stamps only with `stamp_new: true`, because content copied from a site may be legacy content whose look depends on the old defaults. Without it, it warns when markers are missing.
- Every tool that validates returns warnings with stable codes. The result carries `issues` (`code`, `path` in `update_layout` notation, `type`, `message`), `codes` (a count per code) and `issue_count`, and `warnings` summarizes them per code and element type. Warnings never block a write; only data Cornerstone cannot load is an error.

| Code | Meaning |
|------|---------|
| `missing-migration-marker` | The type has base migrations but the element has no `_m` |
| `missing-breakpoint-base` | No `_bp_base` (Cornerstone runs its pre-6.0 breakpoint migration) |
| `breakpoint-base-mismatch` | `_bp_base` is not the site's tag (or not a tag) |
| `breakpoint-data-key` | A `_bp_data` key that does not match the element's tag, so it is ignored |
| `breakpoint-data-length` | A responsive value list without one value per breakpoint |
| `breakpoint-data-base-slot` | A value in the base breakpoint's slot, which is ignored |
| `classic-element`, `deprecated-element`, `legacy-element`, `internal-element` | Types new content should not use |
| `condition-shape`, `condition-unknown`, `condition-post-id` | `show_condition` rules Cornerstone cannot read, does not know, or never matches (string post IDs) |
| `looper-shape`, `looper-feature-off` | Looper keys of the wrong type, unregistered providers, or providers whose feature is off |
| `token-syntax`, `token-untrusted` | Dynamic Content tokens that will not expand, or that read outside input |
| `custom-atts-type`, `parameters-type` | `custom_atts`, `_p_json` or `_p_data` of the wrong type |
| `table-cell-text`, `table-cell-span`, `table-cell-tag`, `table-section-tag` | Table cells and sections that render differently than intended |
| `link-without-href`, `nested-link` | Container links that render as a `div` or `span` |
| `background-layers-off` | Background layers whose advanced switch is off |
| `unknown-element`, `invalid-child`, `component-instance` | The structural warnings of earlier versions |

#### Removing palette colors and fonts

`set_colors` and `set_fonts` accept `remove` (entry or group IDs). Each removed entry is first looked up in page element data and settings, header/footer/layout/component documents, templates, theme options (Global CSS, variables and global parameters included) and other palette entries. An entry still in use is only removed with `force: true`; the response lists every use. Removing a group keeps its members, and the last font cannot be removed.

#### References in layout data

| Reference | Format |
|-----------|--------|
| Global color | `global-color:<_id>`, with alpha `global-color:<_id>:0.33` |
| Global font family | `global-ff:<_id>` |
| Global font weight | `global-fw:<_id>\|fw-normal` or `global-fw:<_id>\|fw-bold` |
| Image | `<attachment_id>:full` (`upload_media` returns it as `cs_ref`) |
| Menu | `menu:<term_id>` (`list_menus` returns it as `cs_ref`) |
| Component instance | `{"_type": "component", "component_id": "<_c_id>", "_p_data": {...}}` (IDs from `list_components`) |

### MCP Resources (3)

| URI | Description |
|-----|-------------|
| `pe://schema/elements` | Full element definitions (cached) |
| `pe://schema/hierarchy` | Valid parent-child relationship map |
| `pe://colors/palette` | Current color palette |

### WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp pe doctor` | Check the site (pass/warn/fail; exits non-zero only on a failure; needs `--user`; `--format=json` prints only the JSON report) |
| `wp pe layout list` | List all Cornerstone layouts |
| `wp pe layout export <id>` | Export layout to JSON file |
| `wp pe layout import <file>` | Validate and import layout from JSON file (needs `--user`) |
| `wp pe layout backup <id>` | Create layout backup |
| `wp pe layout restore <id>` | Restore layout from backup |
| `wp pe mcp test` | Verify MCP server is operational |
| `wp pe mcp tools` | List registered MCP tools |
| `wp pe mcp call <tool>` | Call a tool directly (write tools need `--user`) |

### Options, filters and constants

| Name | Kind | Default | Purpose |
|------|------|---------|---------|
| `pe_allow_skip_validation` | option + filter | `'1'` | Set to `'0'` to refuse `skip_validation: true` on `deploy_layout` and `update_layout` |
| `pe_allow_svg_uploads` | option + filter | `'0'` | Set to `'1'` to let `upload_media` accept allowlisted SVGs |
| `PE_ALLOW_SVG_UPLOADS` | constant | — | Overrides the SVG option (the filter still has the last word) |
| `pe_upload_media_max_bytes` | filter | 20 MB | Download size cap for `upload_media` |
| `pe_force_fallback` | filter | `false` | Force the direct write path instead of Cornerstone's Document API (for testing) |
| `pe_mcp_register_tools` | action | — | Fires after the built-in tools are registered; call `$server->registerTool()` to add your own |

---

## Architecture

### Plugin Structure

```
wpdev-pro-extended/
├── wpdev-pro-extended.php       # Bootstrap, Pro Theme dependency guard, PSR-4 autoloader
└── src/
    ├── Plugin.php               # Service container (lazy-loaded)
    ├── Cornerstone/
    │   ├── DocumentGateway.php  # The one adapter into Cornerstone internals (guarded, with fallbacks)
    │   ├── ElementContext.php   # Migration versions, breakpoint tag and lint context from the live site
    │   ├── DocumentSettings.php # Allowed document settings per type
    │   └── ComponentScanner.php # Component exports, slots and parameters
    ├── Layouts/
    │   ├── LayoutService.php    # Read/write/backup/restore for 3 storage formats
    │   └── LayoutOutline.php    # Outlines and subtrees for large documents
    ├── Elements/
    │   ├── SchemaExtractor.php  # Wraps cornerstone('Elements') with transient cache
    │   ├── HierarchyValidator.php  # Layout validation engine
    │   ├── ElementLint.php      # Coded element data warnings (plain PHP)
    │   ├── ElementStamper.php   # _m and _bp_base markers for new elements (plain PHP)
    │   ├── LintContext.php
    │   ├── ElementTree.php
    │   └── ValidationResult.php
    ├── Settings/                # Palette/font merging and removal, usage scans, theme option reads, option backups
    ├── Css/CssBlocks.php        # Managed Global CSS blocks
    ├── Media/                   # Media import, SVG allowlist
    ├── Site/                    # Health report, feature switches, Max products, host cache purging
    ├── Support/                 # Argument, JSON and diff helpers
    ├── Mcp/
    │   ├── Server.php           # JSON-RPC 2.0 router
    │   ├── Transport/
    │   │   └── StreamableHttp.php  # REST API endpoint (POST)
    │   ├── Tools/               # 26 tool implementations
    │   │   ├── ToolInterface.php
    │   │   ├── AnnotatedToolInterface.php
    │   │   ├── ListElements.php
    │   │   ├── ...
    │   │   └── UpdateLayout.php
    │   └── Resources/           # 3 resource implementations
    │       ├── ResourceInterface.php
    │       ├── ElementSchemaResource.php
    │       ├── HierarchyResource.php
    │       └── ColorPaletteResource.php
    └── Commands/
        ├── LayoutCommand.php    # wp pe layout *
        ├── McpCommand.php       # wp pe mcp *
        └── DoctorCommand.php    # wp pe doctor
tests/                           # Not shipped in release archives
├── unit/                        # Plain-PHP tests: php tests/unit/run.php
└── smoke/                       # WP-CLI suites run against a site
```

### MCP Protocol

The server implements [MCP 2025-03-26](https://modelcontextprotocol.io/) via **Streamable HTTP** transport:

- **Endpoint**: `POST /wp-json/pro-extended/v1/mcp`
- **Protocol**: JSON-RPC 2.0
- **Authentication**: WordPress Application Passwords (Basic Auth)
- **Capability checks**: Per-tool (`edit_posts` for reads, `manage_options` for writes — except `create_page`, which requires `publish_pages`, and `upload_media`, which requires `upload_files`)

### Cornerstone Data Handling

The plugin handles all three Cornerstone storage formats:

| Post Type | Storage | Format |
|-----------|---------|--------|
| `page`, `post` | `_cornerstone_data` meta | Inline tree (nested `_modules`) |
| `cs_header`, `cs_footer`, `cs_layout`, `cs_layout_*` (including the WooCommerce types) | `post_content` | `{"settings", "regions": {"<region>": [...]}}` |
| `cs_global_block` | `post_content` | `{"elements": {flat map keyed by ID}, "settings"}` |

Header, footer, layout and component writes go through Cornerstone's Document API (`Document::create()` / `locate()`, `update()`, `save()`), which fires `cs_save_document`, `cs_save_{type}` (`cs_save_layout` for headers, footers and layouts, `cs_save_component` plus `cs_purge_tmp` for components) like the builder. Page writes go through `Document::save()` as well, which runs `Content::updateElements()` and fires `cs_save_document`; Pro Extended then sets `_cs_last_save`, which Cornerstone only sets during its own REST requests. When the Document API is unavailable, pages and documents are written directly and the same caches are cleared. Legacy global blocks and legacy `cs_layout` posts are written directly.

**Critical safety patterns** applied:
- **`wp_slash(wp_json_encode($data))`** before `update_post_meta()` — prevents WordPress from corrupting JSON escaping
- **`$wpdb` for raw backups** — preserves exact byte-level encoding
- **`_bp_data` validation** — ensures breakpoint arrays are sequential (5-element, null-padded) to prevent `TypeError: t[i] is not iterable` in Cornerstone's React app

### Security

- All endpoints require WordPress authentication (Application Passwords)
- Read tools require `edit_posts` capability
- Write tools require `manage_options` capability (`create_page` requires `publish_pages`, `upload_media` requires `upload_files`); `set_global_css` also needs `edit_css`
- Document writes, custom CSS/JS and Raw Content require `unfiltered_html`
- `upload_media` downloads only over HTTPS through `wp_safe_remote_get()`, caps the size, and accepts SVG only when enabled and only with allowlisted elements and attributes (anything else is refused, not stripped)
- `get_site_info` never returns Max package URLs, External API allowlist entries or endpoint headers, and `get_theme_options` redacts secret-looking values
- Input validation on all tool parameters; unknown keys and enum values are rejected
- Layout structure validation before writes
- Auto-backup before destructive operations
- Zero external dependencies — portable, no supply chain risk

---

## Roadmap

### ✅ v1.0.0-alpha (Current)
- MCP Server with Streamable HTTP transport
- 14 tools (8 read + 6 write) for element/layout management
- 3 MCP resources for schema and palette data
- WP-CLI commands for layout management and MCP testing
- Layout backup/restore system
- Hierarchy and breakpoint data validation

### ✅ v1.1.0 — Site Foundations
- Create headers, footers, component documents and single/archive layouts (`create_document`, `update_document_settings`)
- Component registry (`list_components`) and component instance validation
- Global colors, fonts and font settings (`set_colors`, `set_fonts`) with backups (`list_settings_backups`, `restore_settings`)
- Managed Global CSS blocks (`get_global_css`, `set_global_css`)
- Media import (`upload_media`) and menu discovery (`list_menus`)
- Writes through Cornerstone's Document API, so its caches stay correct
- Tool annotations, `isError` results, dry runs, `wp pe doctor`

### ✅ v1.2.0 — Builder Parity
- Page writes through Cornerstone's `Document::save()` (override flag, save hooks, storage mode)
- `_m` and `_bp_base` markers on new elements (`stamp_new`)
- Coded validator warnings for element data (markers, breakpoints, conditions, loopers, tokens, attributes, tables, links, layers)
- Feature report in `get_site_info`, and `get_theme_options`
- Palette and font removal with a usage check

### 🔜 Planned — Design Tokens & Settings Sync
- **Design Token System**: Centralized token registry, CSS custom property output, admin UI
- **Settings Sync**: ACF-style JSON export/import for version-controlled settings
- **MCP rate limiting**: Transient-based request throttling

### 🔮 Later — Extended Features
- **Element Defaults Manager**: Admin UI for setting default values for Cornerstone elements
- **Color Audit & Replace**: CLI tool to find and replace hardcoded colors with global palette references
- **Layout Versioning**: Git-friendly JSON export with diff support

---

## Support

If you find this project useful, consider supporting its development:

[![Ko-fi](https://img.shields.io/badge/Buy_me_a_coffee-ff5e5b?logo=ko-fi&logoColor=white&style=for-the-badge)](https://ko-fi.com/renandadalte)

---

## Contributing

### Tests

- **Unit tests** need only PHP 8.1+ (with the DOM extension) and no WordPress: `php tests/unit/run.php`.
- **Smoke tests** run through WP-CLI on a site with Pro and this plugin active. Copy `tests/smoke/` somewhere outside the plugin folder, then from the WordPress root run:

  ```bash
  bash /path/to/smoke/run.sh write <admin user ID>      # creates "PE TEST" content and leaves it in place
  bash /path/to/smoke/run.sh readonly <admin user ID>   # read-only calls and dry runs only
  ```

  The write suite creates documents, pages and media titled `PE TEST …` (listed at the end of the run), briefly assigns a test header to the entire site, and writes test palette, font and Global CSS entries that it restores from their backups. Run it only on a staging site. The palette and font writes are skipped when those options already hold data. On a disposable local site (`WP_ENVIRONMENT_TYPE` `local`), add `local=1` to also test shortcode page storage and palette and font removal on a non-empty palette.

### Pull requests

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'feat: add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

Please follow [Conventional Commits](https://www.conventionalcommits.org/) for commit messages.

---

## License

This project is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.
