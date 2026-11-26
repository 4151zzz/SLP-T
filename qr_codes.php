<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_role([ROLE_STAFF, ROLE_ADMIN]);
$pdo = get_pdo();

$pageTitle = 'พิมพ์ QR Code';
$activeNav = 'qr_codes';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'toggle_qr') {
        $nextEnabled = ((string)($_POST['qr_enabled'] ?? '0') === '1');
        set_qr_enabled($pdo, $nextEnabled);
        flash('success', $nextEnabled
            ? 'เปิดใช้งานระบบ QR Code เรียบร้อยแล้ว'
            : 'ปิดใช้งานระบบ QR Code เรียบร้อยแล้ว');
        redirect(app_url('/qr_codes.php'));
    }
}

$qrEnabled = is_qr_enabled($pdo);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:3000');
$baseAbs = $scheme . '://' . $host;

$unifiedQrs = [
    [
        'testid'     => 'qr-unified-room',
        'badge'      => 'QR จองห้องประชุม / ห้องเรียน',
        'badgeClass' => 'text-bg-primary bg-opacity-10 text-primary border border-primary-subtle',
        'icon'       => 'bi-house-fill text-primary',
        'title'      => 'จองห้องประชุม',
        'subtitle'   => 'สแกนเพื่อเข้าสู่หน้าจองห้องประชุม / ห้องเรียนทันที',
        'url'        => $baseAbs . app_url('/book_room.php'),
        'btnClass'   => 'btn-outline-primary',
        'btnText'    => 'เปิดหน้าจองห้อง',
        'filename'   => 'QR-BOOK-ROOM.png',
    ],
    [
        'testid'     => 'qr-unified-vehicle',
        'badge'      => 'QR จองยานพาหนะ',
        'badgeClass' => 'text-bg-success bg-opacity-10 text-success border border-success-subtle',
        'icon'       => 'bi-car-front-fill text-success',
        'title'      => 'จองยานพาหนะ',
        'subtitle'   => 'สแกนเพื่อเข้าสู่หน้าขอใช้รถยนต์ส่วนกลางทันที',
        'url'        => $baseAbs . app_url('/book_vehicle.php'),
        'btnClass'   => 'btn-outline-success',
        'btnText'    => 'เปิดหน้าจองรถ',
        'filename'   => 'QR-BOOK-VEHICLE.png',
    ],
    [
        'testid'     => 'qr-unified-borrow',
        'badge'      => 'QR ยืม-คืนอุปกรณ์/กุญแจ',
        'badgeClass' => 'text-bg-info bg-opacity-10 text-info border border-info-subtle',
        'icon'       => 'bi-key-fill text-info',
        'title'      => 'ยืม-คืนอุปกรณ์และกุญแจ',
        'subtitle'   => 'สแกนเพื่อเข้าสู่หน้ายืม-คืนอุปกรณ์/กุญแจทันที',
        'url'        => $baseAbs . app_url('/assets_borrow.php'),
        'btnClass'   => 'btn-outline-info text-dark',
        'btnText'    => 'เปิดหน้ายืม-คืน',
        'filename'   => 'QR-ASSETS-BORROW.png',
    ],
    [
        'testid'     => 'qr-unified-maint',
        'badge'      => 'QR แจ้งซ่อมออนไลน์',
        'badgeClass' => 'text-bg-warning bg-opacity-25 text-dark border border-warning-subtle',
        'icon'       => 'bi-wrench-adjustable text-warning',
        'title'      => 'แจ้งซ่อมออนไลน์',
        'subtitle'   => 'สแกนเพื่อเข้าสู่หน้าแจ้งซ่อมออนไลน์ทันที',
        'url'        => $baseAbs . app_url('/maintenance.php'),
        'btnClass'   => 'btn-outline-warning text-dark',
        'btnText'    => 'เปิดหน้าแจ้งซ่อม',
        'filename'   => 'QR-MAINTENANCE.png',
    ],
];

$extraHead = <<<'HTML'
<style>
.qr-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 1.1rem;
    padding: 1.25rem;
    text-align: center;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    transition: transform .15s ease, box-shadow .15s ease;
}
.qr-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}
.qr-render-box {
    width: 176px;
    height: 176px;
    padding: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0.75rem auto;
}
.qr-render-box img,
.qr-render-box canvas {
    max-width: 100%;
    max-height: 100%;
    display: block;
}
@media print {
    .qr-card {
        break-inside: avoid;
        page-break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #94a3b8 !important;
    }
}
</style>
HTML;

require_once __DIR__ . '/includes/layout_header.php';
?>

<!-- Header Banner -->
<div class="welcome-card p-3 p-md-4 mb-4 no-print">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div>
            <div class="small text-primary fw-semibold mb-1"><i class="bi bi-qr-code me-1"></i>QR Code หลักของระบบ</div>
            <h1 class="h4 fw-bold text-dark mb-1">พิมพ์ QR Code</h1>
            <p class="small text-muted mb-0">QR Code ชุดเดียวใช้งานได้ทันทีสำหรับ จองห้องประชุม, จองยานพาหนะ, ยืม-คืนอุปกรณ์ และแจ้งซ่อมออนไลน์</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Toggle QR On/Off -->
            <form method="post" action="<?= e(app_url('/qr_codes.php')) ?>" class="d-inline-flex align-items-center" data-testid="qr-toggle-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_qr">
                <input type="hidden" name="qr_enabled" value="<?= $qrEnabled ? '0' : '1' ?>">
                <button type="submit"
                        class="btn <?= $qrEnabled ? 'btn-outline-success' : 'btn-outline-danger' ?> touch-target fw-semibold rounded-3 px-3"
                        data-testid="btn-toggle-qr">
                    <i class="bi <?= $qrEnabled ? 'bi-toggle-on text-success' : 'bi-toggle-off text-danger' ?> fs-5 me-1 align-middle"></i>
                    <span><?= $qrEnabled ? 'QR Code: เปิดใช้งานอยู่ (คลิกเพื่อปิด)' : 'QR Code: ปิดใช้งานอยู่ (คลิกเพื่อเปิด)' ?></span>
                </button>
            </form>

            <?php if ($qrEnabled): ?>
                <button type="button"
                        class="btn btn-primary-gradient touch-target fw-semibold rounded-3 px-3"
                        onclick="window.print()"
                        data-testid="btn-print-qr">
                    <i class="bi bi-printer me-1"></i>พิมพ์ QR Code
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$qrEnabled): ?>
    <div class="spotlight-card p-5 text-center" data-testid="qr-disabled-banner">
        <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger" style="width:68px;height:68px;">
            <i class="bi bi-qr-code-scan fs-2"></i>
        </div>
        <h2 class="h5 fw-bold text-dark mb-2">ระบบ QR Code ถูกปิดใช้งานอยู่</h2>
        <p class="text-muted small mx-auto mb-4" style="max-width: 520px;">
            ขณะนี้ระบบได้ปิดการสร้างและใช้งานทางลัด QR Code ชั่วคราว ผู้ใช้งานยังสามารถจองห้อง จองรถ ยืม-คืนอุปกรณ์ และแจ้งซ่อมผ่านเมนูหลักได้ตามปกติ
        </p>
        <form method="post" action="<?= e(app_url('/qr_codes.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_qr">
            <input type="hidden" name="qr_enabled" value="1">
            <button type="submit" class="btn btn-primary-gradient touch-target fw-semibold rounded-3 px-4">
                <i class="bi bi-power me-1"></i>เปิดใช้งานระบบ QR Code อีกครั้ง
            </button>
        </form>
    </div>
<?php else: ?>
    <div class="row g-4" data-testid="unified-qr-grid">
        <?php foreach ($unifiedQrs as $uq): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="qr-card" data-testid="<?= e($uq['testid']) ?>">
                    <div class="w-100">
                        <span class="badge <?= e($uq['badgeClass']) ?> mb-2">
                            <?= e($uq['badge']) ?>
                        </span>
                        <h2 class="h6 fw-bold text-dark mb-1">
                            <i class="bi <?= e($uq['icon']) ?> me-1"></i><?= e($uq['title']) ?>
                        </h2>
                        <div class="small text-muted"><?= e($uq['subtitle']) ?></div>
                    </div>

                    <div class="qr-render-box" data-qr-url="<?= e($uq['url']) ?>" aria-label="QR <?= e($uq['title']) ?>"></div>

                    <div class="w-100">
                        <div class="d-flex gap-2 justify-content-center no-print">
                            <a href="<?= e($uq['url']) ?>" class="btn btn-sm <?= e($uq['btnClass']) ?> rounded-3 px-3 fw-semibold">
                                <i class="bi bi-box-arrow-up-right me-1"></i><?= e($uq['btnText']) ?>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 btn-dl-qr" data-filename="<?= e($uq['filename']) ?>" title="บันทึกภาพ QR">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$qrScriptUrl = e(app_url('/assets/vendor/qrcode/qrcode.min.js'));
$extraScripts = <<<HTML
<script src="{$qrScriptUrl}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.qr-render-box[data-qr-url]').forEach(function (box) {
        var targetUrl = box.getAttribute('data-qr-url');
        if (!targetUrl || typeof QRCode === 'undefined') return;
        box.innerHTML = '';
        new QRCode(box, {
            text: targetUrl,
            width: 156,
            height: 156,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        setTimeout(function () {
            var img = box.querySelector('img');
            var canvas = box.querySelector('canvas');
            if (img && canvas && canvas.style.display === 'none') {
                canvas.remove();
            }
        }, 50);
    });

    document.querySelectorAll('.btn-dl-qr').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('.qr-card');
            if (!card) return;
            var img = card.querySelector('.qr-render-box img');
            var canvas = card.querySelector('.qr-render-box canvas');
            var dataUrl = (img && img.src && img.src.indexOf('data:image') === 0)
                ? img.src
                : (canvas ? canvas.toDataURL('image/png') : '');
            if (!dataUrl) return;
            var a = document.createElement('a');
            a.href = dataUrl;
            a.download = btn.getAttribute('data-filename') || 'qrcode.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    });
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_footer.php';
