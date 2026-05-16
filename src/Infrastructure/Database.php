<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static self|null $_instance = null;

    private PDO $pdo;
    private string $tableSuffix;

    private function __construct()
    {
        $host   = Config::require('DB_HOST');
        $db     = Config::require('DB_NAME');
        $user   = Config::require('DB_USER');
        $pass   = Config::require('DB_PASS');
        $suffix = Config::get('DB_TABLE_SUFFIX');

        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        $suffix = trim($suffix);
        $this->tableSuffix = preg_match('/^[a-zA-Z0-9_]*$/', $suffix) ? $suffix : '';
    }

    public static function getInstance(): self
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Returns table name with configured suffix */
    public function t(string $baseName): string
    {
        return $baseName . $this->tableSuffix;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
