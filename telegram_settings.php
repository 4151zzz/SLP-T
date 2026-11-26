<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_role([ROLE_STAFF, ROLE_ADMIN]);
$pdo = get_pdo();

$pageTitle = 'ตั้งค่าแจ้งเตือน Telegram';
$activeNav = 'telegram_settings';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'toggle_telegram') {
        $cur = get_telegram_settings($pdo);
        $nextEnabled = ((string)($_POST['telegram_enabled'] ?? '0') === '1');
        save_telegram_settings($pdo, $nextEnabled, $cur['bot_token'], $cur['chat_id']);
        flash('success', $nextEnabled
            ? 'เปิดใช้งานการแจ้งเตือนผ่าน Telegram เรียบร้อยแล้ว'
            : 'ปิดใช้งานการแจ้งเตือนผ่าน Telegram เรียบร้อยแล้ว');
        redirect(app_url('/telegram_settings.php'));
    } elseif ($action === 'save_telegram') {
        $enabled = isset($_POST['telegram_enabled']) && $_POST['telegram_enabled'] === '1';
        $botToken = trim((string)($_POST['telegram_bot_token'] ?? ''));
        $chatId = trim((string)($_POST['telegram_chat_id'] ?? ''));

        if ($enabled && ($botToken === '' || $chatId === '')) {
            flash('warning', 'หากต้องการเปิดใช้งาน กรุณากรอกทั้ง Bot Token และ Chat ID ให้ครบถ้วน');
            redirect(app_url('/telegram_settings.php'));
        }

        save_telegram_settings($pdo, $enabled, $botToken, $chatId);
        flash('success', 'บันทึกการตั้งค่าการแจ้งเตือน Telegram เรียบร้อยแล้ว');
        redirect(app_url('/telegram_settings.php'));
    } elseif ($action === 'test_telegram') {
        $cur = get_telegram_settings($pdo);
        if ($cur['bot_token'] === '' || $cur['chat_id'] === '') {
            flash('danger', 'กรุณากรอกและบันทึก Bot Token กับ Chat ID ก่อนทดสอบส่งข้อความ');
            redirect(app_url('/telegram_settings.php'));
        }
        $testMsg = "✅ <b>ทดสอบการแจ้งเตือนสำเร็จ</b>\n"
            . "ระบบงานบริหารทั่วไป โรงเรียนสระหลวงพิทยาคม\n"
            . "ผู้ทดสอบ: " . htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "\n"
            . "เวลา: " . date('d/m/Y H:i:s');
        $res = send_telegram_message($pdo, $testMsg, true);
        if ($res['ok']) {
            flash('success', 'ส่งข้อความทดสอบไปยัง Telegram สำเร็จแล้ว กรุณาตรวจสอบในแชท/กลุ่ม Telegram ของคุณ');
        } else {
            flash('danger', 'ไม่สามารถส่งข้อความ Telegram ได้: ' . ($res['error'] ?: 'กรุณาตรวจสอบ Bot Token และ Chat ID'));
        }
        redirect(app_url('/telegram_settings.php'));
    }
}

$tg = get_telegram_settings($pdo);

require __DIR__ . '/includes/layout_header.php';
?>

<!-- Header Banner -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div>
            <div class="small text-primary fw-semibold mb-1"><i class="bi bi-telegram me-1"></i>ระบบแจ้งเตือนอัตโนมัติ</div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="telegram-heading">ตั้งค่าแจ้งเตือนผ่าน Telegram</h1>
            <p class="small text-muted mb-0">แจ้งเตือนไปยังกลุ่มหรือแชท Telegram ทันทีเมื่อมีการจองห้องประชุม จองรถ พิจารณาอนุมัติ หรือแจ้งซ่อมออนไลน์</p>
        </div>

        <form method="post" action="<?= e(app_url('/telegram_settings.php')) ?>" class="d-inline-flex align-items-center flex-shrink-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_telegram">
            <input type="hidden" name="telegram_enabled" value="<?= $tg['enabled'] ? '0' : '1' ?>">
            <button type="submit"
                    class="btn <?= $tg['enabled'] ? 'btn-outline-success' : 'btn-outline-danger' ?> touch-target fw-semibold rounded-3 px-3"
                    data-testid="btn-toggle-telegram">
                <i class="bi <?= $tg['enabled'] ? 'bi-toggle-on text-success' : 'bi-toggle-off text-danger' ?> fs-5 me-1 align-middle"></i>
                <span><?= $tg['enabled'] ? 'Telegram: เปิดใช้งานอยู่ (คลิกเพื่อปิด)' : 'Telegram: ปิดใช้งานอยู่ (คลิกเพื่อเปิด)' ?></span>
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="spotlight-card p-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-gear-fill text-primary me-1"></i>กำหนดค่า Bot Token และ Chat ID</h2>
                <span class="badge <?= $tg['enabled'] ? 'text-bg-success' : 'text-bg-secondary' ?>" data-testid="telegram-status-badge">
                    <?= $tg['enabled'] ? 'กำลังเปิดใช้งาน' : 'ปิดใช้งานอยู่' ?>
                </span>
            </div>

            <form method="post" action="<?= e(app_url('/telegram_settings.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_telegram">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           id="tgEnabledSwitch"
                           name="telegram_enabled"
                           value="1"
                           <?= $tg['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="tgEnabledSwitch">
                        เปิดใช้งานการส่งแจ้งเตือนผ่าน Telegram
                    </label>
                    <div class="form-text small">สามารถปิดสวิตช์นี้ได้ทันทีหากไม่ต้องการรับการแจ้งเตือนผ่าน Telegram (ระบบจองยังคงทำงานตามปกติ)</div>
                </div>

                <div class="mb-3">
                    <label for="tgBotToken" class="form-label fw-semibold small text-secondary">Telegram Bot Token</label>
                    <input type="text"
                           class="form-control touch-target font-monospace"
                           id="tgBotToken"
                           name="telegram_bot_token"
                           value="<?= e($tg['bot_token']) ?>"
                           placeholder="เช่น 123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
                           data-testid="input-tg-token">
                </div>

                <div class="mb-4">
                    <label for="tgChatId" class="form-label fw-semibold small text-secondary">Telegram Chat ID / Group ID</label>
                    <input type="text"
                           class="form-control touch-target font-monospace"
                           id="tgChatId"
                           name="telegram_chat_id"
                           value="<?= e($tg['chat_id']) ?>"
                           placeholder="เช่น -1001234567890 หรือ 987654321"
                           data-testid="input-tg-chat-id">
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary-gradient touch-target fw-semibold rounded-3 px-4" data-testid="btn-save-telegram">
                        <i class="bi bi-save me-1"></i>บันทึกการตั้งค่า
                    </button>
                </div>
            </form>

            <hr class="my-4">

            <form method="post" action="<?= e(app_url('/telegram_settings.php')) ?>" class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test_telegram">
                <div class="small text-muted">บันทึก Bot Token และ Chat ID แล้วกดทดสอบเพื่อตรวจว่าบอทส่งเข้าแชทได้จริง</div>
                <button type="submit" class="btn btn-outline-primary touch-target fw-semibold rounded-3 px-3 flex-shrink-0" data-testid="btn-test-telegram">
                    <i class="bi bi-send me-1"></i>ทดสอบส่งข้อความ
                </button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="spotlight-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-question-circle-fill text-info me-1"></i>วิธีสร้าง Bot และหา Chat ID</h2>
            <ol class="small text-secondary ps-3 mb-3 d-flex flex-column gap-2">
                <li>เปิดแอป Telegram แล้วค้นหา <strong>@BotFather</strong></li>
                <li>พิมพ์คำสั่ง <code>/newbot</code> ตั้งชื่อบอทและ username (ลงท้ายด้วย <code>bot</code>) เพื่อรับค่า <strong>Bot Token</strong></li>
                <li>หากต้องการแจ้งเตือนเข้า <strong>กลุ่ม</strong> ให้เชิญบอทเข้ากลุ่มนั้น แล้วพิมพ์ข้อความสัก 1 ข้อความ</li>
                <li>ค้นหา <strong>@userinfobot</strong> หรือเปิดลิงก์ <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> เพื่อดูค่า <strong>Chat ID</strong> (กลุ่มมักขึ้นต้นด้วย <code>-100...</code>)</li>
                <li>นำค่าทั้งสองมากรอกในช่องด้านซ้าย กด <strong>บันทึกการตั้งค่า</strong> แล้วกด <strong>ทดสอบส่งข้อความ</strong></li>
            </ol>
            <div class="alert alert-info border-0 rounded-3 small mb-0">
                <i class="bi bi-info-circle-fill me-1"></i>
                เหตุการณ์ที่จะแจ้งเตือนอัตโนมัติ: คำขอจองห้องประชุมใหม่, คำขอจองยานพาหนะใหม่, การพิจารณาอนุมัติ/ส่งกลับ/ไม่อนุมัติ และใบแจ้งซ่อมออนไลน์ใหม่
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
