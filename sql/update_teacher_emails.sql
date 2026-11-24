-- Update/upsert 108 teachers with emails from PDF
SET NAMES utf8mb4;

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T001', 'T001', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางนิกูล ทองหน้าศาล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't001@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '44', '44', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวรัชนี ชูเมือง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, '44@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T003', 'T003', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชิณ สละชุ่ม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't003@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T004', 'T004', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายประพัฒน์ ทัพซ้าย', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't004@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T005', 'T005', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายปรีดา เหลือบแล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't005@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '62', '62', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายบุญรอด ชูเมือง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'bchchu@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '47', '47', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางวิลาสินี อำพันพงษ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Wilasinee@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '49', '49', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวพรเพชร สิงห์สังข์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'phetch_phetch@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T009', 'T009', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายบูรณากร จันทรวรชาต', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'buranak.56789@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '8', '8', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสุพรรณี บุญเปี่ยม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'suesree1972@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '7', '7', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวจรรยาลักษณ์ เทียมศร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, '7@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T012', 'T012', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวณัฐนิชา วิเชียรสรรค์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'natnicha.wcs@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T013', 'T013', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายสิริพรชัย ทับจีนธัญนพ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'natza008@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T014', 'T014', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวนันทยา ถาวรศักดิ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'koirasak@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T015', 'T015', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวชณัฐา พงษ์พิทักษ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chanathapongpitak@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T016', 'T016', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกฤติกา กระจิวผา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't016@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T017', 'T017', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอมรรัตน์ คุ้มแสง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'amonrut.ko@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T018', 'T018', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวพิชญาภา แจ้งสว่าง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'krukung8927@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T019', 'T019', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกานต์ธีรา เจ็กมา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Kj.lookmai@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T020', 'T020', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวนิศารัตน์ ชมเทศ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nisarat.c@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T021', 'T021', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวชลทิชา ยศสอน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'oayyaa1312@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T022', 'T022', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวพิชชาพร เมธีรัตนาพิพัฒน์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'petalstory90@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '59', '59', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอัมพร สารวัตร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'sarawat363@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T024', 'T024', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสุกัญญา กุสุโมทย์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'sukanya.kusumot28@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T025', 'T025', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวเพชร โตเมศร์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'phetthomat@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T026', 'T026', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชนาธิป ศรีสวัสดิ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chanathip.s@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T027', 'T027', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวณัฐวดี กุตนันท์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nattavadeeka864@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T028', 'T028', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวนันทวัน ดิสสงค์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nantawan.d@psru.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T029', 'T029', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายภาณุวัฒน์ กุสุโมทย์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kusumotpanuwat@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T030', 'T030', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสุวรรณี ช่วยสุริยา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'aolovejaw@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T031', 'T031', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายอนุสิษฐ์ แก้วศรีทอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't031@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T032', 'T032', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายประธาน พะลาด', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, '03prathan@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T033', 'T033', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายเติมพงศ์ ครุธทิน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'termpongk@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T034', 'T034', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชัยสิทธิ์ แสงเย็น', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'jumchicchic@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T035', 'T035', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอาทิตตญา ศรีสวัสดิ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'first.aty@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T036', 'T036', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชัชวาลย์ กันเพชร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chatchawan@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T037', 'T037', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอารีย์ สิบหยอม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nimaree2807@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T038', 'T038', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวพรพรรษา เชื้อวีระชน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'pornpansa_nui@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '80', '80', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางเครือมาส คำเขียน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kruamas2516@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T040', 'T040', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางนพรัตน์ พันธ์กสิกร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nopyt98@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T041', 'T041', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวรัตนา พรายเพริด', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'tungtung.rattana@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T042', 'T042', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายรพีพัฒน์ ชูเมือง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't042@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T043', 'T043', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายนิติกร นาคไพจิตร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Nitikornnakpaijit@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T044', 'T044', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายจงกล อุ้ยตา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Jongkolkab@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T045', 'T045', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวเอมนิการ์ น้อยจั่น', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'emnikar18@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T046', 'T046', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวศุภานัน ถาวรกูล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'supananthawornkul@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T047', 'T047', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอินทิรา ใสเขียนวงค์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't047@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T048', 'T048', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกัญชริญา เดชาศตานันท์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'dechasatanan@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T049', 'T049', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวจิตรลดา สมสาย', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chitlada1992@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T050', 'T050', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชยุตพันธุ์ แจ่มโภคนิษฐ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chayutphan58@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T051', 'T051', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวลำใย ทองใบ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'lamtong45yai@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '77', '77', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางแสงอรุณ เปี่ยมงาม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nongslp15@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T053', 'T053', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางศิริพร เมืองทอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'meen2541@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T054', 'T054', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายสมภพ วัดพาน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Sompop7530@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T055', 'T055', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวภัสราภรณ์ สิงห์ลอ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'passaraporn0597@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T056', 'T056', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวปรียานุช มีจาด', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'preeyanuch.2728@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T057', 'T057', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสุจิรา สงสุวรรณ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'sujira.songsuwan2529@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T058', 'T058', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวนริศรา ปาลี', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'primnaritsara8143322@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T059', 'T059', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายธวัช ให้พร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'thawath2529@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T060', 'T060', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางวัชรี สุขสวัสดิ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kruead29@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T061', 'T061', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวนารีรัตน์ วรศิลป์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kainareerat1980@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T062', 'T062', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวละออง คิมอิ๋ง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'laong@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '38', '38', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายชัยณรงค์ ฉายศรี', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chainarong19412@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '46', '46', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางวิไล ปานแสง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'wilai0308pansang@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '81', '81', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวปริณดา วิเศษผล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'parinada.w@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T066', 'T066', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางละมัย ทุ้ยแป', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 't066@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T067', 'T067', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอัญชลี ม่วงแจ่ม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'ununeded042@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T068', 'T068', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสุกัญญา หนูทอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'sukanya.noothong@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T069', 'T069', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวศิกัญญา สุกสอน', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Sikanya5989@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T070', 'T070', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวจิราภรณ์ พลดงนอก', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'jirapornkwang2@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T071', 'T071', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวฉัตรสุดา ขุนเพ็ง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kp.chatsuda@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T072', 'T072', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอัจฉรา สำเภาทอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'autchara3400@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T073', 'T073', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายกฤษณพงศ์ ทองคำ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kritsanaphong@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T074', 'T074', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายมนสิช คงยุทธ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kmonsit39@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T075', 'T075', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอัจฉริยา เที่ยงอยู่', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'krujubjang333@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T076', 'T076', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายไพบูลย์ พละกสิกร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chinnosuke3030@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T077', 'T077', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางนิรันตรี อาจเอื้อ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'niruntree007@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T078', 'T078', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายวิศิษฏ์ สิริปิยานนท์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'wisit.sir@obec.moe.go.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T079', 'T079', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายพินิจ ปีขาล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'krumos@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T080', 'T080', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวจตุพร เที่ยงอยู่', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Jazz6555@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T081', 'T081', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'ว่าที่ ร.ต.หญิงศุภานัน อยู่ทิม', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'supanan.yoo2079@ndlp.go.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T082', 'T082', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางเรณู ตรีศิริ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kunraymon@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T083', 'T083', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายสาธิต แก้วอ่ำ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Krotomsatit@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T084', 'T084', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวศศิวิมล พูลคล้าย', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'sasiseonsaeng@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T085', 'T085', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายณัฐวุฒิ พาลิตา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'nathawootpalita@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T086', 'T086', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายลัทธพล โตชนก', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'lattaphon.to@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T087', 'T087', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายธีรภัทร์ จำเนียร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'therapat2535@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '54', '54', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางอรกัลยา จันทรสมบัติ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, '54@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T089', 'T089', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางกาญจนา ศรีฉ่ำ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'bnmaa2469@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T090', 'T090', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกมลวรรณ สุวรรณา', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'bow.suwanna@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T091', 'T091', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสุนิสา สิฏฐกุล', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Account_nu@hotmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '76', '76', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางกนกวรรณ บุญเสรฐ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'catkanokwanza01@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T093', 'T093', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสวรรยา เต็งสุวรรณ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Wlanthanum@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T094', 'T094', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายภานุเดช พรมเพชร', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'panudet353@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T095', 'T095', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายฉัตรชัย ศรีทอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'fthajllove1@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T096', 'T096', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวพนิดา ศรีสวัสดิ์', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'pandanunew@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T097', 'T097', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกรกนก ทูลฉลอง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'kknalk30159@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T098', 'T098', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางพรสวรรค์ ภัทรบรรพต', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'pornsawan.maha@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T099', 'T099', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายจตุพล เจนจบ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'jatupon10k@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T100', 'T100', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวกิ่งแก้ว เรืองจุ้ย', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'neo.kingkaew@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '87', '87', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายเอกเทศ เพ็ชรวิจิตร', 'ครูอัตราจ้าง', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'admin@slp.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T102', 'T102', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายธวัชชัย เถื่อนชื่น', 'ครูอัตราจ้าง', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'gto565za@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', '61', '61', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางพเยาว์ ศรีพงษ์', 'ครูอัตราจ้าง', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, '61@saluang.ac.th', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T104', 'T104', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวฌานิกา สุขนิ่ม', 'ครูอัตราจ้าง', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'chanikasukhnim@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T105', 'T105', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวอำภา จักรวาลสดใส', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Amampha33@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T106', 'T106', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นายภาณุวัฒ รักผึ้ง', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'panuwat.rp@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T107', 'T107', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวรุจิดา ทะนุ', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'rujiko.9699@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);

INSERT INTO users (user_type, role, username, staff_id, password_hash, full_name, position_title, department, organization, phone, email, line_user_id, is_active)
VALUES ('internal', 'user', 'T108', 'T108', '$2y$10$.iNQouYGprIttGLPFNhisut.j4eZmSalJsrbCUDZ5fCfyDOiP6EzK', 'นางสาวสุภัทรา ฐานสีมี', 'ครู', 'ฝ่ายวิชาการ', 'โรงเรียนสระหลวงพิทยาคม', NULL, 'Supattha.th@gmail.com', NULL, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), full_name = VALUES(full_name), position_title = VALUES(position_title);
