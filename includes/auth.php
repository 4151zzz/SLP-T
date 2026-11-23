<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('start_secure_session')) {
    function start_secure_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? null) == 443);

            session_name('SLP_ADM_SID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_start();
    }
}

if (!function_exists('is_api_request')) {
    function is_api_request(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_starts_with($uri, app_url('/api/'))
            || str_starts_with($uri, '/api/')
            || str_contains($accept, 'application/json')
            || $xhr === 'xmlhttprequest';
    }
}

if (!function_exists('current_user')) {
    function current_user(bool $refresh = false): ?array
    {
        static $cached = null;
        static $cachedId = null;

        start_secure_session();

        $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($sessionUserId <= 0) {
            $cached = null;
            $cachedId = null;
            return null;
        }

        if (!$refresh && $cached !== null && $cachedId === $sessionUserId) {
            return $cached;
        }

        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_type, role, username, staff_id, full_name, position_title,
                    department, organization, phone, email, line_user_id, is_active
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $sessionUserId]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['is_active'] !== 1) {
            $cached = null;
            $cachedId = null;
            logout_user();
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];

        $_SESSION['user_role'] = $row['role'];
        $_SESSION['user_type'] = $row['user_type'];

        $cached = $row;
        $cachedId = $row['id'];
        return $cached;
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return current_user() !== null;
    }
}

if (!function_exists('is_internal')) {
    function is_internal(?array $user = null): bool
    {
        $u = $user ?? current_user();
        return $u !== null && ($u['user_type'] ?? '') === USER_TYPE_INTERNAL;
    }
}

if (!function_exists('is_external')) {
    function is_external(?array $user = null): bool
    {
        $u = $user ?? current_user();
        return $u !== null && ($u['user_type'] ?? '') === USER_TYPE_EXTERNAL;
    }
}

if (!function_exists('has_role')) {
    function has_role(string|array $roles, ?array $user = null): bool
    {
        $u = $user ?? current_user();
        if ($u === null) {
            return false;
        }
        $list = is_array($roles) ? $roles : [$roles];
        return in_array($u['role'], $list, true);
    }
}

if (!function_exists('require_login')) {
    function require_login(): array
    {
        $u = current_user();
        if ($u !== null) {
            return $u;
        }

        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $target = safe_redirect_path($_SERVER['REQUEST_URI'] ?? '/index.php', '/index.php');
        if (str_starts_with($target, '/login.php') || str_starts_with($target, '/logout.php')) {
            redirect(app_url('/login.php'));
        }
        redirect(app_url('/login.php') . '?redirect=' . urlencode($target));
    }
}

if (!function_exists('require_role')) {
    function require_role(string|array $roles): array
    {
        $u = require_login();
        if (!has_role($roles, $u)) {
            http_response_code(403);
            if (is_api_request()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $home = e(app_url('/index.php'));
            $bsCss = e(app_url('/assets/vendor/bootstrap/bootstrap.min.css'));
            header('Content-Type: text/html; charset=UTF-8');
            echo <<<HTML
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — ไม่มีสิทธิ์เข้าถึง</title>
    <link rel="stylesheet" href="{$bsCss}">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="card shadow-sm border-danger" style="max-width: 480px; width: 100%;">
        <div class="card-body text-center p-4">
            <h1 class="display-6 text-danger fw-bold mb-2">403 Forbidden</h1>
            <p class="mb-3">คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (Forbidden)</p>
            <a href="{$home}" class="btn btn-primary">กลับสู่หน้าหลัก</a>
        </div>
    </div>
</body>
</html>
HTML;
            exit;
        }
        return $u;
    }
}

if (!function_exists('attempt_login')) {
    /**
     * Authenticate internal (username or staff_id) or external (email or username) user.
     *
     * @return array{ok:bool, user:?array, error:?string}
     */
    function attempt_login(string $identifier, string $password): array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return [
                'ok'    => false,
                'user'  => null,
                'error' => 'กรุณากรอกข้อมูลเข้าสู่ระบบและรหัสผ่าน',
            ];
        }

        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_type, role, username, staff_id, password_hash, full_name,
                    position_title, department, organization, phone, email, line_user_id, is_active
             FROM users
             WHERE username = :ident1 OR staff_id = :ident2 OR email = :ident3 OR phone = :ident4
             LIMIT 5'
        );
        $stmt->execute([
            ':ident1' => $identifier,
            ':ident2' => $identifier,
            ':ident3' => $identifier,
            ':ident4' => $identifier,
        ]);
        $rows = $stmt->fetchAll();

        $matched = null;
        foreach ($rows as $row) {
            if ($row['user_type'] === USER_TYPE_INTERNAL) {
                $isInternalMatch = (strcasecmp((string)$row['username'], $identifier) === 0)
                    || ($row['staff_id'] !== null && strcasecmp((string)$row['staff_id'], $identifier) === 0)
                    || ($row['email'] !== null && strcasecmp((string)$row['email'], $identifier) === 0)
                    || ($row['phone'] !== null && $row['phone'] === $identifier);
                if ($isInternalMatch && password_verify($password, (string)$row['password_hash'])) {
                    $matched = $row;
                    break;
                }
            } elseif ($row['user_type'] === USER_TYPE_EXTERNAL) {
                $isExternalMatch = ($row['email'] !== null && strcasecmp((string)$row['email'], $identifier) === 0)
                    || (strcasecmp((string)$row['username'], $identifier) === 0)
                    || ($row['phone'] !== null && $row['phone'] === $identifier);
                if ($isExternalMatch && password_verify($password, (string)$row['password_hash'])) {
                    $matched = $row;
                    break;
                }
            }
        }

        if ($matched === null) {
            return [
                'ok'    => false,
                'user'  => null,
                'error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        if ((int)$matched['is_active'] !== 1) {
            return [
                'ok'    => false,
                'user'  => null,
                'error' => 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน',
            ];
        }

        start_secure_session();
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $matched['id'] = (int)$matched['id'];
        $matched['is_active'] = (int)$matched['is_active'];

        $_SESSION['user_id'] = $matched['id'];
        $_SESSION['user_role'] = $matched['role'];
        $_SESSION['user_type'] = $matched['user_type'];
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        unset($matched['password_hash']);
        current_user(true);

        return [
            'ok'    => true,
            'user'  => $matched,
            'error' => null,
        ];
    }
}

if (!function_exists('logout_user')) {
    function logout_user(): void
    {
        start_secure_session();
        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
