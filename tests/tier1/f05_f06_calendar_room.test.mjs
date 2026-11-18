import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { extractCsrf, extractFormInfo } from '../lib/http.mjs';

const TIER = 1;
const FEATURE_F5 = 'F5';
const FEATURE_F6 = 'F6';

function bangkokParts(date = new Date()) {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  });
  const parts = Object.fromEntries(fmt.formatToParts(date).map((p) => [p.type, p.value]));
  return {
    year: Number(parts.year),
    month: Number(parts.month),
    day: Number(parts.day)
  };
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

function currentMonthRange() {
  const { year, month } = bangkokParts();
  const nextYear = month === 12 ? year + 1 : year;
  const nextMonth = month === 12 ? 1 : month + 1;
  return {
    start: `${year}-${pad2(month)}-01`,
    end: `${nextYear}-${pad2(nextMonth)}-01`
  };
}

// Fixed non-colliding Tier 1 future window: 2027-04-01..2027-04-28
function tier1Window(day, startHour = 9, endHour = 11) {
  if (day < 1 || day > 28) {
    throw new Error(`Tier 1 day out of 1..28 range: ${day}`);
  }
  const date = `2027-04-${pad2(day)}`;
  const nextDay = `2027-04-${pad2(day + 1)}`;
  const sh = pad2(startHour);
  const eh = pad2(endHour);
  return {
    date,
    nextDay,
    start: `${date} ${sh}:00:00`,
    end: `${date} ${eh}:00:00`,
    inputStart: `${date}T${sh}:00`,
    inputEnd: `${date}T${eh}:00`
  };
}

function extractCsrfPair(html, actionHint = null) {
  if (!html || typeof html !== 'string') {
    return { fieldName: '_csrf_token', token: null };
  }

  // Prefer form matching actionHint if present
  if (actionHint) {
    const formInfo = extractFormInfo(html, actionHint);
    if ((formInfo.action || '').toLowerCase().includes(actionHint.toLowerCase())) {
      for (const name of ['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token']) {
        const found = formInfo.inputs.find((inp) => inp.name.toLowerCase() === name);
        if (found && found.value) {
          return { fieldName: found.name, token: found.value };
        }
      }
    }
  }

  // Scan all inputs for _csrf_token or standard names
  const inputTags = html.matchAll(/<input\b([^>]*)>/gi);
  for (const m of inputTags) {
    const attrs = m[1];
    const nameMatch = attrs.match(/\bname\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/i);
    const valMatch = attrs.match(/\bvalue\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/i);
    const name = nameMatch ? (nameMatch[1] ?? nameMatch[2] ?? nameMatch[3] ?? '') : '';
    const value = valMatch ? (valMatch[1] ?? valMatch[2] ?? valMatch[3] ?? '') : '';
    if (/^(?:_csrf_token|csrf_token|_token|csrf|__csrf_token)$/i.test(name) && value) {
      return { fieldName: name, token: value };
    }
  }

  // Fallback to http.mjs extractCsrf
  const std = extractCsrf(html);
  if (std) {
    return { fieldName: '_csrf_token', token: std };
  }

  // Meta tag check including _csrf_token
  const metaMatch = html.match(
    /<meta\b[^>]*name\s*=\s*["'](?:csrf-token|_csrf_token|csrf_token|_token)["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>/i
  ) || html.match(
    /<meta\b[^>]*content\s*=\s*["']([^"']+)["'][^>]*name\s*=\s*["'](?:csrf-token|_csrf_token|csrf_token|_token)["'][^>]*>/i
  );
  if (metaMatch && metaMatch[1]) {
    return { fieldName: '_csrf_token', token: metaMatch[1] };
  }

  return { fieldName: '_csrf_token', token: null };
}

function extractAnyCsrf(html, actionHint = null) {
  return extractCsrfPair(html, actionHint).token;
}

function extractBookingFormMeta(html) {
  const formRe = /<form\b([^>]*)>([\s\S]*?)<\/form>/gi;
  let m;
  let matchedBody = null;
  let matchedAction = '/book_room.php';

  while ((m = formRe.exec(html)) !== null) {
    const attrs = m[1];
    const body = m[2];
    if (/\bname\s*=\s*["']room_id["']/i.test(body)) {
      matchedBody = body;
      const actionMatch = attrs.match(/\baction\s*=\s*(?:"([^"]*)"|'([^']*)')/i);
      const rawAction = actionMatch ? (actionMatch[1] ?? actionMatch[2] ?? '') : '';
      matchedAction = rawAction || '/book_room.php';
      break;
    }
  }

  const csrf = matchedBody
    ? extractCsrfPair(matchedBody, null)
    : extractCsrfPair(html, 'book_room');

  let equipmentFieldName = 'equipment_requested[]';
  if (matchedBody) {
    const eqMatch = matchedBody.match(
      /<input\b[^>]*type\s*=\s*["']checkbox["'][^>]*name\s*=\s*["']([^"']+)["'][^>]*>/i
    );
    if (eqMatch && eqMatch[1]) {
      equipmentFieldName = eqMatch[1];
    }
  }

  let fileFieldName = 'permission_letter';
  if (matchedBody) {
    const fileMatch = matchedBody.match(
      /<input\b[^>]*type\s*=\s*["']file["'][^>]*name\s*=\s*["']([^"']+)["'][^>]*>/i
    );
    if (fileMatch && fileMatch[1]) {
      fileFieldName = fileMatch[1];
    }
  }

  return { action: matchedAction, csrf, equipmentFieldName, fileFieldName };
}

function normalizeEvents(jsonBody) {
  if (Array.isArray(jsonBody)) return jsonBody;
  if (jsonBody && Array.isArray(jsonBody.events)) return jsonBody.events;
  if (jsonBody && Array.isArray(jsonBody.data)) return jsonBody.data;
  return null;
}

function eventColor(ev) {
  return String(
    ev?.color || ev?.backgroundColor || ev?.borderColor || ev?.extendedProps?.color || ''
  ).trim().toUpperCase();
}

function findEvent(events, { bookingId, referenceCode, titleSubstr }) {
  return events.find((ev) => {
    const props = ev?.extendedProps || {};
    const idStr = String(ev?.id ?? '');
    if (bookingId !== undefined && (props.booking_id == bookingId || idStr === String(bookingId) || idStr === `booking-${bookingId}`)) {
      return true;
    }
    if (referenceCode && (props.reference_code === referenceCode || idStr.includes(referenceCode))) {
      return true;
    }
    if (titleSubstr && String(ev?.title || '').includes(titleSubstr)) {
      return true;
    }
    return false;
  }) || null;
}

function registerBookingCleanup(ctx, bookingId, fileRelPath = null) {
  ctx.onCleanup(async () => {
    if (fileRelPath) {
      const cleanRel = String(fileRelPath).replace(/^\/+/, '');
      const fullPath = path.join(ctx.env.projectRoot, cleanRel);
      if (fs.existsSync(fullPath)) {
        fs.unlinkSync(fullPath);
      }
    }
    if (bookingId) {
      await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [Number(bookingId)]).catch(() => {});
      await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [Number(bookingId)]).catch(() => {});
      await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [Number(bookingId)]);
    }
  });
}

function registerEventCleanup(ctx, eventId) {
  ctx.onCleanup(async () => {
    if (eventId) {
      await ctx.db.execSql('DELETE FROM calendar_events WHERE id = ?', [Number(eventId)]);
    }
  });
}

async function saveCalendarEvent(client, csrfPair, payload, preferredMode = null) {
  const withCsrf = {
    ...payload,
    [csrfPair.fieldName]: csrfPair.token,
    _csrf_token: csrfPair.token
  };
  const headers = {
    'X-CSRF-Token': csrfPair.token,
    Accept: 'application/json'
  };

  if (preferredMode === 'form') {
    const res = await client.postForm('/api/calendar_event_save.php', withCsrf, { headers });
    return { res, mode: 'form' };
  }

  const jsonRes = await client.postJson('/api/calendar_event_save.php', withCsrf, { headers });
  if (preferredMode === 'json') {
    return { res: jsonRes, mode: 'json' };
  }

  if ([400, 415, 422].includes(jsonRes.status)) {
    const formRes = await client.postForm('/api/calendar_event_save.php', withCsrf, { headers });
    if (formRes.status < 400) {
      return { res: formRes, mode: 'form' };
    }
  }
  return { res: jsonRes, mode: 'json' };
}

export function register(test) {
  // =========================================================================
  // F5 — Master Calendar & PII Masking (Tier 1: T1-F5-01 .. T1-F5-06)
  // =========================================================================

  test({
    id: 'T1-F5-01',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'calendar.php renders FullCalendar v6 in desktop Chrome without errors',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      const fc = page.locator('.fc').first();
      await fc.waitFor({ state: 'visible', timeout: 10000 });
      await page.locator('.fc-event').first().waitFor({ state: 'visible', timeout: 10000 });

      errors.assertNoErrors('T1-F5-01 calendar.php');

      const box = await fc.boundingBox();
      assert.ok(box && box.width > 300 && box.height > 200, `Expected .fc bounding box > 300x200, got ${JSON.stringify(box)}`);

      const eventCount = await page.locator('.fc-event').count();
      assert.ok(eventCount >= 1, `Expected >= 1 rendered .fc-event, got ${eventCount}`);

      const bodyText = await page.locator('main, body').first().innerText();
      const pageTitle = await page.title();
      assert.ok(
        /ปฏิทิน|calendar/i.test(`${pageTitle} ${bodyText}`),
        `Expected calendar heading/title on calendar.php, got title="${pageTitle}"`
      );
    }
  });

  test({
    id: 'T1-F5-02',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Calendar month, week, and day view buttons switch views cleanly',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.head01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      await page.locator('.fc').first().waitFor({ state: 'visible', timeout: 10000 });

      const titleLocator = page.locator('.fc-toolbar-title').first();
      const monthTitle = (await titleLocator.textContent().catch(() => ''))?.trim() || '';

      const weekBtn = page.locator('.fc-timeGridWeek-button, button:has-text("สัปดาห์"), button:has-text("Week")').first();
      await weekBtn.click();
      await page.locator('.fc-timeGridWeek-view, .fc-timegrid').first().waitFor({ state: 'visible', timeout: 8000 });
      const weekClass = (await weekBtn.getAttribute('class')) || '';
      const weekPressed = await weekBtn.getAttribute('aria-pressed');
      assert.ok(
        weekClass.includes('fc-button-active') || weekPressed === 'true',
        `Expected week button to be active after click (class="${weekClass}", aria-pressed="${weekPressed}")`
      );
      const weekTitle = (await titleLocator.textContent().catch(() => ''))?.trim() || '';

      const dayBtn = page.locator('.fc-timeGridDay-button, button:has-text("วัน"), button:has-text("Day")').first();
      await dayBtn.click();
      await page.locator('.fc-timeGridDay-view, .fc-timegrid').first().waitFor({ state: 'visible', timeout: 8000 });
      const dayTitle = (await titleLocator.textContent().catch(() => ''))?.trim() || '';
      assert.notEqual(dayTitle, weekTitle, `Expected toolbar title to change between week ("${weekTitle}") and day ("${dayTitle}")`);

      const monthBtn = page.locator('.fc-dayGridMonth-button, button:has-text("เดือน"), button:has-text("Month")').first();
      await monthBtn.click();
      await page.locator('.fc-dayGridMonth-view, .fc-daygrid').first().waitFor({ state: 'visible', timeout: 8000 });
      const restoredMonthTitle = (await titleLocator.textContent().catch(() => ''))?.trim() || '';
      if (monthTitle) {
        assert.equal(restoredMonthTitle, monthTitle, 'Expected month view title to match initial month title');
      }

      errors.assertNoErrors('T1-F5-02 view switch');
    }
  });

  test({
    id: 'T1-F5-03',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'api/calendar_events.php returns JSON with exact color codes (#2563EB room, #059669 vehicle, #7C3AED event)',
    fn: async (ctx) => {
      const client = await ctx.http.login(ctx.env.seedUsers.head01.username, ctx.env.seedPassword);
      const { start, end } = currentMonthRange();
      const res = await client.get(
        `/api/calendar_events.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`,
        { headers: { Accept: 'application/json' } }
      );

      assert.equal(res.status, 200, `Expected HTTP 200 from api/calendar_events.php, got ${res.status}`);
      const ctype = res.headers.get('content-type') || '';
      assert.ok(ctype.toLowerCase().includes('application/json'), `Expected JSON content-type, got "${ctype}"`);

      const events = normalizeEvents(res.json());
      assert.ok(Array.isArray(events), 'Expected JSON array or {events: [...]}');
      assert.ok(events.length >= 6, `Expected >= 6 seeded events (5 non-rejected bookings + 3 calendar_events), got ${events.length}`);

      const bkRoom = findEvent(events, {
        bookingId: 1,
        referenceCode: 'BK-001',
        titleSubstr: 'ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน'
      });
      assert.ok(bkRoom, 'Expected seeded room booking BK-001 in calendar_events API');
      assert.equal(eventColor(bkRoom), '#2563EB', `Expected room booking color #2563EB, got ${eventColor(bkRoom)}`);

      const bkVehicle = findEvent(events, {
        bookingId: 3,
        referenceCode: 'BK-003',
        titleSubstr: 'เดินทางไปประสานงานเอกสาร ณ ศูนย์ราชการ'
      });
      assert.ok(bkVehicle, 'Expected seeded vehicle booking BK-003 in calendar_events API');
      assert.equal(eventColor(bkVehicle), '#059669', `Expected vehicle booking color #059669, got ${eventColor(bkVehicle)}`);

      const calEvent = findEvent(events, {
        titleSubstr: 'วันพัฒนาองค์กรและทำความสะอาดใหญ่'
      });
      assert.ok(calEvent, 'Expected seeded calendar_event "วันพัฒนาองค์กรและทำความสะอาดใหญ่ (5ส.)"');
      assert.equal(eventColor(calEvent), '#7C3AED', `Expected calendar event color #7C3AED, got ${eventColor(calEvent)}`);
    }
  });

  test({
    id: 'T1-F5-04',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Clicking a calendar event on calendar.php opens the event detail modal',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.head01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      await page.locator('.fc-event').first().waitFor({ state: 'visible', timeout: 10000 });

      const preferred = page.locator('.fc-event', { hasText: 'ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน' }).first();
      const targetEvent = (await preferred.count()) > 0 ? preferred : page.locator('.fc-event').first();
      const clickedTitle = ((await targetEvent.innerText().catch(() => '')) || '').trim();
      await targetEvent.click();

      const modal = page.locator('.modal.show, [data-testid="calendar-event-modal"].show, [role="dialog"]:visible').first();
      await modal.waitFor({ state: 'visible', timeout: 8000 });
      const modalText = ((await modal.innerText()) || '').trim();
      assert.ok(modalText.length > 10, `Expected non-empty modal detail text, got "${modalText}"`);
      if (clickedTitle) {
        const snippet = clickedTitle.replace(/^\d{1,2}:\d{2}\s*/, '').slice(0, 12);
        if (snippet.length >= 4) {
          assert.ok(
            modalText.includes(snippet),
            `Expected modal text to include clicked event title snippet "${snippet}", got "${modalText}"`
          );
        }
      }

      const closeBtn = modal.locator('.btn-close, [data-bs-dismiss="modal"]').first();
      await closeBtn.click();
      await modal.waitFor({ state: 'hidden', timeout: 5000 });

      errors.assertNoErrors('T1-F5-04 event modal');
    }
  });

  test({
    id: 'T1-F5-05',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'admin and staff01 create, edit, and delete a calendar_event via api/calendar_event_save.php',
    fn: async (ctx) => {
      // 1. Admin create -> edit -> delete on 2027-04-02
      const adminClient = await ctx.http.login(ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
      const adminCalHtml = (await adminClient.get('/calendar.php')).text;
      const adminCsrf = extractCsrfPair(adminCalHtml, 'calendar_event');
      assert.ok(adminCsrf.token, 'Expected CSRF token on calendar.php for admin');

      const winAdmin = tier1Window(2, 10, 12);
      const uniq = Date.now();
      const adminTitle = `E2E-T1-F5-05-ADM-${uniq}`;
      const adminUpdatedTitle = `${adminTitle}-UPDATED`;

      const { res: createRes, mode } = await saveCalendarEvent(adminClient, adminCsrf, {
        action: 'create',
        title: adminTitle,
        event_type: 'meeting',
        description: 'E2E admin event description',
        location: 'Room A',
        start_datetime: winAdmin.start,
        end_datetime: winAdmin.end,
        is_all_day: 0,
        is_public: 1
      });
      assert.ok([200, 201].includes(createRes.status), `Expected 200/201 on admin event create, got ${createRes.status}: ${createRes.text}`);
      const createJson = createRes.json();
      assert.notEqual(createJson?.ok, false, `Expected ok !== false on create: ${createRes.text}`);

      const rowsAfterCreate = await ctx.db.queryJson(
        'SELECT id, title, location, created_by FROM calendar_events WHERE title = ?',
        [adminTitle]
      );
      assert.equal(rowsAfterCreate.length, 1, 'Expected 1 calendar_event row inserted by admin');
      const adminEventId = Number(rowsAfterCreate[0].id);
      registerEventCleanup(ctx, adminEventId);
      assert.equal(rowsAfterCreate[0].location, 'Room A');
      assert.equal(Number(rowsAfterCreate[0].created_by), 1);

      // Edit
      const { res: editRes } = await saveCalendarEvent(
        adminClient,
        adminCsrf,
        {
          action: 'update',
          id: adminEventId,
          title: adminUpdatedTitle,
          event_type: 'general',
          description: 'E2E admin event updated',
          location: 'Room B',
          start_datetime: winAdmin.start,
          end_datetime: winAdmin.end,
          is_all_day: 0,
          is_public: 1
        },
        mode
      );
      assert.equal(editRes.status, 200, `Expected 200 on admin event update, got ${editRes.status}: ${editRes.text}`);

      const rowsAfterEdit = await ctx.db.queryJson(
        'SELECT id, title, location FROM calendar_events WHERE id = ?',
        [adminEventId]
      );
      assert.equal(rowsAfterEdit.length, 1);
      assert.equal(rowsAfterEdit[0].title, adminUpdatedTitle);
      assert.equal(rowsAfterEdit[0].location, 'Room B');

      const feedAfterEdit = await adminClient.get(
        `/api/calendar_events.php?start=${encodeURIComponent(winAdmin.date)}&end=${encodeURIComponent(winAdmin.nextDay)}`,
        { headers: { Accept: 'application/json' } }
      );
      assert.equal(feedAfterEdit.status, 200);
      const editedEvents = normalizeEvents(feedAfterEdit.json());
      const foundEdited = editedEvents.find((e) => String(e.title || '').includes(adminUpdatedTitle));
      assert.ok(foundEdited, 'Expected updated event in api/calendar_events.php feed');
      assert.equal(eventColor(foundEdited), '#7C3AED');

      // Delete
      const { res: delRes } = await saveCalendarEvent(
        adminClient,
        adminCsrf,
        { action: 'delete', id: adminEventId },
        mode
      );
      assert.equal(delRes.status, 200, `Expected 200 on admin event delete, got ${delRes.status}: ${delRes.text}`);
      const countAfterDel = Number(
        await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE id = ?', [adminEventId])
      );
      assert.equal(countAfterDel, 0, 'Expected admin calendar_event deleted from DB');

      const feedAfterDel = await adminClient.get(
        `/api/calendar_events.php?start=${encodeURIComponent(winAdmin.date)}&end=${encodeURIComponent(winAdmin.nextDay)}`,
        { headers: { Accept: 'application/json' } }
      );
      const eventsAfterDel = normalizeEvents(feedAfterDel.json());
      assert.ok(
        !eventsAfterDel.some((e) => String(e.title || '').includes(adminUpdatedTitle)),
        'Expected deleted event to be absent from api/calendar_events.php'
      );

      // 2. Staff01 create -> delete on 2027-04-03
      const staffClient = await ctx.http.login(ctx.env.seedUsers.staff01.username, ctx.env.seedPassword);
      const staffCalHtml = (await staffClient.get('/calendar.php')).text;
      const staffCsrf = extractCsrfPair(staffCalHtml, 'calendar_event');
      assert.ok(staffCsrf.token, 'Expected CSRF token on calendar.php for staff01');

      const winStaff = tier1Window(3, 14, 16);
      const staffTitle = `E2E-T1-F5-05-STF-${uniq}`;
      const { res: staffCreateRes, mode: staffMode } = await saveCalendarEvent(staffClient, staffCsrf, {
        action: 'create',
        title: staffTitle,
        event_type: 'maintenance',
        description: 'E2E staff event',
        location: 'Building 1',
        start_datetime: winStaff.start,
        end_datetime: winStaff.end,
        is_all_day: 0,
        is_public: 1
      });
      assert.ok([200, 201].includes(staffCreateRes.status), `Expected 200/201 on staff01 event create, got ${staffCreateRes.status}`);

      const staffRows = await ctx.db.queryJson(
        'SELECT id, created_by FROM calendar_events WHERE title = ?',
        [staffTitle]
      );
      assert.equal(staffRows.length, 1, 'Expected 1 calendar_event row inserted by staff01');
      const staffEventId = Number(staffRows[0].id);
      registerEventCleanup(ctx, staffEventId);
      assert.equal(Number(staffRows[0].created_by), 2);

      const { res: staffDelRes } = await saveCalendarEvent(
        staffClient,
        staffCsrf,
        { action: 'delete', id: staffEventId },
        staffMode
      );
      assert.equal(staffDelRes.status, 200);
      const staffCountAfterDel = Number(
        await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE id = ?', [staffEventId])
      );
      assert.equal(staffCountAfterDel, 0, 'Expected staff01 calendar_event deleted from DB');
    }
  });

  test({
    id: 'T1-F5-06',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Approver (head01, staff01) and owner (user01) receive full unmasked PII',
    fn: async (ctx) => {
      const { start, end } = currentMonthRange();
      const query = `/api/calendar_events.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;

      for (const username of ['head01', 'staff01', 'user01']) {
        const client = await ctx.http.login(username, ctx.env.seedPassword);
        const res = await client.get(query, { headers: { Accept: 'application/json' } });
        assert.equal(res.status, 200, `Expected HTTP 200 for ${username}`);
        const events = normalizeEvents(res.json());
        assert.ok(Array.isArray(events), `Expected array of events for ${username}`);

        const bk1 = findEvent(events, {
          bookingId: 1,
          referenceCode: 'BK-001',
          titleSubstr: 'ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน'
        });
        assert.ok(bk1, `Expected BK-001 visible for ${username}`);
        assert.notEqual(bk1.extendedProps?.is_masked, true, `Expected BK-001 unmasked for ${username}`);
        const bk1Str = JSON.stringify(bk1);
        assert.ok(bk1Str.includes('กมลชนก'), `Expected requester_name "กมลชนก" visible to ${username}`);
        assert.ok(bk1Str.includes('งบประมาณประจำเดือน'), `Expected BK-001 purpose visible to ${username}`);

        const bk3 = findEvent(events, {
          bookingId: 3,
          referenceCode: 'BK-003',
          titleSubstr: 'เดินทางไปประสานงานเอกสาร ณ ศูนย์ราชการ'
        });
        assert.ok(bk3, `Expected BK-003 visible for ${username}`);
        assert.notEqual(bk3.extendedProps?.is_masked, true, `Expected BK-003 unmasked for ${username}`);
        const bk3Str = JSON.stringify(bk3);
        assert.ok(bk3Str.includes('กมลชนก'), `Expected BK-003 requester_name visible to ${username}`);
        assert.ok(bk3Str.includes('ศูนย์ราชการ'), `Expected BK-003 destination visible to ${username}`);
      }
    }
  });

  // =========================================================================
  // F6 — Room Booking (Internal & External) (Tier 1: T1-F6-01 .. T1-F6-06)
  // =========================================================================

  test({
    id: 'T1-F6-01',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Internal user (user01) books internal-only room (RM-201, allow_external=0) via UI',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      const roomSelect = page.locator('select[name="room_id"]').first();
      await roomSelect.waitFor({ state: 'visible', timeout: 8000 });

      const optionValues = await roomSelect.locator('option').evaluateAll((opts) => opts.map((o) => o.value));
      for (const expectedId of ['1', '2', '3', '4']) {
        assert.ok(optionValues.includes(expectedId), `Expected internal user to see room id=${expectedId}, got ${JSON.stringify(optionValues)}`);
      }

      const win = tier1Window(4, 9, 11);
      const title = `E2E-T1-F6-01-${Date.now()}`;

      await roomSelect.selectOption('3');
      await page.locator('input[name="title"]').first().fill(title);
      await page.locator('textarea[name="purpose"], input[name="purpose"]').first().fill('Internal dept sync');
      await page.locator('input[name="start_datetime"]').first().fill(win.inputStart);
      await page.locator('input[name="end_datetime"]').first().fill(win.inputEnd);
      await page.locator('input[name="attendee_count"]').first().fill('12');

      const submitBtn = page.locator('form:has(select[name="room_id"]) button[type="submit"], form:has(select[name="room_id"]) input[type="submit"]').first();
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        submitBtn.click()
      ]);

      errors.assertNoErrors('T1-F6-01 book internal room');

      const rows = await ctx.db.queryJson(
        'SELECT id, reference_code, booking_type, requester_id, requester_type, room_id, attendee_count, current_step, status FROM bookings WHERE title = ?',
        [title]
      );
      assert.equal(rows.length, 1, `Expected 1 booking row inserted for "${title}"`);
      const row = rows[0];
      registerBookingCleanup(ctx, row.id);

      assert.equal(row.booking_type, 'room');
      assert.equal(Number(row.requester_id), 6);
      assert.equal(row.requester_type, 'internal');
      assert.equal(Number(row.room_id), 3);
      assert.equal(Number(row.attendee_count), 12);
      assert.equal(Number(row.current_step), 1);
      assert.equal(row.status, 'pending_head');
      assert.ok(String(row.reference_code || '').trim().length > 0, 'Expected non-empty reference_code');
    }
  });

  test({
    id: 'T1-F6-02',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Internal user (user02) books open room (RM-101) with optional equipment (microphone, projector, audio_system)',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user02.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      const roomSelect = page.locator('select[name="room_id"]').first();
      await roomSelect.waitFor({ state: 'visible', timeout: 8000 });

      const win = tier1Window(5, 13, 16);
      const title = `E2E-T1-F6-02-${Date.now()}`;

      await roomSelect.selectOption('1');
      await page.locator('input[name="title"]').first().fill(title);
      await page.locator('textarea[name="purpose"], input[name="purpose"]').first().fill('All-hands with full AV');
      await page.locator('input[name="start_datetime"]').first().fill(win.inputStart);
      await page.locator('input[name="end_datetime"]').first().fill(win.inputEnd);
      await page.locator('input[name="attendee_count"]').first().fill('30');

      for (const eq of ['microphone', 'projector', 'audio_system']) {
        const cb = page.locator(`input[type="checkbox"][value="${eq}"]`).first();
        await cb.waitFor({ state: 'visible', timeout: 5000 });
        await cb.check();
      }

      const submitBtn = page.locator('form:has(select[name="room_id"]) button[type="submit"], form:has(select[name="room_id"]) input[type="submit"]').first();
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        submitBtn.click()
      ]);

      errors.assertNoErrors('T1-F6-02 book with equipment');

      const rows = await ctx.db.queryJson(
        'SELECT id, requester_id, room_id, status, equipment_requested FROM bookings WHERE title = ?',
        [title]
      );
      assert.equal(rows.length, 1, `Expected 1 booking row inserted for "${title}"`);
      const row = rows[0];
      registerBookingCleanup(ctx, row.id);

      assert.equal(Number(row.requester_id), 7);
      assert.equal(Number(row.room_id), 1);
      assert.equal(row.status, 'pending_head');

      const parsedEq = typeof row.equipment_requested === 'string'
        ? JSON.parse(row.equipment_requested)
        : row.equipment_requested;
      assert.ok(Array.isArray(parsedEq), `Expected equipment_requested JSON array, got ${row.equipment_requested}`);
      for (const eq of ['microphone', 'projector', 'audio_system']) {
        assert.ok(parsedEq.includes(eq), `Expected equipment "${eq}" in ${JSON.stringify(parsedEq)}`);
      }
    }
  });

  test({
    id: 'T1-F6-03',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'External user (ext01) sees only allow_external=1 rooms on book_room.php',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      const roomSelect = page.locator('select[name="room_id"]').first();
      await roomSelect.waitFor({ state: 'visible', timeout: 8000 });

      const options = await roomSelect.locator('option').evaluateAll((opts) =>
        opts.map((o) => ({ value: o.value, text: (o.textContent || '').trim() }))
      );
      const values = options.map((o) => o.value);
      const allText = options.map((o) => o.text).join(' | ');

      assert.ok(values.includes('1'), `Expected RM-101 (id=1) for external user, got ${JSON.stringify(options)}`);
      assert.ok(values.includes('2'), `Expected RM-AUD (id=2) for external user, got ${JSON.stringify(options)}`);
      assert.ok(!values.includes('3'), `External user must NOT see allow_external=0 RM-201 (id=3)`);
      assert.ok(!values.includes('4'), `External user must NOT see allow_external=0 RM-302 (id=4)`);
      assert.ok(!allText.includes('ห้องประชุมฝ่ายบริหาร'), 'External room dropdown must not list ห้องประชุมฝ่ายบริหาร');
      assert.ok(!allText.includes('ห้องประชุมปฏิบัติการ'), 'External room dropdown must not list ห้องประชุมปฏิบัติการ');

      const fileInput = page.locator('input[type="file"]').first();
      await fileInput.waitFor({ state: 'visible', timeout: 5000 });

      errors.assertNoErrors('T1-F6-03 external room filter');
    }
  });

  test({
    id: 'T1-F6-04',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'External user (ext01) books open room (RM-101) with PDF (permission_letter.pdf) -> pending_head',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      const roomSelect = page.locator('select[name="room_id"]').first();
      await roomSelect.waitFor({ state: 'visible', timeout: 8000 });

      const win = tier1Window(6, 9, 12);
      const title = `E2E-T1-F6-04-${Date.now()}`;

      await roomSelect.selectOption('1');
      await page.locator('input[name="title"]').first().fill(title);
      await page.locator('textarea[name="purpose"], input[name="purpose"]').first().fill('External seminar with PDF permission letter');
      await page.locator('input[name="start_datetime"]').first().fill(win.inputStart);
      await page.locator('input[name="end_datetime"]').first().fill(win.inputEnd);
      await page.locator('input[name="attendee_count"]').first().fill('40');
      await page.locator('input[type="file"]').first().setInputFiles(ctx.fixturePath('permission_letter.pdf'));

      const submitBtn = page.locator('form:has(select[name="room_id"]) button[type="submit"], form:has(select[name="room_id"]) input[type="submit"]').first();
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        submitBtn.click()
      ]);

      errors.assertNoErrors('T1-F6-04 external PDF booking');

      const rows = await ctx.db.queryJson(
        'SELECT id, requester_id, requester_type, room_id, current_step, status, permission_letter_path, permission_letter_original_name FROM bookings WHERE title = ?',
        [title]
      );
      assert.equal(rows.length, 1, `Expected 1 external booking row inserted for "${title}"`);
      const row = rows[0];
      registerBookingCleanup(ctx, row.id, row.permission_letter_path);

      assert.equal(Number(row.requester_id), 8);
      assert.equal(row.requester_type, 'external');
      assert.equal(Number(row.room_id), 1);
      assert.equal(Number(row.current_step), 1);
      assert.equal(row.status, 'pending_head');
      assert.match(String(row.permission_letter_path || ''), /^uploads\/permissions\/[a-f0-9]{32}\.pdf$/i);
      assert.equal(row.permission_letter_original_name, 'permission_letter.pdf');

      const diskPath = path.join(ctx.env.projectRoot, row.permission_letter_path);
      assert.ok(fs.existsSync(diskPath), `Expected uploaded PDF on disk at ${diskPath}`);
      assert.ok(fs.statSync(diskPath).size > 0, 'Expected uploaded PDF size > 0');
    }
  });

  test({
    id: 'T1-F6-05',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'External user (ext01) books open room with PNG & JPG and downloads via download.php',
    fn: async (ctx) => {
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const headClient = await ctx.http.login(ctx.env.seedUsers.head01.username, ctx.env.seedPassword);
      const uniq = Date.now();

      const cases = [
        {
          fixture: 'sample.png',
          expectedExt: 'png',
          expectedMime: 'image/png',
          win: tier1Window(7, 9, 11),
          title: `E2E-T1-F6-05-PNG-${uniq}`,
          attendees: '80'
        },
        {
          fixture: 'sample.jpg',
          expectedExt: 'jpg',
          expectedMime: 'image/jpeg',
          win: tier1Window(8, 9, 11),
          title: `E2E-T1-F6-05-JPG-${uniq}`,
          attendees: '90'
        }
      ];

      for (const tc of cases) {
        const formHtml = (await extClient.get('/book_room.php')).text;
        const formMeta = extractBookingFormMeta(formHtml);
        assert.ok(formMeta.csrf.token, 'Expected CSRF token on book_room.php');

        const fields = {
          [formMeta.csrf.fieldName]: formMeta.csrf.token,
          _csrf_token: formMeta.csrf.token,
          room_id: '2',
          title: tc.title,
          purpose: `External event upload ${tc.fixture}`,
          start_datetime: tc.win.start,
          end_datetime: tc.win.end,
          attendee_count: tc.attendees
        };

        const postRes = await extClient.postMultipart(
          formMeta.action,
          fields,
          { [formMeta.fileFieldName]: ctx.fixturePath(tc.fixture) }
        );
        assert.ok(postRes.status < 400, `Expected <400 on booking with ${tc.fixture}, got ${postRes.status}`);

        const rows = await ctx.db.queryJson(
          'SELECT id, status, permission_letter_path, permission_letter_original_name FROM bookings WHERE title = ?',
          [tc.title]
        );
        assert.equal(rows.length, 1, `Expected 1 booking row for ${tc.fixture}`);
        const row = rows[0];
        registerBookingCleanup(ctx, row.id, row.permission_letter_path);

        assert.equal(row.status, 'pending_head');
        assert.match(
          String(row.permission_letter_path || ''),
          new RegExp(`^uploads/permissions/[a-f0-9]{32}\\.${tc.expectedExt}$`, 'i')
        );

        const expectedBytes = fs.readFileSync(ctx.fixturePath(tc.fixture));

        for (const [roleLabel, downloader] of [['ext01', extClient], ['head01', headClient]]) {
          const dlRes = await downloader.get(`/download.php?type=permission&booking_id=${row.id}&download=1`);
          assert.equal(dlRes.status, 200, `Expected HTTP 200 downloading ${tc.fixture} as ${roleLabel}, got ${dlRes.status}`);
          const ctype = (dlRes.headers.get('content-type') || '').toLowerCase();
          assert.ok(ctype.includes(tc.expectedMime), `Expected content-type ${tc.expectedMime}, got "${ctype}"`);
          const disp = (dlRes.headers.get('content-disposition') || '').toLowerCase();
          assert.ok(disp.includes('attachment'), `Expected attachment disposition, got "${disp}"`);
          assert.ok(disp.includes(tc.fixture), `Expected filename ${tc.fixture} in disposition "${disp}"`);
          assert.equal(
            Buffer.compare(dlRes.bodyBuffer, expectedBytes),
            0,
            `Downloaded bytes for ${tc.fixture} (${roleLabel}) did not match fixture`
          );
        }
      }
    }
  });

  test({
    id: 'T1-F6-06',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Direct access to uploads/ directory is blocked (403)',
    fn: async (ctx) => {
      const guest = ctx.http.createClient();
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);

      for (const [label, client] of [['guest', guest], ['ext01', extClient]]) {
        for (const target of ['/uploads/', '/uploads/index.php']) {
          const res = await client.get(target, { redirect: 'manual' });
          assert.equal(res.status, 403, `Expected HTTP 403 for ${label} GET ${target}, got ${res.status}`);
          assert.ok(
            !/Index of \/uploads/i.test(res.text),
            `Directory index must never be exposed for ${target}`
          );
        }
      }
    }
  });
}
