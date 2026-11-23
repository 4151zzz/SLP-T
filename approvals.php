<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$approverRoles = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
$user = require_role($approverRoles);
$pdo = get_pdo();

$role = (string)($user['role'] ?? '');
$tab = trim((string)($_GET['tab'] ?? 'mine'));
if (!in_array($tab, ['mine', 'all_pending', 'history'], true)) {
    $tab = 'mine';
}

$myQueueSql = match ($role) {
    ROLE_HEAD          => "b.status = 'pending_head'",
    ROLE_VICE_DIRECTOR => "b.status = 'pending_vice'",
    ROLE_DIRECTOR      => "b.status = 'pending_director'",
    ROLE_STAFF         => "b.status = 'pending_head' AND b.booking_type = 'vehicle'",
    ROLE_ADMIN         => "b.status IN ('pending_head', 'pending_vice', 'pending_director')",
    default            => '1 = 0',
};

$countMine = (int)$pdo->query("SELECT COUNT(*) FROM bookings b WHERE {$myQueueSql}")->fetchColumn();
$countAllPending = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director')")->fetchColumn();
$countHistory = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('approved', 'rejected')")->fetchColumn();

$whereSql = match ($tab) {
    'all_pending' => "b.status IN ('pending_head', 'pending_vice', 'pending_director')",
    'history'     => "b.status IN ('approved', 'rejected')",
    default       => $myQueueSql,
};

$orderSql = ($tab === 'history')
    ? 'b.updated_at DESC, b.id DESC LIMIT 60'
    : 'b.start_datetime ASC, b.id ASC';

$bookings = $pdo->query(
    "SELECT b.*,
            u.full_name AS requester_name, u.position_title AS requester_position,
            u.department AS requester_dept, u.organization AS requester_org,
            u.phone AS requester_phone,
            r.room_code, r.name AS room_name, r.building AS room_building,
            v.vehicle_code, v.plate_number, v.brand_model, v.seats AS vehicle_seats,
            d.driver_code, d.full_name AS driver_name, d.phone AS driver_phone
     FROM bookings b
     INNER JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     LEFT JOIN drivers d ON d.id = b.driver_id
     WHERE {$whereSql}
     ORDER BY {$orderSql}"
)->fetchAll();

$logsByBooking = [];
if (!empty($bookings)) {
    $ids = array_map(static fn (array $r): int => (int)$r['id'], $bookings);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtLogs = $pdo->prepare(
        "SELECT * FROM approval_logs WHERE booking_id IN ({$placeholders}) ORDER BY step_number ASC, acted_at ASC, id ASC"
    );
    $stmtLogs->execute($ids);
    foreach ($stmtLogs->fetchAll() as $lg) {
        $logsByBooking[(int)$lg['booking_id']][] = $lg;
    }
}

$availableVehicles = $pdo->query("SELECT id, vehicle_code, plate_number, brand_model, seats FROM vehicles WHERE status = 'available' ORDER BY vehicle_code ASC")->fetchAll();
$availableDrivers = $pdo->query("SELECT id, driver_code, full_name, phone FROM drivers WHERE status = 'available' ORDER BY driver_code ASC")->fetchAll();

$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];
$roleLabels = defined('ROLE_LABELS') ? ROLE_LABELS : [];
$actionLabels = [
    'approved'         => 'เห็นชอบ / อนุมัติ',
    'rejected'         => 'ไม่อนุมัติ',
    'returned'         => 'ส่งกลับแก้ไข',
    'assigned_vehicle' => 'จัดสรรรถ/คนขับ',
];

$canActOnBooking = static function (string $userRole, array $booking): bool {
    $st = (string)$booking['status'];
    if ($userRole === ROLE_ADMIN) {
        return in_array($st, [BOOKING_STATUS_PENDING_HEAD, BOOKING_STATUS_PENDING_VICE, BOOKING_STATUS_PENDING_DIRECTOR], true);
    }
    if ($userRole === ROLE_STAFF) {
        return $st === BOOKING_STATUS_PENDING_HEAD && $booking['booking_type'] === 'vehicle';
    }
    if ($userRole === ROLE_HEAD) {
        return $st === BOOKING_STATUS_PENDING_HEAD;
    }
    if ($userRole === ROLE_VICE_DIRECTOR) {
        return $st === BOOKING_STATUS_PENDING_VICE;
    }
    if ($userRole === ROLE_DIRECTOR) {
        return $st === BOOKING_STATUS_PENDING_DIRECTOR;
    }
    return false;
};

$pageTitle = 'พิจารณาอนุมัติคำขอ';
$activeNav = 'approvals';
$extraHead = '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
$extraScripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize signature canvases (mouse, touch & stylus)
    document.querySelectorAll('.sig-pad-canvas').forEach(function (canvas) {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        let drawing = false;
        let lastX = 0;
        let lastY = 0;

        function getPos(ev) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = rect.width > 0 ? (canvas.width / rect.width) : 1;
            const scaleY = rect.height > 0 ? (canvas.height / rect.height) : 1;
            return {
                x: (ev.clientX - rect.left) * scaleX,
                y: (ev.clientY - rect.top) * scaleY
            };
        }

        canvas.addEventListener('pointerdown', function (ev) {
            if (ev.button !== 0 && ev.pointerType === 'mouse') return;
            drawing = true;
            const pos = getPos(ev);
            lastX = pos.x;
            lastY = pos.y;
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            canvas.setPointerCapture(ev.pointerId);
        });

        canvas.addEventListener('pointermove', function (ev) {
            if (!drawing) return;
            const pos = getPos(ev);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            lastX = pos.x;
            lastY = pos.y;
            canvas.dataset.hasDrawn = '1';
        });

        function stopDraw(ev) {
            if (!drawing) return;
            drawing = false;
            try { canvas.releasePointerCapture(ev.pointerId); } catch (e) {}
        }

        canvas.addEventListener('pointerup', stopDraw);
        canvas.addEventListener('pointercancel', stopDraw);
    });

    document.addEventListener('click', function (ev) {
        const clearBtn = ev.target instanceof Element ? ev.target.closest('.btn-clear-sig') : null;
        if (clearBtn) {
            const bid = clearBtn.getAttribute('data-sig-target');
            const canvas = bid ? document.getElementById('sigCanvas_' + bid) : null;
            const hiddenInp = bid ? document.getElementById('sigInput_' + bid) : null;
            if (canvas) {
                const ctx = canvas.getContext('2d');
                if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
                delete canvas.dataset.hasDrawn;
            }
            if (hiddenInp) hiddenInp.value = '';
            return;
        }

        const btn = ev.target instanceof Element ? ev.target.closest('button[name="action"]') : null;
        if (!btn) return;
        const form = btn.closest('form');
        if (!form) return;

        const actionVal = btn.getAttribute('value') || '';
        const bidInput = form.querySelector('input[name="booking_id"]');
        const bid = bidInput ? bidInput.value : '';
        const canvas = bid ? document.getElementById('sigCanvas_' + bid) : null;
        const sigInput = bid ? document.getElementById('sigInput_' + bid) : null;

        if (canvas && sigInput && canvas.dataset.hasDrawn === '1') {
            sigInput.value = canvas.toDataURL('image/png');
        }

        if (actionVal === 'reject' || actionVal === 'return') {
            const commentEl = form.querySelector('textarea[name="comment"]');
            if (commentEl && commentEl.value.trim() === '') {
                ev.preventDefault();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'กรุณาระบุความเห็น',
                        text: 'จำเป็นต้องกรอกเหตุผลหรือความเห็นประกอบการส่งกลับหรือไม่อนุมัติ',
                        confirmButtonText: 'รับทราบ',
                        confirmButtonColor: '#2563eb',
                        customClass: { popup: 'slp-swal-popup' }
                    }).then(function () {
                        commentEl.focus();
                    });
                } else {
                    commentEl.focus();
                }
                return;
            }
        }

        if (actionVal === 'approve' && canvas && canvas.dataset.hasDrawn !== '1' && form.dataset.sigConfirmed !== '1') {
            ev.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'ยังไม่ได้วาดลายมือชื่อ',
                    text: 'คุณยังไม่ได้เซ็นชื่อในช่องลายเซ็น ต้องการกลับไปเซ็นหรืออนุมัติด้วยชื่อดิจิทัล?',
                    showCancelButton: true,
                    confirmButtonText: 'อนุมัติด้วยชื่อดิจิทัล',
                    cancelButtonText: 'กลับไปเซ็นลายมือชื่อ',
                    confirmButtonColor: '#16a34a',
                    cancelButtonColor: '#2563eb',
                    reverseButtons: true,
                    customClass: { popup: 'slp-swal-popup' }
                }).then(function (res) {
                    if (res && res.isConfirmed) {
                        form.dataset.sigConfirmed = '1';
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(btn);
                        } else {
                            btn.click();
                        }
                    }
                });
            }
        }
    });
});
</script>
HTML;

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-check2-square me-1"></i>ระบบพิจารณาอนุมัติ 3 ขั้นตอน
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="approvals-heading">พิจารณาอนุมัติคำขอ</h1>
            <p class="small text-muted mb-0">
                บทบาท: <strong><?= e($roleLabels[$role] ?? $role) ?></strong> · กด <strong>ดูรายละเอียด</strong> เพื่อดูข้อมูลเต็ม หรือกด <strong>พิจารณา</strong> เพื่อลงนามอนุมัติ
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <a href="<?= e(app_url('/dashboard.php')) ?>" class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                <i class="bi bi-speedometer2"></i> แดชบอร์ด
            </a>
            <a href="<?= e(app_url('/calendar.php')) ?>" class="btn btn-soft-secondary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                <i class="bi bi-calendar3"></i> ปฏิทิน
            </a>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-2 p-sm-3">
        <ul class="nav nav-pills gap-2 flex-wrap" data-testid="approvals-tabs">
            <li class="nav-item">
                <a class="nav-link touch-target d-inline-flex align-items-center gap-2 rounded-3 <?= $tab === 'mine' ? 'active' : 'text-dark bg-light' ?>"
                   href="<?= e(app_url('/approvals.php?tab=mine')) ?>">
                    <i class="bi bi-inbox-fill"></i>
                    <span>คิวงานของฉัน</span>
                    <span class="badge rounded-pill <?= $tab === 'mine' ? 'text-bg-light text-primary' : 'text-bg-warning' ?>"><?= $countMine ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link touch-target d-inline-flex align-items-center gap-2 rounded-3 <?= $tab === 'all_pending' ? 'active' : 'text-dark bg-light' ?>"
                   href="<?= e(app_url('/approvals.php?tab=all_pending')) ?>">
                    <i class="bi bi-hourglass-split"></i>
                    <span>รออนุมัติทั้งหมด</span>
                    <span class="badge rounded-pill <?= $tab === 'all_pending' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= $countAllPending ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link touch-target d-inline-flex align-items-center gap-2 rounded-3 <?= $tab === 'history' ? 'active' : 'text-dark bg-light' ?>"
                   href="<?= e(app_url('/approvals.php?tab=history')) ?>">
                    <i class="bi bi-clock-history"></i>
                    <span>ประวัติการพิจารณา</span>
                    <span class="badge rounded-pill <?= $tab === 'history' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= $countHistory ?></span>
                </a>
            </li>
        </ul>
    </div>
</div>

<?php if (empty($bookings)): ?>
    <div class="spotlight-card p-5 text-center text-muted">
        <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>
        <div class="fw-semibold text-dark mb-1">ไม่มีคำขอในรายการนี้</div>
        <div class="small">รายการทั้งหมดได้รับการพิจารณาเรียบร้อยแล้ว</div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($bookings as $b):
            $bid = (int)$b['id'];
            $isRoom = ($b['booking_type'] === 'room');
            $st = (string)$b['status'];
            $stMeta = $statusLabels[$st] ?? ['label' => $st, 'badge' => 'secondary'];
            $canAct = $canActOnBooking($role, $b);
            $logs = $logsByBooking[$bid] ?? [];
            $isPendingVehicleStep1 = (!$isRoom && $st === BOOKING_STATUS_PENDING_HEAD);

            $orgDeptText = (string)($b['requester_dept'] ?: ($b['requester_org'] ?: '-'));
            $timeRangeText = thai_date($b['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($b['end_datetime'])) . ' น.';
            $placeText = $isRoom
                ? trim(($b['room_code'] ? $b['room_code'] . ' ' : '') . ($b['room_name'] ?: '-'))
                : (string)($b['destination'] ?: '-');

            $detailRows = [
                ['label' => 'รหัสอ้างอิง', 'value' => (string)$b['reference_code']],
                ['label' => 'ประเภทคำขอ',  'value' => $isRoom ? 'จองห้องประชุม' : 'จองยานพาหนะ'],
                ['label' => 'ผู้ขอ',       'value' => $b['requester_name'] . ' (' . $orgDeptText . ')'],
                ['label' => 'วันเวลา',      'value' => thai_date($b['start_datetime'], true, true) . ' – ' . thai_date($b['end_datetime'], true, true)],
            ];
            if ($isRoom) {
                $detailRows[] = ['label' => 'ห้องประชุม', 'value' => $placeText . ' (' . (int)($b['attendee_count'] ?? 0) . ' คน)'];
            } else {
                $detailRows[] = ['label' => 'ปลายทาง', 'value' => $placeText . ' (' . (int)($b['passenger_count'] ?? 0) . ' คน)'];
                $vehSummary = !empty($b['vehicle_code'])
                    ? ($b['vehicle_code'] . ' ' . $b['brand_model'] . ' (' . $b['plate_number'] . ') · คนขับ: ' . ($b['driver_name'] ?: '-'))
                    : 'ยังไม่ได้จัดสรรยานพาหนะ/คนขับ';
                $detailRows[] = ['label' => 'รถ / พขร.', 'value' => $vehSummary];
            }

            $timelinePayload = [];
            foreach ($logs as $lg) {
                $timelinePayload[] = [
                    'step'    => $roleLabels[$lg['step_role']] ?? $lg['step_role'],
                    'status'  => $actionLabels[$lg['action']] ?? $lg['action'],
                    'signer'  => (string)$lg['signer_name'],
                    'time'    => thai_date($lg['acted_at'], true, true),
                    'comment' => (string)($lg['comment'] ?? ''),
                ];
            }

            $linksPayload = [
                ['label' => 'เปิดหน้าไทม์ไลน์เต็ม', 'href' => app_url('/booking_detail.php?id=' . $bid), 'variant' => 'primary'],
                ['label' => 'พิมพ์บันทึกข้อความ (A4)', 'href' => app_url('/print_memo.php?id=' . $bid), 'variant' => 'secondary'],
            ];
            if (!empty($b['permission_letter_path'])) {
                $linksPayload[] = [
                    'label'    => 'ดาวน์โหลดหนังสือขออนุญาต',
                    'href'     => app_url('/download.php?type=permission&booking_id=' . $bid . '&download=1'),
                    'variant'  => 'success',
                    'download' => true,
                ];
            }

            $swalPayload = [
                'title'     => (string)$b['title'],
                'subtitle'  => $b['reference_code'] . ' · ' . $b['requester_name'],
                'badge'     => (string)$stMeta['label'],
                'rows'      => $detailRows,
                'noteTitle' => ($st === BOOKING_STATUS_REJECTED && !empty($b['rejection_reason'])) ? 'เหตุผลที่ไม่อนุมัติ' : 'วัตถุประสงค์',
                'note'      => ($st === BOOKING_STATUS_REJECTED && !empty($b['rejection_reason'])) ? (string)$b['rejection_reason'] : (string)($b['purpose'] ?: ''),
                'noteTone'  => ($st === BOOKING_STATUS_REJECTED && !empty($b['rejection_reason'])) ? 'danger' : 'info',
                'timeline'  => $timelinePayload,
                'links'     => $linksPayload,
            ];
        ?>
            <div class="clean-list-item p-3 p-md-4" data-booking-id="<?= $bid ?>">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div class="min-w-0 flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($b['reference_code']) ?></span>
                            <span class="badge <?= $isRoom ? 'text-bg-primary' : 'text-bg-success' ?>">
                                <i class="bi <?= $isRoom ? 'bi-door-open-fill' : 'bi-truck' ?> me-1"></i>
                                <?= $isRoom ? 'ห้องประชุม' : 'ยานพาหนะ' ?>
                            </span>
                            <span class="badge text-bg-<?= e($stMeta['badge']) ?>"><?= e($stMeta['label']) ?></span>
                            <?php if ($b['requester_type'] === 'external'): ?>
                                <span class="badge rounded-pill text-bg-info text-dark">ภายนอก</span>
                            <?php endif; ?>
                            <?php if ($isPendingVehicleStep1 && empty($b['vehicle_id'])): ?>
                                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">รอจัดรถ/คนขับ</span>
                            <?php endif; ?>
                        </div>

                        <h2 class="h6 fw-bold text-dark mb-1 text-clamp-1"><?= e($b['title']) ?></h2>

                        <div class="compact-meta d-flex flex-wrap align-items-center gap-x-3 gap-1">
                            <span><i class="bi bi-person me-1 text-primary"></i><?= e($b['requester_name']) ?></span>
                            <span class="text-muted">·</span>
                            <span><i class="bi bi-clock me-1 text-primary"></i><?= e($timeRangeText) ?></span>
                            <span class="text-muted">·</span>
                            <span class="text-truncate" style="max-width: 280px;"><i class="bi bi-geo-alt me-1 text-primary"></i><?= e($placeText) ?></span>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-secondary rounded-3 px-3 fw-semibold touch-target d-inline-flex align-items-center gap-1"
                                data-swal-detail="<?= e((string)json_encode($swalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                data-testid="btn-approval-detail-<?= $bid ?>">
                            <i class="bi bi-eye"></i>
                            <span>ดูรายละเอียด</span>
                        </button>

                        <?php if ($canAct): ?>
                            <button type="button"
                                    class="btn btn-sm btn-primary-gradient rounded-3 px-3 fw-semibold touch-target d-inline-flex align-items-center gap-1"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#approvePanel_<?= $bid ?>"
                                    aria-expanded="false"
                                    aria-controls="approvePanel_<?= $bid ?>">
                                <i class="bi bi-pen-fill"></i>
                                <span><?= ($role === ROLE_STAFF) ? 'จัดรถ/คนขับ' : 'พิจารณา' ?></span>
                            </button>
                        <?php else: ?>
                            <a href="<?= e(app_url('/print_memo.php?id=' . $bid)) ?>"
                               class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold touch-target d-inline-flex align-items-center gap-1">
                                <i class="bi bi-printer"></i>
                                <span>บันทึกข้อความ</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canAct): ?>
                    <div class="collapse mt-3" id="approvePanel_<?= $bid ?>">
                        <form method="post"
                              action="<?= e(app_url('/api/approval_action.php')) ?>"
                              class="p-3 rounded-4 border bg-light">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= $bid ?>">

                            <?php if ($isPendingVehicleStep1): ?>
                                <div class="row g-2 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-semibold mb-1" for="veh_<?= $bid ?>">เลือกยานพาหนะ (จัดสรรรถ)</label>
                                        <select class="form-select form-select-sm" name="vehicle_id" id="veh_<?= $bid ?>">
                                            <option value="">-- เลือกยานพาหนะ --</option>
                                            <?php foreach ($availableVehicles as $v): ?>
                                                <option value="<?= (int)$v['id'] ?>" <?= ((int)($b['vehicle_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>>
                                                    <?= e($v['vehicle_code'] . ' · ' . $v['brand_model'] . ' (' . $v['plate_number'] . ', ' . (int)$v['seats'] . ' ที่นั่ง)') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-semibold mb-1" for="drv_<?= $bid ?>">เลือกพนักงานขับรถ</label>
                                        <select class="form-select form-select-sm" name="driver_id" id="drv_<?= $bid ?>">
                                            <option value="">-- เลือกพนักงานขับรถ --</option>
                                            <?php foreach ($availableDrivers as $d): ?>
                                                <option value="<?= (int)$d['id'] ?>" <?= ((int)($b['driver_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                                    <?= e($d['driver_code'] . ' · ' . $d['full_name'] . ' (' . $d['phone'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="signer_<?= $bid ?>">ผู้ลงนามดิจิทัล</label>
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           id="signer_<?= $bid ?>"
                                           name="signer_name"
                                           value="<?= e($user['full_name']) ?>"
                                           required>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label small fw-semibold mb-1" for="comment_<?= $bid ?>">ความเห็น / เหตุผล</label>
                                    <textarea class="form-control form-control-sm"
                                              id="comment_<?= $bid ?>"
                                              name="comment"
                                              rows="2"
                                              placeholder="ระบุความเห็น (จำเป็นเมื่อส่งกลับหรือไม่อนุมัติ)"></textarea>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <label class="form-label small fw-semibold mb-0" for="sigCanvas_<?= $bid ?>">
                                        <i class="bi bi-pen me-1 text-primary"></i>ลายมือชื่อผู้พิจารณา (วาดด้วยเมาส์หรือนิ้วมือ)
                                    </label>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 btn-clear-sig"
                                            data-sig-target="<?= $bid ?>"
                                            style="font-size: 0.75rem;">
                                        <i class="bi bi-eraser me-1"></i>ล้างลายเซ็น
                                    </button>
                                </div>
                                <div class="sig-pad-wrapper" data-sig-wrap="<?= $bid ?>">
                                    <canvas class="sig-pad-canvas"
                                            id="sigCanvas_<?= $bid ?>"
                                            data-booking-id="<?= $bid ?>"
                                            data-testid="sig-canvas-<?= $bid ?>"
                                            width="520"
                                            height="150"></canvas>
                                    <div class="sig-pad-guide">ลงชื่อ........................................................</div>
                                </div>
                                <input type="hidden" name="signature_image" id="sigInput_<?= $bid ?>" value="">
                                <div class="form-text" style="font-size: 0.74rem;">
                                    วาดลายเซ็นในกรอบด้านบน (หากไม่ได้วาด ระบบจะใช้ชื่อผู้ลงนามดิจิทัลแทน)
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <?php if ($isPendingVehicleStep1 && in_array($role, [ROLE_STAFF, ROLE_HEAD, ROLE_ADMIN], true)): ?>
                                    <button type="submit"
                                            name="action"
                                            value="assign_vehicle"
                                            class="btn btn-sm btn-soft-info fw-semibold touch-target rounded-3 px-3">
                                        <i class="bi bi-car-front-fill me-1"></i>บันทึกจัดสรรรถ/คนขับ
                                    </button>
                                <?php endif; ?>

                                <?php if ($role !== ROLE_STAFF): ?>
                                    <button type="submit"
                                            name="action"
                                            value="approve"
                                            class="btn btn-sm btn-success fw-semibold touch-target rounded-3 px-3">
                                        <i class="bi bi-check-lg me-1"></i>
                                        <?= $st === BOOKING_STATUS_PENDING_DIRECTOR ? 'อนุมัติคำขอ' : 'เห็นชอบและส่งต่อ' ?>
                                    </button>

                                    <?php if (in_array($st, [BOOKING_STATUS_PENDING_VICE, BOOKING_STATUS_PENDING_DIRECTOR], true)): ?>
                                        <button type="submit"
                                                name="action"
                                                value="return"
                                                class="btn btn-sm btn-soft-warning fw-semibold touch-target rounded-3 px-3">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>ส่งกลับแก้ไข
                                        </button>
                                    <?php endif; ?>

                                    <button type="submit"
                                            name="action"
                                            value="reject"
                                            class="btn btn-sm btn-soft-danger fw-semibold touch-target rounded-3 px-3">
                                        <i class="bi bi-x-lg me-1"></i>ไม่อนุมัติ
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
