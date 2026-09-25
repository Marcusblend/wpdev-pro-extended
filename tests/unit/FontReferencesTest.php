<?php

declare(strict_types=1);

use ProExtended\Settings\FontReferences as F;

T::group('FontReferences');

$items = [
    ['_id' => 'body', 'title' => 'Body', 'family' => 'Arial', 'source' => 'system'],
    ['_id' => 'fonts', 'title' => 'Group', 'children' => ['body']],
    ['_id' => 'heading', 'title' => 'Heading'],
    'not an item',
    ['title' => 'no id'],
    ['_id' => 'body'],
];
T::same(['body', 'heading'], F::ids($items), 'ids lists font items once, without groups or entries lacking an _id');

T::ok(F::isSourceName('system:helveticaneue'), 'system:<name> is a source form');
T::ok(F::isSourceName('google:barlowcondensed'), 'google:<name> too');
T::ok(! F::isSourceName('global-ff:body'), 'global-ff:<id> is not');
T::ok(! F::isSourceName('body'), 'a bare id is not');

T::same('body', F::familyFix('global-ff:body'), 'global-ff:<id> is fixed to the id');
T::same('body', F::familyFix(' global-ff:body, sans-serif '), 'a trailing fallback is dropped');
T::same('body', F::familyFix('global-fw:body|fw-bold'), 'a weight reference in a family setting gives the id');
T::same(null, F::familyFix('body'), 'a bare id needs no fix');
T::same(null, F::familyFix('global-ff:'), 'a prefix with nothing after it has no fix');

T::same('fw-bold', F::weightFix('global-fw:body|fw-bold'), 'global-fw:<id>|fw-bold is fixed to fw-bold');
T::same('fw-normal', F::weightFix('body|fw-normal'), '<id>|fw-normal is fixed to fw-normal');
T::same('700', F::weightFix('body|700'), 'a numeric weight survives');
T::same(null, F::weightFix('body|heavy'), 'an unreadable weight has no fix');
T::same(null, F::weightFix('fw-normal'), 'a plain weight needs no fix');

T::same('body', F::normalizeThemeFamily('global-ff:body'), 'a theme option family loses its prefix');
T::same('body', F::normalizeThemeFamily('body'), 'a bare id is kept');
T::same('global-ff:body, serif', F::normalizeThemeFamily('global-ff:body, serif'), 'a value that is not exactly global-ff:<id> is left for validation');
T::same(null, F::normalizeThemeFamily(null), 'null is left alone');

T::same('fw-bold', F::normalizeThemeWeight('global-fw:body|fw-bold'), 'a theme option weight loses the prefix and family');
T::same('fw-normal', F::normalizeThemeWeight('body|fw-normal'), 'and a family joined with |');
T::same('fw-bold', F::normalizeThemeWeight('fw-bold'), 'a plain weight is kept');
T::same('inherit', F::normalizeThemeWeight('inherit'), 'inherit is kept');
