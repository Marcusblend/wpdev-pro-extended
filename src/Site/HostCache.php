<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * Host page and object cache purging (WP Engine).
 */
final class HostCache
{
    private const WPE_CLASS = 'WpeCommon';

    /**
     * Which purge methods the host provides.
     *
     * @return array{host: string|null, methods: string[]}
     */
    public function availability(): array
    {
        if (! class_exists(self::WPE_CLASS)) {
            return ['host' => null, 'methods' => []];
        }

        $methods = array_values(array_filter(
            ['purge_varnish_cache', 'purge_varnish_cache_all', 'purge_memcached'],
            static fn(string $method): bool => method_exists(self::WPE_CLASS, $method)
        ));

        return ['host' => 'wp-engine', 'methods' => $methods];
    }

    public function available(): bool
    {
        return $this->availability()['methods'] !== [];
    }

    /**
     * Purge the host cache for one post, or all pages plus the object cache.
     *
     * @return array{ran: string[], unavailable: string[]}
     */
    public function purge(?int $postId): array
    {
        $ran = [];
        $unavailable = [];
        $class = self::WPE_CLASS;

        if (! class_exists($class)) {
            return ['ran' => [], 'unavailable' => ['host: no supported host cache (WP Engine) detected']];
        }

        if ($postId !== null && $postId > 0) {
            if (method_exists($class, 'purge_varnish_cache')) {
                $class::purge_varnish_cache($postId, true);
                $ran[] = 'host: page cache for post ' . $postId;
            } else {
                $unavailable[] = 'host: WpeCommon::purge_varnish_cache()';
            }

            return ['ran' => $ran, 'unavailable' => $unavailable];
        }

        if (method_exists($class, 'purge_varnish_cache_all')) {
            $class::purge_varnish_cache_all();
            $ran[] = 'host: page cache (all pages)';
        } else {
            $unavailable[] = 'host: WpeCommon::purge_varnish_cache_all()';
        }

        if (method_exists($class, 'purge_memcached')) {
            $class::purge_memcached();
            $ran[] = 'host: object cache';
        } else {
            $unavailable[] = 'host: WpeCommon::purge_memcached()';
        }

        return ['ran' => $ran, 'unavailable' => $unavailable];
    }
}
