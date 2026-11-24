<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();
$pdo = get_pdo();
ensure_saved_memos_schema($pdo);

$pageTitle = 'คลังบันทึกข้อความ';
$activeNav = 'saved_memos';
$uid = (int)$user['id'];
$isPrivileged = has_role(PRIVILEGED_VIEW_ROLES, $user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete_memo') {
        $memoId = (int)($_POST['memo_id'] ?? 0);
        if ($memoId > 0) {
            if ($isPrivileged) {
                $stmtDel = $pdo->prepare('DELETE FROM saved_memos WHERE id = :id');
                $stmtDel->execute([':id' => $memoId]);
            } else {
                $stmtDel = $pdo->prepare('DELETE FROM saved_memos WHERE id = :id AND saved_by = :uid');
                $stmtDel->execute([':id' => $memoId, ':uid' => $uid]);
            }
            if ($stmtDel->rowCount() > 0) {
                flash('success', 'ลบบันทึกข้อความออกจากคลังเรียบร้อยแล้ว');
            } else {
                flash('warning', 'ไม่พบบันทึกข้อความที่ต้องการลบ หรือไม่มีสิทธิ์ลบ');
            }
        }
        redirect(app_url('/saved_memos.php'));
    }
}

$filter = trim((string)($_GET['status'] ?? 'all'));
if (!in_array($filter, ['all', 'approved', 'pending'], true)) {
    $filter = 'all';
}

$whereParts = [];
$params = [];
if (!$isPrivileged) {
    $whereParts[] = '(sm.saved_by = :uid OR b.requester_id = :uid2)';
    $params[':uid'] = $uid;
    $params[':uid2'] = $uid;
}
if ($filter === 'approved') {
    $whereParts[] = "sm.booking_status = 'approved'";
} elseif ($filter === 'pending') {
    $whereParts[] = "sm.booking_status IN ('pending_head', 'pending_vice', 'pending_director')";
}

$whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
$stmt = $pdo->prepare(
    "SELECT sm.*, u.full_name AS saved_by_name
     FROM saved_memos sm
     LEFT JOIN bookings b ON b.id = sm.booking_id
     LEFT JOIN users u ON u.id = sm.saved_by
     {$whereSql}
     ORDER BY sm.created_at DESC, sm.id DESC"
);
$stmt->execute($params);
$savedMemos = $stmt->fetchAll();

// Also fetch recent bookings (both approved & pending) so users can download or save directly
$bkWhere = $isPrivileged ? '1 = 1' : 'b.requester_id = :buid';
$stmtBk = $pdo->prepare(
    "SELECT b.id, b.reference_code, b.booking_type, b.title, b.status, b.start_datetime,
            u.full_name AS requester_name
     FROM bookings b
     INNER JOIN users u ON u.id = b.requester_id
     WHERE {$bkWhere}
     ORDER BY b.id DESC
     LIMIT 30"
);
if ($isPrivileged) {
    $stmtBk->execute();
} else {
    $stmtBk->execute([':buid' => $uid]);
}
$recentBookings = $stmtBk->fetchAll();

$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];

require __DIR__ . '/includes/layout_header.php';
?>

<!-- Header Banner -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <div class="small text-primary fw-semibold mb-1"><i class="bi bi-journal-bookmark-fill me-1"></i>คลังจัดเก็บบันทึกข้อความถาวร</div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="saved-memos-heading">คลังบันทึกข้อความ</h1>
            <p class="small text-muted mb-0">บันทึกข้อความที่เก็บไว้จะไม่หายจนกว่าจะกดลบเอง และสามารถดาวน์โหลดได้ทั้งรายการที่อนุมัติแล้วและรออนุมัติ</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= e(app_url('/my_bookings.php')) ?>" class="btn btn-soft-primary touch-target rounded-3 px-3 fw-semibold">
                <i class="bi bi-journal-text me-1"></i>รายการคำขอของฉัน
            </a>
        </div>
    </div>
</div>

<!-- Saved Memos Archive -->
<div class="spotlight-card mb-4">
    <div class="spotlight-header flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-archive-fill text-primary me-1"></i>บันทึกข้อความที่เก็บไว้</h2>
            <span class="badge rounded-pill text-bg-primary"><?= count($savedMemos) ?> ฉบับ</span>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="<?= e(app_url('/saved_memos.php?status=all')) ?>" class="btn btn-sm rounded-pill px-3 <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">ทั้งหมด</a>
            <a href="<?= e(app_url('/saved_memos.php?status=approved')) ?>" class="btn btn-sm rounded-pill px-3 <?= $filter === 'approved' ? 'btn-success' : 'btn-outline-secondary' ?>">อนุมัติแล้ว</a>
            <a href="<?= e(app_url('/saved_memos.php?status=pending')) ?>" class="btn btn-sm rounded-pill px-3 <?= $filter === 'pending' ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">รออนุมัติ</a>
        </div>
    </div>

    <?php if (empty($savedMemos)): ?>
        <div class="p-4 text-center text-muted small">
            ยังไม่มีบันทึกข้อความที่เก็บไว้ในคลัง — สามารถเปิดบันทึกข้อความของคำขอที่ต้องการแล้วกดปุ่ม <strong>เก็บเข้าคลังบันทึกข้อความ</strong> ได้เลยครับ
        </div>
    <?php else: ?>
        <?php foreach ($savedMemos as $sm):
            $stCode = (string)$sm['booking_status'];
            $stBadge = $statusLabels[$stCode]['badge'] ?? 'secondary';
        ?>
            <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" data-testid="saved-memo-row-<?= (int)$sm['id'] ?>">
                <div class="min-w-0 flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge text-bg-light border text-dark font-monospace"><?= e($sm['reference_code']) ?></span>
                        <span class="badge text-bg-<?= e($stBadge) ?>"><?= e($sm['status_label']) ?></span>
                        <span class="badge text-bg-light border text-secondary">
                            <?= $sm['booking_type'] === 'room' ? 'ห้องประชุม/เรียน' : 'ยานพาหนะ' ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark"><?= e($sm['title']) ?></div>
                    <div class="small text-muted mt-1">
                        <span>ผู้ขอ: <strong><?= e($sm['requester_name']) ?></strong></span>
                        <span class="mx-1">·</span>
                        <span>เก็บเข้าคลังเมื่อ: <?= e(thai_date($sm['created_at'], true, true)) ?></span>
                        <?php if (!empty($sm['saved_by_name'])): ?>
                            <span class="mx-1">·</span>
                            <span>โดย: <?= e($sm['saved_by_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                    <a href="<?= e(app_url('/print_memo.php?saved_id=' . (int)$sm['id'])) ?>"
                       class="btn btn-sm btn-soft-primary rounded-3 px-3 fw-semibold">
                        <i class="bi bi-printer me-1"></i>เปิดดู / PDF
                    </a>
                    <a href="<?= e(app_url('/print_memo.php?saved_id=' . (int)$sm['id'] . '&download=doc')) ?>"
                       class="btn btn-sm btn-soft-success rounded-3 px-3 fw-semibold"
                       data-testid="btn-download-saved-doc-<?= (int)$sm['id'] ?>">
                        <i class="bi bi-download me-1"></i>ดาวน์โหลด (.doc)
                    </a>
                    <form method="post"
                          action="<?= e(app_url('/saved_memos.php')) ?>"
                          class="d-inline"
                          data-confirm="ต้องการลบบันทึกข้อความ &quot;<?= e($sm['reference_code'] . ' — ' . $sm['title']) ?>&quot; ออกจากคลังใช่หรือไม่?"
                          data-confirm-danger="1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_memo">
                        <input type="hidden" name="memo_id" value="<?= (int)$sm['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2" title="ลบบันทึกข้อความ" data-testid="btn-delete-saved-memo-<?= (int)$sm['id'] ?>">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Quick Download for Approved & Pending Bookings -->
<div class="spotlight-card">
    <div class="spotlight-header">
        <h2 class="h6 fw-bold mb-0"><i class="bi bi-file-earmark-arrow-down text-success me-1"></i>ดาวน์โหลด / เก็บบันทึกข้อความจากคำขอ (ทั้งอนุมัติแล้วและรออนุมัติ)</h2>
        <span class="badge rounded-pill text-bg-light border text-secondary"><?= count($recentBookings) ?> รายการล่าสุด</span>
    </div>

    <?php if (empty($recentBookings)): ?>
        <div class="p-4 text-center text-muted small">ยังไม่มีรายการคำขอจองในระบบ</div>
    <?php else: ?>
        <?php foreach ($recentBookings as $bk):
            $stCode = (string)$bk['status'];
            $stMeta = $statusLabels[$stCode] ?? ['label' => $stCode, 'badge' => 'secondary'];
        ?>
            <div class="role-queue-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge text-bg-light border text-dark font-monospace"><?= e($bk['reference_code']) ?></span>
                        <span class="badge text-bg-<?= e($stMeta['badge']) ?>"><?= e($stMeta['label']) ?></span>
                    </div>
                    <div class="fw-semibold text-dark small"><?= e($bk['title']) ?></div>
                    <div class="small text-muted">
                        ผู้ขอ: <?= e($bk['requester_name']) ?> · <?= e(thai_date($bk['start_datetime'], true, true)) ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                    <a href="<?= e(app_url('/print_memo.php?id=' . (int)$bk['id'])) ?>" class="btn btn-sm btn-outline-primary rounded-3 px-3">
                        <i class="bi bi-eye me-1"></i>เปิดบันทึก / เก็บเข้าคลัง
                    </a>
                    <a href="<?= e(app_url('/print_memo.php?id=' . (int)$bk['id'] . '&download=doc')) ?>" class="btn btn-sm btn-outline-success rounded-3 px-3">
                        <i class="bi bi-download me-1"></i>ดาวน์โหลด (.doc)
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
