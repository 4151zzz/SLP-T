-- ล้างข้อมูลทั้งหมดในฐานข้อมูล ยกเว้นบัญชี 'admin' และ 'chainarong19412@gmail.com'
-- วิธีใช้: เปิด phpMyAdmin -> เลือกฐานข้อมูล saluangac_General -> แท็บ SQL หรือ Import -> กด Go

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

DELETE FROM notification_logs;
DELETE FROM asset_borrowings;
DELETE FROM maintenance_requests;
DELETE FROM assets;
DELETE FROM calendar_events;
DELETE FROM approval_logs;
DELETE FROM bookings;
DELETE FROM drivers;
DELETE FROM vehicles;
DELETE FROM rooms;
DELETE FROM buildings;

ALTER TABLE notification_logs AUTO_INCREMENT = 1;
ALTER TABLE asset_borrowings AUTO_INCREMENT = 1;
ALTER TABLE maintenance_requests AUTO_INCREMENT = 1;
ALTER TABLE assets AUTO_INCREMENT = 1;
ALTER TABLE calendar_events AUTO_INCREMENT = 1;
ALTER TABLE approval_logs AUTO_INCREMENT = 1;
ALTER TABLE bookings AUTO_INCREMENT = 1;
ALTER TABLE drivers AUTO_INCREMENT = 1;
ALTER TABLE vehicles AUTO_INCREMENT = 1;
ALTER TABLE rooms AUTO_INCREMENT = 1;
ALTER TABLE buildings AUTO_INCREMENT = 1;

DELETE FROM users
WHERE LOWER(COALESCE(email, '')) <> 'chainarong19412@gmail.com'
  AND LOWER(COALESCE(username, '')) <> 'admin';

SET FOREIGN_KEY_CHECKS = 1;
