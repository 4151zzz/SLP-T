-- ล้างข้อมูลทั้งหมดในฐานข้อมูล ยกเว้นบัญชี 'admin' และ 'chainarong19412@gmail.com'
-- วิธีใช้: เปิด phpMyAdmin -> เลือกฐานข้อมูล saluangac_General -> แท็บ SQL -> วางคำสั่งนี้แล้วกด Go

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS buildings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    building_code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    floors_count INT UNSIGNED NOT NULL DEFAULT 1,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_buildings_code (building_code),
    UNIQUE KEY uq_buildings_name (name),
    KEY idx_buildings_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE notification_logs;
TRUNCATE TABLE asset_borrowings;
TRUNCATE TABLE maintenance_requests;
TRUNCATE TABLE assets;
TRUNCATE TABLE calendar_events;
TRUNCATE TABLE approval_logs;
TRUNCATE TABLE bookings;
TRUNCATE TABLE drivers;
TRUNCATE TABLE vehicles;
TRUNCATE TABLE rooms;
TRUNCATE TABLE buildings;

DELETE FROM users
WHERE LOWER(COALESCE(email, '')) <> 'chainarong19412@gmail.com'
  AND LOWER(COALESCE(username, '')) <> 'admin';

SET FOREIGN_KEY_CHECKS = 1;
