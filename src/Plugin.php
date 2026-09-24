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
        $this->doctorCommand()->register();
        $this->warmCommand()->register();
    }

    // ─── Service Accessors (Lazy-loaded) ─────────────────────────────────────

    public function mcpServer(): Mcp\Server
    {
        return $this->resolve('mcp.server', fn() => new Mcp\Server(
            $this->schemaExtractor(),
            $this->layoutService(),
            $this->documentGateway(),
            $this->settingsBackups(),
            $this->hostCache(),
        ));
    }

    public function mcpTransport(): Mcp\Transport\StreamableHttp
    {
        return $this->resolve('mcp.transport', fn() => new Mcp\Transport\StreamableHttp(
            $this->mcpServer(),
        ));
    }

    public function documentGateway(): Cornerstone\DocumentGateway
    {
        return $this->resolve('cornerstone.gateway', fn() => new Cornerstone\DocumentGateway());
    }

    public function layoutService(): Layouts\LayoutService
    {
        return $this->resolve('layouts.service', fn() => new Layouts\LayoutService(
            $this->documentGateway(),
        ));
    }

    public function settingsBackups(): Settings\SettingsBackups
    {
        return $this->resolve('settings.backups', fn() => new Settings\SettingsBackups(
            $this->documentGateway(),
        ));
    }

    public function hostCache(): Site\HostCache
    {
        return $this->resolve('site.host_cache', fn() => new Site\HostCache());
    }

    public function health(): Site\Health
    {
        return $this->resolve('site.health', fn() => new Site\Health(
            $this->documentGateway(),
            $this->hostCache(),
            $this->mcpServer(),
        ));
    }

    public function schemaExtractor(): Elements\SchemaExtractor
    {
        return $this->resolve('elements.schema', fn() => new Elements\SchemaExtractor());
    }

    public function elementContext(): Cornerstone\ElementContext
    {
        return $this->resolve('elements.context', fn() => new Cornerstone\ElementContext(
            $this->schemaExtractor(),
        ));
    }

    public function hierarchyValidator(): Elements\HierarchyValidator
    {
        return $this->resolve('elements.hierarchy', fn() => new Elements\HierarchyValidator(
            $this->schemaExtractor(),
            $this->documentGateway(),
            $this->elementContext(),
        ));
    }

    public function layoutCommand(): Commands\LayoutCommand
    {
        return $this->resolve('commands.layout', fn() => new Commands\LayoutCommand(
            $this->layoutService(),
            $this->hierarchyValidator(),
        ));
    }

    public function mcpCommand(): Commands\McpCommand
    {
        return $this->resolve('commands.mcp', fn() => new Commands\McpCommand(
            $this->mcpServer(),
        ));
    }

    public function doctorCommand(): Commands\DoctorCommand
    {
        return $this->resolve('commands.doctor', fn() => new Commands\DoctorCommand(
            $this->health(),
        ));
    }

    public function warmCommand(): Commands\WarmCommand
    {
        return $this->resolve('commands.warm', fn() => new Commands\WarmCommand(
            $this->schemaExtractor(),
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
