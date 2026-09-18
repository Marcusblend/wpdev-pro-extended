<?php

declare(strict_types=1);

namespace ProExtended\Mcp;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Elements\HierarchyValidator;
use ProExtended\Cornerstone\Permissions;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Site\PlatformSnapshot;
use ProExtended\Site\WriteJournal;
use ProExtended\Templates\TemplateGateway;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\Resources\ResourceInterface;
use ProExtended\Mcp\Tools\AnnotatedToolInterface;
use ProExtended\Mcp\Tools\ToolInterface;
use ProExtended\Menus\MenuGateway;
use ProExtended\Media\MediaImporter;
use ProExtended\Settings\ReferenceScanner;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\ThemeOptionsReader;
use ProExtended\Site\Health;
use ProExtended\Site\HostCache;
use ProExtended\Support\Json;
use ProExtended\Support\JsonArgs;

/**
 * MCP Server — JSON-RPC 2.0 router.
 *
 * Implements the MCP protocol (2025-03-26) subset required for stateless tool calls:
 *   - initialize
 *   - tools/list
 *   - tools/call
 *   - resources/list
 *   - resources/read
 *
 * Protocol problems (unknown tool, missing tool name, a caller without the
 * tool's required capability) are JSON-RPC errors. Anything that goes wrong
 * while a tool runs is a normal result with `isError: true`, as the MCP spec
 * requires, so the model sees the message.
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

    private const INSTRUCTIONS = <<<'TXT'
Pro Extended reads and writes Cornerstone (Pro theme) sites.
- Every write backs up first: undo layout writes with restore_layout and settings writes with restore_settings.
- Never pass skip_validation or skip_backup.
- Run every set_* tool with dry_run: true first and review what would change.
- References inside layouts: colors "global-color:<_id>" (with alpha "global-color:<_id>:0.5"), font family "global-ff:<_id>", font weight "global-fw:<_id>|fw-normal" or "global-fw:<_id>|fw-bold", images "<attachment_id>:full", menus "menu:<term_id>".
- Call list_components before composing component instances ({"_type": "component", "component_id": "<_c_id>", "_p_data": {...}}).
- For large documents call get_layout with summary: true first, then fetch one subtree with path.
TXT;

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, ResourceInterface> */
    private array $resources = [];

    /** @var bool Whether tools have been registered. */
    private bool $toolsRegistered = false;

    /** @var array<string, string> Tool or resource name => why it was not registered. */
    private array $registrationErrors = [];

    public function __construct(
        private readonly SchemaExtractor $schema,
        private readonly LayoutService $layouts,
        private readonly ?DocumentGateway $gateway = null,
        private readonly ?SettingsBackups $backups = null,
        private readonly ?HostCache $hostCache = null,
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

        if (! is_array($params)) {
            $params = [];
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

    /**
     * Tools and resources that failed to register, with the reason.
     *
     * @return array<string, string>
     */
    public function getRegistrationErrors(): array
    {
        $this->ensureToolsRegistered();
        return $this->registrationErrors;
    }

    /**
     * MCP annotations for a tool (empty for tools that declare none).
     *
     * @return array<string, mixed>
     */
    public function annotationsFor(ToolInterface $tool): array
    {
        if (! $tool instanceof AnnotatedToolInterface) {
            return [];
        }

        try {
            return $tool->annotations();
        } catch (\Throwable) {
            return [];
        }
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
            'instructions' => self::INSTRUCTIONS,
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
            $entry = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];

            $annotations = $this->annotationsFor($tool);

            if ($annotations !== []) {
                // 2025-03-26 reads the title from annotations; newer clients
                // read a top-level title.
                if (isset($annotations['title'])) {
                    $entry['title'] = $annotations['title'];
                }

                $entry['annotations'] = $annotations;
            }

            $toolList[] = $entry;
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

        // Then Cornerstone's own, which a site can take away separately.
        $denial = (new Permissions())->denialFor($toolName);

        if ($denial !== null) {
            return $this->error($id, self::ERR_INVALID_REQ, $denial);
        }

        try {
            if (is_string($arguments)) {
                $arguments = JsonArgs::decodeValue($arguments, 'arguments');
            }

            if (! is_array($arguments)) {
                throw new \InvalidArgumentException('Tool arguments must be an object.');
            }

            $result = $tool->execute($arguments);

            // Record what changed, so a site can be asked later. A tool that
            // only reads is skipped, and the journal never fails a write.
            if ($tool instanceof AnnotatedToolInterface && empty($tool->annotations()['readOnlyHint'])) {
                (new WriteJournal())->record($toolName, $arguments, $result);
            }

            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => is_string($result) ? $result : Json::pretty($result),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->toolError($id, $toolName, $e);
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
                        'text'     => is_string($content) ? $content : Json::pretty($content),
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
     *
     * Each tool is registered on its own: if one cannot be constructed (for
     * example because a class it needs is missing), it is logged and skipped
     * and every other tool keeps working.
     */
    private function ensureToolsRegistered(): void
    {
        if ($this->toolsRegistered) {
            return;
        }

        $this->toolsRegistered = true;

        $gateway   = $this->gateway ?? $this->layouts->gateway();
        $backups   = $this->backups ?? new SettingsBackups($gateway);
        $hostCache = $this->hostCache ?? new HostCache();
        $schema    = $this->schema;
        $layouts   = $this->layouts;
        $elements  = new ElementContext($schema);
        $templates = new TemplateGateway();

        // The validator memoizes the hierarchy map, so share one instance rather
        // than rebuilding it per tool.
        $sharedValidator = null;
        $validator = static function () use (&$sharedValidator, $schema, $gateway, $elements): HierarchyValidator {
            return $sharedValidator ??= new HierarchyValidator($schema, $gateway, $elements);
        };

        $factories = [
            // Read tools.
            'list_elements'         => static fn() => new Tools\ListElements($schema),
            'get_element_schema'    => static fn() => new Tools\GetElementSchema($schema),
            'list_layouts'          => static fn() => new Tools\ListLayouts($layouts),
            'get_layout'            => static fn() => new Tools\GetLayout($layouts),
            'validate_layout'       => static fn() => new Tools\ValidateLayout($validator()),
            'list_colors'           => static fn() => new Tools\ListColors(),
            'list_fonts'            => static fn() => new Tools\ListFonts(),
            'get_site_info'         => fn() => new Tools\GetSiteInfo(new Health($gateway, $hostCache, $this)),
            'list_templates'        => static fn() => new Tools\ListTemplates($templates),
            'get_template'          => static fn() => new Tools\GetTemplate($templates),
            'export_tco'            => static fn() => new Tools\ExportTco($templates),
            'get_platform_baseline' => static fn() => new Tools\GetPlatformBaseline(new PlatformSnapshot($schema, $gateway, $elements)),
            'list_prefabs'          => static fn() => new Tools\ListPrefabs(new \ProExtended\Cornerstone\Prefabs()),
            'list_dynamic_content'  => static fn() => new Tools\ListDynamicContent(new \ProExtended\Cornerstone\DynamicContentCatalog()),
            'get_write_journal'     => static fn() => new Tools\GetWriteJournal(new WriteJournal()),

            // Write tools.
            'create_page'           => static fn() => new Tools\CreatePage($layouts, $validator(), $elements),
            'deploy_layout'         => static fn() => new Tools\DeployLayout($layouts, $validator(), $elements),
            'backup_layout'         => static fn() => new Tools\BackupLayout($layouts),
            'restore_layout'        => static fn() => new Tools\RestoreLayout($layouts),
            'clear_cache'           => static fn() => new Tools\ClearCache($gateway, $hostCache, $schema),
            'update_layout'         => static fn() => new Tools\UpdateLayout($layouts, $validator(), $elements, $templates),

            // Site foundations (1.1.0).
            'create_document'          => static fn() => new Tools\CreateDocument($layouts, $validator(), $elements),
            'update_document_settings' => static fn() => new Tools\UpdateDocumentSettings($layouts),
            'list_components'          => static fn() => new Tools\ListComponents($gateway),
            'get_global_css'           => static fn() => new Tools\GetGlobalCss($gateway),
            'set_global_css'           => static fn() => new Tools\SetGlobalCss($gateway, $backups),
            'set_colors'               => static fn() => new Tools\SetColors($gateway, $backups, new ReferenceScanner(new ThemeOptionsReader())),
            'set_fonts'                => static fn() => new Tools\SetFonts($gateway, $backups, new ReferenceScanner(new ThemeOptionsReader())),
            'upload_media'             => static fn() => new Tools\UploadMedia(new MediaImporter()),
            'list_menus'               => static fn() => new Tools\ListMenus(),
            'update_theme_options'     => static fn() => new Tools\UpdateThemeOptions($gateway, $backups, new ThemeOptionsReader()),
            'set_variables'            => static fn() => new Tools\SetVariables($gateway, $backups),
            'set_global_parameters'    => static fn() => new Tools\SetGlobalParameters($gateway, $backups, $elements),
            'create_template'          => static fn() => new Tools\CreateTemplate($templates, $schema, $layouts),
            'import_tco'               => static fn() => new Tools\ImportTco($templates, $schema),
            'create_translation'       => static fn() => new Tools\CreateTranslation($gateway),
            'create_component'         => static fn() => new Tools\CreateComponent($layouts, $validator(), $elements),
            'create_menu'              => static fn() => new Tools\CreateMenu(new MenuGateway()),
            'update_menu'              => static fn() => new Tools\UpdateMenu(new MenuGateway()),
            'list_settings_backups'    => static fn() => new Tools\ListSettingsBackups($backups),
            'restore_settings'         => static fn() => new Tools\RestoreSettings($backups),

            // Builder parity (1.2.0).
            'get_theme_options'        => static fn() => new Tools\GetThemeOptions(new ThemeOptionsReader(), $elements),
        ];

        foreach ($factories as $name => $factory) {
            try {
                $this->registerTool($factory());
            } catch (\Throwable $e) {
                $this->registrationErrors[$name] = $e->getMessage();
                error_log(sprintf('[Pro Extended] MCP tool "%s" was not registered: %s', $name, $e->getMessage()));
            }
        }

        $resources = [
            'pe://schema/elements'  => static fn() => new Resources\ElementSchemaResource($schema),
            'pe://schema/hierarchy' => static fn() => new Resources\HierarchyResource($schema),
            'pe://colors/palette'   => static fn() => new Resources\ColorPaletteResource(),
        ];

        foreach ($resources as $uri => $factory) {
            try {
                $this->registerResource($factory());
            } catch (\Throwable $e) {
                $this->registrationErrors[$uri] = $e->getMessage();
                error_log(sprintf('[Pro Extended] MCP resource "%s" was not registered: %s', $uri, $e->getMessage()));
            }
        }

        /**
         * Fires after the built-in tools are registered, so extensions can add
         * their own with $server->registerTool().
         */
        do_action('pe_mcp_register_tools', $this);
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
     * Build a tool result that reports a failure (`isError: true`).
     *
     * @param  mixed $id
     * @return array<string, mixed>
     */
    private function toolError(mixed $id, string $toolName, \Throwable $e): array
    {
        if ($e instanceof \Error) {
            // A PHP error is a bug rather than bad input; keep it in the log.
            error_log(sprintf('[Pro Extended] %s in tool "%s": %s', get_class($e), $toolName, $e->getMessage()));
            $message = sprintf('Internal error in %s: %s', $toolName, $e->getMessage());
        } else {
            $message = $e->getMessage();
        }

        return $this->success($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message !== '' ? $message : 'The tool failed without a message.',
                ],
            ],
            'isError' => true,
        ]);
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
