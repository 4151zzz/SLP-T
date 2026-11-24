<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$allowedRoles = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
require_role($allowedRoles);

$type = trim((string)($_GET['type'] ?? ''));
if (!in_array($type, ['bookings', 'maintenance'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('400 Bad Request: Invalid export type');
}

$monthRaw = trim((string)($_GET['month'] ?? ''));
$exportAll = ($monthRaw === 'all');
if ($monthRaw === '') {
    $month = date('Y-m');
} elseif ($exportAll) {
    $month = 'all';
} elseif (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthRaw) === 1) {
    $month = $monthRaw;
} else {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('400 Bad Request: Invalid month format');
}

function csv_safe_cell(mixed $val): string
{
    $s = (string)($val ?? '');
    if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s) === 1) {
        return "'" . $s;
    }
    return $s;
}

$pdo = get_pdo();
$rowsToWrite = [];

if ($type === 'bookings') {
    $header = [
        'รหัสอ้างอิง',
        'ประเภทคำขอ',
        'หัวข้อเรื่อง',
        'วัตถุประสงค์',
        'ผู้ขอ',
        'ประเภทผู้ขอ',
        'หน่วยงาน/สังกัด',
        'ห้องประชุม / ปลายทาง',
        'ยานพาหนะ',
        'วันเวลาเริ่มต้น',
        'วันเวลาสิ้นสุด',
        'จำนวนคน',
        'สถานะ',
        'เหตุผลไม่อนุมัติ',
    ];

    $sql = "SELECT b.*,
                u.full_name AS requester_name, u.department AS requester_dept, u.organization AS requester_org,
                r.room_code, r.name AS room_name,
                v.vehicle_code, v.plate_number
         FROM bookings b
         INNER JOIN users u ON u.id = b.requester_id
         LEFT JOIN rooms r ON r.id = b.room_id
         LEFT JOIN vehicles v ON v.id = b.vehicle_id";
    if (!$exportAll) {
        $sql .= " WHERE DATE_FORMAT(b.start_datetime, '%Y-%m') = :month";
    }
    $sql .= " ORDER BY b.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($exportAll ? [] : [':month' => $month]);
    $statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];

    foreach ($stmt->fetchAll() as $b) {
        $isRoom = ($b['booking_type'] === 'room');
        $st = (string)$b['status'];
        $stThai = $statusLabels[$st]['label'] ?? $st;
        $location = $isRoom
            ? trim(($b['room_code'] ? $b['room_code'] . ' ' : '') . ($b['room_name'] ?? ''))
            : (string)($b['destination'] ?? '');
        $vehInfo = !empty($b['vehicle_code'])
            ? $b['vehicle_code'] . ' (' . ($b['plate_number'] ?? '') . ')'
            : '';
        $org = (string)($b['requester_dept'] ?: ($b['requester_org'] ?? ''));
        $count = $isRoom ? (int)($b['attendee_count'] ?? 0) : (int)($b['passenger_count'] ?? 0);

        $rowsToWrite[] = [
            csv_safe_cell($b['reference_code']),
            csv_safe_cell($isRoom ? 'จองห้องประชุม' : 'จองยานพาหนะ'),
            csv_safe_cell($b['title']),
            csv_safe_cell($b['purpose'] ?? ''),
            csv_safe_cell($b['requester_name']),
            csv_safe_cell($b['requester_type'] === 'external' ? 'บุคคลภายนอก' : 'บุคลากรภายใน'),
            csv_safe_cell($org),
            csv_safe_cell($location),
            csv_safe_cell($vehInfo),
            csv_safe_cell($b['start_datetime']),
            csv_safe_cell($b['end_datetime']),
            csv_safe_cell($count),
            csv_safe_cell($stThai . ' (' . $st . ')'),
            csv_safe_cell($b['rejection_reason'] ?? ''),
        ];
    }
} else {
    $header = [
        'เลขที่ใบงาน',
        'สถานที่',
        'อุปกรณ์/ครุภัณฑ์',
        'อาการชำรุด',
        'ความเร่งด่วน',
        'สถานะงานซ่อม',
        'ผู้แจ้งซ่อม',
        'ผู้รับผิดชอบ',
        'หมายเหตุการซ่อม',
        'วันที่แจ้ง',
        'วันที่เสร็จสิ้น',
    ];

    $mSql = "SELECT m.*,
                u.full_name AS reporter_name,
                s.full_name AS staff_name
         FROM maintenance_requests m
         INNER JOIN users u ON u.id = m.reporter_id
         LEFT JOIN users s ON s.id = m.assigned_staff_id";
    if (!$exportAll) {
        $mSql .= " WHERE DATE_FORMAT(m.reported_at, '%Y-%m') = :month";
    }
    $mSql .= " ORDER BY m.id ASC";
    $stmt = $pdo->prepare($mSql);
    $stmt->execute($exportAll ? [] : [':month' => $month]);

    foreach ($stmt->fetchAll() as $m) {
        $rowsToWrite[] = [
            csv_safe_cell($m['ticket_code']),
            csv_safe_cell($m['location_text']),
            csv_safe_cell($m['equipment_name'] ?? ''),
            csv_safe_cell($m['issue_description']),
            csv_safe_cell($m['urgency'] === 'urgent' ? 'เร่งด่วน' : 'ปกติ'),
            csv_safe_cell($m['status']),
            csv_safe_cell($m['reporter_name']),
            csv_safe_cell($m['staff_name'] ?? ''),
            csv_safe_cell($m['repair_notes'] ?? ''),
            csv_safe_cell($m['reported_at']),
            csv_safe_cell($m['completed_at'] ?? ''),
        ];
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = "slp_{$type}_{$month}.csv";
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');
if ($out !== false) {
    fputcsv($out, $header, ',', '"', '');
    foreach ($rowsToWrite as $row) {
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
}
exit;
