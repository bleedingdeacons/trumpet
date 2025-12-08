<?php

declare(strict_types=1);

namespace Common;

/**
 * Class DependencyContainer
 * Simple dependency injection container
 */
if (!class_exists('\Common\DependencyContainer')) {
    class DependencyContainer
    {
        private array $services = [];
        private array $factories = [];

        public function register(string $id, callable $factory): void
        {
            $this->factories[$id] = $factory;
        }

        public function get(string $id): mixed
        {
            if (!isset($this->services[$id])) {
                if (!isset($this->factories[$id])) {
                    throw new \RuntimeException("Service not found: $id");
                }
                $this->services[$id] = $this->factories[$id]($this);
            }
            return $this->services[$id];
        }
    }
}

/**
 * Interface CacheInterface
 * Defines the contract for cache implementations
 */
if (!interface_exists('\Common\CacheInterface')) {
    interface CacheInterface
    {
        public function get($key);
        public function set($key, $value, $group = '', $expire = 0);
        public function delete($key, $group = '');
        public function flush();
    }
}

/**
 * WordPress cache adapter
 */
if (!class_exists('\Common\WordPressCache')) {
    class WordPressCache implements CacheInterface
    {
        public function flush()
        {
            wp_cache_flush();
        }

        public function get($key, $group = '')
        {
            return wp_cache_get($key, $group);
        }

        public function set($key, $value, $group = '', $expire = 0)
        {
            return wp_cache_set($key, $value, $group, $expire);
        }

        public function delete($key, $group = '')
        {
            return wp_cache_delete($key, $group);
        }
    }
}

if (!class_exists('\Common\Functions')) {
    class Functions
    {

        public static function  email_to($address, $subject = '')
        {
            if (!empty($subject)) {
                $address = $address . '?subject=' . $subject;
            }

            return 'mailto:' . $address;
        }

        public static function phone_to($number)
        {
            return 'tel:' . $number;
        }

        public static function  link_to($href, $class, $text = '')
        {

            return '<a target="_blank" rel="noreferrer noopener" class="' . esc_attr($class) . '" href="' . esc_attr($href) . '">' . esc_html($text) . '</a>';
        }

        public static function create_email_anchor($address, $subject, $class, $text)
        {
            $address = self::email_to($address, $subject);

            return self::link_to($address, $class, $text);
        }
    }
}
