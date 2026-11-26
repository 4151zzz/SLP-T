<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/booking_conflict.php';

header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
$isGuest = ($user === null);
$isExternal = ($user !== null) && is_external($user);
$isPrivileged = ($user !== null) && has_role(PRIVILEGED_VIEW_ROLES, $user);
$currentUserId = $user !== null ? (int)$user['id'] : 0;

$rawStart = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$rawEnd = isset($_GET['end']) ? trim((string)$_GET['end']) : '';

if ($rawStart !== '') {
    $startSql = parse_local_datetime($rawStart);
    if ($startSql === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid start date parameter'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $startSql = date('Y-m-01 00:00:00');
}

if ($rawEnd !== '') {
    $endSql = parse_local_datetime($rawEnd);
    if ($endSql === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid end date parameter'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $endSql = date('Y-m-d 00:00:00', strtotime('+1 month', strtotime(date('Y-m-01'))));
}

if ($startSql >= $endSql) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

$filterType = trim((string)($_GET['type'] ?? 'all'));
$pdo = get_pdo();
$events = [];
$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];

if (in_array($filterType, ['all', 'room', 'vehicle', 'booking'], true)) {
    $bookingTypeWhere = '';
    if ($filterType === 'room') {
        $bookingTypeWhere = " AND b.booking_type = 'room'";
    } elseif ($filterType === 'vehicle') {
        $bookingTypeWhere = " AND b.booking_type = 'vehicle'";
    }

    $sqlBookings = "SELECT b.id, b.reference_code, b.booking_type, b.requester_id, b.requester_type,
                           b.room_id, b.vehicle_id, b.driver_id, b.title, b.purpose,
                           b.start_datetime, b.end_datetime, b.attendee_count, b.destination,
                           b.passenger_count, b.status,
                           u.full_name AS requester_name, u.phone AS requester_phone,
                           r.name AS room_name, r.room_code,
                           v.brand_model AS vehicle_name, v.plate_number,
                           d.full_name AS driver_name
                    FROM bookings b
                    INNER JOIN users u ON u.id = b.requester_id
                    LEFT JOIN rooms r ON r.id = b.room_id
                    LEFT JOIN vehicles v ON v.id = b.vehicle_id
                    LEFT JOIN drivers d ON d.id = b.driver_id
                    WHERE b.status NOT IN ('rejected', 'cancelled')
                      AND b.start_datetime < :end_dt
                      AND b.end_datetime > :start_dt
                      {$bookingTypeWhere}
                    ORDER BY b.start_datetime ASC";

    $stmtBookings = $pdo->prepare($sqlBookings);
    $stmtBookings->execute([
        ':end_dt'   => $endSql,
        ':start_dt' => $startSql,
    ]);

    foreach ($stmtBookings->fetchAll() as $row) {
        $isRoom = ($row['booking_type'] === 'room');
        $color = $isRoom ? '#2563EB' : '#059669';
        $isOwner = ($currentUserId > 0 && (int)$row['requester_id'] === $currentUserId);
        $canViewPii = $isPrivileged || $isOwner;
        $isMasked = !$canViewPii;

        $resourceName = $isRoom
            ? ($row['room_name'] ?: 'ห้องประชุม')
            : ($row['vehicle_name']
                ? ($row['vehicle_name'] . (!empty($row['plate_number']) ? ' (' . $row['plate_number'] . ')' : ''))
                : 'ยานพาหนะราชการ');

        $statusCode = (string)$row['status'];
        $statusMeta = $statusLabels[$statusCode] ?? ['label' => $statusCode, 'badge' => 'secondary'];

        $displayTitle = $canViewPii
            ? (string)$row['title']
            : ('ไม่ว่าง: ' . $resourceName);

        $events[] = [
            'id'              => 'booking-' . (int)$row['id'],
            'title'           => $displayTitle,
            'start'           => str_replace(' ', 'T', (string)$row['start_datetime']),
            'end'             => str_replace(' ', 'T', (string)$row['end_datetime']),
            'allDay'          => false,
            'color'           => $color,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'textColor'       => '#FFFFFF',
            'extendedProps'   => [
                'source_type'       => 'booking',
                'booking_id'        => (int)$row['id'],
                'reference_code'    => (string)$row['reference_code'],
                'booking_type'      => (string)$row['booking_type'],
                'room_id'           => $row['room_id'] !== null ? (int)$row['room_id'] : null,
                'vehicle_id'        => $row['vehicle_id'] !== null ? (int)$row['vehicle_id'] : null,
                'resource_name'     => $resourceName,
                'status'            => $statusCode,
                'status_label'      => (string)$statusMeta['label'],
                'color'             => $color,
                'is_masked'         => $isMasked,
                'requester_name'    => $canViewPii ? (string)$row['requester_name'] : 'ผู้ใช้งานระบบ (สงวนสิทธิ์)',
                'requester_contact' => $canViewPii ? ($row['requester_phone'] ?: '-') : null,
                'purpose'           => $canViewPii ? ($row['purpose'] ?: '-') : null,
                'destination'       => $canViewPii ? ($row['destination'] ?: null) : null,
                'attendee_count'    => $canViewPii && $row['attendee_count'] !== null ? (int)$row['attendee_count'] : null,
                'passenger_count'   => $canViewPii && $row['passenger_count'] !== null ? (int)$row['passenger_count'] : null,
                'driver_name'       => $canViewPii ? ($row['driver_name'] ?: null) : null,
            ],
        ];
    }
}

if (in_array($filterType, ['all', 'event'], true)) {
    $publicClause = ($isGuest || $isExternal) ? ' AND ce.is_public = 1' : '';
    $sqlEvents = "SELECT ce.id, ce.title, ce.event_type, ce.description, ce.location,
                         ce.start_datetime, ce.end_datetime, ce.is_all_day, ce.color, ce.is_public,
                         u.full_name AS creator_name
                  FROM calendar_events ce
                  INNER JOIN users u ON u.id = ce.created_by
                  WHERE ce.start_datetime < :end_dt
                    AND ce.end_datetime > :start_dt
                    {$publicClause}
                  ORDER BY ce.start_datetime ASC";

    $stmtEvents = $pdo->prepare($sqlEvents);
    $stmtEvents->execute([
        ':end_dt'   => $endSql,
        ':start_dt' => $startSql,
    ]);

    foreach ($stmtEvents->fetchAll() as $ev) {
        $isAllDay = ((int)$ev['is_all_day'] === 1);
        $color = '#7C3AED';
        $events[] = [
            'id'              => 'event-' . (int)$ev['id'],
            'title'           => (string)$ev['title'],
            'start'           => $isAllDay
                ? substr((string)$ev['start_datetime'], 0, 10)
                : str_replace(' ', 'T', (string)$ev['start_datetime']),
            'end'             => $isAllDay
                ? substr((string)$ev['end_datetime'], 0, 10)
                : str_replace(' ', 'T', (string)$ev['end_datetime']),
            'allDay'          => $isAllDay,
            'color'           => $color,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'textColor'       => '#FFFFFF',
            'extendedProps'   => [
                'source_type'  => 'calendar_event',
                'event_id'     => (int)$ev['id'],
                'event_type'   => (string)$ev['event_type'],
                'description'  => $ev['description'] ?: '-',
                'location'     => $ev['location'] ?: '-',
                'is_all_day'   => $isAllDay ? 1 : 0,
                'is_public'    => (int)$ev['is_public'],
                'creator_name' => (string)$ev['creator_name'],
                'start_raw'    => str_replace(' ', 'T', substr((string)$ev['start_datetime'], 0, 16)),
                'end_raw'      => str_replace(' ', 'T', substr((string)$ev['end_datetime'], 0, 16)),
                'color'        => $color,
                'is_masked'    => false,
            ],
        ];
    }
}

echo json_encode($events, JSON_UNESCAPED_UNICODE);
