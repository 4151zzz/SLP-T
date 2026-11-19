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

        // Auto-detect local development environment
        $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $isLocalDev = in_array($httpHost, ['127.0.0.1:3000', 'localhost:3000', '127.0.0.1', 'localhost'], true)
            || (PHP_SAPI === 'cli' && empty(getenv('DB_NAME')));

        if ($isLocalDev && empty(getenv('DB_NAME'))) {
            return [
                'host' => '127.0.0.1',
                'port' => '3306',
                'name' => 'slp_admin_db',
                'user' => 'root',
                'pass' => '',
            ];
        }

        return [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: '3306',
            'name' => getenv('DB_NAME') ?: 'saluangac_General',
            'user' => getenv('DB_USER') ?: 'saluangac_General',
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
    try {
        $pdo = get_pdo();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $cfg = get_db_config();
        $hostSafe = htmlspecialchars($cfg['host'] . ':' . $cfg['port'], ENT_QUOTES, 'UTF-8');
        $nameSafe = htmlspecialchars($cfg['name'], ENT_QUOTES, 'UTF-8');
        $userSafe = htmlspecialchars($cfg['user'], ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ไม่สามารถเชื่อมต่อฐานข้อมูลได้</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: #ffffff; border: 1px solid #e2e8f0; border-top: 5px solid #ef4444; border-radius: 14px; max-width: 600px; width: 100%; padding: 28px; box-shadow: 0 15px 30px rgba(15, 23, 42, 0.08); }
        h1 { margin-top: 0; color: #dc2626; font-size: 1.35rem; display: flex; align-items: center; gap: 8px; }
        p { margin: 8px 0 14px; font-size: 0.95rem; color: #475569; }
        pre { background: #fef2f2; border: 1px solid #fecaca; padding: 12px 14px; border-radius: 8px; font-size: 0.86rem; overflow-x: auto; color: #991b1b; white-space: pre-wrap; word-break: break-all; }
        .info { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 0.88rem; line-height: 1.6; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>⚠️ ไม่สามารถเชื่อมต่อฐานข้อมูล (Database Error)</h1>
        <p>ระบบไม่สามารถเชื่อมต่อไปยัง MySQL Database ได้ กรุณาตรวจสอบค่าในไฟล์ <code>config/db.php</code> บนเซิร์ฟเวอร์:</p>
        <pre>{$msg}</pre>
        <div class="info">
            <strong>ค่าที่ใช้พยายามเชื่อมต่อในขณะนี้:</strong><br>
            • Host: <code>{$hostSafe}</code><br>
            • Database: <code>{$nameSafe}</code><br>
            • Username: <code>{$userSafe}</code>
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
