<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();
$pdo = get_pdo();

$pageTitle = 'รายการคำขอของฉัน';
$activeNav = 'my_bookings';
$isInternal = is_internal($user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $postAction = trim((string)($_POST['action'] ?? ''));
    $targetBid = (int)($_POST['booking_id'] ?? 0);
    if ($postAction === 'cancel_booking') {
        $res = cancel_booking_record($pdo, $targetBid, $user, trim((string)($_POST['reason'] ?? '')));
        flash($res['ok'] ? 'success' : 'danger', $res['message']);
        redirect(app_url('/my_bookings.php'));
    } elseif ($postAction === 'delete_booking') {
        $res = delete_booking_record($pdo, $targetBid, $user);
        flash($res['ok'] ? 'success' : 'danger', $res['message']);
        redirect(app_url('/my_bookings.php'));
    }
}

$stmt = $pdo->prepare(
    'SELECT b.*, r.room_code, r.name AS room_name,
            v.vehicle_code, v.brand_model AS vehicle_name, v.plate_number,
            d.full_name AS driver_name, d.phone AS driver_phone
     FROM bookings b
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     LEFT JOIN drivers d ON d.id = b.driver_id
     WHERE b.requester_id = :uid
     ORDER BY b.created_at DESC, b.id DESC'
);
$stmt->execute([':uid' => (int)$user['id']]);
$bookings = $stmt->fetchAll();

$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-card-checklist me-1"></i>ติดตามสถานะคำขอทั้งหมดของ <?= e($user['full_name']) ?>
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="my-bookings-heading">รายการคำขอของฉัน</h1>
            <p class="small text-muted mb-0">แสดงสถานะคำขอ 3 ขั้นตอนแบบย่อ กด <strong>ดูเร็ว</strong> เพื่อเปิดดูรายละเอียดทันที</p>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <a href="<?= e(app_url('/book_room.php')) ?>" class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                <i class="bi bi-door-open-fill"></i> จองห้องประชุม
            </a>
            <?php if ($isInternal): ?>
                <a href="<?= e(app_url('/book_vehicle.php')) ?>" class="btn btn-soft-success fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-truck"></i> จองยานพาหนะ
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="spotlight-card">
    <div class="spotlight-header">
        <h2 class="h6 fw-bold mb-0">ประวัติและสถานะคำขอ (<?= count($bookings) ?> รายการ)</h2>
        <a href="<?= e(app_url('/calendar.php')) ?>" class="small fw-semibold text-decoration-none">
            <i class="bi bi-calendar3 me-1"></i>เปิดปฏิทินหลัก
        </a>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="p-5 text-center text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            <div class="small mb-3">คุณยังไม่มีรายการคำขอจองในระบบ</div>
            <a href="<?= e(app_url('/book_room.php')) ?>" class="btn btn-primary btn-sm rounded-pill px-4">จองห้องประชุมเลย</a>
        </div>
    <?php else: ?>
        <?php foreach ($bookings as $b):
            $bid = (int)$b['id'];
            $isRoom = ($b['booking_type'] === 'room');
            $statusCode = (string)$b['status'];
            $statusMeta = $statusLabels[$statusCode] ?? ['label' => $statusCode, 'badge' => 'secondary'];
            $step = (int)$b['current_step'];

            $step1Cls = match ($statusCode) {
                'pending_head' => 'is-active',
                'rejected'     => ($step <= 1 ? 'is-rejected' : 'is-done'),
                default        => 'is-done',
            };
            $step2Cls = match ($statusCode) {
                'pending_vice'                 => 'is-active',
                'pending_director', 'approved' => 'is-done',
                'rejected'                     => ($step === 2 ? 'is-rejected' : ($step > 2 ? 'is-done' : '')),
                default                        => '',
            };
            $step3Cls = match ($statusCode) {
                'pending_director' => 'is-active',
                'approved'         => 'is-done',
                'rejected'         => ($step >= 3 ? 'is-rejected' : ''),
                default            => '',
            };

            $resourceInfo = $isRoom
                ? (($b['room_code'] ? $b['room_code'] . ' · ' : '') . ($b['room_name'] ?: 'ห้องประชุม'))
                : (($b['destination'] ?: '-') . (!empty($b['vehicle_name']) ? ' · ' . $b['vehicle_name'] . ' (' . $b['plate_number'] . ')' : ' · รอจัดสรรรถ'));

            $swalRows = [
                ['label' => 'รหัสคำขอ', 'value' => (string)$b['reference_code']],
                ['label' => 'ประเภท',   'value' => $isRoom ? 'จองห้องประชุม' : 'จองยานพาหนะ'],
                ['label' => 'วันเวลา',  'value' => thai_date($b['start_datetime'], true, true) . ' – ' . thai_date($b['end_datetime'], true, true)],
            ];
            if ($isRoom) {
                $swalRows[] = ['label' => 'ห้องประชุม', 'value' => $resourceInfo . ' (' . (int)($b['attendee_count'] ?? 0) . ' คน)'];
            } else {
                $swalRows[] = ['label' => 'ปลายทาง',    'value' => (string)($b['destination'] ?: '-') . ' (' . (int)($b['passenger_count'] ?? 0) . ' คน)'];
                $swalRows[] = ['label' => 'รถ / คนขับ', 'value' => !empty($b['vehicle_name']) ? ($b['vehicle_name'] . ' (' . $b['plate_number'] . ') · ' . ($b['driver_name'] ?: '-')) : 'รอเจ้าหน้าที่จัดสรรรถ'];
            }

            $swalLinks = [
                ['label' => 'ไทม์ไลน์เต็ม', 'href' => app_url('/booking_detail.php?id=' . $bid), 'variant' => 'primary'],
                ['label' => 'บันทึกข้อความ A4', 'href' => app_url('/print_memo.php?id=' . $bid), 'variant' => 'secondary'],
            ];
            if (!empty($b['permission_letter_path'])) {
                $swalLinks[] = [
                    'label'    => 'ไฟล์แนบ',
                    'href'     => app_url('/download.php?type=permission&booking_id=' . $bid . '&download=1'),
                    'variant'  => 'success',
                    'download' => true,
                ];
            }

            $swalPayload = [
                'title'     => (string)$b['title'],
                'subtitle'  => $b['reference_code'] . ' · ยื่นเมื่อ ' . thai_date($b['created_at'], false, true),
                'badge'     => (string)$statusMeta['label'],
                'rows'      => $swalRows,
                'noteTitle' => ($statusCode === 'rejected' && !empty($b['rejection_reason'])) ? 'เหตุผลที่ไม่อนุมัติ' : 'วัตถุประสงค์',
                'note'      => ($statusCode === 'rejected' && !empty($b['rejection_reason'])) ? (string)$b['rejection_reason'] : (string)($b['purpose'] ?: ''),
                'noteTone'  => ($statusCode === 'rejected' && !empty($b['rejection_reason'])) ? 'danger' : 'info',
                'links'     => $swalLinks,
            ];
        ?>
            <div class="role-queue-item">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div class="min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($b['reference_code']) ?></span>
                            <span class="badge <?= $isRoom ? 'text-bg-primary' : 'text-bg-success' ?>">
                                <?= $isRoom ? 'ห้องประชุม' : 'ยานพาหนะ' ?>
                            </span>
                            <span class="badge text-bg-<?= e($statusMeta['badge']) ?>"><?= e($statusMeta['label']) ?></span>
                        </div>
                        <div class="fw-bold text-dark"><?= e($b['title']) ?></div>
                        <div class="compact-meta">
                            <i class="bi bi-geo-alt me-1 text-primary"></i><?= e($resourceInfo) ?>
                            · <i class="bi bi-clock me-1 text-primary"></i><?= e(thai_date($b['start_datetime'], true, true)) ?> – <?= e(date('H:i', strtotime($b['end_datetime']))) ?> น.
                        </div>
                        <?php if ($statusCode === 'rejected' && !empty($b['rejection_reason'])): ?>
                            <div class="small text-danger mt-1">
                                <i class="bi bi-exclamation-circle me-1"></i>เหตุผลที่ไม่อนุมัติ: <?= e($b['rejection_reason']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="mini-stepper">
                            <span class="mini-step <?= e($step1Cls) ?>">1. หัวหน้างาน</span>
                            <span class="mini-step-sep"><i class="bi bi-chevron-right"></i></span>
                            <span class="mini-step <?= e($step2Cls) ?>">2. รองผู้อำนวยการ</span>
                            <span class="mini-step-sep"><i class="bi bi-chevron-right"></i></span>
                            <span class="mini-step <?= e($step3Cls) ?>">3. ผู้อำนวยการอนุมัติ</span>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-secondary rounded-3 px-3 fw-semibold"
                                data-swal-detail="<?= e((string)json_encode($swalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                data-testid="btn-quick-booking-<?= $bid ?>">
                            <i class="bi bi-eye me-1"></i>ดูเร็ว
                        </button>
                        <?php if (!empty($b['permission_letter_path'])): ?>
                            <a href="<?= e(app_url('/download.php?type=permission&booking_id=' . $bid . '&download=1')) ?>"
                               class="btn btn-sm btn-soft-info rounded-3 px-3">
                                <i class="bi bi-download me-1"></i>ไฟล์แนบ
                            </a>
                        <?php endif; ?>
                        <a href="<?= e(app_url('/booking_detail.php?id=' . $bid)) ?>"
                           class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold">
                            รายละเอียด
                        </a>
                        <?php if ($statusCode !== 'cancelled' && $statusCode !== 'rejected'): ?>
                            <form method="post" action="<?= e(app_url('/my_bookings.php')) ?>" class="d-inline" data-confirm="ต้องการยกเลิกคำขอ &quot;<?= e($b['reference_code']) ?>&quot; ใช่หรือไม่?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_booking">
                                <input type="hidden" name="booking_id" value="<?= $bid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning text-dark rounded-3 px-2 fw-semibold" data-testid="btn-cancel-booking-<?= $bid ?>">
                                    <i class="bi bi-x-circle me-1"></i>ยกเลิก
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($statusCode !== 'approved'): ?>
                            <form method="post" action="<?= e(app_url('/my_bookings.php')) ?>" class="d-inline" data-confirm="ต้องการลบข้อมูลคำขอ &quot;<?= e($b['reference_code']) ?>&quot; ออกจากฐานข้อมูลอย่างถาวรใช่หรือไม่?" data-confirm-danger="1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_booking">
                                <input type="hidden" name="booking_id" value="<?= $bid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบออกจากฐานข้อมูล" data-testid="btn-delete-booking-<?= $bid ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
