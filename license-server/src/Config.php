<?php
/**
 * AR License Manager - Configuration loader.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            http_response_code(500);
            exit('AR License Manager is not configured. Run the installer first.');
        }
        /** @var array<string, mixed> $config */
        $config = require $path;
        if (!is_array($config)) {
            http_response_code(500);
            exit('Invalid configuration file.');
        }
        self::$config = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /** Used by the CLI test suite; production entry point is load(). */
    public static function loadFromArray(array $config): void
    {
        self::$config = $config;
    }
}
