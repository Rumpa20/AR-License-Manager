<?php
/**
 * AR License Manager - PDO database access layer.
 * All queries in the project use prepared statements through this class.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = (string) Config::get('db_host', 'localhost');
            $port = (int) Config::get('db_port', 3306);
            $name = (string) Config::get('db_name', '');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
            try {
                self::$pdo = new PDO($dsn, (string) Config::get('db_user', ''), (string) Config::get('db_pass', ''), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException) {
                throw new RuntimeException('Database connection failed.');
            }
        }
        return self::$pdo;
    }

    public static function table(string $name): string
    {
        $prefix = (string) Config::get('db_prefix', 'arlm_');
        return '`' . str_replace('`', '', $prefix . $name) . '`';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollBack(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
