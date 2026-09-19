<?php

declare(strict_types=1);

use ProExtended\Settings\ApiAllowlist;

T::group('ApiAllowlist');

// Normalising ---------------------------------------------------------------

$errors = [];
T::same('https://api.example.com/v1/', ApiAllowlist::normalize('https://API.Example.com/v1', $errors), 'the host is lowercased and a trailing slash added');
T::same('https://api.example.com/', ApiAllowlist::normalize('  https://api.example.com  ', $errors), 'whitespace and a bare host are handled');
T::same('https://api.example.com:8443/v1/', ApiAllowlist::normalize('https://api.example.com:8443/v1', $errors), 'a port is kept');
T::same('https://api.example.com/v1/', ApiAllowlist::normalize('https://api.example.com/v1///', $errors), 'repeated slashes collapse to one');
T::same([], $errors, 'none of those were refused');

// Refusing ------------------------------------------------------------------

foreach ([
    'http://api.example.com/'          => 'plain http',
    'api.example.com'                  => 'no scheme',
    '/v1/'                             => 'no host',
    'ftp://files.example.com/'         => 'not https',
    'https://api.example.com/v1?key=x' => 'a query',
    'https://api.example.com/#frag'    => 'a fragment',
    ''                                 => 'empty',
] as $entry => $why) {
    $errors = [];
    T::same(null, ApiAllowlist::normalize($entry, $errors), sprintf('"%s" is refused (%s)', $entry, $why));
    T::same(1, count($errors), sprintf('and says why (%s)', $why));
}

// Planning ------------------------------------------------------------------

$stored = "https://a.example.com/\nhttps://b.example.com/v2/";

$plan = ApiAllowlist::plan($stored, ['https://c.example.com/api'], []);
T::same([], $plan['errors'], 'a good entry plans cleanly');
T::same(['https://c.example.com/api/'], $plan['added'], 'and is added, normalised');
T::same(3, count($plan['entries']), 'joining the stored ones');
T::same('https://a.example.com/', $plan['entries'][0], 'which keep their order');
T::same(['https://c.example.com/api' => 'https://c.example.com/api/'], $plan['normalized'], 'the normalisation is reported');

$again = ApiAllowlist::plan($stored, ['https://a.example.com'], []);
T::same([], $again['added'], 'an entry already listed is not added twice');
T::same(['https://a.example.com/'], $again['unchanged'], 'it is reported as already there');
T::same(2, count($again['entries']), 'and the list does not grow');

$removed = ApiAllowlist::plan($stored, [], ['https://b.example.com/v2']);
T::same(['https://b.example.com/v2/'], $removed['removed'], 'removal ignores the trailing slash');
T::same(1, count($removed['entries']), 'and the entry is gone');

T::same([], ApiAllowlist::plan($stored, [], ['https://nothere.example.com/'])['removed'], 'removing something absent is quiet');

$bad = ApiAllowlist::plan($stored, ['http://insecure.example.com/'], []);
T::same(1, count($bad['errors']), 'a refused entry is reported');
T::same([], $bad['added'], 'and adds nothing');

// Round trip ----------------------------------------------------------------

T::same(['https://a.example.com/', 'https://b.example.com/v2/'], ApiAllowlist::parse($stored), 'parsing splits on newlines');
T::same($stored, ApiAllowlist::encode(ApiAllowlist::parse($stored)), 'and encoding puts it back');
T::same([], ApiAllowlist::parse("\n  \n"), 'blank lines are dropped');
