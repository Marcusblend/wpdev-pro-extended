<?php

declare(strict_types=1);

use ProExtended\Cornerstone\Renderer;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Mcp\Tools\RenderPreview;

T::group('RenderPreview');

$tool = new RenderPreview(new Renderer(), new LayoutService());

// Annotations and wording -----------------------------------------------------

$annotations = $tool->annotations();
T::ok($annotations['readOnlyHint'], 'it is read-only: nothing is written to the database');
T::ok($annotations['openWorldHint'], 'and open-world: shortcodes, Twig and External API loopers run');
T::ok(! str_contains($tool->description(), 'Strictly read-only'), 'the description no longer calls it strictly read-only');
T::ok(str_contains($tool->description(), 'writes nothing to the database'), 'it says what it does not do instead');

// Reading the posts it renders --------------------------------------------------

WpStub::reset();
WpStub::$posts[5] = new WP_Post(['ID' => 5, 'post_type' => 'post', 'post_status' => 'private', 'post_author' => 2]);
WpStub::$posts[6] = new WP_Post(['ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1]);
WpStub::$can = static fn (string $capability, mixed ...$args): bool => $capability !== 'read_post' || ($args[0] ?? null) !== 5;

T::throws(static fn () => $tool->execute(['elements' => [['_type' => 'text']], 'for_post' => 5]), 'a contributor cannot render against another author\'s private post', 'cannot read post 5');
T::throws(static fn () => $tool->execute(['post_id' => 5]), 'nor render its layout', 'cannot read post 5');
T::throws(static fn () => $tool->execute(['post_id' => 6, 'for_post' => 5]), 'nor use it as the context for a readable layout', 'for_post');

try {
    $tool->execute(['post_id' => 5]);
} catch (\Throwable $e) {
    T::ok($e instanceof ToolPermissionException, 'the refusal is a permission error, not bad input');
}

T::throws(static fn () => $tool->execute(['elements' => [['_type' => 'text']], 'for_post' => 99]), 'a post that does not exist is reported as such', 'does not exist');

WpStub::reset();

// What counts as output -----------------------------------------------------------

T::ok(RenderPreview::isEmpty(''), 'nothing is empty');
T::ok(RenderPreview::isEmpty('<div class="x-section"><div class="x-row"> </div></div>'), 'wrappers with no content are empty');
T::ok(! RenderPreview::isEmpty('<p>Hello</p>'), 'text is output');
T::ok(! RenderPreview::isEmpty('<div><img src="a.jpg" alt=""></div>'), 'an image is output');
T::ok(! RenderPreview::isEmpty('<span><svg viewBox="0 0 1 1"></svg></span>'), 'an SVG icon is output');
T::ok(! RenderPreview::isEmpty('<video src="a.mp4"></video>'), 'a video is output');
T::ok(! RenderPreview::isEmpty('<iframe src="https://example.com/map"></iframe>'), 'an embed is output');
T::ok(! RenderPreview::isEmpty('<picture><source srcset="a.webp"></picture>'), 'a picture is output');
T::ok(! RenderPreview::isEmpty('<IMG/src="a.jpg">'), 'however the tag is written');
T::ok(RenderPreview::isEmpty('<div data-img="x" class="imgs"></div>'), 'an attribute that merely mentions img is not');

// The query globals a render against a post changes are all put back --------------

$GLOBALS['wp_query'] = 'the main query';
$GLOBALS['wp_the_query'] = 'the main query';
$GLOBALS['post'] = 'the page being served';
unset($GLOBALS['pages'], $GLOBALS['numpages']);

$saved = Renderer::captureGlobals();

$GLOBALS['wp_query'] = 'a singular query for the context post';
$GLOBALS['wp_the_query'] = 'a singular query for the context post';
$GLOBALS['post'] = 'the context post';
$GLOBALS['pages'] = ['page one'];
$GLOBALS['numpages'] = 1;

Renderer::restoreGlobals($saved);

T::same('the main query', $GLOBALS['wp_query'], '$wp_query is put back');
T::same('the main query', $GLOBALS['wp_the_query'], 'and $wp_the_query, which a looper resets to');
T::same('the page being served', $GLOBALS['post'], 'and $post');
T::ok(! array_key_exists('pages', $GLOBALS) && ! array_key_exists('numpages', $GLOBALS), 'a global that was not set before is unset again');

foreach (['wp_query', 'wp_the_query', 'post'] as $name) {
    unset($GLOBALS[$name]);
}
