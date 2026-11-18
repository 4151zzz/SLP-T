<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = require_login();

$type = trim((string)($_GET['type'] ?? ''));
$id = (int)($_GET['booking_id'] ?? $_GET['id'] ?? 0);

if ($id <= 0 || $type === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('400 Bad Request');
}

$pdo = get_pdo();
$relativePath = null;
$downloadName = null;
$isPrivileged = has_role(PRIVILEGED_VIEW_ROLES, $user);

if ($type === 'permission' || $type === 'permission_letter') {
    $stmt = $pdo->prepare(
        'SELECT requester_id, permission_letter_path, permission_letter_original_name
         FROM bookings
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('404 Not Found');
    }
    if ((int)$row['requester_id'] !== (int)$user['id'] && !$isPrivileged) {
        http_response_code(403);
        exit('403 Forbidden');
    }
    $relativePath = $row['permission_letter_path'];
    $downloadName = $row['permission_letter_original_name'] ?: null;
} elseif ($type === 'maintenance_before' || $type === 'maintenance_after') {
    $stmt = $pdo->prepare(
        'SELECT reporter_id, before_photo_path, after_photo_path
         FROM maintenance_requests
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('404 Not Found');
    }
    if ((int)$row['reporter_id'] !== (int)$user['id'] && !$isPrivileged) {
        http_response_code(403);
        exit('403 Forbidden');
    }
    $relativePath = ($type === 'maintenance_before')
        ? $row['before_photo_path']
        : $row['after_photo_path'];
} else {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('400 Invalid file type');
}

if ($relativePath === null || trim((string)$relativePath) === '') {
    http_response_code(404);
    exit('404 File not attached');
}

$cleanRel = ltrim(str_replace(['\\', "\0"], ['/', ''], (string)$relativePath), '/');
$candidate = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRel);
$realBase = realpath(UPLOAD_ROOT);
$realFile = realpath($candidate);

if ($realBase === false || $realFile === false || !is_file($realFile)) {
    http_response_code(404);
    exit('404 File not found');
}

$basePrefix = $realBase . DIRECTORY_SEPARATOR;
$insideBase = (PHP_OS_FAMILY === 'Windows')
    ? str_starts_with(strtolower($realFile), strtolower($basePrefix))
    : str_starts_with($realFile, $basePrefix);

if (!$insideBase) {
    http_response_code(403);
    exit('403 Forbidden');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = ($finfo !== false) ? finfo_file($finfo, $realFile) : 'application/octet-stream';
if ($finfo !== false) {
    finfo_close($finfo);
}

$allowedServeMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
];
if (!is_string($mime) || !in_array($mime, $allowedServeMimes, true)) {
    http_response_code(403);
    exit('403 Unsupported media type');
}

$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)($downloadName ?: $realFile)));
if ($safeName === '' || $safeName === '.' || $safeName === '..') {
    $safeName = basename($realFile);
}

$disposition = (!empty($_GET['download']) && $_GET['download'] === '1') ? 'attachment' : 'inline';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($realFile));
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($realFile);
exit;
