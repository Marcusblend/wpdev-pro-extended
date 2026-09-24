<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\Prefabs;
use ProExtended\Support\Args;

final class ListPrefabs implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly Prefabs $prefabs) {}

    public function name(): string
    {
        return 'list_prefabs';
    }

    public function description(): string
    {
        return 'List Cornerstone\'s prefab elements: the ready-made element groups the builder\'s library inserts, such as a horizontal flex container or a link box. Pass group and name to get one prefab\'s element values, which can be inserted with update_layout\'s add operation — a better starting point than an element with defaults, because it arrives configured the way Cornerstone configures it. Prefabs are registered in code, so this reads the live registry, and it caches what it reads: update_layout\'s prefab operation inserts a prefab by group and name from that cache, since a write cannot read the registry itself.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'group' => [
                    'type'        => 'string',
                    'description' => 'Optional. Only this group; with name, return its values.',
                ],
                'name' => [
                    'type'        => 'string',
                    'description' => 'Optional. With group, return that prefab\'s element values.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['group', 'name'], 'arguments');

        $group = Args::string($arguments, 'group', null, 100);
        $name = Args::string($arguments, 'name', null, 100);

        if ($name !== null && $group === null) {
            throw new \InvalidArgumentException('Pass group alongside name.');
        }

        $all = $this->prefabs->all();

        if ($name !== null) {
            $values = $this->prefabs->values((string) $group, $name);

            if ($values === null) {
                throw new \InvalidArgumentException(sprintf('No prefab "%s" in group "%s". Call without arguments to see them.', $name, (string) $group));
            }

            return ['group' => $group, 'name' => $name, 'values' => $values];
        }

        if ($group !== null) {
            if (! isset($all[$group])) {
                throw new \InvalidArgumentException(sprintf('No prefab group "%s" on this site (%s).', $group, implode(', ', array_keys($all))));
            }

            return ['group' => $group, 'count' => count($all[$group]), 'prefabs' => $all[$group]];
        }

        $count = 0;

        foreach ($all as $prefabs) {
            $count += count($prefabs);
        }

        return ['groups' => array_keys($all), 'count' => $count, 'prefabs' => $all];
    }

    public function annotations(): array
    {
        return Annotations::read('List Prefabs');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
