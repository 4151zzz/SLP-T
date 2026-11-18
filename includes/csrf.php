<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        start_secure_session();
        if (
            empty($_SESSION['_csrf_token'])
            || !is_string($_SESSION['_csrf_token'])
            || strlen($_SESSION['_csrf_token']) !== 64
        ) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(bool $forceJson = false): void
    {
        start_secure_session();
        $sessionToken = (string)($_SESSION['_csrf_token'] ?? '');
        $submitted = (string)($_POST['_csrf_token']
            ?? $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '');

        if ($sessionToken === '' || $submitted === '' || !hash_equals($sessionToken, $submitted)) {
            http_response_code(403);
            if ($forceJson || is_api_request()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Invalid or missing CSRF token',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $loginUrl = e(app_url('/login.php'));
            $homeUrl  = e(app_url('/index.php'));
            header('Content-Type: text/html; charset=UTF-8');
            echo <<<HTML
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — เซสชันไม่ถูกต้อง</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="card shadow-sm border-danger" style="max-width: 480px; width: 100%;">
        <div class="card-body text-center p-4">
            <h1 class="h4 text-danger fw-bold mb-2">เซสชันหมดอายุหรือไม่ถูกต้อง (403 Forbidden)</h1>
            <p class="text-muted mb-3">การตรวจสอบความปลอดภัย (CSRF) ไม่ผ่าน กรุณารีเฟรชหน้าแล้วลองใหม่อีกครั้ง</p>
            <div class="d-flex justify-content-center gap-2">
                <a href="{$homeUrl}" class="btn btn-outline-secondary">หน้าหลัก</a>
                <a href="{$loginUrl}" class="btn btn-primary">เข้าสู่ระบบ</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
            exit;
        }
    }
}
