<?php

declare(strict_types=1);

use ProExtended\Templates\TemplateIdentifier;

T::group('TemplateIdentifier');

$elements = ['headline', 'layout-div', 'section'];
$documents = ['layout:header', 'layout:footer', 'content:page'];

// Parsing -------------------------------------------------------------------

T::same(['type' => 'element', 'sub_type' => 'headline'], TemplateIdentifier::parse('element|headline'), 'an identifier splits on the pipe');
T::same(['type' => 'document', 'sub_type' => 'layout:header'], TemplateIdentifier::parse('document|layout:header'), 'a subtype keeps its own colon');
T::same('element|__multi__', TemplateIdentifier::format('element', '__multi__'), 'and formats back');

foreach (['element', '|headline', 'element|', '', 'element|headline|extra'] as $bad) {
    $threw = false;

    try {
        TemplateIdentifier::parse($bad);
    } catch (InvalidArgumentException) {
        $threw = true;
    }

    // "element|headline|extra" splits into two, with the rest as the subtype,
    // which Cornerstone would then refuse by name rather than by shape.
    if ($bad === 'element|headline|extra') {
        T::ok(! $threw, 'an over-long identifier is left for the type check to refuse');
        continue;
    }

    T::ok($threw, sprintf('"%s" is refused as an identifier', $bad));
}

// What a site will accept ---------------------------------------------------

T::same([], TemplateIdentifier::problems('element', '__multi__', $elements, $documents), 'a block is always allowed');
T::same([], TemplateIdentifier::problems('element', 'headline', $elements, $documents), 'a preset for a known element is allowed');
T::same([], TemplateIdentifier::problems('document', 'layout:header', $elements, $documents), 'a known document type is allowed');

T::same(1, count(TemplateIdentifier::problems('element', 'nope', $elements, $documents)), 'an unknown element is refused');
T::same(1, count(TemplateIdentifier::problems('document', 'layout:nope', $elements, $documents)), 'an unknown document type is refused');
T::same(1, count(TemplateIdentifier::problems('widget', 'x', $elements, $documents)), 'an unknown type is refused');
T::same(1, count(TemplateIdentifier::problems('element', 'headline', [], $documents)), 'with no element list a preset is refused rather than risked');
T::same(1, count(TemplateIdentifier::problems('document', 'layout:header', $elements, [])), 'with no document list a document is refused rather than risked');

// Kinds ---------------------------------------------------------------------

T::same('block', TemplateIdentifier::kind('element', '__multi__'), 'element|__multi__ is a block');
T::same('preset', TemplateIdentifier::kind('element', 'headline'), 'element|<type> is a preset');
T::same('document', TemplateIdentifier::kind('document', 'layout:header'), 'a document is a document');

T::ok(TemplateIdentifier::isPreset('element', 'headline'), 'a preset is recognised');
T::ok(! TemplateIdentifier::isPreset('element', '__multi__'), 'a block is not a preset');
T::ok(! TemplateIdentifier::isPreset('document', 'layout:header'), 'nor is a document');
