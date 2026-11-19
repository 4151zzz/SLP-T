<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (current_user() !== null) {
    redirect(app_url('/index.php'));
}

$errors = [];
$form = [
    'full_name'    => '',
    'organization' => '',
    'phone'        => '',
    'email'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['full_name']    = trim((string)($_POST['full_name'] ?? ''));
    $form['organization'] = trim((string)($_POST['organization'] ?? ''));
    $form['phone']        = trim((string)($_POST['phone'] ?? ''));
    $form['email']        = strtolower(trim((string)($_POST['email'] ?? '')));
    $password             = (string)($_POST['password'] ?? '');
    $hasConfirm           = array_key_exists('password_confirm', $_POST);
    $passwordConfirm      = (string)($_POST['password_confirm'] ?? '');

    if ($form['full_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    }
    if ($form['organization'] === '') {
        $errors[] = 'กรุณากรอกชื่อหน่วยงาน/องค์กร';
    }
    if ($form['phone'] === '') {
        $errors[] = 'กรุณากรอกเบอร์โทรศัพท์ติดต่อ';
    }
    if ($form['email'] === '' || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'กรุณากรอกอีเมลที่ถูกต้อง';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
    }
    if ($hasConfirm && $passwordConfirm !== $password) {
        $errors[] = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    }

    if (empty($errors)) {
        try {
            $pdo = get_pdo();
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkStmt->execute([':email' => $form['email']]);
            if ($checkStmt->fetch()) {
                $errors[] = 'อีเมลนี้ถูกใช้งานในระบบแล้ว กรุณาใช้อีเมลอื่นหรือเข้าสู่ระบบ';
            } else {
                $username = $form['email'];
                if (strlen($username) > 100) {
                    $username = 'ext_' . bin2hex(random_bytes(6));
                } else {
                    $uCheck = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
                    $uCheck->execute([':username' => $username]);
                    if ($uCheck->fetch()) {
                        $username = 'ext_' . bin2hex(random_bytes(6));
                    }
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // External registration strictly forces user_type='external', role='external_user', staff_id=NULL
                $insertStmt = $pdo->prepare(
                    'INSERT INTO users (
                        user_type, role, username, staff_id, password_hash,
                        full_name, position_title, department, organization,
                        phone, email, line_user_id, is_active
                    ) VALUES (
                        :user_type, :role, :username, NULL, :password_hash,
                        :full_name, NULL, NULL, :organization,
                        :phone, :email, NULL, 1
                    )'
                );
                $insertStmt->execute([
                    ':user_type'     => USER_TYPE_EXTERNAL,
                    ':role'          => ROLE_EXTERNAL_USER,
                    ':username'      => $username,
                    ':password_hash' => $passwordHash,
                    ':full_name'     => mb_substr($form['full_name'], 0, 150),
                    ':organization'  => mb_substr($form['organization'], 0, 200),
                    ':phone'         => mb_substr($form['phone'], 0, 30),
                    ':email'         => mb_substr($form['email'], 0, 150),
                ]);

                flash('success', 'ลงทะเบียนผู้ใช้งานภายนอกสำเร็จ กรุณาเข้าสู่ระบบด้วยอีเมลที่ลงทะเบียน');
                redirect(app_url('/login.php'));
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'อีเมลหรือชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว';
            } else {
                $errors[] = 'ไม่สามารถบันทึกข้อมูลลงทะเบียนได้ในขณะนี้';
            }
        }
    }
}

$pageTitle = 'ลงทะเบียนผู้ใช้งานภายนอก';
$hideSidebar = true;
$bodyClass = 'auth-page-body';
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'โรงเรียนสระหลวงพิทยาคม';
$appDevelopers = defined('APP_DEVELOPERS') ? APP_DEVELOPERS : 'wunPiyapong & Chonthicha Puengpak';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="auth-wrapper px-2 px-sm-3">
    <div class="auth-split-card">
        <div class="auth-top-accent"></div>
        <div class="row g-0">
            <div class="col-lg-4 d-none d-lg-flex auth-brand-panel">
                <div class="auth-brand-content">
                    <div class="text-center mb-4">
                        <div class="auth-logo-medallion mb-3">
                            <img src="<?= e(app_url('/assets/img/school-logo.png')) ?>" alt="ตรา<?= e($appOrgName) ?>">
                        </div>
                        <div class="mb-2">
                            <span class="auth-school-pill">
                                <i class="bi bi-award-fill"></i>
                                <span><?= e($appOrgName) ?></span>
                            </span>
                        </div>
                        <h2 class="h4 fw-bold text-white mb-2">บัญชีสำหรับหน่วยงานภายนอก</h2>
                        <p class="small text-white text-opacity-75 mb-0">
                            ลงทะเบียนครั้งเดียวเพื่อขอใช้ห้องประชุมสาธารณะ แนบหนังสือขออนุญาต และติดตามผลอนุมัติออนไลน์
                        </p>
                    </div>

                    <div class="d-flex flex-column gap-2 mt-4">
                        <div class="auth-feature-item">
                            <span class="auth-feature-icon"><i class="bi bi-door-open"></i></span>
                            <div class="small text-white text-opacity-90">ขอจองห้องประชุม/หอประชุมที่เปิดรับภายนอก</div>
                        </div>
                        <div class="auth-feature-item">
                            <span class="auth-feature-icon"><i class="bi bi-file-earmark-pdf"></i></span>
                            <div class="small text-white text-opacity-90">แนบหนังสือขออนุญาต PDF/รูปภาพได้ทันที</div>
                        </div>
                    </div>
                </div>

                <div class="auth-brand-content pt-3 mt-4 border-top border-white border-opacity-15 small text-white text-opacity-75">
                    <div><i class="bi bi-lock-fill me-1 text-warning"></i> ข้อมูลถูกจัดเก็บอย่างปลอดภัยในระบบ</div>
                    <div class="mt-2"><i class="bi bi-code-slash me-1"></i> พัฒนาโดย <strong class="text-white"><?= e($appDevelopers) ?></strong></div>
                </div>
            </div>

            <div class="col-12 col-lg-8 auth-form-panel d-flex flex-column justify-content-center p-4 p-sm-5">
                <div class="auth-mobile-hero d-lg-none mb-4 text-center">
                    <div class="auth-logo-medallion-sm mx-auto mb-2">
                        <img src="<?= e(app_url('/assets/img/school-logo.png')) ?>" alt="ตรา<?= e($appOrgName) ?>">
                    </div>
                    <div class="mb-1">
                        <span class="auth-school-pill">
                            <i class="bi bi-award-fill"></i>
                            <span><?= e($appOrgName) ?></span>
                        </span>
                    </div>
                    <div class="fw-bold text-white small"><?= e(APP_NAME) ?></div>
                </div>

                <div class="mb-4">
                    <span class="auth-welcome-badge mb-2">
                        <i class="bi bi-person-plus-fill"></i>
                        <span>สมัครสมาชิกบุคคลภายนอก</span>
                    </span>
                    <h1 class="h4 fw-bold text-dark mb-1">ลงทะเบียนบุคคลภายนอก</h1>
                    <p class="small text-muted mb-0">สำหรับหน่วยงานหรือบุคคลภายนอกที่ต้องการขอใช้ห้องประชุม/สถานที่</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger border-0 rounded-3 shadow-sm" role="alert" data-testid="register-error">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(app_url('/register.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="full_name" class="form-label fw-semibold small text-secondary">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <div class="input-group auth-field">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text"
                                   class="form-control touch-target"
                                   id="full_name"
                                   name="full_name"
                                   value="<?= e($form['full_name']) ?>"
                                   placeholder="ระบุชื่อ-นามสกุล"
                                   required
                                   data-testid="register-full-name">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="organization" class="form-label fw-semibold small text-secondary">หน่วยงาน / องค์กร <span class="text-danger">*</span></label>
                        <div class="input-group auth-field">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <input type="text"
                                   class="form-control touch-target"
                                   id="organization"
                                   name="organization"
                                   value="<?= e($form['organization']) ?>"
                                   placeholder="ระบุชื่อหน่วยงานหรือบริษัท"
                                   required
                                   data-testid="register-organization">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold small text-secondary">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <div class="input-group auth-field">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="tel"
                                       class="form-control touch-target"
                                       id="phone"
                                       name="phone"
                                       value="<?= e($form['phone']) ?>"
                                       placeholder="08x-xxx-xxxx"
                                       required
                                       data-testid="register-phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold small text-secondary">อีเมล (ใช้สำหรับเข้าสู่ระบบ) <span class="text-danger">*</span></label>
                            <div class="input-group auth-field">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email"
                                       class="form-control touch-target"
                                       id="email"
                                       name="email"
                                       value="<?= e($form['email']) ?>"
                                       placeholder="name@example.org"
                                       required
                                       data-testid="register-email">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold small text-secondary">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) <span class="text-danger">*</span></label>
                            <div class="input-group auth-field">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password"
                                       class="form-control touch-target"
                                       id="password"
                                       name="password"
                                       minlength="8"
                                       placeholder="••••••••"
                                       required
                                       data-testid="register-password">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="password_confirm" class="form-label fw-semibold small text-secondary">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
                            <div class="input-group auth-field">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password"
                                       class="form-control touch-target"
                                       id="password_confirm"
                                       name="password_confirm"
                                       minlength="8"
                                       placeholder="••••••••"
                                       required
                                       data-testid="register-password-confirm">
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                            class="btn btn-auth-submit w-100 touch-target fw-semibold"
                            data-testid="register-submit">
                        <i class="bi bi-check-circle me-1"></i>
                        ยืนยันการลงทะเบียน
                    </button>
                </form>

                <div class="auth-register-box text-center small mt-4">
                    <span class="text-muted">มีบัญชีผู้ใช้งานแล้ว?</span>
                    <a href="<?= e(app_url('/login.php')) ?>" class="fw-semibold text-decoration-none ms-1" data-testid="link-login">
                        เข้าสู่ระบบที่นี่ <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <div class="auth-dev-credit mt-4 pt-3 border-top">
                    <i class="bi bi-code-slash text-primary"></i>
                    <span>พัฒนาโดย</span>
                    <strong class="text-dark"><?= e($appDevelopers) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
