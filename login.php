<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$redirectTarget = safe_redirect_path($_GET['redirect'] ?? $_POST['redirect'] ?? null, '/index.php');

if (current_user() !== null) {
    redirect(app_url($redirectTarget));
}

$error = null;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim((string)($_POST['identifier'] ?? $_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $result = attempt_login($identifier, $password);
    if ($result['ok']) {
        flash('success', 'เข้าสู่ระบบเรียบร้อยแล้ว ยินดีต้อนรับ ' . ($result['user']['full_name'] ?? ''));
        redirect(app_url($redirectTarget));
    }
    $error = $result['error'] ?? 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}

$pageTitle = 'เข้าสู่ระบบ';
$hideSidebar = true;
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'สำนักงานบริหารทั่วไป';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="auth-wrapper">
    <div class="row justify-content-center w-100 mx-0">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4 px-0 px-sm-2">
            <div class="auth-card">
                <div class="auth-header-clean">
                    <div class="auth-icon-circle">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                    <h1 class="h4 fw-bold text-dark mb-1">เข้าสู่ระบบ</h1>
                    <p class="small text-muted mb-0"><?= e(APP_NAME) ?> · <?= e($appOrgName) ?></p>
                </div>

                <div class="p-4 pt-3">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger border-0 rounded-3 shadow-sm" role="alert" data-testid="login-error">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(app_url('/login.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                        <div class="mb-3">
                            <label for="identifier" class="form-label fw-semibold small text-secondary">ชื่อผู้ใช้ / รหัสพนักงาน / อีเมล</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                                <input type="text"
                                       class="form-control touch-target"
                                       id="identifier"
                                       name="identifier"
                                       value="<?= e($identifier) ?>"
                                       placeholder="user01 / EMP001 / อีเมล"
                                       autocomplete="username"
                                       required
                                       autofocus
                                       data-testid="login-identifier">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label fw-semibold small text-secondary">รหัสผ่าน</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password"
                                       class="form-control touch-target"
                                       id="password"
                                       name="password"
                                       placeholder="กรอกรหัสผ่าน"
                                       autocomplete="current-password"
                                       required
                                       data-testid="login-password">
                            </div>
                        </div>

                        <button type="submit"
                                class="btn btn-primary-gradient w-100 touch-target fw-semibold rounded-3"
                                data-testid="login-submit">
                            <i class="bi bi-box-arrow-in-right me-1"></i>
                            เข้าสู่ระบบ
                        </button>
                    </form>

                    <div class="demo-box mt-4">
                        <div class="small fw-semibold text-secondary mb-2 text-center">
                            <i class="bi bi-lightning-charge-fill text-warning me-1"></i>เลือกบทบาทเพื่อทดสอบเข้าใช้งาน
                        </div>
                        <div class="demo-grid">
                            <button type="button" class="demo-pill" data-demo-user="director01" data-demo-pass="Password123!">
                                <i class="bi bi-award"></i> ผู้อำนวยการ
                            </button>
                            <button type="button" class="demo-pill" data-demo-user="vice01" data-demo-pass="Password123!">
                                <i class="bi bi-person-workspace"></i> รองผู้อำนวยการ
                            </button>
                            <button type="button" class="demo-pill" data-demo-user="head01" data-demo-pass="Password123!">
                                <i class="bi bi-person-check"></i> หัวหน้างาน
                            </button>
                            <button type="button" class="demo-pill" data-demo-user="staff01" data-demo-pass="Password123!">
                                <i class="bi bi-tools"></i> เจ้าหน้าที่ / ช่าง
                            </button>
                            <button type="button" class="demo-pill" data-demo-user="user01" data-demo-pass="Password123!">
                                <i class="bi bi-person"></i> บุคลากรภายใน
                            </button>
                            <button type="button" class="demo-pill" data-demo-user="ext01" data-demo-pass="Password123!">
                                <i class="bi bi-building"></i> บุคคลภายนอก
                            </button>
                            <button type="button" class="demo-pill demo-pill-full" data-demo-user="admin" data-demo-pass="Password123!">
                                <i class="bi bi-shield-check"></i> ผู้ดูแลระบบ
                            </button>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="text-center small">
                        <span class="text-muted">สำหรับหน่วยงานหรือบุคคลภายนอก</span><br>
                        <a href="<?= e(app_url('/register.php')) ?>" class="fw-semibold text-decoration-none d-inline-flex align-items-center gap-1 mt-1" data-testid="link-register">
                            <i class="bi bi-person-plus"></i>
                            <span>ลงทะเบียนผู้ใช้งานภายนอกใหม่</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pills = document.querySelectorAll('.demo-pill[data-demo-user]');
    const idField = document.getElementById('identifier');
    const pwField = document.getElementById('password');
    if (!idField || !pwField) return;
    pills.forEach(function (btn) {
        btn.addEventListener('click', function () {
            idField.value = btn.getAttribute('data-demo-user') || '';
            pwField.value = btn.getAttribute('data-demo-pass') || '';
            pills.forEach(function (p) { p.classList.remove('is-selected'); });
            btn.classList.add('is-selected');
            idField.focus();
        });
    });
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
