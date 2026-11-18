<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if (!function_exists('handle_upload')) {
    /**
     * Validate and store an uploaded file safely under uploads/<subdir>/.
     *
     * @param array<string, string> $allowedMimes Map of MIME => file extension
     * @return array{ok:bool, uploaded:bool, path:?string, original_name:?string, mime:?string, size:?int, error:?string}
     */
    function handle_upload(
        string $fieldName,
        string $subdir,
        array $allowedMimes,
        int $maxBytes = UPLOAD_MAX_BYTES,
        bool $required = false
    ): array {
        $emptyResult = static function (bool $ok, ?string $error = null): array {
            return [
                'ok'            => $ok,
                'uploaded'      => false,
                'path'          => null,
                'original_name' => null,
                'mime'          => null,
                'size'          => null,
                'error'         => $error,
            ];
        };

        if (!in_array($subdir, UPLOAD_SUBDIRS, true)) {
            return $emptyResult(false, 'โฟลเดอร์ปลายทางไม่ถูกต้อง');
        }

        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return $required ? $emptyResult(false, 'กรุณาแนบไฟล์') : $emptyResult(true);
        }

        $file = $_FILES[$fieldName];
        $err  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($err === UPLOAD_ERR_NO_FILE) {
            return $required ? $emptyResult(false, 'กรุณาแนบไฟล์') : $emptyResult(true);
        }

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return $emptyResult(false, 'ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด 2MB)');
        }
        if ($err === UPLOAD_ERR_PARTIAL) {
            return $emptyResult(false, 'อัปโหลดไฟล์ไม่สมบูรณ์ กรุณาลองใหม่');
        }
        if ($err === UPLOAD_ERR_NO_TMP_DIR || $err === UPLOAD_ERR_CANT_WRITE || $err === UPLOAD_ERR_EXTENSION) {
            return $emptyResult(false, 'ระบบไม่สามารถบันทึกไฟล์ชั่วคราวได้');
        }
        if ($err !== UPLOAD_ERR_OK) {
            return $emptyResult(false, 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return $emptyResult(false, 'ไฟล์อัปโหลดไม่ถูกต้อง');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $maxMb = round($maxBytes / (1024 * 1024), 1);
            return $emptyResult(false, "ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด {$maxMb}MB)");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return $emptyResult(false, 'ไม่สามารถตรวจสอบชนิดไฟล์ได้');
        }
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);

        if (!is_string($mime) || !isset($allowedMimes[$mime])) {
            return $emptyResult(false, 'ชนิดไฟล์ไม่รองรับ');
        }

        if (str_starts_with($mime, 'image/')) {
            $imgInfo = @getimagesize($tmpName);
            if ($imgInfo === false) {
                return $emptyResult(false, 'ไฟล์รูปภาพไม่ถูกต้องหรือเสียหาย');
            }
        }

        $destDir = UPLOAD_ROOT . DIRECTORY_SEPARATOR . $subdir;
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return $emptyResult(false, 'ไม่สามารถสร้างโฟลเดอร์จัดเก็บไฟล์ได้');
        }

        $ext = $allowedMimes[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destFullPath = $destDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmpName, $destFullPath)) {
            return $emptyResult(false, 'ไม่สามารถย้ายไฟล์ไปยังปลายทางได้');
        }

        $rawName = basename((string)($file['name'] ?? $filename));
        $origName = preg_replace('/[\x00-\x1F\x7F]/', '', $rawName) ?: $filename;

        return [
            'ok'            => true,
            'uploaded'      => true,
            'path'          => 'uploads/' . $subdir . '/' . $filename,
            'original_name' => mb_substr($origName, 0, 255),
            'mime'          => $mime,
            'size'          => $size,
            'error'         => null,
        ];
    }
}

if (!function_exists('upload_maintenance_photo')) {
    function upload_maintenance_photo(string $fieldName = 'photo', bool $required = false): array
    {
        return handle_upload(
            $fieldName,
            'maintenance',
            UPLOAD_ALLOWED_MIMES['maintenance'],
            UPLOAD_MAX_BYTES,
            $required
        );
    }
}

if (!function_exists('upload_permission_letter')) {
    function upload_permission_letter(string $fieldName = 'permission_letter', bool $required = true): array
    {
        return handle_upload(
            $fieldName,
            'permissions',
            UPLOAD_ALLOWED_MIMES['permissions'],
            UPLOAD_MAX_BYTES,
            $required
        );
    }
}

if (!function_exists('delete_uploaded_file')) {
    function delete_uploaded_file(?string $relativePath): void
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return;
        }
        $clean = ltrim(str_replace(['\\', "\0"], ['/', ''], $relativePath), '/');
        $candidate = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
        $realBase = realpath(UPLOAD_ROOT);
        $realFile = realpath($candidate);

        if ($realBase === false || $realFile === false || !is_file($realFile)) {
            return;
        }

        $prefix = $realBase . DIRECTORY_SEPARATOR;
        $inside = (PHP_OS_FAMILY === 'Windows')
            ? str_starts_with(strtolower($realFile), strtolower($prefix))
            : str_starts_with($realFile, $prefix);

        if ($inside) {
            @unlink($realFile);
        }
    }
}
