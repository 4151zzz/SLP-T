<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$allowedRoles = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
require_role($allowedRoles);

header('Content-Type: application/json; charset=UTF-8');

$monthRaw = trim((string)($_GET['month'] ?? ''));
if ($monthRaw === '') {
    $month = date('Y-m');
} elseif (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthRaw) === 1) {
    $month = $monthRaw;
} else {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'Invalid month format (expected YYYY-MM)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_pdo();

// 1. Monthly room usage split internal vs external (excluding rejected)
$stmtRoomType = $pdo->prepare(
    "SELECT requester_type, COUNT(*) AS cnt
     FROM bookings
     WHERE booking_type = 'room'
       AND status != 'rejected'
       AND DATE_FORMAT(start_datetime, '%Y-%m') = :month
     GROUP BY requester_type"
);
$stmtRoomType->execute([':month' => $month]);
$roomInternal = 0;
$roomExternal = 0;
foreach ($stmtRoomType->fetchAll() as $row) {
    if ($row['requester_type'] === USER_TYPE_INTERNAL) {
        $roomInternal = (int)$row['cnt'];
    } elseif ($row['requester_type'] === USER_TYPE_EXTERNAL) {
        $roomExternal = (int)$row['cnt'];
    }
}

$stmtByRoom = $pdo->prepare(
    "SELECT r.room_code, r.name AS room_name,
            SUM(CASE WHEN b.booking_type = 'room' AND b.status != 'rejected' AND DATE_FORMAT(b.start_datetime, '%Y-%m') = :m1 AND b.requester_type = 'internal' THEN 1 ELSE 0 END) AS int_cnt,
            SUM(CASE WHEN b.booking_type = 'room' AND b.status != 'rejected' AND DATE_FORMAT(b.start_datetime, '%Y-%m') = :m2 AND b.requester_type = 'external' THEN 1 ELSE 0 END) AS ext_cnt
     FROM rooms r
     LEFT JOIN bookings b ON b.room_id = r.id
     WHERE r.is_active = 1
     GROUP BY r.id, r.room_code, r.name
     ORDER BY r.room_code ASC"
);
$stmtByRoom->execute([':m1' => $month, ':m2' => $month]);
$byRoomLabels = [];
$byRoomInternal = [];
$byRoomExternal = [];
foreach ($stmtByRoom->fetchAll() as $rm) {
    $byRoomLabels[] = $rm['room_code'] . ' ' . $rm['room_name'];
    $byRoomInternal[] = (int)$rm['int_cnt'];
    $byRoomExternal[] = (int)$rm['ext_cnt'];
}

// 2. Monthly vehicle bookings (excluding rejected)
$stmtVehTotal = $pdo->prepare(
    "SELECT COUNT(*)
     FROM bookings
     WHERE booking_type = 'vehicle'
       AND status != 'rejected'
       AND DATE_FORMAT(start_datetime, '%Y-%m') = :month"
);
$stmtVehTotal->execute([':month' => $month]);
$vehicleTotal = (int)$stmtVehTotal->fetchColumn();

$stmtByVeh = $pdo->prepare(
    "SELECT v.vehicle_code, v.plate_number,
            COUNT(b.id) AS cnt
     FROM vehicles v
     LEFT JOIN bookings b
       ON b.vehicle_id = v.id
      AND b.booking_type = 'vehicle'
      AND b.status != 'rejected'
      AND DATE_FORMAT(b.start_datetime, '%Y-%m') = :month
     GROUP BY v.id, v.vehicle_code, v.plate_number
     ORDER BY v.vehicle_code ASC"
);
$stmtByVeh->execute([':month' => $month]);
$vehLabels = [];
$vehCounts = [];
foreach ($stmtByVeh->fetchAll() as $vr) {
    $vehLabels[] = $vr['vehicle_code'] . ' (' . $vr['plate_number'] . ')';
    $vehCounts[] = (int)$vr['cnt'];
}

$stmtUnassignedVeh = $pdo->prepare(
    "SELECT COUNT(*)
     FROM bookings
     WHERE booking_type = 'vehicle'
       AND vehicle_id IS NULL
       AND status != 'rejected'
       AND DATE_FORMAT(start_datetime, '%Y-%m') = :month"
);
$stmtUnassignedVeh->execute([':month' => $month]);
$unassignedVehCnt = (int)$stmtUnassignedVeh->fetchColumn();
$vehLabels[] = 'รอจัดสรรรถ';
$vehCounts[] = $unassignedVehCnt;

// 3. Monthly approved bookings
$stmtApproved = $pdo->prepare(
    "SELECT COUNT(*)
     FROM bookings
     WHERE status = 'approved'
       AND DATE_FORMAT(start_datetime, '%Y-%m') = :month"
);
$stmtApproved->execute([':month' => $month]);
$bookingsApproved = (int)$stmtApproved->fetchColumn();

// 4. Monthly maintenance requests grouped by exact 4 Thai ENUM statuses
$maintCounts = [
    REPAIR_STATUS_WAITING     => 0,
    REPAIR_STATUS_IN_PROGRESS => 0,
    REPAIR_STATUS_WAIT_PARTS  => 0,
    REPAIR_STATUS_DONE        => 0,
];
$stmtMaint = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt
     FROM maintenance_requests
     WHERE DATE_FORMAT(reported_at, '%Y-%m') = :month
     GROUP BY status"
);
$stmtMaint->execute([':month' => $month]);
foreach ($stmtMaint->fetchAll() as $mr) {
    $st = (string)$mr['status'];
    if (array_key_exists($st, $maintCounts)) {
        $maintCounts[$st] = (int)$mr['cnt'];
    }
}
$maintTotal = array_sum($maintCounts);

// 5. Current global pending approval backlog (all paths contain "pending" for T2-F15-02 empty month check)
$pendingCounts = [
    BOOKING_STATUS_PENDING_HEAD     => 0,
    BOOKING_STATUS_PENDING_VICE     => 0,
    BOOKING_STATUS_PENDING_DIRECTOR => 0,
];
$stmtPending = $pdo->query(
    "SELECT status, COUNT(*) AS cnt
     FROM bookings
     WHERE status IN ('pending_head', 'pending_vice', 'pending_director')
     GROUP BY status"
);
foreach ($stmtPending->fetchAll() as $pr) {
    $pst = (string)$pr['status'];
    if (array_key_exists($pst, $pendingCounts)) {
        $pendingCounts[$pst] = (int)$pr['cnt'];
    }
}
$pendingTotal = array_sum($pendingCounts);

$bookingsActive = $roomInternal + $roomExternal + $vehicleTotal;

echo json_encode([
    'ok'    => true,
    'month' => $month,
    'kpi'   => [
        'bookings_active'   => $bookingsActive,
        'bookings_approved' => $bookingsApproved,
        'room_internal'     => $roomInternal,
        'room_external'     => $roomExternal,
        'vehicle_trips'     => $vehicleTotal,
        'maintenance_total' => $maintTotal,
        'pending_approvals' => $pendingTotal,
    ],
    'room_usage' => [
        'labels'   => ['บุคลากรภายใน', 'บุคคลภายนอก'],
        'data'     => [$roomInternal, $roomExternal],
        'by_room'  => [
            'labels'   => $byRoomLabels,
            'internal' => $byRoomInternal,
            'external' => $byRoomExternal,
        ],
    ],
    'vehicle_usage' => [
        'labels' => $vehLabels,
        'data'   => $vehCounts,
        'total'  => $vehicleTotal,
    ],
    'maintenance_status' => [
        'labels' => [
            REPAIR_STATUS_WAITING,
            REPAIR_STATUS_IN_PROGRESS,
            REPAIR_STATUS_WAIT_PARTS,
            REPAIR_STATUS_DONE,
        ],
        'data' => [
            $maintCounts[REPAIR_STATUS_WAITING],
            $maintCounts[REPAIR_STATUS_IN_PROGRESS],
            $maintCounts[REPAIR_STATUS_WAIT_PARTS],
            $maintCounts[REPAIR_STATUS_DONE],
        ],
    ],
    'pending_queue' => [
        'labels' => ['รอหัวหน้างาน', 'รอรองผู้อำนวยการ', 'รอผู้อำนวยการ'],
        'data'   => [
            $pendingCounts[BOOKING_STATUS_PENDING_HEAD],
            $pendingCounts[BOOKING_STATUS_PENDING_VICE],
            $pendingCounts[BOOKING_STATUS_PENDING_DIRECTOR],
        ],
        'total' => $pendingTotal,
    ],
], JSON_UNESCAPED_UNICODE);
