<?php

declare(strict_types=1);

namespace ProExtended\Mcp;

use ProExtended\Elements\SchemaExtractor;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\Tools\ToolInterface;
use ProExtended\Mcp\Resources\ResourceInterface;

/**
 * MCP Server — JSON-RPC 2.0 router.
 *
 * Implements the MCP protocol (2025-03-26) subset required for stateless tool calls:
 *   - initialize
 *   - tools/list
 *   - tools/call
 *   - resources/list
 *   - resources/read
 */
final class Server
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const SERVER_NAME     = 'pro-extended';
    private const SERVER_VERSION  = PE_VERSION;

    // JSON-RPC 2.0 error codes.
    private const ERR_PARSE       = -32700;
    private const ERR_INVALID_REQ = -32600;
    private const ERR_NOT_FOUND   = -32601;
    private const ERR_INVALID_PAR = -32602;
    private const ERR_INTERNAL    = -32603;

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, ResourceInterface> */
    private array $resources = [];

    /** @var bool Whether tools have been registered. */
    private bool $toolsRegistered = false;

    public function __construct(
        private readonly SchemaExtractor $schema,
        private readonly LayoutService $layouts,
    ) {}

    /**
     * Process a JSON-RPC 2.0 request and return the response.
     *
     * @param  array<string, mixed> $request Parsed JSON-RPC request.
     * @return array<string, mixed> JSON-RPC response.
     */
    public function handleRequest(array $request): array
    {
        $this->ensureToolsRegistered();

        $jsonrpc = $request['jsonrpc'] ?? '';
        $method  = $request['method'] ?? '';
        $params  = $request['params'] ?? [];
        $id      = $request['id'] ?? null;

        // Validate JSON-RPC version.
        if ($jsonrpc !== '2.0') {
            return $this->error($id, self::ERR_INVALID_REQ, 'Invalid JSON-RPC version. Expected "2.0".');
        }

        // Notifications (no id) — acknowledge but don't process.
        if ($id === null) {
            return []; // No response for notifications per JSON-RPC spec.
        }

        if (! is_string($method) || $method === '') {
            return $this->error($id, self::ERR_INVALID_REQ, 'Missing or invalid method.');
        }

        return match ($method) {
            'initialize'     => $this->handleInitialize($id, $params),
            'tools/list'     => $this->handleToolsList($id),
            'tools/call'     => $this->handleToolsCall($id, $params),
            'resources/list' => $this->handleResourcesList($id),
            'resources/read' => $this->handleResourcesRead($id, $params),
            'ping'           => $this->success($id, []),
            default          => $this->error($id, self::ERR_NOT_FOUND, sprintf('Method "%s" not found.', $method)),
        };
    }

    /**
     * Register a tool.
     */
    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Register a resource.
     */
    public function registerResource(ResourceInterface $resource): void
    {
        $this->resources[$resource->uri()] = $resource;
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, ToolInterface>
     */
    public function getTools(): array
    {
        $this->ensureToolsRegistered();
        return $this->tools;
    }

    // ─── MCP Method Handlers ─────────────────────────────────────────────────

    /**
     * Handle initialize — MCP handshake.
     *
     * @param  mixed               $id
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        return $this->success($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => (object) [],
                'resources' => (object) [],
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => PE_VERSION,
            ],
        ]);
    }

    /**
     * Handle tools/list — list all registered tools.
     *
     * @param  mixed $id
     * @return array<string, mixed>
     */
    private function handleToolsList(mixed $id): array
    {
        $toolList = [];

        foreach ($this->tools as $tool) {
            $toolList[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return $this->success($id, ['tools' => $toolList]);
    }

    /**
     * Handle tools/call — execute a tool.
     *
     * @param  mixed               $id
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (! is_string($toolName) || $toolName === '') {
            return $this->error($id, self::ERR_INVALID_PAR, 'Missing tool name.');
        }

        if (! isset($this->tools[$toolName])) {
            return $this->error($id, self::ERR_NOT_FOUND, sprintf('Tool "%s" not found.', $toolName));
        }

        $tool = $this->tools[$toolName];

        // Check WordPress capability.
        if (! current_user_can($tool->requiredCapability())) {
            return $this->error($id, self::ERR_INVALID_REQ, sprintf(
                'Insufficient permissions. Tool "%s" requires capability "%s".',
                $toolName,
                $tool->requiredCapability()
            ));
        }

        try {
            $result = $tool->execute($arguments);

            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => is_string($result) ? $result : wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, self::ERR_INVALID_PAR, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, self::ERR_INTERNAL, $e->getMessage());
        }
    }

    /**
     * Handle resources/list.
     *
     * @param  mixed $id
     * @return array<string, mixed>
     */
    private function handleResourcesList(mixed $id): array
    {
        $resourceList = [];

        foreach ($this->resources as $resource) {
            $resourceList[] = [
                'uri'         => $resource->uri(),
                'name'        => $resource->name(),
                'description' => $resource->description(),
                'mimeType'    => $resource->mimeType(),
            ];
        }

        return $this->success($id, ['resources' => $resourceList]);
    }

    /**
     * Handle resources/read.
     *
     * @param  mixed               $id
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleResourcesRead(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? '';

        if (! is_string($uri) || $uri === '') {
            return $this->error($id, self::ERR_INVALID_PAR, 'Missing resource URI.');
        }

        if (! isset($this->resources[$uri])) {
            return $this->error($id, self::ERR_NOT_FOUND, sprintf('Resource "%s" not found.', $uri));
        }

        $resource = $this->resources[$uri];

        try {
            $content = $resource->read();

            return $this->success($id, [
                'contents' => [
                    [
                        'uri'      => $resource->uri(),
                        'mimeType' => $resource->mimeType(),
                        'text'     => is_string($content) ? $content : wp_json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($id, self::ERR_INTERNAL, $e->getMessage());
        }
    }

    // ─── Tool Registration (Lazy) ────────────────────────────────────────────

    /**
     * Register all built-in tools and resources if not yet done.
     */
    private function ensureToolsRegistered(): void
    {
        if ($this->toolsRegistered) {
            return;
        }

        $this->toolsRegistered = true;

        // Read tools.
        $this->registerTool(new Tools\ListElements($this->schema));
        $this->registerTool(new Tools\GetElementSchema($this->schema));
        $this->registerTool(new Tools\ListLayouts($this->layouts));
        $this->registerTool(new Tools\GetLayout($this->layouts));
        $this->registerTool(new Tools\ValidateLayout(
            new \ProExtended\Elements\HierarchyValidator($this->schema),
        ));
        $this->registerTool(new Tools\ListColors());
        $this->registerTool(new Tools\ListFonts());
        $this->registerTool(new Tools\GetSiteInfo());

        // Write tools.
        $this->registerTool(new Tools\CreatePage($this->layouts));
        $this->registerTool(new Tools\DeployLayout($this->layouts, new \ProExtended\Elements\HierarchyValidator($this->schema)));
        $this->registerTool(new Tools\BackupLayout($this->layouts));
        $this->registerTool(new Tools\RestoreLayout($this->layouts));
        $this->registerTool(new Tools\ClearCache());
        $this->registerTool(new Tools\UpdateLayout($this->layouts));

        // Resources.
        $this->registerResource(new Resources\ElementSchemaResource($this->schema));
        $this->registerResource(new Resources\HierarchyResource($this->schema));
        $this->registerResource(new Resources\ColorPaletteResource());
    }

    // ─── Response Builders ───────────────────────────────────────────────────

    /**
     * Build a JSON-RPC 2.0 success response.
     *
     * @param  mixed               $id
     * @param  array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function success(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    /**
     * Build a JSON-RPC 2.0 error response.
     *
     * @param  mixed  $id
     * @param  int    $code
     * @param  string $message
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}
