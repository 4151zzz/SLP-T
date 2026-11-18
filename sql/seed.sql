SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+07:00';

-- Demo password for all seeded accounts: Password123!
-- Hash generated with password_hash('Password123!', PASSWORD_BCRYPT)

-- 1. users (all 7 roles across internal and external user_type)
INSERT INTO users (id, user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active) VALUES
(1, 'internal', 'admin',         'admin',      'ADM001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'ผู้ดูแลระบบ กลาง',        'นักวิชาการคอมพิวเตอร์',   'ฝ่ายบริหารทั่วไป', 'สำนักงานบริหารทั่วไป',         '02-100-0001', 'admin@slp.local',      NULL, 1),
(2, 'internal', 'staff',         'staff01',    'STF001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นายสมชาย ช่างซ่อม',       'เจ้าหน้าที่บริหารงานทั่วไป', 'ฝ่ายบริหารทั่วไป', 'สำนักงานบริหารทั่วไป',         '02-100-0002', 'staff01@slp.local',    NULL, 1),
(3, 'internal', 'head',          'head01',     'HED001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'ฝ่ายบริหารทั่วไป', 'สำนักงานบริหารทั่วไป',         '02-100-0003', 'head01@slp.local',     NULL, 1),
(4, 'internal', 'vice_director', 'vice01',     'VDR001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นายวิชัย รองผู้อำนวยการ',  'รองผู้อำนวยการฝ่ายบริหาร',  'คณะผู้บริหาร',     'สำนักงานบริหารทั่วไป',         '02-100-0004', 'vice01@slp.local',     NULL, 1),
(5, 'internal', 'director',      'director01', 'DIR001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'ดร.สุชาติ ผู้อำนวยการ',     'ผู้อำนวยการสำนักงาน',      'คณะผู้บริหาร',     'สำนักงานบริหารทั่วไป',         '02-100-0005', 'director01@slp.local', NULL, 1),
(6, 'internal', 'user',          'user01',     'EMP001', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นางสาวกมลชนก พนักงาน',    'นักวิชาการพัสดุ',          'ฝ่ายแผนงาน',       'สำนักงานบริหารทั่วไป',         '081-111-0001', 'user01@slp.local',    NULL, 1),
(7, 'internal', 'user',          'user02',     'EMP002', '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นายธนพล เจ้าหน้าที่',      'นักวิเคราะห์นโยบาย',        'ฝ่ายวิชาการ',      'สำนักงานบริหารทั่วไป',         '081-111-0002', 'user02@slp.local',    NULL, 1),
(8, 'external', 'external_user', 'ext01',      NULL,     '$2y$10$jC6nDrzpCrgUsMdf4gClc.GrEJuLZzgOmyKc7LELfSoaHIBi.u9ce', 'นางสาวอรอนงค์ ผู้ขอภายนอก', 'ผู้ประสานงาน',             NULL,               'สมาคมส่งเสริมการศึกษาภายนอก', '089-999-0001', 'ext01@example.org',   NULL, 1);

-- 2. rooms (2 external-allowed, 2 internal-only)
INSERT INTO rooms (id, room_code, name, building, floor, capacity, allow_external, available_equipment, description, is_active) VALUES
(1, 'RM-101', 'ห้องประชุมราชพฤกษ์',  'อาคารอำนวยการ', 'ชั้น 1', 50,  1, JSON_ARRAY('microphone', 'projector', 'audio_system'), 'ห้องประชุมขนาดกลาง เปิดให้หน่วยงานภายนอกขอใช้ได้', 1),
(2, 'RM-AUD', 'หอประชุมใหญ่',         'อาคารเอนกประสงค์', 'ชั้น 1', 200, 1, JSON_ARRAY('microphone', 'projector', 'audio_system'), 'หอประชุมขนาดใหญ่สำหรับจัดอบรมและสัมมนา รองรับบุคคลภายนอก', 1),
(3, 'RM-201', 'ห้องประชุมฝ่ายบริหาร',  'อาคารอำนวยการ', 'ชั้น 2', 20,  0, JSON_ARRAY('microphone', 'projector'),                  'ห้องประชุมภายในสำหรับผู้บริหารและบุคลากร', 1),
(4, 'RM-302', 'ห้องประชุมปฏิบัติการ', 'อาคารอำนวยการ', 'ชั้น 3', 40,  0, JSON_ARRAY('projector', 'audio_system'),               'ห้องประชุมกลุ่มย่อยภายในหน่วยงาน', 1);

-- 3. vehicles
INSERT INTO vehicles (id, vehicle_code, plate_number, vehicle_type, brand_model, seats, status, notes) VALUES
(1, 'VEH-01', 'ฮข-1024 กทม', 'รถตู้',    'Toyota Commuter 2.8',  12, 'available', 'รถตู้โดยสารสำหรับเดินทางเป็นหมู่คณะ'),
(2, 'VEH-02', 'กฉ-4589 กทม', 'รถเก๋ง',   'Toyota Camry 2.5',      4, 'available', 'รถยนต์ส่วนกลางสำหรับปฏิบัติราชการ'),
(3, 'VEH-03', 'บพ-7712 กทม', 'รถกระบะ',  'Isuzu D-Max 4-Door',    4, 'available', 'รถกระบะสำหรับขนส่งพัสดุและงานซ่อมบำรุง');

-- 4. drivers
INSERT INTO drivers (id, driver_code, full_name, phone, license_number, line_user_id, status) VALUES
(1, 'DRV-01', 'นายมานะ ขับรถดี',   '081-200-0001', 'DL-65001122', NULL, 'available'),
(2, 'DRV-02', 'นายประเสริฐ ปลอดภัย', '081-200-0002', 'DL-65003344', NULL, 'available'),
(3, 'DRV-03', 'นายสมพงษ์ ตรงเวลา', '081-200-0003', 'DL-65005566', NULL, 'available');

-- 5. assets (equipment & keys with total_stock >= 2 and available_stock >= 2)
INSERT INTO assets (id, asset_code, name, category, room_id, location, total_stock, available_stock, description, is_active) VALUES
(1, 'AST-KEY-101', 'กุญแจห้องประชุมราชพฤกษ์ (RM-101)', 'key',       1,    'เคาน์เตอร์บริหารทั่วไป ชั้น 1', 2, 2, 'ชุดกุญแจหลักและสำรองห้องประชุมราชพฤกษ์', 1),
(2, 'AST-KEY-AUD', 'กุญแจหอประชุมใหญ่ (RM-AUD)',       'key',       2,    'เคาน์เตอร์บริหารทั่วไป ชั้น 1', 2, 2, 'ชุดกุญแจหอประชุมใหญ่และห้องควบคุมเสียง', 1),
(3, 'AST-MIC-01',  'ไมโครโฟนไร้สายแบบพกพา',            'equipment', NULL, 'ห้องพัสดุกลาง ชั้น 1',          4, 3, 'ไมโครโฟนไร้สาย UHF พร้อมตัวรับสัญญาณ (ถูกยืม 1 ชุด)', 1),
(4, 'AST-PRJ-01',  'เครื่องฉายโปรเจคเตอร์พกพา',        'equipment', NULL, 'ห้องพัสดุกลาง ชั้น 1',          3, 3, 'โปรเจคเตอร์ Full HD พร้อมสาย HDMI', 1),
(5, 'AST-EXT-01',  'ปลั๊กพ่วงสายไฟยาว 10 เมตร',        'equipment', NULL, 'ห้องพัสดุกลาง ชั้น 1',          5, 5, 'ปลั๊กพ่วงมาตรฐาน มอก. สำหรับงานกิจกรรม', 1);

-- 6. bookings (current-month dates days 10..23 so M5 monthly dashboard & CSV always match)
INSERT INTO bookings (id, reference_code, booking_type, requester_id, requester_type, room_id, vehicle_id, driver_id, title, purpose, start_datetime, end_datetime, attendee_count, equipment_requested, permission_letter_path, permission_letter_original_name, destination, passenger_count, current_step, status, rejection_reason) VALUES
(1, 'BK-001', 'room',    6, 'internal', 1,    NULL, NULL, 'ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน',     'ประชุมชี้แจงแผนงานและงบประมาณประจำเดือน',           DATE_FORMAT(CURDATE(), '%Y-%m-10 09:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-10 12:00:00'), 25,   JSON_ARRAY('microphone', 'projector'), NULL, NULL, NULL, NULL, 3, 'approved',         NULL),
(2, 'BK-002', 'room',    8, 'external', 2,    NULL, NULL, 'อบรมเชิงปฏิบัติการเครือข่ายการศึกษา',       'จัดอบรมพัฒนาทักษะบุคลากรทางการศึกษาเครือข่าย',       DATE_FORMAT(CURDATE(), '%Y-%m-12 08:30:00'), DATE_FORMAT(CURDATE(), '%Y-%m-12 16:30:00'), 120,  JSON_ARRAY('microphone', 'projector', 'audio_system'), NULL, 'permission_demo.pdf', NULL, NULL, 3, 'approved', NULL),
(3, 'BK-003', 'vehicle', 6, 'internal', NULL, NULL, NULL, 'เดินทางไปประสานงานเอกสาร ณ ศูนย์ราชการ',      'ส่งเอกสารราชการและประสานงานโครงการ (ขอรถตู้พร้อมคนขับ)', DATE_FORMAT(CURDATE(), '%Y-%m-15 08:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-15 17:00:00'), NULL, NULL, NULL, NULL, 'ศูนย์ราชการเฉลิมพระเกียรติฯ แจ้งวัฒนะ', 6, 1, 'pending_head',     NULL),
(4, 'BK-004', 'room',    7, 'internal', 3,    NULL, NULL, 'ประชุมกลั่นกรองนโยบายและยุทธศาสตร์',        'ทบทวนตัวชี้วัดและแผนยุทธศาสตร์ไตรมาส',               DATE_FORMAT(CURDATE(), '%Y-%m-18 13:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-18 16:00:00'), 15,   JSON_ARRAY('projector'), NULL, NULL, NULL, NULL, 2, 'pending_vice',     NULL),
(5, 'BK-005', 'vehicle', 6, 'internal', NULL, 2,    2,    'เดินทางเข้าร่วมประชุมกระทรวงศึกษาธิการ',      'ร่วมประชุมชี้แจงนโยบายระดับกรม',                     DATE_FORMAT(CURDATE(), '%Y-%m-20 09:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-20 15:30:00'), NULL, NULL, NULL, NULL, 'กระทรวงศึกษาธิการ เขตดุสิต', 3, 3, 'pending_director', NULL),
(6, 'BK-006', 'room',    7, 'internal', 1,    NULL, NULL, 'กิจกรรมซ้อมนำเสนอผลงานนอกเวลา',             'ขอใช้ห้องประชุมในช่วงวันหยุดซ้อนกิจกรรมหลัก',        DATE_FORMAT(CURDATE(), '%Y-%m-22 10:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-22 12:00:00'), 12,   JSON_ARRAY('microphone'), NULL, NULL, NULL, NULL, 1, 'rejected',         'ช่วงเวลาดังกล่าวมีกำหนดบำรุงรักษาระบบไฟฟ้าประจำเดือน');

-- 7. approval_logs
INSERT INTO approval_logs (id, booking_id, step_number, step_role, approver_id, action, comment, signer_name, signer_position, digital_signature, acted_at) VALUES
(1, 1, 1, 'head',          3, 'approved', 'เห็นสมควรอนุญาตให้ใช้ห้องประชุมราชพฤกษ์',              'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', DATE_FORMAT(CURDATE(), '%Y-%m-08 09:30:00')),
(2, 1, 2, 'vice_director', 4, 'approved', 'ตรวจสอบความพร้อมห้องแล้ว เห็นชอบตามเสนอ',             'นายวิชัย รองผู้อำนวยการ',  'รองผู้อำนวยการฝ่ายบริหาร',  'นายวิชัย รองผู้อำนวยการ',  DATE_FORMAT(CURDATE(), '%Y-%m-08 13:15:00')),
(3, 1, 3, 'director',      5, 'approved', 'อนุมัติให้ใช้ห้องประชุมตามกำหนดการ',                  'ดร.สุชาติ ผู้อำนวยการ',     'ผู้อำนวยการสำนักงาน',      'ดร.สุชาติ ผู้อำนวยการ',     DATE_FORMAT(CURDATE(), '%Y-%m-09 10:00:00')),
(4, 2, 1, 'head',          3, 'approved', 'ตรวจหนังสือขอความอนุเคราะห์จากหน่วยงานภายนอกแล้ว',      'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', DATE_FORMAT(CURDATE(), '%Y-%m-09 11:00:00')),
(5, 2, 2, 'vice_director', 4, 'approved', 'เห็นชอบให้หน่วยงานภายนอกเข้าใช้หอประชุมใหญ่',           'นายวิชัย รองผู้อำนวยการ',  'รองผู้อำนวยการฝ่ายบริหาร',  'นายวิชัย รองผู้อำนวยการ',  DATE_FORMAT(CURDATE(), '%Y-%m-09 14:30:00')),
(6, 2, 3, 'director',      5, 'approved', 'อนุมัติการใช้งานหอประชุมใหญ่',                        'ดร.สุชาติ ผู้อำนวยการ',     'ผู้อำนวยการสำนักงาน',      'ดร.สุชาติ ผู้อำนวยการ',     DATE_FORMAT(CURDATE(), '%Y-%m-10 08:45:00')),
(7, 4, 1, 'head',          3, 'approved', 'ห้องว่างพร้อมใช้งาน ส่งต่อรองผู้อำนวยการพิจารณา',       'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', DATE_FORMAT(CURDATE(), '%Y-%m-11 10:20:00')),
(8, 5, 1, 'head',          3, 'approved', 'จัดรถเก๋ง VEH-02 และพนักงานขับรถ DRV-02 เรียบร้อยแล้ว', 'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', DATE_FORMAT(CURDATE(), '%Y-%m-11 11:00:00')),
(9, 5, 2, 'vice_director', 4, 'approved', 'เห็นชอบการเดินทางไปราชการ เสนอผู้อำนวยการอนุมัติ',    'นายวิชัย รองผู้อำนวยการ',  'รองผู้อำนวยการฝ่ายบริหาร',  'นายวิชัย รองผู้อำนวยการ',  DATE_FORMAT(CURDATE(), '%Y-%m-11 15:40:00')),
(10, 6, 1, 'head',         3, 'rejected', 'ช่วงเวลาดังกล่าวมีกำหนดบำรุงรักษาระบบไฟฟ้าประจำเดือน', 'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', DATE_FORMAT(CURDATE(), '%Y-%m-12 09:10:00'));

-- 8. calendar_events
INSERT INTO calendar_events (id, title, event_type, description, location, start_datetime, end_datetime, is_all_day, color, is_public, created_by) VALUES
(1, 'วันพัฒนาองค์กรและทำความสะอาดใหญ่ (5ส.)', 'holiday',     'กิจกรรม Big Cleaning Day ประจำเดือน',             'ทุกอาคารภายในสำนักงาน', DATE_FORMAT(CURDATE(), '%Y-%m-14 08:30:00'), DATE_FORMAT(CURDATE(), '%Y-%m-14 16:30:00'), 1, '#7C3AED', 1, 1),
(2, 'ประชุมผู้บริหารประจำเดือน',               'meeting',     'ประชุมสรุปผลการดำเนินงานฝ่ายบริหารทั่วไป',         'ห้องประชุมฝ่ายบริหาร',  DATE_FORMAT(CURDATE(), '%Y-%m-19 09:30:00'), DATE_FORMAT(CURDATE(), '%Y-%m-19 12:00:00'), 0, '#7C3AED', 1, 2),
(3, 'กำหนดบำรุงรักษาระบบไฟฟ้าประจำเดือน',       'maintenance', 'ตรวจสอบตู้เมนไฟฟ้าและระบบสำรองไฟ',                 'อาคารอำนวยการ ชั้น 1', DATE_FORMAT(CURDATE(), '%Y-%m-22 09:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-22 13:00:00'), 0, '#7C3AED', 1, 2);

-- 9. maintenance_requests (covers all 4 Thai repair statuses + room_id and asset_id)
INSERT INTO maintenance_requests (id, ticket_code, reporter_id, room_id, asset_id, location_text, equipment_name, issue_description, urgency, before_photo_path, after_photo_path, status, assigned_staff_id, repair_notes, reported_at, completed_at) VALUES
(1, 'MT-001', 6, 1,    NULL, 'อาคารอำนวยการ ชั้น 1 — ห้องประชุมราชพฤกษ์', 'เครื่องปรับอากาศฝั่งซ้าย',       'แอร์ไม่เย็น มีเสียงดังผิดปกติขณะเปิดใช้งาน',           'urgent', NULL, NULL, 'รอรับเรื่อง', NULL, NULL, DATE_FORMAT(CURDATE(), '%Y-%m-11 09:15:00'), NULL),
(2, 'MT-002', 7, 2,    NULL, 'อาคารเอนกประสงค์ ชั้น 1 — หอประชุมใหญ่',     'ชุดไมโครโฟนห้องประชุม',           'สัญญาณไมโครโฟนตัวที่ 2 ขาดหายเป็นระยะ',               'normal', NULL, NULL, 'กำลังซ่อม',  2,    'กำลังตรวจเช็กแผงวงจรตัวรับสัญญาณและเปลี่ยนแบตเตอรี่', DATE_FORMAT(CURDATE(), '%Y-%m-10 14:00:00'), NULL),
(3, 'MT-003', 6, NULL, 4,    'ห้องพัสดุกลาง ชั้น 1',                       'เครื่องฉายโปรเจคเตอร์พกพา',       'หลอดภาพแสงหรี่และขึ้นไฟเตือน Temp',                  'normal', NULL, NULL, 'รออะไหล่',   2,    'สั่งซื้อหลอดภาพโปรเจคเตอร์รุ่นทดแทน รอจัดส่ง 3 วัน', DATE_FORMAT(CURDATE(), '%Y-%m-08 11:30:00'), NULL),
(4, 'MT-004', 7, 3,    NULL, 'อาคารอำนวยการ ชั้น 2 — ห้องประชุมฝ่ายบริหาร', 'ระบบไฟส่องสว่างโคมดาวน์ไลท์',     'หลอดไฟ LED เหนือโต๊ะประชุมกะพริบ 2 ดวง',              'normal', NULL, NULL, 'เสร็จสิ้น',   2,    'เปลี่ยนหลอด LED ใหม่จำนวน 2 ดวง ทดสอบเปิดใช้งานปกติแล้ว', DATE_FORMAT(CURDATE(), '%Y-%m-05 10:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-06 15:30:00'));

-- 10. asset_borrowings (1 active borrowed matching AST-MIC-01 available_stock=3, 1 returned)
INSERT INTO asset_borrowings (id, borrow_code, asset_id, borrower_id, issued_by, quantity, purpose, borrowed_at, expected_return_at, returned_at, return_handler_id, status, notes, return_note) VALUES
(1, 'BRW-001', 3, 6, 2, 1, 'ใช้งานประชุมชี้แจงแผนงานฝ่ายพัสดุ',    DATE_FORMAT(CURDATE(), '%Y-%m-10 08:30:00'), DATE_FORMAT(CURDATE(), '%Y-%m-25 17:00:00'), NULL, NULL, 'borrowed', 'รับอุปกรณ์พร้อมถ่านสำรอง 2 ก้อน', NULL),
(2, 'BRW-002', 1, 7, 2, 1, 'เปิดห้องประชุมราชพฤกษ์จัดเตรียมเอกสาร', DATE_FORMAT(CURDATE(), '%Y-%m-04 08:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-04 17:00:00'), DATE_FORMAT(CURDATE(), '%Y-%m-04 16:45:00'), 2, 'returned', 'รับกุญแจชุดที่ 1', 'คืนกุญแจครบถ้วน สภาพปกติ');

-- 11. notification_logs
INSERT INTO notification_logs (id, booking_id, event_name, target_role, target_user_id, channel, recipient, message, delivery_status, error_detail, created_at) VALUES
(1, 1, 'approved',     'user', 6, 'log', 'user01@slp.local', 'คำขอจอง BK-001 ได้รับการอนุมัติเรียบร้อยแล้ว',       'logged', NULL, DATE_FORMAT(CURDATE(), '%Y-%m-09 10:01:00')),
(2, 3, 'pending_head', 'head', 3, 'log', 'head01@slp.local', 'มีคำขอจองยานพาหนะใหม่ BK-003 รอการพิจารณาจากหัวหน้างาน', 'logged', NULL, DATE_FORMAT(CURDATE(), '%Y-%m-12 08:30:00'));
