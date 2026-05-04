<?php

final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/../config/config.php';
        $database = $config['database'];
        if (is_array($database) && ($database['driver'] ?? '') === 'mysql') {
            self::$driver = 'mysql';
            $host = $database['host'] ?? '127.0.0.1';
            $port = (int)($database['port'] ?? 3306);
            $dbname = $database['dbname'] ?? 'discadora';
            $charset = $database['charset'] ?? 'utf8mb4';
            $user = $database['user'] ?? 'root';
            $password = $database['password'] ?? '';
            self::ensureMysqlDatabase($host, $port, $dbname, $charset, $user, $password);
            self::$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'",
            ]);
            return self::$pdo;
        }

        self::$driver = 'sqlite';
        $path = is_array($database) ? ($database['sqlite_path'] ?? __DIR__ . '/../data/app.sqlite') : $database;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::$pdo = new PDO('sqlite:' . $path);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
        self::$pdo->exec('PRAGMA busy_timeout = 10000');
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA synchronous = NORMAL');
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        return self::$pdo;
    }

    public static function driver(): string
    {
        self::conn();
        return self::$driver;
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    private static function ensureMysqlDatabase(string $host, int $port, string $dbname, string $charset, string $user, string $password): void
    {
        $pdo = new PDO("mysql:host={$host};port={$port};charset={$charset}", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $safeName = str_replace('`', '``', $dbname);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    }
}
