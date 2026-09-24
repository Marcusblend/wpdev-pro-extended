<?php

declare(strict_types=1);

/**
 * Runs in its own process for PrefabsTest: it defines cornerstone(), which the
 * rest of the unit suite must never see.
 *
 * Plays three requests against one transient store — a write with nothing
 * cached, the list_prefabs read that fills the cache, and the write again — and
 * prints what each did as JSON.
 */

require dirname(__DIR__) . '/bootstrap.php';

use ProExtended\Cornerstone\BuilderContext;
use ProExtended\Cornerstone\Prefabs;
use ProExtended\Elements\HierarchyValidator;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\Tools\ListPrefabs;
use ProExtended\Mcp\Tools\UpdateLayout;

final class FakeElementLibrary
{
    /** @return array<string, mixed> */
    public function get_library(): array
    {
        return [
            'groups'  => ['layout' => 'Layout'],
            'prefabs' => ['layout' => ['row' => ['title' => 'Row', 'values' => ['_type' => 'layout-row', '_m' => ['e' => 2], '_modules' => []]]]],
        ];
    }
}

function cornerstone(string $service = ''): object
{
    return new FakeElementLibrary();
}

/** @return array<string, mixed> */
function cs_prefab_element_values(string $group, string $name): array
{
    return (new FakeElementLibrary())->get_library()['prefabs'][$group][$name]['values'] ?? [];
}

/** A new request: no actions fired yet, not in builder context. */
function new_request(): void
{
    WpStub::$actions = [];
    (new ReflectionProperty(BuilderContext::class, 'entered'))->setValue(null, false);
}

$operation = [['op' => 'prefab', 'path' => '0', 'group' => 'layout', 'name' => 'row']];
$tool = new UpdateLayout(new LayoutService(), new HierarchyValidator(new SchemaExtractor()));
$report = [];

new_request();
$cold = $tool->patch([], $operation);
$report['cold'] = ['errors' => $cold['errors'], 'fired' => did_action('cs_before_late_data')];

new_request();
(new ListPrefabs(new Prefabs()))->execute(['group' => 'layout', 'name' => 'row']);
$report['read'] = ['fired' => did_action('cs_before_late_data')];

new_request();
$warm = $tool->patch([], $operation);
$report['warm'] = ['errors' => $warm['errors'], 'data' => $warm['data'], 'fired' => did_action('cs_before_late_data')];

new_request();
$missing = $tool->patch([], [['op' => 'prefab', 'path' => '0', 'group' => 'layout', 'name' => 'nope']]);
$report['missing'] = ['errors' => $missing['errors'], 'fired' => did_action('cs_before_late_data')];

echo json_encode($report);
