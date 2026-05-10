<?php
declare(strict_types=1);

namespace MIS;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $instance = null;
    private static array $config = [];

    public static function configure(array $cfg): void
    {
        self::$config = $cfg;
        self::$instance = null;
    }

    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }
        if (empty(self::$config)) {
            throw new \RuntimeException('Db not configured — call Db::configure() first.');
        }
        $c = self::$config;
        $dsnParts = ['mysql:'];
        if (!empty($c['socket'])) {
            $dsnParts[] = 'unix_socket=' . $c['socket'];
        } else {
            $dsnParts[] = 'host=' . ($c['host'] ?? '127.0.0.1');
            $dsnParts[] = 'port=' . ($c['port'] ?? 3306);
        }
        $dsnParts[] = 'dbname=' . $c['name'];
        $dsnParts[] = 'charset=' . ($c['charset'] ?? 'utf8mb4');
        $dsn = implode(';', [array_shift($dsnParts) . array_shift($dsnParts)]) . ';' . implode(';', $dsnParts);
        // Cleaner: build manually
        $dsn = sprintf(
            'mysql:%sdbname=%s;charset=%s',
            !empty($c['socket'])
                ? 'unix_socket=' . $c['socket'] . ';'
                : 'host=' . ($c['host'] ?? '127.0.0.1') . ';port=' . ($c['port'] ?? 3306) . ';',
            $c['name'],
            $c['charset'] ?? 'utf8mb4'
        );
        try {
            self::$instance = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
