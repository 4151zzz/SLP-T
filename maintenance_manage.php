<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/upload.php';

$user = require_role([ROLE_STAFF, ROLE_ADMIN]);
$pdo = get_pdo();

$filterStatus = trim((string)($_GET['status'] ?? ''));
if ($filterStatus !== '' && !in_array($filterStatus, REPAIR_STATUSES, true)) {
    $filterStatus = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $ticketId = (int)($_POST['id'] ?? $_POST['ticket_id'] ?? $_POST['request_id'] ?? 0);
    $newStatus = trim((string)($_POST['status'] ?? $_POST['repair_status'] ?? ''));
    $repairNotes = trim((string)($_POST['repair_notes'] ?? $_POST['notes'] ?? $_POST['note'] ?? $_POST['remarks'] ?? ''));
    $redirectStatus = trim((string)($_POST['filter_status'] ?? ''));
    $redirectUrl = app_url('/maintenance_manage.php' . (in_array($redirectStatus, REPAIR_STATUSES, true) ? ('?status=' . urlencode($redirectStatus)) : ''));

    if ($ticketId <= 0 || !in_array($newStatus, REPAIR_STATUSES, true)) {
        flash('danger', 'ข้อมูลสถานะหรือเลขที่ใบงานไม่ถูกต้อง');
        redirect($redirectUrl);
    }

    $stmtExist = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id = :id LIMIT 1');
    $stmtExist->execute([':id' => $ticketId]);
    $existing = $stmtExist->fetch();

    if (!$existing) {
        flash('danger', 'ไม่พบใบงานซ่อมบำรุงที่ต้องการอัปเดต');
        redirect($redirectUrl);
    }

    $newAfterPhotoPath = null;
    $uploadRes = upload_maintenance_photo('after_photo', false);
    if (!$uploadRes['ok']) {
        flash('danger', $uploadRes['error'] ?? 'ไม่สามารถอัปโหลดรูปภาพหลังซ่อมได้');
        redirect($redirectUrl);
    }
    if ($uploadRes['uploaded']) {
        $newAfterPhotoPath = $uploadRes['path'];
    }

    try {
        $assignedStaffId = ((string)$user['role'] === ROLE_STAFF)
            ? (int)$user['id']
            : (!empty($existing['assigned_staff_id']) ? (int)$existing['assigned_staff_id'] : (int)$user['id']);

        $afterPhotoToSave = $newAfterPhotoPath ?? ($existing['after_photo_path'] ?? null);
        $notesToSave = ($repairNotes !== '') ? $repairNotes : ($existing['repair_notes'] ?? null);

        if ($newStatus === REPAIR_STATUS_DONE) {
            $sqlUpd = 'UPDATE maintenance_requests
                       SET status = :status,
                           repair_notes = :notes,
                           assigned_staff_id = :staff_id,
                           after_photo_path = :after_photo,
                           completed_at = COALESCE(completed_at, NOW())
                       WHERE id = :id';
        } else {
            $sqlUpd = 'UPDATE maintenance_requests
                       SET status = :status,
                           repair_notes = :notes,
                           assigned_staff_id = :staff_id,
                           after_photo_path = :after_photo,
                           completed_at = NULL
                       WHERE id = :id';
        }

        $pdo->prepare($sqlUpd)->execute([
            ':status'      => $newStatus,
            ':notes'       => $notesToSave,
            ':staff_id'    => $assignedStaffId,
            ':after_photo' => $afterPhotoToSave,
            ':id'          => $ticketId,
        ]);

        if ($newAfterPhotoPath !== null && !empty($existing['after_photo_path']) && $existing['after_photo_path'] !== $newAfterPhotoPath) {
            delete_uploaded_file((string)$existing['after_photo_path']);
        }

        flash('success', "อัปเดตสถานะใบงาน {$existing['ticket_code']} เป็น \"{$newStatus}\" เรียบร้อยแล้ว");
    } catch (Throwable $e) {
        delete_uploaded_file($newAfterPhotoPath);
        flash('danger', 'เกิดข้อผิดพลาดในการบันทึกข้อมูลงานซ่อม');
    }

    redirect($redirectUrl);
}

$counts = [
    'all'                     => 0,
    REPAIR_STATUS_WAITING     => 0,
    REPAIR_STATUS_IN_PROGRESS => 0,
    REPAIR_STATUS_WAIT_PARTS  => 0,
    REPAIR_STATUS_DONE        => 0,
];
foreach ($pdo->query('SELECT status, COUNT(*) AS cnt FROM maintenance_requests GROUP BY status')->fetchAll() as $cr) {
    $st = (string)$cr['status'];
    $cnt = (int)$cr['cnt'];
    $counts['all'] += $cnt;
    if (isset($counts[$st])) {
        $counts[$st] = $cnt;
    }
}

$sqlTickets = 'SELECT m.*,
                      u.full_name AS reporter_name, u.department AS reporter_dept, u.phone AS reporter_phone,
                      s.full_name AS staff_name
               FROM maintenance_requests m
               INNER JOIN users u ON u.id = m.reporter_id
               LEFT JOIN users s ON s.id = m.assigned_staff_id';
$params = [];
if ($filterStatus !== '') {
    $sqlTickets .= ' WHERE m.status = :status';
    $params[':status'] = $filterStatus;
}
$sqlTickets .= " ORDER BY (m.status = 'เสร็จสิ้น') ASC, (m.urgency = 'urgent') DESC, m.reported_at DESC, m.id DESC";

$stmtTickets = $pdo->prepare($sqlTickets);
$stmtTickets->execute($params);
$tickets = $stmtTickets->fetchAll();

$repairBadges = defined('REPAIR_STATUS_BADGES') ? REPAIR_STATUS_BADGES : [];
$pageTitle = 'จัดการงานซ่อม';
$activeNav = 'maintenance_manage';

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-tools me-1"></i>ระบบบริหารจัดการใบงานซ่อมบำรุง
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="maintenance-manage-heading">จัดการงานซ่อม</h1>
            <p class="small text-muted mb-0">อัปเดตสถานะ 4 ขั้นตอน บันทึกผลการซ่อม หรือคลิก <strong>รายละเอียด</strong> เพื่อดูข้อมูลและภาพประกอบ</p>
        </div>
        <a href="<?= e(app_url('/maintenance.php')) ?>" class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3 flex-shrink-0">
            <i class="bi bi-plus-circle"></i> แจ้งซ่อมใหม่
        </a>
    </div>
</div>

<!-- 4 Status KPI Summary -->
<div class="row g-2 g-sm-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-amber p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-amber"><i class="bi bi-inbox-fill"></i></div>
            <div>
                <div class="kpi-label mb-1">รอรับเรื่อง</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= $counts[REPAIR_STATUS_WAITING] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-blue p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-blue"><i class="bi bi-gear-wide-connected"></i></div>
            <div>
                <div class="kpi-label mb-1">กำลังซ่อม</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= $counts[REPAIR_STATUS_IN_PROGRESS] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-indigo p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-indigo"><i class="bi bi-box-seam-fill"></i></div>
            <div>
                <div class="kpi-label mb-1">รออะไหล่</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= $counts[REPAIR_STATUS_WAIT_PARTS] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-emerald p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-emerald"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="kpi-label mb-1">เสร็จสิ้น</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= $counts[REPAIR_STATUS_DONE] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- 5 Status Filter Tabs -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-2 p-sm-3">
        <ul class="nav nav-pills gap-2 flex-wrap" data-testid="maint-filter-tabs">
            <li class="nav-item">
                <a class="nav-link touch-target d-inline-flex align-items-center gap-2 rounded-3 <?= $filterStatus === '' ? 'active' : 'text-dark bg-light' ?>"
                   href="<?= e(app_url('/maintenance_manage.php')) ?>">
                    <span>ทั้งหมด</span>
                    <span class="badge rounded-pill <?= $filterStatus === '' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= $counts['all'] ?></span>
                </a>
            </li>
            <?php foreach (REPAIR_STATUSES as $st): ?>
                <li class="nav-item">
                    <a class="nav-link touch-target d-inline-flex align-items-center gap-2 rounded-3 <?= $filterStatus === $st ? 'active' : 'text-dark bg-light' ?>"
                       href="<?= e(app_url('/maintenance_manage.php?status=' . urlencode($st))) ?>">
                        <span><?= e($st) ?></span>
                        <span class="badge rounded-pill <?= $filterStatus === $st ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= $counts[$st] ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php if (empty($tickets)): ?>
    <div class="spotlight-card p-5 text-center text-muted">
        <i class="bi bi-tools fs-1 d-block mb-2"></i>
        <div class="small">ไม่พบใบงานซ่อมในสถานะที่เลือก</div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($tickets as $tk):
            $tid = (int)$tk['id'];
            $bCls = $repairBadges[$tk['status']] ?? 'secondary';

            $photos = [];
            if (!empty($tk['before_photo_path'])) {
                $photos[] = ['label' => 'ภาพก่อนซ่อม', 'url' => app_url('/download.php?type=maintenance_before&id=' . $tid)];
            }
            if (!empty($tk['after_photo_path'])) {
                $photos[] = ['label' => 'ภาพหลังซ่อม', 'url' => app_url('/download.php?type=maintenance_after&id=' . $tid)];
            }

            $swalPayload = [
                'title'    => (string)($tk['equipment_name'] ?: 'รายละเอียดงานซ่อม'),
                'subtitle' => $tk['ticket_code'] . ' · ' . thai_date($tk['reported_at'], true, true),
                'badge'    => (string)$tk['status'] . ($tk['urgency'] === 'urgent' ? ' (งานด่วน)' : ''),
                'rows'     => [
                    ['label' => 'สถานที่',     'value' => (string)$tk['location_text']],
                    ['label' => 'ผู้แจ้งซ่อม',  'value' => $tk['reporter_name'] . (!empty($tk['reporter_dept']) ? ' (' . $tk['reporter_dept'] . ')' : '') . (!empty($tk['reporter_phone']) ? ' · ' . $tk['reporter_phone'] : '')],
                    ['label' => 'อาการชำรุด',   'value' => (string)$tk['issue_description']],
                    ['label' => 'ผู้รับผิดชอบ', 'value' => (string)($tk['staff_name'] ?: 'ยังไม่มีผู้รับผิดชอบ')],
                    ['label' => 'ปิดงานเมื่อ',  'value' => !empty($tk['completed_at']) ? thai_date($tk['completed_at'], true, true) : '-'],
                ],
                'noteTitle' => 'บันทึกการซ่อมจากช่าง',
                'note'      => (string)($tk['repair_notes'] ?: ''),
                'noteTone'  => $tk['status'] === REPAIR_STATUS_DONE ? 'success' : 'info',
                'photos'    => $photos,
            ];
        ?>
            <div class="card clean-list-item border-0" data-ticket-id="<?= $tid ?>">
                <div class="card-body p-3 p-md-4">
                    <!-- Compact Ticket Summary Header -->
                    <div class="d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-2 mb-3">
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge text-bg-light border text-dark font-monospace"><?= e($tk['ticket_code']) ?></span>
                                <span class="badge badge-maint-<?= e($tk['status']) ?> text-bg-<?= e($bCls) ?>"><?= e($tk['status']) ?></span>
                                <?php if ($tk['urgency'] === 'urgent'): ?>
                                    <span class="badge text-bg-danger">งานด่วน</span>
                                <?php endif; ?>
                                <span class="small text-muted">
                                    · <?= e(thai_date($tk['reported_at'], false, true)) ?>
                                </span>
                            </div>

                            <h2 class="h6 fw-bold text-dark mb-1"><?= e($tk['equipment_name'] ?: 'งานซ่อมทั่วไป') ?></h2>

                            <div class="compact-meta mb-1">
                                <i class="bi bi-geo-alt me-1 text-primary"></i><?= e($tk['location_text']) ?>
                                · <i class="bi bi-person me-1 text-primary"></i><?= e($tk['reporter_name']) ?>
                            </div>
                            <div class="small text-dark text-clamp-2">
                                <strong>อาการ:</strong> <?= e($tk['issue_description']) ?>
                            </div>
                            <?php if (!empty($tk['repair_notes'])): ?>
                                <div class="small text-secondary mt-1 text-clamp-1">
                                    <i class="bi bi-chat-left-text me-1 text-success"></i><strong>บันทึกช่าง:</strong> <?= e($tk['repair_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                            <?php if (!empty($tk['before_photo_path'])): ?>
                                <button type="button"
                                        class="btn btn-sm btn-soft-secondary rounded-3 px-2"
                                        data-swal-photo="<?= e(app_url('/download.php?type=maintenance_before&id=' . $tid)) ?>"
                                        data-swal-title="ภาพก่อนซ่อม · <?= e($tk['ticket_code']) ?>">
                                    <i class="bi bi-image me-1"></i>ก่อนซ่อม
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($tk['after_photo_path'])): ?>
                                <button type="button"
                                        class="btn btn-sm btn-soft-success rounded-3 px-2"
                                        data-swal-photo="<?= e(app_url('/download.php?type=maintenance_after&id=' . $tid)) ?>"
                                        data-swal-title="ภาพหลังซ่อม · <?= e($tk['ticket_code']) ?>">
                                    <i class="bi bi-check2-circle me-1"></i>หลังซ่อม
                                </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold"
                                    data-swal-detail="<?= e((string)json_encode($swalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                    data-testid="btn-maint-detail-<?= $tid ?>">
                                <i class="bi bi-eye me-1"></i>รายละเอียด
                            </button>
                        </div>
                    </div>

                    <!-- Slim Single-Row Update Form -->
                    <form method="post"
                          action="<?= e(app_url('/maintenance_manage.php')) ?>"
                          enctype="multipart/form-data"
                          class="p-2 p-sm-3 rounded-3 border bg-light">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <?php if ($filterStatus !== ''): ?>
                            <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>">
                        <?php endif; ?>

                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-sm-6 col-xl-2">
                                <label class="form-label small fw-semibold mb-1" for="status_<?= $tid ?>">สถานะ</label>
                                <select class="form-select form-select-sm" id="status_<?= $tid ?>" name="status">
                                    <?php foreach (REPAIR_STATUSES as $st): ?>
                                        <option value="<?= e($st) ?>" <?= ($tk['status'] === $st) ? 'selected' : '' ?>>
                                            <?= e($st) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-sm-6 col-xl-4">
                                <label class="form-label small fw-semibold mb-1" for="notes_<?= $tid ?>">หมายเหตุช่าง</label>
                                <textarea class="form-control form-control-sm"
                                          id="notes_<?= $tid ?>"
                                          name="repair_notes"
                                          rows="1"
                                          placeholder="บันทึกผลการซ่อม / อะไหล่..."><?= e($tk['repair_notes'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12 col-sm-8 col-xl-4">
                                <label class="form-label small fw-semibold mb-1" for="after_photo_<?= $tid ?>">รูปหลังซ่อม (ถ้ามี)</label>
                                <input type="file"
                                       class="form-control form-control-sm"
                                       id="after_photo_<?= $tid ?>"
                                       name="after_photo"
                                       accept="image/*"
                                       capture="environment">
                            </div>

                            <div class="col-12 col-sm-4 col-xl-2 d-grid">
                                <button type="submit" class="btn btn-primary-gradient btn-sm rounded-3 fw-semibold text-nowrap" style="min-height: 31px;">
                                    <i class="bi bi-save me-1"></i>บันทึก
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
