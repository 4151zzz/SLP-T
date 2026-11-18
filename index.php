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
$menuItems = nav_items($user);
$pendingCount = pending_approval_count($user);
$role = (string)($user['role'] ?? ROLE_USER);
$roleLabel = ROLE_LABELS[$role] ?? $role;
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'สำนักงานบริหารทั่วไป';
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

    if ($viewMode === 'executive' || $viewMode === 'approver_head') {
        $whereQueue = match ($role) {
            ROLE_DIRECTOR      => "b.status = 'pending_director'",
            ROLE_VICE_DIRECTOR => "b.status = 'pending_vice'",
            ROLE_HEAD          => "b.status = 'pending_head'",
            ROLE_ADMIN         => "b.status IN ('pending_head', 'pending_vice', 'pending_director')",
            default            => '1 = 0',
        };
        $rows = $pdo->query(
            "SELECT b.booking_type, b.title, b.start_datetime, b.status, u.full_name AS requester_name
             FROM bookings b
             INNER JOIN users u ON u.id = b.requester_id
             WHERE {$whereQueue}
             ORDER BY b.start_datetime ASC
             LIMIT 3"
        )->fetchAll();

        foreach ($rows as $row) {
            $meta = $statusLabelsMap[$row['status']] ?? ['label' => $row['status'], 'badge' => 'secondary'];
            $isRoom = ($row['booking_type'] === 'room');
            $priorityItems[] = [
                'icon'       => $isRoom ? 'bi-door-open' : 'bi-truck',
                'iconBox'    => $isRoom ? 'icon-box-indigo' : 'icon-box-teal',
                'title'      => (string)$row['title'],
                'subtitle'   => $row['requester_name'] . ' · ' . thai_date($row['start_datetime'], true, true),
                'badgeLabel' => (string)$meta['label'],
                'badgeClass' => (string)$meta['badge'],
                'ctaHref'    => app_url('/approvals.php'),
                'ctaLabel'   => 'พิจารณา',
            ];
        }
    } elseif ($viewMode === 'operational_staff') {
        $vehRows = $pdo->query(
            "SELECT b.title, b.start_datetime, u.full_name AS requester_name
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
            ];
        }

        $remain = 3 - count($priorityItems);
        if ($remain > 0) {
            $maintRows = $pdo->query(
                "SELECT m.equipment_name, m.issue_description, m.location_text, m.urgency, m.status, m.reported_at
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
                ];
            }
        }
    } else {
        $stmtRecent = $pdo->prepare(
            "SELECT b.booking_type, b.title, b.start_datetime, b.destination, b.status, r.name AS room_name
             FROM bookings b
             LEFT JOIN rooms r ON r.id = b.room_id
             WHERE b.requester_id = :uid
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT 3"
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
                'ctaHref'    => app_url('/my_bookings.php'),
                'ctaLabel'   => 'ดูรายละเอียด',
            ];
        }
    }
} catch (Throwable $e) {
    // Keep safe empty defaults on query failure
}

$kpiCards = match ($viewMode) {
    'executive', 'approver_head' => [
        ['label' => 'รออนุมัติ',   'value' => $pendingCount,               'icon' => 'bi-hourglass-split',   'color' => 'kpi-icon-amber'],
        ['label' => 'อนุมัติแล้ว', 'value' => $stats['approved_bookings'], 'icon' => 'bi-check-circle-fill', 'color' => 'kpi-icon-emerald'],
        ['label' => 'ห้องประชุม',  'value' => $stats['rooms'],             'icon' => 'bi-door-open-fill',    'color' => 'kpi-icon-blue'],
        ['label' => 'งานแจ้งซ่อม', 'value' => $stats['open_maint'],        'icon' => 'bi-wrench-adjustable', 'color' => 'kpi-icon-indigo'],
    ],
    'operational_staff' => [
        ['label' => 'รอจัดรถ',     'value' => $pendingCount,            'icon' => 'bi-truck-front-fill',  'color' => 'kpi-icon-amber'],
        ['label' => 'งานซ่อมค้าง',   'value' => $stats['open_maint'],     'icon' => 'bi-tools',             'color' => 'kpi-icon-indigo'],
        ['label' => 'กำลังถูกยืม',   'value' => $stats['active_borrows'], 'icon' => 'bi-key-fill',          'color' => 'kpi-icon-emerald'],
        ['label' => 'รถพร้อมใช้',   'value' => $stats['vehicles'],       'icon' => 'bi-car-front-fill',    'color' => 'kpi-icon-blue'],
    ],
    default => [
        ['label' => 'คำขอทั้งหมด',  'value' => $stats['my_bookings'], 'icon' => 'bi-card-checklist', 'color' => 'kpi-icon-indigo'],
        ['label' => 'รอพิจารณา',    'value' => $stats['my_pending'],  'icon' => 'bi-clock-history',  'color' => 'kpi-icon-amber'],
        ['label' => 'อนุมัติแล้ว',   'value' => $stats['my_approved'], 'icon' => 'bi-check2-circle',  'color' => 'kpi-icon-emerald'],
        ['label' => 'ห้องประชุม',   'value' => ($role === ROLE_EXTERNAL_USER ? $stats['external_rooms'] : $stats['rooms']), 'icon' => 'bi-door-open-fill', 'color' => 'kpi-icon-blue'],
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

$shortDescriptions = [
    'calendar'           => 'ดูตารางการใช้ห้องและรถ',
    'book_room'          => 'ขอใช้ห้องประชุม/สถานที่',
    'book_vehicle'       => 'ขอใช้รถยนต์ราชการ',
    'my_bookings'        => 'ติดตามสถานะคำขอ',
    'approvals'          => 'อนุมัติคำขอในระบบ',
    'maintenance'        => 'แจ้งอุปกรณ์หรือสถานที่ชำรุด',
    'maintenance_manage' => 'อัปเดตสถานะงานซ่อม',
    'assets_borrow'      => 'ยืมและคืนพัสดุ/กุญแจ',
    'qr_codes'           => 'สร้าง QR ประจำห้อง/อุปกรณ์',
    'dashboard'          => 'ดูสรุปสถิติและกราฟ',
    'users_manage'       => 'เพิ่มและกำหนดสิทธิ์ผู้ใช้',
];

$iconColorMap = [
    'calendar'           => 'icon-box-blue',
    'book_room'          => 'icon-box-indigo',
    'book_vehicle'       => 'icon-box-teal',
    'my_bookings'        => 'icon-box-cyan',
    'approvals'          => 'icon-box-amber',
    'maintenance'        => 'icon-box-rose',
    'maintenance_manage' => 'icon-box-orange',
    'assets_borrow'      => 'icon-box-emerald',
    'qr_codes'           => 'icon-box-purple',
    'dashboard'          => 'icon-box-slate',
    'users_manage'       => 'icon-box-blue',
];

$welcomeInitial = mb_substr((string)$user['full_name'], 0, 1, 'UTF-8') ?: 'U';

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Zone 1: Clean Welcome Header Card -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3 min-w-0">
            <div class="welcome-avatar d-none d-sm-inline-flex"><?= e($welcomeInitial) ?></div>
            <div class="min-w-0">
                <div class="small text-muted mb-1">
                    <i class="bi bi-calendar3 me-1"></i><?= e($todayThai) ?> · <?= e($appOrgName) ?>
                </div>
                <h1 class="h4 fw-bold text-dark mb-2" data-testid="portal-heading">
                    สวัสดี, <?= e($user['full_name']) ?>
                </h1>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="welcome-role-badge">
                        <i class="bi bi-person-check-fill"></i>
                        <?= e($roleLabel) ?>
                    </span>
                    <?php if ($userOrgOrDept !== ''): ?>
                        <span class="small text-muted"><?= e($userOrgOrDept) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            <?php if ($pendingCount > 0): ?>
                <a href="<?= e(app_url('/approvals.php')) ?>"
                   class="btn btn-warning fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 shadow-sm"
                   data-testid="pending-approval-banner">
                    <i class="bi bi-bell-fill"></i>
                    <span>มีคำขอรอพิจารณา <?= (int)$pendingCount ?> รายการ</span>
                </a>
            <?php endif; ?>

            <?php if ($role === ROLE_ADMIN): ?>
                <button type="button"
                        class="btn btn-outline-primary bg-white fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3"
                        data-bs-toggle="modal"
                        data-bs-target="#quickAddUserModal"
                        data-testid="btn-quick-add-user">
                    <i class="bi bi-person-plus-fill"></i>
                    <span>เพิ่มผู้ใช้งาน</span>
                </button>
            <?php endif; ?>

            <?php if ($viewMode === 'executive'): ?>
                <a href="<?= e(app_url('/dashboard.php')) ?>"
                   class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-speedometer2"></i>
                    <span>เปิดแดชบอร์ดผู้บริหาร</span>
                </a>
            <?php elseif ($viewMode === 'approver_head' && $pendingCount === 0): ?>
                <a href="<?= e(app_url('/approvals.php')) ?>"
                   class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-check2-square"></i>
                    <span>พิจารณาอนุมัติ</span>
                </a>
            <?php elseif ($viewMode === 'operational_staff'): ?>
                <a href="<?= e(app_url('/maintenance_manage.php')) ?>"
                   class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-tools"></i>
                    <span>จัดการงานซ่อม</span>
                </a>
            <?php elseif ($viewMode === 'requester'): ?>
                <a href="<?= e(app_url('/book_room.php')) ?>"
                   class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-plus-circle"></i>
                    <span><?= $role === ROLE_EXTERNAL_USER ? 'ขอจองห้องประชุม' : 'จองห้องประชุม' ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Zone 2: 4 Clean Big-Number KPI Cards -->
<div class="row g-2 g-sm-3 mb-4">
    <?php foreach ($kpiCards as $kpi): ?>
        <div class="col-6 col-xl-3">
            <div class="kpi-card p-3 h-100 d-flex align-items-center gap-3">
                <div class="kpi-icon <?= e($kpi['color']) ?>">
                    <i class="bi <?= e($kpi['icon']) ?>"></i>
                </div>
                <div class="min-w-0 flex-grow-1">
                    <div class="kpi-label text-truncate mb-1"><?= e($kpi['label']) ?></div>
                    <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$kpi['value'] ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Zone 3A: Single Focused Priority List -->
<div class="spotlight-card mb-4" data-testid="role-spotlight">
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
                <a href="<?= e(app_url('/book_room.php')) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 mt-2">
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
                        <a href="<?= e($item['ctaHref']) ?>" class="btn btn-sm btn-outline-primary rounded-3 px-3">
                            <?= e($item['ctaLabel']) ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Zone 3B: Clean Menu Grid -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="h6 fw-bold text-secondary mb-0">เมนูลัด</h2>
    <span class="small text-muted"><?= count($menuItems) ?> รายการ</span>
</div>

<div class="row g-3" data-testid="portal-quick-actions">
    <?php foreach ($menuItems as $item):
        $iconClass = $iconColorMap[$item['key']] ?? 'icon-box-blue';
    ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <a href="<?= e($item['href']) ?>"
               class="card h-100 text-decoration-none text-dark portal-card"
               data-testid="card-<?= e($item['key']) ?>">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="portal-icon-box <?= e($iconClass) ?>" style="width: 46px; height: 46px; font-size: 1.25rem;">
                        <i class="bi <?= e($item['icon']) ?>"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2">
                            <h3 class="h6 fw-bold mb-0 text-dark text-truncate"><?= e($item['label']) ?></h3>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="badge rounded-pill text-bg-danger"><?= (int)$item['badge'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small text-truncate"><?= e($shortDescriptions[$item['key']] ?? '') ?></div>
                    </div>
                    <i class="bi bi-chevron-right portal-arrow flex-shrink-0"></i>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($role === ROLE_ADMIN): ?>
<!-- Quick Add User Modal for Admin -->
<div class="modal fade" id="quickAddUserModal" tabindex="-1" aria-labelledby="quickAddUserModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="quickAddUserModalTitle">
                    <span class="portal-icon-box icon-box-blue" style="width: 36px; height: 36px; font-size: 1rem;">
                        <i class="bi bi-person-plus-fill"></i>
                    </span>
                    <span>เพิ่มผู้ใช้งานใหม่</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/users_manage.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="q_user_type" class="form-label fw-semibold small text-secondary">ประเภทผู้ใช้งาน <span class="text-danger">*</span></label>
                            <select class="form-select touch-target" id="q_user_type" name="user_type">
                                <option value="internal" selected>บุคลากรภายในหน่วยงาน</option>
                                <option value="external">บุคคล / หน่วยงานภายนอก</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="q_role" class="form-label fw-semibold small text-secondary">บทบาทสิทธิ์ (Role) <span class="text-danger">*</span></label>
                            <select class="form-select touch-target" id="q_role" name="role">
                                <option value="user">บุคลากรภายใน</option>
                                <option value="head">หัวหน้างาน</option>
                                <option value="vice_director">รองผู้อำนวยการ</option>
                                <option value="director">ผู้อำนวยการ</option>
                                <option value="staff">เจ้าหน้าที่บริหาร/ช่าง</option>
                                <option value="admin">ผู้ดูแลระบบ</option>
                                <option value="external_user">บุคคลภายนอก</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label for="q_full_name" class="form-label fw-semibold small text-secondary">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="q_full_name" name="full_name" placeholder="เช่น นายสมศักดิ์ รักงาน" required>
                        </div>
                        <div class="col-md-4">
                            <label for="q_staff_id" class="form-label fw-semibold small text-secondary">รหัสพนักงาน</label>
                            <input type="text" class="form-control touch-target" id="q_staff_id" name="staff_id" placeholder="เช่น EMP003">
                        </div>
                        <div class="col-md-6">
                            <label for="q_username" class="form-label fw-semibold small text-secondary">ชื่อผู้ใช้ (Username) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="q_username" name="username" placeholder="เช่น user03" required>
                        </div>
                        <div class="col-md-6">
                            <label for="q_email" class="form-label fw-semibold small text-secondary">อีเมล <span class="text-danger">*</span></label>
                            <input type="email" class="form-control touch-target" id="q_email" name="email" placeholder="name@slp.local" required>
                        </div>
                        <div class="col-md-6">
                            <label for="q_password" class="form-label fw-semibold small text-secondary">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) <span class="text-danger">*</span></label>
                            <input type="password" class="form-control touch-target" id="q_password" name="password" minlength="8" placeholder="กำหนดรหัสผ่านเริ่มต้น" required>
                        </div>
                        <div class="col-md-6">
                            <label for="q_phone" class="form-label fw-semibold small text-secondary">เบอร์โทรศัพท์</label>
                            <input type="tel" class="form-control touch-target" id="q_phone" name="phone" placeholder="08x-xxx-xxxx">
                        </div>
                        <div class="col-md-6">
                            <label for="q_department" class="form-label fw-semibold small text-secondary">ฝ่าย / กลุ่มงาน</label>
                            <input type="text" class="form-control touch-target" id="q_department" name="department" placeholder="เช่น ฝ่ายบริหารทั่วไป">
                        </div>
                        <div class="col-md-6">
                            <label for="q_organization" class="form-label fw-semibold small text-secondary">หน่วยงาน / องค์กร</label>
                            <input type="text" class="form-control touch-target" id="q_organization" name="organization" value="<?= e($appOrgName) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light px-4 py-3">
                    <a href="<?= e(app_url('/users_manage.php')) ?>" class="btn btn-link text-decoration-none me-auto small">ไปหน้าจัดการผู้ใช้ทั้งหมด</a>
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>บันทึกผู้ใช้งาน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const t = document.getElementById('q_user_type');
    const r = document.getElementById('q_role');
    const s = document.getElementById('q_staff_id');
    if (!t || !r || !s) return;
    function syncQ() {
        const ext = t.value === 'external';
        if (ext) { r.value = 'external_user'; s.value = ''; s.disabled = true; }
        else { if (r.value === 'external_user') r.value = 'user'; s.disabled = false; }
        Array.from(r.options).forEach(function (o) {
            o.disabled = ext ? (o.value !== 'external_user') : (o.value === 'external_user');
        });
    }
    t.addEventListener('change', syncQ);
    syncQ();
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
