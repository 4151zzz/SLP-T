<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_role(ROLE_ADMIN);
$pdo = get_pdo();

ensure_resource_image_schema($pdo);

$pageTitle = 'จัดการอาคาร ห้อง และยานพาหนะ';
$activeNav = 'resources_manage';

$validTabs = ['rooms', 'buildings', 'vehicles', 'drivers'];
$tab = trim((string)($_GET['tab'] ?? 'rooms'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'rooms';
}

$vehicleStatuses = [
    'available'   => ['label' => 'พร้อมใช้งาน',  'badge' => 'text-bg-success'],
    'maintenance' => ['label' => 'ซ่อมบำรุง',    'badge' => 'text-bg-warning text-dark'],
    'inactive'    => ['label' => 'งดใช้งาน',     'badge' => 'text-bg-secondary'],
];

$driverStatuses = [
    'available' => ['label' => 'พร้อมปฏิบัติงาน', 'badge' => 'text-bg-success'],
    'on_leave'  => ['label' => 'ลางาน/ติดภารกิจ', 'badge' => 'text-bg-warning text-dark'],
    'inactive'  => ['label' => 'พ้นสภาพ/พักงาน',  'badge' => 'text-bg-secondary'],
];

$redirectTab = static function (string $targetTab) use ($validTabs): never {
    $safeTab = in_array($targetTab, $validTabs, true) ? $targetTab : 'rooms';
    redirect(app_url('/resources_manage.php?tab=' . urlencode($safeTab)));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $returnTab = trim((string)($_POST['return_tab'] ?? $tab));
    if (!in_array($returnTab, $validTabs, true)) {
        $returnTab = 'rooms';
    }

    // -------------------------------------------------------------------------
    // 1. BUILDINGS CRUD
    // -------------------------------------------------------------------------
    if ($action === 'create_building' || $action === 'update_building') {
        $isUpdate = ($action === 'update_building');
        $id = $isUpdate ? (int)($_POST['id'] ?? 0) : 0;
        $code = mb_substr(trim((string)($_POST['building_code'] ?? '')), 0, 30);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
        $floorsCount = max(1, (int)($_POST['floors_count'] ?? 1));
        $description = trim((string)($_POST['description'] ?? ''));
        $imgUrl = normalize_image_url($_POST['image_url'] ?? null, $imgErr);

        if ($imgErr !== null) {
            flash('danger', $imgErr);
            $redirectTab('buildings');
        }
        if (($isUpdate && $id <= 0) || $code === '' || $name === '') {
            flash('danger', 'กรุณากรอกรหัสอาคารและชื่ออาคารให้ครบถ้วน');
            $redirectTab('buildings');
        }

        try {
            $dupStmt = $pdo->prepare(
                'SELECT id, building_code, name FROM buildings WHERE (building_code = :c OR name = :n) AND id != :id LIMIT 1'
            );
            $dupStmt->execute([':c' => $code, ':n' => $name, ':id' => $id]);
            $dup = $dupStmt->fetch();
            if ($dup) {
                $msg = (strcasecmp((string)$dup['building_code'], $code) === 0)
                    ? "รหัสอาคาร \"{$code}\" มีอยู่ในระบบแล้ว"
                    : "ชื่ออาคาร \"{$name}\" มีอยู่ในระบบแล้ว";
                flash('danger', $msg);
                $redirectTab('buildings');
            }

            if ($isUpdate) {
                $oldStmt = $pdo->prepare('SELECT id, name FROM buildings WHERE id = :id LIMIT 1');
                $oldStmt->execute([':id' => $id]);
                $oldRow = $oldStmt->fetch();
                if (!$oldRow) {
                    flash('danger', 'ไม่พบข้อมูลอาคารที่ต้องการแก้ไข');
                    $redirectTab('buildings');
                }

                $pdo->beginTransaction();
                $updStmt = $pdo->prepare(
                    'UPDATE buildings SET building_code = :c, name = :n, floors_count = :f, description = :d, image_url = :img WHERE id = :id'
                );
                $updStmt->execute([
                    ':c'   => $code,
                    ':n'   => $name,
                    ':f'   => $floorsCount,
                    ':d'   => $description !== '' ? $description : null,
                    ':img' => $imgUrl,
                    ':id'  => $id,
                ]);
                if ((string)$oldRow['name'] !== $name) {
                    $syncRooms = $pdo->prepare('UPDATE rooms SET building = :new_b WHERE building = :old_b');
                    $syncRooms->execute([':new_b' => $name, ':old_b' => (string)$oldRow['name']]);
                }
                $pdo->commit();
                flash('success', "อัปเดตข้อมูลอาคาร \"{$name}\" เรียบร้อยแล้ว");
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO buildings (building_code, name, floors_count, description, image_url, is_active) VALUES (:c, :n, :f, :d, :img, 1)'
                );
                $insStmt->execute([
                    ':c'   => $code,
                    ':n'   => $name,
                    ':f'   => $floorsCount,
                    ':d'   => $description !== '' ? $description : null,
                    ':img' => $imgUrl,
                ]);
                flash('success', "เพิ่มอาคาร \"{$name}\" เรียบร้อยแล้ว");
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'ไม่สามารถบันทึกข้อมูลอาคารได้ กรุณาตรวจสอบข้อมูลอีกครั้ง');
        }
        $redirectTab('buildings');
    } elseif ($action === 'toggle_building') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE buildings SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id')->execute([':id' => $id]);
            flash('success', 'อัปเดตสถานะการใช้งานอาคารเรียบร้อยแล้ว');
        }
        $redirectTab('buildings');
    } elseif ($action === 'delete_building') {
        $id = (int)($_POST['id'] ?? 0);
        $bStmt = $pdo->prepare('SELECT id, name FROM buildings WHERE id = :id LIMIT 1');
        $bStmt->execute([':id' => $id]);
        $bRow = $bStmt->fetch();
        if (!$bRow) {
            flash('danger', 'ไม่พบข้อมูลอาคารที่ต้องการลบ');
            $redirectTab('buildings');
        }

        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE building = :name');
        $cntStmt->execute([':name' => (string)$bRow['name']]);
        $linkedRooms = (int)$cntStmt->fetchColumn();
        if ($linkedRooms > 0) {
            flash('warning', "ไม่สามารถลบอาคาร \"{$bRow['name']}\" ได้ เนื่องจากยังมีห้องสังกัดอยู่ {$linkedRooms} ห้อง กรุณาย้ายห้องออกก่อนหรือใช้ปุ่มปิดใช้งาน");
            $redirectTab('buildings');
        }

        $pdo->prepare('DELETE FROM buildings WHERE id = :id')->execute([':id' => $id]);
        flash('success', "ลบอาคาร \"{$bRow['name']}\" ออกจากระบบเรียบร้อยแล้ว");
        $redirectTab('buildings');
    }

    // -------------------------------------------------------------------------
    // 2. ROOMS CRUD
    // -------------------------------------------------------------------------
    if ($action === 'create_room' || $action === 'update_room') {
        $isUpdate = ($action === 'update_room');
        $id = $isUpdate ? (int)($_POST['id'] ?? 0) : 0;
        $code = mb_substr(trim((string)($_POST['room_code'] ?? '')), 0, 30);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);

        $buildingSelect = trim((string)($_POST['building_select'] ?? ''));
        $buildingCustom = trim((string)($_POST['building_custom'] ?? ''));
        if ($buildingSelect === '__custom__') {
            $building = $buildingCustom;
        } elseif ($buildingSelect !== '') {
            $building = $buildingSelect;
        } else {
            $building = trim((string)($_POST['building'] ?? ''));
        }
        $building = mb_substr($building, 0, 150);

        $floor = mb_substr(trim((string)($_POST['floor'] ?? '')), 0, 30);
        $capacity = max(1, (int)($_POST['capacity'] ?? 10));
        $allowExternal = (isset($_POST['allow_external']) && (string)$_POST['allow_external'] === '1') ? 1 : 0;
        $rawEq = $_POST['available_equipment'] ?? [];
        $allowedEq = array_keys(ROOM_EQUIPMENT_LABELS);
        $equipmentList = array_values(array_intersect($allowedEq, is_array($rawEq) ? array_map('strval', $rawEq) : []));
        $eqJson = !empty($equipmentList) ? json_encode($equipmentList, JSON_UNESCAPED_UNICODE) : null;
        $description = trim((string)($_POST['description'] ?? ''));
        $imgUrl = normalize_image_url($_POST['image_url'] ?? null, $imgErr);

        if ($imgErr !== null) {
            flash('danger', $imgErr);
            $redirectTab('rooms');
        }
        if (($isUpdate && $id <= 0) || $code === '' || $name === '') {
            flash('danger', 'กรุณากรอกรหัสห้องและชื่อห้องให้ครบถ้วน');
            $redirectTab('rooms');
        }

        try {
            $dupStmt = $pdo->prepare('SELECT id FROM rooms WHERE room_code = :c AND id != :id LIMIT 1');
            $dupStmt->execute([':c' => $code, ':id' => $id]);
            if ($dupStmt->fetch()) {
                flash('danger', "รหัสห้อง \"{$code}\" มีอยู่ในระบบแล้ว");
                $redirectTab('rooms');
            }

            if ($isUpdate) {
                $updStmt = $pdo->prepare(
                    'UPDATE rooms
                     SET room_code = :c, name = :n, building = :b, floor = :fl,
                         capacity = :cap, allow_external = :ext, available_equipment = :eq, description = :d, image_url = :img
                     WHERE id = :id'
                );
                $updStmt->execute([
                    ':c'   => $code,
                    ':n'   => $name,
                    ':b'   => $building !== '' ? $building : null,
                    ':fl'  => $floor !== '' ? $floor : null,
                    ':cap' => $capacity,
                    ':ext' => $allowExternal,
                    ':eq'  => $eqJson,
                    ':d'   => $description !== '' ? $description : null,
                    ':img' => $imgUrl,
                    ':id'  => $id,
                ]);
                flash('success', "อัปเดตข้อมูลห้อง \"{$name}\" เรียบร้อยแล้ว");
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO rooms (room_code, name, building, floor, capacity, allow_external, available_equipment, description, image_url, is_active)
                     VALUES (:c, :n, :b, :fl, :cap, :ext, :eq, :d, :img, 1)'
                );
                $insStmt->execute([
                    ':c'   => $code,
                    ':n'   => $name,
                    ':b'   => $building !== '' ? $building : null,
                    ':fl'  => $floor !== '' ? $floor : null,
                    ':cap' => $capacity,
                    ':ext' => $allowExternal,
                    ':eq'  => $eqJson,
                    ':d'   => $description !== '' ? $description : null,
                    ':img' => $imgUrl,
                ]);
                flash('success', "เพิ่มห้อง \"{$name}\" เรียบร้อยแล้ว");
            }
        } catch (Throwable $e) {
            flash('danger', 'ไม่สามารถบันทึกข้อมูลห้องได้ กรุณาตรวจสอบข้อมูลอีกครั้ง');
        }
        $redirectTab('rooms');
    } elseif ($action === 'toggle_room') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE rooms SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id')->execute([':id' => $id]);
            flash('success', 'อัปเดตสถานะการเปิดใช้งานห้องเรียบร้อยแล้ว');
        }
        $redirectTab('rooms');
    } elseif ($action === 'delete_room') {
        $id = (int)($_POST['id'] ?? 0);
        $rStmt = $pdo->prepare('SELECT id, name FROM rooms WHERE id = :id LIMIT 1');
        $rStmt->execute([':id' => $id]);
        $rRow = $rStmt->fetch();
        if (!$rRow) {
            flash('danger', 'ไม่พบข้อมูลห้องที่ต้องการลบ');
            $redirectTab('rooms');
        }

        $bkStmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE room_id = :id');
        $bkStmt->execute([':id' => $id]);
        $bkCount = (int)$bkStmt->fetchColumn();
        if ($bkCount > 0) {
            flash('warning', "ไม่สามารถลบห้อง \"{$rRow['name']}\" ได้ เนื่องจากมีประวัติการจองอยู่ {$bkCount} รายการ กรุณาใช้ปุ่มปิดให้บริการแทน");
            $redirectTab('rooms');
        }

        $pdo->prepare('DELETE FROM rooms WHERE id = :id')->execute([':id' => $id]);
        flash('success', "ลบห้อง \"{$rRow['name']}\" ออกจากระบบเรียบร้อยแล้ว");
        $redirectTab('rooms');
    }

    // -------------------------------------------------------------------------
    // 3. VEHICLES CRUD
    // -------------------------------------------------------------------------
    if ($action === 'create_vehicle' || $action === 'update_vehicle') {
        $isUpdate = ($action === 'update_vehicle');
        $id = $isUpdate ? (int)($_POST['id'] ?? 0) : 0;
        $code = mb_substr(trim((string)($_POST['vehicle_code'] ?? '')), 0, 30);
        $plate = mb_substr(trim((string)($_POST['plate_number'] ?? '')), 0, 30);
        $vType = mb_substr(trim((string)($_POST['vehicle_type'] ?? '')), 0, 80);
        $brand = mb_substr(trim((string)($_POST['brand_model'] ?? '')), 0, 120);
        $seats = max(1, (int)($_POST['seats'] ?? 4));
        $status = trim((string)($_POST['status'] ?? 'available'));
        if (!array_key_exists($status, $vehicleStatuses)) {
            $status = 'available';
        }
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (($isUpdate && $id <= 0) || $code === '' || $plate === '' || $vType === '' || $brand === '') {
            flash('danger', 'กรุณากรอกรหัสรถ ทะเบียน ประเภท และยี่ห้อ/รุ่นให้ครบถ้วน');
            $redirectTab('vehicles');
        }

        try {
            $dupStmt = $pdo->prepare(
                'SELECT id, vehicle_code, plate_number FROM vehicles WHERE (vehicle_code = :c OR plate_number = :p) AND id != :id LIMIT 1'
            );
            $dupStmt->execute([':c' => $code, ':p' => $plate, ':id' => $id]);
            $dup = $dupStmt->fetch();
            if ($dup) {
                $msg = (strcasecmp((string)$dup['vehicle_code'], $code) === 0)
                    ? "รหัสรถ \"{$code}\" มีอยู่ในระบบแล้ว"
                    : "ทะเบียนรถ \"{$plate}\" มีอยู่ในระบบแล้ว";
                flash('danger', $msg);
                $redirectTab('vehicles');
            }

            if ($isUpdate) {
                $updStmt = $pdo->prepare(
                    'UPDATE vehicles
                     SET vehicle_code = :c, plate_number = :p, vehicle_type = :t, brand_model = :b,
                         seats = :s, status = :st, notes = :nt
                     WHERE id = :id'
                );
                $updStmt->execute([
                    ':c'  => $code,
                    ':p'  => $plate,
                    ':t'  => $vType,
                    ':b'  => $brand,
                    ':s'  => $seats,
                    ':st' => $status,
                    ':nt' => $notes !== '' ? $notes : null,
                    ':id' => $id,
                ]);
                flash('success', "อัปเดตข้อมูลรถ \"{$brand} ({$plate})\" เรียบร้อยแล้ว");
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO vehicles (vehicle_code, plate_number, vehicle_type, brand_model, seats, status, notes)
                     VALUES (:c, :p, :t, :b, :s, :st, :nt)'
                );
                $insStmt->execute([
                    ':c'  => $code,
                    ':p'  => $plate,
                    ':t'  => $vType,
                    ':b'  => $brand,
                    ':s'  => $seats,
                    ':st' => $status,
                    ':nt' => $notes !== '' ? $notes : null,
                ]);
                flash('success', "เพิ่มยานพาหนะ \"{$brand} ({$plate})\" เรียบร้อยแล้ว");
            }
        } catch (Throwable $e) {
            flash('danger', 'ไม่สามารถบันทึกข้อมูลยานพาหนะได้ กรุณาตรวจสอบข้อมูลอีกครั้ง');
        }
        $redirectTab('vehicles');
    } elseif ($action === 'set_vehicle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'available'));
        if ($id > 0 && array_key_exists($status, $vehicleStatuses)) {
            $pdo->prepare('UPDATE vehicles SET status = :st WHERE id = :id')->execute([':st' => $status, ':id' => $id]);
            flash('success', 'อัปเดตสถานะยานพาหนะเรียบร้อยแล้ว');
        }
        $redirectTab('vehicles');
    } elseif ($action === 'delete_vehicle') {
        $id = (int)($_POST['id'] ?? 0);
        $vStmt = $pdo->prepare('SELECT id, brand_model, plate_number FROM vehicles WHERE id = :id LIMIT 1');
        $vStmt->execute([':id' => $id]);
        $vRow = $vStmt->fetch();
        if (!$vRow) {
            flash('danger', 'ไม่พบข้อมูลยานพาหนะที่ต้องการลบ');
            $redirectTab('vehicles');
        }

        $bkStmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE vehicle_id = :id');
        $bkStmt->execute([':id' => $id]);
        $bkCount = (int)$bkStmt->fetchColumn();
        if ($bkCount > 0) {
            flash('warning', "ไม่สามารถลบรถ \"{$vRow['plate_number']}\" ได้ เนื่องจากมีประวัติการจองอยู่ {$bkCount} รายการ กรุณาเปลี่ยนสถานะเป็นงดใช้งานแทน");
            $redirectTab('vehicles');
        }

        $pdo->prepare('DELETE FROM vehicles WHERE id = :id')->execute([':id' => $id]);
        flash('success', "ลบยานพาหนะ \"{$vRow['brand_model']} ({$vRow['plate_number']})\" ออกจากระบบเรียบร้อยแล้ว");
        $redirectTab('vehicles');
    }

    // -------------------------------------------------------------------------
    // 4. DRIVERS CRUD
    // -------------------------------------------------------------------------
    if ($action === 'create_driver' || $action === 'update_driver') {
        $isUpdate = ($action === 'update_driver');
        $id = $isUpdate ? (int)($_POST['id'] ?? 0) : 0;
        $code = mb_substr(trim((string)($_POST['driver_code'] ?? '')), 0, 30);
        $fullName = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 150);
        $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 30);
        $license = mb_substr(trim((string)($_POST['license_number'] ?? '')), 0, 50);
        $status = trim((string)($_POST['status'] ?? 'available'));
        if (!array_key_exists($status, $driverStatuses)) {
            $status = 'available';
        }

        if (($isUpdate && $id <= 0) || $code === '' || $fullName === '' || $phone === '') {
            flash('danger', 'กรุณากรอกรหัสพนักงานขับรถ ชื่อ-นามสกุล และเบอร์โทรศัพท์ให้ครบถ้วน');
            $redirectTab('drivers');
        }

        try {
            $dupStmt = $pdo->prepare('SELECT id FROM drivers WHERE driver_code = :c AND id != :id LIMIT 1');
            $dupStmt->execute([':c' => $code, ':id' => $id]);
            if ($dupStmt->fetch()) {
                flash('danger', "รหัสพนักงานขับรถ \"{$code}\" มีอยู่ในระบบแล้ว");
                $redirectTab('drivers');
            }

            if ($isUpdate) {
                $updStmt = $pdo->prepare(
                    'UPDATE drivers
                     SET driver_code = :c, full_name = :fn, phone = :ph, license_number = :lc, status = :st
                     WHERE id = :id'
                );
                $updStmt->execute([
                    ':c'  => $code,
                    ':fn' => $fullName,
                    ':ph' => $phone,
                    ':lc' => $license !== '' ? $license : null,
                    ':st' => $status,
                    ':id' => $id,
                ]);
                flash('success', "อัปเดตข้อมูลพนักงานขับรถ \"{$fullName}\" เรียบร้อยแล้ว");
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO drivers (driver_code, full_name, phone, license_number, status)
                     VALUES (:c, :fn, :ph, :lc, :st)'
                );
                $insStmt->execute([
                    ':c'  => $code,
                    ':fn' => $fullName,
                    ':ph' => $phone,
                    ':lc' => $license !== '' ? $license : null,
                    ':st' => $status,
                ]);
                flash('success', "เพิ่มพนักงานขับรถ \"{$fullName}\" เรียบร้อยแล้ว");
            }
        } catch (Throwable $e) {
            flash('danger', 'ไม่สามารถบันทึกข้อมูลพนักงานขับรถได้ กรุณาตรวจสอบข้อมูลอีกครั้ง');
        }
        $redirectTab('drivers');
    } elseif ($action === 'set_driver_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'available'));
        if ($id > 0 && array_key_exists($status, $driverStatuses)) {
            $pdo->prepare('UPDATE drivers SET status = :st WHERE id = :id')->execute([':st' => $status, ':id' => $id]);
            flash('success', 'อัปเดตสถานะพนักงานขับรถเรียบร้อยแล้ว');
        }
        $redirectTab('drivers');
    } elseif ($action === 'delete_driver') {
        $id = (int)($_POST['id'] ?? 0);
        $dStmt = $pdo->prepare('SELECT id, full_name FROM drivers WHERE id = :id LIMIT 1');
        $dStmt->execute([':id' => $id]);
        $dRow = $dStmt->fetch();
        if (!$dRow) {
            flash('danger', 'ไม่พบข้อมูลพนักงานขับรถที่ต้องการลบ');
            $redirectTab('drivers');
        }

        $bkStmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE driver_id = :id');
        $bkStmt->execute([':id' => $id]);
        $bkCount = (int)$bkStmt->fetchColumn();
        if ($bkCount > 0) {
            flash('warning', "ไม่สามารถลบ \"{$dRow['full_name']}\" ได้ เนื่องจากมีประวัติขับรถในการจองอยู่ {$bkCount} รายการ กรุณาเปลี่ยนสถานะเป็นพักงานแทน");
            $redirectTab('drivers');
        }

        $pdo->prepare('DELETE FROM drivers WHERE id = :id')->execute([':id' => $id]);
        flash('success', "ลบพนักงานขับรถ \"{$dRow['full_name']}\" ออกจากระบบเรียบร้อยแล้ว");
        $redirectTab('drivers');
    }
}

// -----------------------------------------------------------------------------
// FETCH ALL RESOURCE DATA
// -----------------------------------------------------------------------------
$buildings = [];
try {
    $buildings = $pdo->query(
        'SELECT b.*,
                (SELECT COUNT(*) FROM rooms r WHERE r.building = b.name) AS room_count
         FROM buildings b
         ORDER BY b.is_active DESC, b.building_code ASC'
    )->fetchAll();
} catch (Throwable $e) {
    $buildings = [];
}

$allRooms = $pdo->query(
    'SELECT r.*,
            b.image_url AS building_image_url,
            (SELECT COUNT(*) FROM bookings bk WHERE bk.room_id = r.id) AS booking_count
     FROM rooms r
     LEFT JOIN buildings b ON b.name = r.building
     ORDER BY r.is_active DESC, COALESCE(r.building, "") ASC, r.room_code ASC'
)->fetchAll();

$vehicles = $pdo->query(
    'SELECT v.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS booking_count
     FROM vehicles v
     ORDER BY v.vehicle_code ASC'
)->fetchAll();

$drivers = $pdo->query(
    'SELECT d.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.driver_id = d.id) AS booking_count
     FROM drivers d
     ORDER BY d.driver_code ASC'
)->fetchAll();

// Collect unique building names for dropdowns/filters (combining `buildings` table + legacy rooms.building values)
$buildingNamesList = [];
foreach ($buildings as $b) {
    $bName = trim((string)$b['name']);
    if ($bName !== '' && !in_array($bName, $buildingNamesList, true)) {
        $buildingNamesList[] = $bName;
    }
}
foreach ($allRooms as $rm) {
    $rb = trim((string)($rm['building'] ?? ''));
    if ($rb !== '' && !in_array($rb, $buildingNamesList, true)) {
        $buildingNamesList[] = $rb;
    }
}

// Room search / building filter
$searchRoomQ = trim((string)($_GET['q'] ?? ''));
$filterBuilding = trim((string)($_GET['building'] ?? ''));
$filteredRooms = array_values(array_filter($allRooms, static function (array $r) use ($searchRoomQ, $filterBuilding): bool {
    if ($filterBuilding !== '' && (string)($r['building'] ?? '') !== $filterBuilding) {
        return false;
    }
    if ($searchRoomQ !== '') {
        $haystack = mb_strtolower(
            implode(' ', [$r['room_code'], $r['name'], $r['building'] ?? '', $r['floor'] ?? '']),
            'UTF-8'
        );
        if (!str_contains($haystack, mb_strtolower($searchRoomQ, 'UTF-8'))) {
            return false;
        }
    }
    return true;
}));

$activeRoomsCount = count(array_filter($allRooms, static fn (array $r): bool => (int)$r['is_active'] === 1));
$availVehiclesCount = count(array_filter($vehicles, static fn (array $v): bool => (string)$v['status'] === 'available'));
$availDriversCount = count(array_filter($drivers, static fn (array $d): bool => (string)$d['status'] === 'available'));

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Header Welcome Card -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-building-fill-gear me-1"></i>ศูนย์จัดการทรัพยากรส่วนกลาง (ผู้ดูแลระบบ)
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="resources-manage-heading">จัดการอาคาร ห้องประชุม และยานพาหนะ</h1>
            <p class="small text-muted mb-0">
                เพิ่ม แก้ไข หรือปิด/เปิดให้บริการอาคาร ห้องประชุม/ห้องทั่วไป รถ และพนักงานขับรถ ข้อมูลจะเชื่อมไปยังหน้าจองและหน้าแจ้งซ่อมทันที
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            <button type="button"
                    class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                    id="btnTopQuickAdd">
                <i class="bi bi-plus-circle-fill"></i>
                <span id="btnTopQuickAddLabel">
                    <?= match ($tab) {
                        'buildings' => 'เพิ่มอาคารใหม่',
                        'vehicles'  => 'เพิ่มยานพาหนะใหม่',
                        'drivers'   => 'เพิ่มพนักงานขับรถ',
                        default     => 'เพิ่มห้องใหม่',
                    } ?>
                </span>
            </button>
        </div>
    </div>
</div>

<!-- 4 Clickable Summary KPI Cards -->
<div class="row g-2 g-sm-3 mb-4">
    <div class="col-6 col-xl-3">
        <a href="<?= e(app_url('/resources_manage.php?tab=buildings')) ?>" class="kpi-link-card">
            <div class="kpi-card kpi-accent-indigo p-3 h-100 d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-indigo"><i class="bi bi-buildings-fill"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="kpi-label text-truncate mb-1">อาคารสถานที่</div>
                    <div class="fs-4 fw-bold text-dark lh-1"><?= count($buildings) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= e(app_url('/resources_manage.php?tab=rooms')) ?>" class="kpi-link-card">
            <div class="kpi-card kpi-accent-blue p-3 h-100 d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-blue"><i class="bi bi-door-open-fill"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="kpi-label text-truncate mb-1">ห้องพร้อมใช้งาน</div>
                    <div class="fs-4 fw-bold text-dark lh-1"><?= $activeRoomsCount ?><span class="fs-6 fw-normal text-muted">/<?= count($allRooms) ?></span></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= e(app_url('/resources_manage.php?tab=vehicles')) ?>" class="kpi-link-card">
            <div class="kpi-card kpi-accent-emerald p-3 h-100 d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-emerald"><i class="bi bi-truck-front-fill"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="kpi-label text-truncate mb-1">รถพร้อมใช้</div>
                    <div class="fs-4 fw-bold text-dark lh-1"><?= $availVehiclesCount ?><span class="fs-6 fw-normal text-muted">/<?= count($vehicles) ?></span></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= e(app_url('/resources_manage.php?tab=drivers')) ?>" class="kpi-link-card">
            <div class="kpi-card kpi-accent-amber p-3 h-100 d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-amber"><i class="bi bi-person-badge-fill"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="kpi-label text-truncate mb-1">พนักงานขับรถ</div>
                    <div class="fs-4 fw-bold text-dark lh-1"><?= $availDriversCount ?><span class="fs-6 fw-normal text-muted">/<?= count($drivers) ?></span></div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Tab Navigation Bar -->
<div class="spotlight-card mb-4">
    <div class="spotlight-header flex-wrap gap-2">
        <div class="resource-tab-pills d-flex flex-wrap gap-2">
            <a href="<?= e(app_url('/resources_manage.php?tab=rooms')) ?>"
               class="btn btn-sm rounded-pill px-3 fw-semibold <?= $tab === 'rooms' ? 'btn-primary' : 'btn-light border text-secondary' ?>"
               data-testid="tab-rooms">
                <i class="bi bi-door-open me-1"></i>ห้องประชุม / ห้อง <span class="badge rounded-pill ms-1 <?= $tab === 'rooms' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= count($allRooms) ?></span>
            </a>
            <a href="<?= e(app_url('/resources_manage.php?tab=buildings')) ?>"
               class="btn btn-sm rounded-pill px-3 fw-semibold <?= $tab === 'buildings' ? 'btn-primary' : 'btn-light border text-secondary' ?>"
               data-testid="tab-buildings">
                <i class="bi bi-buildings me-1"></i>อาคารสถานที่ <span class="badge rounded-pill ms-1 <?= $tab === 'buildings' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= count($buildings) ?></span>
            </a>
            <a href="<?= e(app_url('/resources_manage.php?tab=vehicles')) ?>"
               class="btn btn-sm rounded-pill px-3 fw-semibold <?= $tab === 'vehicles' ? 'btn-primary' : 'btn-light border text-secondary' ?>"
               data-testid="tab-vehicles">
                <i class="bi bi-truck me-1"></i>ยานพาหนะ (รถ) <span class="badge rounded-pill ms-1 <?= $tab === 'vehicles' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= count($vehicles) ?></span>
            </a>
            <a href="<?= e(app_url('/resources_manage.php?tab=drivers')) ?>"
               class="btn btn-sm rounded-pill px-3 fw-semibold <?= $tab === 'drivers' ? 'btn-primary' : 'btn-light border text-secondary' ?>"
               data-testid="tab-drivers">
                <i class="bi bi-person-badge me-1"></i>พนักงานขับรถ <span class="badge rounded-pill ms-1 <?= $tab === 'drivers' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?>"><?= count($drivers) ?></span>
            </a>
        </div>

        <div class="d-flex gap-2">
            <?php if ($tab === 'buildings'): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-semibold" id="btnAddBuilding" data-testid="btn-add-building">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มอาคาร
                </button>
            <?php elseif ($tab === 'vehicles'): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-semibold" id="btnAddVehicle" data-testid="btn-add-vehicle">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มยานพาหนะ
                </button>
            <?php elseif ($tab === 'drivers'): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-semibold" id="btnAddDriver" data-testid="btn-add-driver">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มพนักงานขับรถ
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-semibold" id="btnAddRoom" data-testid="btn-add-room">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มห้อง
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================================================================
         TAB 1: ROOMS
         =================================================================== -->
    <?php if ($tab === 'rooms'): ?>
        <div class="p-3 border-bottom bg-light bg-opacity-50">
            <form method="get" action="<?= e(app_url('/resources_manage.php')) ?>" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="rooms">
                <div class="col-12 col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text"
                               class="form-control"
                               name="q"
                               value="<?= e($searchRoomQ) ?>"
                               placeholder="ค้นหารหัสห้อง ชื่อห้อง หรือชั้น...">
                    </div>
                </div>
                <div class="col-8 col-md-4">
                    <select class="form-select form-select-sm" name="building" onchange="this.form.submit()">
                        <option value="">ทุกอาคาร</option>
                        <?php foreach ($buildingNamesList as $bn): ?>
                            <option value="<?= e($bn) ?>" <?= $filterBuilding === $bn ? 'selected' : '' ?>>
                                <?= e($bn) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 col-md-3 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">กรอง</button>
                    <?php if ($searchRoomQ !== '' || $filterBuilding !== ''): ?>
                        <a href="<?= e(app_url('/resources_manage.php?tab=rooms')) ?>" class="btn btn-sm btn-outline-secondary">ล้าง</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if (empty($filteredRooms)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-door-closed fs-1 d-block mb-2 opacity-50"></i>
                <div class="fw-semibold text-dark mb-1">ยังไม่มีข้อมูลห้องประชุม / ห้องในระบบ</div>
                <div class="small mb-3">เริ่มต้นด้วยการเพิ่มอาคารหรือเพิ่มห้องประชุมเพื่อให้บุคลากรจองและแจ้งซ่อมได้</div>
                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold" onclick="document.getElementById('btnAddRoom')?.click()">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มห้องแรกเลย
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($filteredRooms as $rm):
                $isActive = ((int)$rm['is_active'] === 1);
                $allowExt = ((int)$rm['allow_external'] === 1);
                $eqKeys = json_decode((string)($rm['available_equipment'] ?? '[]'), true);
                if (!is_array($eqKeys)) {
                    $eqKeys = [];
                }
                $eqNames = array_map(static fn ($k): string => ROOM_EQUIPMENT_LABELS[$k] ?? (string)$k, $eqKeys);
                $ownRoomImg = trim((string)($rm['image_url'] ?? ''));
                $effectiveRoomImg = $ownRoomImg !== '' ? $ownRoomImg : trim((string)($rm['building_image_url'] ?? ''));
                $rmDetail = [
                    'title'    => $rm['room_code'] . ' · ' . $rm['name'],
                    'subtitle' => trim(($rm['building'] ?: 'ไม่ระบุอาคาร') . ($rm['floor'] ? ' · ' . $rm['floor'] : '')),
                    'badge'    => $isActive ? 'เปิดให้บริการ' : 'ปิดให้บริการ',
                    'rows'     => [
                        ['label' => 'ความจุสูงสุด',        'value' => (int)$rm['capacity'] . ' คน'],
                        ['label' => 'สิทธิ์บุคคลภายนอก',   'value' => $allowExt ? 'อนุญาตให้บุคคลภายนอกจองได้' : 'เฉพาะบุคลากรภายใน'],
                        ['label' => 'อุปกรณ์ประจำห้อง',     'value' => !empty($eqNames) ? implode(', ', $eqNames) : '-'],
                        ['label' => 'ประวัติการจองในระบบ', 'value' => (int)$rm['booking_count'] . ' รายการ'],
                    ],
                    'noteTitle' => 'รายละเอียดเพิ่มเติม',
                    'note'      => (string)($rm['description'] ?: ''),
                ];
                if ($effectiveRoomImg !== '') {
                    $rmDetail['photos'] = [[
                        'label' => $ownRoomImg !== '' ? 'รูปภาพห้อง' : 'รูปภาพอาคาร',
                        'url'   => $effectiveRoomImg,
                    ]];
                }
                $rmJson = [
                    'id'                  => (int)$rm['id'],
                    'room_code'           => (string)$rm['room_code'],
                    'name'                => (string)$rm['name'],
                    'building'            => (string)($rm['building'] ?? ''),
                    'floor'               => (string)($rm['floor'] ?? ''),
                    'capacity'            => (int)$rm['capacity'],
                    'allow_external'      => $allowExt,
                    'available_equipment' => $eqKeys,
                    'description'         => (string)($rm['description'] ?? ''),
                    'image_url'           => $ownRoomImg,
                ];
            ?>
                <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" data-testid="room-row-<?= e($rm['room_code']) ?>">
                    <div class="d-flex align-items-center gap-3 min-w-0 flex-grow-1">
                        <?php if ($effectiveRoomImg !== ''): ?>
                            <button type="button"
                                    class="resource-thumb"
                                    data-swal-photo="<?= e($effectiveRoomImg) ?>"
                                    data-swal-title="<?= e($rm['name']) ?>"
                                    title="คลิกเพื่อดูภาพขยาย">
                                <img src="<?= e($effectiveRoomImg) ?>"
                                     alt="<?= e($rm['name']) ?>"
                                     loading="lazy"
                                     onerror="this.classList.add('d-none'); this.nextElementSibling?.classList.remove('d-none');">
                                <span class="d-none text-muted"><i class="bi bi-image fs-5"></i></span>
                            </button>
                        <?php else: ?>
                            <span class="resource-thumb-placeholder" aria-hidden="true"><i class="bi bi-door-open"></i></span>
                        <?php endif; ?>

                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge text-bg-light border text-dark font-monospace"><?= e($rm['room_code']) ?></span>
                                <span class="fw-bold text-dark"><?= e($rm['name']) ?></span>
                                <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $isActive ? 'เปิดให้บริการ' : 'ปิดให้บริการ' ?>
                                </span>
                                <?php if ($allowExt): ?>
                                    <span class="badge text-bg-info text-dark">บุคคลภายนอกจองได้</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted d-flex flex-wrap gap-3">
                                <span><i class="bi bi-building me-1"></i><?= e($rm['building'] ?: 'ไม่ระบุอาคาร') ?><?= !empty($rm['floor']) ? ' · ' . e($rm['floor']) : '' ?></span>
                                <span><i class="bi bi-people me-1"></i>รองรับ <?= (int)$rm['capacity'] ?> คน</span>
                                <?php if (!empty($eqNames)): ?>
                                    <span><i class="bi bi-speaker me-1"></i><?= e(implode(', ', $eqNames)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-primary rounded-3 px-2"
                                title="ดูรายละเอียด"
                                data-swal-detail="<?= e((string)json_encode($rmDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary rounded-3 px-3 btn-edit-room"
                                data-json="<?= e((string)json_encode($rmJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข
                        </button>
                        <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_room">
                            <input type="hidden" name="return_tab" value="rooms">
                            <input type="hidden" name="id" value="<?= (int)$rm['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?> rounded-3 px-3">
                                <i class="bi <?= $isActive ? 'bi-pause-circle' : 'bi-play-circle' ?> me-1"></i><?= $isActive ? 'ปิดชั่วคราว' : 'เปิดใช้งาน' ?>
                            </button>
                        </form>
                        <form method="post"
                              action="<?= e(app_url('/resources_manage.php')) ?>"
                              class="d-inline"
                              data-confirm="ต้องการลบห้อง &quot;<?= e($rm['name']) ?>&quot; ออกจากระบบถาวรหรือไม่?"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_room">
                            <input type="hidden" name="return_tab" value="rooms">
                            <input type="hidden" name="id" value="<?= (int)$rm['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบห้อง">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <!-- ===================================================================
         TAB 2: BUILDINGS
         =================================================================== -->
    <?php elseif ($tab === 'buildings'): ?>
        <?php if (empty($buildings)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-buildings fs-1 d-block mb-2 opacity-50"></i>
                <div class="fw-semibold text-dark mb-1">ยังไม่มีข้อมูลอาคารสถานที่ในระบบ</div>
                <div class="small mb-3">เพิ่มรายชื่ออาคารของโรงเรียนเพื่อนำไปผูกกับห้องประชุมและห้องต่าง ๆ</div>
                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold" onclick="document.getElementById('btnAddBuilding')?.click()">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มอาคารแรกเลย
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($buildings as $bld):
                $isActive = ((int)$bld['is_active'] === 1);
                $bldImg = trim((string)($bld['image_url'] ?? ''));
                $bldDetail = [
                    'title'    => $bld['building_code'] . ' · ' . $bld['name'],
                    'subtitle' => 'อาคารสถานที่',
                    'badge'    => $isActive ? 'เปิดใช้งาน' : 'ปิดใช้งาน',
                    'rows'     => [
                        ['label' => 'จำนวนชั้น',        'value' => (int)$bld['floors_count'] . ' ชั้น'],
                        ['label' => 'ห้องที่สังกัดอาคารนี้', 'value' => (int)$bld['room_count'] . ' ห้อง'],
                    ],
                    'noteTitle' => 'รายละเอียด',
                    'note'      => (string)($bld['description'] ?: ''),
                ];
                if ($bldImg !== '') {
                    $bldDetail['photos'] = [[
                        'label' => 'รูปภาพอาคาร',
                        'url'   => $bldImg,
                    ]];
                }
                $bldJson = [
                    'id'            => (int)$bld['id'],
                    'building_code' => (string)$bld['building_code'],
                    'name'          => (string)$bld['name'],
                    'floors_count'  => (int)$bld['floors_count'],
                    'description'   => (string)($bld['description'] ?? ''),
                    'image_url'     => $bldImg,
                ];
            ?>
                <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" data-testid="building-row-<?= e($bld['building_code']) ?>">
                    <div class="d-flex align-items-center gap-3 min-w-0 flex-grow-1">
                        <?php if ($bldImg !== ''): ?>
                            <button type="button"
                                    class="resource-thumb"
                                    data-swal-photo="<?= e($bldImg) ?>"
                                    data-swal-title="<?= e($bld['name']) ?>"
                                    title="คลิกเพื่อดูภาพขยาย">
                                <img src="<?= e($bldImg) ?>"
                                     alt="<?= e($bld['name']) ?>"
                                     loading="lazy"
                                     onerror="this.classList.add('d-none'); this.nextElementSibling?.classList.remove('d-none');">
                                <span class="d-none text-muted"><i class="bi bi-image fs-5"></i></span>
                            </button>
                        <?php else: ?>
                            <span class="resource-thumb-placeholder" aria-hidden="true"><i class="bi bi-buildings"></i></span>
                        <?php endif; ?>

                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge text-bg-light border text-dark font-monospace"><?= e($bld['building_code']) ?></span>
                                <span class="fw-bold text-dark"><?= e($bld['name']) ?></span>
                                <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $isActive ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                                </span>
                            </div>
                            <div class="small text-muted d-flex flex-wrap gap-3">
                                <span><i class="bi bi-layers me-1"></i><?= (int)$bld['floors_count'] ?> ชั้น</span>
                                <span><i class="bi bi-door-open me-1"></i>มีห้องสังกัด <?= (int)$bld['room_count'] ?> ห้อง</span>
                                <?php if (!empty($bld['description'])): ?>
                                    <span class="text-truncate"><?= e($bld['description']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-primary rounded-3 px-2"
                                title="ดูรายละเอียด"
                                data-swal-detail="<?= e((string)json_encode($bldDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary rounded-3 px-3 btn-edit-building"
                                data-json="<?= e((string)json_encode($bldJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข
                        </button>
                        <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_building">
                            <input type="hidden" name="return_tab" value="buildings">
                            <input type="hidden" name="id" value="<?= (int)$bld['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?> rounded-3 px-3">
                                <i class="bi <?= $isActive ? 'bi-pause-circle' : 'bi-play-circle' ?> me-1"></i><?= $isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                            </button>
                        </form>
                        <form method="post"
                              action="<?= e(app_url('/resources_manage.php')) ?>"
                              class="d-inline"
                              data-confirm="ต้องการลบอาคาร &quot;<?= e($bld['name']) ?>&quot; ใช่หรือไม่?"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_building">
                            <input type="hidden" name="return_tab" value="buildings">
                            <input type="hidden" name="id" value="<?= (int)$bld['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบอาคาร">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <!-- ===================================================================
         TAB 3: VEHICLES
         =================================================================== -->
    <?php elseif ($tab === 'vehicles'): ?>
        <?php if (empty($vehicles)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-truck-front fs-1 d-block mb-2 opacity-50"></i>
                <div class="fw-semibold text-dark mb-1">ยังไม่มีข้อมูลยานพาหนะในระบบ</div>
                <div class="small mb-3">เพิ่มรถตู้ รถเก๋ง รถกระบะ หรือรถบัสของโรงเรียนเพื่อให้บุคลากรยื่นขอใช้รถได้</div>
                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold" onclick="document.getElementById('btnAddVehicle')?.click()">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มยานพาหนะแรกเลย
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($vehicles as $veh):
                $stCode = (string)$veh['status'];
                $stMeta = $vehicleStatuses[$stCode] ?? ['label' => $stCode, 'badge' => 'text-bg-secondary'];
                $vehDetail = [
                    'title'    => $veh['vehicle_code'] . ' · ' . $veh['brand_model'],
                    'subtitle' => $veh['vehicle_type'] . ' · ทะเบียน ' . $veh['plate_number'],
                    'badge'    => (string)$stMeta['label'],
                    'rows'     => [
                        ['label' => 'ทะเบียนรถ',           'value' => (string)$veh['plate_number']],
                        ['label' => 'ประเภทรถ',           'value' => (string)$veh['vehicle_type']],
                        ['label' => 'จำนวนที่นั่ง',         'value' => (int)$veh['seats'] . ' ที่นั่ง'],
                        ['label' => 'ประวัติการจองในระบบ', 'value' => (int)$veh['booking_count'] . ' รายการ'],
                    ],
                    'noteTitle' => 'หมายเหตุ',
                    'note'      => (string)($veh['notes'] ?: ''),
                ];
                $vehJson = [
                    'id'           => (int)$veh['id'],
                    'vehicle_code' => (string)$veh['vehicle_code'],
                    'plate_number' => (string)$veh['plate_number'],
                    'vehicle_type' => (string)$veh['vehicle_type'],
                    'brand_model'  => (string)$veh['brand_model'],
                    'seats'        => (int)$veh['seats'],
                    'status'       => $stCode,
                    'notes'        => (string)($veh['notes'] ?? ''),
                ];
            ?>
                <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" data-testid="vehicle-row-<?= e($veh['vehicle_code']) ?>">
                    <div class="min-w-0 flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($veh['vehicle_code']) ?></span>
                            <span class="fw-bold text-dark"><?= e($veh['brand_model']) ?></span>
                            <span class="badge text-bg-primary bg-opacity-75"><?= e($veh['vehicle_type']) ?></span>
                            <span class="badge <?= e($stMeta['badge']) ?>"><?= e($stMeta['label']) ?></span>
                        </div>
                        <div class="small text-muted d-flex flex-wrap gap-3">
                            <span><i class="bi bi-credit-card-2-front me-1"></i>ทะเบียน <?= e($veh['plate_number']) ?></span>
                            <span><i class="bi bi-people me-1"></i><?= (int)$veh['seats'] ?> ที่นั่ง</span>
                            <?php if (!empty($veh['notes'])): ?>
                                <span class="text-truncate"><?= e($veh['notes']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-primary rounded-3 px-2"
                                title="ดูรายละเอียด"
                                data-swal-detail="<?= e((string)json_encode($vehDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary rounded-3 px-3 btn-edit-vehicle"
                                data-json="<?= e((string)json_encode($vehJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข
                        </button>
                        <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_vehicle_status">
                            <input type="hidden" name="return_tab" value="vehicles">
                            <input type="hidden" name="id" value="<?= (int)$veh['id'] ?>">
                            <select class="form-select form-select-sm d-inline-block w-auto rounded-3" name="status" onchange="this.form.submit()" aria-label="สถานะรถ">
                                <?php foreach ($vehicleStatuses as $sk => $sm): ?>
                                    <option value="<?= e($sk) ?>" <?= $stCode === $sk ? 'selected' : '' ?>><?= e($sm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="post"
                              action="<?= e(app_url('/resources_manage.php')) ?>"
                              class="d-inline"
                              data-confirm="ต้องการลบรถ &quot;<?= e($veh['brand_model'] . ' (' . $veh['plate_number'] . ')') ?>&quot; ใช่หรือไม่?"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_vehicle">
                            <input type="hidden" name="return_tab" value="vehicles">
                            <input type="hidden" name="id" value="<?= (int)$veh['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบรถ">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <!-- ===================================================================
         TAB 4: DRIVERS
         =================================================================== -->
    <?php elseif ($tab === 'drivers'): ?>
        <?php if (empty($drivers)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-person-badge fs-1 d-block mb-2 opacity-50"></i>
                <div class="fw-semibold text-dark mb-1">ยังไม่มีข้อมูลพนักงานขับรถในระบบ</div>
                <div class="small mb-3">เพิ่มรายชื่อพนักงานขับรถเพื่อใช้จัดสรรร่วมกับยานพาหนะเมื่อมีการอนุมัติ</div>
                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold" onclick="document.getElementById('btnAddDriver')?.click()">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มพนักงานขับรถแรกเลย
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($drivers as $drv):
                $stCode = (string)$drv['status'];
                $stMeta = $driverStatuses[$stCode] ?? ['label' => $stCode, 'badge' => 'text-bg-secondary'];
                $drvDetail = [
                    'title'    => $drv['driver_code'] . ' · ' . $drv['full_name'],
                    'subtitle' => 'พนักงานขับรถราชการ',
                    'badge'    => (string)$stMeta['label'],
                    'rows'     => [
                        ['label' => 'เบอร์โทรศัพท์',        'value' => (string)$drv['phone']],
                        ['label' => 'เลขใบอนุญาตขับขี่',   'value' => (string)($drv['license_number'] ?: '-')],
                        ['label' => 'ประวัติการขับรถในระบบ', 'value' => (int)$drv['booking_count'] . ' รายการ'],
                    ],
                ];
                $drvJson = [
                    'id'             => (int)$drv['id'],
                    'driver_code'    => (string)$drv['driver_code'],
                    'full_name'      => (string)$drv['full_name'],
                    'phone'          => (string)$drv['phone'],
                    'license_number' => (string)($drv['license_number'] ?? ''),
                    'status'         => $stCode,
                ];
            ?>
                <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" data-testid="driver-row-<?= e($drv['driver_code']) ?>">
                    <div class="min-w-0 flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($drv['driver_code']) ?></span>
                            <span class="fw-bold text-dark"><?= e($drv['full_name']) ?></span>
                            <span class="badge <?= e($stMeta['badge']) ?>"><?= e($stMeta['label']) ?></span>
                        </div>
                        <div class="small text-muted d-flex flex-wrap gap-3">
                            <span><i class="bi bi-telephone me-1"></i><?= e($drv['phone']) ?></span>
                            <?php if (!empty($drv['license_number'])): ?>
                                <span><i class="bi bi-card-heading me-1"></i>ใบขับขี่ <?= e($drv['license_number']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-soft-primary rounded-3 px-2"
                                title="ดูรายละเอียด"
                                data-swal-detail="<?= e((string)json_encode($drvDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary rounded-3 px-3 btn-edit-driver"
                                data-json="<?= e((string)json_encode($drvJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข
                        </button>
                        <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_driver_status">
                            <input type="hidden" name="return_tab" value="drivers">
                            <input type="hidden" name="id" value="<?= (int)$drv['id'] ?>">
                            <select class="form-select form-select-sm d-inline-block w-auto rounded-3" name="status" onchange="this.form.submit()" aria-label="สถานะพนักงานขับรถ">
                                <?php foreach ($driverStatuses as $sk => $sm): ?>
                                    <option value="<?= e($sk) ?>" <?= $stCode === $sk ? 'selected' : '' ?>><?= e($sm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="post"
                              action="<?= e(app_url('/resources_manage.php')) ?>"
                              class="d-inline"
                              data-confirm="ต้องการลบพนักงานขับรถ &quot;<?= e($drv['full_name']) ?>&quot; ใช่หรือไม่?"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_driver">
                            <input type="hidden" name="return_tab" value="drivers">
                            <input type="hidden" name="id" value="<?= (int)$drv['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบพนักงานขับรถ">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- =======================================================================
     MODAL 1: BUILDING ADD / EDIT
     ======================================================================= -->
<div class="modal fade" id="buildingModal" tabindex="-1" aria-labelledby="buildingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="buildingModalTitle">เพิ่มอาคารใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" id="buildingForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="bld_action" value="create_building">
                <input type="hidden" name="return_tab" value="buildings">
                <input type="hidden" name="id" id="bld_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="bld_code" class="form-label fw-semibold small text-secondary">รหัสอาคาร <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="bld_code" name="building_code" placeholder="เช่น BLD-01" required>
                        </div>
                        <div class="col-7">
                            <label for="bld_floors" class="form-label fw-semibold small text-secondary">จำนวนชั้น <span class="text-danger">*</span></label>
                            <input type="number" class="form-control touch-target" id="bld_floors" name="floors_count" min="1" value="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="bld_name" class="form-label fw-semibold small text-secondary">ชื่ออาคาร <span class="text-danger">*</span></label>
                        <input type="text" class="form-control touch-target" id="bld_name" name="name" placeholder="เช่น อาคารอำนวยการ / อาคาร 1" required>
                    </div>
                    <div class="mb-3">
                        <label for="bld_desc" class="form-label fw-semibold small text-secondary">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" id="bld_desc" name="description" rows="2" placeholder="ระบุรายละเอียดที่ตั้งหรือลักษณะอาคาร (ถ้ามี)"></textarea>
                    </div>
                    <div class="mb-0">
                        <label for="bld_image_url" class="form-label fw-semibold small text-secondary">ลิงก์รูปภาพอาคาร (URL)</label>
                        <input type="url" class="form-control touch-target" id="bld_image_url" name="image_url" placeholder="https://... หรือลิงก์แชร์ Google Drive" maxlength="500">
                        <div class="form-text small text-muted">เก็บเฉพาะลิงก์รูปภาพในฐานข้อมูล ไม่เปลืองพื้นที่โฮสติ้ง · รองรับลิงก์ภาพโดยตรงและ Google Drive (ตั้งค่าแชร์เป็นทุกคนที่มีลิงก์)</div>
                        <div class="url-img-preview-box mt-2 d-none" id="bld_img_preview_wrap">
                            <img src="" alt="ตัวอย่างรูปภาพอาคาร" id="bld_img_preview">
                            <div class="small text-danger mt-1 d-none" id="bld_img_preview_err">ไม่สามารถโหลดตัวอย่างภาพจากลิงก์นี้ได้ กรุณาตรวจสอบลิงก์หรือสิทธิ์การแชร์</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" id="bld_submit">บันทึกข้อมูลอาคาร</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =======================================================================
     MODAL 2: ROOM ADD / EDIT
     ======================================================================= -->
<div class="modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="roomModalTitle">เพิ่มห้องประชุม / ห้องใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" id="roomForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="rm_action" value="create_room">
                <input type="hidden" name="return_tab" value="rooms">
                <input type="hidden" name="id" id="rm_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="rm_code" class="form-label fw-semibold small text-secondary">รหัสห้อง <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="rm_code" name="room_code" placeholder="เช่น RM-101" required>
                        </div>
                        <div class="col-7">
                            <label for="rm_capacity" class="form-label fw-semibold small text-secondary">ความจุ (คน) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control touch-target" id="rm_capacity" name="capacity" min="1" value="30" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="rm_name" class="form-label fw-semibold small text-secondary">ชื่อห้องประชุม / ห้องเรียน <span class="text-danger">*</span></label>
                        <input type="text" class="form-control touch-target" id="rm_name" name="name" placeholder="เช่น ห้องประชุมราชพฤกษ์ / ห้องเรียน ม.1/1" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label for="rm_building_select" class="form-label fw-semibold small text-secondary">อาคารสถานที่</label>
                            <select class="form-select touch-target" id="rm_building_select" name="building_select">
                                <option value="">-- ไม่ระบุ / เลือกอาคาร --</option>
                                <?php foreach ($buildingNamesList as $bn): ?>
                                    <option value="<?= e($bn) ?>"><?= e($bn) ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">+ พิมพ์ชื่ออาคารเอง...</option>
                            </select>
                            <div class="mt-2 d-none" id="rmBuildingCustomWrap">
                                <input type="text" class="form-control form-control-sm" id="rm_building_custom" name="building_custom" placeholder="พิมพ์ชื่ออาคาร...">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label for="rm_floor" class="form-label fw-semibold small text-secondary">ชั้น / ระดับชั้น</label>
                            <input type="text" class="form-control touch-target" id="rm_floor" name="floor" list="floorSuggestions" placeholder="เช่น ชั้น 1 หรือ ม.1">
                            <datalist id="floorSuggestions">
                                <option value="ชั้น 1">
                                <option value="ชั้น 2">
                                <option value="ชั้น 3">
                                <option value="ชั้น 4">
                                <option value="ม.1">
                                <option value="ม.2">
                                <option value="ม.3">
                                <option value="ม.4">
                                <option value="ม.5">
                                <option value="ม.6">
                                <option value="ลานเอนกประสงค์">
                            </datalist>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary d-block">อุปกรณ์ประจำห้อง</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (ROOM_EQUIPMENT_LABELS as $eqKey => $eqLabel): ?>
                                <label class="btn btn-sm btn-outline-secondary rounded-pill d-inline-flex align-items-center gap-2 px-3 py-1 m-0" style="cursor: pointer;">
                                    <input class="form-check-input m-0 rm-eq-checkbox" type="checkbox" name="available_equipment[]" value="<?= e($eqKey) ?>">
                                    <span class="small"><?= e($eqLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="rm_allow_ext" name="allow_external" value="1">
                        <label class="form-check-label small fw-semibold text-dark" for="rm_allow_ext">อนุญาตให้บุคคล / หน่วยงานภายนอกขอจองใช้ได้</label>
                    </div>

                    <div class="mb-3">
                        <label for="rm_desc" class="form-label fw-semibold small text-secondary">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" id="rm_desc" name="description" rows="2" placeholder="ข้อมูลเพิ่มเติมเกี่ยวกับห้อง (ถ้ามี)"></textarea>
                    </div>
                    <div class="mb-0">
                        <label for="rm_image_url" class="form-label fw-semibold small text-secondary">ลิงก์รูปภาพห้อง (ไม่บังคับ)</label>
                        <input type="url" class="form-control touch-target" id="rm_image_url" name="image_url" placeholder="เว้นว่างเพื่อใช้รูปของอาคารโดยอัตโนมัติ" maxlength="500">
                        <div class="form-text small text-muted">หากระบุลิงก์ภาพเฉพาะของห้อง ระบบจะแสดงรูปห้องนี้ตอนจอง (หากเว้นว่างจะแสดงรูปของอาคารแทน)</div>
                        <div class="url-img-preview-box mt-2 d-none" id="rm_img_preview_wrap">
                            <img src="" alt="ตัวอย่างรูปภาพห้อง" id="rm_img_preview">
                            <div class="small text-danger mt-1 d-none" id="rm_img_preview_err">ไม่สามารถโหลดตัวอย่างภาพจากลิงก์นี้ได้</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" id="rm_submit">บันทึกข้อมูลห้อง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =======================================================================
     MODAL 3: VEHICLE ADD / EDIT
     ======================================================================= -->
<div class="modal fade" id="vehicleModal" tabindex="-1" aria-labelledby="vehicleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="vehicleModalTitle">เพิ่มยานพาหนะใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" id="vehicleForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="veh_action" value="create_vehicle">
                <input type="hidden" name="return_tab" value="vehicles">
                <input type="hidden" name="id" id="veh_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="veh_code" class="form-label fw-semibold small text-secondary">รหัสรถ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="veh_code" name="vehicle_code" placeholder="เช่น VEH-01" required>
                        </div>
                        <div class="col-7">
                            <label for="veh_plate" class="form-label fw-semibold small text-secondary">หมายเลขทะเบียน <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="veh_plate" name="plate_number" placeholder="เช่น นข 1234 พิจิตร" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="veh_type" class="form-label fw-semibold small text-secondary">ประเภทรถ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="veh_type" name="vehicle_type" list="vehicleTypeSuggestions" placeholder="เช่น รถตู้, รถเก๋ง" required>
                            <datalist id="vehicleTypeSuggestions">
                                <option value="รถตู้">
                                <option value="รถเก๋ง">
                                <option value="รถกระบะ">
                                <option value="รถบัส">
                                <option value="รถอเนกประสงค์ (SUV)">
                                <option value="รถหกล้อ">
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label for="veh_seats" class="form-label fw-semibold small text-secondary">จำนวนที่นั่ง <span class="text-danger">*</span></label>
                            <input type="number" class="form-control touch-target" id="veh_seats" name="seats" min="1" value="12" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label for="veh_brand" class="form-label fw-semibold small text-secondary">ยี่ห้อ / รุ่นรถ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="veh_brand" name="brand_model" placeholder="เช่น Toyota Commuter 2.8" required>
                        </div>
                        <div class="col-md-5">
                            <label for="veh_status" class="form-label fw-semibold small text-secondary">สถานะรถ</label>
                            <select class="form-select touch-target" id="veh_status" name="status">
                                <?php foreach ($vehicleStatuses as $sk => $sm): ?>
                                    <option value="<?= e($sk) ?>"><?= e($sm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="veh_notes" class="form-label fw-semibold small text-secondary">หมายเหตุเพิ่มเติม</label>
                        <textarea class="form-control" id="veh_notes" name="notes" rows="2" placeholder="ระบุหมายเหตุการใช้งาน (ถ้ามี)"></textarea>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" id="veh_submit">บันทึกข้อมูลยานพาหนะ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =======================================================================
     MODAL 4: DRIVER ADD / EDIT
     ======================================================================= -->
<div class="modal fade" id="driverModal" tabindex="-1" aria-labelledby="driverModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="driverModalTitle">เพิ่มพนักงานขับรถใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/resources_manage.php')) ?>" id="driverForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="drv_action" value="create_driver">
                <input type="hidden" name="return_tab" value="drivers">
                <input type="hidden" name="id" id="drv_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label for="drv_code" class="form-label fw-semibold small text-secondary">รหัสคนขับ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="drv_code" name="driver_code" placeholder="เช่น DRV-01" required>
                        </div>
                        <div class="col-7">
                            <label for="drv_status" class="form-label fw-semibold small text-secondary">สถานะปฏิบัติงาน</label>
                            <select class="form-select touch-target" id="drv_status" name="status">
                                <?php foreach ($driverStatuses as $sk => $sm): ?>
                                    <option value="<?= e($sk) ?>"><?= e($sm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="drv_name" class="form-label fw-semibold small text-secondary">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control touch-target" id="drv_name" name="full_name" placeholder="ระบุชื่อ-นามสกุลพนักงานขับรถ" required>
                    </div>

                    <div class="row g-3 mb-0">
                        <div class="col-md-6">
                            <label for="drv_phone" class="form-label fw-semibold small text-secondary">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control touch-target" id="drv_phone" name="phone" placeholder="08x-xxx-xxxx" required>
                        </div>
                        <div class="col-md-6">
                            <label for="drv_license" class="form-label fw-semibold small text-secondary">เลขที่ใบอนุญาตขับขี่</label>
                            <input type="text" class="form-control touch-target" id="drv_license" name="license_number" placeholder="ระบุเลขใบขับขี่ (ถ้ามี)">
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" id="drv_submit">บันทึกพนักงานขับรถ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const activeTab = <?= json_encode($tab) ?>;

    const bldModalEl = document.getElementById('buildingModal');
    const rmModalEl  = document.getElementById('roomModal');
    const vehModalEl = document.getElementById('vehicleModal');
    const drvModalEl = document.getElementById('driverModal');

    const bldModal = bldModalEl ? bootstrap.Modal.getOrCreateInstance(bldModalEl) : null;
    const rmModal  = rmModalEl  ? bootstrap.Modal.getOrCreateInstance(rmModalEl)  : null;
    const vehModal = vehModalEl ? bootstrap.Modal.getOrCreateInstance(vehModalEl) : null;
    const drvModal = drvModalEl ? bootstrap.Modal.getOrCreateInstance(drvModalEl) : null;

    const rmBldSel   = document.getElementById('rm_building_select');
    const rmBldWrap  = document.getElementById('rmBuildingCustomWrap');
    const rmBldInput = document.getElementById('rm_building_custom');

    function syncRoomBuildingCustom() {
        if (!rmBldSel || !rmBldWrap) return;
        const isCustom = rmBldSel.value === '__custom__';
        rmBldWrap.classList.toggle('d-none', !isCustom);
        if (isCustom && rmBldInput) {
            rmBldInput.focus();
        }
    }
    if (rmBldSel) {
        rmBldSel.addEventListener('change', syncRoomBuildingCustom);
    }

    function normalizePreviewUrl(raw) {
        const val = String(raw || '').trim();
        if (!val || !/^https?:\/\//i.test(val)) return '';
        let m = val.match(/^https?:\/\/drive\.google\.com\/file\/d\/([A-Za-z0-9_-]{10,})/i);
        if (m) return 'https://drive.google.com/thumbnail?id=' + m[1] + '&sz=w1000';
        m = val.match(/^https?:\/\/drive\.google\.com\/(?:open|uc)\b.*[?&]id=([A-Za-z0-9_-]{10,})/i);
        if (m) return 'https://drive.google.com/thumbnail?id=' + m[1] + '&sz=w1000';
        return val;
    }

    function bindImagePreview(inputId, wrapId, imgId, errId) {
        const inputEl = document.getElementById(inputId);
        const wrapEl  = document.getElementById(wrapId);
        const imgEl   = document.getElementById(imgId);
        const errEl   = document.getElementById(errId);
        if (!inputEl || !wrapEl || !imgEl) return function () {};

        imgEl.addEventListener('error', function () {
            imgEl.classList.add('d-none');
            if (errEl) errEl.classList.remove('d-none');
        });
        imgEl.addEventListener('load', function () {
            imgEl.classList.remove('d-none');
            if (errEl) errEl.classList.add('d-none');
        });

        function refresh() {
            const url = normalizePreviewUrl(inputEl.value);
            if (!url) {
                wrapEl.classList.add('d-none');
                imgEl.removeAttribute('src');
                if (errEl) errEl.classList.add('d-none');
                return;
            }
            wrapEl.classList.remove('d-none');
            imgEl.classList.remove('d-none');
            if (errEl) errEl.classList.add('d-none');
            imgEl.src = url;
        }

        inputEl.addEventListener('input', refresh);
        inputEl.addEventListener('change', refresh);
        return refresh;
    }

    const refreshBldPreview = bindImagePreview('bld_image_url', 'bld_img_preview_wrap', 'bld_img_preview', 'bld_img_preview_err');
    const refreshRmPreview  = bindImagePreview('rm_image_url', 'rm_img_preview_wrap', 'rm_img_preview', 'rm_img_preview_err');

    function openAddBuilding() {
        const f = document.getElementById('buildingForm');
        if (f) f.reset();
        document.getElementById('bld_action').value = 'create_building';
        document.getElementById('bld_id').value = '';
        document.getElementById('bld_image_url').value = '';
        document.getElementById('buildingModalTitle').textContent = 'เพิ่มอาคารใหม่';
        document.getElementById('bld_submit').textContent = 'บันทึกข้อมูลอาคาร';
        refreshBldPreview();
        if (bldModal) bldModal.show();
    }

    function openAddRoom() {
        const f = document.getElementById('roomForm');
        if (f) f.reset();
        document.getElementById('rm_action').value = 'create_room';
        document.getElementById('rm_id').value = '';
        document.getElementById('rm_image_url').value = '';
        document.getElementById('roomModalTitle').textContent = 'เพิ่มห้องประชุม / ห้องใหม่';
        document.getElementById('rm_submit').textContent = 'บันทึกข้อมูลห้อง';
        document.querySelectorAll('.rm-eq-checkbox').forEach(cb => { cb.checked = false; });
        syncRoomBuildingCustom();
        refreshRmPreview();
        if (rmModal) rmModal.show();
    }

    function openAddVehicle() {
        const f = document.getElementById('vehicleForm');
        if (f) f.reset();
        document.getElementById('veh_action').value = 'create_vehicle';
        document.getElementById('veh_id').value = '';
        document.getElementById('vehicleModalTitle').textContent = 'เพิ่มยานพาหนะใหม่';
        document.getElementById('veh_submit').textContent = 'บันทึกข้อมูลยานพาหนะ';
        if (vehModal) vehModal.show();
    }

    function openAddDriver() {
        const f = document.getElementById('driverForm');
        if (f) f.reset();
        document.getElementById('drv_action').value = 'create_driver';
        document.getElementById('drv_id').value = '';
        document.getElementById('driverModalTitle').textContent = 'เพิ่มพนักงานขับรถใหม่';
        document.getElementById('drv_submit').textContent = 'บันทึกพนักงานขับรถ';
        if (drvModal) drvModal.show();
    }

    const topQuickAdd = document.getElementById('btnTopQuickAdd');
    if (topQuickAdd) {
        topQuickAdd.addEventListener('click', function () {
            if (activeTab === 'buildings') openAddBuilding();
            else if (activeTab === 'vehicles') openAddVehicle();
            else if (activeTab === 'drivers') openAddDriver();
            else openAddRoom();
        });
    }

    document.getElementById('btnAddBuilding')?.addEventListener('click', openAddBuilding);
    document.getElementById('btnAddRoom')?.addEventListener('click', openAddRoom);
    document.getElementById('btnAddVehicle')?.addEventListener('click', openAddVehicle);
    document.getElementById('btnAddDriver')?.addEventListener('click', openAddDriver);

    document.querySelectorAll('.btn-edit-building').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.getAttribute('data-json') || '{}');
            document.getElementById('bld_action').value = 'update_building';
            document.getElementById('bld_id').value = d.id || '';
            document.getElementById('bld_code').value = d.building_code || '';
            document.getElementById('bld_name').value = d.name || '';
            document.getElementById('bld_floors').value = d.floors_count || 1;
            document.getElementById('bld_desc').value = d.description || '';
            document.getElementById('bld_image_url').value = d.image_url || '';
            document.getElementById('buildingModalTitle').textContent = 'แก้ไขข้อมูลอาคาร';
            document.getElementById('bld_submit').textContent = 'บันทึกการเปลี่ยนแปลง';
            refreshBldPreview();
            if (bldModal) bldModal.show();
        });
    });

    document.querySelectorAll('.btn-edit-room').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.getAttribute('data-json') || '{}');
            document.getElementById('rm_action').value = 'update_room';
            document.getElementById('rm_id').value = d.id || '';
            document.getElementById('rm_code').value = d.room_code || '';
            document.getElementById('rm_name').value = d.name || '';
            document.getElementById('rm_floor').value = d.floor || '';
            document.getElementById('rm_capacity').value = d.capacity || 10;
            document.getElementById('rm_allow_ext').checked = Number(d.allow_external) === 1;
            document.getElementById('rm_desc').value = d.description || '';
            document.getElementById('rm_image_url').value = d.image_url || '';

            const bVal = d.building || '';
            if (rmBldSel) {
                const hasOpt = [...rmBldSel.options].some(o => o.value === bVal);
                if (bVal === '') {
                    rmBldSel.value = '';
                    if (rmBldInput) rmBldInput.value = '';
                } else if (hasOpt) {
                    rmBldSel.value = bVal;
                    if (rmBldInput) rmBldInput.value = '';
                } else {
                    rmBldSel.value = '__custom__';
                    if (rmBldInput) rmBldInput.value = bVal;
                }
                syncRoomBuildingCustom();
            }

            const eqArr = Array.isArray(d.available_equipment) ? d.available_equipment : [];
            document.querySelectorAll('.rm-eq-checkbox').forEach(function (cb) {
                cb.checked = eqArr.includes(cb.value);
            });

            document.getElementById('roomModalTitle').textContent = 'แก้ไขข้อมูลห้อง';
            document.getElementById('rm_submit').textContent = 'บันทึกการเปลี่ยนแปลง';
            refreshRmPreview();
            if (rmModal) rmModal.show();
        });
    });

    document.querySelectorAll('.btn-edit-vehicle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.getAttribute('data-json') || '{}');
            document.getElementById('veh_action').value = 'update_vehicle';
            document.getElementById('veh_id').value = d.id || '';
            document.getElementById('veh_code').value = d.vehicle_code || '';
            document.getElementById('veh_plate').value = d.plate_number || '';
            document.getElementById('veh_type').value = d.vehicle_type || '';
            document.getElementById('veh_brand').value = d.brand_model || '';
            document.getElementById('veh_seats').value = d.seats || 4;
            document.getElementById('veh_status').value = d.status || 'available';
            document.getElementById('veh_notes').value = d.notes || '';
            document.getElementById('vehicleModalTitle').textContent = 'แก้ไขข้อมูลยานพาหนะ';
            document.getElementById('veh_submit').textContent = 'บันทึกการเปลี่ยนแปลง';
            if (vehModal) vehModal.show();
        });
    });

    document.querySelectorAll('.btn-edit-driver').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.getAttribute('data-json') || '{}');
            document.getElementById('drv_action').value = 'update_driver';
            document.getElementById('drv_id').value = d.id || '';
            document.getElementById('drv_code').value = d.driver_code || '';
            document.getElementById('drv_name').value = d.full_name || '';
            document.getElementById('drv_phone').value = d.phone || '';
            document.getElementById('drv_license').value = d.license_number || '';
            document.getElementById('drv_status').value = d.status || 'available';
            document.getElementById('driverModalTitle').textContent = 'แก้ไขข้อมูลพนักงานขับรถ';
            document.getElementById('drv_submit').textContent = 'บันทึกการเปลี่ยนแปลง';
            if (drvModal) drvModal.show();
        });
    });
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
