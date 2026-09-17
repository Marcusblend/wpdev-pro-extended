<?php

declare(strict_types=1);

use ProExtended\Css\CssBlocks;

T::group('CssBlocks');

$original = ".site { margin: 0; }\n/* keep */\nbody { color: #111; }";

// End insertion, update, second block, removals: the outside never changes.
$step1 = CssBlocks::upsert($original, 'pe-test', '.a { color: red; background: #fff; }');
T::ok($step1['created'], 'creates a block');
T::same($original, CssBlocks::outside($step1['css']), 'outside unchanged after create');
$parsed = CssBlocks::parse($step1['css']);
T::same([], $parsed['errors'], 'parses without errors');
T::same('.a { color: red; background: #fff; }', $parsed['blocks'][0]['content'], 'reads the block content back');

$step2 = CssBlocks::upsert($step1['css'], 'pe-test', ".a { color: blue; }\n.b { color: green; }");
T::ok(! $step2['created'], 'updates the existing block');
T::same($original, CssBlocks::outside($step2['css']), 'outside unchanged after update');
T::same(".a { color: blue; }\n.b { color: green; }", CssBlocks::parse($step2['css'])['blocks'][0]['content'], 'reads the updated content');

$step3 = CssBlocks::upsert($step2['css'], 'second', '.c { color: black; }', 'start');
T::same($original, CssBlocks::outside($step3['css']), 'outside unchanged after a start insertion');
T::same(['second', 'pe-test'], array_column(CssBlocks::parse($step3['css'])['blocks'], 'name'), 'keeps block order');

$step4 = CssBlocks::remove($step3['css'], 'pe-test');
T::same($original, CssBlocks::outside($step4['css']), 'outside unchanged after removing a block');
$step5 = CssBlocks::remove($step4['css'], 'second');
T::same($original, $step5['css'], 'removing every block restores the original bytes');

// Trailing newline and empty stylesheets.
$withNewline = "a { color: red; }\n";
$x = CssBlocks::upsert($withNewline, 'one', 'b { color: blue; }');
T::same($withNewline, CssBlocks::remove($x['css'], 'one')['css'], 'round trip keeps a trailing newline');
$empty = CssBlocks::upsert('', 'one', 'b { color: blue; }');
T::same("/* pe:begin one */\nb { color: blue; }\n/* pe:end one */", $empty['css'], 'an empty stylesheet gets just the block');
T::same('', CssBlocks::remove($empty['css'], 'one')['css'], 'removing the only block empties it again');
$twoAtTop = CssBlocks::upsert(CssBlocks::upsert('p{}', 'one', 'x{}', 'start')['css'], 'two', 'y{}', 'start')['css'];
T::same('p{}', CssBlocks::outside($twoAtTop), 'outside is right with two blocks at the top');
T::same('p{}', CssBlocks::remove(CssBlocks::remove($twoAtTop, 'one')['css'], 'two')['css'], 'both top blocks remove cleanly');
$emptyContent = CssBlocks::upsert('p{}', 'blank', '');
T::same('', CssBlocks::parse($emptyContent['css'])['blocks'][0]['content'], 'a block may be empty');

// Parse problems.
$dup = "/* pe:begin a */\nx{}\n/* pe:end a */\n/* pe:begin a */\ny{}\n/* pe:end a */";
T::ok(CssBlocks::parse($dup)['errors'] !== [], 'reports duplicate block names');
T::throws(static fn() => CssBlocks::upsert($dup, 'b', 'z{}'), 'refuses to write around duplicate blocks', 'more than once');
T::ok(CssBlocks::parse("/* pe:begin a */\nx{}")['errors'] !== [], 'reports a missing pe:end');
T::ok(CssBlocks::parse("/*pe:begin a*/ x{}")['errors'] !== [], 'reports a malformed marker');
T::throws(static fn() => CssBlocks::remove('p{}', 'nope'), 'removing a missing block is an error', 'no block');
T::throws(static fn() => CssBlocks::upsert('', 'Bad Name', 'x{}'), 'rejects an invalid name', 'Invalid block name');
T::throws(static fn() => CssBlocks::upsert('', '-x', 'x{}'), 'rejects a leading hyphen');

// Input checks.
T::ok(CssBlocks::inputErrors('</style><script>alert(1)</script>') !== [], 'refuses </style> and <script>');
T::ok(CssBlocks::inputErrors('<?php echo 1; ?>') !== [], 'refuses <?');
T::ok(CssBlocks::inputErrors("/* pe:begin x */") !== [], 'refuses markers in input');
T::ok(CssBlocks::inputErrors('a { color: red;') !== [], 'refuses an unclosed brace');
T::ok(CssBlocks::inputErrors('a { color: red; }}') !== [], 'refuses an extra closing brace');
T::ok(CssBlocks::inputErrors('/* open comment') !== [], 'refuses an unclosed comment');
T::ok(CssBlocks::inputErrors('a { content: "x }') !== [], 'refuses an unclosed string');
T::same([], CssBlocks::inputErrors('a::after { content: "}{"; } /* } */ b { background: url("x{y}.png"); }'), 'ignores braces in strings and comments');
T::same([], CssBlocks::inputErrors("@media (min-width: 40em) { .a { color: red; } }"), 'accepts nested at-rules');

// Warnings.
T::ok(CssBlocks::warnings('.x { background: #000; }') !== [], 'warns on background without color');
T::same([], CssBlocks::warnings('.x { background: #000; color: #fff; }'), 'no warning when color is set');
T::same([], CssBlocks::warnings('.x { background: none; }'), 'no warning for background: none');
T::ok(CssBlocks::warnings('@media print { .y { background-color: red !important; } }') !== [], 'warns inside media queries');
T::ok(CssBlocks::warnings('@import url("https://fonts.googleapis.com/css2?family=Inter");') !== [], 'warns on remote @import');
T::ok(CssBlocks::warnings(str_repeat('.a{color:red !important}', 51)) !== [], 'warns on more than 50 !important');
T::same([], CssBlocks::warnings(str_repeat('.a{color:red !important}', 50)), 'no warning at 50 !important');
