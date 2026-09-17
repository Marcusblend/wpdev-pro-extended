<?php

declare(strict_types=1);

/**
 * Helpers for the smoke suites. Run the suites with:
 *
 *     wp eval-file --use-include --user=<admin ID> <suite>.php [args...]
 *
 * (--use-include matters: the files declare strict_types, which eval() rejects.)
 */

if (! defined('ABSPATH')) {
    exit(1);
}

final class PeSmoke
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;

    /** @var string[] */
    public static array $failures = [];

    /** @var array<int, array{kind: string, id: int, title: string}> */
    public static array $created = [];

    /** @var string[] Things a person must look at, whatever the result. */
    public static array $attention = [];

    private static int $rpcId = 1;
    private static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n== {$name} ==\n";
    }

    /**
     * Call a tool through the MCP server, exactly as a client would.
     *
     * @param  array<string, mixed> $arguments
     * @return array{rpc_error: array<string, mixed>|null, is_error: bool, text: string, data: mixed, bytes: int}
     */
    public static function call(string $tool, array $arguments = []): array
    {
        $response = self::rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);

        if (isset($response['error'])) {
            return ['rpc_error' => $response['error'], 'is_error' => true, 'text' => (string) $response['error']['message'], 'data' => null, 'bytes' => 0];
        }

        $result = $response['result'] ?? [];
        $text = (string) ($result['content'][0]['text'] ?? '');

        return [
            'rpc_error' => null,
            'is_error'  => ! empty($result['isError']),
            'text'      => $text,
            'data'      => json_decode($text, true),
            'bytes'     => strlen($text),
        ];
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function rpc(string $method, array $params = []): array
    {
        // Encode and decode the request the way the HTTP transport does.
        $request = json_decode((string) wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => self::$rpcId++,
            'method'  => $method,
            'params'  => (object) $params,
        ]), true);

        return pro_extended()->mcpServer()->handleRequest(is_array($request) ? $request : []);
    }

    public static function check(bool $ok, string $label, string $detail = ''): bool
    {
        $line = self::$section . ' > ' . $label;

        if ($ok) {
            self::$pass++;
            echo "PASS  {$line}\n";
        } else {
            self::$fail++;
            self::$failures[] = $line . ($detail !== '' ? ' — ' . $detail : '');
            echo "FAIL  {$line}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }

        return $ok;
    }

    public static function skip(string $label, string $why): void
    {
        self::$skip++;
        echo 'SKIP  ' . self::$section . " > {$label} — {$why}\n";
    }

    /**
     * Assert a successful tool call and return its data.
     *
     * @param  array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function ok(array $response, string $label): array
    {
        $good = ! $response['is_error'] && is_array($response['data']);
        self::check($good, $label, $good ? '' : substr($response['text'], 0, 400));

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function isError(array $response, string $label, string $contains = ''): bool
    {
        $good = $response['is_error'] && $response['rpc_error'] === null
            && ($contains === '' || stripos($response['text'], $contains) !== false);

        return self::check($good, $label, $good ? '' : 'got: ' . substr($response['text'], 0, 300));
    }

    public static function track(string $kind, int $id, string $title): void
    {
        foreach (self::$created as $item) {
            if ($item['id'] === $id && $item['kind'] === $kind) {
                return;
            }
        }

        self::$created[] = ['kind' => $kind, 'id' => $id, 'title' => $title];
    }

    /**
     * Normalize through JSON so {} and [] compare the way PHP stores them.
     */
    public static function canon(mixed $value): mixed
    {
        return json_decode((string) wp_json_encode($value), true);
    }

    public static function same(mixed $expected, mixed $actual, string $label): bool
    {
        $a = self::canon($expected);
        $b = self::canon($actual);

        return self::check($a === $b, $label, $a === $b ? '' : 'expected ' . substr((string) wp_json_encode($a), 0, 300) . ' got ' . substr((string) wp_json_encode($b), 0, 300));
    }

    /**
     * Every sent key must come back equal; extra stored keys must be in $allowedExtra.
     *
     * @param array<string, mixed> $sent
     * @param array<string, mixed> $stored
     * @param string[]             $allowedExtra
     */
    public static function settingsRoundTrip(array $sent, array $stored, array $allowedExtra, string $label): bool
    {
        $problems = [];

        foreach ($sent as $key => $value) {
            if (! array_key_exists($key, $stored)) {
                $problems[] = "missing {$key}";
            } elseif (self::canon($value) !== self::canon($stored[$key])) {
                $problems[] = "changed {$key}";
            }
        }

        $extra = array_diff(array_keys($stored), array_keys($sent), $allowedExtra);

        if ($extra !== []) {
            $problems[] = 'unexpected ' . implode(', ', $extra);
        }

        return self::check($problems === [], $label, implode('; ', $problems));
    }

    /**
     * An option exactly as stored.
     *
     * @return array{exists: bool, raw: string|null}
     */
    public static function rawOption(string $name): array
    {
        global $wpdb;

        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));

        return ['exists' => $raw !== null, 'raw' => $raw === null ? null : (string) $raw];
    }

    /**
     * Fetch a front-end URL with a cache-busting query string.
     *
     * @return array{code: int, body: string}
     */
    public static function fetch(string $path = '/'): array
    {
        $url = add_query_arg('pe_smoke', uniqid('', true), home_url($path));
        $response = wp_remote_get($url, ['timeout' => 45, 'redirection' => 3, 'headers' => ['Cache-Control' => 'no-cache']]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'body' => $response->get_error_message()];
        }

        return ['code' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
    }

    /**
     * A component-registry lookup straight from the tool.
     *
     * @return array<string, mixed>|null
     */
    public static function component(string $componentId): ?array
    {
        $data = self::call('list_components', ['search' => $componentId])['data'];

        foreach ((array) ($data['components'] ?? []) as $row) {
            if (($row['component_id'] ?? null) === $componentId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether $data has $key set to null (?? cannot tell null from missing).
     *
     * @param array<string, mixed> $data
     */
    public static function isNull(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && $data[$key] === null;
    }

    /**
     * A post field read from the database, bypassing every cache.
     */
    public static function postField(int $postId, string $field): ?string
    {
        global $wpdb;

        if (! in_array($field, ['post_type', 'post_title', 'post_name', 'post_status', 'post_content', 'post_parent'], true)) {
            return null;
        }

        $value = $wpdb->get_var($wpdb->prepare("SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d", $postId));

        return $value === null ? null : (string) $value;
    }

    /**
     * Record something a person must look at, whatever the test result.
     */
    public static function attention(string $message): void
    {
        self::$attention[] = $message;
        echo "PE_SMOKE_ATTENTION {$message}\n";
    }

    /**
     * The data get_layout returns for a post (the "data" key), or null.
     */
    public static function layout(int $postId): mixed
    {
        $response = self::call('get_layout', ['post_id' => $postId]);

        return is_array($response['data']) && array_key_exists('data', $response['data']) ? $response['data']['data'] : null;
    }

    /**
     * An option stored as slashed JSON (the way Cornerstone stores its
     * palette, fonts and font config), decoded.
     *
     * @return array<mixed>|null
     */
    public static function jsonOption(string $name): ?array
    {
        $raw = self::rawOption($name)['raw'];

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode(wp_unslash($raw), true);

        if (! is_array($decoded)) {
            $decoded = json_decode($raw, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cornerstone memoizes its component registry for the rest of a request.
     * Real MCP calls each run in their own request; this suite runs many in
     * one process, so drop the memo before rendering pages that use a
     * component created earlier in the run.
     */
    public static function forgetCornerstoneComponents(): void
    {
        if (! function_exists('cornerstone')) {
            return;
        }

        try {
            $service = cornerstone('Components');

            if (is_object($service) && property_exists($service, 'cache')) {
                (new \ReflectionProperty($service, 'cache'))->setValue($service, null);
            }
        } catch (\Throwable) {
            // Nothing to forget.
        }
    }

    /**
     * Run a WP-CLI command in a new process. Unlike WP_CLI::runcommand(), this
     * does not pass on this process's --user, so a command can be run
     * without one.
     *
     * @return array{code: int, stdout: string, output: string}
     */
    public static function cli(string $command): array
    {
        if (! function_exists('proc_open') || ! class_exists('WP_CLI') || empty($GLOBALS['argv'][0])) {
            return ['code' => -1, 'stdout' => '', 'output' => 'proc_open or WP-CLI is not available'];
        }

        $stderrFile = wp_tempnam('pe-smoke-stderr');
        $line = sprintf(
            '%s %s --path=%s %s 2>%s',
            escapeshellarg(\WP_CLI\Utils\get_php_binary()),
            escapeshellarg((string) $GLOBALS['argv'][0]),
            escapeshellarg(ABSPATH),
            $command,
            escapeshellarg($stderrFile)
        );

        $process = proc_open($line, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, getcwd() ?: null);

        if (! is_resource($process)) {
            unlink($stderrFile);

            return ['code' => -1, 'stdout' => '', 'output' => 'could not start the process'];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $stderr = (string) file_get_contents($stderrFile);
        unlink($stderrFile);

        return ['code' => $code, 'stdout' => $stdout, 'output' => trim($stdout . "\n" . $stderr)];
    }

    /**
     * Layout data as the create tools store it: with the migration and
     * breakpoint markers Cornerstone gives new elements.
     *
     * @param string $shape "tree" (a page), "regions" (a layout's regions map)
     *                      or "flat" (a component document's element map).
     */
    public static function stamped(mixed $data, string $shape = 'tree'): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $stamper = (new \ProExtended\Cornerstone\ElementContext(pro_extended()->schemaExtractor()))->stamper();

        return match ($shape) {
            'regions' => $stamper->stampRegions($data),
            'flat'    => $stamper->stampFlat($data),
            default   => $stamper->stampTree($data),
        };
    }

    public static function summary(): void
    {
        echo "\n== Created on this site (titled PE TEST; left in place) ==\n";

        foreach (self::$created as $item) {
            printf("  %-10s #%-6d %s\n", $item['kind'], $item['id'], $item['title']);
        }

        echo "\nPE_SMOKE_CREATED " . wp_json_encode(self::$created) . "\n";
        foreach (self::$attention as $message) {
            echo "\nATTENTION: {$message}\n";
        }

        printf("\n%d passed, %d failed, %d skipped\n", self::$pass, self::$fail, self::$skip);

        foreach (self::$failures as $failure) {
            echo "  - {$failure}\n";
        }

        echo self::$fail === 0 ? "PE_SMOKE_RESULT OK\n" : "PE_SMOKE_RESULT FAIL\n";
    }
}
