<?php

declare(strict_types=1);

namespace ProExtended\Commands;

use ProExtended\Mcp\Server;

/**
 * WP-CLI commands for MCP server management and testing.
 *
 * ## EXAMPLES
 *
 *     wp pe mcp tools
 *     wp pe mcp call get_site_info
 *     wp pe mcp call list_elements --args='{"group":"content"}'
 *     wp pe mcp call create_document --args='{"type":"header","title":"Main"}' --user=1
 */
final class McpCommand
{
    public function __construct(
        private readonly Server $server,
    ) {}

    /**
     * Register the CLI commands.
     */
    public function register(): void
    {
        \WP_CLI::add_command('pe mcp', $this);
    }

    /**
     * List all registered MCP tools.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp pe mcp tools
     *     wp pe mcp tools --format=json
     *
     * @subcommand tools
     */
    public function tools(array $args, array $assocArgs): void
    {
        $tools = $this->server->getTools();

        $items = [];
        foreach ($tools as $tool) {
            $schema = $tool->inputSchema();
            $required = $schema['required'] ?? [];

            $properties = $schema['properties'] ?? [];
            if ($properties instanceof \stdClass) {
                $properties = (array) $properties;
            }

            $annotations = $this->server->annotationsFor($tool);

            $items[] = [
                'name'        => $tool->name(),
                'description' => mb_substr($tool->description(), 0, 80) . (mb_strlen($tool->description()) > 80 ? '...' : ''),
                'capability'  => $tool->requiredCapability(),
                'read_only'   => ($annotations['readOnlyHint'] ?? false) ? 'yes' : 'no',
                'params'      => implode(', ', array_keys($properties)),
                'required'    => implode(', ', $required),
            ];
        }

        $format = $assocArgs['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $items, ['name', 'description', 'capability', 'read_only', 'params', 'required']);
    }

    /**
     * Call an MCP tool directly via CLI.
     *
     * ## OPTIONS
     *
     * <tool>
     * : Tool name to call.
     *
     * [--args=<json>]
     * : Tool arguments as JSON string.
     *
     * ## EXAMPLES
     *
     *     wp pe mcp call get_site_info
     *     wp pe mcp call list_elements --args='{"group":"content"}'
     *     wp pe mcp call get_layout --args='{"post_id":42}'
     *     wp pe mcp call deploy_layout --args='{"post_id":42,"layout_data":[]}' --user=1
     *
     * Write tools need --user=<ID> of a user with unfiltered_html. A tool
     * that fails prints its message and exits with status 1.
     *
     * @subcommand call
     */
    public function call(array $args, array $assocArgs): void
    {
        $toolName = $args[0] ?? '';

        if (empty($toolName)) {
            \WP_CLI::error('Tool name is required.');
        }

        $argsJson = $assocArgs['args'] ?? '{}';
        $arguments = json_decode($argsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \WP_CLI::error('Invalid JSON args: ' . json_last_error_msg());
        }

        $tools = $this->server->getTools();

        if (isset($tools[$toolName])) {
            $annotations = $this->server->annotationsFor($tools[$toolName]);
            $readOnly = ($annotations['readOnlyHint'] ?? false) === true;

            // Without a user, WordPress filters post_content (kses) and damages
            // Cornerstone's JSON, so write tools need a real user.
            if (! $readOnly && ! current_user_can('unfiltered_html')) {
                \WP_CLI::error(sprintf(
                    '"%s" writes data. Run it with --user=<ID> of a user who has the unfiltered_html capability (an administrator).',
                    $toolName
                ));
            }
        }

        // Build a JSON-RPC request and send it through the server.
        $request = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $toolName,
                'arguments' => $arguments,
            ],
        ];

        $response = $this->server->handleRequest($request);

        if (isset($response['error'])) {
            \WP_CLI::error(sprintf(
                'Error %d: %s',
                $response['error']['code'] ?? 0,
                $response['error']['message'] ?? 'Unknown error'
            ));
            return;
        }

        // Extract the content text from the MCP response.
        $result = $response['result'] ?? [];
        $content = $result['content'] ?? [];

        foreach ($content as $item) {
            if ($item['type'] === 'text') {
                echo $item['text'] . "\n";
            }
        }

        // A tool that failed while running reports isError; exit non-zero.
        if (! empty($result['isError'])) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Test MCP endpoint availability.
     *
     * ## EXAMPLES
     *
     *     wp pe mcp test
     *
     * @subcommand test
     */
    public function test(array $args, array $assocArgs): void
    {
        // Test initialize.
        $request = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => (object) [],
                'clientInfo'      => [
                    'name'    => 'pe-cli-test',
                    'version' => '1.0',
                ],
            ],
        ];

        $response = $this->server->handleRequest($request);

        if (isset($response['error'])) {
            \WP_CLI::error('MCP initialize failed: ' . ($response['error']['message'] ?? 'Unknown error'));
            return;
        }

        $serverInfo = $response['result']['serverInfo'] ?? [];

        \WP_CLI::success(sprintf(
            'MCP server is operational: %s v%s (protocol %s)',
            $serverInfo['name'] ?? 'unknown',
            $serverInfo['version'] ?? 'unknown',
            $response['result']['protocolVersion'] ?? 'unknown'
        ));

        // Count tools.
        $tools = $this->server->getTools();
        \WP_CLI::log(sprintf('Registered tools: %d', count($tools)));

        foreach ($this->server->getRegistrationErrors() as $name => $message) {
            \WP_CLI::warning(sprintf('Not registered: %s (%s)', $name, $message));
        }
    }
}
