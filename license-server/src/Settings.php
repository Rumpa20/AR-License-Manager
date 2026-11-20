<?php
/**
 * AR License Manager - Runtime settings stored in the settings table.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function boot(): void
    {
        self::$cache = [];
        $rows = Database::all('SELECT name, value FROM ' . Database::table('settings'));
        foreach ($rows as $row) {
            self::$cache[(string) $row['name']] = (string) $row['value'];
        }
    }

    public static function get(string $name, string $default = ''): string
    {
        $value = self::$cache[$name] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function set(string $name, string $value): void
    {
        Database::run(
            'INSERT INTO ' . Database::table('settings') . ' (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value]
        );
        if (self::$cache !== null) {
            self::$cache[$name] = $value;
        }
    }

    public static function setMany(array $pairs): void
    {
        Database::begin();
        try {
            foreach ($pairs as $name => $value) {
                self::set((string) $name, (string) $value);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
