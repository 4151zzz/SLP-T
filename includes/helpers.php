<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('safe_redirect_path')) {
    /**
     * Validate and normalize a local relative redirect path + query string.
     * Rejects external URLs, protocol-relative URLs, backslashes, control chars, and schemes.
     */
    function safe_redirect_path(?string $path, string $fallback = '/index.php'): string
    {
        if ($path === null) {
            return $fallback;
        }

        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $fallback;
        }
        if (str_contains($path, '\\')) {
            return $fallback;
        }
        if (str_starts_with($path, '//')) {
            return $fallback;
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1) {
            return $fallback;
        }

        $parts = parse_url($path);
        if ($parts === false) {
            return $fallback;
        }
        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        $relPath = (string)($parts['path'] ?? '');
        if ($relPath === '') {
            $relPath = '/index.php';
        }
        if ($relPath[0] !== '/') {
            $relPath = '/' . $relPath;
        }

        $base = app_base_path();
        if ($base !== '' && str_starts_with($relPath, $base . '/')) {
            $relPath = substr($relPath, strlen($base));
        }

        if (str_starts_with($relPath, '//')) {
            return $fallback;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $relPath . $query;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $statusCode = 302): never
    {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        if (function_exists('start_secure_session')) {
            start_secure_session();
        } elseif (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $normalized = match (strtolower(trim($type))) {
            'error', 'danger' => 'danger',
            'success'         => 'success',
            'warning'         => 'warning',
            default           => 'info',
        };

        if (!isset($_SESSION['_flashes']) || !is_array($_SESSION['_flashes'])) {
            $_SESSION['_flashes'] = [];
        }
        $_SESSION['_flashes'][] = [
            'type'    => $normalized,
            'message' => $message,
        ];
    }
}

if (!function_exists('get_flashes')) {
    /**
     * @return array<int, array{type:string, message:string}>
     */
    function get_flashes(): array
    {
        if (function_exists('start_secure_session')) {
            start_secure_session();
        } elseif (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $flashes = $_SESSION['_flashes'] ?? [];
        unset($_SESSION['_flashes']);
        return is_array($flashes) ? $flashes : [];
    }
}

if (!function_exists('thai_date')) {
    function thai_date(?string $datetime, bool $withTime = false, bool $shortMonth = false): string
    {
        if ($datetime === null) {
            return '-';
        }
        $raw = trim($datetime);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return '-';
        }

        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone('Asia/Bangkok'));
        } catch (Throwable $e) {
            return '-';
        }

        $fullMonths = [
            1  => 'มกราคม',
            2  => 'กุมภาพันธ์',
            3  => 'มีนาคม',
            4  => 'เมษายน',
            5  => 'พฤษภาคม',
            6  => 'มิถุนายน',
            7  => 'กรกฎาคม',
            8  => 'สิงหาคม',
            9  => 'กันยายน',
            10 => 'ตุลาคม',
            11 => 'พฤศจิกายน',
            12 => 'ธันวาคม',
        ];
        $shortMonths = [
            1  => 'ม.ค.',
            2  => 'ก.พ.',
            3  => 'มี.ค.',
            4  => 'เม.ย.',
            5  => 'พ.ค.',
            6  => 'มิ.ย.',
            7  => 'ก.ค.',
            8  => 'ส.ค.',
            9  => 'ก.ย.',
            10 => 'ต.ค.',
            11 => 'พ.ย.',
            12 => 'ธ.ค.',
        ];

        $day = (int)$dt->format('j');
        $monthNum = (int)$dt->format('n');
        $beYear = (int)$dt->format('Y') + 543;
        $monthName = $shortMonth ? $shortMonths[$monthNum] : $fullMonths[$monthNum];

        $formatted = $day . ' ' . $monthName . ' ' . $beYear;
        if ($withTime) {
            $formatted .= ' เวลา ' . $dt->format('H:i') . ' น.';
        }
        return $formatted;
    }
}

if (!function_exists('pending_approval_count')) {
    function pending_approval_count(?array $user, ?PDO $pdo = null): int
    {
        if ($user === null) {
            return 0;
        }
        $role = (string)($user['role'] ?? '');
        if (!in_array($role, [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], true)) {
            return 0;
        }

        try {
            $db = $pdo ?? get_pdo();
            $sql = match ($role) {
                ROLE_HEAD          => "SELECT COUNT(*) FROM bookings WHERE status = 'pending_head'",
                ROLE_VICE_DIRECTOR => "SELECT COUNT(*) FROM bookings WHERE status = 'pending_vice'",
                ROLE_DIRECTOR      => "SELECT COUNT(*) FROM bookings WHERE status = 'pending_director'",
                ROLE_STAFF         => "SELECT COUNT(*) FROM bookings WHERE status = 'pending_head' AND booking_type = 'vehicle' AND (vehicle_id IS NULL OR driver_id IS NULL)",
                ROLE_ADMIN         => "SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director')",
                default            => null,
            };
            if ($sql === null) {
                return 0;
            }
            return (int)$db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('nav_items')) {
    /**
     * Return role-filtered navigation items for the layout sidebar and home portal.
     *
     * @return array<int, array{key:string, href:string, label:string, icon:string, testid:string, badge:?int}>
     */
    function nav_items(?array $user): array
    {
        if ($user === null) {
            return [];
        }

        $role = (string)($user['role'] ?? '');
        $allAuthenticated = [
            ROLE_USER,
            ROLE_EXTERNAL_USER,
            ROLE_HEAD,
            ROLE_VICE_DIRECTOR,
            ROLE_DIRECTOR,
            ROLE_STAFF,
            ROLE_ADMIN,
        ];
        $internalOnly = INTERNAL_ROLES;
        $approvers = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
        $staffAdmin = [ROLE_STAFF, ROLE_ADMIN];
        $executivesAdmin = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];

        $pendingCount = in_array($role, $approvers, true) ? pending_approval_count($user) : 0;

        $catalog = [
            [
                'key'    => 'calendar',
                'href'   => app_url('/calendar.php'),
                'label'  => 'ปฏิทินหลัก',
                'icon'   => 'bi-calendar3',
                'testid' => 'nav-calendar',
                'roles'  => $allAuthenticated,
                'badge'  => null,
            ],
            [
                'key'    => 'book_room',
                'href'   => app_url('/book_room.php'),
                'label'  => 'จองห้องประชุม',
                'icon'   => 'bi-door-open',
                'testid' => 'nav-book-room',
                'roles'  => $allAuthenticated,
                'badge'  => null,
            ],
            [
                'key'    => 'book_vehicle',
                'href'   => app_url('/book_vehicle.php'),
                'label'  => 'จองยานพาหนะ',
                'icon'   => 'bi-truck',
                'testid' => 'nav-book-vehicle',
                'roles'  => $internalOnly,
                'badge'  => null,
            ],
            [
                'key'    => 'my_bookings',
                'href'   => app_url('/my_bookings.php'),
                'label'  => 'รายการคำขอของฉัน',
                'icon'   => 'bi-card-checklist',
                'testid' => 'nav-my-bookings',
                'roles'  => $allAuthenticated,
                'badge'  => null,
            ],
            [
                'key'    => 'saved_memos',
                'href'   => app_url('/saved_memos.php'),
                'label'  => 'คลังบันทึกข้อความ',
                'icon'   => 'bi-journal-bookmark-fill',
                'testid' => 'nav-saved-memos',
                'roles'  => $allAuthenticated,
                'badge'  => null,
            ],
            [
                'key'    => 'approvals',
                'href'   => app_url('/approvals.php'),
                'label'  => 'พิจารณาอนุมัติ',
                'icon'   => 'bi-check2-square',
                'testid' => 'nav-approvals',
                'roles'  => $approvers,
                'badge'  => $pendingCount > 0 ? $pendingCount : null,
            ],
            [
                'key'    => 'maintenance',
                'href'   => app_url('/maintenance.php'),
                'label'  => 'แจ้งซ่อมออนไลน์ (QR)',
                'icon'   => 'bi-wrench-adjustable',
                'testid' => 'nav-maintenance',
                'roles'  => $allAuthenticated,
                'badge'  => null,
            ],
            [
                'key'    => 'maintenance_manage',
                'href'   => app_url('/maintenance_manage.php'),
                'label'  => 'จัดการงานซ่อม',
                'icon'   => 'bi-tools',
                'testid' => 'nav-maintenance-manage',
                'roles'  => $staffAdmin,
                'badge'  => null,
            ],
            [
                'key'    => 'assets_borrow',
                'href'   => app_url('/assets_borrow.php'),
                'label'  => 'ยืม-คืนอุปกรณ์/กุญแจ',
                'icon'   => 'bi-key',
                'testid' => 'nav-assets-borrow',
                'roles'  => $internalOnly,
                'badge'  => null,
            ],
            [
                'key'    => 'qr_codes',
                'href'   => app_url('/qr_codes.php'),
                'label'  => 'พิมพ์ QR Code',
                'icon'   => 'bi-qr-code',
                'testid' => 'nav-qr-codes',
                'roles'  => $staffAdmin,
                'badge'  => null,
            ],
            [
                'key'    => 'telegram_settings',
                'href'   => app_url('/telegram_settings.php'),
                'label'  => 'ตั้งค่า Telegram',
                'icon'   => 'bi-telegram',
                'testid' => 'nav-telegram-settings',
                'roles'  => $staffAdmin,
                'badge'  => null,
            ],
            [
                'key'    => 'dashboard',
                'href'   => app_url('/dashboard.php'),
                'label'  => 'แดชบอร์ดผู้บริหาร',
                'icon'   => 'bi-speedometer2',
                'testid' => 'nav-dashboard',
                'roles'  => $executivesAdmin,
                'badge'  => null,
            ],
            [
                'key'    => 'print_report',
                'href'   => app_url('/print_report.php'),
                'label'  => 'พิมพ์รายงาน (PDF)',
                'icon'   => 'bi-file-earmark-pdf',
                'testid' => 'nav-print-report',
                'roles'  => $executivesAdmin,
                'badge'  => null,
            ],
            [
                'key'    => 'users_manage',
                'href'   => app_url('/users_manage.php'),
                'label'  => 'จัดการผู้ใช้งาน',
                'icon'   => 'bi-people-fill',
                'testid' => 'nav-users-manage',
                'roles'  => [ROLE_ADMIN],
                'badge'  => null,
            ],
            [
                'key'    => 'resources_manage',
                'href'   => app_url('/resources_manage.php'),
                'label'  => 'จัดการอาคาร/ห้อง/รถ',
                'icon'   => 'bi-building-fill-gear',
                'testid' => 'nav-resources-manage',
                'roles'  => [ROLE_ADMIN],
                'badge'  => null,
            ],
        ];

        $visible = [];
        foreach ($catalog as $item) {
            if (in_array($role, $item['roles'], true)) {
                unset($item['roles']);
                $visible[] = $item;
            }
        }

        $rolePriority = [
            ROLE_DIRECTOR      => ['dashboard', 'print_report', 'approvals', 'saved_memos', 'calendar', 'my_bookings', 'book_room', 'book_vehicle', 'assets_borrow', 'maintenance'],
            ROLE_VICE_DIRECTOR => ['dashboard', 'print_report', 'approvals', 'saved_memos', 'calendar', 'my_bookings', 'book_room', 'book_vehicle', 'assets_borrow', 'maintenance'],
            ROLE_HEAD          => ['approvals', 'dashboard', 'print_report', 'saved_memos', 'calendar', 'book_room', 'book_vehicle', 'my_bookings', 'assets_borrow', 'maintenance'],
            ROLE_STAFF         => ['approvals', 'maintenance_manage', 'assets_borrow', 'qr_codes', 'telegram_settings', 'saved_memos', 'print_report', 'calendar', 'dashboard', 'book_room', 'book_vehicle', 'my_bookings', 'maintenance'],
            ROLE_ADMIN         => ['dashboard', 'print_report', 'approvals', 'saved_memos', 'users_manage', 'resources_manage', 'maintenance_manage', 'assets_borrow', 'qr_codes', 'telegram_settings', 'calendar', 'book_room', 'book_vehicle', 'my_bookings', 'maintenance'],
            ROLE_USER          => ['calendar', 'book_room', 'book_vehicle', 'my_bookings', 'saved_memos', 'assets_borrow', 'maintenance'],
            ROLE_EXTERNAL_USER => ['book_room', 'my_bookings', 'saved_memos', 'calendar', 'maintenance'],
        ];
        if (isset($rolePriority[$role])) {
            $orderMap = array_flip($rolePriority[$role]);
            usort($visible, static function (array $a, array $b) use ($orderMap): int {
                return ($orderMap[$a['key']] ?? 999) <=> ($orderMap[$b['key']] ?? 999);
            });
        }

        return $visible;
    }
}

if (!function_exists('is_active_nav')) {
    function is_active_nav(string $href): bool
    {
        $current = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        $target = basename((string)(parse_url($href, PHP_URL_PATH) ?: $href));
        return $current !== '' && $current === $target;
    }
}

if (!function_exists('ensure_resource_image_schema')) {
    /**
     * Auto-migrate buildings table and image_url columns on buildings/rooms once per request.
     */
    function ensure_resource_image_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS buildings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    building_code VARCHAR(30) NOT NULL,
                    name VARCHAR(150) NOT NULL,
                    floors_count INT UNSIGNED NOT NULL DEFAULT 1,
                    description TEXT NULL,
                    image_url VARCHAR(500) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_buildings_code (building_code),
                    UNIQUE KEY uq_buildings_name (name),
                    KEY idx_buildings_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            if (!$pdo->query("SHOW COLUMNS FROM buildings LIKE 'image_url'")->fetch()) {
                $pdo->exec('ALTER TABLE buildings ADD COLUMN image_url VARCHAR(500) NULL AFTER description');
            }
            if (!$pdo->query("SHOW COLUMNS FROM rooms LIKE 'image_url'")->fetch()) {
                $pdo->exec('ALTER TABLE rooms ADD COLUMN image_url VARCHAR(500) NULL AFTER description');
            }
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('cancel_booking_record')) {
    /**
     * Cancel a booking by its requester or an approver/admin.
     *
     * @return array{ok:bool, message:string}
     */
    function cancel_booking_record(PDO $pdo, int $bookingId, array $actorUser, string $reason = ''): array
    {
        $res = delete_booking_record($pdo, $bookingId, $actorUser, true);
        if ($res['ok']) {
            $res['message'] = str_replace('ลบข้อมูลคิวงาน', 'ยกเลิกและลบคำขอ', $res['message']);
        }
        return $res;
    }
}

if (!function_exists('delete_booking_record')) {
    /**
     * Permanently delete a booking queue record from the database.
     *
     * @return array{ok:bool, message:string}
     */
    function delete_booking_record(PDO $pdo, int $bookingId, array $actorUser, bool $allowAnyOwnerStatus = false): array
    {
        if ($bookingId <= 0) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานที่ต้องการลบ'];
        }
        $stmt = $pdo->prepare('SELECT id, reference_code, requester_id, status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานนี้ในระบบ'];
        }
        $uid = (int)$actorUser['id'];
        $isOwner = ((int)$row['requester_id'] === $uid);
        $isPrivileged = has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $actorUser);
        if (!$isPrivileged && !($isOwner && ($allowAnyOwnerStatus || (string)$row['status'] !== 'approved'))) {
            return ['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ลบข้อมูลคิวงานนี้'];
        }
        if (function_exists('ensure_saved_memos_schema')) {
            ensure_saved_memos_schema($pdo);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM approval_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            $pdo->prepare('DELETE FROM notification_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            try {
                if ($pdo->query("SHOW TABLES LIKE 'saved_memos'")->fetch()) {
                    $pdo->prepare('UPDATE saved_memos SET booking_id = NULL WHERE booking_id = :id')->execute([':id' => $bookingId]);
                }
            } catch (Throwable $ignored) {
                // Non-fatal if saved_memos is unavailable
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => 'ลบข้อมูลคิวงาน "' . $row['reference_code'] . '" ออกจากฐานข้อมูลเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'ไม่สามารถลบข้อมูลคิวงานได้'];
        }
    }
}

if (!function_exists('save_telegram_settings')) {
    /**
     * Save Telegram notification settings into system_settings.
     */
    function save_telegram_settings(PDO $pdo, bool $enabled, string $botToken, string $chatId): void
    {
        ensure_system_settings_schema($pdo);
        $pairs = [
            'telegram_enabled'   => $enabled ? '1' : '0',
            'telegram_bot_token' => trim($botToken),
            'telegram_chat_id'   => trim($chatId),
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
}

if (!function_exists('send_telegram_message')) {
    /**
     * Send an HTML message to Telegram Bot API if enabled and configured.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    function send_telegram_message(PDO $pdo, string $htmlMessage, bool $forceTest = false): array
    {
        $cfg = get_telegram_settings($pdo);
        if ((!$cfg['enabled'] && !$forceTest) || $cfg['bot_token'] === '' || $cfg['chat_id'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($cfg['bot_token']) . '/sendMessage';
        // rawurlencode encodes ':' in bot token; Telegram expects raw token with ':'
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $cfg['chat_id'],
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $code >= 200 && $code < 300) {
                    return ['ok' => true, 'skipped' => false, 'error' => null];
                }
                return ['ok' => false, 'skipped' => false, 'error' => $err ?: ('HTTP ' . $code)];
            }

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 4,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                return ['ok' => true, 'skipped' => false, 'error' => null];
            }
            return ['ok' => false, 'skipped' => false, 'error' => 'Request failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('normalize_image_url')) {
    /**
     * Validate an external HTTP/HTTPS image URL and auto-convert Google Drive share links.
     */
    function normalize_image_url(?string $raw, ?string &$error = null): ?string
    {
        $error = null;
        $val = trim((string)($raw ?? ''));
        if ($val === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $val) === 1
            || preg_match('#^https?://#i', $val) !== 1
            || filter_var($val, FILTER_VALIDATE_URL) === false
        ) {
            $error = 'กรุณาระบุลิงก์รูปภาพที่ถูกต้อง (ขึ้นต้นด้วย http:// หรือ https:// และยาวไม่เกิน 500 ตัวอักษร)';
            return null;
        }

        if (preg_match('#^https?://drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})#i', $val, $m) === 1) {
            $val = 'https://drive.google.com/thumbnail?id=' . $m[1] . '&sz=w1000';
        } elseif (preg_match('#^https?://drive\.google\.com/(?:open|uc)\b.*[?&]id=([A-Za-z0-9_-]{10,})#i', $val, $m) === 1) {
            $val = 'https://drive.google.com/thumbnail?id=' . $m[1] . '&sz=w1000';
        }

        if (strlen($val) > 500) {
            $error = 'กรุณาระบุลิงก์รูปภาพที่ถูกต้อง (ขึ้นต้นด้วย http:// หรือ https:// และยาวไม่เกิน 500 ตัวอักษร)';
            return null;
        }

        return $val;
    }
}

if (!function_exists('ensure_system_settings_schema')) {
    /**
     * Ensure system_settings table exists and has default qr_enabled setting.
     */
    function ensure_system_settings_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    setting_value VARCHAR(255) NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('qr_enabled', '1')"
            );
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('cancel_booking_record')) {
    /**
     * Cancel a booking by its requester or an approver/admin.
     *
     * @return array{ok:bool, message:string}
     */
    function cancel_booking_record(PDO $pdo, int $bookingId, array $actorUser, string $reason = ''): array
    {
        $res = delete_booking_record($pdo, $bookingId, $actorUser, true);
        if ($res['ok']) {
            $res['message'] = str_replace('ลบข้อมูลคิวงาน', 'ยกเลิกและลบคำขอ', $res['message']);
        }
        return $res;
    }
}

if (!function_exists('delete_booking_record')) {
    /**
     * Permanently delete a booking queue record from the database.
     *
     * @return array{ok:bool, message:string}
     */
    function delete_booking_record(PDO $pdo, int $bookingId, array $actorUser, bool $allowAnyOwnerStatus = false): array
    {
        if ($bookingId <= 0) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานที่ต้องการลบ'];
        }
        $stmt = $pdo->prepare('SELECT id, reference_code, requester_id, status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานนี้ในระบบ'];
        }
        $uid = (int)$actorUser['id'];
        $isOwner = ((int)$row['requester_id'] === $uid);
        $isPrivileged = has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $actorUser);
        if (!$isPrivileged && !($isOwner && ($allowAnyOwnerStatus || (string)$row['status'] !== 'approved'))) {
            return ['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ลบข้อมูลคิวงานนี้'];
        }
        if (function_exists('ensure_saved_memos_schema')) {
            ensure_saved_memos_schema($pdo);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM approval_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            $pdo->prepare('DELETE FROM notification_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            try {
                if ($pdo->query("SHOW TABLES LIKE 'saved_memos'")->fetch()) {
                    $pdo->prepare('UPDATE saved_memos SET booking_id = NULL WHERE booking_id = :id')->execute([':id' => $bookingId]);
                }
            } catch (Throwable $ignored) {
                // Non-fatal if saved_memos is unavailable
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => 'ลบข้อมูลคิวงาน "' . $row['reference_code'] . '" ออกจากฐานข้อมูลเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'ไม่สามารถลบข้อมูลคิวงานได้'];
        }
    }
}

if (!function_exists('save_telegram_settings')) {
    /**
     * Save Telegram notification settings into system_settings.
     */
    function save_telegram_settings(PDO $pdo, bool $enabled, string $botToken, string $chatId): void
    {
        ensure_system_settings_schema($pdo);
        $pairs = [
            'telegram_enabled'   => $enabled ? '1' : '0',
            'telegram_bot_token' => trim($botToken),
            'telegram_chat_id'   => trim($chatId),
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
}

if (!function_exists('send_telegram_message')) {
    /**
     * Send an HTML message to Telegram Bot API if enabled and configured.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    function send_telegram_message(PDO $pdo, string $htmlMessage, bool $forceTest = false): array
    {
        $cfg = get_telegram_settings($pdo);
        if ((!$cfg['enabled'] && !$forceTest) || $cfg['bot_token'] === '' || $cfg['chat_id'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($cfg['bot_token']) . '/sendMessage';
        // rawurlencode encodes ':' in bot token; Telegram expects raw token with ':'
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $cfg['chat_id'],
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $code >= 200 && $code < 300) {
                    return ['ok' => true, 'skipped' => false, 'error' => null];
                }
                return ['ok' => false, 'skipped' => false, 'error' => $err ?: ('HTTP ' . $code)];
            }

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 4,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                return ['ok' => true, 'skipped' => false, 'error' => null];
            }
            return ['ok' => false, 'skipped' => false, 'error' => 'Request failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('is_qr_enabled')) {
    /**
     * Check whether QR code generation and scan shortcuts are enabled.
     */
    function is_qr_enabled(PDO $pdo): bool
    {
        ensure_system_settings_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'qr_enabled' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return $val === false ? true : ($val === '1');
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('set_qr_enabled')) {
    /**
     * Enable or disable QR code functionality in system_settings.
     */
    function set_qr_enabled(PDO $pdo, bool $enabled): void
    {
        ensure_system_settings_schema($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES ('qr_enabled', :val)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([':val' => $enabled ? '1' : '0']);
    }
}

if (!function_exists('ensure_booking_signature_schema')) {
    /**
     * Ensure bookings.requester_signature column exists.
     */
    function ensure_booking_signature_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'requester_signature'")->fetch()) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN requester_signature VARCHAR(255) NULL AFTER permission_letter_original_name');
            }
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('ensure_vehicle_form_meta_schema')) {
    /**
     * Ensure bookings.vehicle_form_meta column exists.
     */
    function ensure_vehicle_form_meta_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'vehicle_form_meta'")->fetch()) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN vehicle_form_meta TEXT NULL AFTER passenger_count');
            }
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('cancel_booking_record')) {
    /**
     * Cancel a booking by its requester or an approver/admin.
     *
     * @return array{ok:bool, message:string}
     */
    function cancel_booking_record(PDO $pdo, int $bookingId, array $actorUser, string $reason = ''): array
    {
        $res = delete_booking_record($pdo, $bookingId, $actorUser, true);
        if ($res['ok']) {
            $res['message'] = str_replace('ลบข้อมูลคิวงาน', 'ยกเลิกและลบคำขอ', $res['message']);
        }
        return $res;
    }
}

if (!function_exists('delete_booking_record')) {
    /**
     * Permanently delete a booking queue record from the database.
     *
     * @return array{ok:bool, message:string}
     */
    function delete_booking_record(PDO $pdo, int $bookingId, array $actorUser, bool $allowAnyOwnerStatus = false): array
    {
        if ($bookingId <= 0) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานที่ต้องการลบ'];
        }
        $stmt = $pdo->prepare('SELECT id, reference_code, requester_id, status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานนี้ในระบบ'];
        }
        $uid = (int)$actorUser['id'];
        $isOwner = ((int)$row['requester_id'] === $uid);
        $isPrivileged = has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $actorUser);
        if (!$isPrivileged && !($isOwner && ($allowAnyOwnerStatus || (string)$row['status'] !== 'approved'))) {
            return ['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ลบข้อมูลคิวงานนี้'];
        }
        if (function_exists('ensure_saved_memos_schema')) {
            ensure_saved_memos_schema($pdo);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM approval_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            $pdo->prepare('DELETE FROM notification_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            try {
                if ($pdo->query("SHOW TABLES LIKE 'saved_memos'")->fetch()) {
                    $pdo->prepare('UPDATE saved_memos SET booking_id = NULL WHERE booking_id = :id')->execute([':id' => $bookingId]);
                }
            } catch (Throwable $ignored) {
                // Non-fatal if saved_memos is unavailable
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => 'ลบข้อมูลคิวงาน "' . $row['reference_code'] . '" ออกจากฐานข้อมูลเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'ไม่สามารถลบข้อมูลคิวงานได้'];
        }
    }
}

if (!function_exists('save_telegram_settings')) {
    /**
     * Save Telegram notification settings into system_settings.
     */
    function save_telegram_settings(PDO $pdo, bool $enabled, string $botToken, string $chatId): void
    {
        ensure_system_settings_schema($pdo);
        $pairs = [
            'telegram_enabled'   => $enabled ? '1' : '0',
            'telegram_bot_token' => trim($botToken),
            'telegram_chat_id'   => trim($chatId),
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
}

if (!function_exists('send_telegram_message')) {
    /**
     * Send an HTML message to Telegram Bot API if enabled and configured.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    function send_telegram_message(PDO $pdo, string $htmlMessage, bool $forceTest = false): array
    {
        $cfg = get_telegram_settings($pdo);
        if ((!$cfg['enabled'] && !$forceTest) || $cfg['bot_token'] === '' || $cfg['chat_id'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($cfg['bot_token']) . '/sendMessage';
        // rawurlencode encodes ':' in bot token; Telegram expects raw token with ':'
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $cfg['chat_id'],
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $code >= 200 && $code < 300) {
                    return ['ok' => true, 'skipped' => false, 'error' => null];
                }
                return ['ok' => false, 'skipped' => false, 'error' => $err ?: ('HTTP ' . $code)];
            }

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 4,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                return ['ok' => true, 'skipped' => false, 'error' => null];
            }
            return ['ok' => false, 'skipped' => false, 'error' => 'Request failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('ensure_booking_classroom_schema')) {
    /**
     * Ensure bookings.class_level and bookings.class_room columns exist.
     */
    function ensure_booking_classroom_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'class_level'")->fetch()) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN class_level VARCHAR(50) NULL AFTER room_id');
            }
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'class_room'")->fetch()) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN class_room VARCHAR(80) NULL AFTER class_level');
            }
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('cancel_booking_record')) {
    /**
     * Cancel a booking by its requester or an approver/admin.
     *
     * @return array{ok:bool, message:string}
     */
    function cancel_booking_record(PDO $pdo, int $bookingId, array $actorUser, string $reason = ''): array
    {
        $res = delete_booking_record($pdo, $bookingId, $actorUser, true);
        if ($res['ok']) {
            $res['message'] = str_replace('ลบข้อมูลคิวงาน', 'ยกเลิกและลบคำขอ', $res['message']);
        }
        return $res;
    }
}

if (!function_exists('delete_booking_record')) {
    /**
     * Permanently delete a booking queue record from the database.
     *
     * @return array{ok:bool, message:string}
     */
    function delete_booking_record(PDO $pdo, int $bookingId, array $actorUser, bool $allowAnyOwnerStatus = false): array
    {
        if ($bookingId <= 0) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานที่ต้องการลบ'];
        }
        $stmt = $pdo->prepare('SELECT id, reference_code, requester_id, status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานนี้ในระบบ'];
        }
        $uid = (int)$actorUser['id'];
        $isOwner = ((int)$row['requester_id'] === $uid);
        $isPrivileged = has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $actorUser);
        if (!$isPrivileged && !($isOwner && ($allowAnyOwnerStatus || (string)$row['status'] !== 'approved'))) {
            return ['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ลบข้อมูลคิวงานนี้'];
        }
        if (function_exists('ensure_saved_memos_schema')) {
            ensure_saved_memos_schema($pdo);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM approval_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            $pdo->prepare('DELETE FROM notification_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            try {
                if ($pdo->query("SHOW TABLES LIKE 'saved_memos'")->fetch()) {
                    $pdo->prepare('UPDATE saved_memos SET booking_id = NULL WHERE booking_id = :id')->execute([':id' => $bookingId]);
                }
            } catch (Throwable $ignored) {
                // Non-fatal if saved_memos is unavailable
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => 'ลบข้อมูลคิวงาน "' . $row['reference_code'] . '" ออกจากฐานข้อมูลเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'ไม่สามารถลบข้อมูลคิวงานได้'];
        }
    }
}

if (!function_exists('save_telegram_settings')) {
    /**
     * Save Telegram notification settings into system_settings.
     */
    function save_telegram_settings(PDO $pdo, bool $enabled, string $botToken, string $chatId): void
    {
        ensure_system_settings_schema($pdo);
        $pairs = [
            'telegram_enabled'   => $enabled ? '1' : '0',
            'telegram_bot_token' => trim($botToken),
            'telegram_chat_id'   => trim($chatId),
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
}

if (!function_exists('send_telegram_message')) {
    /**
     * Send an HTML message to Telegram Bot API if enabled and configured.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    function send_telegram_message(PDO $pdo, string $htmlMessage, bool $forceTest = false): array
    {
        $cfg = get_telegram_settings($pdo);
        if ((!$cfg['enabled'] && !$forceTest) || $cfg['bot_token'] === '' || $cfg['chat_id'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($cfg['bot_token']) . '/sendMessage';
        // rawurlencode encodes ':' in bot token; Telegram expects raw token with ':'
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $cfg['chat_id'],
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $code >= 200 && $code < 300) {
                    return ['ok' => true, 'skipped' => false, 'error' => null];
                }
                return ['ok' => false, 'skipped' => false, 'error' => $err ?: ('HTTP ' . $code)];
            }

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 4,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                return ['ok' => true, 'skipped' => false, 'error' => null];
            }
            return ['ok' => false, 'skipped' => false, 'error' => 'Request failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('ensure_saved_memos_schema')) {
    /**
     * Ensure saved_memos archive table exists.
     */
    function ensure_saved_memos_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS saved_memos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    booking_id INT UNSIGNED NULL,
                    reference_code VARCHAR(50) NOT NULL,
                    booking_type VARCHAR(20) NOT NULL DEFAULT \'room\',
                    title VARCHAR(255) NOT NULL,
                    requester_name VARCHAR(150) NOT NULL,
                    booking_status VARCHAR(40) NOT NULL,
                    status_label VARCHAR(100) NOT NULL,
                    memo_html LONGTEXT NOT NULL,
                    saved_by INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_saved_memos_user (saved_by, created_at),
                    KEY idx_saved_memos_status (booking_status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('ensure_booking_cancelled_schema')) {
    /**
     * Ensure bookings.status ENUM includes 'cancelled'.
     */
    function ensure_booking_cancelled_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'status'")->fetch();
            $type = (string)($col['Type'] ?? '');
            if ($type !== '' && stripos($type, "'cancelled'") === false) {
                $pdo->exec("ALTER TABLE bookings MODIFY COLUMN status ENUM('pending_head', 'pending_vice', 'pending_director', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_head'");
            }
        } catch (Throwable $e) {
            // Non-fatal if DB account lacks DDL privileges
        }
    }
}

if (!function_exists('cancel_booking_record')) {
    /**
     * Cancel a booking by its requester or an approver/admin.
     *
     * @return array{ok:bool, message:string}
     */
    function cancel_booking_record(PDO $pdo, int $bookingId, array $actorUser, string $reason = ''): array
    {
        $res = delete_booking_record($pdo, $bookingId, $actorUser, true);
        if ($res['ok']) {
            $res['message'] = str_replace('ลบข้อมูลคิวงาน', 'ยกเลิกและลบคำขอ', $res['message']);
        }
        return $res;
    }
}

if (!function_exists('delete_booking_record')) {
    /**
     * Permanently delete a booking queue record from the database.
     *
     * @return array{ok:bool, message:string}
     */
    function delete_booking_record(PDO $pdo, int $bookingId, array $actorUser, bool $allowAnyOwnerStatus = false): array
    {
        if ($bookingId <= 0) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานที่ต้องการลบ'];
        }
        $stmt = $pdo->prepare('SELECT id, reference_code, requester_id, status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'ไม่พบข้อมูลคิวงานนี้ในระบบ'];
        }
        $uid = (int)$actorUser['id'];
        $isOwner = ((int)$row['requester_id'] === $uid);
        $isPrivileged = has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $actorUser);
        if (!$isPrivileged && !($isOwner && ($allowAnyOwnerStatus || (string)$row['status'] !== 'approved'))) {
            return ['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ลบข้อมูลคิวงานนี้'];
        }
        if (function_exists('ensure_saved_memos_schema')) {
            ensure_saved_memos_schema($pdo);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM approval_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            $pdo->prepare('DELETE FROM notification_logs WHERE booking_id = :id')->execute([':id' => $bookingId]);
            try {
                if ($pdo->query("SHOW TABLES LIKE 'saved_memos'")->fetch()) {
                    $pdo->prepare('UPDATE saved_memos SET booking_id = NULL WHERE booking_id = :id')->execute([':id' => $bookingId]);
                }
            } catch (Throwable $ignored) {
                // Non-fatal if saved_memos is unavailable
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => 'ลบข้อมูลคิวงาน "' . $row['reference_code'] . '" ออกจากฐานข้อมูลเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'ไม่สามารถลบข้อมูลคิวงานได้'];
        }
    }
}

if (!function_exists('get_telegram_settings')) {
    /**
     * Load Telegram notification settings from system_settings.
     *
     * @return array{enabled:bool, bot_token:string, chat_id:string}
     */
    function get_telegram_settings(PDO $pdo): array
    {
        ensure_system_settings_schema($pdo);
        $out = ['enabled' => false, 'bot_token' => '', 'chat_id' => ''];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('telegram_enabled', 'telegram_bot_token', 'telegram_chat_id')");
            foreach ($stmt->fetchAll() as $row) {
                $k = (string)$row['setting_key'];
                $v = (string)$row['setting_value'];
                if ($k === 'telegram_enabled') {
                    $out['enabled'] = ($v === '1');
                } elseif ($k === 'telegram_bot_token') {
                    $out['bot_token'] = $v;
                } elseif ($k === 'telegram_chat_id') {
                    $out['chat_id'] = $v;
                }
            }
        } catch (Throwable $e) {
            // Ignore read failure
        }
        return $out;
    }
}

if (!function_exists('save_telegram_settings')) {
    /**
     * Save Telegram notification settings into system_settings.
     */
    function save_telegram_settings(PDO $pdo, bool $enabled, string $botToken, string $chatId): void
    {
        ensure_system_settings_schema($pdo);
        $pairs = [
            'telegram_enabled'   => $enabled ? '1' : '0',
            'telegram_bot_token' => trim($botToken),
            'telegram_chat_id'   => trim($chatId),
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
}

if (!function_exists('send_telegram_message')) {
    /**
     * Send an HTML message to Telegram Bot API if enabled and configured.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    function send_telegram_message(PDO $pdo, string $htmlMessage, bool $forceTest = false): array
    {
        $cfg = get_telegram_settings($pdo);
        if ((!$cfg['enabled'] && !$forceTest) || $cfg['bot_token'] === '' || $cfg['chat_id'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($cfg['bot_token']) . '/sendMessage';
        // rawurlencode encodes ':' in bot token; Telegram expects raw token with ':'
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $cfg['chat_id'],
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $code >= 200 && $code < 300) {
                    return ['ok' => true, 'skipped' => false, 'error' => null];
                }
                return ['ok' => false, 'skipped' => false, 'error' => $err ?: ('HTTP ' . $code)];
            }

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 4,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                return ['ok' => true, 'skipped' => false, 'error' => null];
            }
            return ['ok' => false, 'skipped' => false, 'error' => 'Request failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}
