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
