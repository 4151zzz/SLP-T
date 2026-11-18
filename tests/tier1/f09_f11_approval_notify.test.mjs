import { test } from '../lib/harness.mjs';

const CSRF_NAMES = ['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token'];

function decodeHtmlEntities(str) {
  return String(str)
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#0*39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>');
}

function parseAttributes(attrText) {
  const attrs = {};
  const re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let match;
  while ((match = re.exec(attrText)) !== null) {
    const key = match[1].toLowerCase();
    const rawVal = match[2] ?? match[3] ?? match[4] ?? '';
    attrs[key] = decodeHtmlEntities(rawVal);
  }
  return attrs;
}

function extractCsrfToken(html) {
  if (!html || typeof html !== 'string') return null;

  const found = new Map();
  for (const m of html.matchAll(/<input\b([^>]*)>/gi)) {
    const attrs = parseAttributes(m[1]);
    if (!attrs.name) continue;
    const lowerName = attrs.name.toLowerCase();
    if (CSRF_NAMES.includes(lowerName) && !found.has(lowerName)) {
      found.set(lowerName, attrs.value ?? '');
    }
  }
  for (const name of CSRF_NAMES) {
    if (found.has(name) && found.get(name) !== '') {
      return found.get(name);
    }
  }
  for (const name of CSRF_NAMES) {
    if (found.has(name)) {
      return found.get(name);
    }
  }

  for (const m of html.matchAll(/<meta\b([^>]*)>/gi)) {
    const attrs = parseAttributes(m[1]);
    const name = (attrs.name || '').toLowerCase();
    if (/^(?:_csrf_token|csrf-token|csrf_token|_token)$/.test(name) && typeof attrs.content === 'string') {
      return attrs.content;
    }
  }
  return null;
}

function extractFormWithCsrf(html, actionHint = null) {
  if (!html || typeof html !== 'string') {
    return { action: '', method: 'POST', csrfFieldName: '_csrf_token', csrfToken: null, inputs: [] };
  }

  const forms = [];
  for (const m of html.matchAll(/<form\b([^>]*)>([\s\S]*?)<\/form>/gi)) {
    forms.push({ attrs: parseAttributes(m[1]), body: m[2] });
  }

  let selectedForm = null;
  if (forms.length > 0) {
    if (actionHint) {
      const hint = String(actionHint).toLowerCase();
      selectedForm = forms.find((f) => (f.attrs.action || '').toLowerCase().includes(hint)) || null;
    }
    if (!selectedForm) {
      selectedForm = forms.find((f) => !(f.attrs.action || '').toLowerCase().includes('logout.php')) || forms[0];
    }
  }

  const scopeHtml = selectedForm ? selectedForm.body : html;
  const action = selectedForm?.attrs.action ?? '';
  const method = (selectedForm?.attrs.method || 'GET').toUpperCase();

  const inputs = [];
  for (const m of scopeHtml.matchAll(/<(input|select|textarea)\b([^>]*)>/gi)) {
    const tag = m[1].toLowerCase();
    const attrs = parseAttributes(m[2]);
    if (!attrs.name) continue;
    inputs.push({
      tag,
      type: (attrs.type || (tag === 'input' ? 'text' : tag)).toLowerCase(),
      name: attrs.name,
      value: attrs.value ?? ''
    });
  }

  let csrfFieldName = null;
  let csrfToken = null;
  for (const name of CSRF_NAMES) {
    const inp = inputs.find((x) => x.name.toLowerCase() === name);
    if (inp) {
      csrfFieldName = inp.name;
      csrfToken = inp.value;
      break;
    }
  }

  if (csrfToken === null) {
    csrfToken = extractCsrfToken(html);
    if (!csrfFieldName) csrfFieldName = '_csrf_token';
  }

  return { action, method, csrfFieldName, csrfToken, inputs };
}

let tempSeq = 0;

function nextFutureSlot(dayOffset = 0) {
  tempSeq += 1;
  const dayNum = ((tempSeq + dayOffset) % 25) + 1;
  const dd = String(dayNum).padStart(2, '0');
  const startHour = 8 + (tempSeq % 8);
  const sh = String(startHour).padStart(2, '0');
  const eh = String(startHour + 2).padStart(2, '0');
  return {
    date: `2030-07-${dd}`,
    startTime: `${sh}:00`,
    endTime: `${eh}:00`,
    startDatetime: `2030-07-${dd} ${sh}:00:00`,
    endDatetime: `2030-07-${dd} ${eh}:00:00`
  };
}

function makeRef(prefix = 'TST-F9') {
  tempSeq += 1;
  const ts = Date.now().toString(36).slice(-6).toUpperCase();
  const rnd = Math.random().toString(36).slice(2, 5).toUpperCase();
  return `${prefix}-${ts}${tempSeq}${rnd}`.slice(0, 30);
}

async function createTempBooking(ctx, opts = {}) {
  const slot = nextFutureSlot();
  const referenceCode = opts.referenceCode || makeRef('TST-F9');
  const bookingType = opts.bookingType || 'room';
  const requesterId = opts.requesterId ?? 6;
  const requesterType = opts.requesterType || (requesterId === 8 ? 'external' : 'internal');
  const roomId = bookingType === 'room' ? (opts.roomId ?? 1) : null;
  const vehicleId = bookingType === 'vehicle' ? (opts.vehicleId ?? null) : null;
  const driverId = bookingType === 'vehicle' ? (opts.driverId ?? null) : null;
  const title = opts.title || `ทดสอบคำขอชั่วคราว ${referenceCode}`;
  const purpose = opts.purpose || 'ทดสอบระบบพิจารณาอนุมัติและการแจ้งเตือน';
  const startDatetime = opts.startDatetime || slot.startDatetime;
  const endDatetime = opts.endDatetime || slot.endDatetime;
  const attendeeCount = bookingType === 'room' ? (opts.attendeeCount ?? 12) : null;
  const destination = bookingType === 'vehicle' ? (opts.destination || 'ศูนย์ราชการทดสอบ') : null;
  const passengerCount = bookingType === 'vehicle' ? (opts.passengerCount ?? 4) : null;
  const status = opts.status || 'pending_head';
  const currentStep = opts.currentStep ?? (
    status === 'pending_vice' ? 2 : (status === 'pending_director' || status === 'approved' ? 3 : 1)
  );
  const rejectionReason = opts.rejectionReason ?? null;

  const res = await ctx.db.execSql(
    `INSERT INTO bookings (
      reference_code, booking_type, requester_id, requester_type,
      room_id, vehicle_id, driver_id, title, purpose,
      start_datetime, end_datetime, attendee_count, equipment_requested,
      permission_letter_path, permission_letter_original_name,
      destination, passenger_count, current_step, status, rejection_reason
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?)`,
    [
      referenceCode, bookingType, requesterId, requesterType,
      roomId, vehicleId, driverId, title, purpose,
      startDatetime, endDatetime, attendeeCount,
      destination, passengerCount, currentStep, status, rejectionReason
    ]
  );

  const bookingId = Number(res.lastInsertId);
  ctx.onCleanup(async () => {
    await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [bookingId]);
    await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [bookingId]);
    await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [bookingId]);
  });

  if (opts.seedStepLogs !== false && (currentStep >= 2 || status === 'approved')) {
    await ctx.db.execSql(
      `INSERT INTO approval_logs (booking_id, step_number, step_role, approver_id, action, comment, signer_name, signer_position, digital_signature, acted_at)
       VALUES (?, 1, 'head', 3, 'approved', 'หัวหน้างานเห็นชอบเบื้องต้น', 'นางสาวสมหญิง หัวหน้างาน', 'หัวหน้ากลุ่มงานบริหารทั่วไป', 'นางสาวสมหญิง หัวหน้างาน', NOW())`,
      [bookingId]
    );
  }
  if (opts.seedStepLogs !== false && (currentStep >= 3 || status === 'approved')) {
    await ctx.db.execSql(
      `INSERT INTO approval_logs (booking_id, step_number, step_role, approver_id, action, comment, signer_name, signer_position, digital_signature, acted_at)
       VALUES (?, 2, 'vice_director', 4, 'approved', 'รองผู้อำนวยการเห็นชอบ', 'นายวิชัย รองผู้อำนวยการ', 'รองผู้อำนวยการฝ่ายบริหาร', 'นายวิชัย รองผู้อำนวยการ', NOW())`,
      [bookingId]
    );
  }

  return { id: bookingId, referenceCode, title, startDatetime, endDatetime };
}

function altVerb(verb) {
  const map = {
    approve: 'approved',
    approved: 'approve',
    reject: 'rejected',
    rejected: 'reject',
    return: 'returned',
    returned: 'return',
    assign_vehicle: 'assigned_vehicle',
    assigned_vehicle: 'assign_vehicle'
  };
  return map[verb] || verb;
}

async function detectApprovalContract(client) {
  const res = await client.get('/approvals.php');
  const html = res.text || '';
  let csrfToken = extractCsrfToken(html);
  if (!csrfToken) {
    const idxRes = await client.get('/index.php');
    csrfToken = extractCsrfToken(idxRes.text || '');
  }

  const form = extractFormWithCsrf(html, 'approval');
  const inputs = form.inputs || [];

  const actionField = inputs.find((i) => i.name.toLowerCase() === 'decision') ? 'decision' : 'action';
  const commentField = inputs.find((i) => i.name.toLowerCase() === 'remarks') ? 'remarks' : 'comment';
  const bookingIdField = inputs.find((i) => i.name.toLowerCase() === 'id') ? 'id' : 'booking_id';

  const detectVerb = (shortVerb, pastVerb) => {
    if (new RegExp(`(?:value|data-action)=["']${pastVerb}["']`, 'i').test(html)) return pastVerb;
    if (new RegExp(`(?:value|data-action)=["']${shortVerb}["']`, 'i').test(html)) return shortVerb;
    if (new RegExp(`action\\s*[:=]\\s*["']${pastVerb}["']`, 'i').test(html)) return pastVerb;
    return shortVerb;
  };

  return {
    csrfToken,
    csrfFieldName: form.csrfFieldName || '_csrf_token',
    actionField,
    commentField,
    bookingIdField,
    verbs: {
      approve: detectVerb('approve', 'approved'),
      reject: detectVerb('reject', 'rejected'),
      return: detectVerb('return', 'returned'),
      assign_vehicle: detectVerb('assign_vehicle', 'assigned_vehicle')
    }
  };
}

async function postApprovalAction(client, params, opts = {}) {
  const contract = opts.skipDetect
    ? {
        csrfToken: null,
        csrfFieldName: '_csrf_token',
        actionField: 'action',
        commentField: 'comment',
        bookingIdField: 'booking_id',
        verbs: { approve: 'approve', reject: 'reject', return: 'return', assign_vehicle: 'assign_vehicle' }
      }
    : await detectApprovalContract(client);

  const sendWithVerb = async (verbValue) => {
    const payload = {
      [contract.bookingIdField]: String(params.booking_id),
      [contract.actionField]: verbValue
    };
    if (params.comment !== undefined) {
      payload[contract.commentField] = params.comment;
    }
    if (params.vehicle_id !== undefined && params.vehicle_id !== null) {
      payload.vehicle_id = String(params.vehicle_id);
    }
    if (params.driver_id !== undefined && params.driver_id !== null) {
      payload.driver_id = String(params.driver_id);
    }
    if (params.signer_name !== undefined) {
      payload.signer_name = params.signer_name;
    }
    if (params.digital_signature !== undefined) {
      payload.digital_signature = params.digital_signature;
    }

    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };

    if (!opts.omitCsrf) {
      const tokenVal = opts.invalidCsrf !== undefined ? opts.invalidCsrf : contract.csrfToken;
      if (tokenVal !== null && tokenVal !== undefined) {
        payload[contract.csrfFieldName] = tokenVal;
        payload._csrf_token = tokenVal;
        payload.csrf_token = tokenVal;
        headers['X-CSRF-Token'] = tokenVal;
      }
    }

    const res = await client.postForm('/api/approval_action.php', payload, {
      redirect: 'manual',
      headers
    });

    let json = null;
    try {
      json = JSON.parse(res.text);
    } catch {
      json = null;
    }
    return { status: res.status, ok: res.ok, json, text: res.text, location: res.location };
  };

  const initialVerb = contract.verbs[params.action] || params.action;
  let result = await sendWithVerb(initialVerb);

  if (
    opts.allowVerbFallback !== false &&
    (result.status === 400 || result.status === 422 || (result.json && result.json.ok === false)) &&
    /action|invalid|unsupported|unknown/i.test(result.text || '')
  ) {
    const swapped = altVerb(initialVerb);
    if (swapped !== initialVerb) {
      result = await sendWithVerb(swapped);
    }
  }

  return result;
}

async function captureNotifBaseline(ctx) {
  const beforeLogLen = ctx.db.readNotificationLog().length;
  const beforeMaxId = Number((await ctx.db.scalar('SELECT COALESCE(MAX(id), 0) FROM notification_logs')) || 0);
  return { beforeLogLen, beforeMaxId };
}

async function diffNotifs(ctx, baseline, bookingId = null) {
  const allFileEntries = ctx.db.readNotificationLog();
  const newFileEntries = allFileEntries.slice(baseline.beforeLogLen);
  const newDbRows = bookingId !== null
    ? await ctx.db.queryJson(
        'SELECT * FROM notification_logs WHERE id > ? AND booking_id = ? ORDER BY id ASC',
        [baseline.beforeMaxId, bookingId]
      )
    : await ctx.db.queryJson(
        'SELECT * FROM notification_logs WHERE id > ? ORDER BY id ASC',
        [baseline.beforeMaxId]
      );
  return { newFileEntries, newDbRows };
}

async function submitRoomBookingHttp(ctx, client, overrides = {}) {
  const slot = nextFutureSlot();
  const title = overrides.title || `ทดสอบแจ้งเตือนห้อง ${makeRef('RM')}`;
  const getRes = await client.get('/book_room.php');
  const form = extractFormWithCsrf(getRes.text, 'book_room');

  const fields = {};
  for (const inp of form.inputs) {
    if (inp.type === 'hidden' && !CSRF_NAMES.includes(inp.name.toLowerCase())) {
      fields[inp.name] = inp.value;
    }
  }
  if (form.csrfToken) {
    fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
    fields._csrf_token = form.csrfToken;
  }

  const hasField = (name) => form.inputs.some((i) => i.name.toLowerCase() === name);
  const getInput = (name) => form.inputs.find((i) => i.name.toLowerCase() === name);

  fields.room_id = String(overrides.room_id ?? 1);
  fields[hasField('subject') ? 'subject' : 'title'] = title;
  fields[hasField('reason') ? 'reason' : 'purpose'] = overrides.purpose || 'ทดสอบการแจ้งเตือนการจองห้องประชุม';
  fields[hasField('attendees') ? 'attendees' : 'attendee_count'] = String(overrides.attendee_count ?? 10);

  if (hasField('start_date') && hasField('start_time')) {
    fields.start_date = overrides.date || slot.date;
    fields.start_time = overrides.startTime || slot.startTime;
    fields.end_date = overrides.date || slot.date;
    fields.end_time = overrides.endTime || slot.endTime;
  } else {
    const startInp = getInput('start_datetime');
    const sep = startInp?.type === 'datetime-local' ? 'T' : ' ';
    const sec = startInp?.type === 'datetime-local' ? '' : ':00';
    fields.start_datetime = overrides.start_datetime || `${slot.date}${sep}${slot.startTime}${sec}`;
    fields.end_datetime = overrides.end_datetime || `${slot.date}${sep}${slot.endTime}${sec}`;
  }

  const actionUrl = form.action ? new URL(form.action, getRes.url).pathname : '/book_room.php';
  const postRes = await client.postMultipart(actionUrl, fields, {});
  ctx.assert.ok(postRes.status < 400, `Expected room booking submit < 400, got ${postRes.status}`);

  const rows = await ctx.db.queryJson(
    'SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1',
    [title]
  );
  ctx.assert.equal(rows.length, 1, `Expected booking row inserted for "${title}"`);
  const booking = rows[0];
  ctx.onCleanup(async () => {
    await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [booking.id]);
    await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [booking.id]);
    await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [booking.id]);
  });
  return booking;
}

async function submitVehicleBookingHttp(ctx, client, overrides = {}) {
  const slot = nextFutureSlot();
  const title = overrides.title || `ทดสอบแจ้งเตือนรถ ${makeRef('VH')}`;
  const getRes = await client.get('/book_vehicle.php');
  const form = extractFormWithCsrf(getRes.text, 'book_vehicle');

  const fields = {};
  for (const inp of form.inputs) {
    if (inp.type === 'hidden' && !CSRF_NAMES.includes(inp.name.toLowerCase())) {
      fields[inp.name] = inp.value;
    }
  }
  if (form.csrfToken) {
    fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
    fields._csrf_token = form.csrfToken;
  }

  const hasField = (name) => form.inputs.some((i) => i.name.toLowerCase() === name);
  const getInput = (name) => form.inputs.find((i) => i.name.toLowerCase() === name);

  fields[hasField('subject') ? 'subject' : 'title'] = title;
  fields[hasField('reason') ? 'reason' : 'purpose'] = overrides.purpose || 'ทดสอบการแจ้งเตือนการจองยานพาหนะ';
  fields.destination = overrides.destination || 'สำนักงานปลัดกระทรวง';
  fields[hasField('passengers') ? 'passengers' : 'passenger_count'] = String(overrides.passenger_count ?? 4);

  if (hasField('start_date') && hasField('start_time')) {
    fields.start_date = overrides.date || slot.date;
    fields.start_time = overrides.startTime || slot.startTime;
    fields.end_date = overrides.date || slot.date;
    fields.end_time = overrides.endTime || slot.endTime;
  } else {
    const startInp = getInput('start_datetime');
    const sep = startInp?.type === 'datetime-local' ? 'T' : ' ';
    const sec = startInp?.type === 'datetime-local' ? '' : ':00';
    fields.start_datetime = overrides.start_datetime || `${slot.date}${sep}${slot.startTime}${sec}`;
    fields.end_datetime = overrides.end_datetime || `${slot.date}${sep}${slot.endTime}${sec}`;
  }

  const actionUrl = form.action ? new URL(form.action, getRes.url).pathname : '/book_vehicle.php';
  const postRes = await client.postForm(actionUrl, fields);
  ctx.assert.ok(postRes.status < 400, `Expected vehicle booking submit < 400, got ${postRes.status}`);

  const rows = await ctx.db.queryJson(
    'SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1',
    [title]
  );
  ctx.assert.equal(rows.length, 1, `Expected vehicle booking row inserted for "${title}"`);
  const booking = rows[0];
  ctx.onCleanup(async () => {
    await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [booking.id]);
    await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [booking.id]);
    await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [booking.id]);
  });
  return booking;
}

function assertActionSucceeded(ctx, res, label) {
  ctx.assert.ok(
    (res.status >= 200 && res.status < 300) || res.status === 302,
    `${label}: expected 2xx or 302, got ${res.status} (${res.text.slice(0, 200)})`
  );
  if (res.json && typeof res.json.ok === 'boolean') {
    ctx.assert.equal(res.json.ok, true, `${label}: expected JSON ok=true, got ${res.text.slice(0, 200)}`);
  }
}

// ============================================================================
// F9 — Multi-Step Sequential Approval (Tier 1: 6 tests)
// ============================================================================

test({
  id: 'T1-F9-01',
  tier: 1,
  feature: 'F9',
  name: 'Step 1 head approves pending_head room booking to pending_vice',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_head', currentStep: 1 });
    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const comment = 'หัวหน้างานตรวจสอบแล้ว เห็นชอบให้ใช้ห้องประชุม';

    const res = await postApprovalAction(head, { booking_id: b.id, action: 'approve', comment });
    assertActionSucceeded(ctx, res, 'T1-F9-01');

    const [row] = await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [b.id]);
    ctx.assert.equal(row.status, 'pending_vice');
    ctx.assert.equal(Number(row.current_step), 2);

    const logs = await ctx.db.queryJson('SELECT * FROM approval_logs WHERE booking_id = ? ORDER BY id ASC', [b.id]);
    ctx.assert.equal(logs.length, 1);
    ctx.assert.equal(Number(logs[0].step_number), 1);
    ctx.assert.equal(logs[0].step_role, 'head');
    ctx.assert.equal(Number(logs[0].approver_id), 3);
    ctx.assert.equal(logs[0].action, 'approved');
    ctx.assert.equal(logs[0].comment, comment);
  }
});

test({
  id: 'T1-F9-02',
  tier: 1,
  feature: 'F9',
  name: 'Step 2 vice_director approves pending_vice booking to pending_director',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 7, roomId: 3, status: 'pending_vice', currentStep: 2 });
    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    const comment = 'รองผู้อำนวยการพิจารณาแล้ว เสนอผู้อำนวยการ';

    const res = await postApprovalAction(vice, { booking_id: b.id, action: 'approve', comment });
    assertActionSucceeded(ctx, res, 'T1-F9-02');

    const [row] = await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [b.id]);
    ctx.assert.equal(row.status, 'pending_director');
    ctx.assert.equal(Number(row.current_step), 3);

    const logs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? AND step_role = ? ORDER BY id DESC',
      [b.id, 'vice_director']
    );
    ctx.assert.equal(logs.length, 1);
    ctx.assert.equal(Number(logs[0].step_number), 2);
    ctx.assert.equal(Number(logs[0].approver_id), 4);
    ctx.assert.equal(logs[0].action, 'approved');
  }
});

test({
  id: 'T1-F9-03',
  tier: 1,
  feature: 'F9',
  name: 'Step 3 director grants final approved status',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_director', currentStep: 3 });
    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    const comment = 'ผู้อำนวยการอนุมัติให้ดำเนินการตามคำขอ';

    const res = await postApprovalAction(director, { booking_id: b.id, action: 'approve', comment });
    assertActionSucceeded(ctx, res, 'T1-F9-03');

    const [row] = await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [b.id]);
    ctx.assert.equal(row.status, 'approved');
    ctx.assert.equal(Number(row.current_step), 3);

    const logs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? AND step_role = ? ORDER BY id DESC',
      [b.id, 'director']
    );
    ctx.assert.equal(logs.length, 1);
    ctx.assert.equal(Number(logs[0].step_number), 3);
    ctx.assert.equal(Number(logs[0].approver_id), 5);
    ctx.assert.equal(logs[0].action, 'approved');
  }
});

test({
  id: 'T1-F9-04',
  tier: 1,
  feature: 'F9',
  name: 'Step 1 staff and head assign vehicle_id & driver_id on vehicle booking and forward',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, {
      bookingType: 'vehicle',
      requesterId: 6,
      vehicleId: null,
      driverId: null,
      status: 'pending_head',
      currentStep: 1
    });

    const staff = await ctx.http.login('staff01', ctx.env.seedPassword);
    const assignRes = await postApprovalAction(staff, {
      booking_id: b.id,
      action: 'assign_vehicle',
      vehicle_id: 1,
      driver_id: 1,
      comment: 'จัดเตรียมรถตู้ VEH-01 และพนักงานขับรถ DRV-01'
    });
    assertActionSucceeded(ctx, assignRes, 'T1-F9-04 staff assign');

    const [afterAssign] = await ctx.db.queryJson('SELECT vehicle_id, driver_id, status FROM bookings WHERE id = ?', [b.id]);
    ctx.assert.equal(Number(afterAssign.vehicle_id), 1);
    ctx.assert.equal(Number(afterAssign.driver_id), 1);
    ctx.assert.equal(afterAssign.status, 'pending_head');

    const assignLogs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? AND action = ?',
      [b.id, 'assigned_vehicle']
    );
    ctx.assert.ok(assignLogs.length >= 1, 'Expected assigned_vehicle log entry from staff01');
    ctx.assert.equal(Number(assignLogs[0].approver_id), 2);

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const headRes = await postApprovalAction(head, {
      booking_id: b.id,
      action: 'approve',
      vehicle_id: 1,
      driver_id: 1,
      comment: 'หัวหน้างานตรวจสอบรถและคนขับแล้ว เห็นชอบ'
    });
    assertActionSucceeded(ctx, headRes, 'T1-F9-04 head forward');

    const [afterHead] = await ctx.db.queryJson(
      'SELECT vehicle_id, driver_id, status, current_step FROM bookings WHERE id = ?',
      [b.id]
    );
    ctx.assert.equal(afterHead.status, 'pending_vice');
    ctx.assert.equal(Number(afterHead.current_step), 2);
    ctx.assert.equal(Number(afterHead.vehicle_id), 1);
    ctx.assert.equal(Number(afterHead.driver_id), 1);
  }
});

test({
  id: 'T1-F9-05',
  tier: 1,
  feature: 'F9',
  name: 'Step 2 vice_director returns to pending_head and Step 3 director rejects with reason',
  fn: async (ctx) => {
    const bReturn = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_vice', currentStep: 2 });
    const bReject = await createTempBooking(ctx, { bookingType: 'room', requesterId: 7, roomId: 3, status: 'pending_director', currentStep: 3 });

    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    const returnRes = await postApprovalAction(vice, {
      booking_id: bReturn.id,
      action: 'return',
      comment: 'กรุณาปรับปรุงรายละเอียดผู้เข้าร่วมประชุม'
    });
    assertActionSucceeded(ctx, returnRes, 'T1-F9-05 return');

    const [retRow] = await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [bReturn.id]);
    ctx.assert.equal(retRow.status, 'pending_head');
    ctx.assert.equal(Number(retRow.current_step), 1);

    const retLogs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? AND action = ?',
      [bReturn.id, 'returned']
    );
    ctx.assert.equal(retLogs.length, 1);

    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    const rejectReason = 'งบประมาณไม่เพียงพอ';
    const rejectRes = await postApprovalAction(director, {
      booking_id: bReject.id,
      action: 'reject',
      comment: rejectReason
    });
    assertActionSucceeded(ctx, rejectRes, 'T1-F9-05 reject');

    const [rejRow] = await ctx.db.queryJson('SELECT status, rejection_reason FROM bookings WHERE id = ?', [bReject.id]);
    ctx.assert.equal(rejRow.status, 'rejected');
    ctx.assert.ok((rejRow.rejection_reason || '').includes(rejectReason), 'Expected rejection_reason persisted');

    const rejLogs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? AND action = ?',
      [bReject.id, 'rejected']
    );
    ctx.assert.equal(rejLogs.length, 1);
  }
});

test({
  id: 'T1-F9-06',
  tier: 1,
  feature: 'F9',
  name: 'approval_logs records signer_name, digital_signature, comment, acted_at and approvals.php UI loads',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_head', currentStep: 1 });

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'head01', ctx.env.seedPassword);
    const uiRes = await page.goto(`${ctx.baseUrl}/approvals.php`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(uiRes.status(), 200);
    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(
      bodyText.includes(b.referenceCode) || bodyText.includes(b.title),
      `Expected approvals.php to display pending booking ${b.referenceCode}`
    );
    errors.assertNoErrors('T1-F9-06 approvals.php');

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const explicitComment = 'ตรวจสอบเอกสารครบถ้วน ลงนามรับรอง';
    const res = await postApprovalAction(head, {
      booking_id: b.id,
      action: 'approve',
      comment: explicitComment,
      signer_name: 'นางสาวสมหญิง หัวหน้างาน',
      digital_signature: 'นางสาวสมหญิง หัวหน้างาน'
    });
    assertActionSucceeded(ctx, res, 'T1-F9-06');

    const logs = await ctx.db.queryJson(
      'SELECT signer_name, digital_signature, comment, acted_at, TIMESTAMPDIFF(SECOND, acted_at, NOW()) AS age_sec FROM approval_logs WHERE booking_id = ?',
      [b.id]
    );
    ctx.assert.equal(logs.length, 1);
    ctx.assert.ok(String(logs[0].signer_name || '').trim().length > 0, 'Expected non-empty signer_name');
    ctx.assert.ok(String(logs[0].digital_signature || '').trim().length > 0, 'Expected non-empty digital_signature');
    ctx.assert.equal(logs[0].comment, explicitComment);
    ctx.assert.ok(logs[0].acted_at !== null && Math.abs(Number(logs[0].age_sec)) <= 120, 'Expected acted_at near NOW()');
  }
});

// ============================================================================
// F10 — Timeline / Stepper Status Tracking (Tier 1: 6 tests)
// ============================================================================

test({
  id: 'T1-F10-01',
  tier: 1,
  feature: 'F10',
  name: 'booking_detail.php renders 4-step stepper for internal owner (BK-001)',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
    const res = await page.goto(`${ctx.baseUrl}/booking_detail.php?id=1`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(res.status(), 200);
    errors.assertNoErrors('T1-F10-01');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(bodyText.includes('BK-001'), 'Expected reference BK-001');
    ctx.assert.ok(bodyText.includes('ประชุมวางแผนจัดซื้อจัดจ้างประจำเดือน'), 'Expected BK-001 title');

    const steps = page.locator('.stepper .stepper-step');
    const stepCount = await steps.count();
    ctx.assert.equal(stepCount, 4, `Expected 4 stepper steps, got ${stepCount}`);

    const completedCount = await page.locator('.stepper .stepper-step.is-completed').count();
    const rejectedCount = await page.locator('.stepper .stepper-step.is-rejected').count();
    ctx.assert.ok(completedCount >= 3 && rejectedCount === 0, `Expected completed stepper for approved BK-001 (completed=${completedCount}, rejected=${rejectedCount})`);
  }
});

test({
  id: 'T1-F10-02',
  tier: 1,
  feature: 'F10',
  name: 'booking_detail.php renders 4-step stepper for external owner (BK-002)',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'ext01', ctx.env.seedPassword);
    const res = await page.goto(`${ctx.baseUrl}/booking_detail.php?id=2`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(res.status(), 200);
    errors.assertNoErrors('T1-F10-02');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(bodyText.includes('BK-002'), 'Expected reference BK-002');
    ctx.assert.ok(bodyText.includes('สมาคมส่งเสริมการศึกษาภายนอก'), 'Expected external organization name');

    const stepCount = await page.locator('.stepper .stepper-step').count();
    ctx.assert.equal(stepCount, 4, `Expected 4 stepper steps for external booking, got ${stepCount}`);
  }
});

test({
  id: 'T1-F10-03',
  tier: 1,
  feature: 'F10',
  name: 'Stepper shows current step, completed steps, reviewer comments and timestamps (BK-005)',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
    const res = await page.goto(`${ctx.baseUrl}/booking_detail.php?id=5`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(res.status(), 200);
    errors.assertNoErrors('T1-F10-03');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(bodyText.includes('นางสาวสมหญิง หัวหน้างาน'), 'Expected Step 1 signer name');
    ctx.assert.ok(bodyText.includes('นายวิชัย รองผู้อำนวยการ'), 'Expected Step 2 signer name');
    ctx.assert.ok(bodyText.includes('จัดรถเก๋ง VEH-02 และพนักงานขับรถ DRV-02 เรียบร้อยแล้ว'), 'Expected Step 1 comment');
    ctx.assert.ok(bodyText.includes('เห็นชอบการเดินทางไปราชการ เสนอผู้อำนวยการอนุมัติ'), 'Expected Step 2 comment');
    ctx.assert.ok(bodyText.includes('11:00') || bodyText.includes('15:40') || /25\d{2}/.test(bodyText), 'Expected approval timestamps');

    const currentSteps = await page.locator('.stepper .stepper-step.is-current').count();
    const completedSteps = await page.locator('.stepper .stepper-step.is-completed').count();
    ctx.assert.ok(currentSteps >= 1, 'Expected at least 1 .is-current step for pending_director');
    ctx.assert.ok(completedSteps >= 2, 'Expected at least 2 .is-completed steps for pending_director');
  }
});

test({
  id: 'T1-F10-04',
  tier: 1,
  feature: 'F10',
  name: 'Assigned vehicle and driver details displayed on vehicle booking_detail.php (BK-005)',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
    const res = await page.goto(`${ctx.baseUrl}/booking_detail.php?id=5`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(res.status(), 200);
    errors.assertNoErrors('T1-F10-04');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(
      bodyText.includes('VEH-02') || bodyText.includes('กฉ-4589 กทม') || bodyText.includes('Toyota Camry'),
      'Expected assigned vehicle details on BK-005'
    );
    ctx.assert.ok(
      bodyText.includes('นายประเสริฐ ปลอดภัย') || bodyText.includes('081-200-0002'),
      'Expected assigned driver details on BK-005'
    );
  }
});

test({
  id: 'T1-F10-05',
  tier: 1,
  feature: 'F10',
  name: 'booking_detail.php links to print_memo.php?id=1 without fetching M5 route',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01', ctx.env.seedPassword);
    const res = await page.goto(`${ctx.baseUrl}/booking_detail.php?id=1`, { waitUntil: 'domcontentloaded' });
    ctx.assert.equal(res.status(), 200);
    errors.assertNoErrors('T1-F10-05');

    const printLink = page.locator('a[href*="print_memo.php"][href*="id=1"]').first();
    ctx.assert.ok((await printLink.count()) >= 1, 'Expected link to print_memo.php?id=1');
    ctx.assert.equal(await printLink.isVisible(), true, 'Expected print_memo link to be visible');
    const href = await printLink.getAttribute('href');
    ctx.assert.ok(href && href.includes('print_memo.php') && href.includes('id=1'));
  }
});

test({
  id: 'T1-F10-06',
  tier: 1,
  feature: 'F10',
  name: 'Approvers and staff/admin can view booking_detail.php stepper',
  fn: async (ctx) => {
    for (const username of ['head01', 'vice01', 'director01', 'staff01', 'admin']) {
      const client = await ctx.http.login(username, ctx.env.seedPassword);
      const res = await client.get('/booking_detail.php?id=1');
      ctx.assert.equal(res.status, 200, `Expected 200 for ${username} on booking_detail.php?id=1`);
      ctx.assert.ok(res.text.includes('BK-001'), `Expected BK-001 for ${username}`);
      ctx.assert.ok(res.text.includes('stepper-step'), `Expected stepper markup for ${username}`);
    }
  }
});

// ============================================================================
// F11 — Notification Dispatcher (Tier 1: 6 tests)
// ============================================================================

test({
  id: 'T1-F11-01',
  tier: 1,
  feature: 'F11',
  name: 'New room booking submission logs notification for head',
  fn: async (ctx) => {
    const user = await ctx.http.login('user01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);

    const booking = await submitRoomBookingHttp(ctx, user);
    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, booking.id);

    ctx.assert.ok(
      newDbRows.some((r) => r.target_role === 'head'),
      `Expected notification_logs row targeting head for booking ${booking.id}, got ${JSON.stringify(newDbRows)}`
    );
    ctx.assert.ok(
      newFileEntries.some(
        (e) =>
          (Number(e.booking_id) === Number(booking.id) || JSON.stringify(e).includes(booking.reference_code)) &&
          JSON.stringify(e).includes('head')
      ),
      `Expected notifications.log entry for booking ${booking.id} targeting head`
    );
  }
});

test({
  id: 'T1-F11-02',
  tier: 1,
  feature: 'F11',
  name: 'New vehicle booking submission logs notifications for both head and staff',
  fn: async (ctx) => {
    const user = await ctx.http.login('user01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);

    const booking = await submitVehicleBookingHttp(ctx, user);
    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, booking.id);

    ctx.assert.ok(newDbRows.some((r) => r.target_role === 'head'), 'Expected DB notification for head');
    ctx.assert.ok(newDbRows.some((r) => r.target_role === 'staff'), 'Expected DB notification for staff');

    const matchingFile = newFileEntries.filter(
      (e) => Number(e.booking_id) === Number(booking.id) || JSON.stringify(e).includes(booking.reference_code)
    );
    ctx.assert.ok(
      matchingFile.some((e) => (e.target_role || '') === 'head' || JSON.stringify(e).includes('head')),
      'Expected notifications.log entry for head'
    );
    ctx.assert.ok(
      matchingFile.some((e) => (e.target_role || '') === 'staff' || JSON.stringify(e).includes('staff')),
      'Expected notifications.log entry for staff'
    );
  }
});

test({
  id: 'T1-F11-03',
  tier: 1,
  feature: 'F11',
  name: 'Step 1 forward logs notification for vice_director',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_head', currentStep: 1 });
    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);

    const res = await postApprovalAction(head, { booking_id: b.id, action: 'approve', comment: 'ส่งต่อรองผู้อำนวยการ' });
    assertActionSucceeded(ctx, res, 'T1-F11-03');

    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, b.id);
    ctx.assert.ok(newDbRows.some((r) => r.target_role === 'vice_director'), 'Expected DB notification for vice_director');
    ctx.assert.ok(
      newFileEntries.some((e) => Number(e.booking_id) === b.id && (e.target_role === 'vice_director' || JSON.stringify(e).includes('vice_director'))),
      'Expected notifications.log entry for vice_director'
    );
  }
});

test({
  id: 'T1-F11-04',
  tier: 1,
  feature: 'F11',
  name: 'Step 2 forward logs notification for director',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_vice', currentStep: 2 });
    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);

    const res = await postApprovalAction(vice, { booking_id: b.id, action: 'approve', comment: 'ส่งต่อผู้อำนวยการ' });
    assertActionSucceeded(ctx, res, 'T1-F11-04');

    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, b.id);
    ctx.assert.ok(newDbRows.some((r) => r.target_role === 'director'), 'Expected DB notification for director');
    ctx.assert.ok(
      newFileEntries.some((e) => Number(e.booking_id) === b.id && (e.target_role === 'director' || JSON.stringify(e).includes('director'))),
      'Expected notifications.log entry for director'
    );
  }
});

test({
  id: 'T1-F11-05',
  tier: 1,
  feature: 'F11',
  name: 'Step 3 final approval logs notification back to requester',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 6, roomId: 1, status: 'pending_director', currentStep: 3 });
    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);

    const res = await postApprovalAction(director, { booking_id: b.id, action: 'approve', comment: 'อนุมัติคำขอเรียบร้อย' });
    assertActionSucceeded(ctx, res, 'T1-F11-05');

    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, b.id);
    ctx.assert.ok(
      newDbRows.some((r) => Number(r.target_user_id) === 6 && String(r.event_name || '').includes('approved')),
      'Expected DB notification back to requester user01 (id=6) with approved event'
    );
    ctx.assert.ok(
      newFileEntries.some((e) => Number(e.booking_id) === b.id && String(e.event || e.event_name || '').includes('approved')),
      'Expected notifications.log entry for approved decision'
    );
  }
});

test({
  id: 'T1-F11-06',
  tier: 1,
  feature: 'F11',
  name: 'Rejection logs notification back to requester with rejection reason',
  fn: async (ctx) => {
    const b = await createTempBooking(ctx, { bookingType: 'room', requesterId: 8, requesterType: 'external', roomId: 1, status: 'pending_head', currentStep: 1 });
    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const baseline = await captureNotifBaseline(ctx);
    const reason = 'เอกสารแนบไม่ชัดเจน';

    const res = await postApprovalAction(head, { booking_id: b.id, action: 'reject', comment: reason });
    assertActionSucceeded(ctx, res, 'T1-F11-06');

    const { newFileEntries, newDbRows } = await diffNotifs(ctx, baseline, b.id);
    ctx.assert.ok(
      newDbRows.some((r) => Number(r.target_user_id) === 8 && String(r.message || '').includes(reason)),
      'Expected DB notification to ext01 (id=8) containing rejection reason'
    );
    ctx.assert.ok(
      newFileEntries.some((e) => Number(e.booking_id) === b.id && String(e.message || JSON.stringify(e)).includes(reason)),
      'Expected notifications.log entry containing rejection reason'
    );
  }
});
