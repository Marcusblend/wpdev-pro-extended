<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Transport;

use ProExtended\Mcp\Server;

/**
 * Streamable HTTP transport for the MCP server.
 *
 * Registers a WP REST API endpoint that receives JSON-RPC 2.0 messages via POST
 * and returns JSON responses. Authentication is handled by WordPress Application
 * Passwords via the native REST API Basic Auth filter.
 */
final class StreamableHttp
{
    private const NAMESPACE = 'pro-extended/v1';
    private const ROUTE     = '/mcp';

    public function __construct(
        private readonly Server $server,
    ) {}

    /**
     * Register the REST API route.
     */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [$this, 'handlePost'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods'             => \WP_REST_Server::READABLE, // GET (SSE — future)
                'callback'            => [$this, 'handleGet'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);
    }

    /**
     * Handle POST requests (JSON-RPC 2.0 messages).
     */
    public function handlePost(\WP_REST_Request $request): \WP_REST_Response
    {
        $contentType = $request->get_content_type();

        // Validate Content-Type is JSON.
        if (! $contentType || ! in_array($contentType['value'], ['application/json', 'application/json; charset=utf-8', 'application/json;charset=utf-8'], true)) {
            // Try to be lenient about content-type.
            $body = $request->get_body();
            if (empty($body)) {
                return new \WP_REST_Response([
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => [
                        'code'    => -32700,
                        'message' => 'Empty request body.',
                    ],
                ], 400);
            }
        }

        $body = $request->get_body();

        // Parse JSON body.
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    'code'    => -32700,
                    'message' => 'Parse error: ' . json_last_error_msg(),
                ],
            ], 400);
        }

        // Check for batch request (array of requests).
        if (isset($decoded[0])) {
            $responses = [];
            foreach ($decoded as $singleRequest) {
                if (is_array($singleRequest)) {
                    $response = $this->server->handleRequest($singleRequest);
                    if (! empty($response)) {
                        $responses[] = $response;
                    }
                }
            }
            return new \WP_REST_Response($responses, 200);
        }

        // Single request.
        $response = $this->server->handleRequest($decoded);

        if (empty($response)) {
            // Notification — return 202 Accepted with no body.
            return new \WP_REST_Response(null, 202);
        }

        return new \WP_REST_Response($response, 200);
    }

    /**
     * Handle GET requests (SSE stream — future implementation).
     *
     * For v1.0, we return 405 Method Not Allowed as we don't support SSE yet.
     */
    public function handleGet(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => -32601,
                'message' => 'SSE streaming is not supported in this version. Use POST for all requests.',
            ],
        ], 405);
    }

    /**
     * Check if the current user is authorized.
     *
     * Requires the user to be logged in with at least `edit_posts` capability.
     * Individual tools may require additional capabilities.
     */
    public function checkPermission(\WP_REST_Request $request): bool
    {
        return current_user_can('edit_posts');
    }
}
