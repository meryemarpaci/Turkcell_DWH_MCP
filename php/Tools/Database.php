<?php

declare(strict_types=1);

namespace App\Tools;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $rel = app_env('DWH_SQLITE_PATH', 'Data/olist_dwh.sqlite');
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $rel);
        if (!is_file($path)) {
            throw new RuntimeException("DWH sqlite not found: $path");
        }
        $dsn = 'sqlite:' . $path;
        self::$pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        try {
            self::$pdo->exec('PRAGMA query_only = ON');
        } catch (\Throwable) {
            // older SQLite builds may not support query_only
        }
        return self::$pdo;
    }
}
