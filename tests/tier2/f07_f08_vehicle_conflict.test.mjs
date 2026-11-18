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

function buildBookingPayload(discovered, values, opts = {}) {
  const payload = {};
  for (const inp of discovered.form.inputs) {
    if (inp.type === 'hidden' && inp.name && !isCsrfFieldName(inp.name)) {
      payload[inp.name] = inp.value ?? '';
    }
  }
  if (!opts.omitCsrf) {
    const tok = opts.csrfOverride !== undefined ? opts.csrfOverride : discovered.csrfToken;
    if (tok !== null && tok !== undefined) {
      payload[discovered.csrfFieldName] = tok;
    }
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
      // fall through
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
      comment: 'E2E vehicle assign test'
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
        comment: 'E2E vehicle approve test'
      },
      { headers: { 'X-CSRF-Token': csrf.token } }
    );
  }

  return apiRes;
}

export function register(test) {
  // =========================================================================
  // Feature F7: Vehicle Booking (Internal Only) — Tier 2 (6 Tests)
  // =========================================================================

  test({
    id: 'T2-F7-01',
    tier: 2,
    feature: 'F7',
    name: 'external_user (ext01) receives 403 on GET and forged POST book_vehicle.php (and guest redirected)',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-01');

      // (1) Unauthenticated guest gets 302 to login.php
      const guest = ctx.http.createClient();
      const guestRes = await guest.get('/book_vehicle.php', { redirect: 'manual' });
      ctx.assert.ok(
        [301, 302, 303].includes(guestRes.status),
        `Expected 302 redirect for guest on /book_vehicle.php, got ${guestRes.status}`
      );
      ctx.assert.ok(
        (guestRes.location || '').includes('login.php'),
        `Expected redirect to login.php, got "${guestRes.location}"`
      );

      // (2) external_user GET /book_vehicle.php -> 403
      const extClient = await loginHttpClient(ctx, 'ext01');
      const extGet = await extClient.get('/book_vehicle.php');
      ctx.assert.equal(extGet.status, 403, `Expected 403 for ext01 GET /book_vehicle.php, got ${extGet.status}`);

      // (3) external_user forged POST /book_vehicle.php using valid CSRF from /book_room.php -> 403
      const roomPage = await extClient.get('/book_room.php');
      const csrf = extractAnyCsrf(roomPage.text, 'book_room', ['room_id']);
      ctx.assert.ok(csrf.token, 'Expected valid CSRF token on /book_room.php for ext01');

      const forgedPost = await extClient.postForm('/book_vehicle.php', {
        [csrf.fieldName || '_csrf_token']: csrf.token,
        _csrf_token: csrf.token,
        title: '[E2E-T2-F7-01] Forged External Vehicle',
        purpose: 'Unauthorized attempt',
        destination: 'พัทยา',
        passenger_count: '4',
        start_datetime: '2027-07-01T09:00',
        end_datetime: '2027-07-01T12:00'
      });
      ctx.assert.equal(
        forgedPost.status,
        403,
        `Expected 403 for ext01 forged POST /book_vehicle.php, got ${forgedPost.status}`
      );

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-07-01 00:00:00' AND start_datetime < '2027-07-02 00:00:00'"
        )
      );
      ctx.assert.equal(count, 0, 'External user must never insert vehicle booking');
    }
  });

  test({
    id: 'T2-F7-02',
    tier: 2,
    feature: 'F7',
    name: 'Invalid passenger_count (0, -2, non-numeric abc, and 13 > max seats 12) rejected',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-02');
      const client = await loginHttpClient(ctx, 'user01');

      for (const badCount of ['0', '-2', 'abc', '13']) {
        const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const payload = buildBookingPayload(form, {
          title: `[E2E-T2-F7-02] BadCount ${badCount}`,
          purpose: 'Invalid passenger test',
          destination: 'นนทบุรี',
          passenger_count: badCount,
          start_datetime: '2027-07-02T09:00',
          end_datetime: '2027-07-02T12:00',
          vehicle_id: form.fields.vehicle_id ? 1 : undefined
        });
        const res = await client.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 500, `Expected <500 for passenger_count=${badCount}, got ${res.status}`);
        ctx.assert.ok(
          isRejectedResponse(res),
          `Expected rejection alert/4xx for passenger_count="${badCount}"`
        );
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-07-02 00:00:00' AND start_datetime < '2027-07-03 00:00:00'"
        )
      );
      ctx.assert.equal(count, 0, 'No rows should be inserted for invalid passenger_count');
    }
  });

  test({
    id: 'T2-F7-03',
    tier: 2,
    feature: 'F7',
    name: 'Invalid round-trip range (end_datetime == start_datetime and end_datetime < start_datetime) rejected',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-03');
      const client = await loginHttpClient(ctx, 'user01');

      const ranges = [
        { label: 'equal', start: '2027-07-03T10:00', end: '2027-07-03T10:00' },
        { label: 'reversed', start: '2027-07-03T15:00', end: '2027-07-03T13:00' }
      ];

      for (const r of ranges) {
        const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const payload = buildBookingPayload(form, {
          title: `[E2E-T2-F7-03] BadRange ${r.label}`,
          purpose: 'Invalid datetime range',
          destination: 'ปทุมธานี',
          passenger_count: 3,
          start_datetime: r.start,
          end_datetime: r.end
        });
        const res = await client.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 500);
        ctx.assert.ok(
          isRejectedResponse(res),
          `Expected rejection for ${r.label} range (${r.start} -> ${r.end})`
        );
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-07-03 00:00:00' AND start_datetime < '2027-07-04 00:00:00'"
        )
      );
      ctx.assert.equal(count, 0);
    }
  });

  test({
    id: 'T2-F7-04',
    tier: 2,
    feature: 'F7',
    name: 'Empty/whitespace destination and missing required fields rejected',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-04');
      const client = await loginHttpClient(ctx, 'user01');

      const invalidCases = [
        { title: '[E2E-T2-F7-04] EmptyDest', destination: '' },
        { title: '[E2E-T2-F7-04] SpaceDest', destination: '   ' },
        { title: '', destination: 'ศาลากลางจังหวัด' }
      ];

      for (const ic of invalidCases) {
        const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const payload = buildBookingPayload(form, {
          title: ic.title,
          purpose: 'Missing field check',
          destination: ic.destination,
          passenger_count: 2,
          start_datetime: '2027-07-04T09:00',
          end_datetime: '2027-07-04T12:00'
        });
        const res = await client.postForm(form.actionUrl, payload);
        ctx.assert.ok(res.status < 500);
        ctx.assert.ok(
          isRejectedResponse(res),
          `Expected validation rejection for title="${ic.title}", destination="${ic.destination}"`
        );
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-07-04 00:00:00' AND start_datetime < '2027-07-05 00:00:00'"
        )
      );
      ctx.assert.equal(count, 0);
    }
  });

  test({
    id: 'T2-F7-05',
    tier: 2,
    feature: 'F7',
    name: 'Missing or invalid CSRF token on POST /book_vehicle.php rejected with 403',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-05');
      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);

      // (a) Missing CSRF completely
      const noCsrfPayload = buildBookingPayload(
        form,
        {
          title: '[E2E-T2-F7-05] Missing CSRF',
          purpose: 'CSRF test',
          destination: 'สมุทรปราการ',
          passenger_count: 3,
          start_datetime: '2027-07-05T09:00',
          end_datetime: '2027-07-05T12:00'
        },
        { omitCsrf: true }
      );
      const resMissing = await client.postForm(form.actionUrl, noCsrfPayload);
      ctx.assert.equal(resMissing.status, 403, `Expected 403 for missing CSRF, got ${resMissing.status}`);

      // (b) Invalid CSRF token
      const badCsrfPayload = buildBookingPayload(
        form,
        {
          title: '[E2E-T2-F7-05] Invalid CSRF',
          purpose: 'CSRF test',
          destination: 'สมุทรปราการ',
          passenger_count: 3,
          start_datetime: '2027-07-05T09:00',
          end_datetime: '2027-07-05T12:00'
        },
        { csrfOverride: 'bad_csrf_token_000' }
      );
      badCsrfPayload._csrf_token = 'bad_csrf_token_000';
      badCsrfPayload.csrf_token = 'bad_csrf_token_000';
      const resBad = await client.postForm(form.actionUrl, badCsrfPayload);
      ctx.assert.equal(resBad.status, 403, `Expected 403 for invalid CSRF, got ${resBad.status}`);

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE start_datetime >= '2027-07-05 00:00:00' AND start_datetime < '2027-07-06 00:00:00'"
        )
      );
      ctx.assert.equal(count, 0);
    }
  });

  test({
    id: 'T2-F7-06',
    tier: 2,
    feature: 'F7',
    name: 'XSS payload in destination/title is escaped on my_bookings.php and no SQL injection',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-06');
      const xssTitle = '[E2E-T2-F7-06] <script>window.__xss=1</script>';
      const xssDest = '<img src=x onerror="window.__xss=2">';

      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);
      const res = await client.postForm(
        form.actionUrl,
        buildBookingPayload(form, {
          title: xssTitle,
          purpose: "Test' OR '1'='1",
          destination: xssDest,
          passenger_count: 2,
          start_datetime: '2027-07-06T09:00',
          end_datetime: '2027-07-06T12:00'
        })
      );
      ctx.assert.ok(res.status < 500);

      const totalBookings = Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings'));
      ctx.assert.ok(totalBookings >= 6, 'bookings table must remain intact (no SQL injection)');

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/my_bookings.php`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T2-F7-06');

      const xssTriggered = await page.evaluate(() => window.__xss);
      ctx.assert.equal(xssTriggered, undefined, 'XSS payload must be escaped and never execute');
    }
  });

  // =========================================================================
  // Feature F8: Server-Side Conflict Detection — Tier 2 (6 Tests)
  // =========================================================================

  test({
    id: 'T2-F8-01',
    tier: 2,
    feature: 'F8',
    name: 'Exact identical time range blocked on room booking',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-10');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status)
         VALUES (?, 'room', 6, 'internal', 3, ?, 'Approved meeting', '2027-07-10 09:00:00', '2027-07-10 12:00:00', 10, 3, 'approved')`,
        ['E2E-T2F801-SEED', '[E2E-T2-F8-01] Approved Seed']
      );

      const client = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, client, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const res = await client.postForm(
        form.actionUrl,
        buildBookingPayload(form, {
          room_id: 3,
          title: '[E2E-T2-F8-01] Exact Overlap',
          purpose: 'Exact collision',
          attendee_count: 8,
          start_datetime: '2027-07-10T09:00',
          end_datetime: '2027-07-10T12:00'
        })
      );
      ctx.assert.ok(res.status < 500);
      ctx.assert.ok(isRejectedResponse(res), 'Exact identical range must be rejected');
      ctx.assert.equal(hasSuccessAlert(res.text), false);

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 3 AND start_datetime >= '2027-07-10 00:00:00' AND start_datetime < '2027-07-11 00:00:00'"
        )
      );
      ctx.assert.equal(count, 1);
    }
  });

  test({
    id: 'T2-F8-02',
    tier: 2,
    feature: 'F8',
    name: 'Containment overlaps (new inside existing, existing inside new) both blocked',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-11');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status)
         VALUES (?, 'room', 6, 'internal', 3, ?, 'Long meeting', '2027-07-11 09:00:00', '2027-07-11 14:00:00', 12, 3, 'approved')`,
        ['E2E-T2F802-SEED', '[E2E-T2-F8-02] Long Seed']
      );

      const client = await loginHttpClient(ctx, 'user02');
      const attempts = [
        { label: 'inside', start: '2027-07-11T10:00', end: '2027-07-11T12:00' },
        { label: 'enclosing', start: '2027-07-11T08:00', end: '2027-07-11T16:00' }
      ];

      for (const att of attempts) {
        const form = await discoverBookingForm(ctx, client, '/book_room.php', 'book_room', [
          'room_id',
          'start_datetime'
        ]);
        const res = await client.postForm(
          form.actionUrl,
          buildBookingPayload(form, {
            room_id: 3,
            title: `[E2E-T2-F8-02] ${att.label}`,
            purpose: 'Containment overlap',
            attendee_count: 6,
            start_datetime: att.start,
            end_datetime: att.end
          })
        );
        ctx.assert.ok(res.status < 500);
        ctx.assert.ok(isRejectedResponse(res), `Expected containment (${att.label}) to be blocked`);
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 3 AND start_datetime >= '2027-07-11 00:00:00' AND start_datetime < '2027-07-12 00:00:00'"
        )
      );
      ctx.assert.equal(count, 1);
    }
  });

  test({
    id: 'T2-F8-03',
    tier: 2,
    feature: 'F8',
    name: '1-minute boundary overlaps (09:00-11:01 and 12:59-15:00 against 11:00-13:00) blocked',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-12');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status)
         VALUES (?, 'room', 6, 'internal', 3, ?, 'Midday meeting', '2027-07-12 11:00:00', '2027-07-12 13:00:00', 10, 3, 'approved')`,
        ['E2E-T2F803-SEED', '[E2E-T2-F8-03] Midday Seed']
      );

      const client = await loginHttpClient(ctx, 'user01');
      const boundaries = [
        { label: 'tail-1min', start: '2027-07-12T09:00', end: '2027-07-12T11:01' },
        { label: 'head-1min', start: '2027-07-12T12:59', end: '2027-07-12T15:00' }
      ];

      for (const b of boundaries) {
        const form = await discoverBookingForm(ctx, client, '/book_room.php', 'book_room', [
          'room_id',
          'start_datetime'
        ]);
        const res = await client.postForm(
          form.actionUrl,
          buildBookingPayload(form, {
            room_id: 3,
            title: `[E2E-T2-F8-03] ${b.label}`,
            purpose: '1-minute boundary overlap',
            attendee_count: 5,
            start_datetime: b.start,
            end_datetime: b.end
          })
        );
        ctx.assert.ok(res.status < 500);
        ctx.assert.ok(isRejectedResponse(res), `Expected 1-minute overlap (${b.label}) to be blocked`);
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 3 AND start_datetime >= '2027-07-12 00:00:00' AND start_datetime < '2027-07-13 00:00:00'"
        )
      );
      ctx.assert.equal(count, 1);
    }
  });

  test({
    id: 'T2-F8-04',
    tier: 2,
    feature: 'F8',
    name: 'Partial start/end overlaps blocked across pending_vice and pending_director statuses',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-13');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status)
         VALUES
         (?, 'room', 6, 'internal', 3, ?, 'Pending vice slot', '2027-07-13 09:00:00', '2027-07-13 11:00:00', 8, 2, 'pending_vice'),
         (?, 'room', 6, 'internal', 3, ?, 'Pending director slot', '2027-07-13 14:00:00', '2027-07-13 16:00:00', 8, 3, 'pending_director')`,
        [
          'E2E-T2F804-VICE',
          '[E2E-T2-F8-04] Pending Vice',
          'E2E-T2F804-DIR',
          '[E2E-T2-F8-04] Pending Director'
        ]
      );

      const client = await loginHttpClient(ctx, 'user02');
      const overlaps = [
        { label: 'start-overlap-vice', start: '2027-07-13T08:00', end: '2027-07-13T10:00' },
        { label: 'end-overlap-dir', start: '2027-07-13T15:00', end: '2027-07-13T17:00' }
      ];

      for (const ov of overlaps) {
        const form = await discoverBookingForm(ctx, client, '/book_room.php', 'book_room', [
          'room_id',
          'start_datetime'
        ]);
        const res = await client.postForm(
          form.actionUrl,
          buildBookingPayload(form, {
            room_id: 3,
            title: `[E2E-T2-F8-04] ${ov.label}`,
            purpose: 'Partial status overlap',
            attendee_count: 5,
            start_datetime: ov.start,
            end_datetime: ov.end
          })
        );
        ctx.assert.ok(res.status < 500);
        ctx.assert.ok(isRejectedResponse(res), `Expected partial overlap (${ov.label}) to be blocked`);
        ctx.assert.equal(hasSuccessAlert(res.text), false);
      }

      const count = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 3 AND start_datetime >= '2027-07-13 00:00:00' AND start_datetime < '2027-07-14 00:00:00'"
        )
      );
      ctx.assert.equal(count, 2);
    }
  });

  test({
    id: 'T2-F8-05',
    tier: 2,
    feature: 'F8',
    name: 'Concurrent double-POST race on same room slot inserts at most 1 row (Promise.all)',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-15');

      const client1 = await loginHttpClient(ctx, 'user01');
      const client2 = await loginHttpClient(ctx, 'user02');

      const form1 = await discoverBookingForm(ctx, client1, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);
      const form2 = await discoverBookingForm(ctx, client2, '/book_room.php', 'book_room', [
        'room_id',
        'start_datetime'
      ]);

      const payload1 = buildBookingPayload(form1, {
        room_id: 4,
        title: '[E2E-T2-F8-05] Race User01',
        purpose: 'Race check 1',
        attendee_count: 10,
        start_datetime: '2027-07-15T09:00',
        end_datetime: '2027-07-15T12:00'
      });
      const payload2 = buildBookingPayload(form2, {
        room_id: 4,
        title: '[E2E-T2-F8-05] Race User02',
        purpose: 'Race check 2',
        attendee_count: 10,
        start_datetime: '2027-07-15T09:00',
        end_datetime: '2027-07-15T12:00'
      });

      const [res1, res2] = await Promise.all([
        client1.postForm(form1.actionUrl, payload1),
        client2.postForm(form2.actionUrl, payload2)
      ]);

      ctx.assert.ok(res1.status < 500, `Race request 1 returned ${res1.status}`);
      ctx.assert.ok(res2.status < 500, `Race request 2 returned ${res2.status}`);

      const activeCount = Number(
        await ctx.db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE room_id = 4 AND start_datetime >= '2027-07-15 00:00:00' AND start_datetime < '2027-07-16 00:00:00' AND status != 'rejected'"
        )
      );
      ctx.assert.equal(activeCount, 1, `Concurrent double-POST must insert exactly 1 active row, got ${activeCount}`);

      const rejected1 = isRejectedResponse(res1);
      const rejected2 = isRejectedResponse(res2);
      ctx.assert.ok(rejected1 || rejected2, 'One of the two concurrent requests must be rejected');
      ctx.assert.ok(!rejected1 || !rejected2, 'One of the two concurrent requests must succeed');
    }
  });

  test({
    id: 'T2-F8-06',
    tier: 2,
    feature: 'F8',
    name: 'Vehicle & driver conflict boundaries (1-min overlap blocked vs back-to-back allowed)',
    fn: async (ctx) => {
      registerWindowCleanup(ctx, '2027-07-16');
      await ctx.db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, vehicle_id, driver_id, title, purpose, start_datetime, end_datetime, destination, passenger_count, current_step, status)
         VALUES (?, 'vehicle', 6, 'internal', 2, 2, ?, 'Director pending trip', '2027-07-16 11:00:00', '2027-07-16 13:00:00', 'อยุธยา', 3, 3, 'pending_director')`,
        ['E2E-T2F806-A', '[E2E-T2-F8-06] Active Vehicle 2']
      );

      const userClient = await loginHttpClient(ctx, 'user01');
      const form = await discoverBookingForm(ctx, userClient, '/book_vehicle.php', 'book_vehicle', [
        'destination',
        'passenger_count',
        'start_datetime'
      ]);

      if (form.fields.vehicle_id) {
        // Branch A: vehicle_id exposed at request time
        const resConflict = await userClient.postForm(
          form.actionUrl,
          buildBookingPayload(form, {
            title: '[E2E-T2-F8-06] 1-Min Conflict',
            purpose: 'Boundary conflict',
            destination: 'อ่างทอง',
            passenger_count: 2,
            start_datetime: '2027-07-16T10:00',
            end_datetime: '2027-07-16T11:01',
            vehicle_id: 2
          })
        );
        ctx.assert.ok(resConflict.status < 500);
        ctx.assert.ok(isRejectedResponse(resConflict), '1-min vehicle overlap must be rejected');
        ctx.assert.equal(hasSuccessAlert(resConflict.text), false);

        const formOk = await discoverBookingForm(ctx, userClient, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const resOk = await userClient.postForm(
          formOk.actionUrl,
          buildBookingPayload(formOk, {
            title: '[E2E-T2-F8-06] Back-to-Back OK',
            purpose: 'Back to back vehicle slot',
            destination: 'สระบุรี',
            passenger_count: 2,
            start_datetime: '2027-07-16T09:00',
            end_datetime: '2027-07-16T11:00',
            vehicle_id: 2
          })
        );
        ctx.assert.ok(resOk.status < 400);
        ctx.assert.equal(hasAlertType(resOk.text, ['danger', 'warning']), false);

        const rows = await ctx.db.queryJson(
          "SELECT start_datetime, end_datetime, vehicle_id FROM bookings WHERE start_datetime >= '2027-07-16 00:00:00' AND start_datetime < '2027-07-17 00:00:00' ORDER BY start_datetime ASC"
        );
        ctx.assert.equal(rows.length, 2);
        ctx.assert.equal(rows[0].end_datetime, '2027-07-16 11:00:00');
        ctx.assert.equal(Number(rows[0].vehicle_id), 2);
      } else {
        // Branch B: Step-1 assignment
        const titleConflict = '[E2E-T2-F8-06] Deferred 1-Min Conflict';
        const resB = await userClient.postForm(
          form.actionUrl,
          buildBookingPayload(form, {
            title: titleConflict,
            purpose: 'Overlap request',
            destination: 'อ่างทอง',
            passenger_count: 2,
            start_datetime: '2027-07-16T10:00',
            end_datetime: '2027-07-16T11:01'
          })
        );
        ctx.assert.ok(resB.status < 400);
        const rowB = (
          await ctx.db.queryJson('SELECT id FROM bookings WHERE title = ?', [titleConflict])
        )[0];
        ctx.assert.ok(rowB, 'Booking B should exist in pending_head');

        const formC = await discoverBookingForm(ctx, userClient, '/book_vehicle.php', 'book_vehicle', [
          'destination',
          'passenger_count',
          'start_datetime'
        ]);
        const titleOk = '[E2E-T2-F8-06] Deferred BackToBack OK';
        const resC = await userClient.postForm(
          formC.actionUrl,
          buildBookingPayload(formC, {
            title: titleOk,
            purpose: 'Back-to-back request',
            destination: 'สระบุรี',
            passenger_count: 2,
            start_datetime: '2027-07-16T09:00',
            end_datetime: '2027-07-16T11:00'
          })
        );
        ctx.assert.ok(resC.status < 400);
        const rowC = (
          await ctx.db.queryJson('SELECT id FROM bookings WHERE title = ?', [titleOk])
        )[0];
        ctx.assert.ok(rowC, 'Booking C should exist in pending_head');

        const headClient = await loginHttpClient(ctx, 'head01');
        const assignConflict = await attemptStep1VehicleAssign(ctx, headClient, rowB.id, 2, 2);
        ctx.assert.ok(assignConflict.status < 500);
        ctx.assert.ok(
          isRejectedResponse(assignConflict),
          '1-min overlapping Step-1 vehicle/driver assignment must be rejected'
        );
        const afterB = (
          await ctx.db.queryJson('SELECT vehicle_id, driver_id FROM bookings WHERE id = ?', [rowB.id])
        )[0];
        ctx.assert.equal(afterB.vehicle_id, null);
        ctx.assert.equal(afterB.driver_id, null);

        const assignOk = await attemptStep1VehicleAssign(ctx, headClient, rowC.id, 2, 2);
        ctx.assert.ok(assignOk.status < 400);
        ctx.assert.equal(isRejectedResponse(assignOk), false, 'Back-to-back vehicle assignment should succeed');
        const afterC = (
          await ctx.db.queryJson('SELECT vehicle_id, driver_id FROM bookings WHERE id = ?', [rowC.id])
        )[0];
        ctx.assert.equal(Number(afterC.vehicle_id), 2);
        ctx.assert.equal(Number(afterC.driver_id), 2);
      }
    }
  });
}
