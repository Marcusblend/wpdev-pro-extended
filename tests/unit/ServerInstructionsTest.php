<?php

declare(strict_types=1);

use ProExtended\Mcp\Server;

T::group('Server instructions');

$instructions = (string) (new ReflectionClassConstant(Server::class, 'INSTRUCTIONS'))->getValue();

T::ok(str_starts_with($instructions, 'Pro Extended reads and writes Cornerstone (Pro theme) sites.'), 'the handshake opens by saying what the server does');
T::ok(str_contains($instructions, 'Never write PHP, a plugin, an mu-plugin, functions.php code, a custom shortcode or wp_head output'), 'it rules out site-specific code');
T::ok(str_contains($instructions, 'stop and tell the user which native feature falls short'), 'it says what to do instead');

// The ladder is numbered 1 to 8, in order, with the last resort last.
preg_match_all('/^(\d)\. /m', $instructions, $steps);
T::same(['1', '2', '3', '4', '5', '6', '7', '8'], $steps[1], 'the native ladder has eight steps in order');
T::ok(strpos($instructions, "8. Last resort") > strpos($instructions, '1. The element\'s own setting'), 'css and script come last');

foreach (['get_element_schema', 'list_colors', 'set_global_parameters', 'get_native_reference', 'render_preview', 'validate_layout', 'restore_layout', 'restore_settings', 'set_global_js', 'pe://guide/native'] as $name) {
    T::ok(str_contains($instructions, $name), "it names {$name}");
}

// The smoke suite checks the reference form is still in the handshake.
T::ok(str_contains($instructions, '"global-color:<_id>"'), 'it keeps the palette reference form');
T::ok(! str_contains($instructions, "\t"), 'no tabs in the handshake');
