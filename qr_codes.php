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

$assets = $pdo->query(
    'SELECT a.*, r.room_code, r.name AS room_name
     FROM assets a
     LEFT JOIN rooms r ON r.id = a.room_id
     WHERE a.is_active = 1
     ORDER BY a.category ASC, a.asset_code ASC'
)->fetchAll();

$rooms = $pdo->query(
    'SELECT *
     FROM rooms
     WHERE is_active = 1
     ORDER BY room_code ASC'
)->fetchAll();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:3000');
$baseAbs = $scheme . '://' . $host;

$categoryNames = [
    'equipment' => 'อุปกรณ์ทั่วไป',
    'key'       => 'กุญแจห้อง',
    'av'        => 'โสตทัศนูปกรณ์',
    'other'     => 'อื่น ๆ',
];

$extraHead = <<<'HTML'
<style>
.qr-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 1rem;
    padding: 1.1rem;
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
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}
.qr-render-box {
    width: 156px;
    height: 156px;
    padding: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0.5rem auto;
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
            <div class="small text-primary fw-semibold mb-1"><i class="bi bi-qr-code me-1"></i>QR Code ประจำอุปกรณ์และห้องประชุม</div>
            <h1 class="h4 fw-bold mb-1">พิมพ์ QR Code</h1>
            <p class="text-muted small mb-0">สร้างและพิมพ์ป้าย QR Code สำหรับสแกนยืม-คืนอุปกรณ์/กุญแจ และแจ้งซ่อมออนไลน์ได้ทันที</p>
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
            ขณะนี้ระบบได้ปิดการสร้างและใช้งานทางลัด QR Code ชั่วคราว ผู้ใช้งานยังสามารถยืม-คืนอุปกรณ์และแจ้งซ่อมผ่านเมนูหลักได้ตามปกติ
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
    <!-- Filter Tabs -->
    <div class="d-flex flex-wrap gap-2 mb-4 no-print" id="qrFilterTabs">
        <button type="button" class="btn btn-primary rounded-pill px-3 fw-semibold qr-filter-btn active" data-filter="all">
            ทั้งหมด (<?= (count($assets) * 2) + count($rooms) + 2 ?>)
        </button>
        <button type="button" class="btn btn-outline-primary rounded-pill px-3 fw-semibold qr-filter-btn" data-filter="borrow">
            <i class="bi bi-key me-1"></i>ยืม-คืนอุปกรณ์/กุญแจ (<?= count($assets) ?>)
        </button>
        <button type="button" class="btn btn-outline-primary rounded-pill px-3 fw-semibold qr-filter-btn" data-filter="maint-room">
            <i class="bi bi-building me-1"></i>แจ้งซ่อมห้องประชุม (<?= count($rooms) ?>)
        </button>
        <button type="button" class="btn btn-outline-primary rounded-pill px-3 fw-semibold qr-filter-btn" data-filter="maint-asset">
            <i class="bi bi-tools me-1"></i>แจ้งซ่อมอุปกรณ์ (<?= count($assets) ?>)
        </button>
    </div>

    <!-- General System QR Codes (always available even when inventory/rooms are empty) -->
    <div class="mb-5 qr-section" data-qr-section="general">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0"><i class="bi bi-qr-code-scan text-primary me-2"></i>QR Code หลักของระบบ (ใช้งานได้ทันที)</h2>
                <div class="small text-muted no-print">สำหรับติดหน้าห้องพัสดุ เคาน์เตอร์บริการ หรือบอร์ดประชาสัมพันธ์</div>
            </div>
            <span class="badge rounded-pill text-bg-dark">2 รายการ</span>
        </div>
        <div class="row g-3">
            <?php
            $generalBorrowUrl = $baseAbs . app_url('/assets_borrow.php');
            $generalMaintUrl = $baseAbs . app_url('/maintenance.php');
            $generalQrs = [
                [
                    'testid'   => 'qr-general-borrow',
                    'badge'    => 'QR กลาง · ยืม-คืนอุปกรณ์/กุญแจ',
                    'title'    => 'สแกนเพื่อยืม-คืนอุปกรณ์และกุญแจ',
                    'subtitle' => 'สแกนเพื่อเข้าสู่หน้ายืม-คืนอุปกรณ์/กุญแจ',
                    'url'      => $generalBorrowUrl,
                    'btnClass' => 'btn-outline-primary',
                    'btnText'  => 'เปิดหน้ายืม-คืน',
                    'filename' => 'QR-SYSTEM-BORROW.png',
                ],
                [
                    'testid'   => 'qr-general-maint',
                    'badge'    => 'QR กลาง · แจ้งซ่อมออนไลน์',
                    'title'    => 'สแกนเพื่อแจ้งซ่อมออนไลน์',
                    'subtitle' => 'สแกนเพื่อเข้าสู่หน้าแจ้งซ่อมออนไลน์',
                    'url'      => $generalMaintUrl,
                    'btnClass' => 'btn-outline-warning text-dark',
                    'btnText'  => 'เปิดหน้าแจ้งซ่อม',
                    'filename' => 'QR-SYSTEM-MAINT.png',
                ],
            ];
            foreach ($generalQrs as $gq): ?>
                <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                    <div class="qr-card" data-testid="<?= e($gq['testid']) ?>">
                        <div class="w-100">
                            <span class="badge text-bg-primary bg-opacity-10 text-primary border border-primary-subtle mb-1">
                                <?= e($gq['badge']) ?>
                            </span>
                            <div class="fw-bold text-dark"><?= e($gq['title']) ?></div>
                        </div>

                        <div class="qr-render-box" data-qr-url="<?= e($gq['url']) ?>" aria-label="<?= e($gq['title']) ?>"></div>

                        <div class="w-100">
                            <div class="small text-muted mb-2"><?= e($gq['subtitle']) ?></div>
                            <div class="d-flex gap-1 justify-content-center no-print">
                                <a href="<?= e($gq['url']) ?>" class="btn btn-sm <?= e($gq['btnClass']) ?> rounded-3 px-2">
                                    <i class="bi bi-box-arrow-up-right me-1"></i><?= e($gq['btnText']) ?>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 btn-dl-qr" data-filename="<?= e($gq['filename']) ?>" title="บันทึกภาพ QR">
                                    <i class="bi bi-download"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Section 1: Equipment & Key Borrow/Return QR Codes -->
    <div class="mb-5 qr-section" data-qr-section="borrow">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0"><i class="bi bi-key-fill text-primary me-2"></i>QR Code สำหรับยืม-คืนอุปกรณ์และกุญแจ</h2>
                <div class="small text-muted no-print">สแกนเพื่อเปิดหน้ายืม-คืนพร้อมเลือกรายการให้อัตโนมัติ</div>
            </div>
            <span class="badge rounded-pill text-bg-primary"><?= count($assets) ?> รายการ</span>
        </div>

        <?php if (empty($assets)): ?>
            <div class="spotlight-card p-4 text-center text-muted small">
                ยังไม่มีอุปกรณ์หรือกุญแจในระบบ — สามารถเพิ่มได้ที่หน้า <a href="<?= e(app_url('/assets_borrow.php')) ?>">ยืม-คืนอุปกรณ์/กุญแจ</a>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($assets as $ast):
                    $borrowUrl = $baseAbs . app_url('/assets_borrow.php?asset_id=' . (int)$ast['id'] . '&qr=1');
                    $catLabel = $categoryNames[$ast['category']] ?? 'อุปกรณ์';
                ?>
                    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                        <div class="qr-card" data-testid="qr-borrow-<?= e($ast['asset_code']) ?>">
                            <div class="w-100">
                                <span class="badge text-bg-primary bg-opacity-10 text-primary border border-primary-subtle mb-1">
                                    สแกนยืม-คืน · <?= e($catLabel) ?>
                                </span>
                                <div class="fw-bold text-dark small font-monospace"><?= e($ast['asset_code']) ?></div>
                                <div class="fw-semibold text-dark"><?= e($ast['name']) ?></div>
                            </div>

                            <div class="qr-render-box" data-qr-url="<?= e($borrowUrl) ?>" aria-label="QR <?= e($ast['asset_code']) ?>"></div>

                            <div class="w-100">
                                <?php if (!empty($ast['location'])): ?>
                                    <div class="small text-muted mb-2"><i class="bi bi-geo-alt me-1"></i><?= e($ast['location']) ?></div>
                                <?php endif; ?>
                                <div class="d-flex gap-1 justify-content-center no-print">
                                    <a href="<?= e($borrowUrl) ?>" class="btn btn-sm btn-outline-primary rounded-3 px-2" title="ทดสอบเปิดลิงก์">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>เปิดหน้ายืม-คืน
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 btn-dl-qr" data-filename="QR-BORROW-<?= e($ast['asset_code']) ?>.png" title="บันทึกภาพ QR">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Section 2: Room Maintenance QR Codes -->
    <div class="mb-5 qr-section" data-qr-section="maint-room">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0"><i class="bi bi-building-fill text-warning me-2"></i>QR Code แจ้งซ่อมประจำห้องประชุม</h2>
                <div class="small text-muted no-print">ติดหน้าห้องประชุมเพื่อให้ผู้ใช้งานสแกนแจ้งซ่อมประจำห้องได้ทันที</div>
            </div>
            <span class="badge rounded-pill text-bg-warning text-dark"><?= count($rooms) ?> ห้อง</span>
        </div>

        <?php if (empty($rooms)): ?>
            <div class="spotlight-card p-4 text-center text-muted small">ยังไม่มีข้อมูลห้องประชุมในระบบ</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($rooms as $rm):
                    $roomMaintUrl = $baseAbs . app_url('/maintenance.php?room_id=' . (int)$rm['id']);
                ?>
                    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                        <div class="qr-card" data-testid="qr-room-<?= e($rm['room_code']) ?>">
                            <div class="w-100">
                                <span class="badge text-bg-warning bg-opacity-25 text-dark border border-warning-subtle mb-1">สแกนแจ้งซ่อมห้อง</span>
                                <div class="fw-bold text-dark small font-monospace"><?= e($rm['room_code']) ?></div>
                                <div class="fw-semibold text-dark"><?= e($rm['name']) ?></div>
                            </div>

                            <div class="qr-render-box" data-qr-url="<?= e($roomMaintUrl) ?>" aria-label="QR <?= e($rm['room_code']) ?>"></div>

                            <div class="w-100">
                                <div class="small text-muted mb-2">
                                    <?= e($rm['building'] ?? '') ?>
                                    <?= !empty($rm['floor']) ? ' · ชั้น ' . e($rm['floor']) : '' ?>
                                </div>
                                <div class="d-flex gap-1 justify-content-center no-print">
                                    <a href="<?= e($roomMaintUrl) ?>" class="btn btn-sm btn-outline-warning text-dark rounded-3 px-2">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>ทดสอบลิงก์
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 btn-dl-qr" data-filename="QR-MAINT-<?= e($rm['room_code']) ?>.png" title="บันทึกภาพ QR">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Section 3: Asset Maintenance QR Codes -->
    <div class="mb-4 qr-section" data-qr-section="maint-asset">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0"><i class="bi bi-tools text-info me-2"></i>QR Code แจ้งซ่อมประจำอุปกรณ์</h2>
                <div class="small text-muted no-print">ติดบนตัวอุปกรณ์เพื่อให้ผู้ใช้งานสแกนแจ้งซ่อมอุปกรณ์ชิ้นนั้นได้ทันที</div>
            </div>
            <span class="badge rounded-pill text-bg-info text-dark"><?= count($assets) ?> รายการ</span>
        </div>

        <?php if (empty($assets)): ?>
            <div class="spotlight-card p-4 text-center text-muted small">ยังไม่มีข้อมูลอุปกรณ์ในระบบ</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($assets as $ast):
                    $assetMaintUrl = $baseAbs . app_url('/maintenance.php?asset_id=' . (int)$ast['id']);
                ?>
                    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                        <div class="qr-card" data-testid="qr-asset-maint-<?= e($ast['asset_code']) ?>">
                            <div class="w-100">
                                <span class="badge text-bg-info bg-opacity-25 text-dark border border-info-subtle mb-1">สแกนแจ้งซ่อมอุปกรณ์</span>
                                <div class="fw-bold text-dark small font-monospace"><?= e($ast['asset_code']) ?></div>
                                <div class="fw-semibold text-dark"><?= e($ast['name']) ?></div>
                            </div>

                            <div class="qr-render-box" data-qr-url="<?= e($assetMaintUrl) ?>" aria-label="QR <?= e($ast['asset_code']) ?>"></div>

                            <div class="w-100">
                                <?php if (!empty($ast['location'])): ?>
                                    <div class="small text-muted mb-2"><i class="bi bi-geo-alt me-1"></i><?= e($ast['location']) ?></div>
                                <?php endif; ?>
                                <div class="d-flex gap-1 justify-content-center no-print">
                                    <a href="<?= e($assetMaintUrl) ?>" class="btn btn-sm btn-outline-info text-dark rounded-3 px-2">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>ทดสอบลิงก์
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 btn-dl-qr" data-filename="QR-MAINT-<?= e($ast['asset_code']) ?>.png" title="บันทึกภาพ QR">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$qrScriptUrl = e(app_url('/assets/vendor/qrcode/qrcode.min.js'));
$extraScripts = <<<HTML
<script src="{$qrScriptUrl}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Render real scannable QR codes
    document.querySelectorAll('.qr-render-box[data-qr-url]').forEach(function (box) {
        var targetUrl = box.getAttribute('data-qr-url');
        if (!targetUrl || typeof QRCode === 'undefined') return;
        box.innerHTML = '';
        new QRCode(box, {
            text: targetUrl,
            width: 138,
            height: 138,
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

    // Filter tabs
    document.querySelectorAll('.qr-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var filter = btn.getAttribute('data-filter');
            document.querySelectorAll('.qr-filter-btn').forEach(function (b) {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline-primary');
            });
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary', 'active');

            document.querySelectorAll('.qr-section').forEach(function (sec) {
                if (filter === 'all' || sec.getAttribute('data-qr-section') === filter) {
                    sec.classList.remove('d-none');
                } else {
                    sec.classList.add('d-none');
                }
            });
        });
    });

    // Download QR PNG button
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
