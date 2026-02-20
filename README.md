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
- Composer (for autoloading — [install guide](https://getcomposer.org/download/))

### Installation

#### Option A — Upload via WordPress Admin (easiest)

1. [**Download the latest release**](https://github.com/renandadalte/wpdev-pro-extended/archive/refs/heads/main.zip) as a `.zip` file.
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Select the downloaded `.zip` file and click **Install Now**.
4. After installation, click **Activate Plugin**.
5. Connect to your server via SSH or terminal and run:

```bash
cd wp-content/plugins/wpdev-pro-extended
composer dump-autoload --optimize
```

> [!NOTE]
> The `composer dump-autoload` step is required to generate the PHP autoloader. Without it, the plugin will show an error notice. If you don't have terminal access, ask your hosting provider to run this command for you.

#### Option B — Git Clone (for developers)

```bash
# Clone into your plugins directory
cd wp-content/plugins/
git clone https://github.com/renandadalte/wpdev-pro-extended.git

# Generate the autoloader
cd wpdev-pro-extended
composer dump-autoload --optimize

# Activate via WP-CLI
wp plugin activate wpdev-pro-extended
```

### Connecting an MCP Client

To allow your AI assistant to interact with your WordPress site, you need two things:

1. **An Application Password** — Go to your WordPress admin → **Users → Your Profile** → scroll down to **Application Passwords**. Enter a name (e.g., "MCP") and click **Add New Application Password**. Copy the generated password.

2. **A Base64-encoded credential string** — The MCP server uses HTTP [Basic Authentication](https://developer.mozilla.org/en-US/docs/Web/HTTP/Authentication#basic_authentication_scheme). You need to encode your `username:password` pair in Base64.

   **How to generate your Base64 string:**

   ```bash
   # In your terminal (macOS / Linux):
   echo -n "your_wp_username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64

   # Example output: eW91cl93cF91c2VybmFtZTp4eHh4IHh4eHggeHh4eCB4eHh4IHh4eHggeHh4eA==
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

   Edit `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

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
wp pe mcp test                                          # Verify MCP server
wp pe mcp tools                                         # List all 14 tools
wp pe mcp call get_site_info --user=1                   # Call any tool
wp pe layout list                                       # List all layouts
wp pe layout export 42 --output=homepage.json           # Export layout to JSON
wp pe layout backup 42                                  # Create backup
wp pe layout restore 42                                 # Restore from backup
```

---

## Capabilities

### MCP Tools (14)

| Tool | Type | Description |
|------|------|-------------|
| `list_elements` | Read | List all Cornerstone element types with groups and valid children |
| `get_element_schema` | Read | Get full schema for a specific element type (properties, defaults, options) |
| `list_layouts` | Read | List all layouts (pages, headers, footers, global blocks, etc.) |
| `get_layout` | Read | Get complete layout JSON with metadata and checksum |
| `validate_layout` | Read | Validate layout structure against element schema and hierarchy rules |
| `list_colors` | Read | Get the Cornerstone global color palette |
| `list_fonts` | Read | Get registered font definitions and configuration |
| `get_site_info` | Read | Get WordPress, theme, Cornerstone versions and breakpoint config |
| `create_page` | Write | Create a new page with optional Cornerstone layout data |
| `deploy_layout` | Write | Write layout data to a post (auto-backup + validation) |
| `backup_layout` | Write | Create a timestamped backup (up to 10 per post) |
| `restore_layout` | Write | Restore from a backup |
| `clear_cache` | Write | Clear Cornerstone TSS cache (per-post or global) |
| `update_layout` | Write | Apply patch operations (add/remove/update elements) |

### MCP Resources (3)

| URI | Description |
|-----|-------------|
| `pe://schema/elements` | Full element definitions (cached) |
| `pe://schema/hierarchy` | Valid parent-child relationship map |
| `pe://colors/palette` | Current color palette |

### WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp pe layout list` | List all Cornerstone layouts |
| `wp pe layout export <id>` | Export layout to JSON file |
| `wp pe layout import <file>` | Import layout from JSON file |
| `wp pe layout backup <id>` | Create layout backup |
| `wp pe layout restore <id>` | Restore layout from backup |
| `wp pe mcp test` | Verify MCP server is operational |
| `wp pe mcp tools` | List registered MCP tools |
| `wp pe mcp call <tool>` | Call a tool directly |

---

## Architecture

### Plugin Structure

```
wpdev-pro-extended/
├── wpdev-pro-extended.php       # Bootstrap, Pro Theme dependency guard
├── composer.json                # PSR-4 autoloading (ProExtended\ → src/)
└── src/
    ├── Plugin.php               # Service container (lazy-loaded)
    ├── Layouts/
    │   └── LayoutService.php    # Read/write/backup/restore for 3 storage formats
    ├── Elements/
    │   ├── SchemaExtractor.php  # Wraps cornerstone('Elements') with transient cache
    │   ├── HierarchyValidator.php  # Layout validation engine
    │   └── ValidationResult.php
    ├── Mcp/
    │   ├── Server.php           # JSON-RPC 2.0 router
    │   ├── Transport/
    │   │   └── StreamableHttp.php  # REST API endpoint (POST)
    │   ├── Tools/               # 14 tool implementations
    │   │   ├── ToolInterface.php
    │   │   ├── ListElements.php
    │   │   ├── GetElementSchema.php
    │   │   ├── ...
    │   │   └── UpdateLayout.php
    │   └── Resources/           # 3 resource implementations
    │       ├── ResourceInterface.php
    │       ├── ElementSchemaResource.php
    │       ├── HierarchyResource.php
    │       └── ColorPaletteResource.php
    └── Commands/
        ├── LayoutCommand.php    # wp pe layout *
        └── McpCommand.php       # wp pe mcp *
```

### MCP Protocol

The server implements [MCP 2025-03-26](https://modelcontextprotocol.io/) via **Streamable HTTP** transport:

- **Endpoint**: `POST /wp-json/pro-extended/v1/mcp`
- **Protocol**: JSON-RPC 2.0
- **Authentication**: WordPress Application Passwords (Basic Auth)
- **Capability checks**: Per-tool (`edit_posts` for reads, `manage_options` for writes)

### Cornerstone Data Handling

The plugin handles all three Cornerstone storage formats:

| Post Type | Storage | Format |
|-----------|---------|--------|
| `page`, `post` | `_cornerstone_data` meta | Inline tree (nested `_modules`) |
| `cs_header`, `cs_footer`, `cs_layout_*` | `post_content` | Inline tree with regions |
| `cs_global_block` | `post_content` | Flat map (keyed by `_id`) |

**Critical safety patterns** applied:
- **`wp_slash(wp_json_encode($data))`** before `update_post_meta()` — prevents WordPress from corrupting JSON escaping
- **`$wpdb` for raw backups** — preserves exact byte-level encoding
- **`_bp_data` validation** — ensures breakpoint arrays are sequential (5-element, null-padded) to prevent `TypeError: t[i] is not iterable` in Cornerstone's React app

### Security

- All endpoints require WordPress authentication (Application Passwords)
- Read tools require `edit_posts` capability
- Write tools require `manage_options` capability
- Input validation on all tool parameters
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

### 🔜 v1.1 — Design Tokens & Settings Sync
- **Design Token System**: Centralized token registry, CSS custom property output, admin UI
- **Settings Sync**: ACF-style JSON export/import for version-controlled settings
- **MCP rate limiting**: Transient-based request throttling

### 🔮 v1.2 — Extended Features
- **Element Defaults Manager**: Admin UI for setting default values for Cornerstone elements
- **Color Audit & Replace**: CLI tool to find and replace hardcoded colors with global palette references
- **Layout Versioning**: Git-friendly JSON export with diff support

---

## Support

If you find this project useful, consider supporting its development:

[![Ko-fi](https://img.shields.io/badge/Buy_me_a_coffee-ff5e5b?logo=ko-fi&logoColor=white&style=for-the-badge)](https://ko-fi.com/renandadalte)

---

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'feat: add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

Please follow [Conventional Commits](https://www.conventionalcommits.org/) for commit messages.

---

## License

This project is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.
