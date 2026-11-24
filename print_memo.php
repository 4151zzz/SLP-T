<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();

$rawId = trim((string)($_GET['id'] ?? ''));
if ($rawId === '' || !ctype_digit($rawId) || (int)$rawId <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>400 Bad Request</title></head><body><h1>400 Bad Request</h1><p>รหัสคำขอไม่ถูกต้อง</p></body></html>';
    exit;
}

$bookingId = (int)$rawId;
$pdo = get_pdo();

$stmt = $pdo->prepare(
    'SELECT b.*,
            u.full_name AS requester_name, u.position_title AS requester_position,
            u.department AS requester_dept, u.organization AS requester_org,
            u.phone AS requester_phone,
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
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p>ไม่พบคำขอจองที่ระบุ</p></body></html>';
    exit;
}

$isOwner = ((int)$booking['requester_id'] === (int)$user['id']);
$isPrivileged = has_role(PRIVILEGED_VIEW_ROLES, $user);

if (!$isOwner && !$isPrivileged) {
    http_response_code(403);
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
            <p class="mb-3">คุณไม่มีสิทธิ์เข้าถึงเอกสารบันทึกข้อความรายการนี้</p>
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

$isRoom = ($booking['booking_type'] === 'room');
$statusCode = (string)$booking['status'];
$statusMeta = BOOKING_STATUS_LABELS[$statusCode] ?? ['label' => $statusCode, 'badge' => 'secondary'];
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'สำนักงานบริหารทั่วไป';

$orgUnit = trim((string)($booking['requester_dept'] ?: ($booking['requester_org'] ?: $appOrgName)));
$equipmentKeys = [];
if (!empty($booking['equipment_requested'])) {
    $decoded = json_decode((string)$booking['equipment_requested'], true);
    if (is_array($decoded)) {
        $equipmentKeys = $decoded;
    }
}
$equipmentLabelsList = [];
foreach ($equipmentKeys as $ek) {
    $equipmentLabelsList[] = ROOM_EQUIPMENT_LABELS[$ek] ?? (string)$ek;
}

$logsByStep = [1 => [], 2 => [], 3 => []];
foreach ($logs as $lg) {
    $sn = (int)$lg['step_number'];
    if ($sn >= 1 && $sn <= 3) {
        $logsByStep[$sn][] = $lg;
    }
}

$stepTitles = [
    1 => 'ความเห็นหัวหน้างาน (ขั้นตอนที่ 1)',
    2 => 'ความเห็นรองผู้อำนวยการ (ขั้นตอนที่ 2)',
    3 => 'คำสั่ง / การอนุมัติผู้อำนวยการ (ขั้นตอนที่ 3)',
];

$actionThaiMap = [
    'approved'         => 'เห็นชอบ / อนุมัติ',
    'rejected'         => 'ไม่อนุมัติ',
    'returned'         => 'ส่งกลับแก้ไข',
    'assigned_vehicle' => 'จัดสรรยานพาหนะ/คนขับ',
];

$pageTitle = 'บันทึกข้อความ ' . (string)$booking['reference_code'];
$hideSidebar = true;
$extraHead = '<link rel="stylesheet" href="' . e(app_url('/assets/css/print.css')) . '">';

require __DIR__ . '/includes/layout_header.php';
?>
<div class="no-print container-fluid mb-3" style="max-width: 210mm;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 bg-white border rounded-4 p-3 shadow-sm">
        <div class="small text-muted">
            <i class="bi bi-file-earmark-text me-1 text-primary"></i>
            เอกสารรูปแบบ A4 พร้อมพิมพ์ / บันทึกเป็น PDF
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" onclick="window.print()" class="btn btn-primary fw-semibold rounded-3 px-3">
                <i class="bi bi-printer-fill me-1"></i>พิมพ์ / บันทึกเป็น PDF
            </button>
            <a href="<?= e(app_url('/booking_detail.php?id=' . $bookingId)) ?>" class="btn btn-outline-secondary rounded-3 px-3">
                <i class="bi bi-arrow-left me-1"></i>กลับหน้ารายละเอียด
            </a>
        </div>
    </div>
</div>

<article class="memo-sheet" data-testid="memo-sheet">
    <div class="text-center border-bottom border-dark pb-2 mb-3">
        <div class="memo-title">บันทึกข้อความ</div>
    </div>

    <div class="mb-3">
        <div class="mb-1">
            <span class="memo-label">ส่วนราชการ</span>
            <span><?= e($appOrgName) ?><?= $orgUnit !== '' && $orgUnit !== $appOrgName ? ' (' . e($orgUnit) . ')' : '' ?></span>
        </div>
        <div class="row g-2 mx-0 mb-1">
            <div class="col-6 ps-0">
                <span class="memo-label">ที่</span>
                <span><?= e($booking['reference_code']) ?></span>
            </div>
            <div class="col-6 pe-0">
                <span class="memo-label">วันที่</span>
                <span><?= e(thai_date($booking['created_at'])) ?></span>
            </div>
        </div>
        <div class="mb-1">
            <span class="memo-label">เรื่อง</span>
            <span><?= e($booking['title']) ?></span>
        </div>
        <div class="border-top border-dark pt-2 mt-2">
            <span class="memo-label">เรียน</span>
            <span>ผู้อำนวยการ<?= e($appOrgName) ?></span>
        </div>
    </div>

    <div class="mb-3" style="text-indent: 2.2rem;">
        ด้วยข้าพเจ้า <strong><?= e($booking['requester_name']) ?></strong>
        <?= !empty($booking['requester_position']) ? 'ตำแหน่ง ' . e($booking['requester_position']) : '' ?>
        <?= !empty($orgUnit) ? 'สังกัด ' . e($orgUnit) : '' ?>
        <?= !empty($booking['requester_phone']) ? 'โทรศัพท์ ' . e($booking['requester_phone']) : '' ?>
        มีความประสงค์ขออนุญาตใช้<?= $isRoom ? 'ห้องประชุมและสถานที่' : 'ยานพาหนะส่วนกลาง' ?>
        ตั้งแต่วันที่ <strong><?= e(thai_date($booking['start_datetime'], true)) ?></strong>
        ถึงวันที่ <strong><?= e(thai_date($booking['end_datetime'], true)) ?></strong>
        รายละเอียดดังต่อไปนี้
    </div>

    <table class="table table-bordered border-dark table-sm mb-4">
        <tbody>
            <tr>
                <th style="width: 30%;">รหัสอ้างอิงคำขอ</th>
                <td><?= e($booking['reference_code']) ?> (สถานะ: <?= e($statusMeta['label']) ?>)</td>
            </tr>
            <tr>
                <th>ประเภทคำขอ</th>
                <td><?= $isRoom ? 'ขอใช้ห้องประชุม/สถานที่' : 'ขอใช้ยานพาหนะราชการ' ?></td>
            </tr>
            <tr>
                <th>วัตถุประสงค์</th>
                <td><?= e($booking['purpose'] ?: '-') ?></td>
            </tr>
            <?php if ($isRoom): ?>
                <tr>
                    <th>ห้องประชุม / ห้องเรียนที่ขอใช้</th>
                    <td>
                        <?= e(($booking['room_code'] ? $booking['room_code'] . ' — ' : '') . ($booking['room_name'] ?: '-')) ?>
                        <?= !empty($booking['room_building']) ? '(' . e($booking['room_building'] . ' ' . ($booking['room_floor'] ?? '')) . ')' : '' ?>
                        <?php if (!empty($booking['class_level']) || !empty($booking['class_room'])): ?>
                            <div><strong>ชั้น / ห้องเรียน:</strong> <?= e(trim('ชั้น ' . ($booking['class_level'] ?: '-') . ' ห้อง ' . ($booking['class_room'] ?: '-'))) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>จำนวนผู้เข้าร่วม</th>
                    <td><?= (int)($booking['attendee_count'] ?? 0) ?> คน</td>
                </tr>
                <tr>
                    <th>อุปกรณ์โสตทัศนูปกรณ์</th>
                    <td><?= !empty($equipmentLabelsList) ? e(implode(', ', $equipmentLabelsList)) : 'ไม่ได้ขอใช้อุปกรณ์เพิ่มเติม' ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <th>สถานที่ปลายทาง</th>
                    <td><?= e($booking['destination'] ?: '-') ?></td>
                </tr>
                <tr>
                    <th>จำนวนผู้โดยสาร</th>
                    <td><?= (int)($booking['passenger_count'] ?? 0) ?> คน</td>
                </tr>
                <tr>
                    <th>ยานพาหนะที่จัดสรร</th>
                    <td>
                        <?php if (!empty($booking['vehicle_code'])): ?>
                            <?= e($booking['vehicle_code'] . ' · ' . $booking['brand_model'] . ' ทะเบียน ' . $booking['plate_number']) ?>
                        <?php else: ?>
                            ยังไม่ได้จัดสรรยานพาหนะ
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>พนักงานขับรถ</th>
                    <td>
                        <?php if (!empty($booking['driver_name'])): ?>
                            <?= e($booking['driver_name']) ?><?= !empty($booking['driver_phone']) ? ' (โทร. ' . e($booking['driver_phone']) . ')' : '' ?>
                        <?php else: ?>
                            ยังไม่ได้จัดสรรพนักงานขับรถ
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($statusCode === BOOKING_STATUS_REJECTED && !empty($booking['rejection_reason'])): ?>
                <tr>
                    <th>เหตุผลที่ไม่อนุมัติ</th>
                    <td><?= e($booking['rejection_reason']) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Requester signature -->
    <div class="row g-2 mx-0 justify-content-end mb-4 memo-signature-row">
        <div class="col-6 text-center">
            <?php if (!empty($booking['requester_signature']) && str_starts_with((string)$booking['requester_signature'], 'uploads/signatures/')): ?>
                <div class="mb-1">
                    <img src="<?= e(app_url('/download.php?type=requester_signature&id=' . (int)$booking['id'])) ?>"
                         alt="ลายมือชื่อ <?= e($booking['requester_name']) ?>"
                         class="memo-sig-img">
                </div>
            <?php else: ?>
                <div class="mb-2">ลงชื่อ.....................................ผู้ขอ</div>
            <?php endif; ?>
            <div>(<?= e($booking['requester_name']) ?>)</div>
            <div><?= e($booking['requester_position'] ?: $orgUnit) ?></div>
            <div>วันที่ <?= e(thai_date($booking['created_at'])) ?></div>
        </div>
    </div>

    <!-- Approval Chain Sign-off Blocks -->
    <div class="border-top border-dark pt-3">
        <div class="memo-label mb-2">ลำดับการพิจารณาและลงนามอนุมัติ</div>

        <div class="row g-2 mx-0">
            <?php foreach ([1, 2, 3] as $stepNum):
                $stepLogs = $logsByStep[$stepNum];
            ?>
                <div class="col-12 col-md-4">
                    <div class="border border-dark p-2 h-100 approval-signoff-block">
                        <div class="memo-label border-bottom border-dark pb-1 mb-2">
                            <?= e($stepTitles[$stepNum]) ?>
                        </div>
                        <?php if (empty($stepLogs)): ?>
                            <div class="text-center py-3 text-muted">
                                รอการพิจารณา
                            </div>
                        <?php else: ?>
                            <?php foreach ($stepLogs as $lg):
                                $actLabel = $actionThaiMap[$lg['action']] ?? $lg['action'];
                            ?>
                                <div class="mb-2">
                                    <div><strong>ผลการพิจารณา:</strong> <?= e($actLabel) ?></div>
                                    <?php if (!empty($lg['comment'])): ?>
                                        <div><strong>ความเห็น:</strong> <?= e($lg['comment']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2 pt-2 border-top text-center">
                                        <?php if (!empty($lg['digital_signature']) && str_starts_with((string)$lg['digital_signature'], 'uploads/signatures/')): ?>
                                            <img src="<?= e(app_url('/download.php?type=signature&id=' . (int)$lg['id'])) ?>"
                                                 alt="ลายมือชื่อ <?= e($lg['signer_name']) ?>"
                                                 class="memo-sig-img">
                                        <?php else: ?>
                                            <div>ลงนาม: <?= e($lg['digital_signature'] ?: $lg['signer_name']) ?></div>
                                        <?php endif; ?>
                                        <div>(<?= e($lg['signer_name']) ?>)</div>
                                        <?php if (!empty($lg['signer_position'])): ?>
                                            <div><?= e($lg['signer_position']) ?></div>
                                        <?php endif; ?>
                                        <div><?= e(thai_date($lg['acted_at'], true)) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</article>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
