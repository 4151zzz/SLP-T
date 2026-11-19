<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('UPLOAD_ROOT')) {
    define('UPLOAD_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
}
if (!defined('STORAGE_ROOT')) {
    define('STORAGE_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'storage');
}
if (!defined('LOG_DIR')) {
    define('LOG_DIR', STORAGE_ROOT . DIRECTORY_SEPARATOR . 'logs');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'ระบบงานบริหารทั่วไป');
}
if (!defined('APP_ORG_NAME')) {
    define('APP_ORG_NAME', getenv('APP_ORG_NAME') ?: 'โรงเรียนสระหลวงพิทยาคม');
}
if (!defined('APP_DEVELOPERS')) {
    define('APP_DEVELOPERS', 'wunPiyapong & Chonthicha Puengpak');
}

if (!defined('NOTIFY_DRIVER')) {
    $driver = getenv('NOTIFY_DRIVER');
    if ($driver === false || $driver === '') {
        $driver = getenv('NOTIF_DRIVER');
    }
    define('NOTIFY_DRIVER', ($driver !== false && $driver !== '') ? $driver : 'log');
}
if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) {
    define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', getenv('MAIL_FROM') ?: 'noreply@slp-admin.local');
}

if (!defined('UPLOAD_MAX_BYTES')) {
    define('UPLOAD_MAX_BYTES', 2 * 1024 * 1024); // 2 MB
}
if (!defined('UPLOAD_SUBDIRS')) {
    define('UPLOAD_SUBDIRS', ['permissions', 'maintenance', 'signatures']);
}
if (!defined('UPLOAD_ALLOWED_MIMES')) {
    define('UPLOAD_ALLOWED_MIMES', [
        'maintenance' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ],
        'permissions' => [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ],
        'signatures' => [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
        ],
    ]);
}

// User types & roles
if (!defined('USER_TYPE_INTERNAL')) {
    define('USER_TYPE_INTERNAL', 'internal');
}
if (!defined('USER_TYPE_EXTERNAL')) {
    define('USER_TYPE_EXTERNAL', 'external');
}
if (!defined('ROLE_USER')) {
    define('ROLE_USER', 'user');
}
if (!defined('ROLE_EXTERNAL_USER')) {
    define('ROLE_EXTERNAL_USER', 'external_user');
}
if (!defined('ROLE_HEAD')) {
    define('ROLE_HEAD', 'head');
}
if (!defined('ROLE_VICE_DIRECTOR')) {
    define('ROLE_VICE_DIRECTOR', 'vice_director');
}
if (!defined('ROLE_DIRECTOR')) {
    define('ROLE_DIRECTOR', 'director');
}
if (!defined('ROLE_STAFF')) {
    define('ROLE_STAFF', 'staff');
}
if (!defined('ROLE_ADMIN')) {
    define('ROLE_ADMIN', 'admin');
}

if (!defined('INTERNAL_ROLES')) {
    define('INTERNAL_ROLES', [
        ROLE_USER,
        ROLE_HEAD,
        ROLE_VICE_DIRECTOR,
        ROLE_DIRECTOR,
        ROLE_STAFF,
        ROLE_ADMIN,
    ]);
}
if (!defined('PRIVILEGED_VIEW_ROLES')) {
    define('PRIVILEGED_VIEW_ROLES', [
        ROLE_STAFF,
        ROLE_HEAD,
        ROLE_VICE_DIRECTOR,
        ROLE_DIRECTOR,
        ROLE_ADMIN,
    ]);
}
if (!defined('ROLE_LABELS')) {
    define('ROLE_LABELS', [
        ROLE_USER          => 'บุคลากรภายใน',
        ROLE_EXTERNAL_USER => 'บุคคลภายนอก',
        ROLE_HEAD          => 'หัวหน้างาน',
        ROLE_VICE_DIRECTOR => 'รองผู้อำนวยการ',
        ROLE_DIRECTOR      => 'ผู้อำนวยการ',
        ROLE_STAFF         => 'เจ้าหน้าที่บริหาร/ช่าง',
        ROLE_ADMIN         => 'ผู้ดูแลระบบ',
    ]);
}

// Booking workflow statuses
if (!defined('BOOKING_STATUS_PENDING_HEAD')) {
    define('BOOKING_STATUS_PENDING_HEAD', 'pending_head');
}
if (!defined('BOOKING_STATUS_PENDING_VICE')) {
    define('BOOKING_STATUS_PENDING_VICE', 'pending_vice');
}
if (!defined('BOOKING_STATUS_PENDING_DIRECTOR')) {
    define('BOOKING_STATUS_PENDING_DIRECTOR', 'pending_director');
}
if (!defined('BOOKING_STATUS_APPROVED')) {
    define('BOOKING_STATUS_APPROVED', 'approved');
}
if (!defined('BOOKING_STATUS_REJECTED')) {
    define('BOOKING_STATUS_REJECTED', 'rejected');
}
if (!defined('BOOKING_STATUS_LABELS')) {
    define('BOOKING_STATUS_LABELS', [
        BOOKING_STATUS_PENDING_HEAD     => ['label' => 'รอหัวหน้างาน',     'badge' => 'warning text-dark'],
        BOOKING_STATUS_PENDING_VICE     => ['label' => 'รอรองผู้อำนวยการ', 'badge' => 'info text-dark'],
        BOOKING_STATUS_PENDING_DIRECTOR => ['label' => 'รอผู้อำนวยการ',   'badge' => 'primary'],
        BOOKING_STATUS_APPROVED         => ['label' => 'อนุมัติแล้ว',       'badge' => 'success'],
        BOOKING_STATUS_REJECTED         => ['label' => 'ไม่อนุมัติ',        'badge' => 'danger'],
    ]);
}

// Maintenance repair statuses (exact R4 Thai ENUMs)
if (!defined('REPAIR_STATUS_WAITING')) {
    define('REPAIR_STATUS_WAITING', 'รอรับเรื่อง');
}
if (!defined('REPAIR_STATUS_IN_PROGRESS')) {
    define('REPAIR_STATUS_IN_PROGRESS', 'กำลังซ่อม');
}
if (!defined('REPAIR_STATUS_WAIT_PARTS')) {
    define('REPAIR_STATUS_WAIT_PARTS', 'รออะไหล่');
}
if (!defined('REPAIR_STATUS_DONE')) {
    define('REPAIR_STATUS_DONE', 'เสร็จสิ้น');
}
if (!defined('REPAIR_STATUSES')) {
    define('REPAIR_STATUSES', [
        REPAIR_STATUS_WAITING,
        REPAIR_STATUS_IN_PROGRESS,
        REPAIR_STATUS_WAIT_PARTS,
        REPAIR_STATUS_DONE,
    ]);
}
if (!defined('REPAIR_STATUS_BADGES')) {
    define('REPAIR_STATUS_BADGES', [
        REPAIR_STATUS_WAITING     => 'secondary',
        REPAIR_STATUS_IN_PROGRESS => 'primary',
        REPAIR_STATUS_WAIT_PARTS  => 'warning text-dark',
        REPAIR_STATUS_DONE        => 'success',
    ]);
}

// Room optional equipment labels
if (!defined('ROOM_EQUIPMENT_LABELS')) {
    define('ROOM_EQUIPMENT_LABELS', [
        'microphone'   => 'ไมโครโฟน',
        'projector'    => 'โปรเจคเตอร์',
        'audio_system' => 'ระบบเครื่องเสียง',
    ]);
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $envBase = getenv('APP_BASE_PATH');
        if ($envBase !== false) {
            if ($envBase === '' || $envBase === '/') {
                return '';
            }
            $normalized = str_replace('\\', '/', trim($envBase));
            $normalized = '/' . trim($normalized, '/');
            return $normalized === '/' ? '' : $normalized;
        }

        if (PHP_SAPI === 'cli' || empty($_SERVER['DOCUMENT_ROOT'])) {
            return '';
        }

        $docRoot = realpath((string)$_SERVER['DOCUMENT_ROOT']);
        $appRoot = realpath(APP_ROOT);
        if ($docRoot === false || $appRoot === false) {
            return '';
        }

        $docNorm = rtrim(str_replace('\\', '/', $docRoot), '/');
        $appNorm = rtrim(str_replace('\\', '/', $appRoot), '/');

        $cmpApp = DIRECTORY_SEPARATOR === '\\' ? strtolower($appNorm) : $appNorm;
        $cmpDoc = DIRECTORY_SEPARATOR === '\\' ? strtolower($docNorm) : $docNorm;

        if ($cmpApp === $cmpDoc) {
            return '';
        }
        if (str_starts_with($cmpApp, $cmpDoc . '/')) {
            $sub = substr($appNorm, strlen($docNorm));
            $sub = '/' . trim($sub, '/');
            return $sub === '/' ? '' : $sub;
        }

        return '';
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        return $scheme . '://' . $host . app_base_path();
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = '/'): string
    {
        $clean = '/' . ltrim(str_replace('\\', '/', $path), '/');
        return app_base_path() . $clean;
    }
}
