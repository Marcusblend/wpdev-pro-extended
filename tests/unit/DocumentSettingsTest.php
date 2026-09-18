<?php

declare(strict_types=1);

use ProExtended\Cornerstone\DocumentSettings;

T::group('DocumentSettings');

$ok = DocumentSettings::validate('layout:header', [
    'assignments'         => [['group' => true, 'condition' => 'site:entire-site', 'value' => '']],
    'assignment_priority' => '2',
    'multi_region'        => false,
]);
T::same(2, $ok['assignment_priority'], 'normalizes a numeric priority');
T::same('site:entire-site', $ok['assignments'][0]['condition'], 'keeps assignments');
T::throws(static fn() => DocumentSettings::validate('layout:footer', ['multi_region' => true]), 'multi_region is header-only', 'Unknown setting');
T::throws(static fn() => DocumentSettings::validate('layout:single', ['layout_type' => 'archive']), 'layout_type cannot be set', 'Unknown setting');
T::throws(static fn() => DocumentSettings::validate('layout:header', ['assignments' => [['group' => true, 'condition' => 'x']]]), 'assignment items need all three keys');
T::throws(static fn() => DocumentSettings::validate('layout:header', ['assignments' => [['group' => 'yes', 'condition' => 'x', 'value' => '']]]), 'group must be boolean');
T::throws(static fn() => DocumentSettings::validate('layout:header', ['assignments' => [['group' => true, 'condition' => 'x', 'value' => '', 'extra' => 1]]]), 'unknown assignment keys are rejected');
T::throws(static fn() => DocumentSettings::validate('custom:component', ['document_visibility' => 'secret']), 'unknown visibility values are rejected');
T::same(['header_enabled' => false], DocumentSettings::validate('layout:archive', ['header_enabled' => false]), 'archive layouts accept header_enabled');
T::same(true, DocumentSettings::hasCode(['customCSS' => '.a{}']), 'detects custom CSS');
T::same(false, DocumentSettings::hasCode(['customCSS' => '  ']), 'blank CSS is not code');

// Page-level settings -------------------------------------------------------

T::same(
    ['layoutSingle', 'layoutHeader', 'layoutFooter', 'customCSS', 'customJS'],
    DocumentSettings::allowedKeys('content:page'),
    'a page carries its layout overrides and its own code'
);
T::same(DocumentSettings::allowedKeys('content:page'), DocumentSettings::allowedKeys('content:post'), 'any content type is the same');

$page = DocumentSettings::validate('content:page', ['layoutHeader' => 119, 'layoutFooter' => 'none', 'layoutSingle' => 'default']);
T::same('119', $page['layoutHeader'], 'a document ID is stored as a string, the way Cornerstone stores it');
T::same('none', $page['layoutFooter'], '"none" turns the region off');
T::same('default', $page['layoutSingle'], '"default" leaves the assignment rules to decide');

T::same(['mode' => 'document', 'id' => 119], DocumentSettings::readOverride('119'), 'a numeric string reads as a document');
T::same(['mode' => 'document', 'id' => 119], DocumentSettings::readOverride(119), 'so does an integer');
T::same(['mode' => 'default', 'id' => 0], DocumentSettings::readOverride('Default'), 'the keywords ignore case');

$threw = false;

try {
    DocumentSettings::readOverride('nonsense');
} catch (InvalidArgumentException) {
    $threw = true;
}

T::ok($threw, 'anything else is refused rather than stored and ignored');

$rejected = false;

try {
    DocumentSettings::validate('content:page', ['assignments' => []]);
} catch (InvalidArgumentException) {
    $rejected = true;
}

T::ok($rejected, 'a page has no assignments of its own');

T::same(['layout:single', 'layout:header', 'layout:footer'], array_values(DocumentSettings::LAYOUT_OVERRIDES), 'each override names the document type it points at');
