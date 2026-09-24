<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress functions the units reach, backed by WpStub.
 *
 * Nothing here pretends to be WordPress: each stub keeps its state in WpStub,
 * a test sets what it needs and resets it afterwards. `cornerstone()` is
 * deliberately not stubbed — code that checks for Cornerstone must keep seeing
 * a site without it. Every stub is guarded, so a test that defines its own
 * version first keeps it.
 */

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<string, mixed> */
    public static array $transients = [];

    /** @var array<string, int> */
    public static array $actions = [];

    /** @var array<int, object> */
    public static array $posts = [];

    /** @var array<int, array<string, mixed>> Post ID => meta key => value. */
    public static array $meta = [];

    /** @var (\Closure(string, mixed...): bool)|null */
    public static ?\Closure $can = null;

    public static int $userId = 1;

    /** @var array<int, object> Menu ID => items as wp_get_nav_menu_items() returns them. */
    public static array $menuItems = [];

    /** @var array<int, array{menu: int, item: int, args: array<string, mixed>}> */
    public static array $menuWrites = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$actions = [];
        self::$posts = [];
        self::$meta = [];
        self::$can = null;
        self::$userId = 1;
        self::$menuItems = [];
        self::$menuWrites = [];
    }
}

if (! defined('PE_VERSION')) {
    define('PE_VERSION', '0.0.0-test');
}

foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800] as $name => $seconds) {
    if (! defined($name)) {
        define($name, $seconds);
    }
}

if (! class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public int $post_author = 0;
        public string $post_content = '';

        /** @param array<string, mixed> $fields */
        public function __construct(array $fields = [])
        {
            foreach ($fields as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

if (! class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        WpStub::$options[$name] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset(WpStub::$options[$name]);

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $name): mixed
    {
        return WpStub::$transients[$name] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        WpStub::$transients[$name] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        unset(WpStub::$transients[$name]);

        return true;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        WpStub::$actions[$hook] = (WpStub::$actions[$hook] ?? 0) + 1;
    }
}

if (! function_exists('did_action')) {
    function did_action(string $hook): int
    {
        return WpStub::$actions[$hook] ?? 0;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (! function_exists('has_filter')) {
    function has_filter(string $hook, mixed $callback = false): bool
    {
        return false;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return WpStub::$can === null ? true : (WpStub::$can)($capability, ...$args);
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WpStub::$userId;
    }
}

if (! function_exists('get_post')) {
    function get_post(mixed $post = null): ?object
    {
        if (is_object($post)) {
            return $post;
        }

        return WpStub::$posts[(int) $post] ?? null;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        $value = WpStub::$meta[$postId][$key] ?? null;

        return $single ? ($value ?? '') : ($value === null ? [] : [$value]);
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        WpStub::$meta[$postId][$key] = $value;

        return true;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key): bool
    {
        unset(WpStub::$meta[$postId][$key]);

        return true;
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_get_nav_menu_items')) {
    function wp_get_nav_menu_items(int $menu, array $args = []): array|false
    {
        return array_values(array_filter(WpStub::$menuItems, static fn (object $item): bool => (int) ($item->menu ?? 0) === $menu));
    }
}

if (! function_exists('wp_update_nav_menu_item')) {
    function wp_update_nav_menu_item(int $menuId, int $itemId, array $args = []): int
    {
        WpStub::$menuWrites[] = ['menu' => $menuId, 'item' => $itemId, 'args' => $args];

        return $itemId > 0 ? $itemId : 9000 + count(WpStub::$menuWrites);
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $post): int
    {
        return (int) ($post['ID'] ?? 0);
    }
}

if (! function_exists('post_type_exists')) {
    function post_type_exists(string $postType): bool
    {
        return in_array($postType, ['post', 'page', 'attachment', 'nav_menu_item', 'cs_global_block', 'cs_header', 'cs_footer', 'cs_layout_single', 'cs_layout_archive', 'cs_template'], true);
    }
}
