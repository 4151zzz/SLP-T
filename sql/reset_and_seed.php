<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("403 Forbidden: This script can only be executed from CLI.\n");
}

define('DB_NO_AUTO_CONNECT', true);
require_once dirname(__DIR__) . '/config/db.php';

/**
 * Split a SQL file into individual executable statements while respecting
 * single/double/backtick-quoted strings and `--`, `#`, `/* ... *\/` comments.
 *
 * @return array<int, string>
 */
function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\r" || $sql[$i + 2] === "\n")) {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $escaped = false;
            $j = $i - 1;
            while ($j >= 0 && $sql[$j] === '\\') {
                $escaped = !$escaped;
                $j--;
            }
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $escaped = false;
            $j = $i - 1;
            while ($j >= 0 && $sql[$j] === '\\') {
                $escaped = !$escaped;
                $j--;
            }
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        } elseif ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

$cfg = get_db_config();
$dbName = $cfg['name'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "Invalid DB_NAME: {$dbName}\n");
    exit(1);
}

$schemaPath = __DIR__ . '/schema.sql';
$seedPath   = __DIR__ . '/seed.sql';

if (!is_file($schemaPath) || !is_file($seedPath)) {
    fwrite(STDERR, "Missing schema.sql or seed.sql in " . __DIR__ . "\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    foreach (['schema.sql' => $schemaPath, 'seed.sql' => $seedPath] as $label => $file) {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Failed to read {$label}");
        }
        $stmts = split_sql_statements($raw);
        foreach ($stmts as $stmt) {
            $pdo->exec($stmt);
        }
        echo "Applied {$label} (" . count($stmts) . " statements)\n";
    }

    $tables = [
        'users',
        'rooms',
        'vehicles',
        'drivers',
        'assets',
        'bookings',
        'approval_logs',
        'calendar_events',
        'maintenance_requests',
        'asset_borrowings',
        'notification_logs',
    ];
    echo "Database `{$dbName}` reset & seeded successfully:\n";
    foreach ($tables as $t) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo sprintf("  %-22s : %d\n", $t, $cnt);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error resetting/seeding database: " . $e->getMessage() . "\n");
    exit(1);
}
