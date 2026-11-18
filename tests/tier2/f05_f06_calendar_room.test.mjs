import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { extractCsrf, extractFormInfo } from '../lib/http.mjs';

const TIER = 2;
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

// Fixed non-colliding Tier 2 future window: 2027-05-01..2027-05-28
function tier2Window(day, startHour = 9, endHour = 11) {
  if (day < 1 || day > 28) {
    throw new Error(`Tier 2 day out of 1..28 range: ${day}`);
  }
  const date = `2027-05-${pad2(day)}`;
  const nextDay = `2027-05-${pad2(day + 1)}`;
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

  const std = extractCsrf(html);
  if (std) {
    return { fieldName: '_csrf_token', token: std };
  }

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

  let fileFieldName = 'permission_letter';
  if (matchedBody) {
    const fileMatch = matchedBody.match(
      /<input\b[^>]*type\s*=\s*["']file["'][^>]*name\s*=\s*["']([^"']+)["'][^>]*>/i
    );
    if (fileMatch && fileMatch[1]) {
      fileFieldName = fileMatch[1];
    }
  }

  return { action: matchedAction, csrf, fileFieldName };
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

function findSeededBookingEvent(events, { bookingId, referenceCode, titleSubstr, dayOfMonth, expectedColor }) {
  const byIdOrTitle = events.find((ev) => {
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
  });
  if (byIdOrTitle) return byIdOrTitle;

  if (dayOfMonth) {
    const { year, month } = bangkokParts();
    const dayPrefix = `${year}-${pad2(month)}-${pad2(dayOfMonth)}`;
    return events.find((ev) => {
      const startMatch = String(ev?.start || '').startsWith(dayPrefix);
      const colorMatch = !expectedColor || eventColor(ev) === expectedColor;
      return startMatch && colorMatch;
    }) || null;
  }
  return null;
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

function listPermUploads(ctx) {
  const dir = path.join(ctx.env.projectRoot, 'uploads', 'permissions');
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter((f) => f !== '.gitkeep' && f !== 'index.php');
}

function assertRejectedNoRow(res, contextLabel) {
  assert.ok(res.status < 500, `${contextLabel}: must not return HTTP 500 (got ${res.status})`);
  if (res.status >= 400) {
    return;
  }
  if (res.status >= 300 && res.status < 400) {
    const loc = String(res.location || '');
    assert.ok(
      loc.includes('book_room.php') || loc.includes('error'),
      `${contextLabel}: unexpected success-like redirect to "${loc}"`
    );
    return;
  }
  const hasSuccessBanner = /\balert-success\b/i.test(res.text || '');
  assert.ok(!hasSuccessBanner, `${contextLabel}: response must not show alert-success`);
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
  // F5 — Master Calendar & PII Masking (Tier 2: T2-F5-01 .. T2-F5-06)
  // =========================================================================

  test({
    id: 'T2-F5-01',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Non-owner (user02), external (ext01), and guest see masked PII on user01 bookings',
    fn: async (ctx) => {
      const { start, end } = currentMonthRange();
      const url = `/api/calendar_events.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;

      const user02Client = await ctx.http.login(ctx.env.seedUsers.user02.username, ctx.env.seedPassword);
      const ext01Client = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const guestClient = ctx.http.createClient();

      const sensitiveTokens = [
        'กมลชนก',
        '081-111-0001',
        'งบประมาณประจำเดือน',
        'ส่งเอกสารราชการ',
        'ศูนย์ราชการ',
        'กระทรวงศึกษาธิการ'
      ];

      for (const [label, client] of [['user02', user02Client], ['ext01', ext01Client], ['guest', guestClient]]) {
        const res = await client.get(url, { headers: { Accept: 'application/json' } });
        assert.equal(res.status, 200, `Expected HTTP 200 for ${label}, got ${res.status}`);
        const events = normalizeEvents(res.json());
        assert.ok(Array.isArray(events), `Expected JSON event array for ${label}`);

        const bk1 = findSeededBookingEvent(events, {
          bookingId: 1,
          referenceCode: 'BK-001',
          titleSubstr: 'ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน',
          dayOfMonth: 10,
          expectedColor: '#2563EB'
        });
        const bk3 = findSeededBookingEvent(events, {
          bookingId: 3,
          referenceCode: 'BK-003',
          titleSubstr: 'เดินทางไปประสานงานเอกสาร ณ ศูนย์ราชการ',
          dayOfMonth: 15,
          expectedColor: '#059669'
        });
        const bk5 = findSeededBookingEvent(events, {
          bookingId: 5,
          referenceCode: 'BK-005',
          titleSubstr: 'เดินทางเข้าร่วมประชุมกระทรวงศึกษาธิการ',
          dayOfMonth: 20,
          expectedColor: '#059669'
        });

        for (const [bkCode, ev] of [['BK-001', bk1], ['BK-003', bk3], ['BK-005', bk5]]) {
          assert.ok(ev, `Expected ${bkCode} event present (masked) for ${label}`);
          assert.equal(ev.extendedProps?.is_masked, true, `Expected ${bkCode}.extendedProps.is_masked === true for ${label}`);

          const serialized = JSON.stringify(ev);
          for (const secret of sensitiveTokens) {
            assert.ok(
              !serialized.includes(secret),
              `PII leak for ${label} on ${bkCode}: found "${secret}" in ${serialized}`
            );
          }

          const props = ev.extendedProps || {};
          for (const field of ['purpose', 'requester_contact', 'destination']) {
            if (props[field] !== undefined && props[field] !== null && props[field] !== '') {
              assert.ok(
                !/กมลชนก|งบประมาณ|ส่งเอกสาร|ศูนย์ราชการ|กระทรวง|081-111/i.test(String(props[field])),
                `Expected ${bkCode}.extendedProps.${field} masked for ${label}, got "${props[field]}"`
              );
            }
          }
        }
      }

      // Ownership sanity: user02 sees own BK-004 unmasked; ext01 sees own BK-002 unmasked
      const u2Events = normalizeEvents((await user02Client.get(url, { headers: { Accept: 'application/json' } })).json());
      const bk4 = findSeededBookingEvent(u2Events, {
        bookingId: 4,
        referenceCode: 'BK-004',
        titleSubstr: 'ประชุมกลั่นกรองนโยบายและยุทธศาสตร์',
        dayOfMonth: 18,
        expectedColor: '#2563EB'
      });
      assert.ok(bk4, 'Expected user02 to see own BK-004');
      assert.notEqual(bk4.extendedProps?.is_masked, true, 'Expected user02 own BK-004 to be unmasked');
      assert.ok(JSON.stringify(bk4).includes('ธนพล'), 'Expected user02 own name visible on BK-004');

      const extEvents = normalizeEvents((await ext01Client.get(url, { headers: { Accept: 'application/json' } })).json());
      const bk2 = findSeededBookingEvent(extEvents, {
        bookingId: 2,
        referenceCode: 'BK-002',
        titleSubstr: 'อบรมเชิงปฏิบัติการเครือข่ายการศึกษา',
        dayOfMonth: 12,
        expectedColor: '#2563EB'
      });
      assert.ok(bk2, 'Expected ext01 to see own BK-002');
      assert.notEqual(bk2.extendedProps?.is_masked, true, 'Expected ext01 own BK-002 to be unmasked');
      assert.ok(JSON.stringify(bk2).includes('อรอนงค์'), 'Expected ext01 own name visible on BK-002');
    }
  });

  test({
    id: 'T2-F5-02',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'rejected bookings (BK-006) are completely omitted from api/calendar_events.php across all roles',
    fn: async (ctx) => {
      const { start, end } = currentMonthRange();
      const url = `/api/calendar_events.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;

      const clients = [
        ['guest', ctx.http.createClient()],
        ['ext01', await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword)],
        ['user02', await ctx.http.login(ctx.env.seedUsers.user02.username, ctx.env.seedPassword)],
        ['head01', await ctx.http.login(ctx.env.seedUsers.head01.username, ctx.env.seedPassword)],
        ['admin', await ctx.http.login(ctx.env.seedUsers.admin.username, ctx.env.seedPassword)]
      ];

      for (const [label, client] of clients) {
        const res = await client.get(url, { headers: { Accept: 'application/json' } });
        assert.equal(res.status, 200, `Expected HTTP 200 for ${label}`);
        const events = normalizeEvents(res.json());
        assert.ok(Array.isArray(events));
        const leaked = events.filter((ev) => {
          const s = JSON.stringify(ev);
          return (
            ev?.extendedProps?.booking_id == 6 ||
            ev?.extendedProps?.reference_code === 'BK-006' ||
            s.includes('BK-006') ||
            s.includes('กิจกรรมซ้อมนำเสนอผลงานนอกเวลา')
          );
        });
        assert.equal(leaked.length, 0, `Rejected booking BK-006 must be omitted for ${label}, found ${JSON.stringify(leaked)}`);
      }
    }
  });

  test({
    id: 'T2-F5-03',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Non-privileged users (user01, ext01, guest) blocked from api/calendar_event_save.php; missing/invalid CSRF rejected (403)',
    fn: async (ctx) => {
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events'));
      const win = tier2Window(1, 10, 12);

      // user01 & ext01 blocked 403 even with valid CSRF
      for (const username of ['user01', 'ext01']) {
        const client = await ctx.http.login(username, ctx.env.seedPassword);
        const calHtml = (await client.get('/calendar.php')).text;
        const csrf = extractCsrfPair(calHtml, 'calendar_event');
        assert.ok(csrf.token, `Expected CSRF token for ${username}`);

        const { res: createRes } = await saveCalendarEvent(client, csrf, {
          action: 'create',
          title: `Unauthorized-${username}-${Date.now()}`,
          event_type: 'general',
          start_datetime: win.start,
          end_datetime: win.end
        });
        assert.equal(createRes.status, 403, `Expected 403 for ${username} creating calendar_event, got ${createRes.status}`);

        const { res: delRes } = await saveCalendarEvent(client, csrf, {
          action: 'delete',
          id: 1
        });
        assert.equal(delRes.status, 403, `Expected 403 for ${username} deleting seeded event id=1, got ${delRes.status}`);
      }

      // Guest unauthenticated blocked
      const guest = ctx.http.createClient();
      const guestRes = await guest.postJson(
        '/api/calendar_event_save.php',
        { action: 'create', title: 'GuestAttempt', start_datetime: win.start, end_datetime: win.end },
        { redirect: 'manual', headers: { Accept: 'application/json' } }
      );
      assert.ok(
        [401, 403].includes(guestRes.status) || (guestRes.status === 302 && String(guestRes.location || '').includes('login.php')),
        `Expected 401/403/302-to-login for guest on api/calendar_event_save.php, got ${guestRes.status}`
      );

      // Admin without CSRF and with invalid CSRF -> 403
      const adminClient = await ctx.http.login(ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
      const noCsrfRes = await adminClient.postForm(
        '/api/calendar_event_save.php',
        { action: 'create', title: 'NoCsrf', start_datetime: win.start, end_datetime: win.end },
        { headers: { Accept: 'application/json' } }
      );
      assert.equal(noCsrfRes.status, 403, `Expected 403 when CSRF token missing, got ${noCsrfRes.status}`);

      const badCsrfRes = await adminClient.postForm(
        '/api/calendar_event_save.php',
        { _csrf_token: 'deadbeef', csrf_token: 'deadbeef', action: 'create', title: 'BadCsrf', start_datetime: win.start, end_datetime: win.end },
        { headers: { 'X-CSRF-Token': 'deadbeef', Accept: 'application/json' } }
      );
      assert.equal(badCsrfRes.status, 403, `Expected 403 when CSRF token invalid, got ${badCsrfRes.status}`);

      const afterCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events'));
      assert.equal(afterCount, beforeCount, 'Expected calendar_events count unchanged');
      const seededOne = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE id = 1'));
      assert.equal(seededOne, 1, 'Expected seeded calendar_event id=1 intact');
    }
  });

  test({
    id: 'T2-F5-04',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Mobile 375x812 calendar.php renders FullCalendar without horizontal overflow and modal opens cleanly',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);

      await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      await page.locator('.fc').first().waitFor({ state: 'visible', timeout: 10000 });
      await page.locator('.fc-event').first().waitFor({ state: 'visible', timeout: 10000 });

      const layout = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth
      }));
      assert.ok(
        layout.scrollWidth <= layout.clientWidth + 2,
        `Expected no horizontal overflow on 375px mobile: scrollWidth=${layout.scrollWidth}, clientWidth=${layout.clientWidth}`
      );

      const toolbarBox = await page.locator('.fc-toolbar').first().boundingBox();
      assert.ok(toolbarBox && toolbarBox.width <= 376, `Expected .fc-toolbar within 375px viewport, got ${JSON.stringify(toolbarBox)}`);

      // Switch to week/day and open an event modal
      const dayBtn = page.locator('.fc-timeGridDay-button, button:has-text("วัน"), button:has-text("Day")').first();
      if ((await dayBtn.count()) > 0) {
        await dayBtn.click();
        await page.locator('.fc-timeGridDay-view, .fc-timegrid').first().waitFor({ state: 'visible', timeout: 8000 });
      }
      const monthBtn = page.locator('.fc-dayGridMonth-button, button:has-text("เดือน"), button:has-text("Month")').first();
      if ((await monthBtn.count()) > 0) {
        await monthBtn.click();
        await page.locator('.fc-event').first().waitFor({ state: 'visible', timeout: 8000 });
      }

      await page.locator('.fc-event').first().click();
      const modal = page.locator('.modal.show, [data-testid="calendar-event-modal"].show, [role="dialog"]:visible').first();
      await modal.waitFor({ state: 'visible', timeout: 8000 });
      const modalBox = await modal.locator('.modal-dialog, .modal-content').first().boundingBox().catch(() => modal.boundingBox());
      assert.ok(modalBox && modalBox.width <= 376, `Expected mobile modal width <= 375, got ${JSON.stringify(modalBox)}`);

      errors.assertNoErrors('T2-F5-04 mobile calendar');
    }
  });

  test({
    id: 'T2-F5-05',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Invalid, inverted, and SQLi date range queries on api/calendar_events.php handled safely',
    fn: async (ctx) => {
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);

      const probes = [
        { label: 'non-date', url: '/api/calendar_events.php?start=not-a-date&end=still-invalid' },
        { label: 'inverted', url: '/api/calendar_events.php?start=2027-12-31&end=2027-01-01', expectEmpty: true },
        {
          label: 'sqli',
          url: `/api/calendar_events.php?start=${encodeURIComponent("2026-01-01' OR 1=1--")}&end=2026-02-01`
        },
        { label: 'no-params', url: '/api/calendar_events.php' }
      ];

      for (const probe of probes) {
        const res = await client.get(probe.url, { headers: { Accept: 'application/json' } });
        assert.ok([200, 400].includes(res.status), `Probe "${probe.label}" expected 200 or 400, got ${res.status}`);
        assert.ok(
          !/Fatal error|Warning|SQLSTATE|Stack trace/i.test(res.text),
          `Probe "${probe.label}" leaked PHP/SQL error text: ${res.text.slice(0, 300)}`
        );
        const body = res.json();
        if (probe.expectEmpty && res.status === 200) {
          const evs = normalizeEvents(body);
          assert.ok(Array.isArray(evs) && evs.length === 0, `Expected 0 events for inverted range, got ${JSON.stringify(evs)}`);
        }
      }
    }
  });

  test({
    id: 'T2-F5-06',
    tier: TIER,
    feature: FEATURE_F5,
    name: 'Private calendar_events (is_public=0) hidden from guest/ext01, and invalid event payload rejected',
    fn: async (ctx) => {
      const adminClient = await ctx.http.login(ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const guestClient = ctx.http.createClient();

      const calHtml = (await adminClient.get('/calendar.php')).text;
      const csrf = extractCsrfPair(calHtml, 'calendar_event');
      assert.ok(csrf.token, 'Expected CSRF token on calendar.php');

      const win = tier2Window(2, 10, 11);
      const privateTitle = `E2E-T2-F5-PRIVATE-${Date.now()}`;

      const { res: createRes, mode } = await saveCalendarEvent(adminClient, csrf, {
        action: 'create',
        title: privateTitle,
        event_type: 'meeting',
        description: 'Internal-only secret agenda',
        location: 'Executive Room',
        start_datetime: win.start,
        end_datetime: win.end,
        is_all_day: 0,
        is_public: 0
      });
      assert.ok([200, 201].includes(createRes.status), `Expected 200/201 creating private event, got ${createRes.status}`);

      const rows = await ctx.db.queryJson(
        'SELECT id, is_public FROM calendar_events WHERE title = ?',
        [privateTitle]
      );
      assert.equal(rows.length, 1);
      assert.equal(Number(rows[0].is_public), 0);
      registerEventCleanup(ctx, rows[0].id);

      const feedUrl = `/api/calendar_events.php?start=${encodeURIComponent(win.date)}&end=${encodeURIComponent(win.nextDay)}`;

      const adminFeed = normalizeEvents((await adminClient.get(feedUrl, { headers: { Accept: 'application/json' } })).json());
      assert.ok(
        adminFeed.some((e) => String(e.title || '').includes(privateTitle)),
        'Expected admin to see private calendar_event'
      );

      for (const [label, client] of [['ext01', extClient], ['guest', guestClient]]) {
        const feed = normalizeEvents((await client.get(feedUrl, { headers: { Accept: 'application/json' } })).json());
        assert.ok(
          !feed.some((e) => String(e.title || '').includes(privateTitle)),
          `Private calendar_event (is_public=0) must be hidden from ${label}`
        );
      }

      // Invalid payloads rejected without inserting rows
      const countBeforeInvalid = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events'));

      const { res: emptyTitleRes } = await saveCalendarEvent(
        adminClient,
        csrf,
        {
          action: 'create',
          title: '',
          event_type: 'general',
          start_datetime: '2027-05-03 09:00:00',
          end_datetime: '2027-05-03 10:00:00',
          is_public: 1
        },
        mode
      );
      assert.ok(
        [400, 422].includes(emptyTitleRes.status) || (emptyTitleRes.status === 200 && emptyTitleRes.json()?.ok === false),
        `Expected rejection on empty event title, got ${emptyTitleRes.status}: ${emptyTitleRes.text}`
      );

      const { res: invertedRes } = await saveCalendarEvent(
        adminClient,
        csrf,
        {
          action: 'create',
          title: 'Invalid-Inverted-Range',
          event_type: 'general',
          start_datetime: '2027-05-03 11:00:00',
          end_datetime: '2027-05-03 09:00:00',
          is_public: 1
        },
        mode
      );
      assert.ok(
        [400, 422].includes(invertedRes.status) || (invertedRes.status === 200 && invertedRes.json()?.ok === false),
        `Expected rejection on end_datetime <= start_datetime, got ${invertedRes.status}: ${invertedRes.text}`
      );

      const countAfterInvalid = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events'));
      assert.equal(countAfterInvalid, countBeforeInvalid, 'Expected 0 rows inserted for invalid event payloads');
    }
  });

  // =========================================================================
  // F6 — Room Booking (Internal & External) (Tier 2: T2-F6-01 .. T2-F6-06)
  // =========================================================================

  test({
    id: 'T2-F6-01',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'External user (ext01) attempting to book allow_external=0 rooms (RM-201, RM-302) is rejected',
    fn: async (ctx) => {
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      const beforeFiles = new Set(listPermUploads(ctx));

      ctx.onCleanup(() => {
        for (const f of listPermUploads(ctx)) {
          if (!beforeFiles.has(f)) {
            fs.unlinkSync(path.join(ctx.env.projectRoot, 'uploads', 'permissions', f));
          }
        }
      });

      const uniq = Date.now();
      for (const [roomId, day] of [['3', 4], ['4', 5]]) {
        const html = (await extClient.get('/book_room.php')).text;
        const formMeta = extractBookingFormMeta(html);
        const win = tier2Window(day, 9, 11);
        const title = `E2E-T2-F6-01-R${roomId}-${uniq}`;

        const res = await extClient.postMultipart(
          formMeta.action,
          {
            [formMeta.csrf.fieldName]: formMeta.csrf.token,
            _csrf_token: formMeta.csrf.token,
            room_id: roomId,
            title,
            purpose: 'External forbidden room attempt',
            start_datetime: win.start,
            end_datetime: win.end,
            attendee_count: '10'
          },
          { [formMeta.fileFieldName]: ctx.fixturePath('permission_letter.pdf') },
          { redirect: 'manual' }
        );
        assertRejectedNoRow(res, `ext01 booking allow_external=0 room_id=${roomId}`);
      }

      const afterCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(afterCount, beforeCount, 'Expected 0 bookings inserted when external books allow_external=0 rooms');

      const newFiles = listPermUploads(ctx).filter((f) => !beforeFiles.has(f));
      assert.equal(newFiles.length, 0, `Expected 0 orphan permission files on rejected external booking, found ${JSON.stringify(newFiles)}`);
    }
  });

  test({
    id: 'T2-F6-02',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'External user (ext01) booking open room (RM-101) without permission letter is rejected',
    fn: async (ctx) => {
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));

      const html = (await extClient.get('/book_room.php')).text;
      const formMeta = extractBookingFormMeta(html);
      const win = tier2Window(6, 9, 11);

      const res = await extClient.postMultipart(
        formMeta.action,
        {
          [formMeta.csrf.fieldName]: formMeta.csrf.token,
          _csrf_token: formMeta.csrf.token,
          room_id: '1',
          title: `E2E-T2-F6-02-NOFILE-${Date.now()}`,
          purpose: 'External missing letter',
          start_datetime: win.start,
          end_datetime: win.end,
          attendee_count: '20'
        },
        {},
        { redirect: 'manual' }
      );
      assertRejectedNoRow(res, 'ext01 booking without permission_letter');

      const afterCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(afterCount, beforeCount, 'Expected 0 bookings inserted without permission letter');
    }
  });

  test({
    id: 'T2-F6-03',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Malicious/invalid file uploads (bad_mime.txt, disguised_shell.php, disguised_image.png, oversized_dummy.jpg) rejected',
    fn: async (ctx) => {
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      const beforeFiles = new Set(listPermUploads(ctx));

      ctx.onCleanup(() => {
        for (const f of listPermUploads(ctx)) {
          if (!beforeFiles.has(f)) {
            fs.unlinkSync(path.join(ctx.env.projectRoot, 'uploads', 'permissions', f));
          }
        }
      });

      const badFixtures = [
        'bad_mime.txt',
        'disguised_shell.php',
        'disguised_image.png',
        'oversized_dummy.jpg'
      ];

      for (let i = 0; i < badFixtures.length; i++) {
        const fixture = badFixtures[i];
        const html = (await extClient.get('/book_room.php')).text;
        const formMeta = extractBookingFormMeta(html);
        const win = tier2Window(7 + i, 9, 11);

        const res = await extClient.postMultipart(
          formMeta.action,
          {
            [formMeta.csrf.fieldName]: formMeta.csrf.token,
            _csrf_token: formMeta.csrf.token,
            room_id: '1',
            title: `E2E-T2-F6-03-${fixture}-${Date.now()}`,
            purpose: `Bad upload test ${fixture}`,
            start_datetime: win.start,
            end_datetime: win.end,
            attendee_count: '15'
          },
          { [formMeta.fileFieldName]: ctx.fixturePath(fixture) },
          { redirect: 'manual' }
        );

        assertRejectedNoRow(res, `upload rejection for ${fixture}`);
      }

      const afterCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(afterCount, beforeCount, 'Expected 0 bookings inserted for invalid/malicious uploads');

      const newFiles = listPermUploads(ctx).filter((f) => !beforeFiles.has(f));
      assert.equal(newFiles.length, 0, `Expected 0 new files in uploads/permissions, found ${JSON.stringify(newFiles)}`);
    }
  });

  test({
    id: 'T2-F6-04',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Invalid time range (end <= start) & attendee_count (<=0, >capacity) rejected; exact boundary == capacity accepted',
    fn: async (ctx) => {
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      const uniq = Date.now();

      // RM-201 (room_id=3) has capacity=20
      const invalidCases = [
        { label: 'end < start', day: 11, start: '2027-05-11 14:00:00', end: '2027-05-11 12:00:00', attendees: '5' },
        { label: 'end == start', day: 12, start: '2027-05-12 10:00:00', end: '2027-05-12 10:00:00', attendees: '5' },
        { label: 'attendees == 0', day: 13, start: '2027-05-13 09:00:00', end: '2027-05-13 11:00:00', attendees: '0' },
        { label: 'attendees == -3', day: 14, start: '2027-05-14 09:00:00', end: '2027-05-14 11:00:00', attendees: '-3' },
        { label: 'attendees > capacity (21)', day: 15, start: '2027-05-15 09:00:00', end: '2027-05-15 11:00:00', attendees: '21' }
      ];

      for (const ic of invalidCases) {
        const html = (await client.get('/book_room.php')).text;
        const formMeta = extractBookingFormMeta(html);
        const res = await client.postForm(
          formMeta.action,
          {
            [formMeta.csrf.fieldName]: formMeta.csrf.token,
            _csrf_token: formMeta.csrf.token,
            room_id: '3',
            title: `E2E-T2-F6-04-INV-${ic.day}-${uniq}`,
            purpose: `Invalid case ${ic.label}`,
            start_datetime: ic.start,
            end_datetime: ic.end,
            attendee_count: ic.attendees
          },
          { redirect: 'manual' }
        );
        assertRejectedNoRow(res, `book_room invalid case (${ic.label})`);
      }

      const countAfterInvalid = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(countAfterInvalid, beforeCount, 'Expected 0 bookings inserted across all 5 invalid cases');

      // Exact boundary: attendee_count == 20 (== RM-201.capacity) on 2027-05-16
      const winValid = tier2Window(16, 9, 11);
      const validTitle = `E2E-T2-F6-04-BOUNDARY20-${uniq}`;
      const htmlValid = (await client.get('/book_room.php')).text;
      const formMetaValid = extractBookingFormMeta(htmlValid);
      const validRes = await client.postForm(formMetaValid.action, {
        [formMetaValid.csrf.fieldName]: formMetaValid.csrf.token,
        _csrf_token: formMetaValid.csrf.token,
        room_id: '3',
        title: validTitle,
        purpose: 'Exact capacity boundary 20',
        start_datetime: winValid.start,
        end_datetime: winValid.end,
        attendee_count: '20'
      });
      assert.ok(validRes.status < 400, `Expected <400 for exact capacity boundary, got ${validRes.status}`);

      const rows = await ctx.db.queryJson(
        'SELECT id, attendee_count, status FROM bookings WHERE title = ?',
        [validTitle]
      );
      assert.equal(rows.length, 1, 'Expected boundary attendee_count=20 booking to succeed');
      registerBookingCleanup(ctx, rows[0].id);
      assert.equal(Number(rows[0].attendee_count), 20);
      assert.equal(rows[0].status, 'pending_head');
    }
  });

  test({
    id: 'T2-F6-05',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Missing or invalid CSRF token on POST /book_room.php rejected with HTTP 403',
    fn: async (ctx) => {
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));

      const userClient = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const win1 = tier2Window(17, 9, 11);
      const noCsrfRes = await userClient.postForm(
        '/book_room.php',
        {
          room_id: '3',
          title: `E2E-T2-F6-05-NOCSRF-${Date.now()}`,
          purpose: 'Missing CSRF',
          start_datetime: win1.start,
          end_datetime: win1.end,
          attendee_count: '5'
        },
        { redirect: 'manual' }
      );
      assert.equal(noCsrfRes.status, 403, `Expected HTTP 403 on missing CSRF token, got ${noCsrfRes.status}`);

      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const win2 = tier2Window(18, 9, 11);
      const badCsrfRes = await extClient.postMultipart(
        '/book_room.php',
        {
          _csrf_token: 'invalid_token_12345',
          csrf_token: 'invalid_token_12345',
          room_id: '1',
          title: `E2E-T2-F6-05-BADCSRF-${Date.now()}`,
          purpose: 'Invalid CSRF',
          start_datetime: win2.start,
          end_datetime: win2.end,
          attendee_count: '10'
        },
        { permission_letter: ctx.fixturePath('permission_letter.pdf') },
        { redirect: 'manual' }
      );
      assert.equal(badCsrfRes.status, 403, `Expected HTTP 403 on invalid CSRF token, got ${badCsrfRes.status}`);

      const afterCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(afterCount, beforeCount, 'Expected 0 bookings inserted on CSRF failures');
    }
  });

  test({
    id: 'T2-F6-06',
    tier: TIER,
    feature: FEATURE_F6,
    name: 'Non-existent room_id handled safely & download.php blocks IDOR (403)',
    fn: async (ctx) => {
      const user01Client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const beforeCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));

      const html1 = (await user01Client.get('/book_room.php')).text;
      const formMeta1 = extractBookingFormMeta(html1);
      const win1 = tier2Window(19, 9, 11);
      const badRoomRes = await user01Client.postForm(
        formMeta1.action,
        {
          [formMeta1.csrf.fieldName]: formMeta1.csrf.token,
          _csrf_token: formMeta1.csrf.token,
          room_id: '99999',
          title: `E2E-T2-F6-06-BADROOM-${Date.now()}`,
          purpose: 'Non-existent room_id',
          start_datetime: win1.start,
          end_datetime: win1.end,
          attendee_count: '5'
        },
        { redirect: 'manual' }
      );
      assertRejectedNoRow(badRoomRes, 'non-existent room_id=99999');

      const countAfterBadRoom = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      assert.equal(countAfterBadRoom, beforeCount, 'Expected 0 bookings inserted for room_id=99999');

      // Create valid external booking with permission_letter.pdf on 2027-05-20
      const extClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const htmlExt = (await extClient.get('/book_room.php')).text;
      const formMetaExt = extractBookingFormMeta(htmlExt);
      const win2 = tier2Window(20, 9, 11);
      const validTitle = `E2E-T2-F6-06-IDOR-${Date.now()}`;

      const okRes = await extClient.postMultipart(
        formMetaExt.action,
        {
          [formMetaExt.csrf.fieldName]: formMetaExt.csrf.token,
          _csrf_token: formMetaExt.csrf.token,
          room_id: '1',
          title: validTitle,
          purpose: 'IDOR check booking',
          start_datetime: win2.start,
          end_datetime: win2.end,
          attendee_count: '25'
        },
        { [formMetaExt.fileFieldName]: ctx.fixturePath('permission_letter.pdf') }
      );
      assert.ok(okRes.status < 400, `Expected <400 creating ext01 booking for IDOR test, got ${okRes.status}`);

      const rows = await ctx.db.queryJson(
        'SELECT id, permission_letter_path FROM bookings WHERE title = ?',
        [validTitle]
      );
      assert.equal(rows.length, 1, 'Expected ext01 booking created for IDOR test');
      const bookingId = rows[0].id;
      registerBookingCleanup(ctx, bookingId, rows[0].permission_letter_path);

      // user02 (non-owner regular user) blocked with 403
      const user02Client = await ctx.http.login(ctx.env.seedUsers.user02.username, ctx.env.seedPassword);
      const idorRes = await user02Client.get(`/download.php?type=permission&booking_id=${bookingId}`, { redirect: 'manual' });
      assert.equal(idorRes.status, 403, `Expected HTTP 403 IDOR block for user02 on download.php, got ${idorRes.status}`);

      // Unauthenticated guest blocked (302 to login / 401 / 403)
      const guestClient = ctx.http.createClient();
      const guestRes = await guestClient.get(`/download.php?type=permission&booking_id=${bookingId}`, { redirect: 'manual' });
      assert.ok(
        [401, 403].includes(guestRes.status) || (guestRes.status === 302 && String(guestRes.location || '').includes('login.php')),
        `Expected guest download.php to redirect to login or return 401/403, got ${guestRes.status} (${guestRes.location})`
      );
    }
  });
}
