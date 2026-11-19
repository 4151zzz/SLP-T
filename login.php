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
$bodyClass = 'auth-page-body';
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'โรงเรียนสระหลวงพิทยาคม';
$appDevelopers = defined('APP_DEVELOPERS') ? APP_DEVELOPERS : 'wunPiyapong & Chonthicha Puengpak';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="auth-wrapper px-2 px-sm-3">
    <div class="auth-split-card">
        <div class="auth-top-accent"></div>
        <div class="row g-0">
            <!-- Left School Brand Showcase Panel (Desktop) -->
            <div class="col-lg-5 d-none d-lg-flex auth-brand-panel">
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
                        <h2 class="h4 fw-bold text-white mb-2"><?= e(APP_NAME) ?></h2>
                        <p class="small text-white text-opacity-75 mb-0">
                            แพลตฟอร์มบริหารจัดการห้องประชุม ยานพาหนะ การอนุมัติออนไลน์ และงานซ่อมบำรุงครบวงจร
                        </p>
                    </div>

                    <div class="d-flex flex-column gap-2 mt-4">
                        <div class="auth-feature-item">
                            <span class="auth-feature-icon"><i class="bi bi-calendar3"></i></span>
                            <div class="small">
                                <div class="fw-semibold text-white">ปฏิทินกลางและจองห้อง-รถอัจฉริยะ</div>
                                <div class="text-white text-opacity-75" style="font-size: 0.76rem;">ตรวจสอบเวลาว่างและป้องกันการจองซ้ำซ้อนอัตโนมัติ</div>
                            </div>
                        </div>
                        <div class="auth-feature-item">
                            <span class="auth-feature-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="small">
                                <div class="fw-semibold text-white">อนุมัติ 3 ขั้นตอน + บันทึกข้อความ A4</div>
                                <div class="text-white text-opacity-75" style="font-size: 0.76rem;">ติดตามสถานะเรียลไทม์พร้อมพิมพ์เอกสารราชการทันที</div>
                            </div>
                        </div>
                        <div class="auth-feature-item">
                            <span class="auth-feature-icon"><i class="bi bi-speedometer2"></i></span>
                            <div class="small">
                                <div class="fw-semibold text-white">แดชบอร์ดผู้บริหารและแจ้งซ่อม QR</div>
                                <div class="text-white text-opacity-75" style="font-size: 0.76rem;">สรุปสถิติรายเดือนและส่งออกไฟล์ Excel CSV ได้ทันที</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auth-brand-content pt-3 mt-4 border-top border-white border-opacity-15 small text-white text-opacity-75">
                    <div><i class="bi bi-shield-check me-1 text-warning"></i> เข้าสู่ระบบอย่างปลอดภัยด้วยการเข้ารหัสเซสชันและ CSRF</div>
                    <div class="mt-2"><i class="bi bi-code-slash me-1"></i> พัฒนาโดย <strong class="text-white"><?= e($appDevelopers) ?></strong></div>
                </div>
            </div>

            <!-- Right Login Form Panel -->
            <div class="col-12 col-lg-7 auth-form-panel d-flex flex-column justify-content-center p-4 p-sm-5">
                <!-- Mobile Hero Header -->
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
                        <i class="bi bi-shield-lock-fill"></i>
                        <span>ยินดีต้อนรับเข้าสู่ระบบ</span>
                    </span>
                    <h1 class="h3 fw-bold text-dark mb-1">เข้าสู่ระบบ</h1>
                    <p class="small text-muted mb-0">ลงชื่อเข้าใช้งานด้วยชื่อผู้ใช้ รหัสบุคลากร หรืออีเมลของคุณ</p>
                </div>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-3" role="alert" data-testid="login-error">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(app_url('/login.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                    <div class="mb-3">
                        <label for="identifier" class="form-label fw-semibold small text-secondary">ชื่อผู้ใช้ / รหัสบุคลากร / อีเมล</label>
                        <div class="input-group auth-field">
                            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                            <input type="text"
                                   class="form-control touch-target"
                                   id="identifier"
                                   name="identifier"
                                   value="<?= e($identifier) ?>"
                                   placeholder="ชื่อผู้ใช้ รหัสบุคลากร หรืออีเมล"
                                   autocomplete="username"
                                   required
                                   autofocus
                                   data-testid="login-identifier">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold small text-secondary">รหัสผ่าน</label>
                        <div class="input-group auth-field">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password"
                                   class="form-control touch-target"
                                   id="password"
                                   name="password"
                                   placeholder="กรอกรหัสผ่าน"
                                   autocomplete="current-password"
                                   required
                                   data-testid="login-password">
                            <button type="button"
                                    class="btn btn-eye text-muted px-3"
                                    id="toggleLoginPassword"
                                    aria-label="แสดงหรือซ่อนรหัสผ่าน">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                            class="btn btn-auth-submit w-100 touch-target fw-semibold"
                            data-testid="login-submit">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        เข้าสู่ระบบ
                    </button>
                </form>

                <div class="auth-register-box text-center small mt-4">
                    <span class="text-muted">สำหรับหน่วยงานหรือบุคคลภายนอก</span>
                    <a href="<?= e(app_url('/register.php')) ?>" class="fw-semibold text-decoration-none ms-1" data-testid="link-register">
                        ลงทะเบียนผู้ใช้งานภายนอกใหม่ <i class="bi bi-arrow-right"></i>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pwField = document.getElementById('password');
    const toggleBtn = document.getElementById('toggleLoginPassword');

    if (toggleBtn && pwField) {
        toggleBtn.addEventListener('click', function () {
            const isPw = pwField.type === 'password';
            pwField.type = isPw ? 'text' : 'password';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.className = isPw ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        });
    }
});
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
