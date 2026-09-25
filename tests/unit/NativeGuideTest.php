<?php

declare(strict_types=1);

use ProExtended\Elements\ElementLint;
use ProExtended\Mcp\Resources\NativeGuideResource;
use ProExtended\Mcp\Server;

T::group('Native guide');

$resource = new NativeGuideResource();
$guide = (string) $resource->read();

T::same('pe://guide/native', $resource->uri(), 'the resource lives at pe://guide/native');
T::same('text/markdown', $resource->mimeType(), 'and is markdown');
T::ok(str_contains((string) (new ReflectionClassConstant(Server::class, 'INSTRUCTIONS'))->getValue(), $resource->uri()), 'the handshake points at it');

// Every recipe the 1.5 brief asks for, each with its four parts.
$recipes = [
    'Copyright year', 'A promo that switches on a date', 'Opening hours by season and weekday', 'Price arithmetic',
    'Thank-you message on ?sent=1', 'Current navigation item', 'One sticky bar in a multi-bar header',
    'Off-canvas or collapsed mobile navigation', 'Scroll reveal', 'Read more', 'Filtering', 'Self-hosted fonts',
    'Background textures', 'Repeating data', 'Structured data', 'The few script lines left',
];

$sections = preg_split('/^### /m', $guide);
array_shift($sections);
$byTitle = [];

foreach ($sections as $section) {
    [$title] = explode("\n", $section, 2);
    $byTitle[trim($title)] = $section;
}

foreach ($recipes as $title) {
    $section = $byTitle[$title] ?? null;
    T::ok($section !== null, "recipe: {$title}");

    if ($section !== null) {
        $missing = array_filter(['**Need:**', '**Native:**', '**Write:**', '**Verify:**'], static fn(string $part): bool => ! str_contains($section, $part));
        T::same([], array_values($missing), "{$title} has need, native feature, write and verify");
        T::ok(str_contains($section, 'render_preview') || str_contains($section, 'list_fonts') || str_contains($section, 'dry_run'), "{$title} says how to check it");
    }
}

T::same(count($recipes), count($byTitle), 'no recipe outside the list');

$lines = substr_count($guide, "\n") + 1;
T::ok($lines <= 400, 'the guide stays tight', (string) $lines);

// Everything the guide cites must exist in Cornerstone 7.9.4. The fixtures
// were generated from its source; see the header line of each file.
$fixture = static function (string $name): array {
    $rows = [];

    foreach (file(dirname(__DIR__) . '/fixtures/cornerstone-7.9.4/' . $name, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $rows[] = preg_split('/\s+/', $line, 2)[0];
    }

    return $rows;
};

$elementTypes = $fixture('element-types.txt');
$conditionRules = $fixture('condition-rules.txt');
$dcFields = $fixture('dc-fields.txt');
$twig = array_merge($fixture('twig-core.txt'), $fixture('twig-cornerstone.txt'));
$settingKeys = $fixture('setting-keys.txt');

// Element types, lint codes, and the classes and attributes a preview shows.
preg_match_all('/`([a-z][a-z0-9]*(?:-[a-z0-9]+)+)`/', $guide, $matches);
$unknown = [];

foreach (array_unique($matches[1]) as $name) {
    if (str_starts_with($name, 'x-') || str_starts_with($name, 'data-x-')) {
        continue; // Classes and attributes Cornerstone prints, named for the verify steps.
    }

    if (! in_array($name, $elementTypes, true) && ! array_key_exists($name, ElementLint::CODES)) {
        $unknown[] = $name;
    }
}

T::same([], $unknown, 'every element type and warning code the guide names exists');

// Show condition rules resolve the way RuleMatching resolves them.
preg_match_all('/"condition": "([a-z:-]+)"/', $guide, $conditions);
preg_match_all('/"operator": "([a-z-]+)"/', $guide, $operators);
$unresolved = [];

foreach (array_unique($conditions[1]) as $condition) {
    $base = str_replace([':', '-'], '_', $condition);

    if (in_array($base, $conditionRules, true)) {
        continue;
    }

    $withOperator = array_filter($operators[1], static fn(string $op): bool => in_array($base . '_' . str_replace('-', '_', $op), $conditionRules, true));

    if ($withOperator === []) {
        $unresolved[] = $condition;
    }
}

foreach (array_unique($operators[1]) as $operator) {
    if (! in_array('expression_string_' . str_replace('-', '_', $operator), $conditionRules, true)) {
        $unresolved[] = 'operator ' . $operator;
    }
}

T::same([], $unresolved, 'every condition and operator the guide uses is a ConditionRules method');

// Dynamic Content tokens. A field the global group does not register falls
// through to the Global Parameters (Cornerstone_Dynamic_Content_Global's
// default case), which is how {{dc:global:promo_end}} reads a parameter.
preg_match_all('/\{\{dc:([a-z_]+):([a-z_]+)/', $guide, $tokens, PREG_SET_ORDER);
$unregistered = [];

foreach ($tokens as [, $group, $field]) {
    if (! in_array("{$group}:{$field}", $dcFields, true) && $group !== 'global') {
        $unregistered[] = "{$group}:{$field}";
    }
}

T::same([], array_values(array_unique($unregistered)), 'every Dynamic Content token the guide uses is registered');
T::ok(in_array('global:date', $dcFields, true) && in_array('url:param', $dcFields, true) && in_array('looper:field', $dcFields, true), 'the tokens the recipes lean on are in the fixture');

// Twig: every filter and function in the snippets exists on a default Twig
// setup (core, Cornerstone's built-ins and the WordPress extension).
$snippets = [];
preg_match_all('/```twig\n(.*?)```/s', $guide, $blocks);
$snippets = array_merge($snippets, $blocks[1]);
preg_match_all('/`([^`\n]*(?:\{\{ |\{%)[^`\n]*)`/', $guide, $inline);
$snippets = array_merge($snippets, $inline[1]);
$missingTwig = [];

foreach ($snippets as $snippet) {
    $code = (string) preg_replace('/"[^"]*"/', '""', $snippet);

    preg_match_all('/\|\s*([a-z_][a-z0-9_]*)/', $code, $filters);
    preg_match_all('/(?<![\w.|])([a-z_][a-z0-9_]*)\s*\(/', $code, $functions);

    foreach ($filters[1] as $filter) {
        if (! in_array('filter:' . $filter, $twig, true)) {
            $missingTwig[] = 'filter ' . $filter;
        }
    }

    foreach ($functions[1] as $function) {
        if (! in_array('function:' . $function, $twig, true)) {
            $missingTwig[] = 'function ' . $function;
        }
    }
}

T::ok(count($snippets) >= 5, 'the Twig snippets were found', (string) count($snippets));
T::same([], array_values(array_unique($missingTwig)), 'every Twig filter and function the guide uses exists');
T::ok(! str_contains($guide, 'get_option('), 'no recipe relies on the Advanced Twig extension');

// Element keys in the JSON the guide writes were each confirmed in source.
// Tool arguments and example names are listed here rather than in the fixture.
$notElementKeys = [
    'json', 'data', 'config', 'customFontItems', '_id', 'family', 'stack', 'fallback', 'files', 'weight', 'style', 'filename',
    'url', 'id', 'fonts', 'title', 'source', 'dry_run', 'variables', 'type', 'params', 'options',
    'promo_end', 'promo_pct', 'hours', 'day', 'open', 'texture-paper', 'telephone', 'scroll',
];
$themeFontKeys = $fixture('font-theme-options.txt');
preg_match_all('/"([A-Za-z_][A-Za-z0-9_-]*)"\s*:/', $guide, $jsonKeys);
$unconfirmed = [];

foreach (array_unique($jsonKeys[1]) as $key) {
    if (! in_array($key, $settingKeys, true) && ! in_array($key, $notElementKeys, true) && ! in_array($key, $themeFontKeys, true)) {
        $unconfirmed[] = $key;
    }
}

T::same([], $unconfirmed, 'every element key in the guide\'s JSON is confirmed in the fixture');

foreach (['accordion_item_starts_open', 'form_data-cs-ajax-selectors', 'bg_lower_image_size', 'looper_provider_query_string', 'menu_active_links_highlight_current', 'disable_preview', 'raw_content'] as $key) {
    T::ok(str_contains($guide, $key) && in_array($key, $settingKeys, true), "{$key} is cited and confirmed");
}

// Generic: no client, no site.
T::ok(! preg_match('/https?:\/\/(?!schema\.org)/', $guide), 'the guide names no site');

// Fonts (1.5.1): a family is the font's bare _id and a weight "fw-normal" or
// "fw-bold". The old forms render the fallback font and inherit, so they
// must never come back into the guide or the handshake.
$handshake = (string) (new ReflectionClassConstant(Server::class, 'INSTRUCTIONS'))->getValue();

foreach (['global-ff:', 'global-fw:'] as $wrong) {
    T::ok(! str_contains($guide, $wrong), "the guide never writes {$wrong}");
    T::ok(! str_contains($handshake, $wrong), "the handshake never writes {$wrong}");
}

T::ok(! preg_match('/"[A-Za-z][\w-]*\|fw-(?:normal|bold)"/', $guide . $handshake), 'nor a weight with the family joined to it');
T::ok(str_contains($handshake, '"fw-normal" or "fw-bold"') && str_contains($handshake, '"text_font_family": "body"'), 'the handshake gives the bare _id and fw-normal/fw-bold forms');

$fontRecipe = $byTitle['Self-hosted fonts'] ?? '';
preg_match('/```json\n(.*?)```/s', $fontRecipe, $block);
$call = json_decode(trim($block[1] ?? ''), true);
T::ok(is_array($call), 'the self-hosted fonts call is valid JSON');

$fontErrors = [];
$fontConfig = \ProExtended\Settings\FontItems::mergeConfig([], (array) ($call['config'] ?? []), $fontErrors);
T::same([], $fontErrors, 'its customFontItems pass set_fonts\' checks');
T::same([], $fontConfig['normalized'], 'with a stack that needs no splitting');
T::same([], $fontConfig['warnings'], 'and no warnings');
$recipeItem = $fontConfig['config']['customFontItems'][0] ?? [];
T::same(1, count(\ProExtended\Settings\FontItems::splitFontList((string) ($recipeItem['stack'] ?? ''))), 'the custom item\'s stack is one family');
T::ok(($recipeItem['fallback'] ?? '') !== '', 'and the fallback is its own key');

$recipeFont = (array) ($call['fonts'][0] ?? []);
preg_match('/\{"text_font_family": "([^"]+)", "text_font_weight": "([^"]+)"\}/', $fontRecipe, $elementRef);
T::same([$recipeFont['_id'] ?? null, 'fw-normal'], [$elementRef[1] ?? null, $elementRef[2] ?? null], 'the element reference is the global font\'s _id and fw-normal');

$recipeLint = new ElementLint(new \ProExtended\Elements\LintContext(
    styleKeys: static fn (string $type): array => ['text_font_family' => 'font-family', 'text_font_weight' => 'font-weight'],
    fontIds: static fn (): array => [(string) ($recipeFont['_id'] ?? '')],
));
T::same([], array_column($recipeLint->tree([['_type' => 'text', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_font_family' => $elementRef[1] ?? '', 'text_font_weight' => $elementRef[2] ?? '']]), 'code'), 'and validate_layout has nothing to say about it');

preg_match('/update_theme_options `(\{.*?\})`/', $fontRecipe, $themeCall);
$themeOptions = (array) (json_decode($themeCall[1] ?? '', true)['options'] ?? []);
$themePlan = \ProExtended\Settings\ThemeOptionsWriter::plan($themeOptions, $themeFontKeys, [], [(string) ($recipeFont['_id'] ?? '')]);
T::same(['x_body_font_family_selection', 'x_body_font_weight_selection'], array_keys($themeOptions), 'the Theme Options example sets the body family and weight');
T::same([], $themePlan['errors'], 'and update_theme_options accepts it');
T::same([], $themePlan['normalized'], 'as written');

foreach (['font-ref-prefix', 'font-weight-shape', 'literal-font-family'] as $code) {
    T::ok(str_contains($fontRecipe, '`' . $code . '`'), "the fonts recipe names {$code}");
}

