<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_role(ROLE_ADMIN);

$pageTitle = 'จัดการผู้ใช้งาน';
$activeNav = 'users_manage';

$allRoles = [
    ROLE_USER          => 'บุคลากรภายใน',
    ROLE_HEAD          => 'หัวหน้างาน',
    ROLE_VICE_DIRECTOR => 'รองผู้อำนวยการ',
    ROLE_DIRECTOR      => 'ผู้อำนวยการ',
    ROLE_STAFF         => 'เจ้าหน้าที่บริหาร/ช่าง',
    ROLE_ADMIN         => 'ผู้ดูแลระบบ',
    ROLE_EXTERNAL_USER => 'บุคคลภายนอก',
];

$roleBadgeClasses = [
    ROLE_ADMIN         => 'text-bg-dark',
    ROLE_DIRECTOR      => 'text-bg-primary',
    ROLE_VICE_DIRECTOR => 'text-bg-info text-dark',
    ROLE_HEAD          => 'text-bg-warning text-dark',
    ROLE_STAFF         => 'text-bg-success',
    ROLE_USER          => 'text-bg-secondary',
    ROLE_EXTERNAL_USER => 'text-bg-light border text-secondary',
];

$createErrors = [];
$openAddModal = false;
$createForm = [
    'user_type'      => USER_TYPE_INTERNAL,
    'role'           => ROLE_USER,
    'username'       => '',
    'staff_id'       => '',
    'full_name'      => '',
    'position_title' => '',
    'department'     => '',
    'organization'   => APP_ORG_NAME,
    'phone'          => '',
    'email'          => '',
];

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create_user') {
        $openAddModal = true;
        $createForm['user_type']      = trim((string)($_POST['user_type'] ?? USER_TYPE_INTERNAL));
        $createForm['role']           = trim((string)($_POST['role'] ?? ROLE_USER));
        $createForm['username']       = trim((string)($_POST['username'] ?? ''));
        $createForm['staff_id']       = trim((string)($_POST['staff_id'] ?? ''));
        $createForm['full_name']      = trim((string)($_POST['full_name'] ?? ''));
        $createForm['position_title'] = trim((string)($_POST['position_title'] ?? ''));
        $createForm['department']     = trim((string)($_POST['department'] ?? ''));
        $createForm['organization']   = trim((string)($_POST['organization'] ?? ''));
        $createForm['phone']          = trim((string)($_POST['phone'] ?? ''));
        $createForm['email']          = strtolower(trim((string)($_POST['email'] ?? '')));
        $password                     = (string)($_POST['password'] ?? '');

        if (!in_array($createForm['user_type'], [USER_TYPE_INTERNAL, USER_TYPE_EXTERNAL], true)) {
            $createForm['user_type'] = USER_TYPE_INTERNAL;
        }

        if ($createForm['user_type'] === USER_TYPE_EXTERNAL) {
            $createForm['role'] = ROLE_EXTERNAL_USER;
            $createForm['staff_id'] = '';
        } elseif (!array_key_exists($createForm['role'], $allRoles) || $createForm['role'] === ROLE_EXTERNAL_USER) {
            $createForm['role'] = ROLE_USER;
        }

        if ($createForm['full_name'] === '') {
            $createErrors[] = 'กรุณากรอกชื่อ-นามสกุล';
        }
        if ($createForm['username'] === '' || preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $createForm['username']) !== 1) {
            $createErrors[] = 'ชื่อผู้ใช้ (Username) ต้องเป็นตัวอักษรภาษาอังกฤษ ตัวเลข หรือ . _ @ - ความยาว 3-100 ตัวอักษร';
        }
        if ($createForm['email'] === '' || filter_var($createForm['email'], FILTER_VALIDATE_EMAIL) === false) {
            $createErrors[] = 'กรุณากรอกอีเมลที่ถูกต้อง';
        }
        if (mb_strlen($password) < 8) {
            $createErrors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
        }

        if (empty($createErrors)) {
            try {
                $dupCheck = $pdo->prepare('SELECT username, email, staff_id FROM users WHERE username = :u OR email = :e OR (:s1 IS NOT NULL AND staff_id = :s2) LIMIT 1');
                $staffVal = ($createForm['staff_id'] !== '') ? mb_substr($createForm['staff_id'], 0, 50) : null;
                $dupCheck->execute([
                    ':u'  => $createForm['username'],
                    ':e'  => $createForm['email'],
                    ':s1' => $staffVal,
                    ':s2' => $staffVal,
                ]);
                $existing = $dupCheck->fetch();
                if ($existing) {
                    if (strcasecmp((string)$existing['username'], $createForm['username']) === 0) {
                        $createErrors[] = 'ชื่อผู้ใช้ "' . $createForm['username'] . '" มีอยู่ในระบบแล้ว';
                    } elseif (strcasecmp((string)$existing['email'], $createForm['email']) === 0) {
                        $createErrors[] = 'อีเมล "' . $createForm['email'] . '" มีอยู่ในระบบแล้ว';
                    } else {
                        $createErrors[] = 'รหัสพนักงาน "' . $createForm['staff_id'] . '" มีอยู่ในระบบแล้ว';
                    }
                } else {
                    $stmtIns = $pdo->prepare(
                        'INSERT INTO users (
                            user_type, role, username, staff_id, password_hash,
                            full_name, position_title, department, organization,
                            phone, email, line_user_id, is_active
                        ) VALUES (
                            :user_type, :role, :username, :staff_id, :password_hash,
                            :full_name, :position_title, :department, :organization,
                            :phone, :email, NULL, 1
                        )'
                    );
                    $stmtIns->execute([
                        ':user_type'      => $createForm['user_type'],
                        ':role'           => $createForm['role'],
                        ':username'       => mb_substr($createForm['username'], 0, 100),
                        ':staff_id'       => $staffVal,
                        ':password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                        ':full_name'      => mb_substr($createForm['full_name'], 0, 150),
                        ':position_title' => $createForm['position_title'] !== '' ? mb_substr($createForm['position_title'], 0, 150) : null,
                        ':department'     => $createForm['department'] !== '' ? mb_substr($createForm['department'], 0, 150) : null,
                        ':organization'   => $createForm['organization'] !== '' ? mb_substr($createForm['organization'], 0, 200) : null,
                        ':phone'          => $createForm['phone'] !== '' ? mb_substr($createForm['phone'], 0, 30) : null,
                        ':email'          => mb_substr($createForm['email'], 0, 150),
                    ]);

                    flash('success', 'เพิ่มผู้ใช้งาน "' . $createForm['full_name'] . '" (' . ($allRoles[$createForm['role']] ?? $createForm['role']) . ') เรียบร้อยแล้ว');
                    redirect(app_url('/users_manage.php'));
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $createErrors[] = 'ข้อมูลชื่อผู้ใช้ อีเมล หรือรหัสพนักงานซ้ำในระบบ';
                } else {
                    $createErrors[] = 'ไม่สามารถบันทึกข้อมูลผู้ใช้งานได้ในขณะนี้';
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            flash('danger', 'ไม่พบข้อมูลผู้ใช้งาน');
        } elseif ($targetId === (int)$user['id']) {
            flash('warning', 'ไม่สามารถระงับบัญชีผู้ดูแลระบบที่กำลังใช้งานอยู่ได้');
        } else {
            $stmtToggle = $pdo->prepare('UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id');
            $stmtToggle->execute([':id' => $targetId]);
            flash('success', 'อัปเดตสถานะการใช้งานบัญชีเรียบร้อยแล้ว');
        }
        redirect(app_url('/users_manage.php'));
    } elseif ($action === 'update_user') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newRole = trim((string)($_POST['role'] ?? ''));
        $newFullName = trim((string)($_POST['full_name'] ?? ''));
        $newPosition = trim((string)($_POST['position_title'] ?? ''));
        $newDepartment = trim((string)($_POST['department'] ?? ''));
        $newOrg = trim((string)($_POST['organization'] ?? ''));
        $newPhone = trim((string)($_POST['phone'] ?? ''));
        $newEmail = strtolower(trim((string)($_POST['email'] ?? '')));
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($targetId <= 0 || $newFullName === '' || !array_key_exists($newRole, $allRoles)) {
            flash('danger', 'ข้อมูลที่แก้ไขไม่ถูกต้อง');
            redirect(app_url('/users_manage.php'));
        }
        if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            flash('danger', 'กรุณากรอกอีเมล / Gmail ให้ถูกต้อง');
            redirect(app_url('/users_manage.php'));
        }
        $dupEmailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
        $dupEmailStmt->execute([':e' => $newEmail, ':id' => $targetId]);
        if ($dupEmailStmt->fetch()) {
            flash('danger', 'อีเมล "' . $newEmail . '" มีอยู่ในระบบแล้ว');
            redirect(app_url('/users_manage.php'));
        }
        if ($targetId === (int)$user['id'] && $newRole !== ROLE_ADMIN) {
            flash('warning', 'ไม่สามารถเปลี่ยนบทบาทบัญชีผู้ดูแลระบบของตนเองได้');
            redirect(app_url('/users_manage.php'));
        }
        if ($newPassword !== '' && mb_strlen($newPassword) < 8) {
            flash('danger', 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 8 ตัวอักษร');
            redirect(app_url('/users_manage.php'));
        }

        $newUserType = ($newRole === ROLE_EXTERNAL_USER) ? USER_TYPE_EXTERNAL : USER_TYPE_INTERNAL;
        if ($newPassword !== '') {
            $stmtUpd = $pdo->prepare(
                'UPDATE users
                 SET user_type = :ut, role = :r, full_name = :fn, position_title = :pt,
                     department = :dp, organization = :org, phone = :ph, email = :em, password_hash = :pw
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':ut'  => $newUserType,
                ':r'   => $newRole,
                ':fn'  => mb_substr($newFullName, 0, 150),
                ':pt'  => $newPosition !== '' ? mb_substr($newPosition, 0, 150) : null,
                ':dp'  => $newDepartment !== '' ? mb_substr($newDepartment, 0, 150) : null,
                ':org' => $newOrg !== '' ? mb_substr($newOrg, 0, 200) : null,
                ':ph'  => $newPhone !== '' ? mb_substr($newPhone, 0, 30) : null,
                ':em'  => mb_substr($newEmail, 0, 150),
                ':pw'  => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id'  => $targetId,
            ]);
        } else {
            $stmtUpd = $pdo->prepare(
                'UPDATE users
                 SET user_type = :ut, role = :r, full_name = :fn, position_title = :pt,
                     department = :dp, organization = :org, phone = :ph, email = :em
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':ut'  => $newUserType,
                ':r'   => $newRole,
                ':fn'  => mb_substr($newFullName, 0, 150),
                ':pt'  => $newPosition !== '' ? mb_substr($newPosition, 0, 150) : null,
                ':dp'  => $newDepartment !== '' ? mb_substr($newDepartment, 0, 150) : null,
                ':org' => $newOrg !== '' ? mb_substr($newOrg, 0, 200) : null,
                ':ph'  => $newPhone !== '' ? mb_substr($newPhone, 0, 30) : null,
                ':em'  => mb_substr($newEmail, 0, 150),
                ':id'  => $targetId,
            ]);
        }
        flash('success', 'อัปเดตข้อมูลผู้ใช้งาน "' . $newFullName . '" เรียบร้อยแล้ว');
        redirect(app_url('/users_manage.php'));
    } elseif ($action === 'delete_user') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            flash('danger', 'ไม่พบข้อมูลผู้ใช้งานที่ต้องการลบ');
        } elseif ($targetId === (int)$user['id']) {
            flash('warning', 'ไม่สามารถลบบัญชีผู้ดูแลระบบที่กำลังใช้งานอยู่ได้');
        } else {
            $stmtTarget = $pdo->prepare('SELECT id, full_name, username FROM users WHERE id = :id LIMIT 1');
            $stmtTarget->execute([':id' => $targetId]);
            $targetUser = $stmtTarget->fetch();

            if (!$targetUser) {
                flash('danger', 'ไม่พบบัญชีผู้ใช้งานนี้ในระบบ');
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmtBorrows = $pdo->prepare(
                        "SELECT asset_id, quantity
                         FROM asset_borrowings
                         WHERE borrower_id = :id AND status IN ('borrowed', 'overdue')"
                    );
                    $stmtBorrows->execute([':id' => $targetId]);
                    $openBorrows = $stmtBorrows->fetchAll();

                    if (!empty($openBorrows)) {
                        $stmtRestore = $pdo->prepare(
                            'UPDATE assets
                             SET available_stock = LEAST(total_stock, available_stock + :qty)
                             WHERE id = :aid'
                        );
                        foreach ($openBorrows as $ob) {
                            $stmtRestore->execute([
                                ':qty' => (int)$ob['quantity'],
                                ':aid' => (int)$ob['asset_id'],
                            ]);
                        }
                    }

                    $pdo->prepare('DELETE FROM asset_borrowings WHERE borrower_id = :id')->execute([':id' => $targetId]);
                    $pdo->prepare('DELETE FROM maintenance_requests WHERE reporter_id = :id')->execute([':id' => $targetId]);
                    $pdo->prepare('DELETE FROM calendar_events WHERE created_by = :id')->execute([':id' => $targetId]);
                    $pdo->prepare('DELETE FROM approval_logs WHERE approver_id = :id')->execute([':id' => $targetId]);
                    $pdo->prepare('DELETE FROM bookings WHERE requester_id = :id')->execute([':id' => $targetId]);
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetId]);

                    $pdo->commit();
                    flash('success', 'ลบบัญชีผู้ใช้งาน "' . $targetUser['full_name'] . '" (' . $targetUser['username'] . ') ออกจากระบบเรียบร้อยแล้ว');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('danger', 'ไม่สามารถลบบัญชีผู้ใช้งานได้เนื่องจากยังมีข้อมูลอ้างอิงในระบบ');
                }
            }
        }
        redirect(app_url('/users_manage.php'));
    }
}

$filterRole = trim((string)($_GET['role'] ?? 'all'));
$searchQuery = trim((string)($_GET['q'] ?? ''));

$allUsers = $pdo->query(
    'SELECT id, user_type, role, username, staff_id, full_name, position_title,
            department, organization, phone, email, is_active, created_at
     FROM users
     ORDER BY FIELD(role, "director", "vice_director", "head", "staff", "user", "external_user", "admin"), id ASC'
)->fetchAll();

$summary = [
    'total'    => count($allUsers),
    'internal' => 0,
    'external' => 0,
    'active'   => 0,
];

$filteredUsers = [];
foreach ($allUsers as $row) {
    if ($row['user_type'] === USER_TYPE_INTERNAL) {
        $summary['internal']++;
    } else {
        $summary['external']++;
    }
    if ((int)$row['is_active'] === 1) {
        $summary['active']++;
    }

    if ($filterRole === 'executives') {
        if (!in_array($row['role'], [ROLE_DIRECTOR, ROLE_VICE_DIRECTOR, ROLE_HEAD], true)) {
            continue;
        }
    } elseif ($filterRole !== 'all' && $row['role'] !== $filterRole) {
        continue;
    }

    if ($searchQuery !== '') {
        $haystack = mb_strtolower(
            implode(' ', [
                (string)$row['full_name'],
                (string)$row['username'],
                (string)($row['staff_id'] ?? ''),
                (string)$row['email'],
                (string)($row['department'] ?? ''),
                (string)($row['organization'] ?? ''),
            ]),
            'UTF-8'
        );
        if (!str_contains($haystack, mb_strtolower($searchQuery, 'UTF-8'))) {
            continue;
        }
    }

    $filteredUsers[] = $row;
}

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Header Card -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-shield-lock me-1"></i>ระบบบริหารจัดการสิทธิ์และผู้ใช้งาน
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="users-manage-heading">จัดการผู้ใช้งานระบบ</h1>
            <p class="small text-muted mb-0">เพิ่มบัญชีบุคลากรภายใน ผู้บริหาร เจ้าหน้าที่ และกำหนดบทบาทการเข้าถึงระบบ</p>
        </div>
        <div class="flex-shrink-0">
            <button type="button"
                    class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                    data-bs-toggle="modal"
                    data-bs-target="#addUserModal"
                    data-testid="btn-open-add-user">
                <i class="bi bi-person-plus-fill"></i>
                <span>เพิ่มผู้ใช้งานใหม่</span>
            </button>
        </div>
    </div>
</div>

<!-- 4 Clean KPI Stat Cards -->
<div class="row g-2 g-sm-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-blue"><i class="bi bi-people-fill"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">ผู้ใช้ทั้งหมด</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$summary['total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-indigo"><i class="bi bi-person-badge-fill"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">บุคลากรภายใน</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$summary['internal'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-amber"><i class="bi bi-building"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">บุคคลภายนอก</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$summary['external'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card p-3 h-100 d-flex align-items-center gap-3">
            <div class="kpi-icon kpi-icon-emerald"><i class="bi bi-check-circle-fill"></i></div>
            <div class="min-w-0">
                <div class="kpi-label text-truncate mb-1">เปิดใช้งานอยู่</div>
                <div class="fs-3 fw-bold text-dark lh-1"><?= (int)$summary['active'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Users List -->
<div class="spotlight-card">
    <div class="spotlight-header">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <?php
            $filterTabs = [
                'all'             => 'ทั้งหมด',
                'executives'      => 'ผู้บริหาร/หัวหน้า',
                ROLE_STAFF        => 'เจ้าหน้าที่/ช่าง',
                ROLE_USER         => 'บุคลากรภายใน',
                ROLE_EXTERNAL_USER=> 'บุคคลภายนอก',
                ROLE_ADMIN        => 'ผู้ดูแลระบบ',
            ];
            foreach ($filterTabs as $fKey => $fLabel):
                $isActiveTab = ($filterRole === $fKey);
                $tabHref = app_url('/users_manage.php?role=' . urlencode($fKey) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''));
            ?>
                <a href="<?= e($tabHref) ?>"
                   class="btn btn-sm rounded-pill px-3 <?= $isActiveTab ? 'btn-primary' : 'btn-light border text-secondary' ?>">
                    <?= e($fLabel) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= e(app_url('/users_manage.php')) ?>" class="d-flex align-items-center gap-2">
            <input type="hidden" name="role" value="<?= e($filterRole) ?>">
            <div class="input-group input-group-sm" style="max-width: 240px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search"
                       class="form-control"
                       name="q"
                       value="<?= e($searchQuery) ?>"
                       placeholder="ค้นหาชื่อ, รหัส, อีเมล">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary rounded-3">ค้นหา</button>
        </form>
    </div>

    <?php if (empty($filteredUsers)): ?>
        <div class="p-5 text-center text-muted">
            <i class="bi bi-person-x fs-2 d-block mb-2"></i>
            <div class="small">ไม่พบรายชื่อผู้ใช้งานที่ตรงกับเงื่อนไข</div>
        </div>
    <?php else: ?>
        <?php foreach ($filteredUsers as $uRow):
            $uId = (int)$uRow['id'];
            $uActive = ((int)$uRow['is_active'] === 1);
            $uRole = (string)$uRow['role'];
            $uRoleLabel = $allRoles[$uRole] ?? $uRole;
            $uBadgeCls = $roleBadgeClasses[$uRole] ?? 'text-bg-secondary';
            $uInitial = mb_substr((string)$uRow['full_name'], 0, 1, 'UTF-8') ?: 'U';
            $uOrgText = (string)($uRow['department'] ?: ($uRow['organization'] ?: '-'));
            $isSelf = ($uId === (int)$user['id']);
            $swalUserPayload = [
                'title'    => (string)$uRow['full_name'],
                'subtitle' => '@' . $uRow['username'] . (!empty($uRow['staff_id']) ? ' (' . $uRow['staff_id'] . ')' : ''),
                'badge'    => $uRoleLabel . ($uActive ? ' · ปกติ' : ' · ระงับใช้งาน'),
                'rows'     => [
                    ['label' => 'ประเภทบัญชี',  'value' => $uRow['user_type'] === USER_TYPE_EXTERNAL ? 'บุคคลภายนอก' : 'บุคลากรภายใน'],
                    ['label' => 'ตำแหน่ง',      'value' => (string)($uRow['position_title'] ?: '-')],
                    ['label' => 'ฝ่าย/หน่วยงาน', 'value' => $uOrgText],
                    ['label' => 'อีเมล',        'value' => (string)$uRow['email']],
                    ['label' => 'โทรศัพท์',     'value' => (string)($uRow['phone'] ?: '-')],
                ],
            ];
        ?>
            <div class="role-queue-item">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="user-list-avatar <?= $uActive ? '' : 'opacity-50' ?>">
                            <?= e($uInitial) ?>
                        </div>
                        <div class="min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="fw-bold text-dark"><?= e($uRow['full_name']) ?></span>
                                <span class="badge rounded-pill <?= e($uBadgeCls) ?>"><?= e($uRoleLabel) ?></span>
                                <?php if (!$uActive): ?>
                                    <span class="badge rounded-pill text-bg-danger">ระงับการใช้งาน</span>
                                <?php endif; ?>
                            </div>
                            <div class="compact-meta text-truncate">
                                <span class="fw-medium text-secondary">@<?= e($uRow['username']) ?></span>
                                <?= !empty($uRow['staff_id']) ? ' (' . e($uRow['staff_id']) . ')' : '' ?>
                                · <?= e($uOrgText) ?>
                                <?= !empty($uRow['position_title']) ? ' · ' . e($uRow['position_title']) : '' ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0 align-self-end align-self-lg-center">
                        <button type="button"
                                class="btn btn-sm btn-soft-secondary rounded-3 px-3 fw-semibold"
                                data-swal-detail="<?= e((string)json_encode($swalUserPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <i class="bi bi-eye me-1"></i>ดูข้อมูล
                        </button>

                        <button type="button"
                                class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold btn-edit-user"
                                data-bs-toggle="modal"
                                data-bs-target="#editUserModal"
                                data-user-id="<?= $uId ?>"
                                data-full-name="<?= e($uRow['full_name']) ?>"
                                data-role="<?= e($uRole) ?>"
                                data-position="<?= e((string)($uRow['position_title'] ?? '')) ?>"
                                data-department="<?= e((string)($uRow['department'] ?? '')) ?>"
                                data-organization="<?= e((string)($uRow['organization'] ?? '')) ?>"
                                data-phone="<?= e((string)($uRow['phone'] ?? '')) ?>"
                                data-email="<?= e((string)($uRow['email'] ?? '')) ?>"
                                data-is-self="<?= $isSelf ? '1' : '0' ?>">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข
                        </button>

                        <?php if (!$isSelf): ?>
                            <form method="post"
                                  action="<?= e(app_url('/users_manage.php')) ?>"
                                  class="d-inline m-0"
                                  data-confirm="<?= e($uActive ? 'ต้องการระงับการใช้งานบัญชี "' . $uRow['full_name'] . '" ใช่หรือไม่?' : 'ต้องการเปิดใช้งานบัญชี "' . $uRow['full_name'] . '" อีกครั้งใช่หรือไม่?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                <button type="submit"
                                        class="btn btn-sm <?= $uActive ? 'btn-soft-warning' : 'btn-soft-success' ?> rounded-3 px-3 fw-semibold">
                                    <i class="bi <?= $uActive ? 'bi-slash-circle' : 'bi-check-circle' ?> me-1"></i>
                                    <?= $uActive ? 'ระงับบัญชี' : 'เปิดใช้งาน' ?>
                                </button>
                            </form>

                            <form method="post"
                                  action="<?= e(app_url('/users_manage.php')) ?>"
                                  class="d-inline m-0"
                                  data-confirm-danger="1"
                                  data-confirm="<?= e('ยืนยันการลบบัญชีผู้ใช้งาน "' . $uRow['full_name'] . '" (' . $uRow['username'] . ') ออกจากระบบอย่างถาวรใช่หรือไม่?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-soft-danger rounded-3 px-3 fw-semibold"
                                        data-testid="btn-delete-user-<?= $uId ?>">
                                    <i class="bi bi-trash3 me-1"></i>ลบ
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalTitle" aria-hidden="true" data-testid="add-user-modal">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="addUserModalTitle">
                    <span class="portal-icon-box icon-box-blue" style="width: 36px; height: 36px; font-size: 1rem;">
                        <i class="bi bi-person-plus-fill"></i>
                    </span>
                    <span>เพิ่มผู้ใช้งานใหม่</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>

            <form method="post" action="<?= e(app_url('/users_manage.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="modal-body p-4">
                    <?php if (!empty($createErrors)): ?>
                        <div class="alert alert-danger border-0 rounded-3" role="alert" data-testid="add-user-error">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($createErrors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="add_user_type" class="form-label fw-semibold small text-secondary">ประเภทผู้ใช้งาน <span class="text-danger">*</span></label>
                            <select class="form-select touch-target" id="add_user_type" name="user_type" data-testid="add-user-type">
                                <option value="internal" <?= $createForm['user_type'] === USER_TYPE_INTERNAL ? 'selected' : '' ?>>บุคลากรภายในหน่วยงาน</option>
                                <option value="external" <?= $createForm['user_type'] === USER_TYPE_EXTERNAL ? 'selected' : '' ?>>บุคคล / หน่วยงานภายนอก</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="add_role" class="form-label fw-semibold small text-secondary">บทบาทสิทธิ์ (Role) <span class="text-danger">*</span></label>
                            <select class="form-select touch-target" id="add_role" name="role" data-testid="add-user-role">
                                <?php foreach ($allRoles as $rKey => $rLabel): ?>
                                    <option value="<?= e($rKey) ?>" <?= $createForm['role'] === $rKey ? 'selected' : '' ?>><?= e($rLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label for="add_full_name" class="form-label fw-semibold small text-secondary">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="add_full_name" name="full_name" value="<?= e($createForm['full_name']) ?>" placeholder="เช่น นายสมศักดิ์ รักงาน" required data-testid="add-user-fullname">
                        </div>

                        <div class="col-md-4">
                            <label for="add_staff_id" class="form-label fw-semibold small text-secondary">รหัสพนักงาน</label>
                            <input type="text" class="form-control touch-target" id="add_staff_id" name="staff_id" value="<?= e($createForm['staff_id']) ?>" placeholder="เช่น EMP003" data-testid="add-user-staff-id">
                        </div>

                        <div class="col-md-6">
                            <label for="add_username" class="form-label fw-semibold small text-secondary">ชื่อผู้ใช้ (Username) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control touch-target" id="add_username" name="username" value="<?= e($createForm['username']) ?>" placeholder="เช่น user03" required data-testid="add-user-username">
                        </div>

                        <div class="col-md-6">
                            <label for="add_email" class="form-label fw-semibold small text-secondary">อีเมล <span class="text-danger">*</span></label>
                            <input type="email" class="form-control touch-target" id="add_email" name="email" value="<?= e($createForm['email']) ?>" placeholder="name@slp.local" required data-testid="add-user-email">
                        </div>

                        <div class="col-md-6">
                            <label for="add_password" class="form-label fw-semibold small text-secondary">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) <span class="text-danger">*</span></label>
                            <input type="password" class="form-control touch-target" id="add_password" name="password" minlength="8" placeholder="กำหนดรหัสผ่านเริ่มต้น" required data-testid="add-user-password">
                        </div>

                        <div class="col-md-6">
                            <label for="add_phone" class="form-label fw-semibold small text-secondary">เบอร์โทรศัพท์</label>
                            <input type="tel" class="form-control touch-target" id="add_phone" name="phone" value="<?= e($createForm['phone']) ?>" placeholder="08x-xxx-xxxx" data-testid="add-user-phone">
                        </div>

                        <div class="col-md-4">
                            <label for="add_position_title" class="form-label fw-semibold small text-secondary">ตำแหน่ง</label>
                            <input type="text" class="form-control touch-target" id="add_position_title" name="position_title" value="<?= e($createForm['position_title']) ?>" placeholder="เช่น นักวิชาการ">
                        </div>

                        <div class="col-md-4">
                            <label for="add_department" class="form-label fw-semibold small text-secondary">ฝ่าย / กลุ่มงาน</label>
                            <input type="text" class="form-control touch-target" id="add_department" name="department" value="<?= e($createForm['department']) ?>" placeholder="เช่น ฝ่ายบริหารทั่วไป">
                        </div>

                        <div class="col-md-4">
                            <label for="add_organization" class="form-label fw-semibold small text-secondary">หน่วยงาน / องค์กร</label>
                            <input type="text" class="form-control touch-target" id="add_organization" name="organization" value="<?= e($createForm['organization']) ?>" placeholder="ชื่อหน่วยงาน">
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold" data-testid="add-user-submit">
                        <i class="bi bi-check-lg me-1"></i>บันทึกผู้ใช้งาน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="editUserModalTitle">แก้ไขข้อมูลและสิทธิ์ผู้ใช้งาน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= e(app_url('/users_manage.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id" value="">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label fw-semibold small text-secondary">ชื่อ-นามสกุล</label>
                        <input type="text" class="form-control touch-target" id="edit_full_name" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label fw-semibold small text-secondary">อีเมล / Gmail <span class="text-danger">*</span></label>
                        <input type="email" class="form-control touch-target" id="edit_email" name="email" placeholder="example@gmail.com" required data-testid="edit-user-email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_role" class="form-label fw-semibold small text-secondary">บทบาทสิทธิ์ (Role)</label>
                        <select class="form-select touch-target" id="edit_role" name="role">
                            <?php foreach ($allRoles as $rKey => $rLabel): ?>
                                <option value="<?= e($rKey) ?>"><?= e($rLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="edit_position" class="form-label fw-semibold small text-secondary">ตำแหน่ง</label>
                            <input type="text" class="form-control touch-target" id="edit_position" name="position_title">
                        </div>
                        <div class="col-6">
                            <label for="edit_department" class="form-label fw-semibold small text-secondary">ฝ่าย / กลุ่มงาน</label>
                            <input type="text" class="form-control touch-target" id="edit_department" name="department">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="edit_organization" class="form-label fw-semibold small text-secondary">หน่วยงาน</label>
                            <input type="text" class="form-control touch-target" id="edit_organization" name="organization">
                        </div>
                        <div class="col-6">
                            <label for="edit_phone" class="form-label fw-semibold small text-secondary">เบอร์โทรศัพท์</label>
                            <input type="tel" class="form-control touch-target" id="edit_phone" name="phone">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label for="edit_new_password" class="form-label fw-semibold small text-secondary">ตั้งรหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)</label>
                        <input type="password" class="form-control touch-target" id="edit_new_password" name="new_password" minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร">
                    </div>
                </div>
                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary touch-target rounded-3 px-4 fw-semibold">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSel = document.getElementById('add_user_type');
    const roleSel = document.getElementById('add_role');
    const staffInput = document.getElementById('add_staff_id');
    const orgInput = document.getElementById('add_organization');

    function syncTypeFields() {
        if (!typeSel || !roleSel || !staffInput) return;
        const isExt = typeSel.value === 'external';
        if (isExt) {
            roleSel.value = 'external_user';
            staffInput.value = '';
            staffInput.disabled = true;
            if (orgInput && orgInput.value === <?= json_encode(APP_ORG_NAME, JSON_UNESCAPED_UNICODE) ?>) {
                orgInput.value = '';
            }
        } else {
            if (roleSel.value === 'external_user') {
                roleSel.value = 'user';
            }
            staffInput.disabled = false;
            if (orgInput && orgInput.value === '') {
                orgInput.value = <?= json_encode(APP_ORG_NAME, JSON_UNESCAPED_UNICODE) ?>;
            }
        }
        Array.from(roleSel.options).forEach(function (opt) {
            opt.disabled = isExt ? (opt.value !== 'external_user') : (opt.value === 'external_user');
        });
    }

    if (typeSel) {
        typeSel.addEventListener('change', syncTypeFields);
        syncTypeFields();
    }

    document.querySelectorAll('.btn-edit-user').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_user_id').value = btn.dataset.userId || '';
            document.getElementById('edit_full_name').value = btn.dataset.fullName || '';
            const editRole = document.getElementById('edit_role');
            editRole.value = btn.dataset.role || 'user';
            editRole.disabled = (btn.dataset.isSelf === '1');
            if (btn.dataset.isSelf === '1') {
                editRole.disabled = false;
                Array.from(editRole.options).forEach(function (opt) {
                    opt.disabled = (opt.value !== 'admin');
                });
            } else {
                Array.from(editRole.options).forEach(function (opt) {
                    opt.disabled = false;
                });
            }
            document.getElementById('edit_position').value = btn.dataset.position || '';
            document.getElementById('edit_department').value = btn.dataset.department || '';
            document.getElementById('edit_organization').value = btn.dataset.organization || '';
            document.getElementById('edit_phone').value = btn.dataset.phone || '';
            document.getElementById('edit_email').value = btn.dataset.email || '';
            document.getElementById('edit_new_password').value = '';
        });
    });

    <?php if (($openAddModal && !empty($createErrors)) || (($_GET['add'] ?? '') === '1')): ?>
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addUserModal')).show();
    }
    <?php endif; ?>
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
