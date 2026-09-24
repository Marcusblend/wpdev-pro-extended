<?php

declare(strict_types=1);

use ProExtended\Js\JsBlocks;
use ProExtended\Mcp\Tools\SetGlobalJs;

T::group('JsBlocks');

$original = "window.siteFlag = true;\n// keep this comment\nconsole.log('ready');";

$step1 = JsBlocks::upsert($original, 'pe-menu', "document.documentElement.classList.add('js');");
T::ok($step1['created'], 'creates a block');
T::same($original . "\n// pe:begin pe-menu\ndocument.documentElement.classList.add('js');\n// pe:end pe-menu", $step1['js'], 'the block is appended with line markers');
T::same($original, JsBlocks::outside($step1['js']), 'outside unchanged after create');
T::same("document.documentElement.classList.add('js');", JsBlocks::parse($step1['js'])['blocks'][0]['content'], 'reads the block content back');

$step2 = JsBlocks::upsert($step1['js'], 'pe-menu', "var a = 1;\nvar b = 2;");
T::ok(! $step2['created'], 'updates the existing block');
T::same("document.documentElement.classList.add('js');", $step2['before'], 'and reports what it held');
T::same($original, JsBlocks::outside($step2['js']), 'outside unchanged after update');

$step3 = JsBlocks::upsert($step2['js'], 'first', 'var c = 3;', 'start');
T::same(['first', 'pe-menu'], array_column(JsBlocks::parse($step3['js'])['blocks'], 'name'), 'a start insertion goes first');
T::same($original, JsBlocks::outside($step3['js']), 'outside unchanged after a start insertion');

$step4 = JsBlocks::remove($step3['js'], 'pe-menu');
T::same("var a = 1;\nvar b = 2;", $step4['before'], 'remove reports the old content');
T::same($original, JsBlocks::remove($step4['js'], 'first')['js'], 'removing every block restores the original bytes');

$empty = JsBlocks::upsert('', 'one', 'x();');
T::same("// pe:begin one\nx();\n// pe:end one", $empty['js'], 'an empty script gets just the block');
T::same('', JsBlocks::remove($empty['js'], 'one')['js'], 'and removing it empties it again');

$crlf = "a();\r\n// pe:begin one\r\nb();\r\n// pe:end one\r\nc();";
T::same([], JsBlocks::parse($crlf)['errors'], 'markers on CRLF lines are still recognised');

T::ok(JsBlocks::parse("// pe:begin a\nx();")['errors'] !== [], 'a missing pe:end is reported');
T::ok(JsBlocks::parse("/* pe:begin a */\nx();\n/* pe:end a */")['errors'] !== [], 'a CSS-style marker in JS is malformed');
T::ok(JsBlocks::parse("x(); // pe:begin a\ny();\n// pe:end a")['errors'] !== [], 'a marker must start its line');
T::throws(static fn () => JsBlocks::upsert("// pe:begin a\nx();", 'b', 'y();'), 'refuses to write around broken blocks', 'broken');
T::throws(static fn () => JsBlocks::remove('x();', 'nope'), 'removing a missing block is an error', 'no block');
T::throws(static fn () => JsBlocks::upsert('', 'Bad Name', 'x();'), 'rejects an invalid name', 'Invalid block name');

T::group('JsBlocks input');

T::ok(JsBlocks::inputErrors('<script>alert(1)</script>') !== [], 'refuses <script');
T::ok(JsBlocks::inputErrors('var s = "</script>";') !== [], 'refuses </script');
T::ok(JsBlocks::inputErrors('var s = "< /SCRIPT>";') !== [], 'refuses a spaced, upper-case closing tag');
T::ok(JsBlocks::inputErrors('<?php echo 1; ?>') !== [], 'refuses <?');
T::ok(JsBlocks::inputErrors("// pe:end x\n") !== [], 'refuses marker text');
T::ok(JsBlocks::inputErrors(str_repeat('a', JsBlocks::MAX_BYTES + 1)) !== [], 'refuses more than 256 KB');
T::same([], JsBlocks::inputErrors("document.querySelectorAll('.x').forEach(function (el) { el.hidden = false; });"), 'plain JavaScript is accepted');
T::ok(JsBlocks::warnings('var t = "{{dc:post:title}}";') !== [], 'warns that {{ goes through Dynamic Content');
T::ok(JsBlocks::warnings('eval("1")') !== [], 'warns on eval');
T::same([], JsBlocks::warnings('var a = 1;'), 'plain JavaScript has no warnings');

T::group('SetGlobalJs::plan');

$planned = SetGlobalJs::plan($original, 'upsert_block', 'pe-menu', 'x();');
T::same($original, JsBlocks::outside($planned['after']), 'upsert keeps the script outside the blocks');
T::throws(static fn () => SetGlobalJs::plan($original, 'upsert_block', 'pe-menu', '</script><script>x()</script>'), 'upsert refuses a script tag', 'Refused');
T::throws(static fn () => SetGlobalJs::plan($original, 'upsert_block', 'pe-menu', "// pe:begin other\nx();"), 'upsert refuses marker text inside a block', 'markers');
T::throws(static fn () => SetGlobalJs::plan($original, 'upsert_block', null, 'x();'), 'upsert needs a name', 'needs "name"');
T::throws(static fn () => SetGlobalJs::plan($original, 'upsert_block', 'a', null), 'upsert needs js', 'needs "js"');
T::throws(static fn () => SetGlobalJs::plan($original, 'remove_block', 'a', 'x();'), 'remove does not take js', 'does not take');
T::throws(static fn () => SetGlobalJs::plan($original, 'replace_all', null, 'x();'), 'replace_all needs confirmation', 'confirm_replace_all');
T::same('x();', SetGlobalJs::plan($original, 'replace_all', null, 'x();', 'end', true)['after'], 'replace_all with confirmation replaces everything');
T::throws(static fn () => SetGlobalJs::plan($original, 'replace_all', null, '<?php', 'end', true), 'replace_all still refuses <?', 'Refused');
T::throws(static fn () => SetGlobalJs::plan($original, 'replace_all', 'a', 'x();', 'end', true), 'replace_all does not take a name', 'does not take');
T::throws(static fn () => SetGlobalJs::plan($original, 'upsert_block', 'a', str_repeat('a', JsBlocks::MAX_BYTES)), 'the result must stay under the limit', 'limit');
T::same([], SetGlobalJs::plan($original, 'upsert_block', 'a', 'x();')['warnings'], 'plain script plans without warnings');
