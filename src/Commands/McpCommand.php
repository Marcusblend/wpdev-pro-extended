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

            $items[] = [
                'name'        => $tool->name(),
                'description' => mb_substr($tool->description(), 0, 80) . (mb_strlen($tool->description()) > 80 ? '...' : ''),
                'capability'  => $tool->requiredCapability(),
                'params'      => implode(', ', array_keys($properties)),
                'required'    => implode(', ', $required),
            ];
        }

        $format = $assocArgs['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $items, ['name', 'description', 'capability', 'params', 'required']);
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
    }
}
