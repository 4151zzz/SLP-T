<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = current_user();
if ($user === null) {
    redirect(app_url('/login.php'));
}

$pageTitle = 'หน้าหลัก';
$activeNav = 'index';
$pendingCount = pending_approval_count($user);
$role = (string)($user['role'] ?? ROLE_USER);
$roleLabel = ROLE_LABELS[$role] ?? $role;
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'โรงเรียนสระหลวงพิทยาคม';
$todayThai = thai_date(date('Y-m-d'));
$userOrgOrDept = (string)($user['department'] ?: ($user['organization'] ?: ''));

$viewMode = match (true) {
    in_array($role, [ROLE_DIRECTOR, ROLE_VICE_DIRECTOR, ROLE_ADMIN], true) => 'executive',
    $role === ROLE_HEAD  => 'approver_head',
    $role === ROLE_STAFF => 'operational_staff',
    default              => 'requester',
};

$stats = [
    'rooms'             => 0,
    'external_rooms'    => 0,
    'vehicles'          => 0,
    'approved_bookings' => 0,
    'my_bookings'       => 0,
    'my_pending'        => 0,
    'my_approved'       => 0,
    'open_maint'        => 0,
    'active_borrows'    => 0,
];

$priorityItems = [];
$scheduleItems = [];

$shortThaiMonths = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

try {
    $pdo = get_pdo();
    $uid = (int)$user['id'];

    $stats['rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM rooms WHERE is_active = 1')->fetchColumn();
    $stats['external_rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM rooms WHERE is_active = 1 AND allow_external = 1')->fetchColumn();
    $stats['vehicles'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'available'")->fetchColumn();
    $stats['approved_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'approved'")->fetchColumn();
    $stats['open_maint'] = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status != 'เสร็จสิ้น'")->fetchColumn();
    $stats['active_borrows'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_borrowings WHERE status IN ('borrowed', 'overdue')")->fetchColumn();

    $stmtMy = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN status IN ('pending_head', 'pending_vice', 'pending_director') THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_cnt
         FROM bookings
         WHERE requester_id = :uid"
    );
    $stmtMy->execute([':uid' => $uid]);
    $myRow = $stmtMy->fetch();
    if ($myRow) {
        $stats['my_bookings'] = (int)($myRow['total_cnt'] ?? 0);
        $stats['my_pending'] = (int)($myRow['pending_cnt'] ?? 0);
        $stats['my_approved'] = (int)($myRow['approved_cnt'] ?? 0);
    }

    $statusLabelsMap = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];
    $repairBadgesMap = defined('REPAIR_STATUS_BADGES') ? REPAIR_STATUS_BADGES : [];

    // Focused Priority Queue (max 4 items)
    if ($viewMode === 'executive' || $viewMode === 'approver_head') {
        $whereQueue = match ($role) {
            ROLE_DIRECTOR      => "b.status = 'pending_director'",
            ROLE_VICE_DIRECTOR => "b.status = 'pending_vice'",
            ROLE_HEAD          => "b.status = 'pending_head'",
            ROLE_ADMIN         => "b.status IN ('pending_head', 'pending_vice', 'pending_director')",
            default            => '1 = 0',
        };
        $rows = $pdo->query(
            "SELECT b.id, b.reference_code, b.booking_type, b.title, b.purpose, b.start_datetime, b.end_datetime,
                    b.status, b.destination, u.full_name AS requester_name, r.room_code, r.name AS room_name
             FROM bookings b
             INNER JOIN users u ON u.id = b.requester_id
             LEFT JOIN rooms r ON r.id = b.room_id
             WHERE {$whereQueue}
             ORDER BY b.start_datetime ASC
             LIMIT 4"
        )->fetchAll();

        foreach ($rows as $row) {
            $meta = $statusLabelsMap[$row['status']] ?? ['label' => $row['status'], 'badge' => 'secondary'];
            $isRoom = ($row['booking_type'] === 'room');
            $placeLabel = $isRoom ? trim(($row['room_code'] ?? '') . ' ' . ($row['room_name'] ?? '')) : (string)($row['destination'] ?: '-');
            $priorityItems[] = [
                'icon'       => $isRoom ? 'bi-door-open' : 'bi-truck',
                'iconBox'    => $isRoom ? 'icon-box-indigo' : 'icon-box-teal',
                'title'      => (string)$row['title'],
                'subtitle'   => $row['requester_name'] . ' · ' . thai_date($row['start_datetime'], true, true),
                'badgeLabel' => (string)$meta['label'],
                'badgeClass' => (string)$meta['badge'],
                'ctaHref'    => app_url('/approvals.php'),
                'ctaLabel'   => 'พิจารณา',
                'detail'     => [
                    'title'     => (string)$row['title'],
                    'subtitle'  => $row['reference_code'] . ' · ' . $row['requester_name'],
                    'badge'     => (string)$meta['label'],
                    'rows'      => [
                        ['label' => 'ประเภท', 'value' => $isRoom ? 'จองห้องประชุม' : 'จองยานพาหนะ'],
                        ['label' => $isRoom ? 'ห้องประชุม' : 'ปลายทาง', 'value' => $placeLabel ?: '-'],
                        ['label' => 'วันเวลา', 'value' => thai_date($row['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($row['end_datetime'])) . ' น.'],
                    ],
                    'noteTitle' => 'วัตถุประสงค์',
                    'note'      => (string)($row['purpose'] ?: ''),
                    'links'     => [
                        ['label' => 'ไปหน้าพิจารณาอนุมัติ', 'href' => app_url('/approvals.php'), 'variant' => 'primary'],
                        ['label' => 'ดูไทม์ไลน์', 'href' => app_url('/booking_detail.php?id=' . (int)$row['id']), 'variant' => 'secondary'],
                    ],
                ],
            ];
        }
    } elseif ($viewMode === 'operational_staff') {
        $vehRows = $pdo->query(
            "SELECT b.id, b.reference_code, b.title, b.destination, b.start_datetime, b.end_datetime, u.full_name AS requester_name
             FROM bookings b
             INNER JOIN users u ON u.id = b.requester_id
             WHERE b.status = 'pending_head' AND b.booking_type = 'vehicle' AND (b.vehicle_id IS NULL OR b.driver_id IS NULL)
             ORDER BY b.start_datetime ASC
             LIMIT 2"
        )->fetchAll();
        foreach ($vehRows as $vr) {
            $priorityItems[] = [
                'icon'       => 'bi-truck',
                'iconBox'    => 'icon-box-teal',
                'title'      => (string)$vr['title'],
                'subtitle'   => 'ผู้ขอ: ' . $vr['requester_name'] . ' · ' . thai_date($vr['start_datetime'], true, true),
                'badgeLabel' => 'รอจัดรถ/คนขับ',
                'badgeClass' => 'warning text-dark',
                'ctaHref'    => app_url('/approvals.php'),
                'ctaLabel'   => 'จัดรถ',
                'detail'     => [
                    'title'    => (string)$vr['title'],
                    'subtitle' => $vr['reference_code'] . ' · ' . $vr['requester_name'],
                    'badge'    => 'รอจัดรถ/คนขับ',
                    'rows'     => [
                        ['label' => 'ปลายทาง', 'value' => (string)($vr['destination'] ?: '-')],
                        ['label' => 'วันเวลา', 'value' => thai_date($vr['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($vr['end_datetime'])) . ' น.'],
                    ],
                    'links'    => [
                        ['label' => 'จัดรถและคนขับ', 'href' => app_url('/approvals.php'), 'variant' => 'primary'],
                    ],
                ],
            ];
        }

        $remain = 4 - count($priorityItems);
        if ($remain > 0) {
            $maintRows = $pdo->query(
                "SELECT m.ticket_code, m.equipment_name, m.issue_description, m.location_text, m.urgency, m.status, m.reported_at, m.repair_notes
                 FROM maintenance_requests m
                 WHERE m.status != 'เสร็จสิ้น'
                 ORDER BY (m.urgency = 'urgent') DESC, m.reported_at DESC
                 LIMIT {$remain}"
            )->fetchAll();
            foreach ($maintRows as $mr) {
                $isUrgent = ($mr['urgency'] === 'urgent');
                $badgeCls = $isUrgent ? 'danger' : ($repairBadgesMap[$mr['status']] ?? 'secondary');
                $badgeTxt = $isUrgent ? 'งานด่วน · ' . $mr['status'] : (string)$mr['status'];
                $priorityItems[] = [
                    'icon'       => 'bi-wrench-adjustable',
                    'iconBox'    => $isUrgent ? 'icon-box-rose' : 'icon-box-orange',
                    'title'      => (string)($mr['equipment_name'] ?: $mr['issue_description']),
                    'subtitle'   => $mr['location_text'] . ' · ' . thai_date($mr['reported_at'], false, true),
                    'badgeLabel' => $badgeTxt,
                    'badgeClass' => $badgeCls,
                    'ctaHref'    => app_url('/maintenance_manage.php'),
                    'ctaLabel'   => 'อัปเดต',
                    'detail'     => [
                        'title'     => (string)($mr['equipment_name'] ?: 'งานซ่อม'),
                        'subtitle'  => $mr['ticket_code'] . ' · ' . thai_date($mr['reported_at'], true, true),
                        'badge'     => $badgeTxt,
                        'rows'      => [
                            ['label' => 'สถานที่',   'value' => (string)$mr['location_text']],
                            ['label' => 'อาการชำรุด', 'value' => (string)$mr['issue_description']],
                        ],
                        'noteTitle' => 'บันทึกช่างล่าสุด',
                        'note'      => (string)($mr['repair_notes'] ?: ''),
                        'links'     => [
                            ['label' => 'ไปหน้าจัดการงานซ่อม', 'href' => app_url('/maintenance_manage.php'), 'variant' => 'primary'],
                        ],
                    ],
                ];
            }
        }
    } else {
        $stmtRecent = $pdo->prepare(
            "SELECT b.id, b.reference_code, b.booking_type, b.title, b.purpose, b.start_datetime, b.end_datetime, b.destination, b.status, r.name AS room_name
             FROM bookings b
             LEFT JOIN rooms r ON r.id = b.room_id
             WHERE b.requester_id = :uid
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT 4"
        );
        $stmtRecent->execute([':uid' => $uid]);
        $recentRows = $stmtRecent->fetchAll();

        foreach ($recentRows as $rr) {
            $meta = $statusLabelsMap[$rr['status']] ?? ['label' => $rr['status'], 'badge' => 'secondary'];
            $isRoom = ($rr['booking_type'] === 'room');
            $place = $isRoom ? ($rr['room_name'] ?: 'ห้องประชุม') : ($rr['destination'] ?: 'ยานพาหนะ');
            $priorityItems[] = [
                'icon'       => $isRoom ? 'bi-door-open' : 'bi-truck',
                'iconBox'    => $isRoom ? 'icon-box-indigo' : 'icon-box-teal',
                'title'      => (string)$rr['title'],
                'subtitle'   => $place . ' · ' . thai_date($rr['start_datetime'], true, true),
                'badgeLabel' => (string)$meta['label'],
                'badgeClass' => (string)$meta['badge'],
                'ctaHref'    => app_url('/booking_detail.php?id=' . (int)$rr['id']),
                'ctaLabel'   => 'ดูไทม์ไลน์',
                'detail'     => [
                    'title'     => (string)$rr['title'],
                    'subtitle'  => $rr['reference_code'],
                    'badge'     => (string)$meta['label'],
                    'rows'      => [
                        ['label' => $isRoom ? 'ห้องประชุม' : 'ปลายทาง', 'value' => $place],
                        ['label' => 'วันเวลา', 'value' => thai_date($rr['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($rr['end_datetime'])) . ' น.'],
                    ],
                    'noteTitle' => 'วัตถุประสงค์',
                    'note'      => (string)($rr['purpose'] ?: ''),
                    'links'     => [
                        ['label' => 'เปิดหน้าไทม์ไลน์เต็ม', 'href' => app_url('/booking_detail.php?id=' . (int)$rr['id']), 'variant' => 'primary'],
                        ['label' => 'พิมพ์บันทึกข้อความ', 'href' => app_url('/print_memo.php?id=' . (int)$rr['id']), 'variant' => 'secondary'],
                    ],
                ],
            ];
        }
    }

    // Upcoming Schedule & Events (right column, max 4 items)
    $rawSchedule = [];
    $isPrivilegedViewer = has_role(PRIVILEGED_VIEW_ROLES, $user);

    $evSql = ($role === ROLE_EXTERNAL_USER)
        ? 'SELECT title, event_type, description, location, start_datetime, end_datetime, is_all_day FROM calendar_events WHERE end_datetime >= NOW() AND is_public = 1 ORDER BY start_datetime ASC LIMIT 4'
        : 'SELECT title, event_type, description, location, start_datetime, end_datetime, is_all_day FROM calendar_events WHERE end_datetime >= NOW() ORDER BY start_datetime ASC LIMIT 4';
    foreach ($pdo->query($evSql)->fetchAll() as $ev) {
        $evTime = ((int)$ev['is_all_day'] === 1)
            ? 'ตลอดวัน'
            : (date('H:i', strtotime($ev['start_datetime'])) . ' – ' . date('H:i', strtotime($ev['end_datetime'])) . ' น.');
        $rawSchedule[] = [
            'sort_ts'  => (string)$ev['start_datetime'],
            'color'    => '#7C3AED',
            'category' => 'กิจกรรมองค์กร',
            'title'    => (string)$ev['title'],
            'meta'     => $evTime . (!empty($ev['location']) ? ' · ' . $ev['location'] : ''),
            'detail'   => [
                'title'     => (string)$ev['title'],
                'subtitle'  => thai_date($ev['start_datetime'], false, true),
                'badge'     => 'กิจกรรมหน่วยงาน',
                'rows'      => [
                    ['label' => 'เวลา',    'value' => $evTime],
                    ['label' => 'สถานที่', 'value' => (string)($ev['location'] ?: '-')],
                ],
                'noteTitle' => 'รายละเอียด',
                'note'      => (string)($ev['description'] ?: ''),
                'links'     => [
                    ['label' => 'เปิดปฏิทินหลัก', 'href' => app_url('/calendar.php'), 'variant' => 'primary'],
                ],
            ],
        ];
    }

    $bkSql = "SELECT b.id, b.reference_code, b.booking_type, b.title, b.purpose, b.start_datetime, b.end_datetime, b.destination, r.room_code, r.name AS room_name
              FROM bookings b
              LEFT JOIN rooms r ON r.id = b.room_id
              WHERE b.status = 'approved' AND b.end_datetime >= NOW()";
    $bkParams = [];
    if (!$isPrivilegedViewer) {
        $bkSql .= ' AND b.requester_id = :uid';
        $bkParams[':uid'] = $uid;
    }
    $bkSql .= ' ORDER BY b.start_datetime ASC LIMIT 4';
    $stmtBkSched = $pdo->prepare($bkSql);
    $stmtBkSched->execute($bkParams);
    foreach ($stmtBkSched->fetchAll() as $bk) {
        $isRm = ($bk['booking_type'] === 'room');
        $place = $isRm ? trim(($bk['room_code'] ?? '') . ' ' . ($bk['room_name'] ?? '')) : (string)($bk['destination'] ?: '-');
        $bkTime = date('H:i', strtotime($bk['start_datetime'])) . ' – ' . date('H:i', strtotime($bk['end_datetime'])) . ' น.';
        $rawSchedule[] = [
            'sort_ts'  => (string)$bk['start_datetime'],
            'color'    => $isRm ? '#2563EB' : '#059669',
            'category' => $isRm ? 'ห้องประชุม' : 'ยานพาหนะ',
            'title'    => (string)$bk['title'],
            'meta'     => $bkTime . ($place !== '' ? ' · ' . $place : ''),
            'detail'   => [
                'title'     => (string)$bk['title'],
                'subtitle'  => $bk['reference_code'] . ' · อนุมัติแล้ว',
                'badge'     => $isRm ? 'จองห้องประชุม' : 'จองยานพาหนะ',
                'rows'      => [
                    ['label' => 'วันเวลา', 'value' => thai_date($bk['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($bk['end_datetime'])) . ' น.'],
                    ['label' => $isRm ? 'ห้องประชุม' : 'ปลายทาง', 'value' => $place ?: '-'],
                ],
                'noteTitle' => 'วัตถุประสงค์',
                'note'      => (string)($bk['purpose'] ?: ''),
                'links'     => [
                    ['label' => 'ดูไทม์ไลน์คำขอ', 'href' => app_url('/booking_detail.php?id=' . (int)$bk['id']), 'variant' => 'primary'],
                    ['label' => 'เปิดปฏิทินหลัก', 'href' => app_url('/calendar.php'), 'variant' => 'secondary'],
                ],
            ],
        ];
    }

    usort($rawSchedule, static fn (array $a, array $b): int => strcmp($a['sort_ts'], $b['sort_ts']));
    $rawSchedule = array_slice($rawSchedule, 0, 4);

    foreach ($rawSchedule as $sc) {
        $ts = strtotime($sc['sort_ts']);
        $dayNum = $ts ? (int)date('j', $ts) : 1;
        $monNum = $ts ? (int)date('n', $ts) : 1;
        $scheduleItems[] = [
            'day'      => (string)$dayNum,
            'month'    => $shortThaiMonths[$monNum] ?? '',
            'color'    => $sc['color'],
            'category' => $sc['category'],
            'title'    => $sc['title'],
            'meta'     => $sc['meta'],
            'detail'   => $sc['detail'],
        ];
    }
} catch (Throwable $e) {
    // Keep safe empty defaults on query failure
}

$kpiCards = match ($viewMode) {
    'executive', 'approver_head' => [
        ['label' => 'รออนุมัติ',   'value' => $pendingCount,               'icon' => 'bi-hourglass-split',   'color' => 'kpi-icon-amber',   'accent' => 'kpi-accent-amber',   'href' => app_url('/approvals.php')],
        ['label' => 'อนุมัติแล้ว', 'value' => $stats['approved_bookings'], 'icon' => 'bi-check-circle-fill', 'color' => 'kpi-icon-emerald', 'accent' => 'kpi-accent-emerald', 'href' => app_url('/dashboard.php')],
        ['label' => 'ห้องประชุม',  'value' => $stats['rooms'],             'icon' => 'bi-door-open-fill',    'color' => 'kpi-icon-blue',    'accent' => 'kpi-accent-blue',    'href' => app_url($role === ROLE_ADMIN ? '/resources_manage.php' : '/calendar.php')],
        ['label' => 'งานแจ้งซ่อม', 'value' => $stats['open_maint'],        'icon' => 'bi-wrench-adjustable', 'color' => 'kpi-icon-indigo',  'accent' => 'kpi-accent-indigo',  'href' => app_url(in_array($role, [ROLE_STAFF, ROLE_ADMIN], true) ? '/maintenance_manage.php' : '/maintenance.php')],
    ],
    'operational_staff' => [
        ['label' => 'รอจัดรถ',     'value' => $pendingCount,            'icon' => 'bi-truck-front-fill', 'color' => 'kpi-icon-amber',   'accent' => 'kpi-accent-amber',   'href' => app_url('/approvals.php')],
        ['label' => 'งานซ่อมค้าง', 'value' => $stats['open_maint'],     'icon' => 'bi-tools',            'color' => 'kpi-icon-indigo',  'accent' => 'kpi-accent-indigo',  'href' => app_url('/maintenance_manage.php')],
        ['label' => 'กำลังถูกยืม', 'value' => $stats['active_borrows'], 'icon' => 'bi-key-fill',         'color' => 'kpi-icon-emerald', 'accent' => 'kpi-accent-emerald', 'href' => app_url('/dashboard.php')],
        ['label' => 'รถพร้อมใช้',  'value' => $stats['vehicles'],       'icon' => 'bi-car-front-fill',   'color' => 'kpi-icon-blue',    'accent' => 'kpi-accent-blue',    'href' => app_url('/calendar.php')],
    ],
    default => [
        ['label' => 'คำขอทั้งหมด', 'value' => $stats['my_bookings'], 'icon' => 'bi-card-checklist', 'color' => 'kpi-icon-indigo',  'accent' => 'kpi-accent-indigo',  'href' => app_url('/my_bookings.php')],
        ['label' => 'รอพิจารณา',   'value' => $stats['my_pending'],  'icon' => 'bi-clock-history',  'color' => 'kpi-icon-amber',   'accent' => 'kpi-accent-amber',   'href' => app_url('/my_bookings.php')],
        ['label' => 'อนุมัติแล้ว',  'value' => $stats['my_approved'], 'icon' => 'bi-check2-circle',  'color' => 'kpi-icon-emerald', 'accent' => 'kpi-accent-emerald', 'href' => app_url('/my_bookings.php')],
        ['label' => 'ห้องประชุม',  'value' => ($role === ROLE_EXTERNAL_USER ? $stats['external_rooms'] : $stats['rooms']), 'icon' => 'bi-door-open-fill', 'color' => 'kpi-icon-blue', 'accent' => 'kpi-accent-blue', 'href' => app_url('/book_room.php')],
    ],
};

$priorityTitle = match ($viewMode) {
    'executive', 'approver_head' => 'รายการรอพิจารณาอนุมัติ',
    'operational_staff'          => 'งานเร่งด่วนที่ต้องจัดการ',
    default                      => 'คำขอของฉันล่าสุด',
};
$priorityAllHref = match ($viewMode) {
    'executive', 'approver_head' => app_url('/approvals.php'),
    'operational_staff'          => app_url('/maintenance_manage.php'),
    default                      => app_url('/my_bookings.php'),
};
$priorityEmptyText = match ($viewMode) {
    'executive', 'approver_head' => 'ไม่มีรายการรอพิจารณาในขณะนี้',
    'operational_staff'          => 'ไม่มีงานค้างที่ต้องดำเนินการในขณะนี้',
    default                      => 'ยังไม่มีรายการคำขอจอง',
};

$welcomeInitial = mb_substr((string)$user['full_name'], 0, 1, 'UTF-8') ?: 'U';

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Zone 1: Gradient Hero Header -->
<div class="home-hero p-3 p-md-4 mb-4">
    <div class="home-hero-inner d-flex flex-column gap-3">
        <div class="d-flex align-items-center gap-3 min-w-0">
            <div class="home-hero-avatar d-none d-sm-inline-flex"><?= e($welcomeInitial) ?></div>
            <div class="min-w-0 flex-grow-1">
                <div class="small text-white text-opacity-75 mb-1">
                    <i class="bi bi-calendar3 me-1"></i><?= e($todayThai) ?> · <?= e($appOrgName) ?>
                </div>
                <h1 class="h4 fw-bold text-white mb-2" data-testid="portal-heading">
                    สวัสดี, <?= e($user['full_name']) ?>
                </h1>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="home-hero-role-pill">
                        <i class="bi bi-person-check-fill"></i>
                        <?= e($roleLabel) ?>
                    </span>
                    <?php if ($userOrgOrDept !== ''): ?>
                        <span class="small text-white text-opacity-75"><?= e($userOrgOrDept) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 pt-2 border-top border-white border-opacity-15">
            <?php if ($pendingCount > 0): ?>
                <a href="<?= e(app_url('/approvals.php')) ?>"
                   class="btn btn-hero-amber fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="pending-approval-banner">
                    <i class="bi bi-bell-fill"></i>
                    <span>มีคำขอรอพิจารณา <?= (int)$pendingCount ?> รายการ</span>
                </a>
            <?php endif; ?>

            <?php if ($viewMode === 'executive'): ?>
                <a href="<?= e(app_url('/dashboard.php')) ?>"
                   class="btn btn-hero-white fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-speedometer2"></i>
                    <span>เปิดแดชบอร์ดผู้บริหาร</span>
                </a>
            <?php elseif ($viewMode === 'approver_head' && $pendingCount === 0): ?>
                <a href="<?= e(app_url('/approvals.php')) ?>"
                   class="btn btn-hero-white fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-check2-square"></i>
                    <span>พิจารณาอนุมัติ</span>
                </a>
            <?php elseif ($viewMode === 'operational_staff'): ?>
                <a href="<?= e(app_url('/maintenance_manage.php')) ?>"
                   class="btn btn-hero-white fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-tools"></i>
                    <span>จัดการงานซ่อม</span>
                </a>
            <?php elseif ($viewMode === 'requester'): ?>
                <a href="<?= e(app_url('/book_room.php')) ?>"
                   class="btn btn-hero-white fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-plus-circle"></i>
                    <span><?= $role === ROLE_EXTERNAL_USER ? 'ขอจองห้องประชุม' : 'จองห้องประชุม' ?></span>
                </a>
            <?php endif; ?>

            <?php if ($role === ROLE_ADMIN): ?>
                <a href="<?= e(app_url('/resources_manage.php')) ?>"
                   class="btn btn-hero-glass fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="btn-quick-manage-resources">
                    <i class="bi bi-building-fill-gear"></i>
                    <span>จัดการอาคาร/ห้อง/รถ</span>
                </a>
                <a href="<?= e(app_url('/users_manage.php?add=1')) ?>"
                   class="btn btn-hero-glass fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="btn-quick-add-user">
                    <i class="bi bi-person-plus-fill"></i>
                    <span>เพิ่มผู้ใช้งาน</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Zone 2: 4 Clickable KPI Stat Cards -->
<div class="row g-2 g-sm-3 mb-4">
    <?php foreach ($kpiCards as $kpi): ?>
        <div class="col-6 col-xl-3">
            <a href="<?= e($kpi['href']) ?>" class="kpi-link-card">
                <div class="kpi-card <?= e($kpi['accent']) ?> p-3 h-100 d-flex align-items-center gap-3">
                    <div class="kpi-icon <?= e($kpi['color']) ?>">
                        <i class="bi <?= e($kpi['icon']) ?>"></i>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                        <div class="kpi-label text-truncate mb-1"><?= e($kpi['label']) ?></div>
                        <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$kpi['value'] ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Zone 3: 2-Column Workspace (Priority Queue + Upcoming Schedule) -->
<div class="row g-3 g-lg-4">
    <div class="col-12 col-lg-7">
        <div class="spotlight-card h-100" data-testid="role-spotlight">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-stars text-primary"></i>
                    <span><?= e($priorityTitle) ?></span>
                </h2>
                <a href="<?= e($priorityAllHref) ?>" class="small fw-semibold text-decoration-none">
                    ดูทั้งหมด <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <?php if (empty($priorityItems)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-check-circle text-success fs-3 d-block mb-2"></i>
                    <div class="small"><?= e($priorityEmptyText) ?></div>
                    <?php if ($viewMode === 'requester'): ?>
                        <a href="<?= e(app_url('/book_room.php')) ?>" class="btn btn-sm btn-soft-primary rounded-pill px-3 mt-2">
                            จองห้องประชุมเลย
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($priorityItems as $item): ?>
                    <div class="role-queue-item">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="portal-icon-box <?= e($item['iconBox']) ?>" style="width: 42px; height: 42px; font-size: 1.15rem;">
                                    <i class="bi <?= e($item['icon']) ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-dark text-truncate"><?= e($item['title']) ?></div>
                                    <div class="small text-muted text-truncate"><?= e($item['subtitle']) ?></div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-shrink-0 align-self-end align-self-sm-center">
                                <span class="badge rounded-pill text-bg-<?= e($item['badgeClass']) ?> px-3 py-2 fw-medium">
                                    <?= e($item['badgeLabel']) ?>
                                </span>
                                <?php if (!empty($item['detail'])): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-soft-secondary rounded-3 px-2"
                                            data-swal-detail="<?= e((string)json_encode($item['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            title="ดูรายละเอียดเร็ว">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                <?php endif; ?>
                                <a href="<?= e($item['ctaHref']) ?>" class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold">
                                    <?= e($item['ctaLabel']) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="spotlight-card h-100" data-testid="home-upcoming-schedule">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event text-primary"></i>
                    <span>กำหนดการและกิจกรรมเร็ว ๆ นี้</span>
                </h2>
                <a href="<?= e(app_url('/calendar.php')) ?>" class="small fw-semibold text-decoration-none">
                    ปฏิทินทั้งหมด <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <?php if (empty($scheduleItems)): ?>
                <div class="p-4 text-center text-muted small">
                    <i class="bi bi-calendar-x fs-3 d-block mb-2 text-secondary"></i>
                    ยังไม่มีกำหนดการเร็ว ๆ นี้
                </div>
            <?php else: ?>
                <?php foreach ($scheduleItems as $sc): ?>
                    <button type="button"
                            class="role-queue-item w-100 text-start border-0 bg-transparent d-flex align-items-center gap-3"
                            data-swal-detail="<?= e((string)json_encode($sc['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                        <div class="mini-cal-badge">
                            <span class="mini-cal-day"><?= e($sc['day']) ?></span>
                            <span class="mini-cal-month"><?= e($sc['month']) ?></span>
                        </div>
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: <?= e($sc['color']) ?>; display: inline-block; flex-shrink: 0;"></span>
                                <span class="text-muted" style="font-size: 0.74rem;"><?= e($sc['category']) ?></span>
                            </div>
                            <div class="fw-semibold text-dark text-truncate small"><?= e($sc['title']) ?></div>
                            <div class="small text-muted text-truncate"><?= e($sc['meta']) ?></div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small flex-shrink-0"></i>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
