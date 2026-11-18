<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    exit("405 Method Not Allowed\n");
}

verify_csrf();
logout_user();
flash('info', 'ออกจากระบบเรียบร้อยแล้ว');
redirect(app_url('/login.php'));
