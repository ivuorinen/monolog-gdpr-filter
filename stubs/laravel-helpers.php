<?php

/**
 * Laravel Helper Function Stubs for IDE Support
 *
 * This file provides type hints for Laravel helper functions
 * to prevent IDE warnings when using them in this library.
 */

if (!function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @param string|null $abstract
     * @param array<string, mixed> $parameters
     * @return mixed|\Illuminate\Contracts\Foundation\Application
     */
    function app(?string $abstract = null, array $parameters = [])
    {
        // Stub implementation - returns null when Laravel is not available
        unset($abstract, $parameters);
        return null;
    }
}

if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @param array<string, mixed>|string|null $key
     * @param mixed $default
     * @return mixed|\Illuminate\Config\Repository
     */
    function config($key = null, $default = null)
    {
        if (function_exists('app') && app() !== null && app()->bound('config')) {
            /** @var \Illuminate\Config\Repository $config */
            $config = app('config');
            return $config->get($key, $default);
        }

        return $default;
    }
}

if (!function_exists('env')) {
    /**
     * Get the value of an environment variable.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
