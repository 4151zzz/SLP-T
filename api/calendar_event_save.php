<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/booking_conflict.php';

header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!has_role([ROLE_ADMIN, ROLE_STAFF], $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = null;
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}
$input = $jsonBody ?? $_POST;

// Verify CSRF from JSON body, POST, or X-CSRF-Token header
start_secure_session();
$sessionToken = (string)($_SESSION['_csrf_token'] ?? '');
$submittedCsrf = (string)($input['_csrf_token']
    ?? $input['csrf_token']
    ?? $_POST['_csrf_token']
    ?? $_POST['csrf_token']
    ?? $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? '');

if ($sessionToken === '' || $submittedCsrf === '' || !hash_equals($sessionToken, $submittedCsrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string)($input['action'] ?? 'create')));
$pdo = get_pdo();

if ($action === 'delete') {
    $id = (int)($input['id'] ?? $input['event_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'รหัสกิจกรรมไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmtDel = $pdo->prepare('DELETE FROM calendar_events WHERE id = :id');
    $stmtDel->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'deleted' => $stmtDel->rowCount() > 0], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($action, ['create', 'update'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    exit;
}

$title = trim((string)($input['title'] ?? ''));
$eventType = trim((string)($input['event_type'] ?? 'general'));
$description = trim((string)($input['description'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$startSql = parse_local_datetime(isset($input['start_datetime']) ? (string)$input['start_datetime'] : null);
$endSql = parse_local_datetime(isset($input['end_datetime']) ? (string)$input['end_datetime'] : null);
$isAllDay = !empty($input['is_all_day']) ? 1 : 0;
$isPublic = array_key_exists('is_public', $input) ? ((int)!empty($input['is_public'])) : 1;

if (!in_array($eventType, ['holiday', 'meeting', 'maintenance', 'general'], true)) {
    $eventType = 'general';
}

if ($title === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'กรุณาระบุชื่อกิจกรรม'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($startSql === null || $endSql === null || $endSql <= $startSql) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'วันและเวลาสิ้นสุดต้องมากกว่าวันและเวลาเริ่มต้น'], JSON_UNESCAPED_UNICODE);
    exit;
}

$color = '#7C3AED';

if ($action === 'create') {
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (
            title, event_type, description, location, start_datetime, end_datetime,
            is_all_day, color, is_public, created_by
         ) VALUES (
            :title, :event_type, :description, :location, :start_dt, :end_dt,
            :is_all_day, :color, :is_public, :created_by
         )'
    );
    $stmt->execute([
        ':title'       => mb_substr($title, 0, 200),
        ':event_type'  => $eventType,
        ':description' => $description !== '' ? $description : null,
        ':location'    => $location !== '' ? mb_substr($location, 0, 200) : null,
        ':start_dt'    => $startSql,
        ':end_dt'      => $endSql,
        ':is_all_day'  => $isAllDay,
        ':color'       => $color,
        ':is_public'   => $isPublic,
        ':created_by'  => (int)$user['id'],
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($input['id'] ?? $input['event_id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ไม่พบรหัสกิจกรรมที่ต้องการแก้ไข'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmtUpd = $pdo->prepare(
    'UPDATE calendar_events
     SET title = :title, event_type = :event_type, description = :description, location = :location,
         start_datetime = :start_dt, end_datetime = :end_dt, is_all_day = :is_all_day,
         color = :color, is_public = :is_public
     WHERE id = :id'
);
$stmtUpd->execute([
    ':title'       => mb_substr($title, 0, 200),
    ':event_type'  => $eventType,
    ':description' => $description !== '' ? $description : null,
    ':location'    => $location !== '' ? mb_substr($location, 0, 200) : null,
    ':start_dt'    => $startSql,
    ':end_dt'      => $endSql,
    ':is_all_day'  => $isAllDay,
    ':color'       => $color,
    ':is_public'   => $isPublic,
    ':id'          => $id,
]);

echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
