import { extractCsrf, extractFormInfo } from '../lib/http.mjs';

const CSRF_FIELD_CANDIDATES = ['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token'];

function decodeEntities(str) {
  return String(str || '')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#0*39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>');
}

function parseTagAttrs(attrText) {
  const attrs = {};
  const re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let m;
  while ((m = re.exec(attrText || '')) !== null) {
    const key = m[1].toLowerCase();
    const val = m[2] ?? m[3] ?? m[4] ?? '';
    attrs[key] = decodeEntities(val);
  }
  return attrs;
}

function parseAllForms(html) {
  const forms = [];
  const formRe = /<form\b([^>]*)>([\s\S]*?)<\/form>/gi;
  let fm;
  while ((fm = formRe.exec(String(html || ''))) !== null) {
    const attrs = parseTagAttrs(fm[1]);
    const body = fm[2];
    const inputs = [];
    const tagRe = /<(input|select|textarea|button)\b([^>]*)>/gi;
    let tm;
    while ((tm = tagRe.exec(body)) !== null) {
      const tag = tm[1].toLowerCase();
      const iAttrs = parseTagAttrs(tm[2]);
      if (!iAttrs.name) continue;
      inputs.push({
        tag,
        type: (iAttrs.type || (tag === 'input' ? 'text' : tag)).toLowerCase(),
        name: iAttrs.name,
        value: iAttrs.value ?? ''
      });
    }
    forms.push({
      action: attrs.action ?? '',
      method: (attrs.method || 'GET').toUpperCase(),
      attrs,
      inputs,
      body
    });
  }
  return forms;
}

function isCsrfFieldName(name) {
  return CSRF_FIELD_CANDIDATES.includes(String(name || '').toLowerCase());
}

function selectTargetForm(html, actionHint = null, expectedFields = []) {
  const forms = parseAllForms(html);
  const nonLogoutForms = forms.filter((f) => !f.action.toLowerCase().includes('logout.php'));
  const pool = nonLogoutForms.length > 0 ? nonLogoutForms : forms;

  if (expectedFields.length > 0) {
    const lowered = expectedFields.map((x) => x.toLowerCase());
    const byField = pool.find((f) =>
      f.inputs.some((inp) => lowered.includes(inp.name.toLowerCase()))
    );
    if (byField) return byField;
  }

  if (actionHint) {
    const hint = String(actionHint).toLowerCase();
    const byHint = pool.find((f) => f.action.toLowerCase().includes(hint));
    if (byHint) return byHint;
  }

  if (pool.length > 0) return pool[0];

  const fallback = extractFormInfo(html, actionHint);
  return {
    action: fallback.action,
    method: fallback.method,
    attrs: {},
    inputs: fallback.inputs,
    body: html
  };
}

function extractAnyCsrf(html, actionHint = null, expectedFields = []) {
  const targetForm = selectTargetForm(html, actionHint, expectedFields);
  for (const cand of CSRF_FIELD_CANDIDATES) {
    const hit = targetForm.inputs.find((inp) => inp.name.toLowerCase() === cand && inp.value);
    if (hit) {
      return { fieldName: hit.name, token: hit.value };
    }
  }

  const parsedInfo = extractFormInfo(html, actionHint);
  for (const cand of CSRF_FIELD_CANDIDATES) {
    const hit = parsedInfo.inputs.find((inp) => inp.name.toLowerCase() === cand && inp.value);
    if (hit) {
      return { fieldName: hit.name, token: hit.value };
    }
  }

  const inputMatches = String(html || '').matchAll(/<input\b([^>]*)>/gi);
  for (const m of inputMatches) {
    const attrs = parseTagAttrs(m[1]);
    if (!attrs.name) continue;
    const lower = attrs.name.toLowerCase();
    if (CSRF_FIELD_CANDIDATES.includes(lower) && attrs.value) {
      return { fieldName: attrs.name, token: attrs.value };
    }
  }

  const metaMatches = String(html || '').matchAll(/<meta\b([^>]*)>/gi);
  for (const m of metaMatches) {
    const attrs = parseTagAttrs(m[1]);
    const name = (attrs.name || '').toLowerCase();
    if (/^(?:csrf-token|_csrf_token|csrf_token|_token)$/.test(name) && attrs.content) {
      return { fieldName: '_csrf_token', token: attrs.content };
    }
  }

  const fallbackToken = extractCsrf(html);
  if (fallbackToken) {
    return { fieldName: 'csrf_token', token: fallbackToken };
  }

  return { fieldName: '_csrf_token', token: null };
}

function findInputName(inputs, candidates, excludeTypes = new Set(['submit', 'button', 'reset'])) {
  for (const cand of candidates) {
    const found = inputs.find(
      (inp) => inp.name.toLowerCase() === cand && !excludeTypes.has(inp.type)
    );
    if (found) return found.name;
  }
  return null;
}

function resolveActionUrl(rawAction, fallbackPath, currentUrl) {
  if (!rawAction || rawAction.toLowerCase().includes('logout.php')) {
    return fallbackPath;
  }
  try {
    return new URL(rawAction, currentUrl).pathname + new URL(rawAction, currentUrl).search;
  } catch {
    return fallbackPath;
  }
}

async function loginHttpClient(ctx, username, password = ctx.env.seedPassword) {
  try {
    return await ctx.http.login(username, password);
  } catch {
    const client = ctx.http.createClient();
    const getRes = await client.get('/login.php');
    const csrf = extractAnyCsrf(getRes.text, 'login', ['identifier', 'username', 'password']);
    const form = selectTargetForm(getRes.text, 'login', ['identifier', 'username', 'password']);
    const idField =
      findInputName(form.inputs, [
        'identifier',
        'username',
        'login',
        'username_or_staff_id',
        'staff_id',
        'email'
      ]) || 'identifier';
    const pwField = form.inputs.find((i) => i.type === 'password')?.name || 'password';
    const payload = {
      [idField]: username,
      [pwField]: password
    };
    if (csrf.token) {
      payload[csrf.fieldName || '_csrf_token'] = csrf.token;
    }
    for (const inp of form.inputs) {
      if (inp.type === 'hidden' && inp.name && !(inp.name in payload) && !isCsrfFieldName(inp.name)) {
        payload[inp.name] = inp.value ?? '';
      }
    }
    const action = resolveActionUrl(form.action, '/login.php', getRes.url);
    const postRes = await client.postForm(action, payload);
    ctx.assert.ok(
      postRes.status < 400 &&
        client.getCookies('/').length > 0 &&
        !new URL(postRes.url).pathname.endsWith('/login.php'),
      `HTTP login failed for "${username}" (status=${postRes.status}, url=${postRes.url})`
    );
    return client;
  }
}

async function discoverBookingForm(ctx, client, urlPath, actionHint, expectedFields) {
  const res = await client.get(urlPath);
  ctx.assert.equal(res.status, 200, `Expected 200 on GET ${urlPath}, got ${res.status}`);
  const form = selectTargetForm(res.text, actionHint, expectedFields);
  const csrf = extractAnyCsrf(res.text, actionHint, expectedFields);
  const startField =
    findInputName(form.inputs, ['start_datetime', 'start_at', 'departure_datetime', 'start_time', 'start']) ||
    'start_datetime';
  const endField =
    findInputName(form.inputs, ['end_datetime', 'end_at', 'return_datetime', 'end_time', 'end']) ||
    'end_datetime';
  const startInput = form.inputs.find((i) => i.name === startField);
  const endInput = form.inputs.find((i) => i.name === endField);

  return {
    res,
    form,
    actionUrl: resolveActionUrl(form.action, urlPath, res.url),
    csrfFieldName: csrf.fieldName || '_csrf_token',
    csrfToken: csrf.token,
    fields: {
      title: findInputName(form.inputs, ['title', 'subject', 'topic', 'booking_title']) || 'title',
      purpose: findInputName(form.inputs, ['purpose', 'description', 'details', 'reason', 'note', 'notes']) || 'purpose',
      start_datetime: startField,
      end_datetime: endField,
      startIsLocal: !startInput || startInput.type === 'datetime-local',
      endIsLocal: !endInput || endInput.type === 'datetime-local',
      destination: findInputName(form.inputs, ['destination', 'location', 'route']) || 'destination',
      passenger_count:
        findInputName(form.inputs, ['passenger_count', 'passengers', 'seat_count']) || 'passenger_count',
      vehicle_id: findInputName(form.inputs, ['vehicle_id', 'preferred_vehicle_id']),
      driver_id: findInputName(form.inputs, ['driver_id', 'preferred_driver_id']),
      room_id: findInputName(form.inputs, ['room_id', 'venue_id']) || 'room_id',
      attendee_count:
        findInputName(form.inputs, ['attendee_count', 'attendees', 'participants']) || 'attendee_count'
    }
  };
}

function formatDatetimeForInput(isoLocal, isDatetimeLocal = true) {
  if (isDatetimeLocal) {
    return isoLocal.replace(' ', 'T').slice(0, 16);
  }
  return isoLocal.replace('T', ' ').slice(0, 19);
}

function buildBookingPayload(discovered, values) {
  const payload = {};
  for (const inp of discovered.form.inputs) {
    if (inp.type === 'hidden' && inp.name && !isCsrfFieldName(inp.name)) {
      payload[inp.name] = inp.value ?? '';
    }
  }
  if (discovered.csrfToken) {
    payload[discovered.csrfFieldName] = discovered.csrfToken;
  }

  const { fields } = discovered;
  if (values.title !== undefined) payload[fields.title] = values.title;
  if (values.purpose !== undefined) payload[fields.purpose] = values.purpose;
  if (values.start_datetime !== undefined) {
    payload[fields.start_datetime] = formatDatetimeForInput(values.start_datetime, fields.startIsLocal);
  }
  if (values.end_datetime !== undefined) {
    payload[fields.end_datetime] = formatDatetimeForInput(values.end_datetime, fields.endIsLocal);
  }
  if (values.destination !== undefined) payload[fields.destination] = values.destination;
  if (values.passenger_count !== undefined) payload[fields.passenger_count] = String(values.passenger_count);
  if (values.room_id !== undefined) payload[fields.room_id] = String(values.room_id);
  if (values.attendee_count !== undefined) payload[fields.attendee_count] = String(values.attendee_count);
  if (values.vehicle_id !== undefined && fields.vehicle_id) {
    payload[fields.vehicle_id] = String(values.vehicle_id);
  }
  if (values.driver_id !== undefined && fields.driver_id) {
    payload[fields.driver_id] = String(values.driver_id);
  }
  return payload;
}

function hasAlertType(html, types = ['danger', 'warning']) {
  const body = String(html || '');
  const joined = types.join('|');
  const flashRe = new RegExp(`data-flash-type=["'](?:${joined})["']`, 'i');
  const classRe = new RegExp(`\\balert-(?:${joined})\\b`, 'i');
  if (flashRe.test(body) || classRe.test(body)) {
    return true;
  }
  const roleRe = /<div\b(?![^>]*\balert-(?:success|info)\b)[^>]*role=["']alert["'][^>]*>([\s\S]*?)<\/div>/i;
  const match = body.match(roleRe);
  if (match) {
    const cleaned = match[1].replace(/<[^>]+>/g, ' ').trim();
    if (cleaned.length > 0) return true;
  }
  return false;
}

function hasSuccessAlert(html) {
  const body = String(html || '');
  return /data-flash-type=["']success["']/i.test(body) || /\balert-success\b/i.test(body);
}

function isRejectedResponse(res) {
  if (res.status >= 400 && res.status < 500) {
    return true;
  }
  const ct = res.headers?.get('content-type') || '';
  if (ct.includes('application/json')) {
    try {
      const json = JSON.parse(res.text);
      if (json && (json.ok === false || json.success === false || json.error)) {
        return true;
      }
    } catch {
      // fall through to HTML check
    }
  }
  return hasAlertType(res.text, ['danger', 'warning']);
}

function addOneDay(dateStr) {
  const [y, m, d] = dateStr.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + 1));
  return dt.toISOString().slice(0, 10);
}

function registerWindowCleanup(ctx, startDateStr, endDateStr = startDateStr) {
  const nextDay = addOneDay(endDateStr);
  ctx.onCleanup(async () => {
    await ctx.db.execSql(
      'DELETE FROM notification_logs WHERE booking_id IN (SELECT id FROM bookings WHERE start_datetime >= ? AND start_datetime < ?)',
      [`${startDateStr} 00:00:00`, `${nextDay} 00:00:00`]
    );
    await ctx.db.execSql(
      'DELETE FROM bookings WHERE start_datetime >= ? AND start_datetime < ?',
      [`${startDateStr} 00:00:00`, `${nextDay} 00:00:00`]
    );
  });
}

async function fillFirstVisible(page, selectors, value) {
  for (const sel of selectors) {
    const loc = page.locator(sel).first();
    if ((await loc.count()) > 0 && (await loc.isVisible().catch(() => false))) {
      await loc.fill(String(value));
      return true;
    }
  }
  return false;
}

async function selectFirstVisible(page, selectors, value) {
  for (const sel of selectors) {
    const loc = page.locator(sel).first();
    if ((await loc.count()) > 0 && (await loc.isVisible().catch(() => false))) {
      await loc.selectOption(String(value));
      return true;
    }
  }
  return false;
}

async function attemptStep1VehicleAssign(ctx, approverClient, bookingId, vehicleId, driverId) {
  const detailRes = await approverClient.get(`/booking_detail.php?id=${bookingId}`);
  const approvalsRes = await approverClient.get('/approvals.php');

  ctx.assert.ok(
    detailRes.status !== 404 || approvalsRes.status !== 404,
    'M3 Step-1 assignment pages (/booking_detail.php, /approvals.php) returned 404; Branch B requires M3'
  );

  for (const pageRes of [detailRes, approvalsRes]) {
    if (pageRes.status !== 200) continue;
    const forms = parseAllForms(pageRes.text).filter(
      (f) =>
        !f.action.toLowerCase().includes('logout.php') &&
        f.inputs.some((i) =>
          ['vehicle_id', 'driver_id', 'booking_id', 'action'].includes(i.name.toLowerCase())
        )
    );
    const matchingForm =
      forms.find((f) =>
        f.inputs.some(
          (i) => i.name.toLowerCase() === 'booking_id' && String(i.value) === String(bookingId)
        )
      ) || forms[0];

    if (matchingForm) {
      const csrf = extractAnyCsrf(pageRes.text, matchingForm.action, ['vehicle_id', 'booking_id']);
      const payload = {};
      for (const inp of matchingForm.inputs) {
        if (inp.type === 'hidden' && inp.name && !isCsrfFieldName(inp.name)) {
          payload[inp.name] = inp.value ?? '';
        }
      }
      if (csrf.token) {
        payload[csrf.fieldName || '_csrf_token'] = csrf.token;
      }
      payload.booking_id = String(bookingId);
      payload.vehicle_id = String(vehicleId);
      payload.driver_id = String(driverId);
      const hasAssignOpt = /value=["']assign_vehicle["']/i.test(matchingForm.body);
      payload.action = hasAssignOpt ? 'assign_vehicle' : 'approve';

      const targetUrl = resolveActionUrl(matchingForm.action, '/api/approval_action.php', pageRes.url);
      const res = await approverClient.postForm(targetUrl, payload, {
        headers: csrf.token ? { 'X-CSRF-Token': csrf.token } : {}
      });
      if (res.status !== 404) {
        return res;
      }
    }
  }

  const csrfSource = approvalsRes.status === 200 ? approvalsRes.text : detailRes.text;
  const csrf = extractAnyCsrf(csrfSource);
  ctx.assert.ok(
    csrf.token,
    'Could not find CSRF token on approvals.php or booking_detail.php for Step-1 assignment'
  );

  const apiRes = await approverClient.postForm(
    '/api/approval_action.php',
    {
      [csrf.fieldName || '_csrf_token']: csrf.token,
      csrf_token: csrf.token,
      booking_id: String(bookingId),
      action: 'assign_vehicle',
      vehicle_id: String(vehicleId),
      driver_id: String(driverId),
      comment: 'E2E vehicle assign conflict check'
    },
    { headers: { 'X-CSRF-Token': csrf.token } }
  );

  if (apiRes.status === 400 && /invalid\s*action|action/i.test(apiRes.text)) {
    return approverClient.postForm(
      '/api/approval_action.php',
      {
        [csrf.fieldName || '_csrf_token']: csrf.token,
        csrf_token: csrf.token,
        booking_id: String(bookingId),
        action: 'approve',
        vehicle_id: String(vehicleId),
        driver_id: String(driverId),
        comment: 'E2E vehicle approve conflict check'
      },
      { headers: { 'X-CSRF-Token': csrf.token } }
    );
  }

  return apiRes;
}

export function register(test) {
  // =========================================================================
  // Feature F7: Vehicle Booking (Internal Only) — Tier 1 (6 Tests)
  // =========================================================================

  test({
    id: 'T1-F7-01',
    tier: 1,
    feature: 'F7',
    name: 'Internal user01 submits round-trip vehicle request via book_vehicle.php UI to pending_head',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-01');
      const marker = '[E2E-T1-F7-01]';
      const title = `${marker} ประชุมจังหวัด`;
      const destination = 'ศาลากลางจังหวัดนนทบุรี';
      const purpose = 'เดินทางราชการ';

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/book_vehicle.php`, { waitUntil: 'domcontentloaded' });

      const formScope = page
        .locator('form')
        .filter({ hasNot: page.locator('[action*="logout"]') })
        .first();
      await formScope.waitFor({ state: 'visible' });

      await fillFirstVisible(
        page,
        ['input[name="title"]', 'input[name="subject"]', 'input[name="booking_title"]'],
        title
      );
      await fillFirstVisible(
        page,
        ['input[name="destination"]', 'textarea[name="destination"]', 'input[name="location"]'],
        destination
      );
      await fillFirstVisible(
        page,
        ['input[name="passenger_count"]', 'input[name="passengers"]'],
        '4'
      );
      await fillFirstVisible(
        page,
        ['textarea[name="purpose"]', 'textarea[name="description"]', 'input[name="purpose"]'],
        purpose
      );
      await fillFirstVisible(
        page,
        ['input[name="start_datetime"]', 'input[name="departure_datetime"]', 'input[name*="start"]'],
        '2027-06-01T08:30'
      );
      await fillFirstVisible(
        page,
        ['input[name="end_datetime"]', 'input[name="return_datetime"]', 'input[name*="end"]'],
        '2027-06-01T16:30'
      );

      const submitBtn = formScope.locator('button[type="submit"], input[type="submit"]').first();
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {}),
        submitBtn.click()
      ]);
      await page.waitForLoadState('domcontentloaded').catch(() => {});

      errors.assertNoErrors('T1-F7-01');
      const html = await page.content();
      ctx.assert.equal(
        hasAlertType(html, ['danger', 'warning']),
        false,
        'Vehicle booking submit should not produce danger/warning alert'
      );

      const rows = await ctx.db.queryJson(
        'SELECT id, booking_type, requester_id, requester_type, status, current_step, destination, passenger_count, start_datetime, end_datetime FROM bookings WHERE title = ?',
        [title]
      );
      ctx.assert.equal(rows.length, 1, 'Expected 1 booking row inserted for T1-F7-01');
      const row = rows[0];
      ctx.assert.equal(row.booking_type, 'vehicle');
      ctx.assert.equal(Number(row.requester_id), 6);
      ctx.assert.equal(row.requester_type, 'internal');
      ctx.assert.equal(row.status, 'pending_head');
      ctx.assert.equal(Number(row.current_step), 1);
      ctx.assert.equal(row.destination, destination);
      ctx.assert.equal(Number(row.passenger_count), 4);
    }
  });

  test({
    id: 'T1-F7-02',
    tier: 1,
    feature: 'F7',
    name: 'Submitted vehicle booking appears in my_bookings.php with destination and pending status',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-02');
      const title = '[E2E-T1-F7-02] ส่งเอกสาร';
      const destination = 'กระทรวงมหาดไทย';

      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);
      const payload = buildBookingPayload(form, {
        title,
        purpose: 'ส่งเอกสารด่วน',
        destination,
        passenger_count: 3,
        start_datetime: '2027-06-02T09:00',
        end_datetime: '2027-06-02T15:00'
      });
      const postRes = await client.postForm(form.actionUrl, payload);
      ctx.assert.ok(postRes.status < 400, `Expected <400 on vehicle submit, got ${postRes.status}`);
      ctx.assert.equal(hasAlertType(postRes.text, ['danger', 'warning']), false);

      const rows = await ctx.db.queryJson(
        'SELECT id, reference_code FROM bookings WHERE title = ?',
        [title]
      );
      ctx.assert.equal(rows.length, 1, 'Expected vehicle booking row in DB');
      const booking = rows[0];

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/my_bookings.php`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T1-F7-02');

      const bodyText = await page.locator('body').innerText();
      ctx.assert.ok(
        bodyText.includes('[E2E-T1-F7-02]') || bodyText.includes(booking.reference_code),
        `my_bookings.php should list booking title or reference_code (${booking.reference_code})`
      );
      ctx.assert.ok(
        bodyText.includes(destination) || /รอหัวหน้างาน|pending_head|รออนุมัติ/i.test(bodyText),
        'my_bookings.php should show destination or pending status badge'
      );

      const detailLinks = await page
        .locator(`a[href*="booking_detail.php?id=${booking.id}"], a[href*="booking_detail.php"]`)
        .count();
      ctx.assert.ok(detailLinks > 0, 'my_bookings.php should link to booking_detail.php');
    }
  });

  test({
    id: 'T1-F7-03',
    tier: 1,
    feature: 'F7',
    name: 'Round-trip datetimes and optional vehicle preference persist accurately in bookings',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-03');
      const title = '[E2E-T1-F7-03] RoundTrip';
      const destination = 'กรมบัญชีกลาง';

      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);
      const hadVehicleField = Boolean(form.fields.vehicle_id);
      const payload = buildBookingPayload(form, {
        title,
        purpose: 'ประสานงานบัญชีกลาง',
        destination,
        passenger_count: 5,
        start_datetime: '2027-06-03T07:45',
        end_datetime: '2027-06-03T18:15',
        vehicle_id: hadVehicleField ? 1 : undefined
      });
      const res = await client.postForm(form.actionUrl, payload);
      ctx.assert.ok(res.status < 400, `Unexpected HTTP ${res.status}`);
      ctx.assert.equal(hasAlertType(res.text, ['danger', 'warning']), false);

      const rows = await ctx.db.queryJson(
        'SELECT start_datetime, end_datetime, destination, passenger_count, vehicle_id FROM bookings WHERE title = ?',
        [title]
      );
      ctx.assert.equal(rows.length, 1);
      const row = rows[0];
      ctx.assert.equal(row.start_datetime, '2027-06-03 07:45:00');
      ctx.assert.equal(row.end_datetime, '2027-06-03 18:15:00');
      ctx.assert.equal(row.destination, destination);
      ctx.assert.equal(Number(row.passenger_count), 5);
      if (hadVehicleField) {
        ctx.assert.equal(Number(row.vehicle_id), 1);
      } else {
        ctx.assert.equal(row.vehicle_id, null);
      }
    }
  });

  test({
    id: 'T1-F7-04',
    tier: 1,
    feature: 'F7',
    name: 'Other internal roles (staff01 and head01) can access and submit book_vehicle.php',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-04');
      const entries = [
        { username: 'staff01', expectedUserId: 2, title: '[E2E-T1-F7-04] Staff Vehicle', start: '2027-06-04T09:00', end: '2027-06-04T12:00' },
        { username: 'head01', expectedUserId: 3, title: '[E2E-T1-F7-04] Head Vehicle', start: '2027-06-04T13:00', end: '2027-06-04T17:00' }
      ];

      for (const item of entries) {
        const client = await loginHttpClient(ctx, item.username);
        const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const payload = buildBookingPayload(form, {
          title: item.title,
          purpose: 'ปฏิบัติงานราชการ',
          destination: 'สำนักงานปลัดกระทรวง',
          passenger_count: 2,
          start_datetime: item.start,
          end_datetime: item.end
        });
        const res = await client.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 400, `${item.username} POST /book_vehicle.php returned ${res.status}`);
        ctx.assert.equal(hasAlertType(res.text, ['danger', 'warning']), false);

        const rows = await ctx.db.queryJson(
          'SELECT booking_type, requester_id, requester_type, status FROM bookings WHERE title = ?',
          [item.title]
        );
        ctx.assert.equal(rows.length, 1, `Expected 1 booking row for ${item.username}`);
        ctx.assert.equal(rows[0].booking_type, 'vehicle');
        ctx.assert.equal(Number(rows[0].requester_id), item.expectedUserId);
        ctx.assert.equal(rows[0].requester_type, 'internal');
        ctx.assert.equal(rows[0].status, 'pending_head');
      }
    }
  });

  test({
    id: 'T1-F7-05',
    tier: 1,
    feature: 'F7',
    name: 'Boundary valid passenger_count (1 min and 12 max vehicle seats) accepted',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-05', '2027-06-06');
      const client = await loginHttpClient(ctx, 'user01');

      const cases = [
        { title: '[E2E-T1-F7-05] Min1', count: 1, start: '2027-06-05T08:00', end: '2027-06-05T11:00' },
        { title: '[E2E-T1-F7-05] Max12', count: 12, start: '2027-06-06T08:00', end: '2027-06-06T17:00' }
      ];

      for (const tc of cases) {
        const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const payload = buildBookingPayload(form, {
          title: tc.title,
          purpose: 'ทดสอบจำนวนผู้โดยสารขอบเขต',
          destination: 'เขตดุสิต กรุงเทพฯ',
          passenger_count: tc.count,
          start_datetime: tc.start,
          end_datetime: tc.end,
          vehicle_id: form.fields.vehicle_id ? 1 : undefined
        });
        const res = await client.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 400, `Boundary passenger_count=${tc.count} returned ${res.status}`);
        ctx.assert.equal(
          hasAlertType(res.text, ['danger', 'warning']),
          false,
          `Boundary passenger_count=${tc.count} should not be rejected`
        );

        const stored = await ctx.db.scalar(
          'SELECT passenger_count FROM bookings WHERE title = ?',
          [tc.title]
        );
        ctx.assert.equal(Number(stored), tc.count);
      }
    }
  });

  test({
    id: 'T1-F7-06',
    tier: 1,
    feature: 'F7',
    name: 'book_vehicle.php renders responsively on mobile (375x812) with no horizontal overflow',
    fn: async (ctx) => {
      const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
      await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/book_vehicle.php`, { waitUntil: 'domcontentloaded' });

      errors.assertNoErrors('T1-F7-06');

      const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth
      }));
      ctx.assert.ok(
        overflow.scrollWidth <= overflow.clientWidth + 1,
        `Mobile overflow detected: scrollWidth=${overflow.scrollWidth} > clientWidth=${overflow.clientWidth}`
      );

      const requiredSelectors = [
        'input[name="destination"], textarea[name="destination"], input[name="location"]',
        'input[name="passenger_count"], input[name="passengers"]',
        'input[name="start_datetime"], input[name*="start"]',
        'input[name="end_datetime"], input[name*="end"]',
        'form:not([action*="logout"]) button[type="submit"], form:not([action*="logout"]) input[type="submit"]'
      ];
      for (const sel of requiredSelectors) {
        const vis = await page.locator(sel).first().isVisible().catch(() => false);
        ctx.assert.ok(vis, `Expected mobile form control visible: ${sel}`);
      }
    }
  });

  // =========================================================================
  // Feature F8: Server-Side Conflict Detection — Tier 1 (6 Tests)
  // =========================================================================

  test({
    id: 'T1-F8-01',
    tier: 1,
    feature: 'F8',
    name: 'Overlapping room booking blocked server-side with alert and zero DB rows inserted',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-12');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status)
         VALUES (?, 'room', 6, 'internal', 1, ?, 'Existing meeting', '2027-06-12 09:00:00', '2027-06-12 12:00:00', 15, 1, 'pending_head')`,
        ['E2E-T1F801-SEED', '[E2E-T1-F8-01] Seed Active Room']
      );

      const beforeCount = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 1 AND start_datetime >= '2027-06-12 00:00:00' AND start_datetime < '2027-06-13 00:00:00'"
        )
      );
      ctx.assert.equal(beforeCount, 1);

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, 'user02', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });

      const formScope = page
        .locator('form')
        .filter({ hasNot: page.locator('[action*="logout"]') })
        .first();
      await formScope.waitFor({ state: 'visible' });

      await selectFirstVisible(page, ['select[name="room_id"]', 'select[name="venue_id"]'], '1');
      await fillFirstVisible(page, ['input[name="title"]', 'input[name="subject"]'], '[E2E-T1-F8-01] Overlap');
      await fillFirstVisible(page, ['input[name="attendee_count"]', 'input[name="attendees"]'], '10');
      await fillFirstVisible(page, ['textarea[name="purpose"]', 'textarea[name="description"]'], 'Overlap check');
      await fillFirstVisible(page, ['input[name="start_datetime"]', 'input[name*="start"]'], '2027-06-12T10:00');
      await fillFirstVisible(page, ['input[name="end_datetime"]', 'input[name*="end"]'], '2027-06-12T13:00');

      const submitBtn = formScope.locator('button[type="submit"], input[type="submit"]').first();
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {}),
        submitBtn.click()
      ]);
      await page.waitForLoadState('domcontentloaded').catch(() => {});

      ctx.assert.equal(errors.pageErrors.length, 0, 'Expected zero JS pageErrors');
      ctx.assert.equal(errors.serverErrors.length, 0, 'Expected zero HTTP 5xx serverErrors');

      const html = await page.content();
      ctx.assert.ok(
        hasAlertType(html, ['danger', 'warning']),
        'Expected danger/warning conflict alert in UI after overlapping room submit'
      );
      ctx.assert.equal(hasSuccessAlert(html), false, 'Conflicting booking must not show success alert');

      const afterCount = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 1 AND start_datetime >= '2027-06-12 00:00:00' AND start_datetime < '2027-06-13 00:00:00'"
        )
      );
      ctx.assert.equal(afterCount, 1, 'Conflicting room booking must not insert a DB row');
    }
  });

  test({
    id: 'T1-F8-02',
    tier: 1,
    feature: 'F8',
    name: 'Overlapping vehicle booking blocked server-side (request-time vehicle_id or Step-1 assignment)',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-15');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, vehicle_id, driver_id, title, purpose, start_datetime, end_datetime, destination, passenger_count, current_step, status)
         VALUES (?, 'vehicle', 6, 'internal', 1, 1, ?, 'Active trip A', '2027-06-15 09:00:00', '2027-06-15 12:00:00', 'ชลบุรี', 6, 2, 'pending_vice')`,
        ['E2E-T1F802-A', '[E2E-T1-F8-02] Active Vehicle A']
      );

      const userClient = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, userClient, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);

      if (form.fields.vehicle_id) {
        // Branch A: vehicle_id is selectable on book_vehicle.php
        const payload = buildBookingPayload(form, {
          title: '[E2E-T1-F8-02] Overlap Vehicle B',
          purpose: 'Overlapping request',
          destination: 'ระยอง',
          passenger_count: 4,
          start_datetime: '2027-06-15T10:00',
          end_datetime: '2027-06-15T13:00',
          vehicle_id: 1
        });
        const res = await userClient.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 500, `Expected <500, got ${res.status}`);
        ctx.assert.ok(
          isRejectedResponse(res),
          'Branch A: Expected vehicle overlap rejection signal (4xx or danger/warning alert)'
        );
        ctx.assert.equal(hasSuccessAlert(res.text), false);

        const count = Number(
          await ctx.db.scalar(
            "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-06-15 00:00:00' AND start_datetime < '2027-06-16 00:00:00'"
          )
        );
        ctx.assert.equal(count, 1, 'Branch A: Conflicting vehicle booking must not insert row');
      } else {
        // Branch B: vehicle_id deferred to Step 1 assignment
        const titleB = '[E2E-T1-F8-02] Deferred Vehicle B';
        const payload = buildBookingPayload(form, {
          title: titleB,
          purpose: 'Pending head request',
          destination: 'ระยอง',
          passenger_count: 4,
          start_datetime: '2027-06-15T10:00',
          end_datetime: '2027-06-15T13:00'
        });
        const submitRes = await userClient.postForm(form.actionUrl, payload);
        ctx.assert.ok(submitRes.status < 400);
        const rowB = (
          await ctx.db.queryJson('SELECT id, vehicle_id, status FROM bookings WHERE title = ?', [titleB])
        )[0];
        ctx.assert.ok(rowB, 'Branch B: Booking B should be created in pending_head');

        const headClient = await loginHttpClient(ctx, 'head01');
        const assignRes = await attemptStep1VehicleAssign(ctx, headClient, rowB.id, 1, 1);
        ctx.assert.ok(assignRes.status < 500, `Assignment endpoint returned ${assignRes.status}`);
        ctx.assert.ok(
          isRejectedResponse(assignRes),
          `Branch B: Expected Step-1 conflicting vehicle assignment to be rejected (status=${assignRes.status})`
        );

        const afterRowB = (
          await ctx.db.queryJson('SELECT vehicle_id, status FROM bookings WHERE id = ?', [rowB.id])
        )[0];
        ctx.assert.equal(afterRowB.vehicle_id, null, 'Branch B: Conflicting vehicle_id must remain NULL');
        ctx.assert.equal(afterRowB.status, 'pending_head', 'Branch B: Status must remain pending_head');
      }
    }
  });

  test({
    id: 'T1-F8-03',
    tier: 1,
    feature: 'F8',
    name: 'Back-to-back room slot (09:00-11:00 then 11:00-13:00) is ALLOWED',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-16');

      const c1 = await loginHttpClient(ctx, 'user01');
      const f1 = await discoverBookingForm(ctx, c1, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const r1 = await c1.postForm(
        f1.actionUrl,
        buildBookingPayload(f1, {
          room_id: 1,
          title: '[E2E-T1-F8-03-A] Morning Slot',
          purpose: 'Slot A',
          attendee_count: 10,
          start_datetime: '2027-06-16T09:00',
          end_datetime: '2027-06-16T11:00'
        })
      );
      ctx.assert.ok(r1.status < 400);
      ctx.assert.equal(hasAlertType(r1.text, ['danger', 'warning']), false);

      const c2 = await loginHttpClient(ctx, 'user02');
      const f2 = await discoverBookingForm(ctx, c2, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const r2 = await c2.postForm(
        f2.actionUrl,
        buildBookingPayload(f2, {
          room_id: 1,
          title: '[E2E-T1-F8-03-B] BackToBack Slot',
          purpose: 'Slot B',
          attendee_count: 10,
          start_datetime: '2027-06-16T11:00',
          end_datetime: '2027-06-16T13:00'
        })
      );
      ctx.assert.ok(r2.status < 400);
      ctx.assert.equal(
        hasAlertType(r2.text, ['danger', 'warning']),
        false,
        'Back-to-back slot 11:00-13:00 after 09:00-11:00 must be allowed'
      );

      const rows = await ctx.db.queryJson(
        "SELECT start_datetime, end_datetime FROM bookings WHERE room_id = 1 AND start_datetime >= '2027-06-16 00:00:00' AND start_datetime < '2027-06-17 00:00:00' ORDER BY start_datetime ASC"
      );
      ctx.assert.equal(rows.length, 2, 'Expected 2 back-to-back bookings in DB');
      ctx.assert.equal(rows[0].end_datetime, '2027-06-16 11:00:00');
      ctx.assert.equal(rows[1].start_datetime, '2027-06-16 11:00:00');
    }
  });

  test({
    id: 'T1-F8-04',
    tier: 1,
    feature: 'F8',
    name: 'Existing rejected booking does NOT block a new booking on the same slot',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-17');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, rejection_reason)
         VALUES (?, 'room', 7, 'internal', 1, ?, 'Rejected slot', '2027-06-17 09:00:00', '2027-06-17 12:00:00', 10, 1, 'rejected', 'Test rejected')`,
        ['E2E-T1F804-REJ', '[E2E-T1-F8-04] Rejected Seed']
      );

      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const res = await client.postForm(
        form.actionUrl,
        buildBookingPayload(form, {
          room_id: 1,
          title: '[E2E-T1-F8-04] Rebooked Slot',
          purpose: 'Rebooking after rejection',
          attendee_count: 15,
          start_datetime: '2027-06-17T09:00',
          end_datetime: '2027-06-17T12:00'
        })
      );
      ctx.assert.ok(res.status < 400);
      ctx.assert.equal(
        hasAlertType(res.text, ['danger', 'warning']),
        false,
        'Rejected booking must not block new booking on the same time slot'
      );

      const rows = await ctx.db.queryJson(
        "SELECT status FROM bookings WHERE room_id = 1 AND start_datetime >= '2027-06-17 00:00:00' AND start_datetime < '2027-06-18 00:00:00' ORDER BY id ASC"
      );
      ctx.assert.equal(rows.length, 2);
      ctx.assert.deepEqual(
        rows.map((r) => r.status).sort(),
        ['pending_head', 'rejected']
      );
    }
  });

  test({
    id: 'T1-F8-05',
    tier: 1,
    feature: 'F8',
    name: 'my_bookings.php shows only own bookings for user and external_user',
    fn: async (ctx) => {
      // Check user01 via Playwright DESKTOP
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/my_bookings.php`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T1-F8-05 user01');

      const user01Text = await page.locator('body').innerText();
      for (const ownRef of ['BK-001', 'BK-003', 'BK-005']) {
        ctx.assert.ok(user01Text.includes(ownRef), `user01 my_bookings should contain ${ownRef}`);
      }
      for (const otherRef of ['BK-002', 'BK-004', 'BK-006']) {
        ctx.assert.equal(user01Text.includes(otherRef), false, `user01 my_bookings must NOT show ${otherRef}`);
      }

      // Check ext01 via HTTP
      const extClient = await loginHttpClient(ctx, 'ext01');
      const extRes = await extClient.get('/my_bookings.php');
      ctx.assert.equal(extRes.status, 200);
      ctx.assert.ok(extRes.text.includes('BK-002'), 'ext01 my_bookings should contain BK-002');
      for (const otherRef of ['BK-001', 'BK-003', 'BK-004', 'BK-005', 'BK-006']) {
        ctx.assert.equal(extRes.text.includes(otherRef), false, `ext01 my_bookings must NOT show ${otherRef}`);
      }
    }
  });

  test({
    id: 'T1-F8-06',
    tier: 1,
    feature: 'F8',
    name: 'Different rooms booked at the identical time slot do NOT conflict',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-06-18');

      const c1 = await loginHttpClient(ctx, 'user01');
      const f1 = await discoverBookingForm(ctx, c1, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const r1 = await c1.postForm(
        f1.actionUrl,
        buildBookingPayload(f1, {
          room_id: 1,
          title: '[E2E-T1-F8-06] Room 1',
          purpose: 'Parallel Room 1',
          attendee_count: 10,
          start_datetime: '2027-06-18T09:00',
          end_datetime: '2027-06-18T12:00'
        })
      );
      ctx.assert.ok(r1.status < 400);
      ctx.assert.equal(hasAlertType(r1.text, ['danger', 'warning']), false);

      const c2 = await loginHttpClient(ctx, 'user02');
      const f2 = await discoverBookingForm(ctx, c2, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const r2 = await c2.postForm(
        f2.actionUrl,
        buildBookingPayload(f2, {
          room_id: 3,
          title: '[E2E-T1-F8-06] Room 3',
          purpose: 'Parallel Room 3',
          attendee_count: 8,
          start_datetime: '2027-06-18T09:00',
          end_datetime: '2027-06-18T12:00'
        })
      );
      ctx.assert.ok(r2.status < 400);
      ctx.assert.equal(
        hasAlertType(r2.text, ['danger', 'warning']),
        false,
        'Different rooms at the same time must not conflict'
      );

      const rows = await ctx.db.queryJson(
        "SELECT room_id FROM bookings WHERE start_datetime = '2027-06-18 09:00:00' AND end_datetime = '2027-06-18 12:00:00' AND status != 'rejected' ORDER BY room_id ASC"
      );
      ctx.assert.deepEqual(
        rows.map((r) => Number(r.room_id)),
        [1, 3]
      );
    }
  });
}
