<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/upload.php';
require_once __DIR__ . '/includes/booking_conflict.php';

$user = require_login();
$pdo = get_pdo();

ensure_resource_image_schema($pdo);

$pageTitle = 'จองห้องประชุม';
$activeNav = 'book_room';
$isExternal = is_external($user);

$roomsSql = $isExternal
    ? 'SELECT r.id, r.room_code, r.name, r.building, r.floor, r.capacity, r.allow_external,
              r.available_equipment, r.description, r.image_url,
              b.image_url AS building_image_url, b.description AS building_description, b.floors_count AS building_floors
       FROM rooms r
       LEFT JOIN buildings b ON b.name = r.building
       WHERE r.is_active = 1 AND r.allow_external = 1
       ORDER BY r.room_code ASC'
    : 'SELECT r.id, r.room_code, r.name, r.building, r.floor, r.capacity, r.allow_external,
              r.available_equipment, r.description, r.image_url,
              b.image_url AS building_image_url, b.description AS building_description, b.floors_count AS building_floors
       FROM rooms r
       LEFT JOIN buildings b ON b.name = r.building
       WHERE r.is_active = 1
       ORDER BY r.room_code ASC';
$rooms = $pdo->query($roomsSql)->fetchAll();
$roomsById = [];
$roomsMeta = [];
foreach ($rooms as $r) {
    $rid = (int)$r['id'];
    $roomsById[$rid] = $r;

    $ownImg = trim((string)($r['image_url'] ?? ''));
    $bldImg = trim((string)($r['building_image_url'] ?? ''));
    $effImg = $ownImg !== '' ? $ownImg : $bldImg;
    $imgSrc = $ownImg !== '' ? 'room' : ($bldImg !== '' ? 'building' : '');

    $eqKeys = json_decode((string)($r['available_equipment'] ?? '[]'), true);
    if (!is_array($eqKeys)) {
        $eqKeys = [];
    }
    $eqLabels = array_values(array_map(static fn ($k): string => ROOM_EQUIPMENT_LABELS[$k] ?? (string)$k, $eqKeys));

    $roomsMeta[$rid] = [
        'id'                   => $rid,
        'room_code'            => (string)$r['room_code'],
        'name'                 => (string)$r['name'],
        'building'             => (string)($r['building'] ?? ''),
        'floor'                => (string)($r['floor'] ?? ''),
        'capacity'             => (int)$r['capacity'],
        'allow_external'       => ((int)$r['allow_external'] === 1),
        'equipment_labels'     => $eqLabels,
        'description'          => (string)($r['description'] ?? ''),
        'building_description' => (string)($r['building_description'] ?? ''),
        'image_url'            => $effImg,
        'image_source'         => $imgSrc,
    ];
}

$prefillStart = parse_local_datetime($_GET['start'] ?? null);
$prefillEnd = parse_local_datetime($_GET['end'] ?? null);
$defaultStart = $prefillStart ? str_replace(' ', 'T', substr($prefillStart, 0, 16)) : date('Y-m-d\T09:00', strtotime('+1 day'));
$defaultEnd = $prefillEnd ? str_replace(' ', 'T', substr($prefillEnd, 0, 16)) : date('Y-m-d\T12:00', strtotime('+1 day'));

$form = [
    'room_id'        => isset($_GET['room_id']) ? (string)(int)$_GET['room_id'] : (isset($rooms[0]['id']) ? (string)(int)$rooms[0]['id'] : ''),
    'title'          => '',
    'purpose'        => '',
    'start_datetime' => $defaultStart,
    'end_datetime'   => $defaultEnd,
    'attendee_count' => '10',
    'equipment'      => [],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['room_id']        = trim((string)($_POST['room_id'] ?? ''));
    $form['title']          = trim((string)($_POST['title'] ?? ''));
    $form['purpose']        = trim((string)($_POST['purpose'] ?? ''));
    $form['start_datetime'] = trim((string)($_POST['start_datetime'] ?? ''));
    $form['end_datetime']   = trim((string)($_POST['end_datetime'] ?? ''));
    $form['attendee_count'] = trim((string)($_POST['attendee_count'] ?? '0'));
    $rawEquipment           = $_POST['equipment_requested'] ?? $_POST['equipment'] ?? [];
    if (!is_array($rawEquipment)) {
        $rawEquipment = [];
    }
    $allowedEqKeys = array_keys(ROOM_EQUIPMENT_LABELS);
    $form['equipment'] = array_values(array_intersect($allowedEqKeys, array_map('strval', $rawEquipment)));

    $roomId = (int)$form['room_id'];
    $attendeeCount = (int)$form['attendee_count'];
    $startSql = parse_local_datetime($form['start_datetime']);
    $endSql = parse_local_datetime($form['end_datetime']);

    $roomRow = null;
    if ($roomId <= 0) {
        $errors[] = 'กรุณาเลือกห้องประชุม';
    } else {
        $stmtRoom = $pdo->prepare('SELECT * FROM rooms WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmtRoom->execute([':id' => $roomId]);
        $roomRow = $stmtRoom->fetch();
        if (!$roomRow) {
            $errors[] = 'ไม่พบห้องประชุมที่เลือก หรือห้องประชุมปิดให้บริการ';
        } elseif ($isExternal && (int)$roomRow['allow_external'] !== 1) {
            $errors[] = 'บุคคลภายนอกไม่มีสิทธิ์ขอใช้ห้องประชุมนี้';
        }
    }

    if ($form['title'] === '') {
        $errors[] = 'กรุณาระบุหัวข้อ/ชื่อการประชุม';
    }
    if ($startSql === null || $endSql === null) {
        $errors[] = 'กรุณาระบุวันและเวลาเริ่มต้น-สิ้นสุดให้ถูกต้อง';
    } elseif ($endSql <= $startSql) {
        $errors[] = 'วันและเวลาสิ้นสุดต้องมากกว่าวันและเวลาเริ่มต้น';
    }

    if ($attendeeCount <= 0) {
        $errors[] = 'จำนวนผู้เข้าร่วมต้องมากกว่า 0 คน';
    } elseif ($roomRow && $attendeeCount > (int)$roomRow['capacity']) {
        $errors[] = 'จำนวนผู้เข้าร่วมเกินความจุห้องประชุม (สูงสุด ' . (int)$roomRow['capacity'] . ' คน)';
    }

    if (empty($errors) && $roomRow && $startSql !== null && $endSql !== null) {
        $conflict = find_room_conflict($pdo, $roomId, $startSql, $endSql);
        if ($conflict !== null) {
            $errors[] = 'ช่วงวันและเวลานี้มีการจองห้อง "' . $roomRow['name'] . '" ไว้แล้ว ('
                . thai_date($conflict['start_datetime'], true, true) . ' - '
                . date('H:i', strtotime($conflict['end_datetime'])) . ' น.)';
        }
    }

    $uploadedPath = null;
    $uploadedOrigName = null;
    if (empty($errors)) {
        $uploadRes = upload_permission_letter('permission_letter', $isExternal);
        if (!$uploadRes['ok']) {
            $errors[] = $uploadRes['error'] ?? 'ไม่สามารถอัปโหลดเอกสารขออนุญาตได้';
        } elseif ($uploadRes['uploaded']) {
            $uploadedPath = $uploadRes['path'];
            $uploadedOrigName = $uploadRes['original_name'];
        }
    }

    if (empty($errors)) {
        try {
            ensure_booking_signature_schema($pdo);
            $requesterSigPath = save_signature_data_url($_POST['requester_signature_image'] ?? null, 'req_room');
            $refCode = generate_booking_reference($pdo);
            $eqJson = !empty($form['equipment']) ? json_encode($form['equipment'], JSON_UNESCAPED_UNICODE) : null;

            $stmtIns = $pdo->prepare(
                'INSERT INTO bookings (
                    reference_code, booking_type, requester_id, requester_type,
                    room_id, vehicle_id, driver_id, title, purpose,
                    start_datetime, end_datetime, attendee_count, equipment_requested,
                    permission_letter_path, permission_letter_original_name, requester_signature,
                    destination, passenger_count, current_step, status
                ) VALUES (
                    :ref, "room", :req_id, :req_type,
                    :room_id, NULL, NULL, :title, :purpose,
                    :start_dt, :end_dt, :attendees, :eq_json,
                    :perm_path, :perm_orig, :req_sig,
                    NULL, NULL, 1, "pending_head"
                )'
            );
            $stmtIns->execute([
                ':ref'       => $refCode,
                ':req_id'    => (int)$user['id'],
                ':req_type'  => $isExternal ? USER_TYPE_EXTERNAL : USER_TYPE_INTERNAL,
                ':room_id'   => $roomId,
                ':title'     => mb_substr($form['title'], 0, 200),
                ':purpose'   => $form['purpose'] !== '' ? $form['purpose'] : null,
                ':start_dt'  => $startSql,
                ':end_dt'    => $endSql,
                ':attendees' => $attendeeCount,
                ':eq_json'   => $eqJson,
                ':perm_path' => $uploadedPath,
                ':perm_orig' => $uploadedOrigName,
                ':req_sig'   => $requesterSigPath,
            ]);
            $newBookingId = (int)$pdo->lastInsertId();

            log_booking_notification(
                $pdo,
                $newBookingId,
                'pending_head',
                ROLE_HEAD,
                null,
                'head@slp.local',
                "มีคำขอจองห้องประชุมใหม่ {$refCode} ({$form['title']}) รอการพิจารณาจากหัวหน้างาน"
            );

            flash('success', "ส่งคำขอจองห้องประชุม {$refCode} เรียบร้อยแล้ว");
            redirect(app_url('/my_bookings.php'));
        } catch (Throwable $e) {
            delete_uploaded_file($uploadedPath);
            $errors[] = 'ไม่สามารถบันทึกข้อมูลการจองได้ กรุณาลองใหม่อีกครั้ง';
        }
    } else {
        delete_uploaded_file($uploadedPath);
    }

    if (!empty($errors)) {
        http_response_code(422);
    }
}

// Fetch upcoming occupied slots for user reference
$upcomingSlots = $pdo->query(
    "SELECT b.reference_code, b.start_datetime, b.end_datetime, b.status, b.attendee_count, r.name AS room_name, r.room_code, r.building
     FROM bookings b
     INNER JOIN rooms r ON r.id = b.room_id
     WHERE b.booking_type = 'room'
       AND b.status != 'rejected'
       AND b.end_datetime >= NOW()
     ORDER BY b.start_datetime ASC
     LIMIT 6"
)->fetchAll();

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-door-open me-1"></i>ระบบจองห้องประชุมและสถานที่
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="book-room-heading">ยื่นคำขอจองห้องประชุม</h1>
            <p class="small text-muted mb-0">
                <?= $isExternal ? 'เลือกสถานที่ที่เปิดให้หน่วยงานภายนอกใช้งาน พร้อมแนบหนังสือขออนุญาต' : 'เลือกห้องประชุม วัน-เวลา และอุปกรณ์เสริม ระบบจะตรวจสอบเวลาชนกันอัตโนมัติ' ?>
            </p>
        </div>
        <a href="<?= e(app_url('/calendar.php')) ?>" class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 flex-shrink-0">
            <i class="bi bi-calendar3"></i>
            <span>ดูปฏิทินห้องว่าง</span>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="spotlight-card p-4">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 rounded-3" role="alert" data-testid="book-room-error">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(app_url('/book_room.php')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>

                <?php if (empty($rooms)): ?>
                    <div class="alert alert-warning border-0 rounded-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 mb-3">
                        <div class="small">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            ยังไม่มีข้อมูลห้องประชุมที่เปิดให้บริการในระบบ
                        </div>
                        <?php if (has_role(ROLE_ADMIN, $user)): ?>
                            <a href="<?= e(app_url('/resources_manage.php?tab=rooms')) ?>" class="btn btn-sm btn-primary rounded-3 flex-shrink-0">
                                <i class="bi bi-plus-circle me-1"></i>เพิ่มห้องประชุม
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="room_id" class="form-label fw-semibold small text-secondary">เลือกห้องประชุม <span class="text-danger">*</span></label>
                    <select class="form-select touch-target" id="room_id" name="room_id" required data-testid="book-room-select">
                        <?php if (empty($rooms)): ?>
                            <option value="">-- ยังไม่มีห้องประชุมในระบบ --</option>
                        <?php else: ?>
                            <?php foreach ($rooms as $r):
                                $locText = trim((string)($r['building'] ?? '') . ($r['floor'] ? ' ' . $r['floor'] : ''));
                            ?>
                                <option value="<?= (int)$r['id'] ?>"
                                        data-capacity="<?= (int)$r['capacity'] ?>"
                                        <?= ((string)$r['id'] === $form['room_id']) ? 'selected' : '' ?>>
                                    <?= e($r['room_code'] . ' — ' . $r['name'] . ($locText !== '' ? ' [' . $locText . ']' : '') . ' (รองรับ ' . (int)$r['capacity'] . ' คน)') ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <?php if (!empty($rooms)): ?>
                        <div class="room-picker-grid mt-2" aria-label="เลือกห้องประชุมจากการ์ด">
                            <?php foreach ($rooms as $r):
                                $rmInfo = $roomsMeta[(int)$r['id']] ?? [];
                                $thumbImg = (string)($rmInfo['image_url'] ?? '');
                                $isSelectedCard = ((string)$r['id'] === (string)$form['room_id']);
                                $locSub = trim(($r['building'] ?: 'ไม่ระบุอาคาร') . ($r['floor'] ? ' · ' . $r['floor'] : ''));
                            ?>
                                <button type="button"
                                        class="room-picker-card<?= $isSelectedCard ? ' is-selected' : '' ?>"
                                        data-room-card-id="<?= (int)$r['id'] ?>"
                                        data-testid="room-picker-card-<?= e($r['room_code']) ?>">
                                    <span class="room-picker-thumb">
                                        <?php if ($thumbImg !== ''): ?>
                                            <img src="<?= e($thumbImg) ?>"
                                                 alt="<?= e($r['name']) ?>"
                                                 loading="lazy"
                                                 onerror="this.classList.add('d-none'); this.nextElementSibling?.classList.remove('d-none');">
                                            <i class="bi bi-door-open-fill d-none"></i>
                                        <?php else: ?>
                                            <i class="bi bi-door-open-fill"></i>
                                        <?php endif; ?>
                                        <span class="room-picker-thumb-cap">รองรับ <?= (int)$r['capacity'] ?> คน</span>
                                    </span>
                                    <span class="room-picker-body d-block">
                                        <span class="d-flex align-items-center justify-content-between gap-1 mb-1">
                                            <span class="fw-semibold text-dark small text-truncate"><?= e($r['name']) ?></span>
                                            <?php if ((int)$r['allow_external'] === 1): ?>
                                                <span class="badge text-bg-info text-dark flex-shrink-0" style="font-size: 0.65rem;">ภายนอก</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="d-block text-muted text-truncate" style="font-size: 0.74rem;">
                                            <i class="bi bi-building me-1"></i><?= e($locSub) ?>
                                        </span>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="title" class="form-label fw-semibold small text-secondary">หัวข้อ / ชื่อกิจกรรม <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control touch-target"
                           id="title"
                           name="title"
                           value="<?= e($form['title']) ?>"
                           placeholder="เช่น ประชุมชี้แจงแผนงานประจำเดือน"
                           required
                           data-testid="book-room-title">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="start_datetime" class="form-label fw-semibold small text-secondary">วันและเวลาเริ่มต้น <span class="text-danger">*</span></label>
                        <input type="datetime-local"
                               class="form-control touch-target"
                               id="start_datetime"
                               name="start_datetime"
                               value="<?= e($form['start_datetime']) ?>"
                               required
                               data-testid="book-room-start">
                    </div>
                    <div class="col-md-6">
                        <label for="end_datetime" class="form-label fw-semibold small text-secondary">วันและเวลาสิ้นสุด <span class="text-danger">*</span></label>
                        <input type="datetime-local"
                               class="form-control touch-target"
                               id="end_datetime"
                               name="end_datetime"
                               value="<?= e($form['end_datetime']) ?>"
                               required
                               data-testid="book-room-end">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="attendee_count" class="form-label fw-semibold small text-secondary">จำนวนผู้เข้าร่วม (คน) <span class="text-danger">*</span></label>
                        <input type="number"
                               class="form-control touch-target"
                               id="attendee_count"
                               name="attendee_count"
                               min="1"
                               value="<?= e($form['attendee_count']) ?>"
                               required
                               data-testid="book-room-attendees">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small text-secondary d-block">อุปกรณ์เสริมที่ต้องการ</label>
                        <div class="d-flex flex-wrap gap-2 pt-1">
                            <?php foreach (ROOM_EQUIPMENT_LABELS as $eqKey => $eqLabel):
                                $isCheckedEq = in_array($eqKey, $form['equipment'], true);
                            ?>
                                <label class="btn btn-sm btn-outline-secondary rounded-pill eq-pill-label d-inline-flex align-items-center gap-2 px-3 py-2 m-0" for="eq_<?= e($eqKey) ?>" style="cursor: pointer;">
                                    <input class="form-check-input m-0"
                                           type="checkbox"
                                           name="equipment_requested[]"
                                           id="eq_<?= e($eqKey) ?>"
                                           value="<?= e($eqKey) ?>"
                                           <?= $isCheckedEq ? 'checked' : '' ?>>
                                    <span class="small fw-medium"><?= e($eqLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="purpose" class="form-label fw-semibold small text-secondary">รายละเอียด / วัตถุประสงค์</label>
                    <textarea class="form-control"
                              id="purpose"
                              name="purpose"
                              rows="2"
                              placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)"><?= e($form['purpose']) ?></textarea>
                </div>

                <div class="mb-4">
                    <label for="permission_letter" class="form-label fw-semibold small text-secondary">
                        หนังสือขออนุญาตใช้สถานที่ (PDF, JPG, PNG ไม่เกิน 2MB)
                        <?php if ($isExternal): ?>
                            <span class="text-danger">*</span>
                        <?php endif; ?>
                    </label>
                    <input type="file"
                           class="form-control touch-target"
                           id="permission_letter"
                           name="permission_letter"
                           accept=".pdf,application/pdf,image/jpeg,image/png"
                           data-no-resize="true"
                           <?= $isExternal ? 'required' : '' ?>
                           data-testid="book-room-file">
                </div>

                <!-- Requester Signature Pad -->
                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label fw-semibold small text-secondary mb-0">
                            <i class="bi bi-pen me-1 text-primary"></i>ลงลายมือชื่อผู้ขอจอง
                            <span class="badge text-bg-light border text-secondary ms-1" id="reqSigStatus">ยังไม่ได้เซ็น</span>
                        </label>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2" id="btnClearReqSig">
                            <i class="bi bi-eraser me-1"></i>ล้างลายเซ็น
                        </button>
                    </div>
                    <div class="border rounded-3 bg-white p-1" style="border-style: dashed !important; border-color: #94a3b8 !important;">
                        <canvas id="reqSigCanvas" width="520" height="140" class="w-100 d-block" style="height: 140px; touch-action: none; cursor: crosshair;" data-testid="requester-sig-canvas"></canvas>
                    </div>
                    <input type="hidden" name="requester_signature_image" id="reqSigInput" value="">
                    <div class="form-text small">ใช้นิ้วหรือเมาส์วาดลายมือชื่อในกรอบด้านบน เพื่อแนบในเอกสารบันทึกข้อความ</div>
                </div>

                <button type="submit" class="btn btn-primary-gradient w-100 touch-target fw-semibold rounded-3" data-testid="book-room-submit">
                    <i class="bi bi-send-check me-1"></i>
                    ส่งคำขอจองห้องประชุม
                </button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <?php
            $initRoomId = (int)$form['room_id'];
            $initRoom = $roomsMeta[$initRoomId] ?? (!empty($roomsMeta) ? reset($roomsMeta) : null);
            $initImg = $initRoom ? (string)$initRoom['image_url'] : '';
        ?>
        <div class="spotlight-card room-preview-card mb-4 overflow-hidden" id="selectedRoomPreviewCard" data-testid="room-preview-card">
            <div class="room-preview-hero">
                <img src="<?= e($initImg) ?>"
                     alt="<?= e($initRoom['name'] ?? 'ตัวอย่างห้องประชุม') ?>"
                     id="previewRoomHeroImg"
                     class="<?= $initImg === '' ? 'd-none' : '' ?>"
                     onerror="this.classList.add('d-none'); document.getElementById('previewRoomPlaceholder')?.classList.remove('d-none');">
                <div id="previewRoomPlaceholder" class="room-preview-placeholder<?= $initImg !== '' ? ' d-none' : '' ?>">
                    <i class="bi bi-buildings fs-1 d-block mb-1"></i>
                    <div class="small fw-semibold" id="previewPlaceholderText">
                        <?= $initRoom ? e($initRoom['building'] ?: 'ห้องประชุม') : 'ยังไม่ได้เลือกห้องประชุม' ?>
                    </div>
                </div>

                <button type="button"
                        id="previewRoomZoomBtn"
                        class="btn room-preview-zoom-btn<?= $initImg === '' ? ' d-none' : '' ?>">
                    <i class="bi bi-arrows-fullscreen me-1"></i>ดูภาพขยาย
                </button>

                <div class="room-preview-overlay-badges">
                    <span class="badge text-bg-light text-dark font-monospace shadow-sm" id="previewRoomCodeBadge">
                        <?= $initRoom ? e($initRoom['room_code']) : '-' ?>
                    </span>
                    <span class="badge text-bg-dark bg-opacity-75 shadow-sm" id="previewRoomBuildingBadge">
                        <?= $initRoom ? e($initRoom['building'] ?: 'ไม่ระบุอาคาร') : '-' ?>
                    </span>
                </div>
            </div>

            <div class="p-3">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <h2 class="h6 fw-bold text-dark mb-0" id="previewRoomTitle">
                        <?= $initRoom ? e($initRoom['name']) : 'กรุณาเลือกห้องประชุม' ?>
                    </h2>
                    <span class="badge text-bg-light border text-secondary flex-shrink-0<?= ($initRoom && $initRoom['image_source'] === 'building') ? '' : ' d-none' ?>" id="previewRoomImgSourceBadge" style="font-size: 0.7rem;">ภาพอาคาร</span>
                </div>

                <div class="small text-muted d-flex flex-wrap gap-3 mb-2">
                    <span id="previewRoomLocation">
                        <i class="bi bi-geo-alt text-primary me-1"></i><?= $initRoom ? e(trim(($initRoom['building'] ?: 'ไม่ระบุอาคาร') . ($initRoom['floor'] ? ' · ' . $initRoom['floor'] : ''))) : '-' ?>
                    </span>
                    <span id="previewRoomCapacity">
                        <i class="bi bi-people text-success me-1"></i><?= $initRoom ? ('รองรับ ' . (int)$initRoom['capacity'] . ' คน') : '-' ?>
                    </span>
                </div>

                <div class="d-flex flex-wrap gap-1 mb-2" id="previewRoomEquipment">
                    <?php if ($initRoom): ?>
                        <?php if ($initRoom['allow_external']): ?>
                            <span class="badge text-bg-info text-dark">บุคคลภายนอกจองได้</span>
                        <?php endif; ?>
                        <?php foreach ($initRoom['equipment_labels'] as $eqL): ?>
                            <span class="badge text-bg-light border text-secondary"><?= e($eqL) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="small text-secondary mb-0" id="previewRoomDesc">
                    <?= $initRoom ? e($initRoom['description'] ?: ($initRoom['building_description'] ?: 'พร้อมให้บริการตามช่วงเวลาที่ว่าง')) : '' ?>
                </div>
            </div>
        </div>

        <div class="spotlight-card">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history text-primary me-1"></i>คิวจองห้องประชุมเร็ว ๆ นี้</h2>
            </div>
            <?php if (empty($upcomingSlots)): ?>
                <div class="p-4 text-center text-muted small">ยังไม่มีรายการจองล่วงหน้า</div>
            <?php else: ?>
                <?php foreach ($upcomingSlots as $slot):
                    $stMetaSlot = BOOKING_STATUS_LABELS[$slot['status']] ?? ['label' => $slot['status']];
                    $slotDetail = [
                        'title'    => $slot['room_code'] . ' · ' . $slot['room_name'],
                        'subtitle' => 'ช่วงเวลาที่ถูกจองแล้ว',
                        'badge'    => (string)$stMetaSlot['label'],
                        'rows'     => [
                            ['label' => 'อาคาร/สถานที่', 'value' => (string)($slot['room_building'] ?? $slot['building'] ?? '-')],
                            ['label' => 'วันเวลา',       'value' => thai_date($slot['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($slot['end_datetime'])) . ' น.'],
                            ['label' => 'จำนวนผู้เข้าร่วม', 'value' => (int)($slot['attendee_count'] ?? 0) . ' คน'],
                        ],
                    ];
                ?>
                    <button type="button"
                            class="role-queue-item w-100 text-start border-0 bg-transparent d-flex align-items-center justify-content-between gap-2"
                            data-swal-detail="<?= e((string)json_encode($slotDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                        <div class="min-w-0">
                            <div class="fw-semibold text-dark small text-truncate"><?= e($slot['room_code'] . ' · ' . $slot['room_name']) ?></div>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-calendar-event me-1"></i><?= e(thai_date($slot['start_datetime'], true, true)) ?> - <?= e(date('H:i', strtotime($slot['end_datetime']))) ?> น.
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small flex-shrink-0"></i>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ROOMS_META = <?= (string)json_encode($roomsMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const roomSelect = document.getElementById('room_id');
    const attendeesInput = document.getElementById('attendee_count');
    const heroImg = document.getElementById('previewRoomHeroImg');
    const placeholder = document.getElementById('previewRoomPlaceholder');
    const placeholderText = document.getElementById('previewPlaceholderText');
    const zoomBtn = document.getElementById('previewRoomZoomBtn');
    const codeBadge = document.getElementById('previewRoomCodeBadge');
    const bldBadge = document.getElementById('previewRoomBuildingBadge');
    const imgSrcBadge = document.getElementById('previewRoomImgSourceBadge');
    const titleEl = document.getElementById('previewRoomTitle');
    const locEl = document.getElementById('previewRoomLocation');
    const capEl = document.getElementById('previewRoomCapacity');
    const eqWrap = document.getElementById('previewRoomEquipment');
    const descEl = document.getElementById('previewRoomDesc');

    let currentPreviewUrl = <?= (string)json_encode($initImg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    let currentPreviewTitle = <?= (string)json_encode($initRoom['name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

    if (zoomBtn) {
        zoomBtn.addEventListener('click', function () {
            if (currentPreviewUrl && window.SLPApp && typeof window.SLPApp.showPhoto === 'function') {
                window.SLPApp.showPhoto(currentPreviewUrl, currentPreviewTitle || 'ภาพห้อง/อาคาร');
            }
        });
    }

    function syncRoomPreview(roomId) {
        const meta = ROOMS_META[String(roomId)] || null;
        document.querySelectorAll('.room-picker-card[data-room-card-id]').forEach(function (card) {
            card.classList.toggle('is-selected', card.getAttribute('data-room-card-id') === String(roomId));
        });

        if (!meta) return;
        currentPreviewUrl = meta.image_url || '';
        currentPreviewTitle = meta.name || '';

        if (heroImg && placeholder) {
            if (currentPreviewUrl) {
                heroImg.src = currentPreviewUrl;
                heroImg.alt = currentPreviewTitle;
                heroImg.classList.remove('d-none');
                placeholder.classList.add('d-none');
                if (zoomBtn) zoomBtn.classList.remove('d-none');
            } else {
                heroImg.classList.add('d-none');
                heroImg.removeAttribute('src');
                placeholder.classList.remove('d-none');
                if (placeholderText) placeholderText.textContent = meta.building || meta.name || 'ห้องประชุม';
                if (zoomBtn) zoomBtn.classList.add('d-none');
            }
        }

        if (codeBadge) codeBadge.textContent = meta.room_code || '-';
        if (bldBadge) bldBadge.textContent = meta.building || 'ไม่ระบุอาคาร';
        if (imgSrcBadge) imgSrcBadge.classList.toggle('d-none', meta.image_source !== 'building');
        if (titleEl) titleEl.textContent = meta.name || '-';
        if (locEl) {
            const locText = ((meta.building || 'ไม่ระบุอาคาร') + (meta.floor ? ' · ' + meta.floor : '')).trim();
            locEl.innerHTML = '<i class="bi bi-geo-alt text-primary me-1"></i>';
            locEl.appendChild(document.createTextNode(locText));
        }
        if (capEl) {
            capEl.innerHTML = '<i class="bi bi-people text-success me-1"></i>';
            capEl.appendChild(document.createTextNode('รองรับ ' + (meta.capacity || 0) + ' คน'));
        }
        if (attendeesInput && meta.capacity) {
            attendeesInput.max = String(meta.capacity);
        }
        if (eqWrap) {
            eqWrap.innerHTML = '';
            if (meta.allow_external) {
                const extBadge = document.createElement('span');
                extBadge.className = 'badge text-bg-info text-dark';
                extBadge.textContent = 'บุคคลภายนอกจองได้';
                eqWrap.appendChild(extBadge);
            }
            (meta.equipment_labels || []).forEach(function (lbl) {
                const b = document.createElement('span');
                b.className = 'badge text-bg-light border text-secondary';
                b.textContent = lbl;
                eqWrap.appendChild(b);
            });
        }
        if (descEl) {
            descEl.textContent = meta.description || meta.building_description || 'พร้อมให้บริการตามช่วงเวลาที่ว่าง';
        }
    }

    if (roomSelect) {
        roomSelect.addEventListener('change', function () {
            syncRoomPreview(roomSelect.value);
        });
    }

    document.querySelectorAll('.room-picker-card[data-room-card-id]').forEach(function (card) {
        card.addEventListener('click', function () {
            const rid = card.getAttribute('data-room-card-id');
            if (!rid) return;
            if (roomSelect) {
                roomSelect.value = rid;
            }
            syncRoomPreview(rid);
        });
    });

    // Requester signature canvas
    const sigCanvas = document.getElementById('reqSigCanvas');
    const sigInput = document.getElementById('reqSigInput');
    const sigStatus = document.getElementById('reqSigStatus');
    const btnClearSig = document.getElementById('btnClearReqSig');
    if (sigCanvas && sigInput) {
        const ctx = sigCanvas.getContext('2d');
        let drawing = false;
        let lastX = 0;
        let lastY = 0;
        function getPos(ev) {
            const r = sigCanvas.getBoundingClientRect();
            const sx = r.width > 0 ? (sigCanvas.width / r.width) : 1;
            const sy = r.height > 0 ? (sigCanvas.height / r.height) : 1;
            return { x: (ev.clientX - r.left) * sx, y: (ev.clientY - r.top) * sy };
        }
        sigCanvas.addEventListener('pointerdown', function (ev) {
            if (ev.button !== 0 && ev.pointerType === 'mouse') return;
            drawing = true;
            const p = getPos(ev);
            lastX = p.x; lastY = p.y;
            ctx.beginPath(); ctx.moveTo(lastX, lastY);
            sigCanvas.setPointerCapture(ev.pointerId);
        });
        sigCanvas.addEventListener('pointermove', function (ev) {
            if (!drawing) return;
            const p = getPos(ev);
            ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
            ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.stroke();
            lastX = p.x; lastY = p.y;
            sigCanvas.dataset.hasDrawn = '1';
            if (sigStatus) {
                sigStatus.textContent = 'เซ็นแล้ว';
                sigStatus.className = 'badge text-bg-success ms-1';
            }
        });
        function stopSig(ev) {
            if (!drawing) return;
            drawing = false;
            try { sigCanvas.releasePointerCapture(ev.pointerId); } catch (e) {}
            if (sigCanvas.dataset.hasDrawn === '1') {
                sigInput.value = sigCanvas.toDataURL('image/png');
            }
        }
        sigCanvas.addEventListener('pointerup', stopSig);
        sigCanvas.addEventListener('pointercancel', stopSig);
        if (btnClearSig) {
            btnClearSig.addEventListener('click', function () {
                ctx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
                delete sigCanvas.dataset.hasDrawn;
                sigInput.value = '';
                if (sigStatus) {
                    sigStatus.textContent = 'ยังไม่ได้เซ็น';
                    sigStatus.className = 'badge text-bg-light border text-secondary ms-1';
                }
            });
        }
        const formEl = sigCanvas.closest('form');
        if (formEl) {
            formEl.addEventListener('submit', function () {
                if (sigCanvas.dataset.hasDrawn === '1') {
                    sigInput.value = sigCanvas.toDataURL('image/png');
                }
            });
        }
    }
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
