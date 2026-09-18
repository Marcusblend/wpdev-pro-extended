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
