<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/upload.php';
require_once __DIR__ . '/includes/booking_conflict.php';

$user = require_login();
$pdo = get_pdo();

$pageTitle = 'จองห้องประชุม';
$activeNav = 'book_room';
$isExternal = is_external($user);

$roomsSql = $isExternal
    ? 'SELECT id, room_code, name, building, floor, capacity, allow_external, available_equipment, description
       FROM rooms WHERE is_active = 1 AND allow_external = 1 ORDER BY room_code ASC'
    : 'SELECT id, room_code, name, building, floor, capacity, allow_external, available_equipment, description
       FROM rooms WHERE is_active = 1 ORDER BY room_code ASC';
$rooms = $pdo->query($roomsSql)->fetchAll();
$roomsById = [];
foreach ($rooms as $r) {
    $roomsById[(int)$r['id']] = $r;
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
            $refCode = generate_booking_reference($pdo);
            $eqJson = !empty($form['equipment']) ? json_encode($form['equipment'], JSON_UNESCAPED_UNICODE) : null;

            $stmtIns = $pdo->prepare(
                'INSERT INTO bookings (
                    reference_code, booking_type, requester_id, requester_type,
                    room_id, vehicle_id, driver_id, title, purpose,
                    start_datetime, end_datetime, attendee_count, equipment_requested,
                    permission_letter_path, permission_letter_original_name,
                    destination, passenger_count, current_step, status
                ) VALUES (
                    :ref, "room", :req_id, :req_type,
                    :room_id, NULL, NULL, :title, :purpose,
                    :start_dt, :end_dt, :attendees, :eq_json,
                    :perm_path, :perm_orig,
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

                <div class="mb-3">
                    <label for="room_id" class="form-label fw-semibold small text-secondary">เลือกห้องประชุม <span class="text-danger">*</span></label>
                    <select class="form-select touch-target" id="room_id" name="room_id" required data-testid="book-room-select">
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"
                                    data-capacity="<?= (int)$r['capacity'] ?>"
                                    <?= ((string)$r['id'] === $form['room_id']) ? 'selected' : '' ?>>
                                <?= e($r['room_code'] . ' — ' . $r['name'] . ' (รองรับ ' . (int)$r['capacity'] . ' คน)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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

                <button type="submit" class="btn btn-primary-gradient w-100 touch-target fw-semibold rounded-3" data-testid="book-room-submit">
                    <i class="bi bi-send-check me-1"></i>
                    ส่งคำขอจองห้องประชุม
                </button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-4">
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
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
