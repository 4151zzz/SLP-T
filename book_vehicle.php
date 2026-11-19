<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/booking_conflict.php';

$user = require_role(INTERNAL_ROLES);
$pdo = get_pdo();

$pageTitle = 'จองยานพาหนะ';
$activeNav = 'book_vehicle';

$vehicles = $pdo->query(
    "SELECT id, vehicle_code, plate_number, vehicle_type, brand_model, seats, notes
     FROM vehicles
     WHERE status = 'available'
     ORDER BY vehicle_code ASC"
)->fetchAll();

$vehiclesById = [];
$maxFleetSeats = 12;
foreach ($vehicles as $v) {
    $vehiclesById[(int)$v['id']] = $v;
    if ((int)$v['seats'] > $maxFleetSeats) {
        $maxFleetSeats = (int)$v['seats'];
    }
}

$prefillStart = parse_local_datetime($_GET['start'] ?? null);
$prefillEnd = parse_local_datetime($_GET['end'] ?? null);
$defaultStart = $prefillStart ? str_replace(' ', 'T', substr($prefillStart, 0, 16)) : date('Y-m-d\T08:30', strtotime('+1 day'));
$defaultEnd = $prefillEnd ? str_replace(' ', 'T', substr($prefillEnd, 0, 16)) : date('Y-m-d\T16:30', strtotime('+1 day'));

$form = [
    'title'           => '',
    'destination'     => '',
    'purpose'         => '',
    'passenger_count' => '4',
    'vehicle_id'      => '',
    'start_datetime'  => $defaultStart,
    'end_datetime'    => $defaultEnd,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['title']           = trim((string)($_POST['title'] ?? ''));
    $form['destination']     = trim((string)($_POST['destination'] ?? ''));
    $form['purpose']         = trim((string)($_POST['purpose'] ?? ''));
    $form['passenger_count'] = trim((string)($_POST['passenger_count'] ?? '0'));
    $form['vehicle_id']      = trim((string)($_POST['vehicle_id'] ?? ''));
    $form['start_datetime']  = trim((string)($_POST['start_datetime'] ?? ''));
    $form['end_datetime']    = trim((string)($_POST['end_datetime'] ?? ''));

    $passengerCount = (int)$form['passenger_count'];
    $vehicleId = $form['vehicle_id'] !== '' ? (int)$form['vehicle_id'] : 0;
    $startSql = parse_local_datetime($form['start_datetime']);
    $endSql = parse_local_datetime($form['end_datetime']);

    if ($form['title'] === '') {
        $errors[] = 'กรุณาระบุเรื่อง/ภารกิจการเดินทาง';
    }
    if ($form['destination'] === '') {
        $errors[] = 'กรุณาระบุจุดหมายปลายทาง';
    }
    if ($startSql === null || $endSql === null) {
        $errors[] = 'กรุณาระบุวันและเวลาไป-กลับให้ถูกต้อง';
    } elseif ($endSql <= $startSql) {
        $errors[] = 'วันและเวลากลับต้องมากกว่าวันและเวลาออกเดินทาง';
    }

    $selectedVehicle = null;
    if ($vehicleId > 0) {
        $stmtVeh = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id AND status = 'available' LIMIT 1");
        $stmtVeh->execute([':id' => $vehicleId]);
        $selectedVehicle = $stmtVeh->fetch();
        if (!$selectedVehicle) {
            $errors[] = 'ยานพาหนะที่เลือกไม่พร้อมให้บริการ';
        }
    }

    $seatLimit = $selectedVehicle ? (int)$selectedVehicle['seats'] : $maxFleetSeats;
    if ($passengerCount <= 0) {
        $errors[] = 'จำนวนผู้โดยสารต้องมากกว่า 0 คน';
    } elseif ($passengerCount > $seatLimit) {
        $errors[] = 'จำนวนผู้โดยสารเกินจำนวนที่นั่งที่รองรับ (สูงสุด ' . $seatLimit . ' ที่นั่ง)';
    }

    if (empty($errors) && $vehicleId > 0 && $startSql !== null && $endSql !== null) {
        $conflict = find_vehicle_conflict($pdo, $vehicleId, $startSql, $endSql);
        if ($conflict !== null) {
            $errors[] = 'ยานพาหนะ "' . $selectedVehicle['brand_model'] . '" มีการจองในช่วงเวลาดังกล่าวแล้ว ('
                . thai_date($conflict['start_datetime'], true, true) . ')';
        }
    }

    if (empty($errors)) {
        try {
            $refCode = generate_booking_reference($pdo);
            $stmtIns = $pdo->prepare(
                'INSERT INTO bookings (
                    reference_code, booking_type, requester_id, requester_type,
                    room_id, vehicle_id, driver_id, title, purpose,
                    start_datetime, end_datetime, attendee_count, equipment_requested,
                    permission_letter_path, permission_letter_original_name,
                    destination, passenger_count, current_step, status
                ) VALUES (
                    :ref, "vehicle", :req_id, "internal",
                    NULL, :vehicle_id, NULL, :title, :purpose,
                    :start_dt, :end_dt, NULL, NULL,
                    NULL, NULL,
                    :dest, :passengers, 1, "pending_head"
                )'
            );
            $stmtIns->execute([
                ':ref'        => $refCode,
                ':req_id'     => (int)$user['id'],
                ':vehicle_id' => $vehicleId > 0 ? $vehicleId : null,
                ':title'      => mb_substr($form['title'], 0, 200),
                ':purpose'    => $form['purpose'] !== '' ? $form['purpose'] : null,
                ':start_dt'   => $startSql,
                ':end_dt'     => $endSql,
                ':dest'       => mb_substr($form['destination'], 0, 255),
                ':passengers' => $passengerCount,
            ]);
            $newBookingId = (int)$pdo->lastInsertId();

            log_booking_notification(
                $pdo,
                $newBookingId,
                'pending_head',
                ROLE_HEAD,
                null,
                'head@slp.local',
                "มีคำขอจองยานพาหนะใหม่ {$refCode} ({$form['title']}) รอการพิจารณาจากหัวหน้างาน"
            );
            log_booking_notification(
                $pdo,
                $newBookingId,
                'pending_head',
                ROLE_STAFF,
                null,
                'staff@slp.local',
                "มีคำขอจองยานพาหนะใหม่ {$refCode} ({$form['destination']}) รอจัดสรรรถและพนักงานขับรถ"
            );

            flash('success', "ส่งคำขอจองยานพาหนะ {$refCode} เรียบร้อยแล้ว");
            redirect(app_url('/my_bookings.php'));
        } catch (Throwable $e) {
            $errors[] = 'ไม่สามารถบันทึกข้อมูลการจองยานพาหนะได้';
        }
    }

    if (!empty($errors)) {
        http_response_code(422);
    }
}

$upcomingVehicleBookings = $pdo->query(
    "SELECT b.start_datetime, b.end_datetime, b.destination, v.brand_model, v.plate_number
     FROM bookings b
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     WHERE b.booking_type = 'vehicle'
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
                <i class="bi bi-truck me-1"></i>ระบบขอใช้ยานพาหนะราชการ
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="book-vehicle-heading">ยื่นคำขอจองยานพาหนะ</h1>
            <p class="small text-muted mb-0">ระบุจุดหมายปลายทาง วันเวลาเดินทางไป-กลับ และจำนวนผู้โดยสาร</p>
        </div>
        <a href="<?= e(app_url('/calendar.php')) ?>" class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 flex-shrink-0">
            <i class="bi bi-calendar3"></i>
            <span>ดูปฏิทินการใช้รถ</span>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="spotlight-card p-4">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 rounded-3" role="alert" data-testid="book-vehicle-error">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(app_url('/book_vehicle.php')) ?>" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="title" class="form-label fw-semibold small text-secondary">เรื่อง / ภารกิจการเดินทาง <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control touch-target"
                           id="title"
                           name="title"
                           value="<?= e($form['title']) ?>"
                           placeholder="เช่น เดินทางไปราชการ / ประสานงานเอกสาร"
                           required
                           data-testid="book-vehicle-title">
                </div>

                <div class="mb-3">
                    <label for="destination" class="form-label fw-semibold small text-secondary">จุดหมายปลายทาง <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control touch-target"
                           id="destination"
                           name="destination"
                           value="<?= e($form['destination']) ?>"
                           placeholder="เช่น กระทรวงศึกษาธิการ เขตดุสิต"
                           required
                           data-testid="book-vehicle-destination">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="start_datetime" class="form-label fw-semibold small text-secondary">วันและเวลาออกเดินทาง <span class="text-danger">*</span></label>
                        <input type="datetime-local"
                               class="form-control touch-target"
                               id="start_datetime"
                               name="start_datetime"
                               value="<?= e($form['start_datetime']) ?>"
                               required
                               data-testid="book-vehicle-start">
                    </div>
                    <div class="col-md-6">
                        <label for="end_datetime" class="form-label fw-semibold small text-secondary">วันและเวลากลับ <span class="text-danger">*</span></label>
                        <input type="datetime-local"
                               class="form-control touch-target"
                               id="end_datetime"
                               name="end_datetime"
                               value="<?= e($form['end_datetime']) ?>"
                               required
                               data-testid="book-vehicle-end">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="passenger_count" class="form-label fw-semibold small text-secondary">จำนวนผู้โดยสาร (คน) <span class="text-danger">*</span></label>
                        <input type="number"
                               class="form-control touch-target"
                               id="passenger_count"
                               name="passenger_count"
                               min="1"
                               max="<?= (int)$maxFleetSeats ?>"
                               value="<?= e($form['passenger_count']) ?>"
                               required
                               data-testid="book-vehicle-passengers">
                    </div>
                    <div class="col-md-8">
                        <label for="vehicle_id" class="form-label fw-semibold small text-secondary">ยานพาหนะที่ต้องการ (ไม่บังคับ)</label>
                        <select class="form-select touch-target" id="vehicle_id" name="vehicle_id">
                            <option value="">ให้เจ้าหน้าที่จัดสรรรถและคนขับให้</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= ((string)$v['id'] === $form['vehicle_id']) ? 'selected' : '' ?>>
                                    <?= e($v['vehicle_code'] . ' — ' . $v['vehicle_type'] . ' ' . $v['brand_model'] . ' (' . (int)$v['seats'] . ' ที่นั่ง)') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="purpose" class="form-label fw-semibold small text-secondary">รายละเอียดเพิ่มเติม</label>
                    <textarea class="form-control"
                              id="purpose"
                              name="purpose"
                              rows="2"
                              placeholder="ระบุรายละเอียดหรือหมายเหตุเพิ่มเติม"><?= e($form['purpose']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary-gradient w-100 touch-target fw-semibold rounded-3" data-testid="book-vehicle-submit">
                    <i class="bi bi-send-check me-1"></i>
                    ส่งคำขอใช้ยานพาหนะ
                </button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="spotlight-card mb-4">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-car-front text-success me-1"></i>รถราชการพร้อมบริการ</h2>
            </div>
            <?php foreach ($vehicles as $v):
                $vehDetail = [
                    'title'    => $v['vehicle_code'] . ' · ' . $v['brand_model'],
                    'subtitle' => $v['vehicle_type'],
                    'badge'    => 'พร้อมให้บริการ',
                    'rows'     => [
                        ['label' => 'ทะเบียนรถ', 'value' => (string)$v['plate_number']],
                        ['label' => 'จำนวนที่นั่ง', 'value' => (int)$v['seats'] . ' ที่นั่ง'],
                    ],
                    'noteTitle' => 'หมายเหตุ',
                    'note'      => (string)($v['notes'] ?: ''),
                ];
            ?>
                <button type="button"
                        class="role-queue-item w-100 text-start border-0 bg-transparent d-flex align-items-center justify-content-between gap-2"
                        data-swal-detail="<?= e((string)json_encode($vehDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <div class="min-w-0">
                        <div class="fw-semibold text-dark small"><?= e($v['vehicle_type'] . ' · ' . $v['brand_model']) ?></div>
                        <div class="small text-muted">ทะเบียน <?= e($v['plate_number']) ?> · <?= (int)$v['seats'] ?> ที่นั่ง</div>
                    </div>
                    <i class="bi bi-info-circle text-primary small flex-shrink-0"></i>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="spotlight-card">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history text-primary me-1"></i>คิวใช้รถเร็ว ๆ นี้</h2>
            </div>
            <?php if (empty($upcomingVehicleBookings)): ?>
                <div class="p-4 text-center text-muted small">ยังไม่มีรายการจองรถล่วงหน้า</div>
            <?php else: ?>
                <?php foreach ($upcomingVehicleBookings as $ub): ?>
                    <div class="role-queue-item">
                        <div class="fw-semibold text-dark small text-truncate"><?= e($ub['brand_model'] ?: 'รอจัดสรรรถ') ?></div>
                        <div class="small text-muted">
                            <?= e(thai_date($ub['start_datetime'], true, true)) ?> - <?= e(date('H:i', strtotime($ub['end_datetime']))) ?> น.
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
