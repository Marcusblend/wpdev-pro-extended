# Workstream 3 — native coverage (C1, C2, C4, C5, C6, A19)

Branch `ws3`, based on `feature/v1.5-native-complete`. Unit tests: 1007 pass (751 at the start). With
`PE_TWIG_AUTOLOAD=<cornerstone>/includes/integration/Twig/vendor/autoload.php` five more run against
Cornerstone's vendored Twig 3.9.3 (1012).

## CHANGELOG — `[1.5.0]`

### Added

- **`get_native_reference`**: one read tool, with a required `section`, for what Cornerstone on this site offers natively, read from its live registries rather than a hand-kept list. `dynamic_content` is what `list_dynamic_content` returned (same `group`, `search` and `groups_only`). `twig` gives the switch and every `cs_twig_` sub-toggle, the functions, filters, tests, tags and globals registered on the site's own Twig environment, how Dynamic Content groups read as Twig variables (`post.title`, `looper.item`, `param.x`), the stored Twig templates (`template: "<id>"` returns one in full) and notes such as passing the site timezone to `date()`. `conditions` lists every rule key with its operators and value choices, for element `show_condition` and for document assignments, with the stored rule shape of each. `loopers` lists each provider, the `looper_provider_*` keys it reads, what the current item is while it loops, and the `{{dc:looper:*}}` fields. `parameter_types` lists the managed types a component's `_p_json` can use and what each outputs. `regions` names the regions each document type renders. Each section is cached until Cornerstone, the theme or the plugins change (`refresh: true` reads again), and a registry that cannot be read is reported as `unavailable` with the reason and never cached
- **`set_global_js`**: the home for the few lines of script a build cannot do without, instead of a plugin or `wp_head` output. It edits Global JS through named blocks (`// pe:begin <name>` … `// pe:end <name>`, each marker on its own line) the way `set_global_css` edits Global CSS: `upsert_block`, `remove_block`, and `replace_all` behind `confirm_replace_all`; script outside the blocks never changes, `dry_run` returns the diff, and every write is backed up for `restore_settings`. It needs `manage_options` and `unfiltered_html`, refuses `<script`, `</script`, `<?` and marker text, and warns on `{{`/`{%` (Cornerstone runs Global JS through Dynamic Content and Twig), `eval` and `document.write`
- **Custom Assets (Cornerstone 7.9)**: `update_document_settings` takes `customScripts` and `customStyles` on any document or page, and `update_theme_options` takes the site-wide `cs_custom_scripts` and `cs_custom_styles`. Items are checked before anything is written — `https` URLs only, known keys, a `type` of `""` or `"module"`, a known `rel`, handle-safe ids — and completed with the builder's defaults. Both need `unfiltered_html` and Cornerstone's `global.document_assets` permission. Layout backups now carry a document's asset meta, so `restore_layout` puts it back, and `get_layout` returns `document_assets` when a document has any
- **`twig-syntax` and `twig-off` validator warnings**: every element string holding Twig (`{%`, a `{{` that is not a `{{dc:` token, or `{# #}`) is parsed — never rendered — with the site's own Twig environment, and a string that does not parse is reported with its key and Twig's message. Cornerstone prints the raw template on the live site when Twig fails, so this is the only place the mistake shows. Dynamic Content tokens are set aside first, since Cornerstone expands them before Twig runs. With Twig switched off, Twig syntax is reported as `twig-off` because it would print literally
- **Twig templates through `update_theme_options`**: `cs_twig_templates` is checked before it is written — a list of `{id, title, template}` with unique, include-safe ids — because Cornerstone loads every item into its Twig environment without checking, and one malformed item breaks every Twig render on the site. Shared logic is written once and pulled in with `{% include 'cs-template:<id>' %}`
- **`valid_parents` on `list_elements`**: where each element may sit, from the registry data the validator checks. Cornerstone's own `valid_parent` wins where an element declares one (a Section sits directly in a region, a Tab in Tabs, a marker in a Map); otherwise it is every current container that accepts the element, classic and deprecated containers left out
- **`emits` on `get_element_schema`**: the selector Cornerstone writes an element's styles with — one generated class, specificity 0,1,0, plus the site's `:where()` prefix, which adds nothing — and, where the element's TSS source can be read, the properties it always emits, the mixins it includes, what only sometimes applies, and each nested selector with its specificity. Where the source cannot be read it says so instead of guessing. Tag controls list the tags they accept (`accepts.tags`), and the Section and Div surfaces carry a note on Pro's `::before`/`::after` clearfix under `display: grid` or `flex`
- **Native registries in `get_platform_baseline`**: the fingerprint now records the site's Twig function, filter and test names, the condition rule handlers (with each expression operator), the looper provider keys and the parameter types, so a Themeco release that adds or drops one shows as drift. A registry that cannot be read — Twig while it is off — is recorded as null and not compared, and neither is a baseline saved before 1.5

### Changed

- **`list_dynamic_content` is a deprecated alias** of `get_native_reference` `section: "dynamic_content"`; its description says so, and it goes after 1.5
- **`update_theme_options` refuses `cs_twig_extension_advanced`**, the Twig extension that lets any Twig string call any PHP function and fire WordPress actions. Twig itself and its other sub-toggles stay writable, and the tool's description now says Twig is switched on there
- **Choices in the control surface are keyed by value**: Cornerstone writes most choices as a list of `{value, label}`, and `accepts.choices` keyed them by list position (`"0" => "<div>"`). They are keyed by the value the setting stores now

## README

Tool table (`### MCP Tools`), new rows:

| Tool | Type | Description |
|------|------|-------------|
| `get_native_reference` | Read | What Cornerstone offers natively, from its live registries: `section` `dynamic_content`, `twig`, `conditions`, `loopers`, `parameter_types` or `regions` |
| `set_global_js` | Write | Create, replace or remove named Global JS blocks (or replace the whole script); needs `unfiltered_html` |

Changed rows:

| Tool | Type | Description |
|------|------|-------------|
| `list_elements` | Read | List all Cornerstone element types with groups, valid children and valid parents |
| `get_element_schema` | Read | The settings an element type has, as the Inspector groups them, with what its styles always emit (`emits`), accepted tags and element notes |
| `get_layout` | Read | … (add) and the document's Custom Assets (`document_assets`) when it has any |
| `update_document_settings` | Write | Change a document's settings (Custom Assets included), title or slug without touching its elements |
| `list_dynamic_content` | Read | Deprecated: use `get_native_reference` `section: "dynamic_content"` |

Warning-code table, new row:

| Code | Meaning |
|------|---------|
| `twig-syntax`, `twig-off` | Twig in an element string that does not parse with the site's Twig environment, or Twig on a site where it is off |

Document settings (under "Writes, backups and dry runs" or a new "Document settings" paragraph):

- `customScripts`: a list of `{src, id, type, deps, ver, async, defer, nomodule, in_footer}`; `type` is `""` or `"module"`; `in_footer` defaults to `true`.
- `customStyles`: a list of `{src, id, rel, media}`; `rel` is `stylesheet` (default), `preload`, `prefetch`, `modulepreload` or `alternate`.
- `src` must be an absolute `https://` URL; missing item keys take the builder's defaults; `[]` removes them all. Both need `unfiltered_html` and Cornerstone's `global.document_assets`. Site-wide: the theme options `cs_custom_scripts` and `cs_custom_styles` with the same item shapes.
- Twig is switched on with `update_theme_options` `{"cs_twig_enabled": true}`; `cs_twig_extension_advanced` is refused. Twig templates are `cs_twig_templates`: `[{"id", "title", "template"}]`.

Writes/backups bullet: Global JS backups are restored with `restore_settings` and key `option:<Global JS option>` (`option:x_custom_scripts` on Pro); the tool returns it as `restore_key`.

Security bullet: `set_global_js`, and Custom Assets on `update_document_settings`/`update_theme_options`, need `unfiltered_html`.

## Integrator to-dos

- **`Permissions::TOOL_KEYS`** (workstream 1): add
  - `'set_global_js' => 'global.edit_custom_js'` (a real Cornerstone 7.9.4 key, `classes/Services/Permissions.php:72`)
  - `'get_native_reference' => 'element-library'` if read tools are gated the way `list_elements` is; otherwise put it on the exempt list with `list_dynamic_content`
  - Custom Assets are checked inside `update_document_settings` and `update_theme_options` against `global.document_assets` only when an asset key is written (so the tool key stays `layout` / `global.theme_options`).
- **A16** (`ThemeOptionsWriter::REFUSED['x_custom_scripts']` still says "written with set_global_css"): point it, and `cs_v1_custom_js`, at `set_global_js`. I left the line alone to avoid a conflict with whoever owns A16.
- **Handshake (D1)**: already names `get_native_reference` and `set_global_js`; nothing to change.
- **Smoke suites**: `readonly-suite.php` sections 23 and 24 are new. The tool-count assertions (`46`) in both suites need the final count: this workstream adds `get_native_reference` and `set_global_js`.
- **`clear_cache`**: consider calling `NativeReference::clearCache()` from the `elements` branch (A15 touches that branch, so I did not).
- **`ElementLint`**: the Twig codes are in `ElementLint::TWIG_CODES` with `checkTwig()`; `ElementLint::codes()` returns both lists. If workstream 4 prefers one `CODES` array, fold them in and add the two fixtures from `tests/unit/TwigLintTest.php` to `ElementLintTest.php`'s fixture list.
- **`LintContext`** gained two trailing optional arguments (`twigEnabled`, `twigParser`); `ElementContext::lintContext()` passes them by name, so extra positional arguments added by workstream 4 do not collide.
- **`LayoutService`**: two one-line hooks (`DocumentAssets::addToBackup()` in `backup()`, `DocumentAssets::restoreFromBackup()` in `restore()` just before the TSS cache clear). A10 edits `restore()` nearby.
- **`DocumentSettings`** is unchanged: Custom Assets are taken out of the settings map in `UpdateDocumentSettings::takeAssets()` before validation. `create_document` does not accept them; add them there too if wanted.

## Where each registry comes from (Cornerstone 7.9.4)

- Twig: `integration/Twig/cornerstone-twig-renderer.php` (boots only with `cs_twig_enabled`, PHP 8.1+), `src/Renderer.php` (`twigInstance()`: `cs_twig_filters`, `cs_twig_functions`, `cs_twig_boot`), `src/api.php` (`cs_twig_environment()`), `src/ThemeOptions.php` (option keys and the templates list control), `src/TwigExtension.php` + `src/DCToTwigGrabber.php` (DC groups as globals), `src/DynamicContentOverride.php` (Twig runs on `cs_dynamic_content_after_render`).
- Conditions: `classes/Services/Conditionals.php` (`get_condition_contexts()`, `get_assignment_contexts()`), `classes/Services/RuleMatching.php` (stored rule shape, rule name resolution), `classes/Util/ConditionRules.php`.
- Loopers: `elements/control-partials/looper-provider.php` (choices and per-provider controls), `looper-consumer.php`, `classes/Services/LooperProviders.php` (`looper_provider_<type>_<key>`), `_classes/dynamic-content/class-looper-provider.php` (`looper_factory`), `class-dynamic-content-looper.php` (`{{dc:looper:*}}`).
- Parameter types: `classes/Util/ManagedParameters.php` (`managedTypes()`, `cs_parameters_managed`), `classes/Util/Parameter.php` (group, `[]`, `color-pair`, `isVar`, shorthand).
- Regions: `classes/Documents/Header.php`, `Footer.php`, `Layout.php` (`getRegions()`, `cs_layout_type_required_regions`), `Component.php` / `Content.php` (`getInitialElements()`).
- Custom Assets: `integration/DocumentAssets/DocumentAssets.php`.
- Global JS key: `classes/Services/ThemeOptions.php` (`get_global_js_key()`, `cs_global_js_option`), printed by `classes/Services/EnqueueScripts.php` (`globalCustomJs()`, Dynamic Content applied).
- Element styles: `classes/Tss/Reducers/ModuleReducer.php` (selector `.$m`), `classes/Services/Tss.php` (`getSelectorPrefix()`, TSS assets in `assets/tss/`), `classes/Elements/Definition.php` (`get_style_template()`, `get_tss_config()`); valid parents from `options.valid_parent` in `elements/definitions/*.php`; tag choices from `elements/registry-setup.php` (`options_choices_layout_tags`).

## Verify on a site

Run on the test install as an administrator (`wp pe mcp call <tool> '<json>' --user=<ID>` or through the client).

1. **Twig environment names** (Twig on): `get_native_reference {"section": "twig", "refresh": true}` — `environment.functions` should include `date`, `range`, `get_posts` (WordPress extension on); `environment.globals` should include `post`, `looper`, `param`, `dc`. Then with Twig off: `environment.unavailable` explains why.
2. **twig-syntax end to end**: `validate_layout {"layout_data": [{"_type": "text", "_m": {"e": 1}, "_bp_base": "4_4", "text_content": "{% if %}x{% endif %}"}]}` → `twig-syntax` with Twig's message; `"{{ \"now\"|date(\"Y\") }} {{dc:post:title}}"` → no warning. Also run `deploy_layout` with `dry_run` on a Twig page to confirm building the environment during a write-path validation has no side effect on the save.
3. **Conditions**: `get_native_reference {"section": "conditions", "search": "specific-post"}` — rules read in builder context; confirm `value.lookup` is `posts:<type>` and `csi18n` labels resolve (not raw keys).
4. **Loopers**: `get_native_reference {"section": "loopers"}` — confirm the partial is readable outside the builder (`cs_partial_controls('looper-provider')`), `query-builder` lists `looper_provider_query_post_types`, and `item` is `post` for `query-recent`, `term` for `taxonomy`, `array-or-post` for `custom`/`dc`/API providers. `unknown` means `Cornerstone_Looper_Provider` classes were not loaded on that request.
5. **Regions**: `get_native_reference {"section": "regions"}` — header `top/right/bottom/left`, footer `footer`, layouts `layout`, component and page `content`. `Document::create('content')` for pages is unverified.
6. **`emits`**: `get_element_schema {"element_type": "layout-div", "search": "tag"}` and `{"element_type": "cornerstone-form"}` (Forms installed; its custom style template is read directly). Core elements depend on finding `@module <name>` blocks in `CS_ROOT_PATH/assets/tss/` (read as text); if `properties` is null there, the module sources are not in that form and only the selector facts apply. Confirm the `:where(...)` prefix matches the site's compatibility mode.
7. **Clearfix note**: build a Section and a Div with `display: grid` in their `css` (`$el { display: grid; grid-template-columns: 1fr 1fr; }`), `render_preview`, and check whether Pro's stylesheet gives `.x-section`/`.x-div` `::before`/`::after` content. The note's wording assumes it does (from build experience); it is not in the PHP source.
8. **Tag list**: `get_element_schema {"element_type": "layout-div", "search": "tag"}` — `accepts.tags` should list div … label. If the Inspector data already keys choices by value, the list is unchanged; if it came as `{value, label}` lists, earlier versions returned `"0"`, `"1"` … keys.
9. **Custom Assets**: `update_document_settings {"document_id": <header id>, "settings": {"customScripts": [{"src": "https://cdn.jsdelivr.net/npm/<pkg>", "id": "pe-test"}]}, "dry_run": true}`, then for real on the test install: check `_cs_document_scripts` meta, the script tag on the front end, `get_layout` `document_assets`, then `restore_layout` with the returned `backup_id` removes it. Repeat as a user without `global.document_assets` (refused). Site-wide: `update_theme_options {"options": {"cs_custom_styles": [{"src": "https://…/x.css"}]}, "dry_run": true}`.
10. **Global JS key**: `set_global_js {"operation": "upsert_block", "name": "pe-test", "js": "window.peTest = 1;", "dry_run": true}` — `option_key` should be `x_custom_scripts` on Pro (Cornerstone's own default is `cs_v1_custom_js`); then a real write, check `<script id="cornerstone-custom-js">` on the front end, and `restore_settings {"key": "option:x_custom_scripts"}`.
11. **Twig templates shape**: `get_theme_options` on a site with templates made in the builder — confirm items are exactly `{id, title, template}` (the validator allows extra `_`-prefixed keys in case the list control adds bookkeeping).
12. **Baseline**: `get_platform_baseline {"include_lists": true}` — the six new lists are filled (Twig ones null while Twig is off); save, add a Twig filter through `cs_twig_filters` in a test mu-plugin on the test install, and confirm `twig_filters` shows it as added.
