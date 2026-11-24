SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notification_logs;
DROP TABLE IF EXISTS asset_borrowings;
DROP TABLE IF EXISTS maintenance_requests;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS calendar_events;
DROP TABLE IF EXISTS approval_logs;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS drivers;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS buildings;
DROP TABLE IF EXISTS users;

-- 1. users
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('internal', 'external') NOT NULL DEFAULT 'internal',
    role ENUM('user', 'external_user', 'head', 'vice_director', 'director', 'staff', 'admin') NOT NULL DEFAULT 'user',
    username VARCHAR(100) NOT NULL,
    staff_id VARCHAR(50) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    position_title VARCHAR(150) NULL,
    department VARCHAR(150) NULL,
    organization VARCHAR(200) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NOT NULL,
    line_user_id VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_staff_id (staff_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active),
    KEY idx_users_type (user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. buildings
CREATE TABLE buildings (
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

-- 2. rooms
CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    building VARCHAR(150) NULL,
    floor VARCHAR(30) NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 10,
    allow_external TINYINT(1) NOT NULL DEFAULT 0,
    available_equipment JSON NULL,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_code (room_code),
    KEY idx_rooms_external_active (allow_external, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. vehicles
CREATE TABLE vehicles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_code VARCHAR(30) NOT NULL,
    plate_number VARCHAR(30) NOT NULL,
    vehicle_type VARCHAR(80) NOT NULL,
    brand_model VARCHAR(120) NOT NULL,
    seats INT UNSIGNED NOT NULL DEFAULT 4,
    status ENUM('available', 'maintenance', 'inactive') NOT NULL DEFAULT 'available',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicles_code (vehicle_code),
    UNIQUE KEY uq_vehicles_plate (plate_number),
    KEY idx_vehicles_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. drivers
CREATE TABLE drivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_code VARCHAR(30) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    license_number VARCHAR(50) NULL,
    line_user_id VARCHAR(100) NULL,
    status ENUM('available', 'on_leave', 'inactive') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drivers_code (driver_code),
    KEY idx_drivers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. bookings
CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(30) NOT NULL,
    booking_type ENUM('room', 'vehicle') NOT NULL,
    requester_id INT UNSIGNED NOT NULL,
    requester_type ENUM('internal', 'external') NOT NULL DEFAULT 'internal',
    room_id INT UNSIGNED NULL,
    class_level VARCHAR(50) NULL,
    class_room VARCHAR(80) NULL,
    vehicle_id INT UNSIGNED NULL,
    driver_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    purpose TEXT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    attendee_count INT UNSIGNED NULL,
    equipment_requested JSON NULL,
    permission_letter_path VARCHAR(255) NULL,
    permission_letter_original_name VARCHAR(255) NULL,
    requester_signature VARCHAR(255) NULL,
    destination VARCHAR(255) NULL,
    passenger_count INT UNSIGNED NULL,
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending_head', 'pending_vice', 'pending_director', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_head',
    rejection_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bookings_ref (reference_code),
    KEY idx_bookings_room_conflict (booking_type, room_id, status, start_datetime, end_datetime),
    KEY idx_bookings_vehicle_conflict (booking_type, vehicle_id, status, start_datetime, end_datetime),
    KEY idx_bookings_driver_conflict (driver_id, status, start_datetime, end_datetime),
    KEY idx_bookings_calendar (start_datetime, end_datetime, status),
    KEY idx_bookings_requester (requester_id, status),
    KEY idx_bookings_workflow (status, current_step),
    CONSTRAINT fk_bookings_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. approval_logs
CREATE TABLE approval_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    step_number TINYINT UNSIGNED NOT NULL,
    step_role ENUM('staff', 'head', 'vice_director', 'director') NOT NULL,
    approver_id INT UNSIGNED NOT NULL,
    action ENUM('approved', 'rejected', 'returned', 'assigned_vehicle') NOT NULL,
    comment TEXT NULL,
    signer_name VARCHAR(150) NOT NULL,
    signer_position VARCHAR(150) NULL,
    digital_signature TEXT NULL,
    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_approval_booking_step (booking_id, step_number, acted_at),
    CONSTRAINT fk_approval_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_approval_approver FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. calendar_events
CREATE TABLE calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    event_type ENUM('holiday', 'meeting', 'maintenance', 'general') NOT NULL DEFAULT 'general',
    description TEXT NULL,
    location VARCHAR(200) NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    is_all_day TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(20) NOT NULL DEFAULT '#7C3AED',
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_calendar_range (start_datetime, end_datetime),
    CONSTRAINT fk_calendar_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. assets
CREATE TABLE assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    category ENUM('equipment', 'key', 'av', 'other') NOT NULL DEFAULT 'equipment',
    room_id INT UNSIGNED NULL,
    location VARCHAR(150) NULL,
    total_stock INT UNSIGNED NOT NULL DEFAULT 1,
    available_stock INT UNSIGNED NOT NULL DEFAULT 1,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assets_code (asset_code),
    KEY idx_assets_cat_active (category, is_active),
    CONSTRAINT chk_assets_stock CHECK (available_stock <= total_stock),
    CONSTRAINT fk_assets_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. maintenance_requests
CREATE TABLE maintenance_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_code VARCHAR(30) NOT NULL,
    reporter_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NULL,
    asset_id INT UNSIGNED NULL,
    location_text VARCHAR(200) NOT NULL,
    equipment_name VARCHAR(200) NULL,
    issue_description TEXT NOT NULL,
    urgency ENUM('normal', 'urgent') NOT NULL DEFAULT 'normal',
    before_photo_path VARCHAR(255) NULL,
    after_photo_path VARCHAR(255) NULL,
    status ENUM('รอรับเรื่อง', 'กำลังซ่อม', 'รออะไหล่', 'เสร็จสิ้น') NOT NULL DEFAULT 'รอรับเรื่อง',
    assigned_staff_id INT UNSIGNED NULL,
    repair_notes TEXT NULL,
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maint_ticket (ticket_code),
    KEY idx_maint_status (status, reported_at),
    KEY idx_maint_room (room_id),
    KEY idx_maint_asset (asset_id),
    CONSTRAINT fk_maint_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maint_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. asset_borrowings
CREATE TABLE asset_borrowings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrow_code VARCHAR(30) NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    borrower_id INT UNSIGNED NOT NULL,
    issued_by INT UNSIGNED NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    purpose VARCHAR(255) NULL,
    borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expected_return_at DATETIME NOT NULL,
    returned_at DATETIME NULL,
    return_handler_id INT UNSIGNED NULL,
    status ENUM('borrowed', 'returned', 'overdue') NOT NULL DEFAULT 'borrowed',
    notes TEXT NULL,
    return_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_borrow_code (borrow_code),
    KEY idx_borrow_asset_status (asset_id, status),
    KEY idx_borrow_user (borrower_id, status),
    CONSTRAINT fk_borrow_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_borrow_user FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_borrow_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_borrow_handler FOREIGN KEY (return_handler_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. notification_logs
CREATE TABLE notification_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NULL,
    event_name VARCHAR(60) NOT NULL,
    target_role VARCHAR(40) NULL,
    target_user_id INT UNSIGNED NULL,
    channel ENUM('log', 'line', 'email') NOT NULL DEFAULT 'log',
    recipient VARCHAR(200) NULL,
    message TEXT NOT NULL,
    delivery_status ENUM('logged', 'sent', 'failed') NOT NULL DEFAULT 'logged',
    error_detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_booking (booking_id, created_at),
    CONSTRAINT fk_notif_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. system_settings
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('qr_enabled', '1');

-- 13. saved_memos
CREATE TABLE IF NOT EXISTS saved_memos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NULL,
    reference_code VARCHAR(50) NOT NULL,
    booking_type VARCHAR(20) NOT NULL DEFAULT 'room',
    title VARCHAR(255) NOT NULL,
    requester_name VARCHAR(150) NOT NULL,
    booking_status VARCHAR(40) NOT NULL,
    status_label VARCHAR(100) NOT NULL,
    memo_html LONGTEXT NOT NULL,
    saved_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_saved_memos_user (saved_by, created_at),
    KEY idx_saved_memos_status (booking_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
