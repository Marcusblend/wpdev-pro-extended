<?php

declare(strict_types=1);

use ProExtended\Cornerstone\TwigCatalog;
use ProExtended\Cornerstone\TwigSyntax;
use ProExtended\Elements\ElementLint;
use ProExtended\Elements\LintContext;
use ProExtended\Settings\ThemeOptionsWriter;
use ProExtended\Settings\TwigTemplates;

T::group('TwigSyntax');

T::ok(! TwigSyntax::containsTwig('Hello {{dc:post:title}}'), 'a Dynamic Content token is not Twig');
T::ok(! TwigSyntax::containsTwig('{{dc:looper:field key="{{dc:p:image_key}}"}}'), 'nor are nested tokens');
T::ok(TwigSyntax::containsTwig('© {{ "now"|date("Y") }}'), 'an output tag is Twig');
T::ok(TwigSyntax::containsTwig('{% if x %}a{% endif %}'), 'a block tag is Twig');
T::ok(TwigSyntax::containsTwig('{# note #}'), 'a comment is Twig');
T::ok(TwigSyntax::containsTwig('{{post.title}}'), 'an output tag without spaces is Twig too');
T::ok(TwigSyntax::containsTwig('{% if {{dc:post:id}} > 5 %}x{% endif %}'), 'Twig around a token is Twig');
T::ok(! TwigSyntax::containsTwig('.a { color: red; } @media (min-width: 1px) { .b { x: y } }'), 'CSS braces are not Twig');
T::ok(! TwigSyntax::containsTwig('Plain text {# not closed'), 'an unclosed comment opener alone is not Twig');
T::same('{% if 0 > 5 %}x{% endif %}', TwigSyntax::withoutTokens('{% if {{dc:post:id}} > 5 %}x{% endif %}'), 'tokens become a neutral placeholder before parsing');
T::same('a 0 b', TwigSyntax::withoutTokens('a {{dc:looper:field key="{{dc:p:k}}"}} b'), 'a nested token is one placeholder');
T::same('x {{dc:post:title', TwigSyntax::withoutTokens('x {{dc:post:title'), 'an unclosed token is left for the token lint');
T::same('{{ "now"|date("Y") }}', TwigSyntax::excerpt('© {{ "now"|date("Y") }}'), 'the excerpt starts at the Twig');

T::group('ElementLint Twig');

$el = static fn (array $extra): array => ['_type' => 'text', '_m' => ['e' => 1], '_bp_base' => '4_4'] + $extra;
$codes = static function (array $issues): array {
    $list = array_values(array_unique(array_column($issues, 'code')));
    sort($list);

    return $list;
};

$off = new ElementLint(new LintContext(['text' => 1], '4_4', [], [], null, null, null, null, false, null));
$seen = [];

$issues = $off->tree([$el(['text_content' => '© {{ "now"|date("Y") }} Company'])]);
T::same(['twig-off'], $codes($issues), 'Twig while Twig is off is reported');
T::same('0', $issues[0]['path'], 'at the element path');
T::ok(str_contains($issues[0]['message'], 'text_content'), 'naming the key');
T::ok(str_contains($issues[0]['message'], 'cs_twig_enabled'), 'and how to switch Twig on');
$seen['twig-off'] = true;

T::same(['twig-off'], $codes($off->tree([['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', '_modules' => [$el(['text_content' => '{% if true %}x{% endif %}'])]]])), 'nested elements are checked');
T::same('0._modules.0', $off->tree([['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', '_modules' => [$el(['text_content' => '{% if true %}x{% endif %}'])]]])[0]['path'], 'with their nested path');
T::same(['twig-off'], $codes($off->flat(['e1' => $el(['text_content' => '{{ post.title }}'])])), 'component element maps are checked');
T::same([], $codes($off->tree([$el(['text_content' => 'Hello {{dc:post:title}}'])])), 'a token alone is not Twig, even with Twig off');
T::same([], $codes($off->tree([$el(['text_content' => 'no braces at all'])])), 'plain text is quiet');

$unknown = new ElementLint(new LintContext(['text' => 1], '4_4'));
T::same([], $codes($unknown->tree([$el(['text_content' => '{% if %}'])])), 'with Twig\'s state unknown, nothing is reported');

$calls = [];
$parser = static function (string $template) use (&$calls): ?string {
    $calls[] = $template;

    return str_contains($template, '{% if %}') ? 'An expression is expected (line 1)' : null;
};
$on = new ElementLint(new LintContext(['text' => 1], '4_4', [], [], null, null, null, null, true, $parser));

$bad = $on->tree([$el(['text_content' => 'Hi {% if %}x{% endif %}'])]);
T::same(['twig-syntax'], $codes($bad), 'Twig that does not parse is reported');
T::ok(str_contains($bad[0]['message'], 'An expression is expected (line 1)'), 'with Twig\'s own message');
T::ok(str_contains($bad[0]['message'], 'text_content'), 'and the key');
$seen['twig-syntax'] = true;

$calls = [];
T::same([], $codes($on->tree([$el(['text_content' => '{{ post.title }} {{dc:post:id}}', 'text_tag' => 'p'])])), 'Twig that parses is quiet');
T::same(['{{ post.title }} 0'], $calls, 'only the Twig string is parsed, tokens set aside');

$noParser = new ElementLint(new LintContext(['text' => 1], '4_4', [], [], null, null, null, null, true, null));
T::same([], $codes($noParser->tree([$el(['text_content' => '{% if %}'])])), 'without an environment twig-syntax is skipped');

T::same([], array_values(array_diff(array_keys(ElementLint::TWIG_CODES), array_keys($seen))), 'every Twig code has a fixture');
T::ok(isset(ElementLint::codes()['twig-syntax'], ElementLint::codes()['missing-breakpoint-base']), 'codes() lists the Twig codes with the rest');

// A real parse, when a Twig autoloader is supplied (Cornerstone ships one:
// PE_TWIG_AUTOLOAD=.../integration/Twig/vendor/autoload.php).
$autoload = getenv('PE_TWIG_AUTOLOAD');

if (is_string($autoload) && $autoload !== '' && is_file($autoload)) {
    require_once $autoload;

    $environment = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
    $real = TwigCatalog::parserFor($environment);

    T::ok($real !== null, 'a real environment gives a parser');
    T::same(null, $real('{{ "now"|date("Y") }}'), 'valid Twig parses');
    T::ok(is_string($real('{% if %}x{% endif %}')), 'broken Twig returns its message');
    T::ok(str_contains((string) $real('{{ x|nosuchfilter }}'), 'nosuchfilter'), 'an unknown filter is caught at parse time');
    T::same(null, $real("{% include 'cs-template:footer' %}"), 'an include is parsed without loading the template');
}

T::group('Twig theme options');

$registered = ['cs_twig_enabled', 'cs_twig_extension_advanced', 'cs_twig_extension_wordpress', 'cs_twig_templates'];

T::same([], ThemeOptionsWriter::plan(['cs_twig_enabled' => true, 'cs_twig_extension_wordpress' => true], $registered, [])['errors'], 'Twig and its sub-toggles are writable');
$advanced = ThemeOptionsWriter::plan(['cs_twig_extension_advanced' => true], $registered, []);
T::same(1, count($advanced['errors']), 'the Advanced sub-toggle is refused');
T::ok(str_contains($advanced['errors'][0], 'PHP'), 'with the reason');

$good = [['id' => 'footer-year', 'title' => 'Footer year', 'template' => '{{ "now"|date("Y") }}']];
T::same([], ThemeOptionsWriter::plan(['cs_twig_templates' => $good], $registered, [])['errors'], 'a valid template list is writable');
T::same(1, count(ThemeOptionsWriter::plan(['cs_twig_templates' => $good], $registered, [])['writes']), 'and planned');
$malformed = ThemeOptionsWriter::plan(['cs_twig_templates' => [['id' => 'x', 'content' => 'y']]], $registered, []);
T::ok($malformed['errors'] !== [], 'a malformed template list is refused');
T::same([], $malformed['writes'], 'and plans no write');

T::group('TwigTemplates');

T::same([], TwigTemplates::errors([]), 'an empty list is fine');
T::same([], TwigTemplates::errors($good), 'id, title and template are fine');
T::same([], TwigTemplates::errors([['id' => 'a', 'template' => 'x', '_id' => 'builder']]), 'builder bookkeeping keys are left alone');
T::ok((bool) array_filter(TwigTemplates::errors([['id' => 'a', 'content' => 'x']]), static fn (string $e): bool => str_contains($e, 'stored under "template"')), 'content is pointed at template');
T::ok(TwigTemplates::errors([['id' => 'a', 'template' => 'x'], ['id' => 'a', 'template' => 'y']]) !== [], 'a duplicate id is refused');
T::ok(TwigTemplates::errors([['id' => 'has space', 'template' => 'x']]) !== [], 'an id with a space is refused');
T::ok(TwigTemplates::errors([['template' => 'x']]) !== [], 'a missing id is refused');
T::ok(TwigTemplates::errors([['id' => 'a']]) !== [], 'a missing template is refused');
T::ok(TwigTemplates::errors(['a' => ['id' => 'a', 'template' => 'x']]) !== [], 'a keyed map is refused');
T::ok(TwigTemplates::errors('not a list') !== [], 'a string is refused');
T::ok(TwigTemplates::errors([['id' => 'a', 'template' => '<?php echo 1; ?>']]) !== [], 'PHP tags are refused');

$summary = TwigTemplates::summarize([['id' => 'footer-year', 'title' => 'Year', 'template' => "{% set y = 1 %}\n{{ y }}"]]);
T::same("{% include 'cs-template:footer-year' %}", $summary[0]['include'], 'the summary gives the include');
T::same(2, $summary[0]['lines'], 'and the size in lines');
T::same(strlen("{% set y = 1 %}\n{{ y }}"), $summary[0]['bytes'], 'and bytes');
