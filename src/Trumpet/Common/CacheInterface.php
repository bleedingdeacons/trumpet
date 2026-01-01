<?php

declare(strict_types=1);

namespace Trumpet\Common;

/**
 * Interface CacheInterface
 * Defines the contract for cache implementations
 */
interface CacheInterface
{
    /**
     * Get a cached value
     *
     * @param string $key Cache key
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * Set a cached value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param string $group Cache group
     * @param int $expire Expiration time in seconds
     * @return bool
     */
    public function set(string $key, mixed $value, string $group = '', int $expire = 0): bool;

    /**
     * Delete a cached value
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool
     */
    public function delete(string $key, string $group = ''): bool;

    /**
     * Flush all cached values
     *
     * @return bool
     */
    public function flush(): bool;
}
