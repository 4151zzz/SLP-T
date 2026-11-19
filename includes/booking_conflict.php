<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';

if (!function_exists('parse_local_datetime')) {
    /**
     * Parse strict local or ISO8601 datetime string into canonical 'Y-m-d H:i:s' (Asia/Bangkok).
     */
    function parse_local_datetime(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $val = trim($raw);
        if ($val === '') {
            return null;
        }

        $tz = new DateTimeZone('Asia/Bangkok');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m) === 1) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return null;
            }
            return sprintf('%04d-%02d-%02d 00:00:00', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $val, $m) === 1) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            $h = (int)$m[4];
            $mi = (int)$m[5];
            $s = isset($m[6]) ? (int)$m[6] : 0;
            if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
                return null;
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $val) === 1) {
            try {
                $dt = new DateTimeImmutable($val);
                return $dt->setTimezone($tz)->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}

if (!function_exists('find_room_conflict')) {
    /**
     * Detect half-open [start, end) overlap on non-rejected room bookings.
     */
    function find_room_conflict(
        PDO $pdo,
        int $roomId,
        string $startSql,
        string $endSql,
        ?int $excludeBookingId = null
    ): ?array {
        $sql = "SELECT id, reference_code, title, start_datetime, end_datetime, status
                FROM bookings
                WHERE booking_type = 'room'
                  AND room_id = :room_id
                  AND status != 'rejected'
                  AND start_datetime < :end_dt
                  AND end_datetime > :start_dt";
        $params = [
            ':room_id'  => $roomId,
            ':end_dt'   => $endSql,
            ':start_dt' => $startSql,
        ];
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeBookingId;
        }
        $sql .= ' ORDER BY start_datetime ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('find_vehicle_conflict')) {
    /**
     * Detect half-open [start, end) overlap on non-rejected vehicle bookings.
     */
    function find_vehicle_conflict(
        PDO $pdo,
        int $vehicleId,
        string $startSql,
        string $endSql,
        ?int $excludeBookingId = null
    ): ?array {
        $sql = "SELECT id, reference_code, title, start_datetime, end_datetime, status
                FROM bookings
                WHERE booking_type = 'vehicle'
                  AND vehicle_id = :vehicle_id
                  AND status != 'rejected'
                  AND start_datetime < :end_dt
                  AND end_datetime > :start_dt";
        $params = [
            ':vehicle_id' => $vehicleId,
            ':end_dt'     => $endSql,
            ':start_dt'   => $startSql,
        ];
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeBookingId;
        }
        $sql .= ' ORDER BY start_datetime ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('find_driver_conflict')) {
    /**
     * Detect half-open [start, end) overlap on non-rejected driver assignments.
     */
    function find_driver_conflict(
        PDO $pdo,
        int $driverId,
        string $startSql,
        string $endSql,
        ?int $excludeBookingId = null
    ): ?array {
        $sql = "SELECT id, reference_code, title, start_datetime, end_datetime, status
                FROM bookings
                WHERE booking_type = 'vehicle'
                  AND driver_id = :driver_id
                  AND status != 'rejected'
                  AND start_datetime < :end_dt
                  AND end_datetime > :start_dt";
        $params = [
            ':driver_id' => $driverId,
            ':end_dt'    => $endSql,
            ':start_dt'  => $startSql,
        ];
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeBookingId;
        }
        $sql .= ' ORDER BY start_datetime ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('generate_booking_reference')) {
    function generate_booking_reference(PDO $pdo): string
    {
        $prefix = 'BK-' . date('Ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $suffix = strtoupper(bin2hex(random_bytes(2)));
            $candidate = $prefix . $suffix;
            $stmt = $pdo->prepare('SELECT id FROM bookings WHERE reference_code = :ref LIMIT 1');
            $stmt->execute([':ref' => $candidate]);
            if (!$stmt->fetch()) {
                return $candidate;
            }
        }
        return $prefix . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('log_booking_notification')) {
    function log_booking_notification(
        PDO $pdo,
        ?int $bookingId,
        string $eventName,
        ?string $targetRole,
        ?int $targetUserId,
        string $recipient,
        string $message
    ): void {
        $channel = in_array(NOTIFY_DRIVER, ['log', 'line', 'email'], true) ? NOTIFY_DRIVER : 'log';
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO notification_logs (
                    booking_id, event_name, target_role, target_user_id,
                    channel, recipient, message, delivery_status, error_detail
                ) VALUES (
                    :booking_id, :event_name, :target_role, :target_user_id,
                    :channel, :recipient, :message, :delivery_status, NULL
                )'
            );
            $stmt->execute([
                ':booking_id'      => $bookingId,
                ':event_name'      => mb_substr($eventName, 0, 60),
                ':target_role'     => $targetRole !== null ? mb_substr($targetRole, 0, 40) : null,
                ':target_user_id'  => $targetUserId,
                ':channel'         => $channel,
                ':recipient'       => mb_substr($recipient, 0, 200),
                ':message'         => $message,
                ':delivery_status' => 'logged',
            ]);
        } catch (Throwable $e) {
            // Do not break booking workflow if notification DB log fails
        }

        try {
            if (!is_dir(LOG_DIR)) {
                @mkdir(LOG_DIR, 0755, true);
            }
            $logLine = json_encode([
                'timestamp'      => date('c'),
                'booking_id'     => $bookingId,
                'event_name'     => $eventName,
                'target_role'    => $targetRole,
                'target_user_id' => $targetUserId,
                'channel'        => $channel,
                'recipient'      => $recipient,
                'message'        => $message,
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            @file_put_contents(LOG_DIR . DIRECTORY_SEPARATOR . 'notifications.log', $logLine, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Ignore filesystem log failure
        }
    }
}
