<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();

$pageTitle = 'ปฏิทินหลัก';
$activeNav = 'calendar';
$isInternal = is_internal($user);
$canManageEvents = has_role([ROLE_ADMIN, ROLE_STAFF], $user);
$csrfToken = csrf_token();

$initialViewMode = trim((string)($_GET['view'] ?? ''));
if (!in_array($initialViewMode, ['calendar', 'table', 'list'], true)) {
    $initialViewMode = '';
}

$extraHead = '<meta name="csrf-token" content="' . e($csrfToken) . '">' . PHP_EOL
    . '<meta name="_csrf_token" content="' . e($csrfToken) . '">' . PHP_EOL
    . '<style>
        .fc-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }
        .fc .fc-button-primary {
            background-color: #2563eb;
            border-color: #2563eb;
        }
        .fc .fc-button-primary:hover,
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }
        .fc .fc-daygrid-event,
        .fc .fc-timegrid-event {
            border-radius: 0.45rem;
            padding: 3px 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #ffffff !important;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.22);
            border-width: 1px !important;
            cursor: pointer;
        }
        .fc .fc-daygrid-event .fc-event-main,
        .fc .fc-daygrid-event .fc-event-title,
        .fc .fc-daygrid-event .fc-event-time {
            color: #ffffff !important;
        }
        .fc-theme-standard .fc-scrollgrid {
            border: 1px solid #94a3b8 !important;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .fc-theme-standard td,
        .fc-theme-standard th {
            border: 1px solid #cbd5e1 !important;
        }
        .fc .fc-col-header-cell {
            font-weight: 700;
            padding: 0.5rem 0;
        }
        .fc .fc-daygrid-day-frame {
            min-height: 108px;
            background-image:
                linear-gradient(to right, rgba(148, 163, 184, 0.18) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148, 163, 184, 0.18) 1px, transparent 1px);
            background-size: 22px 22px;
        }
        /* Soft-rich day-of-week colors */
        .fc .fc-col-header-cell.fc-day-sun { background-color: #fecdd3 !important; }
        .fc .fc-col-header-cell.fc-day-sun a { color: #9f1239 !important; }
        .fc .fc-daygrid-day.fc-day-sun { background-color: #ffe4e6 !important; }

        .fc .fc-col-header-cell.fc-day-mon { background-color: #fde68a !important; }
        .fc .fc-col-header-cell.fc-day-mon a { color: #92400e !important; }
        .fc .fc-daygrid-day.fc-day-mon { background-color: #fef3c7 !important; }

        .fc .fc-col-header-cell.fc-day-tue { background-color: #fbcfe8 !important; }
        .fc .fc-col-header-cell.fc-day-tue a { color: #9d174d !important; }
        .fc .fc-daygrid-day.fc-day-tue { background-color: #fce7f3 !important; }

        .fc .fc-col-header-cell.fc-day-wed { background-color: #bbf7d0 !important; }
        .fc .fc-col-header-cell.fc-day-wed a { color: #166534 !important; }
        .fc .fc-daygrid-day.fc-day-wed { background-color: #dcfce7 !important; }

        .fc .fc-col-header-cell.fc-day-thu { background-color: #fed7aa !important; }
        .fc .fc-col-header-cell.fc-day-thu a { color: #9a3412 !important; }
        .fc .fc-daygrid-day.fc-day-thu { background-color: #ffedd5 !important; }

        .fc .fc-col-header-cell.fc-day-fri { background-color: #bfdbfe !important; }
        .fc .fc-col-header-cell.fc-day-fri a { color: #1e40af !important; }
        .fc .fc-daygrid-day.fc-day-fri { background-color: #dbeafe !important; }

        .fc .fc-col-header-cell.fc-day-sat { background-color: #e9d5ff !important; }
        .fc .fc-col-header-cell.fc-day-sat a { color: #6b21a8 !important; }
        .fc .fc-daygrid-day.fc-day-sat { background-color: #f3e8ff !important; }

        .fc .fc-daygrid-event.fc-event-room {
            background-color: #1d4ed8 !important;
            border-color: #1e40af !important;
        }
        .fc .fc-daygrid-event.fc-event-vehicle {
            background-color: #059669 !important;
            border-color: #047857 !important;
        }
        .fc .fc-daygrid-event.fc-event-org {
            background-color: #6d28d9 !important;
            border-color: #5b21b6 !important;
        }

        .fc .fc-daygrid-day.fc-day-today {
            box-shadow: inset 0 0 0 2px #2563eb;
        }
        @media (max-width: 575.98px) {
            .fc .fc-toolbar {
                flex-direction: column;
                gap: 0.5rem;
            }
            .fc .fc-toolbar-title {
                font-size: 1.05rem;
            }
        }
    </style>';

require __DIR__ . '/includes/layout_header.php';
?>
<!-- Header Card -->
<div class="welcome-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div class="min-w-0">
            <div class="small text-muted mb-1">
                <i class="bi bi-calendar3 me-1"></i>ตารางการใช้ทรัพยากรและกิจกรรมหน่วยงาน
            </div>
            <h1 class="h4 fw-bold text-dark mb-2" data-testid="calendar-heading">ปฏิทินหลักและตารางการจอง</h1>
            <div class="d-flex flex-wrap align-items-center gap-3 small text-secondary">
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-house-fill text-primary"></i> อาคาร / ห้องประชุม
                </span>
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-car-front-fill text-success"></i> ยานพาหนะ
                </span>
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-calendar-event-fill" style="color: #7C3AED;"></i> กิจกรรมหน่วยงาน
                </span>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            <a href="<?= e(app_url('/book_room.php')) ?>"
               class="btn btn-primary-gradient fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                <i class="bi bi-door-open-fill"></i>
                <span>จองห้องประชุม</span>
            </a>
            <?php if ($isInternal): ?>
                <a href="<?= e(app_url('/book_vehicle.php')) ?>"
                   class="btn btn-soft-success fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3">
                    <i class="bi bi-truck"></i>
                    <span>จองยานพาหนะ</span>
                </a>
            <?php endif; ?>
            <?php if (has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $user)): ?>
                <a href="<?= e(app_url('/export_csv.php?type=bookings&month=all')) ?>"
                   class="btn btn-outline-success fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="btn-export-all-bookings">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    <span>Export CSV ทั้งหมด</span>
                </a>
            <?php endif; ?>
            <?php if ($canManageEvents): ?>
                <button type="button"
                        class="btn btn-soft-primary fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                        data-testid="add-event-btn"
                        id="btnOpenAddEvent">
                    <i class="bi bi-calendar-plus"></i>
                    <span>เพิ่มกิจกรรม</span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Calendar Filter, View Mode Switcher & Views Container -->
<div class="spotlight-card">
    <div class="spotlight-header flex-wrap gap-2">
        <div class="d-flex flex-wrap align-items-center gap-1" id="calendarFilterGroup">
            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 cal-filter-btn" data-type="all">ทั้งหมด</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="room">ห้องประชุม</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="vehicle">ยานพาหนะ</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="event">กิจกรรมหน่วยงาน</button>
        </div>

        <div class="cal-mode-switcher" role="group" aria-label="รูปแบบการแสดงผลปฏิทิน">
            <button type="button" class="btn btn-sm cal-mode-btn active" data-view-mode="calendar" data-testid="cal-mode-calendar">
                <i class="bi bi-calendar3"></i>
                <span>ปฏิทิน</span>
            </button>
            <button type="button" class="btn btn-sm cal-mode-btn" data-view-mode="table" data-testid="cal-mode-table">
                <i class="bi bi-table"></i>
                <span>ตาราง</span>
            </button>
            <button type="button" class="btn btn-sm cal-mode-btn" data-view-mode="list" data-testid="cal-mode-list">
                <i class="bi bi-list-ul"></i>
                <span>ลิสต์เรียงตามวัน</span>
            </button>
        </div>
    </div>

    <div class="p-2 p-sm-3 p-md-4">
        <!-- Toolbar for Table & Daily Grouped List Modes -->
        <div id="calendarAltToolbar" class="cal-alt-toolbar mb-3 d-none">
            <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="เลื่อนช่วงเวลา">
                        <button type="button" class="btn btn-outline-secondary" id="calAltPrev" title="ช่วงก่อนหน้า">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary fw-semibold px-3" id="calAltToday">วันนี้</button>
                        <button type="button" class="btn btn-outline-secondary" id="calAltNext" title="ช่วงถัดไป">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                    <div class="fw-bold text-dark fs-6 ms-1" id="calAltTitle">-</div>
                    <span class="badge rounded-pill text-bg-light border text-secondary" id="calAltCount">0 รายการ</span>
                </div>

                <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2">
                    <div class="d-flex flex-wrap gap-1" id="calAltScopeGroup">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-2 cal-scope-btn" data-alt-scope="month">รายเดือน</button>
                        <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-2 cal-scope-btn" data-alt-scope="week">รายสัปดาห์</button>
                        <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-2 cal-scope-btn" data-alt-scope="day">รายวัน</button>
                        <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-2 cal-scope-btn" data-alt-scope="next30">30 วันข้างหน้า</button>
                    </div>
                    <div class="input-group input-group-sm" style="min-width: 210px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text"
                               class="form-control"
                               id="calAltSearch"
                               placeholder="ค้นหาเรื่อง สถานที่ รหัสจอง...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Mode 1: FullCalendar -->
        <div id="masterCalendar" data-testid="master-calendar"></div>

        <!-- Mode 2: Table View (Sorted Chronologically) -->
        <div id="calendarTableView" class="d-none" data-testid="calendar-table-view"></div>

        <!-- Mode 3: Daily Grouped List View (Sorted by Day) -->
        <div id="calendarListView" class="d-none" data-testid="calendar-list-view"></div>
    </div>
</div>

<!-- Event Detail Modal -->
<div class="modal fade" id="calendarEventModal" tabindex="-1" aria-labelledby="calEventModalTitle" aria-hidden="true" data-testid="calendar-event-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="calEventModalTitle">รายละเอียดรายการ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-4" id="calEventModalBody"></div>
            <div class="modal-footer border-top bg-light px-4 py-3" id="calEventModalFooter">
                <?php if (has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $user)): ?>
                <a href="<?= e(app_url('/export_csv.php?type=bookings&month=all')) ?>"
                   class="btn btn-outline-success fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="btn-export-all-bookings">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    <span>Export CSV ทั้งหมด</span>
                </a>
            <?php endif; ?>
            <?php if ($canManageEvents): ?>
                    <button type="button" class="btn btn-outline-danger rounded-3 px-3 me-auto d-none" id="btnDeleteCalEvent">
                        <i class="bi bi-trash3 me-1"></i>ลบกิจกรรม
                    </button>
                    <button type="button" class="btn btn-outline-primary rounded-3 px-3 d-none" id="btnEditCalEvent">
                        <i class="bi bi-pencil-square me-1"></i>แก้ไข
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary rounded-3 px-3" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Slot Action Modal (Click date/time to Book) -->
<div class="modal fade" id="slotActionModal" tabindex="-1" aria-labelledby="slotActionTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold fs-6" id="slotActionTitle">เลือกดำเนินการในช่วงเวลา</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-4">
                <div class="small text-muted mb-3" id="slotActionSummary"></div>
                <div class="d-grid gap-2">
                    <a href="#" id="slotBookRoomLink" class="btn btn-primary-gradient touch-target d-flex align-items-center justify-content-center gap-2 rounded-3 fw-semibold">
                        <i class="bi bi-door-open-fill"></i> จองห้องประชุมช่วงนี้
                    </a>
                    <?php if ($isInternal): ?>
                        <a href="#" id="slotBookVehicleLink" class="btn btn-outline-success touch-target d-flex align-items-center justify-content-center gap-2 rounded-3 fw-semibold">
                            <i class="bi bi-truck"></i> จองยานพาหนะช่วงนี้
                        </a>
                    <?php endif; ?>
                    <?php if (has_role([ROLE_HEAD, ROLE_VICE_DIRECTOR, ROLE_DIRECTOR, ROLE_STAFF, ROLE_ADMIN], $user)): ?>
                <a href="<?= e(app_url('/export_csv.php?type=bookings&month=all')) ?>"
                   class="btn btn-outline-success fw-semibold touch-target d-inline-flex align-items-center gap-2 rounded-3 px-3"
                   data-testid="btn-export-all-bookings">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    <span>Export CSV ทั้งหมด</span>
                </a>
            <?php endif; ?>
            <?php if ($canManageEvents): ?>
                        <button type="button" id="slotAddEventBtn" class="btn btn-outline-primary touch-target d-flex align-items-center justify-content-center gap-2 rounded-3 fw-semibold">
                            <i class="bi bi-calendar-plus"></i> เพิ่มกิจกรรมหน่วยงาน
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageEvents): ?>
<!-- Create / Edit Calendar Event Modal (Admin / Staff) -->
<div class="modal fade" id="eventFormModal" tabindex="-1" aria-labelledby="eventFormModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <h5 class="modal-title fw-bold" id="eventFormModalTitle">เพิ่มกิจกรรมหน่วยงาน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form id="calendarEventForm" action="<?= e(app_url('/api/calendar_event_save.php')) ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="ev_action" value="create">
                <input type="hidden" name="id" id="ev_id" value="">

                <div class="modal-body p-4">
                    <div class="alert alert-danger d-none" id="evFormError"></div>

                    <div class="mb-3">
                        <label for="ev_title" class="form-label fw-semibold small text-secondary">ชื่อกิจกรรม <span class="text-danger">*</span></label>
                        <input type="text" class="form-control touch-target" id="ev_title" name="title" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="ev_type" class="form-label fw-semibold small text-secondary">ประเภท</label>
                            <select class="form-select touch-target" id="ev_type" name="event_type">
                                <option value="general">กิจกรรมทั่วไป</option>
                                <option value="meeting">การประชุมหน่วยงาน</option>
                                <option value="holiday">วันหยุด / กิจกรรม 5ส.</option>
                                <option value="maintenance">ปิดปรับปรุง / ซ่อมบำรุง</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="ev_location" class="form-label fw-semibold small text-secondary">สถานที่</label>
                            <input type="text" class="form-control touch-target" id="ev_location" name="location" placeholder="เช่น หอประชุมใหญ่">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="ev_start" class="form-label fw-semibold small text-secondary">วันและเวลาเริ่มต้น <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control touch-target" id="ev_start" name="start_datetime" required>
                        </div>
                        <div class="col-md-6">
                            <label for="ev_end" class="form-label fw-semibold small text-secondary">วันและเวลาสิ้นสุด <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control touch-target" id="ev_end" name="end_datetime" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ev_description" class="form-label fw-semibold small text-secondary">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" id="ev_description" name="description" rows="2"></textarea>
                    </div>

                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="ev_all_day" name="is_all_day" value="1">
                            <label class="form-check-label small" for="ev_all_day">กิจกรรมตลอดทั้งวัน</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="ev_public" name="is_public" value="1" checked>
                            <label class="form-check-label small" for="ev_public">แสดงต่อบุคคลภายนอก</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary touch-target rounded-3 px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary-gradient touch-target rounded-3 px-4 fw-semibold">บันทึกกิจกรรม</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$eventsUrl = app_url('/api/calendar_events.php');
$saveUrl = app_url('/api/calendar_event_save.php');
$bookRoomBase = app_url('/book_room.php');
$bookVehicleBase = app_url('/book_vehicle.php');

$extraScripts = '<script src="' . e(app_url('/assets/vendor/fullcalendar/index.global.min.js')) . '"></script>' . PHP_EOL
    . '<script src="' . e(app_url('/assets/vendor/fullcalendar/locales-th.global.min.js')) . '"></script>' . PHP_EOL
    . '<script>
document.addEventListener("DOMContentLoaded", function () {
    const calendarEl = document.getElementById("masterCalendar");
    if (!calendarEl || typeof FullCalendar === "undefined") return;

    const eventsApi = ' . json_encode($eventsUrl) . ';
    const saveApi = ' . json_encode($saveUrl) . ';
    const bookRoomBase = ' . json_encode($bookRoomBase) . ';
    const bookVehicleBase = ' . json_encode($bookVehicleBase) . ';
    const csrfToken = ' . json_encode($csrfToken) . ';
    const canManage = ' . ($canManageEvents ? 'true' : 'false') . ';
    const urlInitialMode = ' . json_encode($initialViewMode) . ';

    const thaiMonthsFull = ["มกราคม","กุมภาพันธ์","มีนาคม","เมษายน","พฤษภาคม","มิถุนายน","กรกฎาคม","สิงหาคม","กันยายน","ตุลาคม","พฤศจิกายน","ธันวาคม"];
    const thaiMonthsShort = ["ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
    const thaiWeekdays = ["อาทิตย์","จันทร์","อังคาร","พุธ","พฤหัสบดี","ศุกร์","เสาร์"];

    let activeFilter = "all";
    let viewMode = urlInitialMode || localStorage.getItem("slp_cal_view_mode") || "calendar";
    if (!["calendar", "table", "list"].includes(viewMode)) viewMode = "calendar";
    let altScope = "month";
    let anchorDate = new Date();
    anchorDate.setHours(0, 0, 0, 0);
    let cachedAltEvents = [];

    let currentSlotStart = "";
    let currentSlotEnd = "";
    let activeCalendarEvent = null;

    function pad2(n) {
        return n < 10 ? "0" + n : String(n);
    }

    function toYmd(dt) {
        return dt.getFullYear() + "-" + pad2(dt.getMonth() + 1) + "-" + pad2(dt.getDate());
    }

    function parseYmdLocal(ymd) {
        const parts = String(ymd || "").slice(0, 10).split("-");
        if (parts.length !== 3) return new Date();
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]), 0, 0, 0);
    }

    function formatThaiShortDate(ymd) {
        const dt = parseYmdLocal(ymd);
        return dt.getDate() + " " + (thaiMonthsShort[dt.getMonth()] || "") + " " + (dt.getFullYear() + 543);
    }

    function formatThaiFullDay(ymd) {
        const dt = parseYmdLocal(ymd);
        const w = thaiWeekdays[dt.getDay()] || "";
        const m = thaiMonthsFull[dt.getMonth()] || "";
        return "วัน" + w + "ที่ " + dt.getDate() + " " + m + " " + (dt.getFullYear() + 543);
    }

    function formatEventTimePill(ev) {
        if (ev.allDay) return "ตลอดวัน";
        const sStr = typeof ev.start === "string" ? ev.start : "";
        const eStr = typeof ev.end === "string" ? ev.end : "";
        const sTime = sStr.length >= 16 ? sStr.slice(11, 16) : "";
        const eTime = eStr.length >= 16 ? eStr.slice(11, 16) : "";
        if (sTime && eTime) return sTime + " – " + eTime + " น.";
        if (sTime) return sTime + " น.";
        return "-";
    }

    function fmtThaiRange(startVal, endVal, allDay) {
        if (!startVal) return "-";
        const isDateObj = startVal instanceof Date;
        if (isDateObj) {
            const optDate = { year: "numeric", month: "short", day: "numeric", timeZone: "Asia/Bangkok" };
            const optTime = { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Bangkok" };
            const sDate = startVal.toLocaleDateString("th-TH", optDate);
            if (allDay) return sDate + " (ตลอดวัน)";
            const sTime = startVal.toLocaleTimeString("th-TH", optTime);
            if (!(endVal instanceof Date)) return sDate + " " + sTime + " น.";
            const eDate = endVal.toLocaleDateString("th-TH", optDate);
            const eTime = endVal.toLocaleTimeString("th-TH", optTime);
            if (sDate === eDate) return sDate + " เวลา " + sTime + " - " + eTime + " น.";
            return sDate + " " + sTime + " น. ถึง " + eDate + " " + eTime + " น.";
        }
        const sStr = String(startVal);
        const eStr = endVal ? String(endVal) : "";
        const sDateStr = formatThaiShortDate(sStr);
        if (allDay) return sDateStr + " (ตลอดวัน)";
        const sTimeStr = sStr.length >= 16 ? sStr.slice(11, 16) : "";
        if (!eStr) return sDateStr + (sTimeStr ? " " + sTimeStr + " น." : "");
        const eDateStr = formatThaiShortDate(eStr);
        const eTimeStr = eStr.length >= 16 ? eStr.slice(11, 16) : "";
        if (sStr.slice(0, 10) === eStr.slice(0, 10)) {
            return sDateStr + " เวลา " + sTimeStr + " - " + eTimeStr + " น.";
        }
        return sDateStr + " " + sTimeStr + " น. ถึง " + eDateStr + " " + eTimeStr + " น.";
    }

    function toInputLocal(dt) {
        const tzOffsetMs = dt.getTimezoneOffset() * 60000;
        return new Date(dt.getTime() - tzOffsetMs).toISOString().slice(0, 16);
    }

    function escapeHtml(str) {
        const div = document.createElement("div");
        div.textContent = str == null ? "" : String(str);
        return div.innerHTML;
    }

    const detailModalEl = document.getElementById("calendarEventModal");
    const detailModal = detailModalEl ? bootstrap.Modal.getOrCreateInstance(detailModalEl) : null;
    const slotModalEl = document.getElementById("slotActionModal");
    const slotModal = slotModalEl ? bootstrap.Modal.getOrCreateInstance(slotModalEl) : null;
    const eventFormModalEl = document.getElementById("eventFormModal");
    const eventFormModal = eventFormModalEl ? bootstrap.Modal.getOrCreateInstance(eventFormModalEl) : null;

    function openEventDetailModal(ev) {
        const p = ev.extendedProps || {};
        activeCalendarEvent = ev;

        const titleEl = document.getElementById("calEventModalTitle");
        const bodyEl = document.getElementById("calEventModalBody");
        const editBtn = document.getElementById("btnEditCalEvent");
        const delBtn = document.getElementById("btnDeleteCalEvent");

        if (titleEl) titleEl.textContent = ev.title || "รายละเอียดรายการ";

        let html = "";
        if (p.source_type === "booking") {
            const typeLabel = p.booking_type === "room" ? "จองห้องประชุม" : "จองยานพาหนะ";
            const statusBadgeMap = {
                pending_head: "warning text-dark",
                pending_vice: "info text-dark",
                pending_director: "primary",
                approved: "success",
                rejected: "danger"
            };
            const stBadge = statusBadgeMap[p.status] || "secondary";
            html += "<div class=\"d-flex flex-wrap gap-2 mb-3\">";
            html += "<span class=\"badge text-bg-light border text-dark font-monospace\">" + escapeHtml(p.reference_code) + "</span>";
            html += "<span class=\"badge " + (p.booking_type === "room" ? "text-bg-primary" : "text-bg-success") + "\">" + escapeHtml(typeLabel) + "</span>";
            html += "<span class=\"badge text-bg-" + stBadge + "\">" + escapeHtml(p.status_label || p.status) + "</span>";
            html += "</div>";
            html += "<div class=\"mb-2\"><strong>สถานที่ / ยานพาหนะ:</strong> " + escapeHtml(p.resource_name) + "</div>";
            html += "<div class=\"mb-2\"><strong>วันและเวลา:</strong> " + escapeHtml(fmtThaiRange(ev.start, ev.end, ev.allDay)) + "</div>";
            if (!p.is_masked) {
                html += "<div class=\"mb-2\"><strong>ผู้ขอใช้:</strong> " + escapeHtml(p.requester_name) + (p.requester_contact ? " (" + escapeHtml(p.requester_contact) + ")" : "") + "</div>";
                if (p.destination) html += "<div class=\"mb-2\"><strong>ปลายทาง:</strong> " + escapeHtml(p.destination) + "</div>";
                if (p.driver_name) html += "<div class=\"mb-2\"><strong>พนักงานขับรถ:</strong> " + escapeHtml(p.driver_name) + "</div>";
                if (p.purpose) html += "<div class=\"mb-0\"><strong>วัตถุประสงค์:</strong> " + escapeHtml(p.purpose) + "</div>";
            } else {
                html += "<div class=\"alert alert-light border small text-muted mb-0 mt-2\"><i class=\"bi bi-shield-lock me-1\"></i>ข้อมูลผู้ขอและรายละเอียดถูกซ่อนเพื่อความเป็นส่วนตัว</div>";
            }
        } else {
            html += "<div class=\"mb-2\"><span class=\"badge\" style=\"background:#7C3AED;\">กิจกรรมหน่วยงาน</span></div>";
            html += "<div class=\"mb-2\"><strong>วันและเวลา:</strong> " + escapeHtml(fmtThaiRange(ev.start, ev.end, ev.allDay)) + "</div>";
            html += "<div class=\"mb-2\"><strong>สถานที่:</strong> " + escapeHtml(p.location || "-") + "</div>";
            html += "<div class=\"mb-0\"><strong>รายละเอียด:</strong> " + escapeHtml(p.description || "-") + "</div>";
        }

        if (bodyEl) bodyEl.innerHTML = html;

        if (editBtn && delBtn) {
            if (canManage && p.source_type === "calendar_event") {
                editBtn.classList.remove("d-none");
                delBtn.classList.remove("d-none");
            } else {
                editBtn.classList.add("d-none");
                delBtn.classList.add("d-none");
            }
        }
        if (detailModal) detailModal.show();
    }

    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: "th",
        initialView: "dayGridMonth",
        eventDisplay: "block",
        height: "auto",
        navLinks: true,
        selectable: true,
        dayMaxEvents: 3,
        buttonText: {
            today: "วันนี้",
            month: "เดือน",
            week: "สัปดาห์",
            day: "วัน",
            listMonth: "กำหนดการ"
        },
        eventTimeFormat: {
            hour: "2-digit",
            minute: "2-digit",
            hour12: false
        },
        headerToolbar: {
            left: "dayGridMonth,timeGridWeek,timeGridDay,listMonth",
            center: "title",
            right: "prev,next today"
        },
        datesSet: function (info) {
            if (info.view && info.view.currentStart) {
                anchorDate = new Date(info.view.currentStart.getTime());
                anchorDate.setHours(0, 0, 0, 0);
            }
        },
        events: function (fetchInfo, successCallback, failureCallback) {
            const url = eventsApi + "?start=" + encodeURIComponent(fetchInfo.startStr) + "&end=" + encodeURIComponent(fetchInfo.endStr) + "&type=" + encodeURIComponent(activeFilter);
            fetch(url, { headers: { "Accept": "application/json" } })
                .then(r => r.json())
                .then(data => successCallback(Array.isArray(data) ? data : (data.events || [])))
                .catch(err => failureCallback(err));
        },
        dateClick: function (info) {
            const baseDate = info.dateStr.slice(0, 10);
            if (info.allDay) {
                currentSlotStart = baseDate + "T09:00";
                currentSlotEnd = baseDate + "T12:00";
            } else {
                currentSlotStart = toInputLocal(info.date);
                const endDt = new Date(info.date.getTime() + 2 * 3600 * 1000);
                currentSlotEnd = toInputLocal(endDt);
            }
            openSlotActionModal();
        },
        select: function (info) {
            if (info.allDay) {
                const baseDate = info.startStr.slice(0, 10);
                currentSlotStart = baseDate + "T09:00";
                currentSlotEnd = baseDate + "T12:00";
            } else {
                currentSlotStart = toInputLocal(info.start);
                currentSlotEnd = toInputLocal(info.end);
            }
            openSlotActionModal();
        },
        eventClassNames: function (arg) {
            const p = arg.event.extendedProps || {};
            if (p.source_type === "booking") {
                return [p.booking_type === "room" ? "fc-event-room" : "fc-event-vehicle"];
            }
            return ["fc-event-org"];
        },
        eventContent: function (arg) {
            const p = arg.event.extendedProps || {};
            let iconClass = "bi-calendar-event-fill";
            if (p.source_type === "booking") {
                iconClass = (p.booking_type === "room") ? "bi-house-fill" : "bi-car-front-fill";
            }
            const wrap = document.createElement("div");
            wrap.className = "d-flex align-items-center gap-1 text-truncate";
            const icon = document.createElement("i");
            icon.className = "bi " + iconClass + " flex-shrink-0";
            wrap.appendChild(icon);
            if (arg.timeText) {
                const t = document.createElement("span");
                t.className = "fw-semibold flex-shrink-0";
                t.textContent = arg.timeText;
                wrap.appendChild(t);
            }
            const title = document.createElement("span");
            title.className = "text-truncate";
            title.textContent = arg.event.title || "";
            wrap.appendChild(title);
            return { domNodes: [wrap] };
        },
        eventClick: function (info) {
            openEventDetailModal(info.event);
        }
    });

    calendar.render();

    function computeAltRange() {
        const y = anchorDate.getFullYear();
        const m = anchorDate.getMonth();
        let startDt, endDt, title;

        if (altScope === "week") {
            startDt = new Date(anchorDate.getTime());
            startDt.setDate(startDt.getDate() - startDt.getDay());
            endDt = new Date(startDt.getTime());
            endDt.setDate(endDt.getDate() + 7);
            const lastInc = new Date(endDt.getTime());
            lastInc.setDate(lastInc.getDate() - 1);
            title = formatThaiShortDate(toYmd(startDt)) + " – " + formatThaiShortDate(toYmd(lastInc));
        } else if (altScope === "day") {
            startDt = new Date(anchorDate.getTime());
            endDt = new Date(startDt.getTime());
            endDt.setDate(endDt.getDate() + 1);
            title = formatThaiFullDay(toYmd(startDt));
        } else if (altScope === "next30") {
            startDt = new Date(anchorDate.getTime());
            endDt = new Date(startDt.getTime());
            endDt.setDate(endDt.getDate() + 30);
            const lastInc = new Date(endDt.getTime());
            lastInc.setDate(lastInc.getDate() - 1);
            title = "30 วัน (" + formatThaiShortDate(toYmd(startDt)) + " – " + formatThaiShortDate(toYmd(lastInc)) + ")";
        } else {
            startDt = new Date(y, m, 1, 0, 0, 0);
            endDt = new Date(y, m + 1, 1, 0, 0, 0);
            title = (thaiMonthsFull[m] || "") + " " + (y + 543);
        }

        return {
            startStr: toYmd(startDt) + " 00:00:00",
            endStr: toYmd(endDt) + " 00:00:00",
            title: title
        };
    }

    function getFilteredSortedAltEvents() {
        const q = (document.getElementById("calAltSearch")?.value || "").trim().toLowerCase();
        const list = cachedAltEvents.filter(function (ev) {
            if (!q) return true;
            const p = ev.extendedProps || {};
            const hay = [
                ev.title,
                p.reference_code,
                p.resource_name,
                p.location,
                p.description,
                p.destination,
                p.requester_name,
                p.status_label
            ].join(" ").toLowerCase();
            return hay.includes(q);
        });

        list.sort(function (a, b) {
            const sa = String(a.start || "");
            const sb = String(b.start || "");
            return sa.localeCompare(sb);
        });
        return list;
    }

    function renderEventCategoryBadge(ev) {
        const p = ev.extendedProps || {};
        if (p.source_type === "booking") {
            if (p.booking_type === "room") {
                return "<span class=\"badge text-bg-primary\"><i class=\"bi bi-house-fill me-1\"></i>อาคาร/ห้องประชุม</span>";
            }
            return "<span class=\"badge text-bg-success\"><i class=\"bi bi-car-front-fill me-1\"></i>ยานพาหนะ</span>";
        }
        return "<span class=\"badge\" style=\"background:#7C3AED;\"><i class=\"bi bi-calendar-event-fill me-1\"></i>กิจกรรมหน่วยงาน</span>";
    }

    function renderEventStatusBadge(ev) {
        const p = ev.extendedProps || {};
        if (p.source_type !== "booking") {
            return "<span class=\"badge text-bg-light border text-secondary\">กิจกรรม</span>";
        }
        const statusBadgeMap = {
            pending_head: "warning text-dark",
            pending_vice: "info text-dark",
            pending_director: "primary",
            approved: "success",
            rejected: "danger"
        };
        const stBadge = statusBadgeMap[p.status] || "secondary";
        return "<span class=\"badge text-bg-" + stBadge + "\">" + escapeHtml(p.status_label || p.status) + "</span>";
    }

    function renderAltViews() {
        const events = getFilteredSortedAltEvents();
        const countEl = document.getElementById("calAltCount");
        if (countEl) countEl.textContent = events.length + " รายการ";

        const tableBox = document.getElementById("calendarTableView");
        const listBox = document.getElementById("calendarListView");

        if (viewMode === "table" && tableBox) {
            if (events.length === 0) {
                tableBox.innerHTML = "<div class=\"text-center text-muted py-5\"><i class=\"bi bi-table fs-1 d-block mb-2 opacity-50\"></i>ไม่พบรายการในช่วงเวลาที่เลือก</div>";
            } else {
                let html = "<div class=\"table-responsive border rounded-3\"><table class=\"table table-bordered table-hover align-middle mb-0\">";
                html += "<thead class=\"table-light\"><tr>";
                html += "<th class=\"text-nowrap\">วันที่</th>";
                html += "<th class=\"text-nowrap\">ช่วงเวลา</th>";
                html += "<th class=\"text-nowrap\">ประเภท</th>";
                html += "<th style=\"min-width:180px;\">เรื่อง / กิจกรรม</th>";
                html += "<th style=\"min-width:150px;\">สถานที่ / ยานพาหนะ</th>";
                html += "<th class=\"text-nowrap\">สถานะ</th>";
                html += "<th class=\"text-end text-nowrap\">รายละเอียด</th>";
                html += "</tr></thead><tbody>";

                events.forEach(function (ev, idx) {
                    const p = ev.extendedProps || {};
                    const ymd = String(ev.start || "").slice(0, 10);
                    const place = p.source_type === "booking" ? (p.resource_name || "-") : (p.location || "-");
                    const dotColor = ev.color || "#2563EB";
                    const titleIcon = p.source_type === "booking"
                        ? (p.booking_type === "room" ? "bi-house-fill text-primary" : "bi-car-front-fill text-success")
                        : "bi-calendar-event-fill text-purple";
                    html += "<tr class=\"cal-table-row\" data-ev-idx=\"" + idx + "\">";
                    html += "<td class=\"text-nowrap fw-semibold small\">" + escapeHtml(formatThaiShortDate(ymd)) + "</td>";
                    html += "<td class=\"text-nowrap small\"><span class=\"cal-time-pill\"><i class=\"bi bi-clock\"></i> " + escapeHtml(formatEventTimePill(ev)) + "</span></td>";
                    html += "<td class=\"text-nowrap\">" + renderEventCategoryBadge(ev) + "</td>";
                    html += "<td><div class=\"fw-semibold text-dark small d-flex align-items-center gap-2\"><i class=\"bi " + titleIcon + "\"></i><span>" + escapeHtml(ev.title) + "</span></div>"
                         + (p.reference_code ? "<div class=\"small text-muted font-monospace\">" + escapeHtml(p.reference_code) + "</div>" : "") + "</td>";
                    html += "<td class=\"small text-secondary\">" + escapeHtml(place) + "</td>";
                    html += "<td class=\"text-nowrap\">" + renderEventStatusBadge(ev) + "</td>";
                    html += "<td class=\"text-end text-nowrap\"><button type=\"button\" class=\"btn btn-sm btn-soft-primary rounded-3 px-2 cal-open-detail\" data-ev-idx=\"" + idx + "\"><i class=\"bi bi-eye\"></i></button></td>";
                    html += "</tr>";
                });

                html += "</tbody></table></div>";
                tableBox.innerHTML = html;

                tableBox.querySelectorAll("[data-ev-idx]").forEach(function (el) {
                    el.addEventListener("click", function (e) {
                        e.stopPropagation();
                        const idx = Number(el.getAttribute("data-ev-idx"));
                        if (events[idx]) openEventDetailModal(events[idx]);
                    });
                });
            }
        }

        if (viewMode === "list" && listBox) {
            if (events.length === 0) {
                listBox.innerHTML = "<div class=\"text-center text-muted py-5\"><i class=\"bi bi-calendar-x fs-1 d-block mb-2 opacity-50\"></i>ไม่พบรายการในช่วงเวลาที่เลือก</div>";
            } else {
                const groups = new Map();
                events.forEach(function (ev, idx) {
                    const ymd = String(ev.start || "").slice(0, 10);
                    if (!groups.has(ymd)) groups.set(ymd, []);
                    groups.get(ymd).push({ ev: ev, idx: idx });
                });

                let html = "<div class=\"d-flex flex-column gap-3\">";
                groups.forEach(function (items, ymd) {
                    html += "<div class=\"cal-day-group\">";
                    html += "<div class=\"cal-day-header\">";
                    html += "<div class=\"d-flex align-items-center gap-2 flex-wrap\">";
                    html += "<span class=\"fw-bold text-dark\"><i class=\"bi bi-calendar-date text-primary me-1\"></i>" + escapeHtml(formatThaiFullDay(ymd)) + "</span>";
                    html += "<span class=\"badge rounded-pill text-bg-primary bg-opacity-75\">" + items.length + " รายการ</span>";
                    html += "</div>";
                    html += "<button type=\"button\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3 cal-book-day-btn\" data-book-date=\"" + escapeHtml(ymd) + "\"><i class=\"bi bi-plus-circle me-1\"></i>จองวันนี้</button>";
                    html += "</div>";

                    items.forEach(function (entry) {
                        const ev = entry.ev;
                        const p = ev.extendedProps || {};
                        const borderCol = ev.color || "#2563EB";
                        const place = p.source_type === "booking" ? (p.resource_name || "-") : (p.location || "-");
                        html += "<div class=\"cal-list-card\" style=\"border-left-color:" + escapeHtml(borderCol) + ";\" data-ev-idx=\"" + entry.idx + "\">";
                        html += "<div class=\"min-w-0 flex-grow-1\">";
                        html += "<div class=\"d-flex flex-wrap align-items-center gap-2 mb-1\">";
                        html += "<span class=\"cal-time-pill\"><i class=\"bi bi-clock\"></i> " + escapeHtml(formatEventTimePill(ev)) + "</span>";
                        html += renderEventCategoryBadge(ev);
                        if (p.reference_code) {
                            html += "<span class=\"badge text-bg-light border text-dark font-monospace\">" + escapeHtml(p.reference_code) + "</span>";
                        }
                        html += renderEventStatusBadge(ev);
                        html += "</div>";
                        html += "<div class=\"fw-bold text-dark\">" + escapeHtml(ev.title) + "</div>";
                        html += "<div class=\"small text-muted d-flex flex-wrap gap-3 mt-1\">";
                        html += "<span><i class=\"bi bi-geo-alt me-1\"></i>" + escapeHtml(place) + "</span>";
                        if (p.source_type === "booking" && !p.is_masked && p.requester_name) {
                            html += "<span><i class=\"bi bi-person me-1\"></i>" + escapeHtml(p.requester_name) + "</span>";
                        }
                        html += "</div></div>";
                        html += "<div class=\"flex-shrink-0 text-md-end\"><span class=\"btn btn-sm btn-soft-primary rounded-3 px-3\">ดูรายละเอียด</span></div>";
                        html += "</div>";
                    });

                    html += "</div>";
                });
                html += "</div>";

                listBox.innerHTML = html;

                listBox.querySelectorAll(".cal-list-card[data-ev-idx]").forEach(function (el) {
                    el.addEventListener("click", function () {
                        const idx = Number(el.getAttribute("data-ev-idx"));
                        if (events[idx]) openEventDetailModal(events[idx]);
                    });
                });

                listBox.querySelectorAll(".cal-book-day-btn[data-book-date]").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        const ymd = btn.getAttribute("data-book-date");
                        if (!ymd) return;
                        currentSlotStart = ymd + "T09:00";
                        currentSlotEnd = ymd + "T12:00";
                        openSlotActionModal();
                    });
                });
            }
        }
    }

    function fetchAndRenderAltViews() {
        const range = computeAltRange();
        const titleEl = document.getElementById("calAltTitle");
        if (titleEl) titleEl.textContent = range.title;

        const url = eventsApi + "?start=" + encodeURIComponent(range.startStr) + "&end=" + encodeURIComponent(range.endStr) + "&type=" + encodeURIComponent(activeFilter);
        fetch(url, { headers: { "Accept": "application/json" } })
            .then(r => r.json())
            .then(function (data) {
                cachedAltEvents = Array.isArray(data) ? data : (data.events || []);
                renderAltViews();
            })
            .catch(function () {
                cachedAltEvents = [];
                renderAltViews();
            });
    }

    function applyViewMode(mode) {
        viewMode = mode;
        try { localStorage.setItem("slp_cal_view_mode", mode); } catch (e) {}

        document.querySelectorAll(".cal-mode-btn").forEach(function (btn) {
            btn.classList.toggle("active", btn.getAttribute("data-view-mode") === mode);
        });

        const altToolbar = document.getElementById("calendarAltToolbar");
        const calBox = document.getElementById("masterCalendar");
        const tableBox = document.getElementById("calendarTableView");
        const listBox = document.getElementById("calendarListView");

        if (mode === "calendar") {
            if (altToolbar) altToolbar.classList.add("d-none");
            if (calBox) calBox.classList.remove("d-none");
            if (tableBox) tableBox.classList.add("d-none");
            if (listBox) listBox.classList.add("d-none");
            calendar.gotoDate(anchorDate);
            calendar.updateSize();
            calendar.refetchEvents();
        } else {
            if (altToolbar) altToolbar.classList.remove("d-none");
            if (calBox) calBox.classList.add("d-none");
            if (tableBox) tableBox.classList.toggle("d-none", mode !== "table");
            if (listBox) listBox.classList.toggle("d-none", mode !== "list");
            fetchAndRenderAltViews();
        }
    }

    document.querySelectorAll(".cal-mode-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const m = btn.getAttribute("data-view-mode") || "calendar";
            applyViewMode(m);
        });
    });

    document.querySelectorAll(".cal-scope-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            altScope = btn.getAttribute("data-alt-scope") || "month";
            document.querySelectorAll(".cal-scope-btn").forEach(function (b) {
                b.classList.remove("btn-primary");
                b.classList.add("btn-light", "border", "text-secondary");
            });
            btn.classList.remove("btn-light", "border", "text-secondary");
            btn.classList.add("btn-primary");
            fetchAndRenderAltViews();
        });
    });

    document.getElementById("calAltPrev")?.addEventListener("click", function () {
        if (altScope === "week") anchorDate.setDate(anchorDate.getDate() - 7);
        else if (altScope === "day") anchorDate.setDate(anchorDate.getDate() - 1);
        else if (altScope === "next30") anchorDate.setDate(anchorDate.getDate() - 30);
        else anchorDate = new Date(anchorDate.getFullYear(), anchorDate.getMonth() - 1, 1);
        fetchAndRenderAltViews();
    });

    document.getElementById("calAltNext")?.addEventListener("click", function () {
        if (altScope === "week") anchorDate.setDate(anchorDate.getDate() + 7);
        else if (altScope === "day") anchorDate.setDate(anchorDate.getDate() + 1);
        else if (altScope === "next30") anchorDate.setDate(anchorDate.getDate() + 30);
        else anchorDate = new Date(anchorDate.getFullYear(), anchorDate.getMonth() + 1, 1);
        fetchAndRenderAltViews();
    });

    document.getElementById("calAltToday")?.addEventListener("click", function () {
        anchorDate = new Date();
        anchorDate.setHours(0, 0, 0, 0);
        fetchAndRenderAltViews();
    });

    document.getElementById("calAltSearch")?.addEventListener("input", renderAltViews);

    function openSlotActionModal() {
        const sumEl = document.getElementById("slotActionSummary");
        const roomLnk = document.getElementById("slotBookRoomLink");
        const vehLnk = document.getElementById("slotBookVehicleLink");
        const qs = "?start=" + encodeURIComponent(currentSlotStart) + "&end=" + encodeURIComponent(currentSlotEnd);
        if (sumEl) sumEl.textContent = "ช่วงเวลาที่เลือก: " + currentSlotStart.replace("T", " ") + " ถึง " + currentSlotEnd.replace("T", " ");
        if (roomLnk) roomLnk.href = bookRoomBase + qs;
        if (vehLnk) vehLnk.href = bookVehicleBase + qs;
        if (slotModal) slotModal.show();
    }

    function refreshActiveView() {
        if (viewMode === "calendar") {
            calendar.refetchEvents();
        } else {
            fetchAndRenderAltViews();
        }
    }

    document.querySelectorAll(".cal-filter-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            activeFilter = btn.getAttribute("data-type") || "all";
            document.querySelectorAll(".cal-filter-btn").forEach(function (b) {
                b.classList.remove("btn-primary");
                b.classList.add("btn-light", "border", "text-secondary");
            });
            btn.classList.remove("btn-light", "border", "text-secondary");
            btn.classList.add("btn-primary");
            refreshActiveView();
        });
    });

    function openEventForm(mode, evData) {
        if (!eventFormModal) return;
        const form = document.getElementById("calendarEventForm");
        const errBox = document.getElementById("evFormError");
        if (errBox) errBox.classList.add("d-none");
        document.getElementById("ev_action").value = mode;
        document.getElementById("eventFormModalTitle").textContent = mode === "update" ? "แก้ไขกิจกรรมหน่วยงาน" : "เพิ่มกิจกรรมหน่วยงาน";

        if (mode === "update" && evData) {
            const p = evData.extendedProps || {};
            document.getElementById("ev_id").value = p.event_id || "";
            document.getElementById("ev_title").value = evData.title || "";
            document.getElementById("ev_type").value = p.event_type || "general";
            document.getElementById("ev_location").value = (p.location && p.location !== "-") ? p.location : "";
            document.getElementById("ev_start").value = p.start_raw || "";
            document.getElementById("ev_end").value = p.end_raw || "";
            document.getElementById("ev_description").value = (p.description && p.description !== "-") ? p.description : "";
            document.getElementById("ev_all_day").checked = Boolean(p.is_all_day);
            document.getElementById("ev_public").checked = Number(p.is_public) === 1;
        } else {
            if (form) form.reset();
            document.getElementById("ev_action").value = "create";
            document.getElementById("ev_id").value = "";
            document.getElementById("ev_public").checked = true;
            if (currentSlotStart) document.getElementById("ev_start").value = currentSlotStart;
            if (currentSlotEnd) document.getElementById("ev_end").value = currentSlotEnd;
        }
        eventFormModal.show();
    }

    const addEventBtn = document.getElementById("btnOpenAddEvent");
    if (addEventBtn) {
        addEventBtn.addEventListener("click", function () {
            const now = new Date();
            const dStr = toInputLocal(now).slice(0, 10);
            currentSlotStart = dStr + "T09:00";
            currentSlotEnd = dStr + "T12:00";
            openEventForm("create");
        });
    }

    const slotAddEvBtn = document.getElementById("slotAddEventBtn");
    if (slotAddEvBtn) {
        slotAddEvBtn.addEventListener("click", function () {
            if (slotModal) slotModal.hide();
            openEventForm("create");
        });
    }

    const editCalBtn = document.getElementById("btnEditCalEvent");
    if (editCalBtn) {
        editCalBtn.addEventListener("click", function () {
            if (detailModal) detailModal.hide();
            if (activeCalendarEvent) openEventForm("update", activeCalendarEvent);
        });
    }

    const delCalBtn = document.getElementById("btnDeleteCalEvent");
    if (delCalBtn) {
        delCalBtn.addEventListener("click", function () {
            if (!activeCalendarEvent) return;
            const p = activeCalendarEvent.extendedProps || {};
            if (!p.event_id) return;
            window.SLPApp.confirmAction("ต้องการลบกิจกรรม \"" + activeCalendarEvent.title + "\" ใช่หรือไม่?").then(function (ok) {
                if (!ok) return;
                fetch(saveApi, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken, "Accept": "application/json" },
                    body: JSON.stringify({ _csrf_token: csrfToken, action: "delete", id: p.event_id })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        if (detailModal) detailModal.hide();
                        refreshActiveView();
                        window.SLPApp.showToast("ลบกิจกรรมเรียบร้อยแล้ว", "success");
                    } else {
                        window.SLPApp.showToast(res.error || "ไม่สามารถลบกิจกรรมได้", "danger");
                    }
                });
            });
        });
    }

    const evForm = document.getElementById("calendarEventForm");
    if (evForm) {
        evForm.addEventListener("submit", function (e) {
            e.preventDefault();
            const errBox = document.getElementById("evFormError");
            const payload = {
                _csrf_token: csrfToken,
                action: document.getElementById("ev_action").value,
                id: document.getElementById("ev_id").value,
                title: document.getElementById("ev_title").value,
                event_type: document.getElementById("ev_type").value,
                location: document.getElementById("ev_location").value,
                start_datetime: document.getElementById("ev_start").value,
                end_datetime: document.getElementById("ev_end").value,
                description: document.getElementById("ev_description").value,
                is_all_day: document.getElementById("ev_all_day").checked ? 1 : 0,
                is_public: document.getElementById("ev_public").checked ? 1 : 0
            };
            fetch(saveApi, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken, "Accept": "application/json" },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    if (eventFormModal) eventFormModal.hide();
                    refreshActiveView();
                    window.SLPApp.showToast("บันทึกกิจกรรมเรียบร้อยแล้ว", "success");
                } else if (errBox) {
                    errBox.textContent = res.error || "ไม่สามารถบันทึกกิจกรรมได้";
                    errBox.classList.remove("d-none");
                }
            })
            .catch(() => {
                if (errBox) {
                    errBox.textContent = "เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์";
                    errBox.classList.remove("d-none");
                }
            });
        });
    }

    if (viewMode !== "calendar") {
        applyViewMode(viewMode);
    }
});
</script>';

require __DIR__ . '/includes/layout_footer.php';
