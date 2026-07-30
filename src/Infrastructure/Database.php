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

        $this->pdo->exec("SET time_zone = '" . self::spotTimeZoneOffset() . "'");

        $suffix = trim($suffix);
        $this->tableSuffix = preg_match('/^[a-zA-Z0-9_]*$/', $suffix) ? $suffix : '';
    }

    /**
     * Смещение таймзоны заведения для сессии MySQL.
     *
     * Зачем: сервер живёт в EEST (+03:00), MySQL настроен на time_zone=SYSTEM,
     * а приложение работает во вьетнамском времени (+07:00). До этой правки
     * NOW(), CURDATE() и колонки DEFAULT CURRENT_TIMESTAMP писались в киевском
     * времени, то есть на 4 часа позади того, что в те же таблицы кладёт PHP
     * через date('Y-m-d H:i:s'). Самое неприятное следствие — CURDATE() до
     * 04:00 по Нячангу возвращал ВЧЕРАШНюю дату, а ресторан работает за
     * полночь.
     *
     * Берём числовое смещение, а не 'Asia/Ho_Chi_Minh': именованные зоны
     * требуют загруженных таблиц mysql.time_zone_name, которых на хостинге
     * обычно нет. Вьетнам не переходит на летнее время, поэтому фиксированный
     * +07:00 эквивалентен named-зоне круглый год.
     */
    public static function spotTimeZoneOffset(): string
    {
        $tzName = trim(Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh')) ?: 'Asia/Ho_Chi_Minh';
        try {
            $offsetSeconds = (new \DateTimeZone($tzName))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            $offsetSeconds = 7 * 3600;
        }
        $sign    = $offsetSeconds < 0 ? '-' : '+';
        $abs     = abs($offsetSeconds);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
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
