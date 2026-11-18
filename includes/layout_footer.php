<?php
declare(strict_types=1);

$appName = defined('APP_NAME') ? APP_NAME : 'ระบบงานบริหารทั่วไป';
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'สำนักงานบริหารทั่วไป';
?>
    </main>
</div><!-- /.app-shell -->

<footer class="bg-white border-top py-3 px-3 text-center text-muted small no-print">
    <div class="container-fluid d-flex flex-column flex-sm-row align-items-center justify-content-between gap-1">
        <span>&copy; <?= (int)date('Y') + 543 ?> <strong><?= e($appName) ?></strong> · <?= e($appOrgName) ?></span>
        <span class="text-secondary">General Administration System</span>
    </div>
</footer>

<!-- Reusable Confirmation Modal -->
<div class="modal fade no-print" id="appConfirmModal" tabindex="-1" aria-labelledby="appConfirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="appConfirmModalTitle">
                    <i class="bi bi-question-circle-fill text-primary"></i>
                    <span>ยืนยันการดำเนินการ</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-4" id="appConfirmModalBody">คุณต้องการดำเนินการต่อหรือไม่?</div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal" data-testid="confirm-cancel-btn">ยกเลิก</button>
                <button type="button" class="btn btn-primary touch-target rounded-3 px-4 fw-semibold" id="appConfirmModalOk" data-testid="confirm-ok-btn">ยืนยัน</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3 no-print" id="appToastContainer" aria-live="polite" aria-atomic="true"></div>

<script src="<?= e(app_url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
<?php if (!empty($extraScripts)): ?>
    <?= $extraScripts ?>
<?php endif; ?>
</body>
</html>
