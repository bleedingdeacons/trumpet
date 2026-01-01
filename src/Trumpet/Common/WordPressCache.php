<?php

declare(strict_types=1);

namespace Trumpet\Common;

/**
 * WordPress cache adapter
 */
class WordPressCache implements CacheInterface
{
    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        return wp_cache_get($key);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        return wp_cache_set($key, $value, $group, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key, string $group = ''): bool
    {
        return wp_cache_delete($key, $group);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        return wp_cache_flush();
    }
}
