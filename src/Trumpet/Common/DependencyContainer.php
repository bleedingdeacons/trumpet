<?php

declare(strict_types=1);

namespace Trumpet\Common;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Simple dependency injection container
 */
class DependencyContainer
{
    private array $services = [];
    private array $factories = [];

    /**
     * Register a service factory
     *
     * @param string $id Service identifier
     * @param callable $factory Factory callback
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Get a service by its identifier
     *
     * @param string $id Service identifier
     * @return mixed
     * @throws RuntimeException If service not found
     */
    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            if (!isset($this->factories[$id])) {
                throw new RuntimeException("Service not found: $id");
            }
            $this->services[$id] = $this->factories[$id]($this);
        }
        return $this->services[$id];
    }
}
