<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Resources;

/**
 * pe://guide/native — worked recipes for building common site features with
 * Cornerstone's own features instead of PHP, plugins or shortcodes.
 *
 * Every key, token, condition and Twig name below was read from Cornerstone
 * 7.9.4's source; tests/unit/NativeGuideTest.php checks them against fixtures
 * generated from it, so a recipe cannot quietly cite something that does not
 * exist. Where a key could not be confirmed, the recipe says to look it up
 * with get_element_schema rather than naming one.
 */
final class NativeGuideResource implements ResourceInterface
{
    public const URI = 'pe://guide/native';

    private const GUIDE = <<<'MD'
# Native recipes for Cornerstone builds

Each recipe builds a common site feature from what Cornerstone already has. None of them needs PHP, a plugin, an mu-plugin, a shortcode or wp_head output. For each one you get the need, the native feature, the keys, tokens or Twig to write, and how to check it with render_preview before you save.

The keys come from Cornerstone 7.9.4. Before you write one, confirm it on the site with get_element_schema and `search`, because another version can differ. Element data is shown as JSON without `_m` and `_bp_base`, because the create tools add those.

## Ground rules

- **Twig** must be on: check get_site_info `features.twig`. Dynamic Content tokens expand first and Twig renders the result, so a `{{dc:...}}` token can sit inside a Twig expression.
- **Time zones.** WordPress runs PHP in UTC, and Cornerstone gives Twig no timezone, so Twig's `"now"` is UTC.
  - When you read the clock in Twig, pass the site timezone. `date_i18n("e")` returns it; the WordPress Twig extension is on by default. `{{dc:global:date format="e"}}` also returns it.
  - Build relative dates with `date_modify` on a date that already has the site timezone.
  - Format a stored calendar date such as "2026-11-01" without a timezone. If you pass the site timezone, it moves back a day on sites west of UTC.
  - `{{dc:global:date}}` and the `global:today` condition already use site time.
- **Global Parameters** are site-wide values the client edits, written with set_global_parameters. Read one as `{{dc:global:<name>}}` in any field, or as `global.<name>` in Twig. Don't give a parameter the name of a built-in global field (`date`, `time`, `site_title`, `home_url`): the field wins.
- **Verify** with render_preview. Pass `elements` (or `post_id` and `path`), and pass `for_post` when the output depends on the page. Nothing is saved. Then run validate_layout and fix every warning you introduced.

## Dates and time

### Copyright year
- **Need:** a footer year that never needs editing.
- **Native:** Dynamic Content.
- **Write:** in the footer Text element's `text_content`, use `© {{dc:global:date format="Y"}} {{dc:global:site_title}}`. With Twig: `© {{ "now"|date("Y", date_i18n("e")) }}`. A founding-year range keeps its start year: `© 2015–{{dc:global:date format="Y"}}`.
- **Verify:** render_preview shows this year. validate_layout reports `hardcoded-date` for a typed year beside © or "Copyright".

### A promo that switches on a date
- **Need:** "Book before November 1 and save 15%" until that date, then the regular copy, with no edit on the day.
- **Native:** Global Parameters hold the date and the numbers, and show conditions switch the copy.
- **Write:** set_global_parameters with `{"json": {"promo_end": "text|2026-11-01", "promo_pct": "text|15"}, "data": {"promo_end": "2026-11-01", "promo_pct": "15"}}` (the schema shorthand is `type|initial`). Then build two sibling elements. The promo element gets:
  ```json
  {"show_condition": [{"group": true, "condition": "global:today", "value": "{{dc:global:promo_end}}", "toggle": true}]}
  ```
  The regular element gets the same rule with `"toggle": false`. `global:today` compares the site's local time with the value: `true` means before, `false` means on or after. It switches at midnight, site time.
  To switch one string instead, use Twig on the same parameters. Compare the dates as text, and format the stored date with no timezone:
  ```twig
  {% set today = "now"|date("Y-m-d", date_i18n("e")) %}{{ today < global.promo_end ? "Book before " ~ global.promo_end|date("F j") ~ " and save " ~ global.promo_pct ~ "%" : "Book your stay" }}
  ```
- **Verify:** render_preview the pair twice, once with the rule's value set to yesterday and once to tomorrow. Exactly one element renders each time. validate_layout reports `hardcoded-date` for promo copy with a typed date ("until 12/31").

### Opening hours by season and weekday
- **Need:** summer and winter hours, with weekend hours, that switch by themselves.
- **Native:** Twig `date()` and PHP relative dates in the site timezone, with the hours in Global Parameters.
- **Write:** in `text_content`:
  ```twig
  {% set tz = date_i18n("e") %}{% set clock = date("now", tz) %}{% set today = clock|date("Y-m-d", tz) %}
  {% set summer = today >= clock|date_modify("last monday of may")|date("Y-m-d", tz) and today < clock|date_modify("first monday of september")|date("Y-m-d", tz) %}
  {% set weekend = clock|date("N", tz) >= 6 %}
  {{ summer ? (weekend ? global.hours_summer_weekend : global.hours_summer) : (weekend ? global.hours_winter_weekend : global.hours_winter) }}
  ```
  Don't write `"last monday of may"|date("Y-m-d", tz)`. It parses at midnight UTC and lands on the day before west of UTC.
- **Verify:** render_preview `{{ "now"|date("Y-m-d H:i", date_i18n("e")) }}` next to `{{dc:global:date format="Y-m-d H:i"}}` in one preview. They must match; that is the timezone check. To test a season, preview a copy with `clock` set to `date("now", tz)|date_modify("2026-07-04 12:00")`.

## Values, logic and visibility

### Price arithmetic
- **Need:** show a discounted price computed from values the client edits.
- **Native:** Twig on Global Parameters, or on component parameters (`param.<name>`).
- **Write:** `${{ (global.price * (100 - global.promo_pct) / 100)|number_format(0) }}` gives "102" for 120 at 15%. For cents, use `number_format(2, ".", ",")`. Inside a component, use `param.price` and `param.pct`.
- **Verify:** render_preview shows the computed figure. If it shows 0, a parameter name is wrong: a missing parameter reads as empty.

### Thank-you message on ?sent=1
- **Need:** after a form redirects to `/contact/?sent=1`, show a thank-you and hide the form.
- **Native:** an expression show condition on the URL parameter.
- **Write:** give the thank-you element this rule:
  ```json
  {"show_condition": [{"group": true, "condition": "expression:string", "operand": "{{dc:url:param key='sent'}}", "operator": "is", "value": "1"}]}
  ```
  The form gets the same rule with `"operator": "is-not"`. `{{dc:url:param}}` reads visitor input, so compare it and don't print it. validate_layout reports `token-untrusted` for it, which is expected when the token only feeds a condition.
- **Verify:** render_preview can't set a query string. Preview a copy with the operand `"1"` (the element shows) and `""` (it hides), then load the saved page with `?sent=1`.

## Navigation and header

### Current navigation item
- **Need:** highlight the page the visitor is on.
- **Native:** a Navigation element on a WordPress menu, which marks the current item itself.
- **Write:** use `nav-inline`, `nav-dropdown`, `nav-collapsed` or `nav-layered` with `"menu": "menu:<term_id>"` (list_menus has the IDs). `menu_active_links_highlight_current` and `menu_active_links_highlight_ancestors` are on by default. They give the current link its interaction (`_alt`) styling, so set the link's `_alt` colours to palette references. Find them with get_element_schema `search: "alt"`. Don't target `.current-menu-item` in CSS, and don't build a nav from Buttons, because they can't tell which page is current.
- **Verify:** render_preview with `for_post` set to a page in the menu. That page's link carries `x-always-active`.

### One sticky bar in a multi-bar header
- **Need:** a utility bar that scrolls away above a main bar that sticks.
- **Native:** the Bar element's sticky settings, set per bar.
- **Write:** in the header's `top` region, set `"bar_sticky": true` on the main bar only; the other bars stay `false`. Sticky only works in the top region. Options: `bar_sticky_hide_initially` (the bar appears after scrolling), `bar_sticky_only_show_on_scroll_up`, `bar_sticky_trigger_offset`, `bar_sticky_shrink` (for example "0.75"), `bar_sticky_keep_margin`, `bar_sticky_z_stack`.
- **Verify:** render_preview the header document. Only the sticky bar has the class `x-bar-is-sticky`, and its `data-x-bar` JSON carries the options.

### Off-canvas or collapsed mobile navigation
- **Need:** a burger menu on phones and an inline menu on desktops.
- **Native:** an Off Canvas element holding a Navigation Layered (drill-down) or Navigation Collapsed (expanding) element, plus hiding by breakpoint.
- **Write:** in a header bar, place a `nav-inline` with `"hide_bp": "xs sm"` and a `layout-off-canvas` with `"hide_bp": "md lg xl"`. Inside the off-canvas, place a `nav-layered` or `nav-collapsed` on the same `menu`. Set `off_canvas_location` ("left" or "right") and `toggle_type` (for example "burger-1"). The tags `xs sm md lg xl` apply to Pro's default five breakpoints; get_site_info lists the site's breakpoints.
- **Verify:** render_preview the bar. The inline nav carries `x-hide-xs x-hide-sm` and the off-canvas carries the other three.

## Behaviour

### Scroll reveal
- **Need:** content that fades or slides in as it enters the viewport.
- **Native:** the Scroll effect, which most elements have (get_element_schema `search: "scroll"`).
- **Write:**
  ```json
  {"effects_scroll": true, "effects_type_scroll": "transform", "effects_opacity_exit": "0", "effects_transform_exit": "translate(0px, 1rem)", "effects_opacity_enter": "1", "effects_transform_enter": "translate(0px, 0px)", "effects_behavior_scroll": "fire-once", "effects_duration_scroll": "600ms", "effects_delay_scroll": "0ms", "effects_offset_top": "10%", "effects_offset_bottom": "10%"}
  ```
  To stagger siblings, increase `effects_delay_scroll` on each. For a keyframe animation, set `effects_type_scroll` to "animation" and look up its keys with get_element_schema.
- **Verify:** render_preview. The element has the class `x-effect-exit` and a `data-x-effect` attribute containing `"scroll":true`.

### Read more
- **Need:** a teaser whose full text opens on click.
- **Native:** an Accordion.
- **Write:** an `accordion` with one `accordion-item`. Set `accordion_item_header_content` to "Read more", put the rest of the text in `accordion_item_content`, and set `accordion_item_starts_open: false`. For rich content, use `accordion-item-elements`. For a FAQ, `accordion_faq_schema: true` outputs FAQPage JSON-LD natively.
- **Verify:** render_preview shows the header and the collapsed panel. The FAQ schema is printed in the page footer, so check it in the live page source.

### Filtering
- **Need:** let visitors narrow a list by category or field.
- **Native:** Tabs for a few fixed groups, or a Cornerstone Forms form that drives a looper for real filters.
- **Write:**
  - **Fixed groups:** a `tabs` element with one `tab-elements` per group (label in `tab_label_content`), each holding a looper for that group. Find the term keys with get_element_schema `search: "term"`.
  - **Facets:** a `cornerstone-form` with `"form_method": "GET"`, `"form_data-cs-ajax": true` and `"form_data-cs-ajax-auto-submit": true`. Name the results container in `form_data-cs-ajax-selectors`; check its stored shape with get_element_schema `search: "selectors"`. Add inputs (`cornerstone-form-select`, `cornerstone-form-checkbox-list`), each with a `name`. The results looper reads them with `{{dc:url:param key='<name>'}}`, for example in `looper_provider_query_string`. The values are visitor input, so use them only where any value is harmless, such as a taxonomy slug or a search term.
- **Verify:** render_preview the looper with the token replaced by a literal value. The results should change, and `empty: true` means nothing matched.

## Assets

### Self-hosted fonts
- **Need:** brand fonts served from the site, with no `@font-face` in custom code.
- **Native:** Cornerstone's custom fonts, which write the `@font-face` rules themselves, and a global font that points at one.
- **Write:** first upload_media the `.woff2` files. Then run set_fonts with `dry_run: true`:
  ```json
  {"config": {"customFontItems": [{"_id": "brand-sans", "family": "Brand Sans", "stack": "\"Brand Sans\"", "fallback": "sans-serif", "files": [{"weight": "400", "style": "normal", "filename": "brand-sans-400.woff2", "url": "/wp-content/uploads/2026/09/brand-sans-400.woff2", "id": 123}, {"weight": "700", "style": "normal", "filename": "brand-sans-700.woff2", "url": "/wp-content/uploads/2026/09/brand-sans-700.woff2", "id": 124}]}]}, "fonts": [{"_id": "body", "title": "Body", "family": "Brand Sans", "source": "custom"}], "dry_run": true}
  ```
  Cornerstone prints the custom item's `stack` as the `@font-face` family, so it holds the one quoted family. Other families go in `fallback`, which Cornerstone adds after the stack for elements. The global font `body` points at the custom item by `family`.
  Reference the global font by its `_id` on its own, and the weight on its own. An element gets `{"text_font_family": "body", "text_font_weight": "fw-normal"}`. Theme Options get update_theme_options `{"options": {"x_body_font_family_selection": "body", "x_body_font_weight_selection": "fw-normal"}}`.
  **Variable fonts:** give the one file a weight range, `{"weight": "100 900"}`, which Cornerstone prints in `@font-face` as it is. List the same file again under `"400"` and `"700"`, because Cornerstone matches `"fw-normal"`, `"fw-bold"` and numeric weights against the listed weights and reads a range as its lower end. Cornerstone writes no font-stretch descriptor, and it stores `customFontFaceCSS` without printing it. For a width axis, add an `@font-face` with a font-stretch range in Global CSS (set_global_css), then set font-stretch in the element's `css` (`$el { font-stretch: 75%; }`) or a Global CSS rule.
- **Verify:** get_native_reference section "fonts" lists `body` with the stack `"Brand Sans", sans-serif`, and the custom item with the `font_face_family` `"Brand Sans"`. validate_layout reports `literal-font-family` where a stack was typed, `font-ref-prefix` for a family or weight written with a prefix, and `font-weight-shape` for a weight with the family joined to it.

### Background textures
- **Need:** a paper or grain texture behind sections.
- **Native:** a background layer, or a Global Variable for a texture used across the site.
- **Write:**
  - **One element:** `{"section_bg_advanced": true, "bg_lower_type": "image", "bg_lower_image": "<attachment_id>:full", "bg_lower_image_repeat": "repeat"}` (`bg_lower_image_size` defaults to cover). The switch is `<prefix>_bg_advanced` for each element (`layout_div_bg_advanced` on a Div), and validate_layout reports `background-layers-off` when it is missing.
  - **Site-wide:** set_variables `{"variables": {"texture-paper": "url(/wp-content/uploads/2026/09/paper.webp)"}}`. The URL is root-relative, so it works on staging and live. Use `var(--texture-paper)` where a setting takes a background image (get_element_schema `search: "image"`), or in the element's css as `$el { background-image: var(--texture-paper); }` when no setting takes one.
- **Verify:** render_preview shows the `x-bg` layer markup. The image itself is in the generated CSS, so look at the live page as well.

### Repeating data
- **Need:** lists the client maintains, such as hours, team members or price rows.
- **Native:** a Global Parameter list (`group[]`), a JSON or CSV looper, or a Table whose rows loop.
- **Write:**
  - **Global list:** set_global_parameters `{"json": {"hours": {"type": "group[]", "params": {"day": "text|", "open": "text|"}}}, "data": {"hours": [{"day": "Mon–Fri", "open": "8–6"}, {"day": "Sat", "open": "9–4"}]}}`. The provider element gets `{"looper_provider": true, "looper_provider_type": "dc", "looper_provider_dc": "{{dc:global:hours}}"}`, and its child consumer gets `{"looper_consumer": true}` with `{{dc:looper:field key='day'}}` in its text.
  - **JSON:** `{"looper_provider": true, "looper_provider_type": "json", "looper_provider_json": "[{\"day\":\"Mon\"}]"}`.
  - **CSV:** `{"looper_provider": true, "looper_provider_type": "csv", "looper_provider_csv_type": "content", "looper_provider_csv_content": "day,open\nMon,8-6"}`. This needs `cs_csv_enabled`. upload_media does not take `.csv`.
  - **Table:** `layout-table` › `layout-table-section` (provider) › `layout-table-row` (consumer) › `layout-table-cell`. If the site has a Data Tables extension, list_elements shows its element.
- **Verify:** render_preview shows one row per item. `empty: true` means the provider returned nothing.

### Structured data
- **Need:** schema.org markup.
- **Native:** the SEO plugin's settings for site, organization, breadcrumb and article schema. The Accordion's `accordion_faq_schema` covers FAQs. For anything else, such as a LocalBusiness with opening hours, one Raw Content element in the footer that reads Dynamic Content.
- **Write:**
  ```html
  <script type="application/ld+json">{"@context": "https://schema.org", "@type": "LocalBusiness", "name": {{dc:global:site_title type='json'}}, "url": {{dc:global:home_url type='json'}}, "telephone": {{dc:global:phone type='json'}}}</script>
  ```
  Put this in `raw_content` of a `raw-content` element. `type='json'` turns each token into a quoted, escaped JSON string. Writing Raw Content needs `unfiltered_html`. Add a show condition when the markup belongs to one page only.
- **Verify:** render_preview the element and parse the text between the script tags as JSON.

### The few script lines left
- **Need:** a snippet nothing above covers, such as an analytics tag or a consent hook.
- **Native:** set_global_js, which writes named blocks into Cornerstone's Global JS. For a script that must run before first paint, use a Raw Content element at the top of the header's first bar.
- **Write:** set_global_js with a named block and `dry_run: true` first. In a Raw Content element, set `raw_content` to the `<script>` and `disable_preview: true` so it doesn't run in the builder. A script tag never goes in Text, Headline or `custom_atts`.
- **Verify:** set_global_js `dry_run` shows the block. render_preview of the Raw Content element returns the tag unchanged.
MD;

    public function uri(): string
    {
        return self::URI;
    }

    public function name(): string
    {
        return 'Native Recipes';
    }

    public function description(): string
    {
        return 'Worked recipes for common site features built only from Cornerstone features — Dynamic Content, Twig, Global Parameters, show conditions, loopers, native elements and effects — with the keys, tokens and Twig to write and how to verify each with render_preview.';
    }

    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function read(): mixed
    {
        return self::GUIDE;
    }
}
