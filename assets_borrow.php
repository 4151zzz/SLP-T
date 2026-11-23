<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/booking_conflict.php';

$user = require_role(INTERNAL_ROLES);
$pdo = get_pdo();

$pageTitle = 'ยืม-คืนอุปกรณ์และกุญแจ';
$activeNav = 'assets_borrow';
$isManager = has_role([ROLE_STAFF, ROLE_ADMIN], $user);
$uid = (int)$user['id'];

$categoryLabels = [
    'equipment' => ['label' => 'อุปกรณ์ทั่วไป',   'icon' => 'bi-tools',          'badge' => 'text-bg-primary'],
    'key'       => ['label' => 'กุญแจห้อง',      'icon' => 'bi-key-fill',       'badge' => 'text-bg-warning text-dark'],
    'av'        => ['label' => 'โสตทัศนูปกรณ์',  'icon' => 'bi-speaker-fill',   'badge' => 'text-bg-info text-dark'],
    'other'     => ['label' => 'อื่น ๆ',          'icon' => 'bi-box-seam-fill',  'badge' => 'text-bg-secondary'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    // -------------------------------------------------------------------------
    // 1. BORROW ASSET / KEY
    // -------------------------------------------------------------------------
    if ($action === 'borrow') {
        $assetId = (int)($_POST['asset_id'] ?? $_POST['item_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? $_POST['qty'] ?? 0);
        $rawRet = trim((string)($_POST['expected_return_at'] ?? $_POST['expected_return_date'] ?? $_POST['return_date'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? $_POST['notes'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $expectedReturnSql = parse_local_datetime($rawRet);
        if ($expectedReturnSql === null) {
            $expectedReturnSql = date('Y-m-d 17:00:00', strtotime('+1 day'));
        }

        if ($assetId <= 0 || $quantity <= 0) {
            flash('danger', 'กรุณาเลือกอุปกรณ์/กุญแจและระบุจำนวนที่ต้องการยืมให้ถูกต้อง');
            redirect(app_url('/assets_borrow.php'));
        }

        try {
            $pdo->beginTransaction();

            $stmtAsset = $pdo->prepare('SELECT * FROM assets WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE');
            $stmtAsset->execute([':id' => $assetId]);
            $asset = $stmtAsset->fetch();

            if (!$asset) {
                $pdo->rollBack();
                flash('danger', 'ไม่พบอุปกรณ์/กุญแจที่เลือก หรือรายการถูกปิดใช้งาน');
                redirect(app_url('/assets_borrow.php'));
            }

            if ((int)$asset['available_stock'] < $quantity) {
                $pdo->rollBack();
                flash('warning', sprintf('จำนวนคงเหลือของ "%s" ไม่เพียงพอ (คงเหลือ %d ชิ้น)', $asset['name'], (int)$asset['available_stock']));
                redirect(app_url('/assets_borrow.php'));
            }

            $stmtDec = $pdo->prepare(
                'UPDATE assets
                 SET available_stock = available_stock - :qty
                 WHERE id = :id AND available_stock >= :qty2'
            );
            $stmtDec->execute([
                ':qty'  => $quantity,
                ':id'   => $assetId,
                ':qty2' => $quantity,
            ]);
            if ($stmtDec->rowCount() !== 1) {
                $pdo->rollBack();
                flash('warning', 'ไม่สามารถตัดสต็อกอุปกรณ์ได้ กรุณาลองใหม่อีกครั้ง');
                redirect(app_url('/assets_borrow.php'));
            }

            try {
                $borrowCode = 'BRW-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            } catch (Throwable $e) {
                $borrowCode = 'BRW-' . date('ymdHis');
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO asset_borrowings (
                    borrow_code, asset_id, borrower_id, issued_by, quantity,
                    purpose, borrowed_at, expected_return_at, status, notes
                 ) VALUES (
                    :code, :aid, :bid, :iid, :qty,
                    :purpose, NOW(), :exp, "borrowed", :notes
                 )'
            );
            $stmtIns->execute([
                ':code'    => $borrowCode,
                ':aid'     => $assetId,
                ':bid'     => $uid,
                ':iid'     => $isManager ? $uid : null,
                ':qty'     => $quantity,
                ':purpose' => $purpose !== '' ? mb_substr($purpose, 0, 255) : null,
                ':exp'     => $expectedReturnSql,
                ':notes'   => $notes !== '' ? $notes : null,
            ]);

            $pdo->commit();
            flash('success', "บันทึกรายการยืม {$borrowCode} ({$asset['name']} จำนวน {$quantity}) เรียบร้อยแล้ว");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'ไม่สามารถบันทึกการยืมอุปกรณ์ได้ กรุณาลองใหม่อีกครั้ง');
        }
        redirect(app_url('/assets_borrow.php'));
    }

    // -------------------------------------------------------------------------
    // 2. RETURN ASSET / KEY (Staff & Admin)
    // -------------------------------------------------------------------------
    if ($action === 'return_asset' || $action === 'return') {
        if (!$isManager) {
            http_response_code(403);
            flash('danger', 'เฉพาะเจ้าหน้าที่บริหาร/ช่างหรือผู้ดูแลระบบเท่านั้นที่บันทึกรับคืนได้');
            redirect(app_url('/assets_borrow.php'));
        }

        $borrowingId = (int)($_POST['borrowing_id'] ?? $_POST['id'] ?? 0);
        $returnNote = trim((string)($_POST['return_note'] ?? $_POST['note'] ?? ''));

        if ($borrowingId <= 0) {
            flash('danger', 'ไม่พบรหัสรายการยืมที่ต้องการรับคืน');
            redirect(app_url('/assets_borrow.php'));
        }

        try {
            $pdo->beginTransaction();

            $stmtBrw = $pdo->prepare(
                'SELECT b.*, a.name AS asset_name
                 FROM asset_borrowings b
                 INNER JOIN assets a ON a.id = b.asset_id
                 WHERE b.id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmtBrw->execute([':id' => $borrowingId]);
            $brw = $stmtBrw->fetch();

            if (!$brw) {
                $pdo->rollBack();
                flash('danger', 'ไม่พบรายการยืมที่ระบุ');
                redirect(app_url('/assets_borrow.php'));
            }

            if ((string)$brw['status'] === 'returned') {
                $pdo->rollBack();
                flash('info', "รายการ {$brw['borrow_code']} ถูกรับคืนเรียบร้อยไปแล้ว");
                redirect(app_url('/assets_borrow.php'));
            }

            $pdo->prepare(
                'UPDATE asset_borrowings
                 SET status = "returned",
                     returned_at = NOW(),
                     return_handler_id = :hid,
                     return_note = :rnote
                 WHERE id = :id'
            )->execute([
                ':hid'   => $uid,
                ':rnote' => $returnNote !== '' ? $returnNote : null,
                ':id'    => $borrowingId,
            ]);

            $pdo->prepare(
                'UPDATE assets
                 SET available_stock = LEAST(total_stock, available_stock + :qty)
                 WHERE id = :aid'
            )->execute([
                ':qty' => (int)$brw['quantity'],
                ':aid' => (int)$brw['asset_id'],
            ]);

            $pdo->commit();
            flash('success', "รับคืน {$brw['borrow_code']} ({$brw['asset_name']}) เรียบร้อยแล้ว");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'เกิดข้อผิดพลาดในการบันทึกรับคืนอุปกรณ์');
        }
        redirect(app_url('/assets_borrow.php'));
    }

    // -------------------------------------------------------------------------
    // 3. MANAGE ASSETS CATALOG (Staff & Admin)
    // -------------------------------------------------------------------------
    if ($action === 'create_asset' || $action === 'update_asset') {
        if (!$isManager) {
            http_response_code(403);
            flash('danger', 'คุณไม่มีสิทธิ์จัดการคลังอุปกรณ์/กุญแจ');
            redirect(app_url('/assets_borrow.php'));
        }

        $isUpdate = ($action === 'update_asset');
        $id = $isUpdate ? (int)($_POST['id'] ?? 0) : 0;
        $code = mb_substr(trim((string)($_POST['asset_code'] ?? '')), 0, 30);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
        $category = trim((string)($_POST['category'] ?? 'equipment'));
        if (!array_key_exists($category, $categoryLabels)) {
            $category = 'equipment';
        }
        $roomId = (int)($_POST['room_id'] ?? 0);
        $location = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 150);
        $totalStock = max(1, (int)($_POST['total_stock'] ?? 1));
        $description = trim((string)($_POST['description'] ?? ''));

        if (($isUpdate && $id <= 0) || $code === '' || $name === '') {
            flash('danger', 'กรุณากรอกรหัสและชื่ออุปกรณ์/กุญแจให้ครบถ้วน');
            redirect(app_url('/assets_borrow.php'));
        }

        try {
            $dupStmt = $pdo->prepare('SELECT id FROM assets WHERE asset_code = :c AND id != :id LIMIT 1');
            $dupStmt->execute([':c' => $code, ':id' => $id]);
            if ($dupStmt->fetch()) {
                flash('danger', "รหัสอุปกรณ์ \"{$code}\" มีอยู่ในระบบแล้ว");
                redirect(app_url('/assets_borrow.php'));
            }

            if ($isUpdate) {
                $oldStmt = $pdo->prepare('SELECT total_stock, available_stock FROM assets WHERE id = :id LIMIT 1');
                $oldStmt->execute([':id' => $id]);
                $old = $oldStmt->fetch();
                if (!$old) {
                    flash('danger', 'ไม่พบข้อมูลอุปกรณ์ที่ต้องการแก้ไข');
                    redirect(app_url('/assets_borrow.php'));
                }
                $diff = $totalStock - (int)$old['total_stock'];
                $newAvail = max(0, min($totalStock, (int)$old['available_stock'] + $diff));

                $pdo->prepare(
                    'UPDATE assets
                     SET asset_code = :c, name = :n, category = :cat, room_id = :rid,
                         location = :loc, total_stock = :tot, available_stock = :avail, description = :desc
                     WHERE id = :id'
                )->execute([
                    ':c'     => $code,
                    ':n'     => $name,
                    ':cat'   => $category,
                    ':rid'   => $roomId > 0 ? $roomId : null,
                    ':loc'   => $location !== '' ? $location : null,
                    ':tot'   => $totalStock,
                    ':avail' => $newAvail,
                    ':desc'  => $description !== '' ? $description : null,
                    ':id'    => $id,
                ]);
                flash('success', "อัปเดตข้อมูล \"{$name}\" เรียบร้อยแล้ว");
            } else {
                $pdo->prepare(
                    'INSERT INTO assets (asset_code, name, category, room_id, location, total_stock, available_stock, description, is_active)
                     VALUES (:c, :n, :cat, :rid, :loc, :tot, :avail, :desc, 1)'
                )->execute([
                    ':c'     => $code,
                    ':n'     => $name,
                    ':cat'   => $category,
                    ':rid'   => $roomId > 0 ? $roomId : null,
                    ':loc'   => $location !== '' ? $location : null,
                    ':tot'   => $totalStock,
                    ':avail' => $totalStock,
                    ':desc'  => $description !== '' ? $description : null,
                ]);
                flash('success', "เพิ่ม \"{$name}\" เข้าคลังเรียบร้อยแล้ว");
            }
        } catch (Throwable $e) {
            flash('danger', 'ไม่สามารถบันทึกข้อมูลอุปกรณ์/กุญแจได้');
        }
        redirect(app_url('/assets_borrow.php'));
    } elseif ($action === 'toggle_asset') {
        if (!$isManager) {
            http_response_code(403);
            flash('danger', 'คุณไม่มีสิทธิ์จัดการคลังอุปกรณ์/กุญแจ');
            redirect(app_url('/assets_borrow.php'));
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE assets SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id')->execute([':id' => $id]);
            flash('success', 'อัปเดตสถานะรายการอุปกรณ์เรียบร้อยแล้ว');
        }
        redirect(app_url('/assets_borrow.php'));
    }
}

// -----------------------------------------------------------------------------
// FETCH DATA FOR RENDER
// -----------------------------------------------------------------------------
$assets = $pdo->query(
    'SELECT a.*, r.room_code, r.name AS room_name
     FROM assets a
     LEFT JOIN rooms r ON r.id = a.room_id
     ORDER BY a.is_active DESC, a.category ASC, a.asset_code ASC'
)->fetchAll();

$borrowableAssets = array_values(array_filter(
    $assets,
    static fn (array $a): bool => (int)$a['is_active'] === 1 && (int)$a['available_stock'] > 0
));

$roomsList = $pdo->query('SELECT id, room_code, name FROM rooms WHERE is_active = 1 ORDER BY room_code ASC')->fetchAll();

$whereBorrow = $isManager ? '1 = 1' : 'b.borrower_id = :uid';
$stmtActive = $pdo->prepare(
    "SELECT b.*, a.asset_code, a.name AS asset_name, a.category,
            u.full_name AS borrower_name, u.phone AS borrower_phone
     FROM asset_borrowings b
     INNER JOIN assets a ON a.id = b.asset_id
     INNER JOIN users u ON u.id = b.borrower_id
     WHERE b.status IN ('borrowed', 'overdue') AND {$whereBorrow}
     ORDER BY b.expected_return_at ASC, b.id DESC"
);
if (!$isManager) {
    $stmtActive->execute([':uid' => $uid]);
} else {
    $stmtActive->execute();
}
$activeBorrowings = $stmtActive->fetchAll();

$stmtHistory = $pdo->prepare(
    "SELECT b.*, a.asset_code, a.name AS asset_name, a.category,
            u.full_name AS borrower_name,
            h.full_name AS handler_name
     FROM asset_borrowings b
     INNER JOIN assets a ON a.id = b.asset_id
     INNER JOIN users u ON u.id = b.borrower_id
     LEFT JOIN users h ON h.id = b.return_handler_id
     WHERE b.status = 'returned' AND {$whereBorrow}
     ORDER BY b.returned_at DESC, b.id DESC
     LIMIT 30"
);
if (!$isManager) {
    $stmtHistory->execute([':uid' => $uid]);
} else {
    $stmtHistory->execute();
}
$returnedHistory = $stmtHistory->fetchAll();

$totalActiveAssets = count(array_filter($assets, static fn (array $a): bool => (int)$a['is_active'] === 1));
$totalAvailStock = array_sum(array_map(static fn (array $a): int => (int)$a['is_active'] === 1 ? (int)$a['available_stock'] : 0, $assets));
$defaultExpectedReturn = date('Y-m-d\T17:00', strtotime('+1 day'));
$qrEnabled = is_qr_enabled($pdo);
$preselectAssetId = (int)($_GET['asset_id'] ?? 0);
$fromQr = isset($_GET['qr']);
$scannedAsset = null;
if ($preselectAssetId > 0) {
    foreach ($assets as $a) {
        if ((int)$a['id'] === $preselectAssetId) {
            $scannedAsset = $a;
            break;
        }
    }
}
$selectedBorrowAsset = $borrowableAssets[0] ?? null;
if ($preselectAssetId > 0) {
    foreach ($borrowableAssets as $ba) {
        if ((int)$ba['id'] === $preselectAssetId) {
            $selectedBorrowAsset = $ba;
            break;
        }
    }
}

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Header Card -->
<?php if ($fromQr && !$qrEnabled): ?>
    <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-3 d-flex align-items-center gap-2" data-testid="qr-disabled-notice">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <span>ระบบสแกน QR Code ถูกปิดใช้งานชั่วคราว โดยยังสามารถทำรายการยืม-คืนผ่านแบบฟอร์มด้านล่างได้ตามปกติ</span>
    </div>
<?php elseif ($scannedAsset && $qrEnabled): ?>
    <div class="alert alert-primary border-0 rounded-4 shadow-sm mb-3 d-flex align-items-center justify-content-between gap-2" data-testid="qr-scanned-banner">
        <div>
            <i class="bi bi-qr-code-scan me-1"></i>
            <strong>สแกน QR Code:</strong> <?= e($scannedAsset['asset_code'] . ' — ' . $scannedAsset['name']) ?>
            (คงเหลือ <?= (int)$scannedAsset['available_stock'] ?>/<?= (int)$scannedAsset['total_stock'] ?>)
        </div>
        <a href="<?= e(app_url('/assets_borrow.php')) ?>" class="btn btn-sm btn-outline-primary rounded-3">ดูทั้งหมด</a>
    </div>
<?php endif; ?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-key-fill me-1"></i>ระบบยืม-คืนอุปกรณ์โสตฯ และกุญแจห้อง
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="assets-borrow-heading">ยืม-คืนอุปกรณ์และกุญแจ</h1>
            <p class="small text-muted mb-0">
                <?= $isManager ? 'บันทึกการเบิก-ยืม ตรวจรับคืนอุปกรณ์ และจัดการคลังพัสดุ/กุญแจในระบบ' : 'ตรวจสอบจำนวนคงเหลือ ยื่นขอยืมอุปกรณ์/กุญแจ และติดตามกำหนดส่งคืน' ?>
            </p>
        </div>

        <?php if ($isManager): ?>
            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                <?php if ($qrEnabled): ?>
                    <a href="<?= e(app_url('/qr_codes.php')) ?>"
                       class="btn btn-outline-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                       data-testid="btn-goto-qr-codes">
                        <i class="bi bi-qr-code"></i>
                        <span>พิมพ์ QR Code</span>
                    </a>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                        id="btnAddAsset"
                        data-testid="btn-add-asset">
                    <i class="bi bi-plus-circle-fill"></i>
                    <span>เพิ่มอุปกรณ์ / กุญแจ</span>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 4 Summary KPI Cards -->
<div class="row g-2 g-sm-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-indigo p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-indigo"><i class="bi bi-box-seam-fill"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">รายการในคลัง</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= $totalActiveAssets ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-emerald p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-emerald"><i class="bi bi-check2-circle"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">คงเหลือพร้อมยืม</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= $totalAvailStock ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-amber p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-amber"><i class="bi bi-hourglass-split"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">กำลังถูกยืม</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= count($activeBorrowings) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-accent-blue p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-blue"><i class="bi bi-arrow-repeat"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">คืนเรียบร้อยแล้ว</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= count($returnedHistory) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Borrow Form + Inventory List -->
    <div class="col-12 col-lg-5">
        <div class="spotlight-card p-4 mb-4">
            <h2 class="h6 fw-bold text-dark mb-3">
                <i class="bi bi-journal-plus text-primary me-1"></i>แบบฟอร์มขอยืมอุปกรณ์ / กุญแจ
            </h2>

            <?php if (empty($borrowableAssets)): ?>
                <div class="alert alert-warning border-0 rounded-3 small mb-0">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    ขณะนี้ยังไม่มีอุปกรณ์หรือกุญแจที่พร้อมให้ยืมในคลัง
                    <?php if ($isManager): ?>
                        กรุณากดปุ่ม <strong>เพิ่มอุปกรณ์ / กุญแจ</strong> ด้านบนเพื่อเพิ่มรายการ
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(app_url('/assets_borrow.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="borrow">

                    <div class="mb-3">
                        <label for="borrow_asset_id" class="form-label fw-semibold small text-secondary">เลือกอุปกรณ์ / กุญแจ <span class="text-danger">*</span></label>
                        <select class="form-select touch-target" id="borrow_asset_id" name="asset_id" required>
                            <?php foreach ($borrowableAssets as $ba): ?>
                                <option value="<?= (int)$ba['id'] ?>"
                                        data-max-stock="<?= (int)$ba['available_stock'] ?>"
                                        <?= ($selectedBorrowAsset && (int)$ba['id'] === (int)$selectedBorrowAsset['id']) ? 'selected' : '' ?>>
                                    <?= e($ba['asset_code'] . ' — ' . $ba['name'] . ' (คงเหลือ ' . (int)$ba['available_stock'] . '/' . (int)$ba['total_stock'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="borrow_qty" class="form-label fw-semibold small text-secondary">จำนวน <span class="text-danger">*</span></label>
                            <input type="number"
                                   class="form-control touch-target"
                                   id="borrow_qty"
                                   name="quantity"
                                   min="1"
                                   max="<?= (int)($selectedBorrowAsset['available_stock'] ?? 1) ?>"
                                   value="1"
                                   required>
                        </div>
                        <div class="col-7">
                            <label for="borrow_return_at" class="form-label fw-semibold small text-secondary">กำหนดวัน-เวลาคืน <span class="text-danger">*</span></label>
                            <input type="datetime-local"
                                   class="form-control touch-target"
                                   id="borrow_return_at"
                                   name="expected_return_at"
                                   value="<?= e($defaultExpectedReturn) ?>"
                                   required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="borrow_purpose" class="form-label fw-semibold small text-secondary">เหตุผล / กิจกรรมที่นำไปใช้</label>
                        <textarea class="form-control"
                                  id="borrow_purpose"
                                  name="purpose"
                                  rows="2"
                                  placeholder="เช่น ใช้ประกอบการประชุม / เปิดห้องเรียน"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary-gradient w-100 touch-target fw-semibold rounded-3" data-testid="btn-submit-borrow">
                        <i class="bi bi-check-circle me-1"></i>ยืนยันการยืม
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Inventory List -->
        <div class="spotlight-card">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-boxes text-primary me-1"></i>รายการอุปกรณ์และกุญแจ</h2>
                <span class="badge rounded-pill text-bg-light border text-secondary"><?= count($assets) ?> รายการ</span>
            </div>

            <?php if (empty($assets)): ?>
                <div class="p-4 text-center text-muted small">ยังไม่มีข้อมูลอุปกรณ์หรือกุญแจในระบบ</div>
            <?php else: ?>
                <?php foreach ($assets as $ast):
                    $cat = $categoryLabels[$ast['category']] ?? $categoryLabels['other'];
                    $isAstActive = ((int)$ast['is_active'] === 1);
                    $avail = (int)$ast['available_stock'];
                    $tot = (int)$ast['total_stock'];
                    $astJson = [
                        'id'          => (int)$ast['id'],
                        'asset_code'  => (string)$ast['asset_code'],
                        'name'        => (string)$ast['name'],
                        'category'    => (string)$ast['category'],
                        'room_id'     => (int)($ast['room_id'] ?? 0),
                        'location'    => (string)($ast['location'] ?? ''),
                        'total_stock' => $tot,
                        'description' => (string)($ast['description'] ?? ''),
                    ];
                ?>
                    <div class="role-queue-item d-flex align-items-start justify-content-between gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                                <span class="badge text-bg-light border text-dark font-monospace"><?= e($ast['asset_code']) ?></span>
                                <span class="badge <?= e($cat['badge']) ?>"><i class="bi <?= e($cat['icon']) ?> me-1"></i><?= e($cat['label']) ?></span>
                                <?php if (!$isAstActive): ?>
                                    <span class="badge text-bg-secondary">ปิดใช้งาน</span>
                                <?php endif; ?>
                            </div>
                            <div class="fw-semibold text-dark small"><?= e($ast['name']) ?></div>
                            <div class="small text-muted">
                                คงเหลือ <strong class="<?= $avail > 0 ? 'text-success' : 'text-danger' ?>"><?= $avail ?></strong>/<?= $tot ?>
                                <?php if (!empty($ast['location']) || !empty($ast['room_name'])): ?>
                                    · <?= e($ast['location'] ?: $ast['room_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-1 flex-shrink-0">
                            <?php if ($isAstActive && $avail > 0): ?>
                                <button type="button"
                                        class="btn btn-sm btn-soft-primary rounded-3 px-2 btn-pick-asset"
                                        data-pick-asset-id="<?= (int)$ast['id'] ?>"
                                        title="เลือกยืมรายการนี้">
                                    ยืม
                                </button>
                            <?php endif; ?>
                            <?php if ($isManager): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary rounded-3 px-2 btn-edit-asset"
                                        data-json="<?= e((string)json_encode($astJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                        title="แก้ไข">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Active Borrowings + Return History -->
    <div class="col-12 col-lg-7">
        <div class="spotlight-card mb-4">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-hourglass-split text-warning me-1"></i>รายการที่กำลังยืมอยู่</h2>
                <span class="badge rounded-pill text-bg-warning text-dark"><?= count($activeBorrowings) ?> รายการ</span>
            </div>

            <?php if (empty($activeBorrowings)): ?>
                <div class="p-4 text-center text-muted small">ไม่มีรายการยืมค้างคืนในขณะนี้</div>
            <?php else: ?>
                <?php foreach ($activeBorrowings as $ab):
                    $isOverdue = strtotime((string)$ab['expected_return_at']) < time();
                    $isHighlighted = ($preselectAssetId > 0 && (int)$ab['asset_id'] === $preselectAssetId);
                ?>
                    <div class="role-queue-item card <?= $isHighlighted ? 'border-primary border-2 bg-primary bg-opacity-10' : 'border-0 bg-transparent' ?> rounded-0 shadow-none" data-testid="active-borrow-<?= e($ab['borrow_code']) ?>">
                        <div class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-2">
                            <div class="min-w-0 flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <span class="badge text-bg-light border text-dark font-monospace"><?= e($ab['borrow_code']) ?></span>
                                    <span class="badge <?= $isOverdue ? 'text-bg-danger' : 'text-bg-warning text-dark' ?>">
                                        <?= $isOverdue ? 'เกินกำหนดคืน' : 'กำลังยืม' ?>
                                    </span>
                                    <span class="badge text-bg-primary bg-opacity-75">จำนวน <?= (int)$ab['quantity'] ?></span>
                                </div>
                                <div class="fw-bold text-dark"><?= e($ab['asset_code'] . ' · ' . $ab['asset_name']) ?></div>
                                <div class="small text-muted mt-1">
                                    <span><i class="bi bi-person me-1"></i>ผู้ยืม: <strong><?= e($ab['borrower_name']) ?></strong></span>
                                    <span class="mx-1">·</span>
                                    <span><i class="bi bi-calendar-check me-1"></i>กำหนดคืน: <?= e(thai_date($ab['expected_return_at'], true, true)) ?></span>
                                </div>
                                <?php if (!empty($ab['purpose'])): ?>
                                    <div class="small text-secondary mt-1">วัตถุประสงค์: <?= e($ab['purpose']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($isManager): ?>
                            <form method="post" action="<?= e(app_url('/assets_borrow.php')) ?>" class="mt-2 pt-2 border-top d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="return_asset">
                                <input type="hidden" name="borrowing_id" value="<?= (int)$ab['id'] ?>">
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="return_note"
                                       placeholder="สภาพอุปกรณ์/หมายเหตุตอนคืน (ถ้ามี)">
                                <button type="submit" class="btn btn-sm btn-success fw-semibold rounded-3 px-3 flex-shrink-0">
                                    <i class="bi bi-box-arrow-in-left me-1"></i>รับคืน
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Return History -->
        <div class="spotlight-card">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history text-primary me-1"></i>ประวัติการยืม-คืนล่าสุด</h2>
            </div>

            <?php if (empty($returnedHistory)): ?>
                <div class="p-4 text-center text-muted small">ยังไม่มีประวัติการคืนอุปกรณ์</div>
            <?php else: ?>
                <?php foreach ($returnedHistory as $rh): ?>
                    <div class="role-queue-item">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="badge text-bg-light border text-dark font-monospace"><?= e($rh['borrow_code']) ?></span>
                                <span class="badge text-bg-success">คืนแล้ว</span>
                                <span class="fw-semibold text-dark small"><?= e($rh['asset_name']) ?> (<?= (int)$rh['quantity'] ?>)</span>
                            </div>
                            <span class="small text-muted"><?= e(thai_date($rh['returned_at'], true, true)) ?></span>
                        </div>
                        <div class="small text-muted">
                            <span>ผู้ยืม: <strong><?= e($rh['borrower_name']) ?></strong></span>
                            <?php if (!empty($rh['handler_name'])): ?>
                                <span class="mx-1">·</span>
                                <span>ผู้รับคืน: <strong><?= e($rh['handler_name']) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($rh['return_note'])): ?>
                                <span class="mx-1">·</span>
                                <span>หมายเหตุ: <?= e($rh['return_note']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isManager): ?>
<!-- Add / Edit Asset Modal (Staff & Admin) -->
<div class="modal fade" id="assetModal" tabindex="-1" aria-labelledby="assetModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="assetModalTitle">เพิ่มอุปกรณ์ / กุญแจใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/assets_borrow.php')) ?>" id="assetForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="ast_action" value="create_asset">
                <input type="hidden" name="id" id="ast_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="ast_code" class="form-label fw-semibold small text-secondary">รหัสอุปกรณ์ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="ast_code" name="asset_code" placeholder="เช่น AST-MIC-01" required>
                        </div>
                        <div class="col-7">
                            <label for="ast_category" class="form-label fw-semibold small text-secondary">หมวดหมู่</label>
                            <select class="form-select touch-target" id="ast_category" name="category">
                                <?php foreach ($categoryLabels as $ck => $cm): ?>
                                    <option value="<?= e($ck) ?>"><?= e($cm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ast_name" class="form-label fw-semibold small text-secondary">ชื่ออุปกรณ์ / กุญแจ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control touch-target" id="ast_name" name="name" placeholder="เช่น ไมโครโฟนไร้สาย / กุญแจห้องประชุม" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label for="ast_total" class="form-label fw-semibold small text-secondary">จำนวนทั้งหมด <span class="text-danger">*</span></label>
                            <input type="number" class="form-control touch-target" id="ast_total" name="total_stock" min="1" value="1" required>
                        </div>
                        <div class="col-8">
                            <label for="ast_room" class="form-label fw-semibold small text-secondary">ผูกกับห้อง (ถ้ามี)</label>
                            <select class="form-select touch-target" id="ast_room" name="room_id">
                                <option value="0">-- ไม่ระบุห้อง --</option>
                                <?php foreach ($roomsList as $rm): ?>
                                    <option value="<?= (int)$rm['id'] ?>"><?= e($rm['room_code'] . ' — ' . $rm['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ast_location" class="form-label fw-semibold small text-secondary">จุดเก็บ / สถานที่เบิก</label>
                        <input type="text" class="form-control touch-target" id="ast_location" name="location" placeholder="เช่น ห้องพัสดุกลาง / เคาน์เตอร์ธุรการ">
                    </div>

                    <div class="mb-0">
                        <label for="ast_desc" class="form-label fw-semibold small text-secondary">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" id="ast_desc" name="description" rows="2"></textarea>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" id="ast_submit">บันทึกอุปกรณ์</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const assetSel = document.getElementById('borrow_asset_id');
    const qtyInp = document.getElementById('borrow_qty');

    function syncQtyMax() {
        if (!assetSel || !qtyInp) return;
        const opt = assetSel.options[assetSel.selectedIndex];
        const maxStock = opt ? Number(opt.getAttribute('data-max-stock') || 1) : 1;
        qtyInp.max = String(Math.max(1, maxStock));
        if (Number(qtyInp.value) > maxStock) {
            qtyInp.value = String(Math.max(1, maxStock));
        }
    }
    if (assetSel) {
        assetSel.addEventListener('change', syncQtyMax);
        syncQtyMax();
    }

    document.querySelectorAll('.btn-pick-asset[data-pick-asset-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const aid = btn.getAttribute('data-pick-asset-id');
            if (assetSel && aid) {
                assetSel.value = aid;
                syncQtyMax();
                assetSel.focus();
            }
        });
    });

    const astModalEl = document.getElementById('assetModal');
    const astModal = astModalEl ? bootstrap.Modal.getOrCreateInstance(astModalEl) : null;

    document.getElementById('btnAddAsset')?.addEventListener('click', function () {
        const f = document.getElementById('assetForm');
        if (f) f.reset();
        document.getElementById('ast_action').value = 'create_asset';
        document.getElementById('ast_id').value = '';
        document.getElementById('assetModalTitle').textContent = 'เพิ่มอุปกรณ์ / กุญแจใหม่';
        document.getElementById('ast_submit').textContent = 'บันทึกอุปกรณ์';
        if (astModal) astModal.show();
    });

    document.querySelectorAll('.btn-edit-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.getAttribute('data-json') || '{}');
            document.getElementById('ast_action').value = 'update_asset';
            document.getElementById('ast_id').value = d.id || '';
            document.getElementById('ast_code').value = d.asset_code || '';
            document.getElementById('ast_category').value = d.category || 'equipment';
            document.getElementById('ast_name').value = d.name || '';
            document.getElementById('ast_total').value = d.total_stock || 1;
            document.getElementById('ast_room').value = d.room_id || 0;
            document.getElementById('ast_location').value = d.location || '';
            document.getElementById('ast_desc').value = d.description || '';
            document.getElementById('assetModalTitle').textContent = 'แก้ไขข้อมูลอุปกรณ์ / กุญแจ';
            document.getElementById('ast_submit').textContent = 'บันทึกการเปลี่ยนแปลง';
            if (astModal) astModal.show();
        });
    });
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
