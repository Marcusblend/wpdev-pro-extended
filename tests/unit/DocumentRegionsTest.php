<?php

declare(strict_types=1);

use ProExtended\Cornerstone\DocumentGateway;

T::group('Document regions');

$reject = static function (string $docType, array $regions, array $rendered): ?string {
    try {
        DocumentGateway::assertRegionsRendered($docType, $regions, $rendered);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};

// A region the type renders passes; a missing one is Cornerstone's to fill ---

T::same(null, $reject('layout:single', ['layout' => [['_type' => 'section']]], ['layout']), 'a single layout written to "layout" passes');
T::same(null, $reject('layout:header', ['top' => [], 'bottom' => []], ['top', 'right', 'bottom', 'left']), 'a header that leaves out regions passes');
T::same(null, $reject('layout:footer', [], ['footer']), 'no regions at all passes');

// A region the type does not render is an error, not a warning ----------------

$single = $reject('layout:single', ['content' => [['_type' => 'section']]], ['layout']);
T::ok($single !== null, 'a single layout written to "content" is refused');
T::ok(str_contains((string) $single, 'Region "content" is not rendered by a layout:single document'), 'naming the region and the type', (string) $single);
T::ok(str_contains((string) $single, 'Its regions are: "layout"'), 'and the region to use instead', (string) $single);
T::ok(str_contains((string) $single, 'Nothing was written'), 'and saying nothing was saved', (string) $single);

$header = $reject('layout:header', ['top' => [], 'content' => [], 'main' => []], ['top', 'right', 'bottom', 'left']);
T::ok(str_contains((string) $header, 'Regions "content", "main" are not rendered'), 'every stray region is named', (string) $header);
T::ok(str_contains((string) $header, '"top", "right", "bottom", "left"'), 'with every region the header renders', (string) $header);

T::ok($reject('layout:footer', ['content' => []], ['footer']) !== null, 'an empty stray region is refused too');
T::ok($reject('layout:single', [0 => []], ['layout']) !== null, 'a numeric key is not a region');
