<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/upload.php';

$user = require_login();
$pdo = get_pdo();

$rooms = $pdo->query('SELECT id, room_code, name, building, floor FROM rooms WHERE is_active = 1 ORDER BY room_code ASC')->fetchAll();
$assets = $pdo->query('SELECT id, asset_code, name, room_id, location FROM assets WHERE is_active = 1 ORDER BY asset_code ASC')->fetchAll();

$roomMap = [];
foreach ($rooms as $r) {
    $roomMap[(int)$r['id']] = $r;
}
$assetMap = [];
foreach ($assets as $a) {
    $assetMap[(int)$a['id']] = $a;
}

$selectedRoomId = (int)($_REQUEST['room_id'] ?? 0);
$selectedAssetId = (int)($_REQUEST['asset_id'] ?? 0);

$preRoom = $roomMap[$selectedRoomId] ?? null;
$preAsset = $assetMap[$selectedAssetId] ?? null;

if ($preAsset && !$preRoom && !empty($preAsset['room_id'])) {
    $selectedRoomId = (int)$preAsset['room_id'];
    $preRoom = $roomMap[$selectedRoomId] ?? null;
}

$defaultLocation = '';
$defaultEquipment = '';
if ($preAsset) {
    $defaultEquipment = $preAsset['name'] . ' (' . $preAsset['asset_code'] . ')';
    $defaultLocation = (string)($preAsset['location'] ?? '');
}
if ($preRoom && $defaultLocation === '') {
    $defaultLocation = trim(($preRoom['building'] ? $preRoom['building'] . ' ' : '') . ($preRoom['floor'] ? $preRoom['floor'] . ' — ' : '') . $preRoom['name'] . ' (' . $preRoom['room_code'] . ')');
}

$form = [
    'room_id'           => $preRoom ? (int)$preRoom['id'] : 0,
    'asset_id'          => $preAsset ? (int)$preAsset['id'] : 0,
    'location_text'     => $defaultLocation,
    'equipment_name'    => $defaultEquipment,
    'urgency'           => 'normal',
    'issue_description' => '',
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $postRoomId = (int)($_POST['room_id'] ?? 0);
    $postAssetId = (int)($_POST['asset_id'] ?? 0);
    $roomObj = $roomMap[$postRoomId] ?? null;
    $assetObj = $assetMap[$postAssetId] ?? null;

    $form['room_id'] = $roomObj ? (int)$roomObj['id'] : 0;
    $form['asset_id'] = $assetObj ? (int)$assetObj['id'] : 0;
    $form['location_text'] = trim((string)($_POST['location_text'] ?? $_POST['location'] ?? ''));
    $form['equipment_name'] = trim((string)($_POST['equipment_name'] ?? $_POST['equipment'] ?? $_POST['asset_name'] ?? ''));
    $form['urgency'] = trim((string)($_POST['urgency'] ?? 'normal')) === 'urgent' ? 'urgent' : 'normal';
    $form['issue_description'] = trim((string)($_POST['issue_description'] ?? $_POST['description'] ?? $_POST['issue'] ?? ''));

    if ($form['location_text'] === '') {
        if ($assetObj && !empty($assetObj['location'])) {
            $form['location_text'] = (string)$assetObj['location'];
        } elseif ($roomObj) {
            $form['location_text'] = trim(($roomObj['building'] ? $roomObj['building'] . ' ' : '') . $roomObj['name'] . ' (' . $roomObj['room_code'] . ')');
        }
    }
    if ($form['equipment_name'] === '' && $assetObj) {
        $form['equipment_name'] = $assetObj['name'] . ' (' . $assetObj['asset_code'] . ')';
    }

    if ($form['location_text'] === '') {
        $errors[] = 'กรุณาระบุสถานที่ที่พบปัญหา';
    }
    if ($form['issue_description'] === '') {
        $errors[] = 'กรุณาระบุรายละเอียดอาการชำรุด';
    }

    $uploadedPhotoPath = null;
    if (empty($errors)) {
        $uploadRes = upload_maintenance_photo('before_photo', false);
        if (!$uploadRes['ok']) {
            $errors[] = $uploadRes['error'] ?? 'ไม่สามารถอัปโหลดรูปภาพได้';
        } elseif ($uploadRes['uploaded']) {
            $uploadedPhotoPath = $uploadRes['path'];
        }
    }

    if (empty($errors)) {
        try {
            $ticketCode = 'MT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            for ($i = 0; $i < 5; $i++) {
                $chk = $pdo->prepare('SELECT id FROM maintenance_requests WHERE ticket_code = :c LIMIT 1');
                $chk->execute([':c' => $ticketCode]);
                if (!$chk->fetch()) {
                    break;
                }
                $ticketCode = 'MT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO maintenance_requests (
                    ticket_code, reporter_id, room_id, asset_id,
                    location_text, equipment_name, issue_description,
                    urgency, before_photo_path, status, reported_at
                 ) VALUES (
                    :code, :reporter_id, :room_id, :asset_id,
                    :location_text, :equipment_name, :issue_description,
                    :urgency, :before_photo_path, :status, NOW()
                 )'
            );
            $stmtIns->execute([
                ':code'              => $ticketCode,
                ':reporter_id'       => (int)$user['id'],
                ':room_id'           => $form['room_id'] > 0 ? $form['room_id'] : null,
                ':asset_id'          => $form['asset_id'] > 0 ? $form['asset_id'] : null,
                ':location_text'     => mb_substr($form['location_text'], 0, 200),
                ':equipment_name'    => $form['equipment_name'] !== '' ? mb_substr($form['equipment_name'], 0, 200) : null,
                ':issue_description' => $form['issue_description'],
                ':urgency'           => $form['urgency'],
                ':before_photo_path' => $uploadedPhotoPath,
                ':status'            => REPAIR_STATUS_WAITING,
            ]);

            flash('success', "ส่งใบแจ้งซ่อม {$ticketCode} เรียบร้อยแล้ว");
            redirect(app_url('/maintenance.php'));
        } catch (Throwable $e) {
            delete_uploaded_file($uploadedPhotoPath);
            $errors[] = 'ไม่สามารถบันทึกใบแจ้งซ่อมได้ กรุณาลองใหม่อีกครั้ง';
        }
    } else {
        delete_uploaded_file($uploadedPhotoPath);
    }
}

$stmtMyMaint = $pdo->prepare(
    'SELECT m.*, s.full_name AS staff_name
     FROM maintenance_requests m
     LEFT JOIN users s ON s.id = m.assigned_staff_id
     WHERE m.reporter_id = :uid
     ORDER BY m.reported_at DESC, m.id DESC'
);
$stmtMyMaint->execute([':uid' => (int)$user['id']]);
$myTickets = $stmtMyMaint->fetchAll();

$repairBadges = defined('REPAIR_STATUS_BADGES') ? REPAIR_STATUS_BADGES : [];
$pageTitle = 'แจ้งซ่อมออนไลน์ (QR)';
$activeNav = 'maintenance';

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-qr-code-scan me-1"></i>ระบบรับแจ้งปัญหาอุปกรณ์และสถานที่ (QR Code)
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="maintenance-heading">แจ้งซ่อมออนไลน์</h1>
            <p class="small text-muted mb-0">แจ้งจุดชำรุด แนบภาพถ่ายจากมือถือ แล้วกด <strong>ดูรายละเอียด</strong> เพื่อติดตามงานซ่อม</p>
        </div>
        <?php if (has_role([ROLE_STAFF, ROLE_ADMIN], $user)): ?>
            <a href="<?= e(app_url('/maintenance_manage.php')) ?>" class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3 flex-shrink-0">
                <i class="bi bi-tools"></i> จัดการงานซ่อม
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($preRoom || $preAsset): ?>
    <div class="alert alert-info border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-3" data-testid="qr-prefill-banner">
        <i class="bi bi-qr-code-scan fs-3 text-primary"></i>
        <div>
            <div class="fw-bold">ตรวจพบข้อมูลจากการสแกน QR Code</div>
            <div class="small">
                <?php if ($preAsset): ?>
                    อุปกรณ์: <strong><?= e($preAsset['name']) ?></strong> (<?= e($preAsset['asset_code']) ?>)
                    <?php if (!empty($preAsset['location'])): ?> · สถานที่: <?= e($preAsset['location']) ?><?php endif; ?>
                <?php elseif ($preRoom): ?>
                    ห้องประชุม: <strong><?= e($preRoom['name']) ?></strong> (<?= e($preRoom['room_code']) ?>)
                    <?php if (!empty($preRoom['building'])): ?> · <?= e($preRoom['building'] . ' ' . ($preRoom['floor'] ?? '')) ?><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: Maintenance Request Form -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-pencil-square text-primary me-2"></i>กรอกข้อมูลแจ้งซ่อม</h2>
            </div>
            <div class="card-body p-3 p-md-4">
                <form method="post"
                      action="<?= e(app_url('/maintenance.php')) ?>"
                      enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="row g-2 mb-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold small text-secondary" for="room_id">ห้อง / อาคาร</label>
                            <select class="form-select form-select-sm touch-target" id="room_id" name="room_id">
                                <option value="0">-- ไม่ระบุห้อง --</option>
                                <?php foreach ($rooms as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= ($form['room_id'] === (int)$r['id']) ? 'selected' : '' ?>>
                                        <?= e($r['room_code'] . ' — ' . $r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold small text-secondary" for="asset_id">รหัสครุภัณฑ์</label>
                            <select class="form-select form-select-sm touch-target" id="asset_id" name="asset_id">
                                <option value="0">-- ไม่ระบุครุภัณฑ์ --</option>
                                <?php foreach ($assets as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= ($form['asset_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                        <?= e($a['asset_code'] . ' — ' . $a['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary" for="location_text">สถานที่พบปัญหา <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control touch-target"
                               id="location_text"
                               name="location_text"
                               value="<?= e($form['location_text']) ?>"
                               placeholder="เช่น อาคารอำนวยการ ชั้น 1 ห้องประชุมราชพฤกษ์"
                               required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12 col-sm-8">
                            <label class="form-label fw-semibold small text-secondary" for="equipment_name">อุปกรณ์ / สิ่งที่ชำรุด</label>
                            <input type="text"
                                   class="form-control touch-target"
                                   id="equipment_name"
                                   name="equipment_name"
                                   value="<?= e($form['equipment_name']) ?>"
                                   placeholder="เช่น เครื่องปรับอากาศ, โปรเจคเตอร์">
                        </div>
                        <div class="col-12 col-sm-4">
                            <label class="form-label fw-semibold small text-secondary" for="urgency">ความเร่งด่วน</label>
                            <select class="form-select touch-target" id="urgency" name="urgency">
                                <option value="normal" <?= $form['urgency'] === 'normal' ? 'selected' : '' ?>>ปกติ</option>
                                <option value="urgent" <?= $form['urgency'] === 'urgent' ? 'selected' : '' ?>>เร่งด่วน</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary" for="issue_description">อาการชำรุด <span class="text-danger">*</span></label>
                        <textarea class="form-control"
                                  id="issue_description"
                                  name="issue_description"
                                  rows="3"
                                  placeholder="อธิบายอาการที่พบให้ชัดเจน..."
                                  required><?= e($form['issue_description']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary" for="before_photo">ภาพถ่ายก่อนซ่อม (ถ่ายจากกล้องหรือเลือกไฟล์)</label>
                        <input type="file"
                               class="form-control touch-target"
                               id="before_photo"
                               name="before_photo"
                               accept="image/*"
                               capture="environment"
                               data-preview-target="#beforePhotoPreview">
                        <img id="beforePhotoPreview" src="" alt="ตัวอย่างรูปภาพก่อนซ่อม" class="img-fluid rounded-3 border mt-2 d-none" style="max-height: 180px;">
                    </div>

                    <button type="submit" class="btn btn-primary-gradient w-100 fw-semibold touch-target rounded-3">
                        <i class="bi bi-send-fill me-2"></i>ส่งใบแจ้งซ่อม
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: My Submitted Maintenance Requests -->
    <div class="col-12 col-lg-7">
        <div class="spotlight-card">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-list-ul text-primary me-2"></i>รายการแจ้งซ่อมของฉัน (<?= count($myTickets) ?> รายการ)</h2>
            </div>
            <?php if (empty($myTickets)): ?>
                <div class="p-5 text-center text-muted small">
                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                    คุณยังไม่เคยแจ้งซ่อมในระบบ
                </div>
            <?php else: ?>
                <?php foreach ($myTickets as $tk):
                    $tid = (int)$tk['id'];
                    $bCls = $repairBadges[$tk['status']] ?? 'secondary';
                    $photos = [];
                    if (!empty($tk['before_photo_path'])) {
                        $photos[] = ['label' => 'ภาพก่อนซ่อม', 'url' => app_url('/download.php?type=maintenance_before&id=' . $tid)];
                    }
                    if (!empty($tk['after_photo_path'])) {
                        $photos[] = ['label' => 'ภาพหลังซ่อม', 'url' => app_url('/download.php?type=maintenance_after&id=' . $tid)];
                    }
                    $swalMaint = [
                        'title'     => (string)($tk['equipment_name'] ?: 'รายละเอียดใบแจ้งซ่อม'),
                        'subtitle'  => $tk['ticket_code'] . ' · ' . thai_date($tk['reported_at'], true, true),
                        'badge'     => (string)$tk['status'],
                        'rows'      => [
                            ['label' => 'สถานที่',     'value' => (string)$tk['location_text']],
                            ['label' => 'อาการชำรุด',   'value' => (string)$tk['issue_description']],
                            ['label' => 'ความเร่งด่วน', 'value' => $tk['urgency'] === 'urgent' ? 'งานด่วน' : 'ปกติ'],
                            ['label' => 'ช่างผู้รับผิดชอบ', 'value' => (string)($tk['staff_name'] ?: 'รอรับเรื่อง')],
                        ],
                        'noteTitle' => 'บันทึกการซ่อมจากช่าง',
                        'note'      => (string)($tk['repair_notes'] ?: ''),
                        'noteTone'  => $tk['status'] === REPAIR_STATUS_DONE ? 'success' : 'info',
                        'photos'    => $photos,
                    ];
                ?>
                    <div class="role-queue-item">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                            <div class="min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <span class="badge text-bg-light border text-dark font-monospace"><?= e($tk['ticket_code']) ?></span>
                                    <span class="badge text-bg-<?= e($bCls) ?>"><?= e($tk['status']) ?></span>
                                    <?php if ($tk['urgency'] === 'urgent'): ?>
                                        <span class="badge text-bg-danger">งานด่วน</span>
                                    <?php endif; ?>
                                </div>
                                <div class="fw-bold text-dark text-clamp-1"><?= e($tk['equipment_name'] ?: $tk['issue_description']) ?></div>
                                <div class="compact-meta text-clamp-1">
                                    <?= e($tk['issue_description']) ?>
                                </div>
                                <div class="small text-muted">
                                    <i class="bi bi-geo-alt me-1"></i><?= e($tk['location_text']) ?> · <?= e(thai_date($tk['reported_at'], false, true)) ?>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                                <?php if (!empty($tk['after_photo_path'])): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-soft-success rounded-3 px-2"
                                            data-swal-photo="<?= e(app_url('/download.php?type=maintenance_after&id=' . $tid)) ?>"
                                            data-swal-title="ภาพหลังซ่อม · <?= e($tk['ticket_code']) ?>">
                                        <i class="bi bi-check2-circle"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button"
                                        class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold"
                                        data-swal-detail="<?= e((string)json_encode($swalMaint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                                    <i class="bi bi-eye me-1"></i>ดูรายละเอียด
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
