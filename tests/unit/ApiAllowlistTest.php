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

// Hosts inside the site's own network -----------------------------------------

foreach ([
    'https://localhost/'                  => 'localhost',
    'https://LOCALHOST./api'              => 'localhost with a trailing dot',
    'https://admin.localhost/'            => 'a .localhost name',
    'https://127.0.0.1/'                  => 'IPv4 loopback',
    'https://127.8.9.10:8443/v1'          => 'anywhere in 127/8',
    'https://10.0.0.5/'                   => 'RFC 1918 10/8',
    'https://172.16.0.1/'                 => 'RFC 1918 172.16/12, low end',
    'https://172.31.255.254/'             => 'RFC 1918 172.16/12, high end',
    'https://192.168.1.1/'                => 'RFC 1918 192.168/16',
    'https://169.254.169.254/latest/'     => 'link-local, the metadata service',
    'https://0.0.0.0/'                    => 'this host',
    'https://[::1]/'                      => 'IPv6 loopback',
    'https://[::]/'                       => 'the unspecified IPv6 address',
    'https://[fe80::1]/'                  => 'IPv6 link-local',
    'https://[fd12:3456:789a::1]/'        => 'IPv6 unique local',
    'https://[fc00::1]/'                  => 'IPv6 unique local, fc00',
    'https://[::ffff:127.0.0.1]/'         => 'IPv4 loopback written as IPv6',
    'https://[::ffff:10.1.2.3]/'          => 'a private IPv4 address written as IPv6',
    'https://2130706433/'                 => '127.0.0.1 as one number',
    'https://127.1/'                      => '127.0.0.1 shortened',
    'https://0x7f.0.0.1/'                 => '127.0.0.1 in hex',
] as $entry => $why) {
    $errors = [];
    T::same(null, ApiAllowlist::normalize($entry, $errors), sprintf('"%s" is refused (%s)', $entry, $why));
    T::same(1, count($errors), sprintf('and says why (%s)', $why));
}

foreach ([
    'https://172.15.0.1/'     => 'https://172.15.0.1/',
    'https://172.32.0.1/'     => 'https://172.32.0.1/',
    'https://8.8.8.8/dns/'    => 'https://8.8.8.8/dns/',
    'https://[2001:db8::1]/'  => 'https://[2001:db8::1]/',
    'https://localhost.example.com/' => 'https://localhost.example.com/',
    'https://10.example.com/' => 'https://10.example.com/',
    'https://api.example.com/users/@me' => 'https://api.example.com/users/@me/',
] as $entry => $expected) {
    $errors = [];
    T::same($expected, ApiAllowlist::normalize($entry, $errors), sprintf('"%s" is a public host and is allowed', $entry));
}

// User information is refused, not stripped -------------------------------------

foreach (['https://user@api.example.com/', 'https://user:secret@api.example.com/', 'https://api.example.com@evil.example.net/', 'https://@api.example.com/'] as $entry) {
    $errors = [];
    T::same(null, ApiAllowlist::normalize($entry, $errors), sprintf('"%s" is refused rather than stripped', $entry));
    T::ok(str_contains($errors[0] ?? '', 'user name or password'), 'and the message says why');
}

// Failing closed ---------------------------------------------------------------

$single = 'https://a.example.com/';

$emptied = ApiAllowlist::plan($single, [], ['https://a.example.com/'], true);
T::same(1, count($emptied['errors']), 'removing the last entry while the External API is on is an error');
T::ok(str_contains($emptied['errors'][0], 'allow every URL'), 'which says an empty list allows everything');

$off = ApiAllowlist::plan($single, [], ['https://a.example.com/'], false);
T::same([], $off['errors'], 'with the feature off the list may be emptied');

$swapped = ApiAllowlist::plan($single, ['https://b.example.com/'], ['https://a.example.com/'], true);
T::same([], $swapped['errors'], 'replacing the last entry in one call is fine');
T::same(['https://b.example.com/'], $swapped['entries'], 'and leaves the new one');

$stillEmpty = ApiAllowlist::plan('', [], ['https://nothere.example.com/'], true);
T::same(1, count($stillEmpty['errors']), 'a change that leaves an empty list empty is refused while the feature is on');

$filled = ApiAllowlist::plan('', ['https://a.example.com'], [], true);
T::same([], $filled['errors'], 'and filling it is how to fix that');

$badOnly = ApiAllowlist::plan($single, ['https://localhost/'], ['https://a.example.com/'], true);
T::same(2, count($badOnly['errors']), 'a refused addition does not count towards keeping the list filled');
