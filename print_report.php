<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = require_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN]);
$pdo = get_pdo();

$pageTitle = 'พิมพ์รายงานสรุป (PDF)';
$activeNav = 'print_report';

$period = trim((string)($_GET['period'] ?? 'month'));
if (!in_array($period, ['day', 'month', 'year'], true)) {
    $period = 'month';
}

$dateParam = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) !== 1) {
    $dateParam = date('Y-m-d');
}

$monthParam = trim((string)($_GET['month'] ?? date('Y-m')));
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) !== 1) {
    $monthParam = date('Y-m');
}

$yearParam = trim((string)($_GET['year'] ?? date('Y')));
if (preg_match('/^\d{4}$/', $yearParam) !== 1) {
    $yearParam = date('Y');
}

$thaiMonthsFull = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
];
$thaiMonthsShort = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

if ($period === 'day') {
    $startSql = $dateParam . ' 00:00:00';
    $endSql = $dateParam . ' 23:59:59';
    $periodHeading = 'รายงานสรุปประจำวัน';
    $periodSub = 'วันที่ ' . thai_date($dateParam, false, false);
} elseif ($period === 'year') {
    $yInt = (int)$yearParam;
    $startSql = sprintf('%04d-01-01 00:00:00', $yInt);
    $endSql = sprintf('%04d-12-31 23:59:59', $yInt);
    $periodHeading = 'รายงานสรุปประจำปี';
    $periodSub = 'ปีงบประมาณ / ปี พ.ศ. ' . ($yInt + 543);
} else {
    [$my, $mm] = array_map('intval', explode('-', $monthParam));
    $daysInMonth = (int)date('t', strtotime($monthParam . '-01'));
    $startSql = sprintf('%04d-%02d-01 00:00:00', $my, $mm);
    $endSql = sprintf('%04d-%02d-%02d 23:59:59', $my, $mm, $daysInMonth);
    $periodHeading = 'รายงานสรุปประจำเดือน';
    $periodSub = 'เดือน ' . ($thaiMonthsFull[$mm] ?? '') . ' พ.ศ. ' . ($my + 543);
}

// Fetch bookings in period
$stmtBookings = $pdo->prepare(
    'SELECT b.*,
            u.full_name AS requester_name,
            r.room_code, r.name AS room_name,
            v.vehicle_code, v.brand_model, v.plate_number
     FROM bookings b
     INNER JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     WHERE b.start_datetime BETWEEN :start AND :end
     ORDER BY b.start_datetime ASC, b.id ASC'
);
$stmtBookings->execute([':start' => $startSql, ':end' => $endSql]);
$bookings = $stmtBookings->fetchAll();

// Maintenance & Borrowings stats in period
$stmtMaint = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM maintenance_requests WHERE reported_at BETWEEN :start AND :end GROUP BY status');
$stmtMaint->execute([':start' => $startSql, ':end' => $endSql]);
$maintTotal = 0;
$maintDone = 0;
foreach ($stmtMaint->fetchAll() as $mr) {
    $maintTotal += (int)$mr['cnt'];
    if ((string)$mr['status'] === 'ซ่อมเสร็จ') {
        $maintDone += (int)$mr['cnt'];
    }
}

$stmtBorrow = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM asset_borrowings WHERE borrowed_at BETWEEN :start AND :end GROUP BY status');
$stmtBorrow->execute([':start' => $startSql, ':end' => $endSql]);
$borrowTotal = 0;
$borrowReturned = 0;
foreach ($stmtBorrow->fetchAll() as $br) {
    $borrowTotal += (int)$br['cnt'];
    if ((string)$br['status'] === 'returned') {
        $borrowReturned += (int)$br['cnt'];
    }
}

// Aggregate KPIs & Chart datasets
$totalBookings = count($bookings);
$roomCount = 0;
$vehicleCount = 0;
$approvedCount = 0;
$pendingCount = 0;
$rejectedCount = 0;

$trendLabels = [];
$trendRoom = [];
$trendVehicle = [];

if ($period === 'day') {
    for ($h = 7; $h <= 19; $h++) {
        $k = sprintf('%02d', $h);
        $trendLabels[$k] = $k . ':00';
        $trendRoom[$k] = 0;
        $trendVehicle[$k] = 0;
    }
} elseif ($period === 'year') {
    for ($m = 1; $m <= 12; $m++) {
        $k = sprintf('%02d', $m);
        $trendLabels[$k] = $thaiMonthsShort[$m];
        $trendRoom[$k] = 0;
        $trendVehicle[$k] = 0;
    }
} else {
    $daysInMonth = (int)date('t', strtotime($monthParam . '-01'));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $k = sprintf('%02d', $d);
        $trendLabels[$k] = (string)$d;
        $trendRoom[$k] = 0;
        $trendVehicle[$k] = 0;
    }
}

$resourceUsage = [];
foreach ($bookings as $b) {
    $isRoom = ($b['booking_type'] === 'room');
    if ($isRoom) {
        $roomCount++;
    } else {
        $vehicleCount++;
    }

    $st = (string)$b['status'];
    if ($st === BOOKING_STATUS_APPROVED) {
        $approvedCount++;
    } elseif ($st === BOOKING_STATUS_REJECTED) {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }

    $ts = strtotime((string)$b['start_datetime']);
    if ($ts !== false) {
        if ($period === 'day') {
            $bucket = date('H', $ts);
            if (!isset($trendRoom[$bucket])) {
                $bucket = (int)$bucket < 7 ? '07' : '19';
            }
        } elseif ($period === 'year') {
            $bucket = date('m', $ts);
        } else {
            $bucket = date('d', $ts);
        }
        if (isset($trendRoom[$bucket])) {
            if ($isRoom) {
                $trendRoom[$bucket]++;
            } else {
                $trendVehicle[$bucket]++;
            }
        }
    }

    $resLabel = $isRoom
        ? ($b['room_name'] ?: 'ห้องประชุม')
        : ($b['brand_model'] ? $b['brand_model'] . ' (' . $b['plate_number'] . ')' : 'รอจัดสรรรถ');
    $resourceUsage[$resLabel] = ($resourceUsage[$resLabel] ?? 0) + 1;
}

arsort($resourceUsage);
$topResources = array_slice($resourceUsage, 0, 6, true);

$statusLabels = defined('BOOKING_STATUS_LABELS') ? BOOKING_STATUS_LABELS : [];
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'โรงเรียนสระหลวงพิทยาคม';

$extraHead = <<<'HTML'
<style>
.report-sheet {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 1.15rem;
    padding: 1.75rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
}
.report-kpi-box {
    border-radius: 0.9rem;
    padding: 0.95rem 1.1rem;
    color: #ffffff;
}
.report-chart-box {
    position: relative;
    height: 245px;
    width: 100%;
}
@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .app-main {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .report-sheet {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        border-radius: 0 !important;
    }
    .report-chart-box {
        height: 200px !important;
    }
    .report-kpi-box {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .card, .table {
        break-inside: avoid;
    }
}
</style>
HTML;

require __DIR__ . '/includes/layout_header.php';
?>

<!-- Filter & Print Toolbar (Hidden when printing) -->
<div class="welcome-card p-3 p-md-4 mb-4 no-print">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div>
            <div class="small text-primary fw-semibold mb-1"><i class="bi bi-file-earmark-bar-graph me-1"></i>ระบบรายงานสถิติและการพิมพ์ PDF</div>
            <h1 class="h4 fw-bold text-dark mb-1" data-testid="report-heading">พิมพ์รายงานสรุป (PDF)</h1>
            <p class="small text-muted mb-0">เลือกช่วงเวลาแบบ รายวัน รายเดือน หรือ รายปี พร้อมกราฟสรุปและสั่งพิมพ์เป็น PDF ได้ทันที</p>
        </div>

        <form method="get" action="<?= e(app_url('/print_report.php')) ?>" class="d-flex flex-wrap align-items-center gap-2 bg-light border rounded-4 p-2">
            <select class="form-select form-select-sm w-auto rounded-3 fw-semibold" id="reportPeriodSelect" name="period" style="min-height: 38px;" data-testid="report-period">
                <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>รายวัน</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>รายเดือน</option>
                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>รายปี</option>
            </select>

            <input type="date"
                   class="form-control form-control-sm w-auto rounded-3 <?= $period === 'day' ? '' : 'd-none' ?>"
                   id="inputPeriodDay"
                   name="date"
                   value="<?= e($dateParam) ?>"
                   style="min-height: 38px;">

            <input type="month"
                   class="form-control form-control-sm w-auto rounded-3 <?= $period === 'month' ? '' : 'd-none' ?>"
                   id="inputPeriodMonth"
                   name="month"
                   value="<?= e($monthParam) ?>"
                   style="min-height: 38px;">

            <select class="form-select form-select-sm w-auto rounded-3 <?= $period === 'year' ? '' : 'd-none' ?>"
                    id="inputPeriodYear"
                    name="year"
                    style="min-height: 38px;">
                <?php $curY = (int)date('Y'); for ($y = $curY + 1; $y >= $curY - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= ((int)$yearParam === $y) ? 'selected' : '' ?>>พ.ศ. <?= $y + 543 ?></option>
                <?php endfor; ?>
            </select>

            <button type="submit" class="btn btn-sm btn-primary-gradient rounded-3 px-3 fw-semibold" style="min-height: 38px;" data-testid="btn-apply-report">
                <i class="bi bi-funnel-fill me-1"></i>แสดงรายงาน
            </button>

            <button type="button" class="btn btn-sm btn-success rounded-3 px-3 fw-semibold" style="min-height: 38px;" onclick="window.print()" data-testid="btn-print-report-pdf">
                <i class="bi bi-printer-fill me-1"></i>พิมพ์ / บันทึก PDF
            </button>
        </form>
    </div>
</div>

<!-- Printable Report Sheet -->
<div class="report-sheet" id="reportSheet">
    <!-- Official Report Header -->
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 border-bottom pb-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <img src="<?= e(app_url('/assets/img/school-logo.png')) ?>" alt="ตรา<?= e($appOrgName) ?>" style="width: 56px; height: 56px; object-fit: contain;">
            <div>
                <div class="small text-muted fw-semibold"><?= e($appOrgName) ?> · ระบบงานบริหารทั่วไป</div>
                <h2 class="h5 fw-bold text-dark mb-0"><?= e($periodHeading) ?></h2>
                <div class="small text-primary fw-semibold"><?= e($periodSub) ?></div>
            </div>
        </div>
        <div class="text-sm-end small text-muted">
            <div>ผู้จัดทำรายงาน: <strong class="text-dark"><?= e($user['full_name']) ?></strong></div>
            <div>วันที่พิมพ์: <?= e(thai_date(date('Y-m-d H:i:s'), true, true)) ?></div>
        </div>
    </div>

    <!-- 4 Full-Color Gradient KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="report-kpi-box" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                <div class="small opacity-90">การจองทั้งหมด</div>
                <div class="fs-3 fw-bold lh-sm"><?= $totalBookings ?></div>
                <div class="small opacity-75">ห้อง <?= $roomCount ?> · รถ <?= $vehicleCount ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="report-kpi-box" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                <div class="small opacity-90">อนุมัติแล้ว</div>
                <div class="fs-3 fw-bold lh-sm"><?= $approvedCount ?></div>
                <div class="small opacity-75">รออนุมัติ <?= $pendingCount ?> · ไม่อนุมัติ <?= $rejectedCount ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="report-kpi-box" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                <div class="small opacity-90">งานแจ้งซ่อม</div>
                <div class="fs-3 fw-bold lh-sm"><?= $maintTotal ?></div>
                <div class="small opacity-75">ซ่อมเสร็จแล้ว <?= $maintDone ?> รายการ</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="report-kpi-box" style="background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                <div class="small opacity-90">ยืม-คืนอุปกรณ์/กุญแจ</div>
                <div class="fs-3 fw-bold lh-sm"><?= $borrowTotal ?></div>
                <div class="small opacity-75">คืนเรียบร้อย <?= $borrowReturned ?> รายการ</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-8">
            <div class="card border rounded-4 h-100">
                <div class="card-header bg-light border-bottom py-2 px-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-bar-chart-fill text-primary me-1"></i>แนวโน้มการจองห้องประชุมและยานพาหนะ</h3>
                </div>
                <div class="card-body p-3">
                    <div class="report-chart-box">
                        <canvas id="reportTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card border rounded-4 h-100">
                <div class="card-header bg-light border-bottom py-2 px-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-pie-chart-fill text-success me-1"></i>สัดส่วนสถานะคำขอ</h3>
                </div>
                <div class="card-body p-3">
                    <div class="report-chart-box">
                        <canvas id="reportStatusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border rounded-4 mb-4">
        <div class="card-header bg-light border-bottom py-2 px-3">
            <h3 class="h6 fw-bold mb-0"><i class="bi bi-trophy-fill text-warning me-1"></i>ทรัพยากรที่มีการใช้งานสูงสุดในช่วงเวลานี้</h3>
        </div>
        <div class="card-body p-3">
            <div class="report-chart-box" style="height: 210px;">
                <canvas id="reportResourceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Bookings Detail Table -->
    <div class="mb-4">
        <h3 class="h6 fw-bold text-dark mb-2"><i class="bi bi-table text-primary me-1"></i>รายละเอียดรายการจองในช่วงเวลา (<?= $totalBookings ?> รายการ)</h3>
        <?php if (empty($bookings)): ?>
            <div class="border rounded-3 p-4 text-center text-muted small">ไม่มีรายการจองในช่วงเวลาที่เลือก</div>
        <?php else: ?>
            <div class="table-responsive border rounded-3">
                <table class="table table-bordered table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="text-nowrap">รหัสอ้างอิง</th>
                            <th class="text-nowrap">ประเภท</th>
                            <th>เรื่อง / กิจกรรม</th>
                            <th>สถานที่ / ยานพาหนะ</th>
                            <th>ผู้ขอ</th>
                            <th class="text-nowrap">วัน-เวลา</th>
                            <th class="text-nowrap">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b):
                            $isRoom = ($b['booking_type'] === 'room');
                            $st = (string)$b['status'];
                            $stLabel = $statusLabels[$st]['label'] ?? $st;
                            $place = $isRoom
                                ? trim(($b['room_code'] ? $b['room_code'] . ' ' : '') . ($b['room_name'] ?: '-'))
                                : trim(($b['brand_model'] ?: 'รอจัดสรรรถ') . (!empty($b['destination']) ? ' → ' . $b['destination'] : ''));
                        ?>
                            <tr>
                                <td class="font-monospace text-nowrap"><?= e($b['reference_code']) ?></td>
                                <td class="text-nowrap"><?= $isRoom ? 'ห้องประชุม/เรียน' : 'ยานพาหนะ' ?></td>
                                <td><?= e($b['title']) ?></td>
                                <td><?= e($place) ?></td>
                                <td><?= e($b['requester_name']) ?></td>
                                <td class="text-nowrap"><?= e(thai_date($b['start_datetime'], true, true)) ?></td>
                                <td class="text-nowrap fw-semibold"><?= e($stLabel) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sign-off Footer for Printed PDF -->
    <div class="row g-3 pt-3 border-top text-center small mt-4">
        <div class="col-6">
            <div class="mb-4">ลงชื่อ..................................................ผู้จัดทำรายงาน</div>
            <div>(<?= e($user['full_name']) ?>)</div>
            <div class="text-muted">เจ้าหน้าที่ / ผู้รับผิดชอบระบบ</div>
        </div>
        <div class="col-6">
            <div class="mb-4">ลงชื่อ..................................................ผู้รับรองรายงาน</div>
            <div>(..................................................)</div>
            <div class="text-muted">ผู้อำนวยการ<?= e($appOrgName) ?></div>
        </div>
    </div>
</div>

<?php
$chartJsUrl = e(app_url('/assets/vendor/chartjs/chart.umd.min.js'));
$payloadJson = json_encode([
    'trendLabels'   => array_values($trendLabels),
    'trendRoom'     => array_values($trendRoom),
    'trendVehicle'  => array_values($trendVehicle),
    'statusCounts'  => [$approvedCount, $pendingCount, $rejectedCount],
    'topLabels'     => array_keys($topResources),
    'topCounts'     => array_values($topResources),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$extraScripts = <<<HTML
<script src="{$chartJsUrl}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const periodSel = document.getElementById('reportPeriodSelect');
    const inDay = document.getElementById('inputPeriodDay');
    const inMonth = document.getElementById('inputPeriodMonth');
    const inYear = document.getElementById('inputPeriodYear');

    function syncPeriodInputs() {
        if (!periodSel) return;
        const p = periodSel.value;
        if (inDay) inDay.classList.toggle('d-none', p !== 'day');
        if (inMonth) inMonth.classList.toggle('d-none', p !== 'month');
        if (inYear) inYear.classList.toggle('d-none', p !== 'year');
    }
    if (periodSel) {
        periodSel.addEventListener('change', syncPeriodInputs);
    }

    if (typeof Chart === 'undefined') return;
    const data = {$payloadJson};

    const trendEl = document.getElementById('reportTrendChart');
    if (trendEl) {
        new Chart(trendEl, {
            type: 'bar',
            data: {
                labels: data.trendLabels,
                datasets: [
                    {
                        label: 'ห้องประชุม / ห้องเรียน',
                        data: data.trendRoom,
                        backgroundColor: '#2563eb',
                        borderRadius: 6
                    },
                    {
                        label: 'ยานพาหนะ',
                        data: data.trendVehicle,
                        backgroundColor: '#059669',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    const statusEl = document.getElementById('reportStatusChart');
    if (statusEl) {
        const hasAny = data.statusCounts.some(v => v > 0);
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: hasAny ? ['อนุมัติแล้ว', 'รอดำเนินการ', 'ไม่อนุมัติ'] : ['ยังไม่มีข้อมูล'],
                datasets: [{
                    data: hasAny ? data.statusCounts : [1],
                    backgroundColor: hasAny ? ['#10b981', '#f59e0b', '#ef4444'] : ['#e2e8f0'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                cutout: '62%',
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const resEl = document.getElementById('reportResourceChart');
    if (resEl) {
        const hasTop = data.topLabels.length > 0;
        new Chart(resEl, {
            type: 'bar',
            data: {
                labels: hasTop ? data.topLabels : ['ยังไม่มีข้อมูลการใช้งาน'],
                datasets: [{
                    label: 'จำนวนครั้งที่จอง',
                    data: hasTop ? data.topCounts : [0],
                    backgroundColor: ['#2563eb', '#6366f1', '#10b981', '#f59e0b', '#0ea5e9', '#8b5cf6'],
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
});
</script>
HTML;
require __DIR__ . '/includes/layout_footer.php';
