<?php

declare(strict_types=1);

namespace ProExtended;

/**
 * Main plugin class — lightweight service container and module bootstrapper.
 */
final class Plugin
{
    /** @var array<string, object> Resolved service instances. */
    private array $services = [];

    /** @var bool Whether the plugin has been booted. */
    private bool $booted = false;

    /**
     * Boot the plugin: register all modules.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Register REST API routes (MCP endpoint).
        add_action('rest_api_init', [$this, 'registerRoutes']);

        // Register WP-CLI commands.
        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCommands();
        }
    }

    /**
     * Register REST API routes.
     */
    public function registerRoutes(): void
    {
        $this->mcpTransport()->register();
    }

    /**
     * Register WP-CLI commands.
     */
    public function registerCommands(): void
    {
        $this->layoutCommand()->register();
        $this->mcpCommand()->register();
    }

    // ─── Service Accessors (Lazy-loaded) ─────────────────────────────────────

    public function mcpServer(): Mcp\Server
    {
        return $this->resolve('mcp.server', fn() => new Mcp\Server(
            $this->schemaExtractor(),
            $this->layoutService(),
        ));
    }

    public function mcpTransport(): Mcp\Transport\StreamableHttp
    {
        return $this->resolve('mcp.transport', fn() => new Mcp\Transport\StreamableHttp(
            $this->mcpServer(),
        ));
    }

    public function layoutService(): Layouts\LayoutService
    {
        return $this->resolve('layouts.service', fn() => new Layouts\LayoutService());
    }

    public function schemaExtractor(): Elements\SchemaExtractor
    {
        return $this->resolve('elements.schema', fn() => new Elements\SchemaExtractor());
    }

    public function hierarchyValidator(): Elements\HierarchyValidator
    {
        return $this->resolve('elements.hierarchy', fn() => new Elements\HierarchyValidator(
            $this->schemaExtractor(),
        ));
    }

    public function layoutCommand(): Commands\LayoutCommand
    {
        return $this->resolve('commands.layout', fn() => new Commands\LayoutCommand(
            $this->layoutService(),
        ));
    }

    public function mcpCommand(): Commands\McpCommand
    {
        return $this->resolve('commands.mcp', fn() => new Commands\McpCommand(
            $this->mcpServer(),
        ));
    }

    // ─── Container ───────────────────────────────────────────────────────────

    /**
     * Resolve a service by key, creating it if necessary.
     *
     * @template T of object
     * @param  string          $key
     * @param  callable(): T   $factory
     * @return T
     */
    private function resolve(string $key, callable $factory): object
    {
        if (! isset($this->services[$key])) {
            $this->services[$key] = $factory();
        }

        return $this->services[$key];
    }
}
