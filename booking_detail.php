<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();

$rawId = trim((string)($_GET['id'] ?? ''));
if ($rawId === '' || !ctype_digit($rawId) || (int)$rawId <= 0) {
    http_response_code(400);
    $pageTitle = 'คำขอไม่ถูกต้อง';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger rounded-4">รหัสคำขอจองไม่ถูกต้อง (400 Bad Request)</div>';
    echo '<a href="' . e(app_url('/my_bookings.php')) . '" class="btn btn-outline-primary">กลับหน้ารายการคำขอ</a>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$bookingId = (int)$rawId;
$pdo = get_pdo();

$stmt = $pdo->prepare(
    'SELECT b.*,
            u.full_name AS requester_name, u.position_title AS requester_position,
            u.department AS requester_dept, u.organization AS requester_org,
            u.phone AS requester_phone, u.email AS requester_email,
            r.room_code, r.name AS room_name, r.building AS room_building, r.floor AS room_floor,
            v.vehicle_code, v.plate_number, v.vehicle_type, v.brand_model, v.seats AS vehicle_seats,
            d.driver_code, d.full_name AS driver_name, d.phone AS driver_phone
     FROM bookings b
     INNER JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     LEFT JOIN drivers d ON d.id = b.driver_id
     WHERE b.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    $pageTitle = 'ไม่พบคำขอจอง';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-warning rounded-4">ไม่พบรายการคำขอจองที่ระบุ (404 Not Found)</div>';
    echo '<a href="' . e(app_url('/my_bookings.php')) . '" class="btn btn-outline-primary">กลับหน้ารายการคำขอ</a>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$isOwner = ((int)$booking['requester_id'] === (int)$user['id']);
$isPrivileged = has_role(PRIVILEGED_VIEW_ROLES, $user);

if (!$isOwner && !$isPrivileged) {
    http_response_code(403);
    $home = e(app_url('/index.php'));
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — ไม่มีสิทธิ์เข้าถึง</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="card shadow-sm border-danger" style="max-width: 480px; width: 100%;">
        <div class="card-body text-center p-4">
            <h1 class="display-6 text-danger fw-bold mb-2">403 Forbidden</h1>
            <p class="mb-3">คุณไม่มีสิทธิ์ดูรายละเอียดคำขอจองรายการนี้</p>
            <a href="{$home}" class="btn btn-primary">กลับสู่หน้าหลัก</a>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

$stmtLogs = $pdo->prepare(
    'SELECT * FROM approval_logs
     WHERE booking_id = :id
     ORDER BY step_number ASC, acted_at ASC, id ASC'
);
$stmtLogs->execute([':id' => $bookingId]);
$logs = $stmtLogs->fetchAll();

$statusCode = (string)$booking['status'];
$currentStep = (int)$booking['current_step'];
$statusMeta = BOOKING_STATUS_LABELS[$statusCode] ?? ['label' => $statusCode, 'badge' => 'secondary'];
$roleLabels = ROLE_LABELS;

$rejectedStep = $currentStep;
foreach ($logs as $lg) {
    if ($lg['action'] === 'rejected') {
        $rejectedStep = (int)$lg['step_number'];
    }
}

$stepClasses = [
    1 => 'is-completed', // ยื่นคำขอ
    2 => 'is-pending',   // หัวหน้างาน
    3 => 'is-pending',   // รองผู้อำนวยการ
    4 => 'is-pending',   // ผู้อำนวยการอนุมัติ
];

if ($statusCode === BOOKING_STATUS_PENDING_HEAD) {
    $stepClasses[2] = 'is-current';
} elseif ($statusCode === BOOKING_STATUS_PENDING_VICE) {
    $stepClasses[2] = 'is-completed';
    $stepClasses[3] = 'is-current';
} elseif ($statusCode === BOOKING_STATUS_PENDING_DIRECTOR) {
    $stepClasses[2] = 'is-completed';
    $stepClasses[3] = 'is-completed';
    $stepClasses[4] = 'is-current';
} elseif ($statusCode === BOOKING_STATUS_APPROVED) {
    $stepClasses[2] = 'is-completed';
    $stepClasses[3] = 'is-completed';
    $stepClasses[4] = 'is-completed';
} elseif ($statusCode === BOOKING_STATUS_REJECTED) {
    if ($rejectedStep <= 1) {
        $stepClasses[2] = 'is-rejected';
    } elseif ($rejectedStep === 2) {
        $stepClasses[2] = 'is-completed';
        $stepClasses[3] = 'is-rejected';
    } else {
        $stepClasses[2] = 'is-completed';
        $stepClasses[3] = 'is-completed';
        $stepClasses[4] = 'is-rejected';
    }
}

$stepperDefs = [
    1 => ['title' => 'ยื่นคำขอ',          'sub' => 'ส่งคำขอเข้าสู่ระบบ'],
    2 => ['title' => 'หัวหน้างาน',        'sub' => 'ตรวจสอบเบื้องต้น'],
    3 => ['title' => 'รองผู้อำนวยการ',    'sub' => 'กลั่นกรองความเหมาะสม'],
    4 => ['title' => 'ผู้อำนวยการอนุมัติ', 'sub' => 'อนุมัติขั้นสุดท้าย'],
];

$equipmentKeys = [];
if (!empty($booking['equipment_requested'])) {
    $decodedEq = json_decode((string)$booking['equipment_requested'], true);
    if (is_array($decodedEq)) {
        $equipmentKeys = $decodedEq;
    }
}
$equipmentNames = [];
foreach ($equipmentKeys as $ek) {
    $equipmentNames[] = ROOM_EQUIPMENT_LABELS[$ek] ?? (string)$ek;
}

$actionBadges = [
    'approved'         => ['label' => 'เห็นชอบ / อนุมัติ', 'badge' => 'success'],
    'rejected'         => ['label' => 'ไม่อนุมัติ',       'badge' => 'danger'],
    'returned'         => ['label' => 'ส่งกลับแก้ไข',     'badge' => 'warning text-dark'],
    'assigned_vehicle' => ['label' => 'จัดสรรรถ/คนขับ',   'badge' => 'info text-dark'],
];

$isRoom = ($booking['booking_type'] === 'room');
$backUrl = $isPrivileged && !$isOwner ? app_url('/approvals.php') : app_url('/my_bookings.php');

$pageTitle = 'รายละเอียดคำขอ ' . (string)$booking['reference_code'];
$activeNav = $isOwner ? 'my_bookings' : 'approvals';

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge text-bg-light border text-dark font-monospace fs-6"><?= e($booking['reference_code']) ?></span>
                <span class="badge <?= $isRoom ? 'text-bg-primary' : 'text-bg-success' ?>">
                    <?= $isRoom ? 'จองห้องประชุม' : 'จองยานพาหนะ' ?>
                </span>
                <span class="badge text-bg-<?= e($statusMeta['badge']) ?>"><?= e($statusMeta['label']) ?></span>
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="booking-detail-title"><?= e($booking['title']) ?></h1>
            <div class="small text-muted">ยื่นคำขอเมื่อ <?= e(thai_date($booking['created_at'], true)) ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <?php
            $detailTimelineSwal = [];
            foreach ($logs as $lgSwal) {
                $detailTimelineSwal[] = [
                    'step'    => $roleLabels[$lgSwal['step_role']] ?? $lgSwal['step_role'],
                    'status'  => ($actionBadges[$lgSwal['action']]['label'] ?? $lgSwal['action']),
                    'signer'  => (string)$lgSwal['signer_name'],
                    'time'    => thai_date($lgSwal['acted_at'], true, true),
                    'comment' => (string)($lgSwal['comment'] ?? ''),
                ];
            }
            $quickSigPayload = [
                'title'     => (string)$booking['title'],
                'subtitle'  => $booking['reference_code'] . ' · สรุปลายเซ็นและการพิจารณา',
                'badge'     => (string)$statusMeta['label'],
                'rows'      => [
                    ['label' => 'ผู้ขอ',     'value' => (string)$booking['requester_name']],
                    ['label' => 'ช่วงเวลา',  'value' => thai_date($booking['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($booking['end_datetime'])) . ' น.'],
                ],
                'timeline'  => $detailTimelineSwal,
            ];
            ?>
            <button type="button"
                    class="btn btn-soft-secondary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                    data-swal-detail="<?= e((string)json_encode($quickSigPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                <i class="bi bi-pen"></i>
                <span>สรุปลายเซ็น</span>
            </button>
            <a href="<?= e(app_url('/print_memo.php?id=' . $bookingId)) ?>"
               class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
               data-testid="print-memo-link">
                <i class="bi bi-printer-fill"></i>
                <span>พิมพ์บันทึกข้อความ (A4)</span>
            </a>
            <a href="<?= e($backUrl) ?>"
               class="btn btn-soft-secondary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                <i class="bi bi-arrow-left"></i>
                <span>กลับ</span>
            </a>
        </div>
    </div>
</div>

<!-- 4-Step Approval Progress Stepper -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h2 class="h6 fw-bold mb-0"><i class="bi bi-signpost-split me-2 text-primary"></i>ความคืบหน้าการพิจารณา (4 ขั้นตอน)</h2>
    </div>
    <div class="card-body p-3 p-md-4">
        <div class="stepper" data-testid="approval-stepper">
            <?php foreach ($stepperDefs as $idx => $def):
                $cls = $stepClasses[$idx] ?? 'is-pending';
                $iconHtml = match ($cls) {
                    'is-completed' => '<i class="bi bi-check-lg"></i>',
                    'is-rejected'  => '<i class="bi bi-x-lg"></i>',
                    default        => (string)$idx,
                };
            ?>
                <div class="stepper-step <?= e($cls) ?>">
                    <div class="stepper-dot"><?= $iconHtml ?></div>
                    <div>
                        <div class="fw-bold small text-dark"><?= e($def['title']) ?></div>
                        <div class="text-muted" style="font-size: 0.78rem;"><?= e($def['sub']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($statusCode === BOOKING_STATUS_REJECTED && !empty($booking['rejection_reason'])): ?>
            <div class="alert alert-danger rounded-3 mt-4 mb-0">
                <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>เหตุผลที่ไม่อนุมัติ:</strong>
                <?= e($booking['rejection_reason']) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Booking & Requester Details -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-file-text me-2 text-primary"></i>รายละเอียดคำขอ</h2>
            </div>
            <div class="card-body p-3 p-md-4">
                <dl class="row mb-0 small g-2">
                    <dt class="col-sm-4 text-muted">ผู้ขอ</dt>
                    <dd class="col-sm-8 fw-semibold text-dark"><?= e($booking['requester_name']) ?></dd>

                    <dt class="col-sm-4 text-muted">ตำแหน่ง</dt>
                    <dd class="col-sm-8"><?= e($booking['requester_position'] ?: '-') ?></dd>

                    <dt class="col-sm-4 text-muted">หน่วยงาน/สังกัด</dt>
                    <dd class="col-sm-8">
                        <?php
                        $deptParts = array_filter([
                            $booking['requester_dept'] ?? '',
                            $booking['requester_org'] ?? '',
                        ]);
                        echo e(!empty($deptParts) ? implode(' · ', array_unique($deptParts)) : '-');
                        ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">โทรศัพท์</dt>
                    <dd class="col-sm-8"><?= e($booking['requester_phone'] ?: '-') ?></dd>

                    <dt class="col-sm-4 text-muted">วันเวลาเริ่มต้น</dt>
                    <dd class="col-sm-8 fw-semibold text-primary"><?= e(thai_date($booking['start_datetime'], true)) ?></dd>

                    <dt class="col-sm-4 text-muted">วันเวลาสิ้นสุด</dt>
                    <dd class="col-sm-8 fw-semibold text-primary"><?= e(thai_date($booking['end_datetime'], true)) ?></dd>

                    <dt class="col-sm-4 text-muted">วัตถุประสงค์</dt>
                    <dd class="col-sm-8"><?= e($booking['purpose'] ?: '-') ?></dd>

                    <?php if ($isRoom): ?>
                        <dt class="col-sm-4 text-muted">ห้องประชุม</dt>
                        <dd class="col-sm-8 fw-semibold">
                            <?= e(($booking['room_code'] ? $booking['room_code'] . ' — ' : '') . ($booking['room_name'] ?: '-')) ?>
                            <?php if (!empty($booking['room_building'])): ?>
                                <div class="text-muted fw-normal"><?= e($booking['room_building'] . ' ' . ($booking['room_floor'] ?? '')) ?></div>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4 text-muted">จำนวนผู้เข้าร่วม</dt>
                        <dd class="col-sm-8"><?= (int)($booking['attendee_count'] ?? 0) ?> คน</dd>

                        <dt class="col-sm-4 text-muted">อุปกรณ์ที่ขอใช้</dt>
                        <dd class="col-sm-8">
                            <?= !empty($equipmentNames) ? e(implode(', ', $equipmentNames)) : 'ไม่ได้ขอใช้อุปกรณ์เสริม' ?>
                        </dd>

                        <?php if (!empty($booking['permission_letter_path'])): ?>
                            <dt class="col-sm-4 text-muted">หนังสือขออนุญาต</dt>
                            <dd class="col-sm-8">
                                <a href="<?= e(app_url('/download.php?type=permission&booking_id=' . $bookingId . '&download=1')) ?>"
                                   class="btn btn-sm btn-outline-primary rounded-3">
                                    <i class="bi bi-download me-1"></i>
                                    <?= e($booking['permission_letter_original_name'] ?: 'ดาวน์โหลดเอกสารแนบ') ?>
                                </a>
                            </dd>
                        <?php endif; ?>
                    <?php else: ?>
                        <dt class="col-sm-4 text-muted">สถานที่ปลายทาง</dt>
                        <dd class="col-sm-8 fw-semibold"><?= e($booking['destination'] ?: '-') ?></dd>

                        <dt class="col-sm-4 text-muted">จำนวนผู้โดยสาร</dt>
                        <dd class="col-sm-8"><?= (int)($booking['passenger_count'] ?? 0) ?> คน</dd>

                        <dt class="col-sm-4 text-muted">ยานพาหนะ</dt>
                        <dd class="col-sm-8">
                            <?php if (!empty($booking['vehicle_code'])): ?>
                                <div class="fw-semibold text-dark">
                                    <?= e($booking['vehicle_code'] . ' · ' . $booking['brand_model']) ?>
                                </div>
                                <div class="text-muted">ทะเบียน <?= e($booking['plate_number']) ?> (<?= (int)$booking['vehicle_seats'] ?> ที่นั่ง)</div>
                            <?php else: ?>
                                <span class="text-muted">รอเจ้าหน้าที่จัดสรรยานพาหนะ</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4 text-muted">พนักงานขับรถ</dt>
                        <dd class="col-sm-8">
                            <?php if (!empty($booking['driver_name'])): ?>
                                <span class="fw-semibold text-dark"><?= e($booking['driver_name']) ?></span>
                                <div class="text-muted">โทร. <?= e($booking['driver_phone'] ?: '-') ?></div>
                            <?php else: ?>
                                <span class="text-muted">รอเจ้าหน้าที่จัดสรรคนขับ</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Right: Approval History Timeline -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>ประวัติการลงนามพิจารณา</h2>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if (empty($logs)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-hourglass-split fs-2 d-block mb-2"></i>
                        <div class="small">อยู่ระหว่างรอหัวหน้างานพิจารณาในขั้นตอนที่ 1</div>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($logs as $lg):
                            $actInfo = $actionBadges[$lg['action']] ?? ['label' => $lg['action'], 'badge' => 'secondary'];
                            $roleTitle = $roleLabels[$lg['step_role']] ?? $lg['step_role'];
                        ?>
                            <div class="p-3 rounded-3 border bg-light">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <span class="fw-bold text-dark small">
                                        ขั้นตอนที่ <?= (int)$lg['step_number'] ?> · <?= e($roleTitle) ?>
                                    </span>
                                    <span class="badge text-bg-<?= e($actInfo['badge']) ?>"><?= e($actInfo['label']) ?></span>
                                </div>
                                <div class="small text-dark fw-semibold">
                                    <?= e($lg['signer_name']) ?>
                                    <?php if (!empty($lg['signer_position'])): ?>
                                        <span class="text-muted fw-normal">(<?= e($lg['signer_position']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted mb-2" style="font-size: 0.78rem;">
                                    <i class="bi bi-clock me-1"></i><?= e(thai_date($lg['acted_at'], true)) ?>
                                </div>
                                <?php if (!empty($lg['comment'])): ?>
                                    <div class="p-2 rounded bg-white border small text-dark mb-2">
                                        <?= e($lg['comment']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($lg['digital_signature']) && str_starts_with((string)$lg['digital_signature'], 'uploads/signatures/')): ?>
                                    <div class="sig-preview-box">
                                        <div class="small text-muted mb-1" style="font-size: 0.72rem;">ลายมือชื่อผู้พิจารณา</div>
                                        <img src="<?= e(app_url('/download.php?type=signature&id=' . (int)$lg['id'])) ?>"
                                             alt="ลายมือชื่อ <?= e($lg['signer_name']) ?>"
                                             class="memo-sig-img">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
