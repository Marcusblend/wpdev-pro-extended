<?php

declare(strict_types=1);

use ProExtended\Mcp\Tools\CreateComponent;

T::group('CreateComponent registry check');

// The registry the builder reads, keyed by _c_id with the owning document ------

$registry = [
    'card'       => ['root' => 'e2', 'doc' => 40, 'data' => []],
    'cardTitle'  => ['root' => 'e3', 'doc' => 40, 'data' => []],
    'hero'       => ['root' => 'e2', 'doc' => 12, 'data' => []],
];

T::same([], CreateComponent::unregisteredExports(['card', 'cardTitle'], 40, $registry), 'every export registered to the new document passes');

$missing = CreateComponent::unregisteredExports(['card', 'cardBody'], 40, $registry);
T::same([['id' => 'cardBody', 'registered_to' => null]], $missing, 'an export the registry never picked up is reported');

$clash = CreateComponent::unregisteredExports(['hero'], 40, $registry);
T::same([['id' => 'hero', 'registered_to' => 12]], $clash, 'an export id another document already claims is reported with that document');

T::same([['id' => 'card', 'registered_to' => 40]], CreateComponent::unregisteredExports(['card'], 0, $registry), 'no document id means nothing can be confirmed');
T::same([['id' => 'card', 'registered_to' => null]], CreateComponent::unregisteredExports(['card'], 40, []), 'an empty registry confirms nothing');
T::same([], CreateComponent::unregisteredExports(['card'], 40, ['card' => ['doc' => '40']]), 'a document id stored as a string still matches');

// The error says what happened and that nothing was kept ------------------------

$message = CreateComponent::unregisteredMessage(41, [['id' => 'cardBody', 'registered_to' => null], ['id' => 'hero', 'registered_to' => 12]], ['Overlapping component _c_id value: hero. Documents 41 and 12']);

T::ok(str_contains($message, 'document 41'), 'the message names the document that was saved', $message);
T::ok(str_contains($message, '"cardBody"'), 'and each missing export', $message);
T::ok(str_contains($message, '"hero" (Cornerstone has it from document 12 instead)'), 'and which document holds a clashing id', $message);
T::ok(str_contains($message, 'deleted again'), 'and that the document was removed', $message);
T::ok(str_contains($message, 'Overlapping component _c_id value'), 'with the registry\'s own errors', $message);
T::ok(! str_contains(CreateComponent::unregisteredMessage(41, [['id' => 'x', 'registered_to' => null]], ['', '']), 'reports:'), 'blank registry errors are left out');
