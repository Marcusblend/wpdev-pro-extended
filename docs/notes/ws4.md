# Workstream 4: steering and lint

Notes for whoever merges the 1.5 workstreams. This file covers D1, D2, D3 and A15 from `docs/v1.5-plan.md`.

## Commits

| Commit | Item |
|---|---|
| `feat: point the MCP handshake at native Cornerstone features` | D1 |
| `feat: warn on custom shortcodes, typed dates and pasted HTML` | D3 |
| `feat: add the pe://guide/native recipe book` | D2 |
| `fix: make the style lints deterministic` | A15 |
| `test: cover the guide, native-first codes and stored surfaces in smoke runs` | D2, D3, A15 (smoke) |

D3 is committed before D2 because the guide names `hardcoded-date`, and `NativeGuideTest` checks that every code the guide names exists.

The optional `ControlSurface::propertyFromKey` fix was skipped (see "Not done").

## CHANGELOG entries for `[1.5.0]`

### Added

- **`pe://guide/native`**: a recipe book, in markdown, for the features site builds kept reaching for PHP to get. It covers the copyright year, a date-switched promo, price arithmetic, seasonal opening hours, a thank-you on `?sent=1`, the current nav item, one sticky bar in a multi-bar header, off-canvas mobile navigation, scroll reveal, read more, filtering, self-hosted fonts, background textures, repeating data, structured data and the last few lines of script. Each recipe gives the need, the native feature, the keys, tokens or Twig to write, and how to check it with `render_preview`. The timezone rules come first, because they are the trap. WordPress runs PHP in UTC and Cornerstone gives Twig no timezone, so `"now"` is UTC unless the site's zone is passed. Relative dates have to come from `date_modify` on a date that already carries the site's zone. A stored calendar date has to be formatted without a zone, or it moves back a day on sites west of UTC. Every key, token, condition and Twig name was read from Cornerstone 7.9.4. A unit test checks the guide against fixtures generated from that source
- **Native-first handshake**: the MCP `instructions` now open with the rule that a site build never ships PHP, a plugin, an mu-plugin, `functions.php` code, a custom shortcode or `wp_head` output. When a need seems to call for one, the model stops and says which native feature falls short. Instead of a list of tips, the instructions give an ordered ladder: the element's own setting, a global reference in it, components and Global Parameters, Dynamic Content and Twig, conditions, loopers, and native elements and effects. `set_global_css` and `set_global_js` come last
- **`custom-shortcode` validator warning**: element text that uses a `[shortcode]` whose callback is defined outside WordPress core, Pro/Cornerstone or a Themeco extension. The callback is reflected to the file that defines it, and the warning names that file (`plugins/site-helpers/site-helpers.php`), because the page now depends on that code. Dynamic Content or Twig does the same job natively. Cornerstone, the theme that bundles it, plugins named `cornerstone` or `cornerstone-*`, and the Max products listed in `x_max_plugins` count as Themeco's. Unregistered text and escaped `[[tags]]` are left alone
- **`hardcoded-date` validator warning**: a typed year beside ©, "(c)" or "Copyright" goes stale every January. A typed date after deadline wording ("before November 1", "until 12/31", "through Nov 1st") keeps the offer up after it ends. The warning names `{{dc:global:date format="Y"}}` for the year, and a Global Parameter with a `global:today` condition for the promo. A founding year before a live end year (`© 2015–{{dc:global:date format="Y"}}`) passes
- **`html-in-text` validator warning**: a Text or Headline element whose typography all resolves to `inherit`, or whose css sets `display: contents`, while it holds block-level HTML (`div`, `section`, lists, tables, headings, a classed `p`). That is a design pasted in as markup, and nothing in it can be edited in the builder. The warning points to elements and components
- **`wp pe warm`**: builds and stores the control surface of every element type. Run it after a deploy or a Cornerstone update, so the style warnings are there from the first write

### Changed

- **Control surfaces are stored until Cornerstone or Pro Extended changes**: each element type's surface is kept in its own non-autoloaded option (`pe_surface_<md5>`), with no expiry. An index (`pe_surfaces`) records the Cornerstone and Pro Extended versions the surfaces were built with. When either version changes, the stored set reads as missing, and the first write under the new versions drops it. `get_element_schema` stores the surface it builds, as before
- **`clear_cache` with `elements` rebuilds what it clears**: it drops the element definitions and every stored surface, then rebuilds the surfaces at once and reports how many it stored. The style lints are never left empty after a clear
- **`literal-color` and `literal-font-family` read more of the element**:
  - Each key's interaction twin (`<key>_alt`, the hover and active value) is checked too.
  - Per-breakpoint values in `_bp_data` are checked, and a warning names the slot.
  - A value containing `var(` or a `global-color:`, `global-ff:` or `global-fw:` reference anywhere counts as a reference, so `rgba(var(--brand-rgb), .5)` passes.
  - A bare generic family such as `sans-serif`, `serif`, `monospace` or `system-ui` is not reported as a literal stack.

### Fixed

- **The style warnings depended on timing**: `css-over-control` and `literal-color` only ran when an element type's control surface happened to be in a one-hour transient. The same layout warned or didn't depending on whether `get_element_schema` had run for that type within the hour. The warnings now read stored surfaces, which don't expire, and the write path still never enters builder context

## README additions

**MCP Resources**: change the count to 4 and add a row:

| URI | Description |
|-----|-------------|
| `pe://guide/native` | Recipes for common site features built only from Cornerstone features (Dynamic Content, Twig, Global Parameters, conditions, loopers, native elements), with the keys to write and how to verify each |

**WP-CLI Commands**: add a row, and a line to the WP-CLI Usage block (`wp pe warm   # Store every element type's control surface after a deploy`):

| Command | Description |
|---------|-------------|
| `wp pe warm` | Build and store every element type's control surface for this Cornerstone version, so the style warnings give the same answer on every run. Run it after each deploy or Cornerstone update (`--format=json` for a report) |

**Warning codes table** (under "New elements and warning codes"): add these rows. The first two cover codes from 1.4 that the table never listed.

| Code | Meaning |
|------|---------|
| `css-over-control` | A `css` declaration for a property the element has a setting for |
| `literal-color`, `literal-font-family` | A colour or font setting (including its `_alt` and per-breakpoint values) holding a literal value instead of a `global-color:`/`global-ff:` reference or `var()` |
| `custom-shortcode` | A shortcode whose callback is defined outside WordPress core, Pro/Cornerstone and Themeco extensions; the warning names the file |
| `hardcoded-date` | A typed year beside © or "Copyright", or a typed date in promotional copy |
| `html-in-text` | Block-level HTML in a Text or Headline whose typography is all `inherit` (or `display: contents`) |

Add one sentence after the table: "The style warnings (`css-over-control`, `literal-*`) read control surfaces that `get_element_schema`, `clear_cache` with `elements` or `wp pe warm` store. Until one of those runs, they stay quiet."

**Plugin Structure**: `SchemaExtractor.php  # Wraps cornerstone('Elements'); definitions cached, surfaces stored`. Add `SurfaceStore.php  # Control surfaces by Cornerstone and plugin version (plain PHP)`, `ShortcodeOrigin.php  # Where a shortcode is defined (plain PHP)`, `Resources/NativeGuideResource.php` (the resource count becomes 4) and `Commands/WarmCommand.php`.

## Integrator to-dos

1. **Merge points with workstream 3 (Twig lint)**, all in the same places:
   - `ElementLint::CODES`. Mine ends with `] + self::NATIVE_CODES;`.
   - `ElementLint::check()`. Mine adds `$this->checkNative(...)` last.
   - The `LintContext` constructor. Mine adds a trailing `?\Closure $shortcodeSource`.
   - `ElementContext::lintContext()`. Mine passes `$this->shortcodeSource()` last.
   - The fixture-coverage line in `ElementLintTest.php`, which now excludes `NATIVE_CODES`. Their fixtures live in `NativeLintTest.php`.

   Keep both sides' additions. Named arguments are safe: `NativeLintTest` and `DeterministicLintTest` build `LintContext` by name.
2. **Tools the handshake names**: `get_native_reference` and `set_global_js` come from other workstreams. The guide's last recipe describes `set_global_js` as named blocks with `dry_run`, per C4. Check the wording against the tool as merged. If either tool slips out of 1.5, edit `Server::INSTRUCTIONS` and the guide together; `ServerInstructionsTest` names both.
3. **Brief E, Twig smoke test**: the brief expects `{{ "now"|date("H:i") }}` to match `date_i18n("H:i")`. It won't unless the site is on UTC. Cornerstone never sets Twig's timezone: `Renderer::twigInstance()` adds no timezone, and Twig's `CoreExtension::getTimezone()` falls back to `date_default_timezone_get()`, which WordPress forces to UTC. Compare `{{ "now"|date("H:i", date_i18n("e")) }}` (or `{{dc:global:date format="H:i"}}`) with `date_i18n("H:i")` instead, and render each Twig block in the guide. I ran every snippet with the Twig 3.9.3 that Cornerstone bundles, under America/Denver, Europe/Berlin and Asia/Tokyo and across the season boundaries, but not on a live site.
4. **Deploy (brief F.3)**: add `wp pe warm` after each install's deploy. The 1.5.0 version bump makes every stored surface stale by design. `wp pe warm` also clears the element definitions cache first.
5. **A6 dependency**: the guide's check for the current nav item uses `render_preview` with `for_post`. That depends on A6 setting up the main query, so confirm it after A6 merges.
6. **`custom-shortcode` scope**: per the brief, third-party plugins count as site code, so `[contact-form-7]` or `[gravityform]` will warn with the plugin file named. If that is too noisy on the live installs, add an allow-list rather than weakening the classifier. On the test install, check that Pro's own `[x_*]`/`[cs_*]` shortcodes classify as Themeco's. They should, because `CS_ROOT_PATH` sits inside the Pro theme directory.
7. **Unverified on a live site**:
   - whether `layout-off-canvas` prints the `x-hide-*` classes where the guide says;
   - the stored shape of `form_data-cs-ajax-selectors` (the guide sends readers to `get_element_schema`);
   - whether a Global Variable holding `url()` works in any background-image setting, or only in `css` (the guide hedges);
   - the `global:today` date-picker value format (the guide uses `YYYY-MM-DD`, which `strtotime` reads).
8. **Cleanup**: the plugin has no uninstall routine. The surfaces add about one non-autoloaded `pe_surface_*` row per element type, plus `pe_surfaces`. `clear_cache` with `elements` removes them, and an uninstall routine should too.
9. **When Themeco ships a new version**: regenerate `tests/fixtures/cornerstone-7.9.4/` from the new source. The header line of each file says what it lists and where it came from. Then re-run `NativeGuideTest`, which fails on any name the guide cites that has gone.

## Not done

- **`ControlSurface::propertyFromKey`, leading qualifier.** The rule treats a qualifier at index 0 of a key (`sub_`, `dropdown_`, `anchor_`, `toggle_`) as the element's own prefix. To tell `anchor_bg_color` on a Button (the element's own) from the same key on a nav element (the links'), you need each element's style modules: which keys style `$el` and which style nested selectors. That lives in Cornerstone's TSS templates. Only Cornerstone Forms' `.tss` files are in the 7.9.4 source here; core's are not. The only other source is the element's style template from the live registry. Deriving a prefix from the Inspector's first tab regresses the Button, whose tab is `button_anchor` while its keys are `anchor_*`. So I skipped it rather than add a hand-kept prefix list.
