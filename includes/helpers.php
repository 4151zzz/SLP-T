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
            ROLE_ADMIN         => ['dashboard', 'approvals', 'users_manage', 'maintenance_manage', 'assets_borrow', 'qr_codes', 'calendar', 'book_room', 'book_vehicle', 'my_bookings', 'maintenance'],
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
