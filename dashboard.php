<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$allowedRoles = [ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN];
$user = require_role($allowedRoles);
$pdo = get_pdo();

$monthRaw = trim((string)($_GET['month'] ?? ''));
$monthWarning = null;
if ($monthRaw === '') {
    $month = date('Y-m');
} elseif (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthRaw) === 1) {
    $month = $monthRaw;
} else {
    $month = date('Y-m');
    $monthWarning = 'รูปแบบเดือนไม่ถูกต้อง ระบบแสดงข้อมูลเดือนปัจจุบันแทน';
}

// KPI counts for SSR render
$stmtBookingsMonth = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = :month AND status != 'rejected'"
);
$stmtBookingsMonth->execute([':month' => $month]);
$monthlyActiveBookings = (int)$stmtBookingsMonth->fetchColumn();

$stmtApprovedMonth = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = :month AND status = 'approved'"
);
$stmtApprovedMonth->execute([':month' => $month]);
$monthlyApprovedBookings = (int)$stmtApprovedMonth->fetchColumn();

$stmtMaintMonth = $pdo->prepare(
    "SELECT COUNT(*) FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = :month"
);
$stmtMaintMonth->execute([':month' => $month]);
$monthlyMaintTotal = (int)$stmtMaintMonth->fetchColumn();

$pendingApprovalsCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director')"
)->fetchColumn();

$pendingQueueRows = $pdo->query(
    "SELECT b.id, b.reference_code, b.booking_type, b.title, b.purpose, b.start_datetime, b.end_datetime,
            b.destination, b.status, u.full_name AS requester_name, r.room_code, r.name AS room_name
     FROM bookings b
     INNER JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.status IN ('pending_head', 'pending_vice', 'pending_director')
     ORDER BY b.start_datetime ASC
     LIMIT 6"
)->fetchAll();

$openMaintRows = $pdo->query(
    "SELECT m.id, m.ticket_code, m.equipment_name, m.location_text, m.issue_description, m.urgency, m.status,
            m.reported_at, m.repair_notes, u.full_name AS reporter_name
     FROM maintenance_requests m
     INNER JOIN users u ON u.id = m.reporter_id
     WHERE m.status != 'เสร็จสิ้น'
     ORDER BY (m.urgency = 'urgent') DESC, m.reported_at DESC
     LIMIT 6"
)->fetchAll();

$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];
$repairBadges = defined('REPAIR_STATUS_BADGES') ? REPAIR_STATUS_BADGES : [];

$pageTitle = 'แดชบอร์ดผู้บริหาร';
$activeNav = 'dashboard';
$chartJsUrl = app_url('/assets/vendor/chartjs/chart.umd.min.js');
$statsApiUrl = app_url('/api/dashboard_stats.php?month=' . urlencode($month));
$statsApiUrlJson = json_encode($statsApiUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$extraScripts = '<script src="' . e($chartJsUrl) . '"></script>' . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', async function () {
    const apiUrl = {$statsApiUrlJson};
    if (typeof Chart !== 'function') return;

    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Thai", sans-serif';
    Chart.defaults.color = '#64748b';

    try {
        const res = await fetch(apiUrl, { headers: { 'Accept': 'application/json' } });
        const payload = await res.json();
        if (!payload || payload.ok === false) return;

        const gridStyle = { color: '#f1f5f9', drawBorder: false };

        // Chart 1: Room Usage (Internal vs External by Room)
        const roomCanvas = document.getElementById('chartRoomUsage');
        if (roomCanvas && payload.room_usage && payload.room_usage.by_room) {
            const byRoom = payload.room_usage.by_room;
            new Chart(roomCanvas, {
                type: 'bar',
                data: {
                    labels: byRoom.labels || [],
                    datasets: [
                        {
                            label: 'บุคลากรภายใน',
                            data: byRoom.internal || [],
                            backgroundColor: '#2563EB',
                            borderRadius: 6,
                            maxBarThickness: 32
                        },
                        {
                            label: 'บุคคลภายนอก',
                            data: byRoom.external || [],
                            backgroundColor: '#38BDF8',
                            borderRadius: 6,
                            maxBarThickness: 32
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridStyle }
                    }
                }
            });
        }

        // Chart 2: Vehicle Usage
        const vehCanvas = document.getElementById('chartVehicleUsage');
        if (vehCanvas && payload.vehicle_usage) {
            new Chart(vehCanvas, {
                type: 'bar',
                data: {
                    labels: payload.vehicle_usage.labels || [],
                    datasets: [{
                        label: 'จำนวนเที่ยวการใช้งาน',
                        data: payload.vehicle_usage.data || [],
                        backgroundColor: '#10B981',
                        borderRadius: 6,
                        maxBarThickness: 36
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridStyle }
                    }
                }
            });
        }

        // Chart 3: Maintenance 4-Status Doughnut
        const maintCanvas = document.getElementById('chartMaintenanceStatus');
        if (maintCanvas && payload.maintenance_status) {
            const mData = payload.maintenance_status.data || [0, 0, 0, 0];
            const totalM = mData.reduce((a, b) => a + b, 0);
            new Chart(maintCanvas, {
                type: 'doughnut',
                data: {
                    labels: payload.maintenance_status.labels || [],
                    datasets: [{
                        label: 'งานซ่อมบำรุง',
                        data: totalM > 0 ? mData : [0, 0, 0, 0],
                        backgroundColor: ['#64748b', '#2563eb', '#f59e0b', '#10b981'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
                }
            });
        }

        // Chart 4: Pending Approval Queue by Step
        const pendCanvas = document.getElementById('chartPendingQueue');
        if (pendCanvas && payload.pending_queue) {
            new Chart(pendCanvas, {
                type: 'bar',
                data: {
                    labels: payload.pending_queue.labels || [],
                    datasets: [{
                        label: 'คำขอรออนุมัติค้างพิจารณา',
                        data: payload.pending_queue.data || [0, 0, 0],
                        backgroundColor: ['#f59e0b', '#06b6d4', '#4f46e5'],
                        borderRadius: 6,
                        maxBarThickness: 42
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridStyle }
                    }
                }
            });
        }
    } catch (err) {
        console.error(err);
    }
});
</script>
HTML;

require __DIR__ . '/includes/layout_header.php';
?>
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-speedometer2 me-1"></i>สรุปภาพรวมการบริหารทรัพยากร · เดือน <?= e($month) ?>
            </div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="dashboard-heading">แดชบอร์ดผู้บริหาร</h1>
            <p class="small text-muted mb-0">
                ติดตามสถิติการใช้ห้องประชุม ยานพาหนะ คำขอรออนุมัติ และสถานะงานแจ้งซ่อมแบบเรียลไทม์
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            <form method="get" action="<?= e(app_url('/dashboard.php')) ?>" class="d-flex align-items-center gap-2 flex-shrink-0">
                <label class="visually-hidden" for="monthFilter">เลือกเดือน</label>
                <input type="month"
                       class="form-control w-auto touch-target rounded-3"
                       id="monthFilter"
                       name="month"
                       value="<?= e($month) ?>">
                <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-3 fw-semibold text-nowrap flex-shrink-0">
                    <i class="bi bi-funnel-fill me-1"></i>กรองเดือน
                </button>
            </form>

            <a href="<?= e(app_url('/export_csv.php?type=bookings&month=' . urlencode($month))) ?>"
               class="btn btn-soft-success touch-target d-inline-flex align-items-center gap-1 rounded-3 px-3 fw-semibold text-nowrap"
               data-export="bookings">
                <i class="bi bi-file-earmark-spreadsheet"></i>
                <span>CSV การจอง</span>
            </a>
            <a href="<?= e(app_url('/export_csv.php?type=maintenance&month=' . urlencode($month))) ?>"
               class="btn btn-soft-secondary touch-target d-inline-flex align-items-center gap-1 rounded-3 px-3 fw-semibold text-nowrap"
               data-export="maintenance">
                <i class="bi bi-file-earmark-spreadsheet"></i>
                <span>CSV งานซ่อม</span>
            </a>
        </div>
    </div>
    <?php if ($monthWarning !== null): ?>
        <div class="alert alert-warning small mt-3 mb-0 rounded-3"><?= e($monthWarning) ?></div>
    <?php endif; ?>
</div>

<!-- 4 KPI Cards -->
<div class="row g-3 mb-4" data-testid="dashboard-kpis">
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-accent-blue border-0 p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-blue"><i class="bi bi-calendar2-check-fill"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label mb-1">การจองเดือนนี้</div>
                    <div class="fs-3 fw-bold text-dark lh-1"><?= $monthlyActiveBookings ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-accent-amber border-0 p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-amber"><i class="bi bi-hourglass-split"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label mb-1">รออนุมัติทั้งหมด</div>
                    <div class="fs-3 fw-bold text-dark lh-1"><?= $pendingApprovalsCount ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-accent-indigo border-0 p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-indigo"><i class="bi bi-wrench-adjustable-circle-fill"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label mb-1">งานแจ้งซ่อมเดือนนี้</div>
                    <div class="fs-3 fw-bold text-dark lh-1"><?= $monthlyMaintTotal ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-accent-emerald border-0 p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="kpi-icon kpi-icon-emerald"><i class="bi bi-check-circle-fill"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label mb-1">อนุมัติแล้วเดือนนี้</div>
                    <div class="fs-3 fw-bold text-dark lh-1"><?= $monthlyApprovedBookings ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4 Chart.js v4 Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-door-open-fill text-primary me-2"></i>สถิติการใช้ห้องประชุม (ภายใน vs ภายนอก)</h2>
            </div>
            <div class="card-body min-w-0">
                <div class="chart-container">
                    <canvas id="chartRoomUsage"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-truck text-success me-2"></i>สถิติการใช้ยานพาหนะส่วนกลาง</h2>
            </div>
            <div class="card-body min-w-0">
                <div class="chart-container">
                    <canvas id="chartVehicleUsage"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-tools text-indigo me-2"></i>สัดส่วนสถานะงานซ่อมบำรุง (4 สถานะ)</h2>
            </div>
            <div class="card-body min-w-0">
                <div class="chart-container">
                    <canvas id="chartMaintenanceStatus"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-list-check text-warning me-2"></i>คำขอรออนุมัติแยกตามขั้นตอน</h2>
            </div>
            <div class="card-body min-w-0">
                <div class="chart-container">
                    <canvas id="chartPendingQueue"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Action Queues -->
<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="spotlight-card h-100">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-check2-square text-primary me-2"></i>คำขอรออนุมัติล่าสุด</h2>
                <a href="<?= e(app_url('/approvals.php')) ?>" class="small fw-semibold text-decoration-none">เปิดหน้าอนุมัติ</a>
            </div>
            <?php if (empty($pendingQueueRows)): ?>
                <div class="p-4 text-center text-muted small">ไม่มีคำขอรอพิจารณาในขณะนี้</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>เรื่อง / ผู้ขอ</th>
                                <th>สถานะ</th>
                                <th class="text-end">ดู</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingQueueRows as $pq):
                                $meta = $statusLabels[$pq['status']] ?? ['label' => $pq['status'], 'badge' => 'secondary'];
                                $isRm = ($pq['booking_type'] === 'room');
                                $swalPq = [
                                    'title'     => (string)$pq['title'],
                                    'subtitle'  => $pq['reference_code'] . ' · ' . $pq['requester_name'],
                                    'badge'     => (string)$meta['label'],
                                    'rows'      => [
                                        ['label' => 'ประเภท', 'value' => $isRm ? 'จองห้องประชุม' : 'จองยานพาหนะ'],
                                        ['label' => $isRm ? 'ห้องประชุม' : 'ปลายทาง', 'value' => $isRm ? trim(($pq['room_code'] ?? '') . ' ' . ($pq['room_name'] ?? '')) : (string)($pq['destination'] ?: '-')],
                                        ['label' => 'วันเวลา', 'value' => thai_date($pq['start_datetime'], true, true) . ' – ' . date('H:i', strtotime($pq['end_datetime'])) . ' น.'],
                                    ],
                                    'noteTitle' => 'วัตถุประสงค์',
                                    'note'      => (string)($pq['purpose'] ?: ''),
                                    'links'     => [
                                        ['label' => 'ไปหน้าพิจารณาอนุมัติ', 'href' => app_url('/approvals.php'), 'variant' => 'primary'],
                                        ['label' => 'ดูไทม์ไลน์', 'href' => app_url('/booking_detail.php?id=' . (int)$pq['id']), 'variant' => 'secondary'],
                                    ],
                                ];
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($pq['reference_code']) ?></span>
                                            <span class="fw-semibold text-dark text-truncate" style="max-width: 185px;"><?= e($pq['title']) ?></span>
                                        </div>
                                        <div class="text-muted"><?= e($pq['requester_name']) ?> · <?= e(thai_date($pq['start_datetime'], false, true)) ?></div>
                                    </td>
                                    <td><span class="badge text-bg-<?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button"
                                                class="btn btn-sm btn-soft-secondary rounded-3 me-1"
                                                data-swal-detail="<?= e((string)json_encode($swalPq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                                title="ดูเร็ว">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <a href="<?= e(app_url('/booking_detail.php?id=' . (int)$pq['id'])) ?>" class="btn btn-sm btn-soft-primary rounded-3">ดู</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="spotlight-card h-100">
            <div class="spotlight-header">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-wrench-adjustable text-danger me-2"></i>งานแจ้งซ่อมค้างดำเนินการ</h2>
                <?php if (has_role([ROLE_STAFF, ROLE_ADMIN], $user)): ?>
                    <a href="<?= e(app_url('/maintenance_manage.php')) ?>" class="small fw-semibold text-decoration-none">จัดการงานซ่อม</a>
                <?php endif; ?>
            </div>
            <?php if (empty($openMaintRows)): ?>
                <div class="p-4 text-center text-muted small">ไม่มีงานแจ้งซ่อมค้างดำเนินการ</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>งานซ่อม / สถานที่</th>
                                <th>สถานะ</th>
                                <th class="text-end">ดู</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($openMaintRows as $om):
                                $bCls = $repairBadges[$om['status']] ?? 'secondary';
                                $swalOm = [
                                    'title'     => (string)($om['equipment_name'] ?: 'ใบแจ้งซ่อม'),
                                    'subtitle'  => $om['ticket_code'] . ' · ' . thai_date($om['reported_at'], true, true),
                                    'badge'     => (string)$om['status'],
                                    'rows'      => [
                                        ['label' => 'สถานที่',    'value' => (string)$om['location_text']],
                                        ['label' => 'ผู้แจ้งซ่อม', 'value' => (string)$om['reporter_name']],
                                        ['label' => 'ความเร่งด่วน', 'value' => $om['urgency'] === 'urgent' ? 'งานด่วน' : 'ปกติ'],
                                        ['label' => 'อาการชำรุด',  'value' => (string)$om['issue_description']],
                                    ],
                                    'noteTitle' => 'บันทึกช่างล่าสุด',
                                    'note'      => (string)($om['repair_notes'] ?: ''),
                                ];
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <span class="badge text-bg-light border text-dark font-monospace"><?= e($om['ticket_code']) ?></span>
                                            <?php if ($om['urgency'] === 'urgent'): ?>
                                                <span class="badge text-bg-danger">ด่วน</span>
                                            <?php endif; ?>
                                            <span class="fw-semibold text-dark text-truncate" style="max-width: 170px;"><?= e($om['equipment_name'] ?: '-') ?></span>
                                        </div>
                                        <div class="text-muted text-truncate" style="max-width: 240px;"><?= e($om['location_text']) ?></div>
                                    </td>
                                    <td><span class="badge text-bg-<?= e($bCls) ?>"><?= e($om['status']) ?></span></td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-soft-secondary rounded-3"
                                                data-swal-detail="<?= e((string)json_encode($swalOm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                                title="ดูรายละเอียด">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
