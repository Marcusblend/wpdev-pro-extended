<?php

declare(strict_types=1);

use ProExtended\Support\JsonArgs;

T::group('JsonArgs');

$args = JsonArgs::decode(['a' => '{"x":1}', 'b' => '[1,2]', 'c' => ['y' => 2], 'd' => null], ['a', 'b', 'c', 'd', 'missing']);
T::same(['x' => 1], $args['a'], 'decodes an object string');
T::same([1, 2], $args['b'], 'decodes an array string');
T::same(['y' => 2], $args['c'], 'leaves arrays alone');
T::same(null, $args['d'], 'leaves null alone');
T::ok(! array_key_exists('missing', $args), 'does not add missing keys');
T::same([], JsonArgs::decodeValue('{}', 'x'), 'decodes an empty object');
T::throws(static fn() => JsonArgs::decodeValue('not json', 'layout_data'), 'rejects a non-JSON string', 'layout_data');
T::throws(static fn() => JsonArgs::decodeValue('{"broken":', 'layout_data'), 'rejects invalid JSON', 'could not be decoded');
T::throws(static fn() => JsonArgs::decodeValue('"text"', 'layout_data'), 'rejects a JSON scalar');
T::throws(static fn() => JsonArgs::decodeValue('', 'layout_data'), 'rejects an empty string');
