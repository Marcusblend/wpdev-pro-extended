<?php

declare(strict_types=1);

use ProExtended\Cornerstone\ConditionCatalog;
use ProExtended\Cornerstone\LooperCatalog;
use ProExtended\Cornerstone\ParameterTypeCatalog;
use ProExtended\Cornerstone\RegionCatalog;
use ProExtended\Cornerstone\TwigCatalog;
use ProExtended\Mcp\Tools\ListDynamicContent;

// Fixtures follow the shapes Cornerstone 7.9.4 builds (Conditionals,
// looper-provider partial, ManagedParameters, Document regions).

T::group('ConditionCatalog');

$contexts = [
    'labels'   => ['single' => 'Single', 'expression' => 'Expression', 'global' => 'Global'],
    'controls' => [
        'single' => [
            ['key' => 'single:singular-all', 'label' => 'All Singular'],
            ['key' => 'single:post-type', 'label' => 'Post Type', 'toggle' => ['type' => 'boolean'], 'criteria' => ['type' => 'select', 'choices' => [['value' => 'post', 'label' => 'Post'], ['value' => 'page', 'label' => 'Page']]]],
            ['key' => 'single:specific-post-of-type|page', 'label' => 'Page (Specific)', 'toggle' => ['type' => 'boolean'], 'criteria' => ['type' => 'select', 'choices' => 'posts:page']],
            null,
            ['label' => 'no key'],
        ],
        'expression' => [
            ['key' => 'expression:number', 'label' => 'Number', 'toggle' => ['type' => 'operator', 'values' => ['eq', 'not-eq', 'gt'], 'labels' => ['==', '!=', '>']], 'criteria' => ['type' => 'text']],
        ],
        'global' => [
            ['key' => 'global:user-loggedin', 'label' => 'Logged in', 'toggle' => ['type' => 'boolean', 'labels' => ['is logged in', 'is not logged in']], 'criteria' => ['type' => 'static']],
            ['key' => 'global:today', 'label' => 'Today', 'toggle' => ['type' => 'boolean', 'labels' => ['before', 'after']], 'criteria' => ['type' => 'date-picker']],
        ],
    ],
];

$shaped = ConditionCatalog::shape($contexts);
$byKey = array_column($shaped['rules'], null, 'condition');

T::same(6, count($shaped['rules']), 'every rule with a key becomes a row; malformed entries are skipped');
T::same([['context' => 'single', 'label' => 'Single', 'rules' => 3], ['context' => 'expression', 'label' => 'Expression', 'rules' => 1], ['context' => 'global', 'label' => 'Global', 'rules' => 2]], $shaped['contexts'], 'contexts are summarized with their labels and counts');
T::same('static', $byKey['single:singular-all']['value']['type'], 'a rule without criteria takes no value');
T::same('single_post_type', $byKey['single:post-type']['rule_name'], 'the rule name is what RuleMatching resolves');
T::same(['post', 'page'], array_column($byKey['single:post-type']['value']['choices'], 'value'), 'select choices are listed by value');
T::same('single:specific-post-of-type', $byKey['single:specific-post-of-type|page']['handler'], 'the handler is the part before "|"');
T::same(['page'], $byKey['single:specific-post-of-type|page']['arguments'], 'the key\'s arguments are listed');
T::same('posts:page', $byKey['single:specific-post-of-type|page']['value']['lookup'], 'a remote lookup is reported as such');
T::ok(str_contains($byKey['single:specific-post-of-type|page']['value']['note'], 'post ID'), 'and explained');
T::same('operator', $byKey['expression:number']['type'], 'expression rules are operator rules');
T::same(['eq', 'not-eq', 'gt'], $byKey['expression:number']['operators'], 'with their operators');
T::same('>', $byKey['expression:number']['operator_labels']['gt'], 'and the label of each');
T::same(['expression_number_eq', 'expression_number_not_eq', 'expression_number_gt'], $byKey['expression:number']['rule_names'], 'each operator resolves to its own rule');
T::same(['true' => 'is logged in', 'false' => 'is not logged in'], $byKey['global:user-loggedin']['toggle_labels'], 'toggle labels say what true and false mean');
T::same('date-picker', $byKey['global:today']['value']['type'], 'a date rule takes a date');

$many = ['labels' => [], 'controls' => ['x' => [['key' => 'x:y', 'criteria' => ['type' => 'select', 'choices' => array_map(static fn (int $i): array => ['value' => (string) $i, 'label' => 'L' . $i], range(1, 60))]]]]];
$manyRule = ConditionCatalog::shape($many)['rules'][0]['value'];
T::same(60, $manyRule['choice_count'], 'a long choice list keeps its count');
T::same(ConditionCatalog::MAX_CHOICES, count($manyRule['choices']), 'but is cut short');
T::ok($manyRule['truncated'], 'and says so');

$fingerprint = ConditionCatalog::fingerprint($shaped['rules']);
T::ok(in_array('single:specific-post-of-type', $fingerprint, true), 'the fingerprint uses handlers, not per-post-type keys');
T::ok(! in_array('single:specific-post-of-type|page', $fingerprint, true), 'so a new post type does not look like a new rule');
T::ok(in_array('expression:number[gt]', $fingerprint, true), 'operators are fingerprinted');
T::same('expression_string_not_in', ConditionCatalog::ruleName('expression:string', 'not-in'), 'ruleName matches RuleMatching::evaluate');
T::same('current_post_parent', ConditionCatalog::ruleName('current-post:parent|page'), 'ruleName drops the arguments');

T::group('LooperCatalog');

$choices = [
    ['value' => 'query-recent', 'label' => 'Recent Posts'],
    ['value' => 'query-builder', 'label' => 'Query Builder'],
    ['value' => 'taxonomy', 'label' => 'All Terms'],
    ['value' => 'json', 'label' => 'JSON'],
    ['value' => 'range', 'label' => 'Range'],
    ['value' => ''],
];
$controls = [
    ['key' => 'looper_provider_query_string', 'type' => 'text', 'conditions' => [['looper_provider_type' => 'query-string']]],
    [
        'keys' => ['types' => 'looper_provider_query_post_types', 'in' => 'looper_provider_query_post_in'],
        'type' => 'group-picker',
        'conditions' => [['looper_provider_type' => 'query-builder']],
        'controls' => [['key' => 'looper_provider_query_post_status', 'type' => 'select-many']],
    ],
    ['key' => 'looper_provider_query_count', 'type' => 'choose', 'conditions' => [['key' => 'looper_provider_type', 'op' => 'IN', 'value' => ['query-builder', 'query-recent']]]],
    ['key' => 'looper_provider_tax', 'type' => 'select', 'conditions' => [['looper_provider_type' => 'taxonomy']]],
    ['key' => 'looper_provider_json', 'type' => 'code-editor', 'conditions' => [['looper_provider_type' => 'json']]],
    ['key' => 'looper_provider_array_offset', 'type' => 'choose', 'conditions' => [['key' => 'looper_provider_type', 'op' => 'IN', 'value' => ['taxonomy', 'json']]]],
    ['key' => 'looper_provider_range_min', 'type' => 'text', 'conditions' => [['looper_provider_type' => 'range']]],
    ['key' => 'looper_provider_array_loop_keys', 'type' => 'toggle'],
];
$registered = ['range' => ['type' => 'range', 'label' => 'Range', 'values' => ['min' => 1, 'max' => 10, 'steps' => 1]]];
$items = ['query-recent' => 'post', 'query-builder' => 'post', 'taxonomy' => 'term', 'json' => 'array', 'range' => 'array-or-post'];
$fields = [
    ['token' => '{{dc:looper:item}}', 'group' => 'looper', 'field' => 'item', 'label' => 'Item', 'arguments' => [['name' => 'depth']]],
    ['token' => '{{dc:looper:field}}', 'group' => 'looper', 'field' => 'field', 'label' => 'Field', 'arguments' => [['name' => 'key'], ['name' => 'depth'], ['name' => 'fallback']]],
    ['token' => '{{dc:post:title}}', 'group' => 'post', 'field' => 'title', 'label' => 'Title'],
];
$consumer = [['key' => 'looper_consumer', 'type' => 'group', 'controls' => [['key' => 'looper_consumer_repeat'], ['key' => 'looper_consumer_rewind']]]];

$loopers = LooperCatalog::shape($choices, $controls, $registered, $items, $fields, $consumer);
$providers = array_column($loopers['providers'], null, 'provider');

T::same(['query-recent', 'query-builder', 'taxonomy', 'json', 'range'], array_keys($providers), 'every provider choice is listed, in the builder\'s order');
T::same(['looper_provider_type', 'looper_provider_query_post_types', 'looper_provider_query_post_in', 'looper_provider_query_post_status', 'looper_provider_query_count'], $providers['query-builder']['setting_keys'], 'a provider gets the keys its conditions name, children included');
T::same(['looper_provider_type', 'looper_provider_query_count'], $providers['query-recent']['setting_keys'], 'an IN condition attributes a key to each type it lists');
T::ok(in_array('looper_provider_array_offset', $providers['json']['setting_keys'], true), 'shared array keys reach the array providers');
T::same('registered', $providers['range']['source'], 'a cs_looper_provider_register provider is marked registered');
T::same('builtin', $providers['json']['source'], 'the others are built in');
T::ok(in_array('looper_provider_range_max', $providers['range']['setting_keys'], true), 'a registered provider\'s values become looper_provider_<type>_<key> settings');
T::same(10, $providers['range']['defaults']['looper_provider_range_max'], 'with their defaults');
T::same('post', $providers['query-recent']['item'], 'query providers loop posts');
T::ok(str_contains($providers['query-recent']['item_note'], '{{dc:post:'), 'and say post tokens read the looped post');
T::same('term', $providers['taxonomy']['item'], 'taxonomy loops terms');
T::same(['looper_provider_array_loop_keys'], $loopers['element_keys']['shared'], 'keys no condition narrows are shared');
T::same(['looper_consumer', 'looper_consumer_repeat', 'looper_consumer_rewind'], $loopers['element_keys']['consumer'], 'consumer keys come from the consumer partial');
T::same(['{{dc:looper:item}}', '{{dc:looper:field}}'], array_column($loopers['looper_fields'], 'token'), 'only looper group fields are listed');
T::same('looper.field', $loopers['looper_fields'][1]['twig'], 'with their Twig form');
T::same(['key', 'depth', 'fallback'], $loopers['looper_fields'][1]['arguments'], 'and their arguments');
T::same('unknown', LooperCatalog::shape([['value' => 'mystery', 'label' => 'M']], [], [], [], [])['providers'][0]['item'], 'an item kind that cannot be determined says so');
T::same(['json', 'query-builder', 'query-recent', 'range', 'taxonomy'], LooperCatalog::fingerprint($choices), 'the fingerprint is the sorted provider keys');

T::group('ParameterTypeCatalog');

$managed = [
    'managed-size' => ['type' => 'choose', 'initial' => 'md', 'options' => [['value' => 'xs'], ['value' => 'md']]],
    'bg-image'     => ['type' => 'group', 'params' => ['src' => ['type' => 'image'], 'size' => ['type' => 'text'], 'position' => ['type' => 'position']]],
    'position'     => ['type' => 'select', 'initial' => 'center', 'options' => [['value' => '50% 50%', 'label' => 'Center']]],
    'fade-stop'    => ['type' => 'dimension', 'initial' => '33%', 'isVar' => true],
];
$types = ParameterTypeCatalog::shape($managed);
$byType = array_column($types['managed'], null, 'type');

T::same(['managed-size', 'bg-image', 'position', 'fade-stop'], array_keys($byType), 'every managed type is listed');
T::same('choose', $byType['managed-size']['expands_to'], 'with the base type it expands to');
T::same(['xs', 'md'], $byType['managed-size']['values'], 'and its option values');
T::same(['src' => 'image', 'size' => 'text', 'position' => 'position'], $byType['bg-image']['params'], 'a group lists its params');
T::same('an object of its params', $byType['bg-image']['outputs'], 'and says it outputs an object');
T::ok($byType['fade-stop']['isVar'], 'isVar types are marked');
T::same(['choose', 'dimension', 'group', 'image', 'select', 'text'], $types['base_types']['confirmed'], 'base types are the ones managed types compose from, minus managed names');
T::ok(isset($types['structure']['list'], $types['structure']['color-pair']), 'the structural rules are described');
T::same(['bg-image', 'choose', 'dimension', 'fade-stop', 'group', 'image', 'managed-size', 'position', 'select', 'text'], ParameterTypeCatalog::fingerprint($managed), 'the fingerprint holds managed and base types');

T::group('RegionCatalog');

$flat = [
    'e0' => ['_type' => 'root', '_modules' => ['e1']],
    'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => []],
];
T::same(['content'], RegionCatalog::regionsFromElements($flat), 'a component\'s flat map names its region');
$tree = [['_type' => 'root', '_modules' => [['_type' => 'region', '_region' => 'top', '_modules' => []], ['_type' => 'region', '_region' => 'bottom']]]];
T::same(['top', 'bottom'], RegionCatalog::regionsFromElements($tree), 'regions nested in a root are found');
T::same([], RegionCatalog::regionsFromElements([['_type' => 'section']]), 'a tree without regions has none');

T::group('TwigCatalog');

$keys = ['cs_twig_enabled', 'cs_twig_templates', 'cs_twig_extension_wordpress', 'cs_twig_extension_advanced', 'cs_twig_autoescape', 'x_layout_site'];
$toggles = TwigCatalog::toggles($keys, ['cs_twig_enabled' => '1', 'cs_twig_extension_wordpress' => true, 'cs_twig_extension_advanced' => '', 'cs_twig_autoescape' => false]);
T::same(['cs_twig_autoescape', 'cs_twig_enabled', 'cs_twig_extension_advanced', 'cs_twig_extension_wordpress'], array_keys($toggles), 'toggles are the registered cs_twig_ keys, without the templates list');
T::ok($toggles['cs_twig_enabled']['on'], 'a stored "1" is on');
T::ok(! $toggles['cs_twig_autoescape']['on'], 'false is off');
T::ok(! $toggles['cs_twig_extension_advanced']['writable'], 'the Advanced toggle is not writable');
T::ok($toggles['cs_twig_extension_wordpress']['writable'], 'the others are');

$variables = TwigCatalog::variables(
    [['group' => 'post', 'aliases' => ['p']], ['group' => 'param', 'aliases' => ['p2']], ['group' => 'looper', 'aliases' => []]],
    [['group' => 'post', 'field' => 'title'], ['group' => 'post', 'field' => 'meta', 'arguments' => [['name' => 'key']]], ['group' => 'looper', 'field' => 'item']]
);
$vars = array_column($variables['groups'], null, 'group');
T::same('{{dc:post:title}} is {{ post.title }}', $vars['post']['example'], 'a group maps to a Twig variable of the same name');
T::same('{{dc:post:meta key="x"}} is {{ post.meta({key: "x"}) }}', $vars['post']['with_arguments'], 'arguments go in one object');
T::same(['p'], $vars['post']['aliases'], 'aliases are listed');
T::ok(! empty($vars['param']['nests']), 'parameter groups nest');
T::ok(! isset($vars['looper']['with_arguments']), 'a group without arguments has no argument example');

$notes = TwigCatalog::notes('America/Chicago', true);
T::ok(str_contains($notes[0], 'date("now", "America/Chicago")'), 'the timezone note names the site timezone');
T::ok((bool) array_filter($notes, static fn (string $n): bool => str_contains($n, 'cs_twig_extension_advanced')), 'a note says Advanced stays off');
T::ok((bool) array_filter($notes, static fn (string $n): bool => str_contains($n, '|raw')), 'autoescape adds the |raw note');
T::ok(! array_filter(TwigCatalog::notes('UTC', false), static fn (string $n): bool => str_contains($n, '|raw')), 'and only when it is on');

$fakeEnvironment = new class {
    /** @return array<string, object> */
    public function getFunctions(): array { return ['range' => new stdClass(), 'date' => new stdClass(), 'get_posts' => new stdClass()]; }
    /** @return array<string, object> */
    public function getFilters(): array { return ['date' => new stdClass(), 'readtime' => new stdClass()]; }
    /** @return array<string, object> */
    public function getTests(): array { return ['empty' => new stdClass()]; }
    /** @return array<int, object> */
    public function getTokenParsers(): array
    {
        return [new class { public function getTag(): string { return 'if'; } }, new class { public function getTag(): string { return 'for'; } }];
    }
};
$names = TwigCatalog::environmentNames($fakeEnvironment);
T::same(['date', 'get_posts', 'range'], $names['functions'], 'function names are read from the environment, sorted');
T::same(['date', 'readtime'], $names['filters'], 'as are filters');
T::same(['empty'], $names['tests'], 'and tests');
T::same(['for', 'if'], $names['tags'], 'and tags');
T::same(['id' => 'footer-year', 'title' => 'Year', 'template' => '{{ "now"|date("Y") }}'], TwigCatalog::template([['id' => 'footer-year', 'title' => 'Year', 'template' => '{{ "now"|date("Y") }}']], 'footer-year'), 'a stored template is returned in full');
T::throws(static fn () => TwigCatalog::template([], 'nope'), 'an unknown template id is an error', 'No Twig template');

T::group('ListDynamicContent::present');

$catalog = [
    'groups' => [['group' => 'post', 'label' => 'Post', 'aliases' => ['p'], 'fields' => 2], ['group' => 'url', 'label' => 'URL', 'aliases' => [], 'fields' => 1]],
    'fields' => [
        ['token' => '{{dc:post:title}}', 'group' => 'post', 'field' => 'title', 'label' => 'Title'],
        ['token' => '{{dc:post:id}}', 'group' => 'post', 'field' => 'id', 'label' => 'ID'],
        ['token' => '{{dc:url:param}}', 'group' => 'url', 'field' => 'param', 'label' => 'Parameter'],
    ],
];
T::same(3, ListDynamicContent::present($catalog, [])['field_count'], 'the dynamic_content section returns every field');
T::same(1, ListDynamicContent::present($catalog, ['group' => 'url'])['field_count'], 'narrowed by group');
T::same(['post', 'url'], array_column(ListDynamicContent::present($catalog, ['groups_only' => true])['groups'], 'group'), 'or just the groups');
T::ok(! isset(ListDynamicContent::present($catalog, ['groups_only' => true])['fields']), 'without fields');
T::same(1, ListDynamicContent::present($catalog, ['search' => 'param'])['field_count'], 'search matches a field');
T::throws(static fn () => ListDynamicContent::present($catalog, ['group' => 'acf']), 'an unknown group is an error', 'No token group');
