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
            border-radius: 0.4rem;
            padding: 1px 4px;
            font-size: 0.8rem;
            cursor: pointer;
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
                    <span class="fc-legend-dot" style="background: #2563EB;"></span> ห้องประชุม
                </span>
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="fc-legend-dot" style="background: #059669;"></span> ยานพาหนะ
                </span>
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="fc-legend-dot" style="background: #7C3AED;"></span> กิจกรรมหน่วยงาน
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

<!-- Calendar Filter & Canvas Card -->
<div class="spotlight-card">
    <div class="spotlight-header">
        <div class="d-flex flex-wrap align-items-center gap-1" id="calendarFilterGroup">
            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 cal-filter-btn" data-type="all">ทั้งหมด</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="room">ห้องประชุม</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="vehicle">ยานพาหนะ</button>
            <button type="button" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 cal-filter-btn" data-type="event">กิจกรรมหน่วยงาน</button>
        </div>
        <div class="small text-muted d-none d-md-block">
            <i class="bi bi-hand-index me-1"></i>คลิกวันที่หรือช่วงเวลาบนปฏิทินเพื่อจองทันที
        </div>
    </div>

    <div class="p-2 p-sm-3 p-md-4">
        <div id="masterCalendar" data-testid="master-calendar"></div>
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

    let activeFilter = "all";
    let currentSlotStart = "";
    let currentSlotEnd = "";
    let activeCalendarEvent = null;

    function fmtThaiRange(startDate, endDate, allDay) {
        if (!startDate) return "-";
        const optDate = { year: "numeric", month: "short", day: "numeric", timeZone: "Asia/Bangkok" };
        const optTime = { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Bangkok" };
        const sDate = startDate.toLocaleDateString("th-TH", optDate);
        if (allDay) {
            return sDate + " (ตลอดวัน)";
        }
        const sTime = startDate.toLocaleTimeString("th-TH", optTime);
        if (!endDate) return sDate + " " + sTime + " น.";
        const eDate = endDate.toLocaleDateString("th-TH", optDate);
        const eTime = endDate.toLocaleTimeString("th-TH", optTime);
        if (sDate === eDate) {
            return sDate + " เวลา " + sTime + " - " + eTime + " น.";
        }
        return sDate + " " + sTime + " น. ถึง " + eDate + " " + eTime + " น.";
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

    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: "th",
        initialView: "dayGridMonth",
        height: "auto",
        navLinks: true,
        selectable: true,
        dayMaxEvents: 3,
        eventTimeFormat: {
            hour: "2-digit",
            minute: "2-digit",
            hour12: false
        },
        headerToolbar: {
            left: "dayGridMonth,timeGridWeek,timeGridDay",
            center: "title",
            right: "prev,next today"
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
        eventClick: function (info) {
            const ev = info.event;
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
    });

    calendar.render();

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

    document.querySelectorAll(".cal-filter-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            activeFilter = btn.getAttribute("data-type") || "all";
            document.querySelectorAll(".cal-filter-btn").forEach(function (b) {
                b.classList.remove("btn-primary");
                b.classList.add("btn-light", "border", "text-secondary");
            });
            btn.classList.remove("btn-light", "border", "text-secondary");
            btn.classList.add("btn-primary");
            calendar.refetchEvents();
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
                        calendar.refetchEvents();
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
                    calendar.refetchEvents();
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
});
</script>';

require __DIR__ . '/includes/layout_footer.php';
