<?php
declare(strict_types=1);

if (!function_exists('get_db_config')) {
    /**
     * Read database configuration with environment variable overrides.
     *
     * @return array{host:string, port:string, name:string, user:string, pass:string}
     */
    function get_db_config(): array
    {
        $pass = getenv('DB_PASS');
        return [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'name' => getenv('DB_NAME') ?: 'Sauangac_general',
            'user' => getenv('DB_USER') ?: 'wun426655',
            'pass' => ($pass === false) ? 'wun426655' : $pass,
        ];
    }
}

if (!function_exists('get_pdo')) {
    /**
     * Return a configured PDO instance with ERRMODE_EXCEPTION and EMULATE_PREPARES => false.
     */
    function get_pdo(): PDO
    {
        static $pdo = null;
        static $cacheKey = null;

        $cfg = get_db_config();
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['name']
        );
        $key = $dsn . '|' . $cfg['user'];

        if ($pdo instanceof PDO && $cacheKey === $key) {
            return $pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'",
        ];

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $cacheKey = $key;
        return $pdo;
    }
}

if (!defined('DB_NO_AUTO_CONNECT') && PHP_SAPI !== 'cli') {
    $pdo = get_pdo();
}
