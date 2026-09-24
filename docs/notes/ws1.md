# Workstream 1 notes: section A fixes

Covers A1–A8, A10–A14 and A16 from `docs/v1.5-plan.md`. A9, A15, A17, A18 and A19 belong to other workstreams.

## CHANGELOG, `[1.5.0]`

### Fixed

- **`update_layout` refused its own editing operations**: `move`, `duplicate`, `wrap`, `unwrap` and `prefab` were handled and documented, but `inputSchema()` still listed only `add`, `remove`, `update` and `preset` and declared no `to`, `group` or `name`. A client that validates arguments against the tool's schema rejected those calls before they arrived, the same bug 1.3 fixed for `preset`. The schema now reads the list of operations the tool handles, and every operation declares the properties it takes. A new schema parity test runs over every registered tool and checks that the ops and arguments each description names are in its schema
- **`move` landed one place too far down its list**: it took the element out and then read `to` in the tree that was left, where every later sibling had already moved up. Moving `0._modules.0` to `0._modules.2` landed after the old third sibling instead of before it, and a target inside a later top-level sibling could point at nothing. `to` is now resolved against the tree as it stands: its parent must exist and its index must fit before anything is taken out. The element lands where the one at `to` is now, which moves down, as a drop in the builder does. A move into its own subtree is still refused
- **`wrap` inserted an unstamped wrapper**: `add` gave new elements the `_m` and `_bp_base` markers Cornerstone gives elements it creates, and `wrap` did not, so a wrapper of a type with base migrations was read as legacy content and filled with its oldest defaults. The wrapper is now stamped like an added element (`stamp_new`), and the element it wraps keeps exactly the markers it had
- **The `prefab` operation entered builder context during a save**: it read the prefab from Cornerstone's element library, which only answers inside builder context, so the write fired `cs_before_late_data` and marked the save request as a builder request. `list_prefabs` now caches the values it reads (per Cornerstone version), and the operation takes them only from that cache. When a prefab has not been read yet, the operation is refused with the `list_prefabs` call to make first
- **A preset replaced the element's content and label**: the `preset` operation merged every key the preset stored, so a preset saved from a real element carried its text, tag, label and migration marker onto the target, and replaced its whole responsive data map. It now applies only the keys the element's definition designates as style, and merges responsive values key by key. Content, `_label`, `_m`, `_c_*`, ids, children, region and parent stay the element's own. When the designations cannot be read, the operation is refused rather than guessed
- **`set_api_allowlist` could open the External API to every URL**: Cornerstone reads an empty allowlist as "allow everything", and the tool would remove the last entry while the feature was on. It also accepted hosts inside the site's own network, and silently stripped user information from `https://user@host`. While the External API is on, a change that leaves the list empty is refused. Refused hosts: `localhost`, `*.localhost`, loopback, RFC 1918, link-local `169.254.0.0/16`, `0.0.0.0/8`, IPv6 loopback, link-local and unique local addresses, IPv4 addresses written as IPv6, and numeric forms such as `2130706433` or `127.1`. A URL with user information is refused rather than stripped
- **`render_preview` could show a post its caller cannot read, and misjudged its context**: `post_id` and `for_post` took any post, so a contributor could render another author's private post. Both now need `read_post`. `for_post` set only `$post`, so `is_singular()`, archive conditions and the current-query looper answered for whatever request was running. The post now becomes the main query (`$wp_query` and `$wp_the_query`), and every global the render touches is put back afterwards. Output of only an image, SVG, video, iframe or picture is no longer reported as `empty`. The tool stays read-only (it writes nothing to the database), is now marked open-world because it runs shortcodes, Twig and External API loopers, and no longer calls itself "strictly read-only"
- **Tools added since 1.3 ignored Cornerstone's permissions**: `render_preview`, `list_dynamic_content`, `create_snapshot`, `restore_snapshot`, `set_api_allowlist`, `list_layouts`, `get_layout`, `validate_layout`, `list_prefabs`, `list_settings_backups` and `restore_settings` ran for a role Cornerstone had shut out. Each now declares the Cornerstone permission its work needs. `set_global_css` is gated on Cornerstone's own `global.edit_custom_css` rather than `global.theme_options`. Every tool that stays ungated is on an explicit exempt list with the reason, and a test requires every registered tool to be on exactly one of the two lists
- **Renaming a child menu item moved it to the top level**: `update_menu` sent `menu-item-parent-id` only when an operation named a parent, and `wp_update_nav_menu_item()` reads a missing one as 0. Every add and update now sends a parent: the one the operation names, or the one the item already has
- **`restore_layout` left settings that were added after the backup**: a page backed up without `_cornerstone_settings`, then given a header override by `update_document_settings`, kept the override after the restore. A backup that recorded no settings now deletes the row, and a settings write the database refuses is reported instead of passing as success. Backups made before settings were captured restore exactly as before
- **`set_variables` dropped or renamed variables it was not asked about**: the stored list was rebuilt on every write, so an item whose name the pattern rejected, a duplicate and a non-item were dropped, and a stored `--name` was renamed to `name`. Only the names a call adds, changes or removes are validated now. Every other stored item is kept byte-identical, in place
- **Parameter schemas lost their objects on write**: `set_global_parameters` decoded and re-encoded the stored `_p_json` schema on every write, even a write that only changed values, so every `{}` came back as `[]` (which the builder reads as a list) and the escaping changed. A schema string is now written back exactly as given. A schema that arrives already decoded gets `{}` back wherever a schema always has an object (the top level, a group's params, a parameter's definition, a group's initial value). `create_component` encodes its `_p_json` the same way
- **The write journal misread dry runs and skipped failures**: `dry_run: "false"` was logged as a dry run, and `import_tco` and `restore_snapshot` previews (no `confirm`) and baseline reads (no `save`) were logged as writes. Each tool's own flag is now read the way the tool reads it. A write that throws is journalled as failed, with its error message
- **The Global JS refusal named the wrong tool**: `update_theme_options` said Global JS (`x_custom_scripts`, `cs_v1_custom_js`) is written with `set_global_css`. It now names `set_global_js`

### Changed

- **Reading layouts needs Cornerstone's `layout` permission**: `list_layouts` and `get_layout` are gated on `layout`, as the layout write tools already were. `validate_layout`, `list_prefabs`, `list_dynamic_content` and `render_preview` need `element-library`. `create_snapshot` needs `global.theme_options`. `set_api_allowlist`, `list_settings_backups` and `restore_settings` need `global`. Administrators have all of these by default
- **`update_layout`'s `prefab` operation reads from `list_prefabs`' cache**: call `list_prefabs` (a listing, or `group` and `name`) before inserting a prefab by name. The cache lasts a day and is tied to the Cornerstone version

## README

- Tool table, `update_layout`: "Apply patch operations: add, remove, update, preset, move, duplicate, wrap, unwrap, prefab".
- Tool table, `render_preview` (when the table gains the 1.4 tools): "Render elements or a stored layout to HTML against a post, without saving. Needs `read_post` on the posts it renders."
- "New elements and warning codes": `update_layout` stamps elements inserted by `add` and the wrapper a `wrap` puts in.
- Add a line under "Writes, backups and dry runs": "Every tool that touches Cornerstone data also checks the Cornerstone permission its work needs (`Permissions::TOOL_KEYS`). The tools that don't are listed with the reason in `Permissions::EXEMPT`."
- `set_api_allowlist` (when listed): "refuses internal hosts and user information, and never leaves the list empty while the External API is on."

## For the integrator

- **Permission lists** (`src/Cornerstone/Permissions.php`). `PermissionsTest` fails until every registered tool is in exactly one of `TOOL_KEYS` and `EXEMPT`, and every entry is registered.
  - Add `set_global_js` => `global.edit_custom_js` (the brief's C4).
  - Add `get_native_reference`. It reports what the builder offers while editing elements, so `element-library` matches `list_dynamic_content`, its deprecated alias.
  - Remove `restore_snapshot` from `TOOL_KEYS` when B2 removes the tool. Also drop it from `WriteJournal::PREVIEW_UNLESS` (harmless if left).
  - `create_translation` is in `EXEMPT`. When A18 adds it to `TOOL_KEYS`, delete the `EXEMPT` entry; the test refuses a tool on both lists.
- **Schema parity test** (`tests/unit/SchemaParityTest.php`) runs over every registered tool, including new ones.
  - Every `op` or `operation` enum value must be named in the tool's description.
  - Every `"op": "x"` the description shows must be in the enum.
  - Every `name: true|false|"text"|number` outside a `{...}` example must be a schema property, or a property of the tool named just before it ("list_templates with kind: ...").
  - A result field written that way must go in `RESULT_WORDS`, or be reworded.
- **Unit-test stubs** are in `tests/unit/stubs.php` (required from `bootstrap.php`). Each function is guarded with `function_exists`. If another workstream stubbed the same functions in `bootstrap.php`, keep one copy. `cornerstone()` is deliberately not stubbed. `PrefabsTest` defines it in a separate process (`tests/unit/fixtures/prefab-requests.php`, run with `shell_exec`).
- **Shared files, edited narrowly to keep merges clean**:
  - `Server.php`: only the `catch` in `handleToolsCall()`.
  - `CreateComponent.php`: only the `_p_json` encoding line.
  - `ElementContext.php`: a new `designations()` after `stamper()`. A15 edits further down.
  - `ThemeOptionsWriter::REFUSED`: two messages and the docblock. C2 adds a Twig key to the same constant.
  - `Annotations::read()`: gained an optional `$openWorld` parameter.
- **Smoke suite**: `tests/smoke/write-suite.php` now expects two open-world tools (`upload_media`, `render_preview`). The tool count there (46) is untouched.

## Verify on the test install

These paths need WordPress or Cornerstone and are only partly unit-tested:

- **A6.** Run `render_preview` with `for_post` on a Single/current-query looper and an `is_singular` condition. Both should resolve against the post. Then run a second tool in the same session to confirm the globals came back. The `WP_Query` setup is not unit-tested. If a filter narrows the query away from the post, the post is put back into it.
- **A4.** Run `list_prefabs`, then an `update_layout` `prefab` operation on a real prefab. The cache is keyed by `CS_VERSION`.
- **A11.** Apply a preset saved in the builder with `update_layout`, and compare with applying it in the builder. Only keys designated `style*` are applied, so a preset that relied on `css` (designated `markup`) or another non-style key no longer applies that key.
- **A10.** Back up a page without `_cornerstone_settings`, set a header override with `update_document_settings`, then run `restore_layout`. The override should be gone.
- **Limits**:
  - A5 checks the host as written: a public name that resolves to a private address is not caught.
  - A13 cannot recover a `{}` nested outside the known object positions once a client has sent the schema as a decoded object. Passing `json` as a string keeps it exact.
