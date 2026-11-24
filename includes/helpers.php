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
                'key'    => 'dashboard',
                'href'   => app_url('/dashboard.php'),
                'label'  => 'แดชบอร์ดผู้บริหาร',
                'icon'   => 'bi-speedometer2',
                'testid' => 'nav-dashboard',
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
            ROLE_DIRECTOR      => ['dashboard', 'approvals', 'calendar', 'my_bookings', 'book_room', 'book_vehicle', 'assets_borrow', 'maintenance'],
            ROLE_VICE_DIRECTOR => ['dashboard', 'approvals', 'calendar', 'my_bookings', 'book_room', 'book_vehicle', 'assets_borrow', 'maintenance'],
            ROLE_HEAD          => ['approvals', 'dashboard', 'calendar', 'book_room', 'book_vehicle', 'my_bookings', 'assets_borrow', 'maintenance'],
            ROLE_STAFF         => ['approvals', 'maintenance_manage', 'assets_borrow', 'qr_codes', 'calendar', 'dashboard', 'book_room', 'book_vehicle', 'my_bookings', 'maintenance'],
            ROLE_ADMIN         => ['dashboard', 'approvals', 'users_manage', 'resources_manage', 'maintenance_manage', 'assets_borrow', 'qr_codes', 'calendar', 'book_room', 'book_vehicle', 'my_bookings', 'maintenance'],
            ROLE_USER          => ['calendar', 'book_room', 'book_vehicle', 'my_bookings', 'assets_borrow', 'maintenance'],
            ROLE_EXTERNAL_USER => ['book_room', 'my_bookings', 'calendar', 'maintenance'],
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
