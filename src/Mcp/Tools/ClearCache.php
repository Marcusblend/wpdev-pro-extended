<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Site\HostCache;
use ProExtended\Support\Args;

final class ClearCache implements ToolInterface, AnnotatedToolInterface
{
    private const INCLUDES = ['tss', 'generated_styles', 'components', 'assignments', 'elements', 'host'];

    private readonly DocumentGateway $gateway;
    private readonly HostCache $hostCache;
    private readonly SchemaExtractor $schema;

    public function __construct(?DocumentGateway $gateway = null, ?HostCache $hostCache = null, ?SchemaExtractor $schema = null)
    {
        $this->gateway = $gateway ?? new DocumentGateway();
        $this->hostCache = $hostCache ?? new HostCache();
        $this->schema = $schema ?? new SchemaExtractor();
    }

    public function name(): string
    {
        return 'clear_cache';
    }

    public function description(): string
    {
        return 'Clear Cornerstone caches for a specific post or site-wide. include picks what to clear: tss (compiled CSS), generated_styles (all generated styles and Cornerstone\'s temporary caches), components (component registry), assignments (header/footer/layout assignment rules), host (WP Engine page cache for the post, or all pages plus the object cache). Default: tss with post_id; tss and generated_styles without. The response lists what ran and what was unavailable.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Post ID to clear cache for. If omitted, clears all TSS caches.',
                ],
                'include' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string', 'enum' => self::INCLUDES],
                    'description' => 'Optional. What to clear.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId = isset($arguments['post_id']) ? (int) $arguments['post_id'] : null;
        $postId = ($postId !== null && $postId > 0) ? $postId : null;

        $include = Args::list($arguments, 'include');

        if ($include === null) {
            $include = $postId !== null ? ['tss'] : ['tss', 'generated_styles'];
        }

        foreach ($include as $item) {
            if (! is_string($item) || ! in_array($item, self::INCLUDES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown include value %s. Allowed: %s.',
                    is_string($item) ? '"' . $item . '"' : gettype($item),
                    implode(', ', self::INCLUDES)
                ));
            }
        }

        $include = array_values(array_unique($include));
        $ran = [];
        $unavailable = [];
        $entries = 0;

        foreach ($include as $item) {
            switch ($item) {
                case 'tss':
                    if ($postId !== null) {
                        delete_post_meta($postId, '_cs_generated_tss');
                        $ran[] = 'tss: post ' . $postId;
                    } else {
                        $entries = $this->countMeta('_cs_generated_tss');
                        delete_post_meta_by_key('_cs_generated_tss');
                        $ran[] = sprintf('tss: %d entries', $entries);
                    }
                    break;

                case 'generated_styles':
                    $purge = $this->gateway->purgeGenerated();
                    $ran[] = sprintf('generated_styles (%s)', $purge['path']);
                    break;

                case 'components':
                    $ran[] = sprintf('components (%s)', $this->gateway->purgeComponents()['path']);
                    break;

                case 'assignments':
                    $ran[] = sprintf('assignments (%s)', $this->gateway->clearAssignments()['path']);
                    break;

                case 'elements':
                    // Element definitions and the Inspector control surface are
                    // cached for an hour; a Cornerstone update changes both.
                    $this->schema->clearCache();
                    $ran[] = 'elements: definitions and control surfaces';
                    break;

                case 'host':
                    $host = $this->hostCache->purge($postId);
                    $ran = array_merge($ran, $host['ran']);
                    $unavailable = array_merge($unavailable, $host['unavailable']);
                    break;
            }
        }

        if ($postId !== null) {
            return [
                'cleared'     => true,
                'scope'       => 'post',
                'post_id'     => $postId,
                'ran'         => $ran,
                'unavailable' => $unavailable,
            ];
        }

        return [
            'cleared'         => true,
            'scope'           => 'global',
            'entries_cleared' => $entries,
            'ran'             => $ran,
            'unavailable'     => $unavailable,
        ];
    }

    private function countMeta(string $key): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $key
        ));
    }

    public function annotations(): array
    {
        return Annotations::write('Clear Cache', false, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
