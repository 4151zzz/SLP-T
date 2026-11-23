<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/booking_conflict.php';

$approverRoles = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
$user = require_role($approverRoles);

$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$wantsJson = str_contains($accept, 'application/json')
    || $xhr === 'xmlhttprequest'
    || str_contains($contentType, 'application/json');

$respond = static function (bool $ok, int $httpCode, string $message, array $extra = []) use ($wantsJson): never {
    if ($wantsJson) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge([
            'ok'      => $ok,
            'message' => $message,
        ], $ok ? [] : ['error' => $message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    flash($ok ? 'success' : 'danger', $message);
    redirect(app_url('/approvals.php'));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(false, 405, 'Method Not Allowed');
}

$input = $_POST;
if (str_contains($contentType, 'application/json')) {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $input = array_merge($decoded, $input);
        }
    }
}

if (empty($_POST['_csrf_token']) && !empty($input['_csrf_token'])) {
    $_POST['_csrf_token'] = (string)$input['_csrf_token'];
} elseif (empty($_POST['csrf_token']) && !empty($input['csrf_token'])) {
    $_POST['csrf_token'] = (string)$input['csrf_token'];
}

verify_csrf($wantsJson);

$bookingId = (int)($input['booking_id'] ?? $input['id'] ?? 0);
$rawAction = strtolower(trim((string)($input['action'] ?? $input['decision'] ?? '')));
$action = match ($rawAction) {
    'approve', 'approved'                 => 'approve',
    'reject', 'rejected'                  => 'reject',
    'return', 'returned'                  => 'return',
    'assign_vehicle', 'assigned_vehicle'  => 'assign_vehicle',
    default                               => '',
};

$comment = trim((string)($input['comment'] ?? $input['remarks'] ?? ''));
$vehicleId = (int)($input['vehicle_id'] ?? 0);
$driverId = (int)($input['driver_id'] ?? 0);

$roleLabelsMap = defined('ROLE_LABELS') ? ROLE_LABELS : [];
$role = (string)($user['role'] ?? '');
$defaultPosition = trim((string)($user['position_title'] ?? '')) ?: ($roleLabelsMap[$role] ?? $role);
$signerName = trim((string)($input['signer_name'] ?? '')) ?: (string)$user['full_name'];
$signerPosition = trim((string)($input['signer_position'] ?? '')) ?: $defaultPosition;
$digitalSignature = trim((string)($input['digital_signature'] ?? '')) ?: $signerName;
$rawSignatureImage = trim((string)($input['signature_image'] ?? ''));

$saveSignatureDataUrl = static function (string $dataUrl, int $bkId): ?string {
    if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
        return null;
    }
    $b64 = substr($dataUrl, strlen('data:image/png;base64,'));
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 24 || strlen($bin) > 512000) {
        return null;
    }
    if (!str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
        return null;
    }
    $sigDir = UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'signatures';
    if (!is_dir($sigDir)) {
        @mkdir($sigDir, 0755, true);
    }
    if (!is_dir($sigDir)) {
        return null;
    }
    try {
        $fname = sprintf('sig_%d_%s.png', $bkId, bin2hex(random_bytes(8)));
    } catch (Throwable $e) {
        $fname = sprintf('sig_%d_%s.png', $bkId, uniqid('', true));
    }
    $fullPath = $sigDir . DIRECTORY_SEPARATOR . $fname;
    if (@file_put_contents($fullPath, $bin) === false) {
        return null;
    }
    return 'uploads/signatures/' . $fname;
};

if ($bookingId > 0) {
    $sigCandidate = str_starts_with($rawSignatureImage, 'data:image/png;base64,')
        ? $rawSignatureImage
        : (str_starts_with($digitalSignature, 'data:image/png;base64,') ? $digitalSignature : '');
    if ($sigCandidate !== '') {
        $savedSigPath = $saveSignatureDataUrl($sigCandidate, $bookingId);
        if ($savedSigPath !== null) {
            $digitalSignature = $savedSigPath;
        }
    }
}

if ($bookingId <= 0) {
    $respond(false, 422, 'ไม่พบรหัสคำขอจองที่ถูกต้อง');
}
if ($action === '') {
    $respond(false, 422, 'คำสั่งดำเนินการ (action) ไม่ถูกต้อง');
}

$pdo = get_pdo();
$notifyTasks = [];
$updatedStatus = '';
$updatedStep = 1;
$logAction = '';
$successMessage = 'บันทึกการพิจารณาเรียบร้อยแล้ว';

try {
    $pdo->beginTransaction();

    $stmtBooking = $pdo->prepare(
        'SELECT b.*, u.full_name AS requester_name, u.email AS requester_email, u.role AS requester_role
         FROM bookings b
         INNER JOIN users u ON u.id = b.requester_id
         WHERE b.id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $stmtBooking->execute([':id' => $bookingId]);
    $booking = $stmtBooking->fetch();

    if (!$booking) {
        $pdo->rollBack();
        $respond(false, 404, 'ไม่พบรายการคำขอจองที่ต้องการพิจารณา');
    }

    $currentStatus = (string)$booking['status'];
    if (in_array($currentStatus, [BOOKING_STATUS_APPROVED, BOOKING_STATUS_REJECTED], true)) {
        $pdo->rollBack();
        $respond(false, 409, 'คำขอจองนี้ได้รับการพิจารณาเสร็จสิ้นแล้ว');
    }

    $isVehicleBooking = ($booking['booking_type'] === 'vehicle');
    $startSql = (string)$booking['start_datetime'];
    $endSql = (string)$booking['end_datetime'];
    $refCode = (string)$booking['reference_code'];
    $bookingTitle = (string)$booking['title'];

    $insertLog = $pdo->prepare(
        'INSERT INTO approval_logs (
            booking_id, step_number, step_role, approver_id, action,
            comment, signer_name, signer_position, digital_signature, acted_at
         ) VALUES (
            :booking_id, :step_number, :step_role, :approver_id, :action,
            :comment, :signer_name, :signer_position, :digital_signature, NOW()
         )'
    );

    if ($action === 'assign_vehicle') {
        if (!$isVehicleBooking) {
            $pdo->rollBack();
            $respond(false, 422, 'ไม่สามารถจัดสรรยานพาหนะให้คำขอจองห้องประชุมได้');
        }
        if ($currentStatus !== BOOKING_STATUS_PENDING_HEAD) {
            $pdo->rollBack();
            $respond(false, 409, 'จัดสรรยานพาหนะและคนขับได้เฉพาะคำขอที่อยู่ในขั้นตอนที่ 1 (รอหัวหน้างาน)');
        }
        if (!in_array($role, [ROLE_STAFF, ROLE_HEAD, ROLE_ADMIN], true)) {
            $pdo->rollBack();
            $respond(false, 403, 'คุณไม่มีสิทธิ์จัดสรรยานพาหนะและพนักงานขับรถ');
        }
        if ($vehicleId <= 0 || $driverId <= 0) {
            $pdo->rollBack();
            $respond(false, 422, 'กรุณาเลือกทั้งยานพาหนะและพนักงานขับรถ');
        }

        $stmtVeh = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id AND status = 'available' LIMIT 1");
        $stmtVeh->execute([':id' => $vehicleId]);
        $veh = $stmtVeh->fetch();
        if (!$veh) {
            $pdo->rollBack();
            $respond(false, 422, 'ยานพาหนะที่เลือกไม่พร้อมใช้งาน');
        }

        $stmtDrv = $pdo->prepare("SELECT * FROM drivers WHERE id = :id AND status = 'available' LIMIT 1");
        $stmtDrv->execute([':id' => $driverId]);
        $drv = $stmtDrv->fetch();
        if (!$drv) {
            $pdo->rollBack();
            $respond(false, 422, 'พนักงานขับรถที่เลือกไม่พร้อมปฏิบัติงาน');
        }

        $passengers = (int)($booking['passenger_count'] ?? 0);
        if ($passengers > (int)$veh['seats']) {
            $pdo->rollBack();
            $respond(false, 422, 'จำนวนผู้โดยสาร (' . $passengers . ' คน) เกินความจุรถที่เลือก (' . (int)$veh['seats'] . ' ที่นั่ง)');
        }

        $vehConflict = find_vehicle_conflict($pdo, $vehicleId, $startSql, $endSql, $bookingId);
        if ($vehConflict !== null) {
            $pdo->rollBack();
            $respond(false, 409, 'ยานพาหนะ ' . $veh['vehicle_code'] . ' (' . $veh['plate_number'] . ') ถูกจองในช่วงเวลานี้แล้ว (' . $vehConflict['reference_code'] . ')');
        }

        $drvConflict = find_driver_conflict($pdo, $driverId, $startSql, $endSql, $bookingId);
        if ($drvConflict !== null) {
            $pdo->rollBack();
            $respond(false, 409, 'พนักงานขับรถ ' . $drv['full_name'] . ' มีภารกิจในช่วงเวลานี้แล้ว (' . $drvConflict['reference_code'] . ')');
        }

        $pdo->prepare('UPDATE bookings SET vehicle_id = :vid, driver_id = :did WHERE id = :id')
            ->execute([':vid' => $vehicleId, ':did' => $driverId, ':id' => $bookingId]);

        $stepRole = ($role === ROLE_STAFF) ? 'staff' : 'head';
        $logAction = 'assigned_vehicle';
        $updatedStatus = $currentStatus;
        $updatedStep = (int)$booking['current_step'];

        $insertLog->execute([
            ':booking_id'        => $bookingId,
            ':step_number'       => 1,
            ':step_role'         => $stepRole,
            ':approver_id'       => (int)$user['id'],
            ':action'            => $logAction,
            ':comment'           => $comment !== '' ? $comment : ('จัดสรรรถ ' . $veh['vehicle_code'] . ' และคนขับ ' . $drv['full_name']),
            ':signer_name'       => mb_substr($signerName, 0, 150),
            ':signer_position'   => mb_substr($signerPosition, 0, 150),
            ':digital_signature' => $digitalSignature,
        ]);

        $notifyTasks[] = [
            'event'     => 'assigned_vehicle',
            'role'      => ROLE_HEAD,
            'user_id'   => null,
            'recipient' => 'head01@slp.local',
            'message'   => "คำขอ {$refCode} ({$bookingTitle}) จัดสรรรถ {$veh['vehicle_code']} และคนขับ {$drv['full_name']} เรียบร้อยแล้ว",
        ];
        $successMessage = "บันทึกการจัดสรรรถ {$veh['vehicle_code']} และคนขับ {$drv['full_name']} เรียบร้อยแล้ว";
    } elseif ($action === 'approve') {
        if ($currentStatus === BOOKING_STATUS_PENDING_HEAD) {
            if (!in_array($role, [ROLE_HEAD, ROLE_ADMIN], true)) {
                $pdo->rollBack();
                $respond(false, 403, 'เฉพาะหัวหน้างานหรือผู้ดูแลระบบเท่านั้นที่อนุมัติขั้นตอนที่ 1 ได้');
            }

            $effVehId = $vehicleId > 0 ? $vehicleId : (int)($booking['vehicle_id'] ?? 0);
            $effDrvId = $driverId > 0 ? $driverId : (int)($booking['driver_id'] ?? 0);

            if ($isVehicleBooking) {
                if ($effVehId <= 0 || $effDrvId <= 0) {
                    $pdo->rollBack();
                    $respond(false, 422, 'กรุณาจัดสรรยานพาหนะและพนักงานขับรถก่อนส่งต่อขั้นตอนถัดไป');
                }

                $stmtVeh = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id AND status = 'available' LIMIT 1");
                $stmtVeh->execute([':id' => $effVehId]);
                $veh = $stmtVeh->fetch();
                if (!$veh) {
                    $pdo->rollBack();
                    $respond(false, 422, 'ยานพาหนะที่ระบุไม่พร้อมใช้งาน');
                }

                $stmtDrv = $pdo->prepare("SELECT * FROM drivers WHERE id = :id AND status = 'available' LIMIT 1");
                $stmtDrv->execute([':id' => $effDrvId]);
                $drv = $stmtDrv->fetch();
                if (!$drv) {
                    $pdo->rollBack();
                    $respond(false, 422, 'พนักงานขับรถที่ระบุไม่พร้อมปฏิบัติงาน');
                }

                $passengers = (int)($booking['passenger_count'] ?? 0);
                if ($passengers > (int)$veh['seats']) {
                    $pdo->rollBack();
                    $respond(false, 422, 'จำนวนผู้โดยสารเกินความจุของยานพาหนะที่เลือก');
                }

                $vehConflict = find_vehicle_conflict($pdo, $effVehId, $startSql, $endSql, $bookingId);
                if ($vehConflict !== null) {
                    $pdo->rollBack();
                    $respond(false, 409, 'ยานพาหนะ ' . $veh['vehicle_code'] . ' มีการจองซ้อนในช่วงเวลานี้แล้ว (' . $vehConflict['reference_code'] . ')');
                }

                $drvConflict = find_driver_conflict($pdo, $effDrvId, $startSql, $endSql, $bookingId);
                if ($drvConflict !== null) {
                    $pdo->rollBack();
                    $respond(false, 409, 'พนักงานขับรถ ' . $drv['full_name'] . ' มีคิวซ้อนในช่วงเวลานี้แล้ว (' . $drvConflict['reference_code'] . ')');
                }

                $pdo->prepare(
                    "UPDATE bookings SET vehicle_id = :vid, driver_id = :did, status = 'pending_vice', current_step = 2 WHERE id = :id"
                )->execute([':vid' => $effVehId, ':did' => $effDrvId, ':id' => $bookingId]);
            } else {
                $pdo->prepare("UPDATE bookings SET status = 'pending_vice', current_step = 2 WHERE id = :id")
                    ->execute([':id' => $bookingId]);
            }

            $updatedStatus = BOOKING_STATUS_PENDING_VICE;
            $updatedStep = 2;
            $logAction = 'approved';

            $insertLog->execute([
                ':booking_id'        => $bookingId,
                ':step_number'       => 1,
                ':step_role'         => 'head',
                ':approver_id'       => (int)$user['id'],
                ':action'            => $logAction,
                ':comment'           => $comment !== '' ? $comment : null,
                ':signer_name'       => mb_substr($signerName, 0, 150),
                ':signer_position'   => mb_substr($signerPosition, 0, 150),
                ':digital_signature' => $digitalSignature,
            ]);

            $notifyTasks[] = [
                'event'     => 'pending_vice',
                'role'      => ROLE_VICE_DIRECTOR,
                'user_id'   => null,
                'recipient' => 'vice01@slp.local',
                'message'   => "คำขอ {$refCode} ({$bookingTitle}) ผ่านการพิจารณาขั้นต้นจากหัวหน้างานแล้ว รอรองผู้อำนวยการพิจารณา",
            ];
            $successMessage = "เห็นชอบคำขอ {$refCode} และส่งต่อรองผู้อำนวยการเรียบร้อยแล้ว";
        } elseif ($currentStatus === BOOKING_STATUS_PENDING_VICE) {
            if (!in_array($role, [ROLE_VICE_DIRECTOR, ROLE_ADMIN], true)) {
                $pdo->rollBack();
                $respond(false, 403, 'เฉพาะรองผู้อำนวยการหรือผู้ดูแลระบบเท่านั้นที่พิจารณาขั้นตอนที่ 2 ได้');
            }

            $pdo->prepare("UPDATE bookings SET status = 'pending_director', current_step = 3 WHERE id = :id")
                ->execute([':id' => $bookingId]);

            $updatedStatus = BOOKING_STATUS_PENDING_DIRECTOR;
            $updatedStep = 3;
            $logAction = 'approved';

            $insertLog->execute([
                ':booking_id'        => $bookingId,
                ':step_number'       => 2,
                ':step_role'         => 'vice_director',
                ':approver_id'       => (int)$user['id'],
                ':action'            => $logAction,
                ':comment'           => $comment !== '' ? $comment : null,
                ':signer_name'       => mb_substr($signerName, 0, 150),
                ':signer_position'   => mb_substr($signerPosition, 0, 150),
                ':digital_signature' => $digitalSignature,
            ]);

            $notifyTasks[] = [
                'event'     => 'pending_director',
                'role'      => ROLE_DIRECTOR,
                'user_id'   => null,
                'recipient' => 'director01@slp.local',
                'message'   => "คำขอ {$refCode} ({$bookingTitle}) ผ่านความเห็นชอบจากรองผู้อำนวยการแล้ว รอผู้อำนวยการอนุมัติ",
            ];
            $successMessage = "เห็นชอบคำขอ {$refCode} และเสนอผู้อำนวยการเรียบร้อยแล้ว";
        } elseif ($currentStatus === BOOKING_STATUS_PENDING_DIRECTOR) {
            if (!in_array($role, [ROLE_DIRECTOR, ROLE_ADMIN], true)) {
                $pdo->rollBack();
                $respond(false, 403, 'เฉพาะผู้อำนวยการหรือผู้ดูแลระบบเท่านั้นที่อนุมัติขั้นตอนที่ 3 ได้');
            }

            $pdo->prepare("UPDATE bookings SET status = 'approved', current_step = 3 WHERE id = :id")
                ->execute([':id' => $bookingId]);

            $updatedStatus = BOOKING_STATUS_APPROVED;
            $updatedStep = 3;
            $logAction = 'approved';

            $insertLog->execute([
                ':booking_id'        => $bookingId,
                ':step_number'       => 3,
                ':step_role'         => 'director',
                ':approver_id'       => (int)$user['id'],
                ':action'            => $logAction,
                ':comment'           => $comment !== '' ? $comment : null,
                ':signer_name'       => mb_substr($signerName, 0, 150),
                ':signer_position'   => mb_substr($signerPosition, 0, 150),
                ':digital_signature' => $digitalSignature,
            ]);

            $notifyTasks[] = [
                'event'     => 'approved',
                'role'      => (string)$booking['requester_role'],
                'user_id'   => (int)$booking['requester_id'],
                'recipient' => (string)$booking['requester_email'],
                'message'   => "คำขอจอง {$refCode} ({$bookingTitle}) ได้รับการอนุมัติเรียบร้อยแล้ว",
            ];
            $successMessage = "อนุมัติคำขอ {$refCode} เรียบร้อยแล้ว";
        } else {
            $pdo->rollBack();
            $respond(false, 409, 'สถานะคำขอไม่รองรับการอนุมัติ');
        }
    } elseif ($action === 'return') {
        if ($comment === '') {
            $pdo->rollBack();
            $respond(false, 422, 'กรุณาระบุความเห็นหรือเหตุผลในการส่งกลับแก้ไข');
        }

        if ($currentStatus === BOOKING_STATUS_PENDING_VICE) {
            if (!in_array($role, [ROLE_VICE_DIRECTOR, ROLE_ADMIN], true)) {
                $pdo->rollBack();
                $respond(false, 403, 'คุณไม่มีสิทธิ์ส่งกลับคำขอในขั้นตอนนี้');
            }

            $pdo->prepare("UPDATE bookings SET status = 'pending_head', current_step = 1 WHERE id = :id")
                ->execute([':id' => $bookingId]);

            $updatedStatus = BOOKING_STATUS_PENDING_HEAD;
            $updatedStep = 1;
            $logAction = 'returned';

            $insertLog->execute([
                ':booking_id'        => $bookingId,
                ':step_number'       => 2,
                ':step_role'         => 'vice_director',
                ':approver_id'       => (int)$user['id'],
                ':action'            => $logAction,
                ':comment'           => $comment,
                ':signer_name'       => mb_substr($signerName, 0, 150),
                ':signer_position'   => mb_substr($signerPosition, 0, 150),
                ':digital_signature' => $digitalSignature,
            ]);

            $notifyTasks[] = [
                'event'     => 'returned',
                'role'      => ROLE_HEAD,
                'user_id'   => null,
                'recipient' => 'head01@slp.local',
                'message'   => "คำขอ {$refCode} ({$bookingTitle}) ถูกส่งกลับจากรองผู้อำนวยการ: {$comment}",
            ];
            $successMessage = "ส่งคำขอ {$refCode} กลับไปยังหัวหน้างานเรียบร้อยแล้ว";
        } elseif ($currentStatus === BOOKING_STATUS_PENDING_DIRECTOR) {
            if (!in_array($role, [ROLE_DIRECTOR, ROLE_ADMIN], true)) {
                $pdo->rollBack();
                $respond(false, 403, 'คุณไม่มีสิทธิ์ส่งกลับคำขอในขั้นตอนนี้');
            }

            $pdo->prepare("UPDATE bookings SET status = 'pending_vice', current_step = 2 WHERE id = :id")
                ->execute([':id' => $bookingId]);

            $updatedStatus = BOOKING_STATUS_PENDING_VICE;
            $updatedStep = 2;
            $logAction = 'returned';

            $insertLog->execute([
                ':booking_id'        => $bookingId,
                ':step_number'       => 3,
                ':step_role'         => 'director',
                ':approver_id'       => (int)$user['id'],
                ':action'            => $logAction,
                ':comment'           => $comment,
                ':signer_name'       => mb_substr($signerName, 0, 150),
                ':signer_position'   => mb_substr($signerPosition, 0, 150),
                ':digital_signature' => $digitalSignature,
            ]);

            $notifyTasks[] = [
                'event'     => 'returned',
                'role'      => ROLE_VICE_DIRECTOR,
                'user_id'   => null,
                'recipient' => 'vice01@slp.local',
                'message'   => "คำขอ {$refCode} ({$bookingTitle}) ถูกส่งกลับจากผู้อำนวยการ: {$comment}",
            ];
            $successMessage = "ส่งคำขอ {$refCode} กลับไปยังรองผู้อำนวยการเรียบร้อยแล้ว";
        } else {
            $pdo->rollBack();
            $respond(false, 422, 'ไม่สามารถส่งกลับคำขอในสถานะปัจจุบันได้');
        }
    } elseif ($action === 'reject') {
        if ($comment === '') {
            $pdo->rollBack();
            $respond(false, 422, 'กรุณาระบุเหตุผลที่ไม่อนุมัติ');
        }

        $stepMeta = match ($currentStatus) {
            BOOKING_STATUS_PENDING_HEAD     => ['num' => 1, 'role' => 'head',          'allowed' => [ROLE_HEAD, ROLE_ADMIN]],
            BOOKING_STATUS_PENDING_VICE     => ['num' => 2, 'role' => 'vice_director', 'allowed' => [ROLE_VICE_DIRECTOR, ROLE_ADMIN]],
            BOOKING_STATUS_PENDING_DIRECTOR => ['num' => 3, 'role' => 'director',      'allowed' => [ROLE_DIRECTOR, ROLE_ADMIN]],
            default                         => null,
        };

        if ($stepMeta === null) {
            $pdo->rollBack();
            $respond(false, 409, 'สถานะคำขอไม่รองรับการไม่อนุมัติ');
        }
        if (!in_array($role, $stepMeta['allowed'], true)) {
            $pdo->rollBack();
            $respond(false, 403, 'คุณไม่มีสิทธิ์ไม่อนุมัติคำขอในขั้นตอนนี้');
        }

        $pdo->prepare("UPDATE bookings SET status = 'rejected', rejection_reason = :reason, current_step = :step WHERE id = :id")
            ->execute([
                ':reason' => $comment,
                ':step'   => $stepMeta['num'],
                ':id'     => $bookingId,
            ]);

        $updatedStatus = BOOKING_STATUS_REJECTED;
        $updatedStep = $stepMeta['num'];
        $logAction = 'rejected';

        $insertLog->execute([
            ':booking_id'        => $bookingId,
            ':step_number'       => $stepMeta['num'],
            ':step_role'         => $stepMeta['role'],
            ':approver_id'       => (int)$user['id'],
            ':action'            => $logAction,
            ':comment'           => $comment,
            ':signer_name'       => mb_substr($signerName, 0, 150),
            ':signer_position'   => mb_substr($signerPosition, 0, 150),
            ':digital_signature' => $digitalSignature,
        ]);

        $notifyTasks[] = [
            'event'     => 'rejected',
            'role'      => (string)$booking['requester_role'],
            'user_id'   => (int)$booking['requester_id'],
            'recipient' => (string)$booking['requester_email'],
            'message'   => "คำขอจอง {$refCode} ({$bookingTitle}) ไม่ได้รับอนุมัติ เหตุผล: {$comment}",
        ];
        $successMessage = "บันทึกผลไม่อนุมัติคำขอ {$refCode} เรียบร้อยแล้ว";
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $respond(false, 500, 'เกิดข้อผิดพลาดในการบันทึกข้อมูลการพิจารณา');
}

foreach ($notifyTasks as $nt) {
    log_booking_notification(
        $pdo,
        $bookingId,
        $nt['event'],
        $nt['role'],
        $nt['user_id'],
        $nt['recipient'],
        $nt['message']
    );
}

$respond(true, 200, $successMessage, [
    'booking_id'   => $bookingId,
    'status'       => $updatedStatus,
    'current_step' => $updatedStep,
    'action'       => $logAction,
]);
