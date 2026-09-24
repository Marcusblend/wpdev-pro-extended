<?php

declare(strict_types=1);

use ProExtended\Elements\SchemaExtractor;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\Server;
use ProExtended\Menus\MenuItems;
use ProExtended\Mcp\Tools\UpdateLayout;

T::group('SchemaParity');

/*
 * What a tool's description tells a client to send, its inputSchema() has to
 * accept: a client that validates arguments against the schema refuses
 * anything else before the call reaches the tool. 1.3 shipped update_layout's
 * "preset" that way, and 1.4 did it again with five more operations.
 *
 * Descriptions are prose, so this reads only the forms that are unambiguous:
 *
 *  1. `"op": "x"` — x is in the schema's op enum.
 *  2. Every value of an `op` or `operation` enum is named in the description,
 *     so the schema offers nothing the description never explains.
 *  3. `name: true`, `name: false`, `name: "text"` or `name: 5` outside a {...}
 *     example is an argument, and must be a property somewhere in the schema —
 *     or in the schema of the tool named just before it, as in "list_templates
 *     with kind: ...".
 *  4. Where the operations declare their own properties, every quoted
 *     identifier the description uses as a key (`"path": ...`) or in prose
 *     (`takes "to"`) is an operation property, a top-level property or an op.
 *
 * Limits: an argument named only in bare prose ("move takes to") is not seen;
 * update_menu and create_menu validate their operations in MenuItems rather
 * than in the schema, so their ops are checked against MenuItems::OPS; and
 * words a description uses for a result field are listed in RESULT_WORDS.
 */

$server = new Server(new SchemaExtractor(), new LayoutService());
$tools = $server->getTools();

T::ok(count($tools) > 40, 'the registry builds without WordPress', (string) count($tools));
T::same([], $server->getRegistrationErrors(), 'every tool constructs');

/** Words a description uses for a field of the result, not an argument. */
const RESULT_WORDS = [
    'upload_media' => ['deduped'],
];

/** Tools whose operations MenuItems validates, not the schema. */
const CODE_VALIDATED_OPS = [
    'update_menu' => MenuItems::OPS,
    'create_menu' => MenuItems::OPS,
];

/**
 * Every property name declared anywhere in a schema.
 *
 * @return string[]
 */
function parity_properties(mixed $schema): array
{
    if (! is_array($schema)) {
        return [];
    }

    $names = [];

    foreach ($schema as $key => $value) {
        if ($key === 'properties' && is_array($value)) {
            $names = array_merge($names, array_map('strval', array_keys($value)));
        }

        $names = array_merge($names, parity_properties($value));
    }

    return array_values(array_unique($names));
}

/**
 * The enum of every property called op or operation, and the object it sits in.
 *
 * @return array<int, array{enum: string[], siblings: string[]}>
 */
function parity_op_enums(mixed $schema): array
{
    if (! is_array($schema)) {
        return [];
    }

    $found = [];

    if (isset($schema['properties']) && is_array($schema['properties'])) {
        foreach (['op', 'operation'] as $name) {
            if (isset($schema['properties'][$name]['enum']) && is_array($schema['properties'][$name]['enum'])) {
                $found[] = [
                    'enum'     => array_map('strval', $schema['properties'][$name]['enum']),
                    'siblings' => array_map('strval', array_keys($schema['properties'])),
                ];
            }
        }
    }

    foreach ($schema as $value) {
        $found = array_merge($found, parity_op_enums($value));
    }

    return $found;
}

function parity_without_examples(string $text): string
{
    while (preg_match('/\{[^{}]*\}/', $text) === 1) {
        $text = (string) preg_replace('/\{[^{}]*\}/', ' ', $text);
    }

    return $text;
}

foreach ($tools as $name => $tool) {
    $description = $tool->description();
    $schema = $tool->inputSchema();
    $properties = parity_properties($schema);
    $opEnums = parity_op_enums($schema);
    $ops = array_values(array_unique(array_merge(...array_merge([[]], array_column($opEnums, 'enum')))));

    // 1. "op": "x" -------------------------------------------------------------

    preg_match_all('/"op":\s*"([a-z_]+)"/', $description, $named);

    foreach (array_unique($named[1]) as $op) {
        $accepted = CODE_VALIDATED_OPS[$name] ?? $ops;
        T::ok(in_array($op, $accepted, true), sprintf('%s: the "%s" op its description names is accepted', $name, $op));
    }

    // 2. every op the schema offers is explained --------------------------------

    foreach ($ops as $op) {
        T::ok(preg_match('/\b' . preg_quote($op, '/') . '\b/', $description) === 1, sprintf('%s: the "%s" op in its schema is named in its description', $name, $op));
    }

    // 3. name: value arguments -------------------------------------------------

    preg_match_all('/(?:\b([a-z][a-z0-9_]*)\s+with\s+)?\b([a-z][a-z0-9_]*):\s*(?:true|false|"[^"]*"|\d+)/', parity_without_examples($description), $arguments, PREG_SET_ORDER);

    foreach ($arguments as $match) {
        $owner = $match[1] !== '' && isset($tools[$match[1]]) ? $match[1] : $name;
        $argument = $match[2];

        if (in_array($argument, RESULT_WORDS[$name] ?? [], true)) {
            continue;
        }

        $ownerProperties = $owner === $name ? $properties : parity_properties($tools[$owner]->inputSchema());

        T::ok(in_array($argument, $ownerProperties, true), sprintf('%s: "%s" named in its description is an argument of %s', $name, $argument, $owner));
    }

    // 4. quoted keys of the operations -----------------------------------------

    foreach ($opEnums as $opEnum) {
        if (count($opEnum['siblings']) < 2) {
            continue;
        }

        $topLevel = array_map('strval', array_keys((array) ($schema['properties'] ?? [])));
        $known = array_merge($opEnum['siblings'], $topLevel, $opEnum['enum']);

        // A quoted identifier not preceded by ": " (a value, such as kind: "preset").
        preg_match_all('/(?<!:\s)(?<!:)"([a-z_]+)"/', $description, $quoted);

        foreach (array_unique($quoted[1]) as $word) {
            T::ok(in_array($word, $known, true), sprintf('%s: "%s" in its description is a property of its operations', $name, $word));
        }
    }
}

// Targeted: update_layout ---------------------------------------------------

$updateLayout = $tools['update_layout'];
$itemSchema = $updateLayout->inputSchema()['properties']['operations']['items'];

T::same(UpdateLayout::OPS, $itemSchema['properties']['op']['enum'], 'update_layout offers exactly the ops it handles');

foreach (['to', 'group', 'name', 'value', 'preset', 'path'] as $property) {
    T::ok(isset($itemSchema['properties'][$property]), sprintf('update_layout declares "%s" on an operation', $property));
}

foreach (UpdateLayout::OPS as $op) {
    $patched = $updateLayout->patch([['_type' => 'section', '_modules' => []]], [['op' => $op, 'path' => '0']]);
    $unknown = array_filter($patched['errors'], static fn (string $error): bool => str_contains($error, 'Unknown operation'));
    T::same([], array_values($unknown), sprintf('update_layout handles the "%s" op it offers', $op));
}

// The ops patch() dispatches on, read from its match, so a new arm that never
// reached OPS (and so never reached the schema) is caught here.
$method = new ReflectionMethod(UpdateLayout::class, 'patch');
$source = implode('', array_slice((array) file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
preg_match('/match \(\$opType\) \{(.*?)\n\s*\};/s', $source, $arms);
preg_match_all("/^\s*'([a-z_]+)'\s*=>/m", $arms[1] ?? '', $handled);
$offered = UpdateLayout::OPS;
sort($offered);
sort($handled[1]);
T::same($offered, $handled[1], 'every op update_layout handles is one it offers');

$unknown = $updateLayout->patch([], [['op' => 'rename', 'path' => '0']]);
T::ok(str_contains($unknown['errors'][0] ?? '', 'Unknown operation "rename"'), 'an op it does not offer is refused by name');

// Targeted: the menu tools --------------------------------------------------

foreach (MenuItems::OPS as $op) {
    T::ok(str_contains($tools['update_menu']->description(), '"op": "' . $op . '"'), sprintf('update_menu shows the shape of its "%s" op', $op));
}

// Targeted: set_global_css --------------------------------------------------

$cssSchema = $tools['set_global_css']->inputSchema();

foreach ($cssSchema['properties']['operation']['enum'] as $operation) {
    T::ok(str_contains($tools['set_global_css']->description(), $operation), sprintf('set_global_css explains its "%s" operation', $operation));
}

T::ok(isset($cssSchema['properties']['name'], $cssSchema['properties']['css'], $cssSchema['properties']['confirm_replace_all']), 'set_global_css declares the arguments its operations take');
