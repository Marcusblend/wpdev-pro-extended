<?php

declare(strict_types=1);

use ProExtended\Site\WriteJournal;

T::group('WriteJournal');

// What a call touched -------------------------------------------------------

T::same(['kind' => 'post_id', 'id' => 42], WriteJournal::targetOf([], ['post_id' => 42]), 'a result naming a post is the target');
T::same(['kind' => 'document_id', 'id' => 7], WriteJournal::targetOf([], ['document_id' => 7]), 'so is a document');
T::same(['kind' => 'post_id', 'id' => 9], WriteJournal::targetOf(['post_id' => 9], []), 'the arguments are used when the result says nothing');
T::same(['kind' => 'post_id', 'id' => 42], WriteJournal::targetOf(['post_id' => 9], ['post_id' => 42]), 'the result wins over the arguments');
T::same(['kind' => 'menu', 'id' => 'Primary'], WriteJournal::targetOf(['menu' => 'Primary'], []), 'a named target is kept as given');
T::same(null, WriteJournal::targetOf([], []), 'a call that touched nothing identifiable has no target');
T::same(null, WriteJournal::targetOf([], 'a string result'), 'a result that is not an object has no target');
T::same(null, WriteJournal::targetOf(['post_id' => ''], []), 'an empty id is not a target');

// What changed --------------------------------------------------------------

T::same('written 2', WriteJournal::summarize(['written' => ['a', 'b']]), 'a list is summarized by its size');
T::same('operations_applied 3', WriteJournal::summarize(['operations_applied' => 3]), 'a count is kept');
T::same('added 1, removed 2', WriteJournal::summarize(['added' => ['a'], 'removed' => ['b', 'c']]), 'several counts read in order');
T::same('backup_id abc123', WriteJournal::summarize(['backup_id' => 'abc123']), 'a backup is worth recording');
T::same('written 1, backup_id x, write_path api', WriteJournal::summarize(['written' => ['k'], 'backup_id' => 'x', 'write_path' => 'api']), 'counts come before the paths');

T::same(null, WriteJournal::summarize([]), 'a result with nothing to report summarizes to nothing');
T::same(null, WriteJournal::summarize(['added' => []]), 'an empty list is not reported');
T::same(null, WriteJournal::summarize(['operations_applied' => 0]), 'nor is a zero count');
T::same(null, WriteJournal::summarize('text'), 'nor is a result that is not an object');

// Dry runs, read from each tool's own flag -------------------------------------

T::ok(WriteJournal::isDryRun('set_colors', ['dry_run' => true]), 'dry_run: true is a dry run');
T::ok(WriteJournal::isDryRun('set_colors', ['dry_run' => 'true']), 'so is the string "true"');
T::ok(! WriteJournal::isDryRun('set_colors', ['dry_run' => 'false']), 'the string "false" is not, though it is not empty');
T::ok(! WriteJournal::isDryRun('set_colors', ['dry_run' => '0']), 'nor is "0"');
T::ok(! WriteJournal::isDryRun('set_colors', []), 'a call without dry_run writes');
T::ok(! WriteJournal::isDryRun('set_colors', ['dry_run' => 'yes']), 'a flag the tool would refuse is not taken as a preview');

T::ok(WriteJournal::isDryRun('import_tco', []), 'import_tco without confirm is a preview');
T::ok(WriteJournal::isDryRun('import_tco', ['confirm' => false]), 'as it is with confirm: false');
T::ok(WriteJournal::isDryRun('restore_snapshot', ['confirm' => 'false']), 'or "false"');
T::ok(! WriteJournal::isDryRun('restore_snapshot', ['confirm' => true]), 'confirm: true writes');
T::ok(! WriteJournal::isDryRun('import_tco', ['confirm' => true, 'dry_run' => true]), 'and a confirm-style tool ignores a dry_run it does not take');
T::ok(WriteJournal::isDryRun('get_platform_baseline', []), 'reading the baseline without save writes nothing');
T::ok(! WriteJournal::isDryRun('get_platform_baseline', ['save' => true]), 'save: true does');

// Entries -------------------------------------------------------------------------

WpStub::reset();
$journal = new WriteJournal();

$journal->record('import_tco', ['confirm' => false], ['entries' => []]);
T::ok($journal->read()[0]['dry_run'], 'a confirm: false preview is logged as a dry run');

$journal->record('set_variables', ['dry_run' => 'false'], ['added' => ['brand']]);
T::ok(! $journal->read()[0]['dry_run'], 'a dry_run: "false" write is logged as a write');

$journal->recordFailure('update_layout', ['post_id' => 42], new RuntimeException('Writing the patched layout to post 42 failed.'));
$failed = $journal->read()[0];
T::ok($failed['failed'] ?? false, 'a thrown write is logged as failed');
T::same('Writing the patched layout to post 42 failed.', $failed['error'] ?? null, 'with its error');
T::same(['kind' => 'post_id', 'id' => 42], $failed['target'] ?? null, 'and what it was aimed at');
T::ok(! $failed['dry_run'], 'and is not a dry run');
T::ok(! isset($journal->read()[1]['failed']), 'a write that succeeded is not marked failed');

T::same(503, strlen(WriteJournal::errorText(new RuntimeException(str_repeat('x', 900)))), 'a long message is cut to 500 bytes and an ellipsis');
T::same('LogicException', WriteJournal::errorText(new LogicException('')), 'an empty message names the exception instead');

// Through the server ---------------------------------------------------------------

WpStub::reset();
$server = new ProExtended\Mcp\Server(new ProExtended\Elements\SchemaExtractor(), new ProExtended\Layouts\LayoutService());

$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'set_api_allowlist', 'arguments' => []]]);
T::ok($response['result']['isError'] ?? false, 'a write that throws is reported to the caller');
$logged = $journal->read()[0] ?? [];
T::same('set_api_allowlist', $logged['tool'] ?? null, 'and journalled');
T::ok($logged['failed'] ?? false, 'as failed');
T::same('Pass add, remove, or both.', $logged['error'] ?? null, 'with the error the caller saw');

$server->handleRequest(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_colors', 'arguments' => ['unexpected' => true]]]);
T::same('set_api_allowlist', $journal->read()[0]['tool'] ?? null, 'a read tool that throws is not journalled');

WpStub::reset();
