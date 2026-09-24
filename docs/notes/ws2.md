# Workstream 2 notes (A9, A17, A18, B1–B5, C3)

Changelog-ready bullets for `[1.5.0]`, the README changes they need, and what the integrator has to do when merging this branch.

## Changelog bullets

### Removed

- **Header presets on `create_document`** (`preset`, `preset_options`, and `src/Recipes/HeaderRecipes.php`): the recipes held about forty element keys that nothing checked against the element registry. Replacement: save a finished header as a document template with `create_template` (`from_document`) and pass `get_template`'s content as `layout_data`, or start from a `list_prefabs` prefab with `update_layout`. Passing `preset` is now an unknown-argument error
- **`restore_snapshot`**: it wrote back whatever options the snapshot it was given named, so a hand-edited or foreign snapshot could write any option on the site. Replacement: Pro's Theme Options export and import (colors and fonts included since Cornerstone 7.6.0), `restore_settings` for a single option, and host backups. `create_snapshot` stays as a read-only site inventory; its result no longer carries `restorable`
- **`titles` on `import_tco`**: Cornerstone's importer takes the archive as it is. A template whose title is already in the library comes in with a numbered title, as it does in the builder
- **The hand-kept extension list** (`Extensions::KNOWN`): guessed class names, constants, element types and token groups for six extensions. Replacement: the live registries (see Changed)

### Changed

- **`import_tco` uses Cornerstone's own importer** (`cs_import_tco()`), the one the builder uses. It now brings in what the exporter bundles: terms (reused by name, which overwrites that term's slug and description), global colors and fonts (merged by `_id`, replacing an item with the same `_id`), images (sideloaded into the Media Library and deduplicated), component and document dependencies, and templates. Every image reference in the imported content, attachment or literal URL alike, is re-pointed at this site's copy as `<id>:full`. Menus and a full-site export's Global CSS are not imported, and the description says so. Without `confirm: true` it still describes the archive first: what it would add, replace and ignore, and anything that blocks it. A blocked archive imports nothing. Colors and fonts are backed up first, so `restore_settings` can undo a replaced item. The tool is now annotated destructive and not idempotent
- **Extensions come from the live registries**: `get_platform_baseline`'s extensions block lists the element types, Dynamic Content groups and looper providers the site has, grouped by the plugin, theme or Cornerstone whose code registered each one. The owner is found by reflecting each entry's own code (an element's render or builder callback, a group's value filter, a provider's class or filter). An entry that cannot be attributed is listed under `unknown`, a registry that cannot be read is named under `unreadable`, and the Max products and ACF sit beside it
- **`create_document` names each type's regions**: the description and the `layout_data` schema list them (header: top, right, bottom, left; footer: footer; single and archive layouts: layout). The names come from Cornerstone's document classes and the `cs_layout_type_required_regions` filter, not a list kept in the plugin
- **`create_translation` status**: every copy starts as a draft, whatever the source's status and post type. A Cornerstone document (`cs_*`) is published as `tco-data`, the status Cornerstone assigns and renders documents from, only when `status: "publish"` asks for it

### Fixed

- **A region the document type does not render is an error**: `create_document` only warned about it, so a single layout written with a `content` region saved, got its site-wide assignment and rendered empty, because Cornerstone drops unknown regions when it loads a document. `create_document` and `deploy_layout` now refuse it, name the stray region and the ones the type renders, and write nothing. `deploy_layout` checks it before validation and the backup, and `skip_validation` does not skip it
- **`create_translation` corrupted the JSON it copied**: `post_content` went into `wp_insert_post()` unslashed, so every `\"` and `\n` in a header, footer, component or layout lost its backslash and the copy no longer decoded. It now writes through the same slash-and-guard path as every other raw document write, and the title and excerpt are slashed too
- **`create_translation` could publish without the publish check**: for anything but a page or post it took the source's status, so a published source gave a published copy and the post type's publish capability was never asked for. Copying a Cornerstone document, or a page built in Cornerstone, now also needs `unfiltered_html`, like every other document write
- **`create_translation` left most of the post behind**: the copy now gets every `_cornerstone_*` and `_cs_*` key the source holds (except caches and per-post records such as generated styles, the component map and import signatures), `_wp_page_template`, the featured image and the taxonomy terms. A term, the featured image and the parent page are each swapped for their target-language translation where WPML has one, and keep the source's otherwise
- **`create_translation` made orphans**: with no WPML translation group to join (a post type WPML is not set to translate, or a post not saved since WPML was activated) it inserted the copy anyway, and WPML started a new group for it. It now refuses before writing, as Cornerstone's own translation endpoint does
- **`create_component` could report a component that never appears**: a shape the registry does not recognise, or an export id another document already uses, saved without complaint. After the write it rebuilds the registry the builder reads and looks for every export. If one is missing, the new document is deleted and the call fails, naming the missing export and any document that holds a clashing id
- **The platform baseline never saw a permission change**: it stored `array_keys(Features::permissions())`, which is the result's own four keys (`available`, `user_id`, `allowed`, `denied`). It now stores the permission names, allowed and denied together and sorted. It stores the names only, not which way each went, because the answers belong to the calling user
- **Free ACF was reported as ACF Pro**: the extension entry matched the `ACF` class, which free ACF declares too. ACF Pro is now the `ACF_PRO` constant or `acf_get_setting('pro')`, in `get_platform_baseline` and in `get_site_info`'s `features.acf`
- **`import_tco` let an archive carry code, SVGs and media past the rules**: Cornerstone's importer checks none of these, so they are checked over the whole archive before it runs. Each of the following blocks the import:
  - an entry type or taxonomy this site lacks
  - `customCSS`, `customJS` or Raw Content from a user without `unfiltered_html`
  - images from a user without `upload_files`
  - an SVG, unless `pe_allow_svg_uploads` is on and the SVG passes the validator `upload_media` uses

  Every member is now held to the per-file and total expansion limits, images included, because the importer extracts them all

## README changes needed

- Tool list: remove `restore_snapshot`. Describe `create_snapshot` as a read-only site inventory, and name Theme Options export/import, `restore_settings` and host backups as the way to put settings back
- Tool list and the "Site Systems" feature paragraph (around line 442, "Menus and Pro headers (with mega menus)"): drop the header presets. Say that a reusable header is saved as a template and applied with the template tools or `list_prefabs`
- `create_document` row: add "a region the type does not render is an error" and list the regions (header: top/right/bottom/left, footer: footer, layouts: layout)
- `import_tco` row, if there is one: "imports with Cornerstone's own importer (templates, documents, colors, fonts, media, terms; not menus); describe first, `confirm: true` to write; `titles` removed"
- "Extension reporting" (feature paragraph): say it reads the live registries and groups entries by the plugin that registered them, not a list of known extensions
- `get_site_info` row: no change to the wording, but `features.acf.pro` is now true only for ACF Pro
- The document storage section (around line 396) already describes `{"settings", "regions": {"<region>": [...]}}`. A sentence could name each type's regions and say that unknown ones are refused

## Integrator to-dos

- **`Permissions::TOOL_KEYS`** (workstream 1's file): add `create_translation`. Suggested key: `content.{post_type}` for content, or `layout`/`component` for the matching `cs_*` document type. It varies by source, so it may need the same per-document treatment as `update_document_settings`. If one fixed key is required, `layout` is the closest for the documents the fix was about
- **Tools removed from `Server.php`**: `restore_snapshot`. Remove it from any permission-coverage or schema-parity test lists, and from exempt lists
- **Tool count**: both smoke suites now expect 45 tools on this branch, one fewer than 46. Add the tools other workstreams register (`get_native_reference`, `set_global_js`, …) when merging
- **`import_tco` constructor** now takes `SettingsBackups` as its third argument. `Server.php` is updated. Check that it survives a merge with other changes to the factory list
- **`TemplateGateway::readArchive()` is now static** and returns `{files, manifest, entries, svg, bytes}` instead of `{entries: [{file, data}], files}`. `import_tco` is the only caller
- **`DocumentGateway`** gained `insertRaw()`, `rawPostarr()`, `renderedRegions()` and `assertRegionsRendered()`. All are additive, placed next to `writeRaw()` and `regionsFor()`
- **`PlatformSnapshot`**: A19 (Twig, conditions, parameter types) edits the same file. This branch touches `take()` on the `permissions` line only. It also replaces `extensions()` and adds private helpers and two class constants at the top
- **`Features.php`**: one line in `report()` (`'acf' => self::acf()`) and a new `acf()` method
- **`CreateComponent.php`**: the self-check sits after `createDocument()` and adds two public static helpers. The `_p_json` encoding lines are untouched
- **CHANGELOG `[1.3.0]`** has a bullet for `create_snapshot and restore_snapshot` and one for "Extension awareness … ACF Pro". Those are history and stay. The Removed and Fixed bullets above supersede them

## Needs a live site (not verified here)

- **`import_tco` confirmed write**: `cs_import_tco()` has not run against a real archive in this environment. Check on the test install:
  - an `export_tco` archive of a header with images and a component round-trips
  - images are re-pointed
  - colors and fonts merge
  - the backups restore
- **Native importer quirk**: when an image is marked `no-import` in the manifest, Cornerstone's importer raises PHP warnings and writes `":full"`. The describe step warns about it, but the write goes ahead
- **WPML (plan E)**: nothing here could be exercised without WPML. Check on the test install:
  - that the translation joins its group
  - that terms map through `wpml_object_id`
  - whether `wp_get_object_terms()` returns the source's terms while the admin language differs (WPML filters term queries)
  - that a translated header is assigned to its own language. The copy keeps the source's assignments; Cornerstone's own clone drops them and relies on WPML mapping the assigned document to the current language
- **Extensions attribution**: check on the test install:
  - that Cornerstone Forms' elements group under `plugin:cornerstone-forms` and Cornerstone's own elements under `cornerstone`
  - that nothing unexpected lands in `unknown`
- **`create_component` self-check**: exercise the rollback path by passing an export id that another component document already uses
