import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from '../lib/harness.mjs';

const VALID_1X1_PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const VALID_MINIMAL_PDF =
  '%PDF-1.4\n' +
  '1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n' +
  '2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n' +
  '3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n' +
  'xref\n0 4\n' +
  '0000000000 65535 f \n' +
  '0000000009 00000 n \n' +
  '0000000058 00000 n \n' +
  '0000000115 00000 n \n' +
  'trailer\n<< /Size 4 /Root 1 0 R >>\n' +
  'startxref\n190\n%%EOF\n';

function uniqueTag(prefix) {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

function currentYearMonth() {
  const now = new Date();
  return `${now.getFullYear()}-${pad2(now.getMonth() + 1)}`;
}

function nextMonthDay(day = 15) {
  const now = new Date();
  const y = now.getMonth() === 11 ? now.getFullYear() + 1 : now.getFullYear();
  const m = now.getMonth() === 11 ? 1 : now.getMonth() + 2;
  return `${y}-${pad2(m)}-${pad2(day)}`;
}

function nextMonthRange() {
  const now = new Date();
  const y = now.getMonth() === 11 ? now.getFullYear() + 1 : now.getFullYear();
  const m = now.getMonth() === 11 ? 1 : now.getMonth() + 2;
  const endY = m === 12 ? y + 1 : y;
  const endM = m === 12 ? 1 : m + 1;
  return {
    start: `${y}-${pad2(m)}-01`,
    end: `${endY}-${pad2(endM)}-01`
  };
}

function decodeHtml(str) {
  return String(str || '')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#0*39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>');
}

function parseAttrs(attrText) {
  const attrs = {};
  const re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let m;
  while ((m = re.exec(attrText || '')) !== null) {
    const key = m[1].toLowerCase();
    const val = m[2] ?? m[3] ?? m[4] ?? '';
    attrs[key] = decodeHtml(val);
  }
  return attrs;
}

function extractCsrfToken(html, form = null) {
  const priority = ['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token'];

  if (form && Array.isArray(form.inputs)) {
    for (const p of priority) {
      const hit = form.inputs.find((inp) => inp.name.toLowerCase() === p && inp.value);
      if (hit) return { fieldName: hit.name, token: hit.value };
    }
    const fuzzy = form.inputs.find(
      (inp) => inp.type === 'hidden' && /csrf/i.test(inp.name) && inp.value
    );
    if (fuzzy) return { fieldName: fuzzy.name, token: fuzzy.value };
  }

  if (typeof html === 'string' && html) {
    const allInputs = [...html.matchAll(/<input\b([^>]*)>/gi)].map((m) => parseAttrs(m[1]));
    for (const p of priority) {
      const hit = allInputs.find((a) => (a.name || '').toLowerCase() === p && a.value);
      if (hit) return { fieldName: hit.name, token: hit.value };
    }
    const fuzzyInput = allInputs.find(
      (a) => (a.type || '').toLowerCase() === 'hidden' && /csrf/i.test(a.name || '') && a.value
    );
    if (fuzzyInput) return { fieldName: fuzzyInput.name, token: fuzzyInput.value };

    for (const m of html.matchAll(/<meta\b([^>]*)>/gi)) {
      const a = parseAttrs(m[1]);
      if (/^(?:csrf-token|csrf_token|_token|_csrf_token)$/i.test(a.name || '') && a.content) {
        return { fieldName: '_csrf_token', token: a.content };
      }
    }
  }

  return { fieldName: '_csrf_token', token: null };
}

function discoverForms(html) {
  if (!html || typeof html !== 'string') return [];
  const forms = [];
  const formRe = /<form\b([^>]*)>([\s\S]*?)<\/form>/gi;
  let fm;
  while ((fm = formRe.exec(html)) !== null) {
    const attrs = parseAttrs(fm[1]);
    const body = fm[2];
    const inputs = [];
    const selects = new Map();
    const buttons = [];

    for (const im of body.matchAll(/<input\b([^>]*)>/gi)) {
      const ia = parseAttrs(im[1]);
      if (!ia.name) continue;
      const type = (ia.type || 'text').toLowerCase();
      inputs.push({
        tag: 'input',
        type,
        name: ia.name,
        value: ia.value ?? '',
        checked: Object.prototype.hasOwnProperty.call(ia, 'checked'),
        accept: ia.accept || '',
        raw: ia
      });
    }

    for (const tm of body.matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/gi)) {
      const ta = parseAttrs(tm[1]);
      if (!ta.name) continue;
      inputs.push({
        tag: 'textarea',
        type: 'textarea',
        name: ta.name,
        value: decodeHtml(tm[2]).trim(),
        raw: ta
      });
    }

    for (const sm of body.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/gi)) {
      const sa = parseAttrs(sm[1]);
      if (!sa.name) continue;
      const options = [];
      for (const om of sm[2].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/gi)) {
        const oa = parseAttrs(om[1]);
        const label = decodeHtml(om[2].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());
        options.push({
          value: oa.value ?? label,
          label,
          selected: Object.prototype.hasOwnProperty.call(oa, 'selected')
        });
      }
      selects.set(sa.name, options);
      const selOpt = options.find((o) => o.selected) || options[0];
      inputs.push({
        tag: 'select',
        type: 'select',
        name: sa.name,
        value: selOpt ? selOpt.value : '',
        options,
        raw: sa
      });
    }

    for (const bm of body.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/gi)) {
      const ba = parseAttrs(bm[1]);
      const label = decodeHtml(bm[2].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());
      if (ba.name) {
        buttons.push({ name: ba.name, value: ba.value ?? '', label, type: (ba.type || 'submit').toLowerCase() });
      }
    }

    const csrf = extractCsrfToken(html, { inputs });
    forms.push({
      action: attrs.action || '',
      method: (attrs.method || 'GET').toUpperCase(),
      enctype: (attrs.enctype || '').toLowerCase(),
      body,
      inputs,
      selects,
      buttons,
      csrfFieldName: csrf.fieldName,
      csrfToken: csrf.token
    });
  }
  return forms;
}

function discoverForm(html, opts = {}) {
  const forms = discoverForms(html);
  const nonLogout = forms.filter((f) => !/logout/i.test(f.action || ''));
  const pool = nonLogout.length > 0 ? nonLogout : forms;

  if (opts.hiddenMatch) {
    const [hKey, hVal] = opts.hiddenMatch;
    const hit = pool.find((f) =>
      f.inputs.some(
        (i) => i.name.toLowerCase() === hKey.toLowerCase() && String(i.value) === String(hVal)
      )
    );
    if (hit) return hit;
  }

  if (opts.actionHint) {
    const hint = String(opts.actionHint).toLowerCase();
    const hit = pool.find((f) => (f.action || '').toLowerCase().includes(hint));
    if (hit) return hit;
  }

  if (Array.isArray(opts.fieldHints) && opts.fieldHints.length > 0) {
    const hit = pool.find((f) =>
      opts.fieldHints.some((hint) =>
        f.inputs.some((inp) => inp.name.toLowerCase().includes(hint.toLowerCase()))
      )
    );
    if (hit) return hit;
  }

  return pool[0] || {
    action: '',
    method: 'POST',
    enctype: '',
    body: '',
    inputs: [],
    selects: new Map(),
    buttons: [],
    ...extractCsrfToken(html)
  };
}

function resolveActionUrl(action, fallbackPath, currentUrl) {
  if (!action) return fallbackPath;
  try {
    return new URL(action, currentUrl).toString();
  } catch {
    return fallbackPath;
  }
}

function seedFormPayload(form, html = '') {
  const payload = {};
  for (const inp of form.inputs || []) {
    if (inp.type === 'hidden' && inp.name) {
      payload[inp.name] = inp.value ?? '';
    }
  }
  const csrf = form.csrfToken ? { fieldName: form.csrfFieldName, token: form.csrfToken } : extractCsrfToken(html, form);
  if (csrf.token) {
    payload[csrf.fieldName || '_csrf_token'] = csrf.token;
    if (!payload._csrf_token) payload._csrf_token = csrf.token;
  }
  return { payload, csrfToken: csrf.token };
}

function pickField(form, candidates, opts = {}) {
  const exclude = new Set(opts.excludeTypes || []);
  for (const cand of candidates) {
    const found = (form.inputs || []).find(
      (inp) => inp.name.toLowerCase() === cand.toLowerCase() && !exclude.has(inp.type)
    );
    if (found) return found;
  }
  for (const cand of candidates) {
    const found = (form.inputs || []).find(
      (inp) => inp.name.toLowerCase().includes(cand.toLowerCase()) && !exclude.has(inp.type)
    );
    if (found) return found;
  }
  return null;
}

function setField(payload, form, candidates, value, fallbackName = null) {
  const found = pickField(form, candidates);
  if (found) {
    payload[found.name] = value;
    return found.name;
  }
  if (fallbackName) {
    payload[fallbackName] = value;
    return fallbackName;
  }
  return null;
}

function fillDateTimeRange(payload, form, startStr, endStr) {
  // startStr / endStr: 'YYYY-MM-DD HH:MM:SS'
  const [startDate, startTimeFull] = startStr.split(' ');
  const [endDate, endTimeFull] = endStr.split(' ');
  const startHm = startTimeFull.slice(0, 5);
  const endHm = endTimeFull.slice(0, 5);
  const startLocal = `${startDate}T${startHm}`;
  const endLocal = `${endDate}T${endHm}`;

  const startDt = pickField(form, ['start_datetime', 'start_at', 'start_date_time', 'begin_at']);
  const endDt = pickField(form, ['end_datetime', 'end_at', 'end_date_time', 'finish_at']);

  if (startDt && endDt) {
    payload[startDt.name] = startDt.type === 'datetime-local' ? startLocal : startStr;
    payload[endDt.name] = endDt.type === 'datetime-local' ? endLocal : endStr;
    return;
  }

  const dateField = pickField(form, ['booking_date', 'event_date', 'date', 'start_date']);
  const endDateField = pickField(form, ['end_date']);
  const startTimeField = pickField(form, ['start_time', 'time_start', 'begin_time']);
  const endTimeField = pickField(form, ['end_time', 'time_end', 'finish_time']);

  if (dateField && startTimeField && endTimeField) {
    payload[dateField.name] = startDate;
    if (endDateField) payload[endDateField.name] = endDate;
    payload[startTimeField.name] = startHm;
    payload[endTimeField.name] = endHm;
    return;
  }

  payload.start_datetime = startStr;
  payload.end_datetime = endStr;
}

function pickSelectOption(form, fieldCandidates, matchPredicates) {
  const sel = pickField(form, fieldCandidates);
  if (!sel || !Array.isArray(sel.options)) return null;
  for (const pred of matchPredicates) {
    const opt = sel.options.find((o) => {
      if (o.value === '') return false;
      if (typeof pred === 'function') return pred(o);
      const needle = String(pred).toLowerCase();
      return String(o.value).toLowerCase() === needle || o.label.toLowerCase().includes(needle);
    });
    if (opt) return { fieldName: sel.name, value: opt.value, label: opt.label };
  }
  return null;
}

function findFileField(form, fallback = 'permission_letter') {
  const fileInp = (form.inputs || []).find((inp) => inp.type === 'file');
  return fileInp ? fileInp.name : fallback;
}

function assertBlockedOrError(assert, res, label) {
  const text = res.text || '';
  if (res.status >= 400) return;

  try {
    const parsed = JSON.parse(text);
    if (parsed && (parsed.ok === false || parsed.success === false || parsed.error)) {
      return;
    }
  } catch {
    // HTML response
  }

  const hasAlert =
    /\b(?:alert-danger|alert-warning|invalid-feedback|is-invalid|text-danger)\b/i.test(text) ||
    /ซ้ำซ้อน|ชนกัน|ไม่ว่าง|ถูกจอง|ไม่สามารถจอง|conflict|overlap|ไม่เพียงพอ|เกินจำนวน|หมด|ไม่อนุญาต|forbidden|invalid|ไม่รองรับ|ขนาดใหญ่เกิน|กรุณา/i.test(
      text
    );

  assert.ok(
    hasAlert,
    `${label}: expected HTTP >= 400, JSON error, or error/conflict alert, got HTTP ${res.status} at ${res.url}`
  );
}

function loadFixtureBuffer(ctx, filename, fallbackFn) {
  const p = ctx.fixturePath(filename);
  if (fs.existsSync(p)) {
    return fs.readFileSync(p);
  }
  return fallbackFn();
}

function registerUploadCleanup(ctx, relPath) {
  if (!relPath || typeof relPath !== 'string') return;
  const cleanRel = relPath.replace(/^\/+/, '').replace(/\\/g, '/');
  if (!cleanRel.startsWith('uploads/')) return;
  const absPath = path.resolve(ctx.env.projectRoot, cleanRel);
  const uploadsRoot = path.resolve(ctx.env.projectRoot, 'uploads');
  if (!absPath.toLowerCase().startsWith(uploadsRoot.toLowerCase() + path.sep)) return;
  ctx.onCleanup(async () => {
    try {
      if (fs.existsSync(absPath)) {
        fs.unlinkSync(absPath);
      }
    } catch {
      // ignore cleanup error
    }
  });
}

function registerBookingCleanup(ctx, getIdsFn) {
  ctx.onCleanup(async () => {
    const ids = (typeof getIdsFn === 'function' ? getIdsFn() : getIdsFn).filter(Boolean);
    for (const id of ids) {
      await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [id]).catch(() => {});
      await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [id]).catch(() => {});
      await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [id]).catch(() => {});
    }
  });
}

async function openTestPage(ctx, viewport) {
  if (typeof ctx.browser?.openPage === 'function') {
    const res = await ctx.browser.openPage(viewport);
    let closed = false;
    const close = async () => {
      if (closed) return;
      closed = true;
      try {
        res.errors?.detach?.();
      } catch {}
      try {
        await res.context?.close?.();
      } catch {}
    };
    ctx.onCleanup(close);
    return { ...res, close };
  }

  const browser = await ctx.browser.launchChrome();
  const context = await ctx.browser.createContext(browser, viewport);
  const page = await context.newPage();
  const errors = ctx.browser.attachErrorListeners(page);
  let closed = false;
  const close = async () => {
    if (closed) return;
    closed = true;
    try {
      errors?.detach?.();
    } catch {}
    try {
      await context?.close?.();
    } catch {}
    try {
      await browser?.close?.();
    } catch {}
  };
  ctx.onCleanup(close);
  return { browser, context, page, errors, close };
}

function parseCsv(rawText) {
  const text = rawText.replace(/^\uFEFF/, '');
  const rows = [];
  let row = [];
  let cell = '';
  let inQuotes = false;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    const next = text[i + 1];
    if (inQuotes) {
      if (ch === '"' && next === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') {
        inQuotes = false;
      } else {
        cell += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(cell);
      cell = '';
    } else if (ch === '\r' && next === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
      i++;
    } else if (ch === '\n' || ch === '\r') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else {
      cell += ch;
    }
  }
  if (cell.length > 0 || row.length > 0) {
    row.push(cell);
    rows.push(row);
  }
  return rows.filter((r) => r.some((c) => c !== ''));
}

async function submitRoomBooking(client, { roomId, roomCode, title, purpose, start, end, attendees = 15, equipment = [], fileSpec = null }) {
  const getRes = await client.get('/book_room.php');
  const form = discoverForm(getRes.text, { actionHint: 'book_room', fieldHints: ['room_id', 'title'] });
  const { payload, csrfToken } = seedFormPayload(form, getRes.text);

  const roomOpt = pickSelectOption(form, ['room_id', 'room'], [String(roomId), roomCode]);
  payload[roomOpt ? roomOpt.fieldName : 'room_id'] = roomOpt ? roomOpt.value : String(roomId);
  setField(payload, form, ['title', 'subject', 'topic', 'booking_title'], title, 'title');
  setField(payload, form, ['purpose', 'description', 'details', 'detail'], purpose, 'purpose');
  setField(payload, form, ['attendee_count', 'attendees', 'participants', 'num_people'], String(attendees), 'attendee_count');
  fillDateTimeRange(payload, form, start, end);

  if (equipment.length > 0) {
    const eqInput = (form.inputs || []).find((i) => /equipment/i.test(i.name));
    const eqName = eqInput ? eqInput.name : 'equipment_requested[]';
    payload[eqName] = equipment;
  }

  const actionUrl = resolveActionUrl(form.action, '/book_room.php', getRes.url);
  const headers = csrfToken ? { 'X-CSRF-Token': csrfToken } : {};

  if (fileSpec) {
    const fileField = findFileField(form, 'permission_letter');
    return client.postMultipart(actionUrl, payload, { [fileField]: fileSpec }, { headers });
  }
  return client.postForm(actionUrl, payload, { headers });
}

async function submitVehicleBooking(client, { title, purpose, start, end, destination, passengers = 4 }) {
  const getRes = await client.get('/book_vehicle.php');
  if (getRes.status >= 400) return getRes;
  const form = discoverForm(getRes.text, { actionHint: 'book_vehicle', fieldHints: ['destination', 'passenger'] });
  const { payload, csrfToken } = seedFormPayload(form, getRes.text);

  setField(payload, form, ['title', 'subject', 'topic'], title, 'title');
  setField(payload, form, ['purpose', 'description', 'details'], purpose, 'purpose');
  setField(payload, form, ['destination', 'place', 'location'], destination, 'destination');
  setField(payload, form, ['passenger_count', 'passengers', 'num_passengers'], String(passengers), 'passenger_count');
  fillDateTimeRange(payload, form, start, end);

  const actionUrl = resolveActionUrl(form.action, '/book_vehicle.php', getRes.url);
  const headers = csrfToken ? { 'X-CSRF-Token': csrfToken } : {};
  return client.postForm(actionUrl, payload, { headers });
}

async function performApprovalAction(client, bookingId, intent, opts = {}) {
  // intent: 'approve' | 'reject' | 'return' | 'assign_vehicle'
  const pagesToTry = [`/approvals.php`, `/booking_detail.php?id=${bookingId}`];
  let pageRes = null;
  let matchingForm = null;

  for (const p of pagesToTry) {
    const r = await client.get(p);
    if (r.status < 400) {
      pageRes = r;
      const forms = discoverForms(r.text).filter((f) => !/logout/i.test(f.action || ''));
      matchingForm =
        forms.find((f) =>
          f.inputs.some(
            (i) => /^(?:booking_id|id)$/i.test(i.name) && String(i.value) === String(bookingId)
          )
        ) ||
        forms.find((f) => /approval/i.test(f.action || '')) ||
        null;
      if (matchingForm) break;
    }
  }

  const csrf = extractCsrfToken(pageRes?.text || '', matchingForm);
  const intentTokens = {
    approve: ['approve', 'approved', 'forward'],
    reject: ['reject', 'rejected'],
    return: ['return', 'returned', 'send_back'],
    assign_vehicle: ['assign_vehicle', 'assigned_vehicle', 'assign']
  }[intent] || [intent];

  if (matchingForm && (matchingForm.action || '').toLowerCase().includes('approval') && !/application\/json/i.test(pageRes?.text || '')) {
    const { payload } = seedFormPayload(matchingForm, pageRes.text);
    setField(payload, matchingForm, ['booking_id', 'id'], String(bookingId), 'booking_id');

    const actionField = pickField(matchingForm, ['action', 'decision', 'status_action']);
    let chosenAction = intentTokens[0];
    if (actionField && actionField.options) {
      const opt = actionField.options.find((o) =>
        intentTokens.some((t) => o.value.toLowerCase() === t)
      );
      if (opt) chosenAction = opt.value;
    } else if (matchingForm.buttons.length > 0) {
      const btn = matchingForm.buttons.find((b) =>
        intentTokens.some((t) => b.value.toLowerCase() === t)
      );
      if (btn) {
        payload[btn.name] = btn.value;
        chosenAction = btn.value;
      }
    }
    if (!actionField && matchingForm.buttons.length === 0) {
      payload.action = chosenAction;
    } else if (actionField) {
      payload[actionField.name] = chosenAction;
    }

    if (opts.comment !== undefined) {
      setField(payload, matchingForm, ['comment', 'remarks', 'reason', 'rejection_reason', 'note'], opts.comment, 'comment');
    }
    if (opts.vehicleId !== undefined && opts.vehicleId !== null) {
      setField(payload, matchingForm, ['vehicle_id', 'vehicle'], String(opts.vehicleId), 'vehicle_id');
    }
    if (opts.driverId !== undefined && opts.driverId !== null) {
      setField(payload, matchingForm, ['driver_id', 'driver'], String(opts.driverId), 'driver_id');
    }

    const actionUrl = resolveActionUrl(matchingForm.action, '/api/approval_action.php', pageRes.url);
    const headers = csrf.token ? { 'X-CSRF-Token': csrf.token } : {};
    return client.postForm(actionUrl, payload, { headers });
  }

  // Fallback to /api/approval_action.php (form-first unless page explicitly encodes JSON)
  const token = csrf.token || extractCsrfToken((await client.get('/index.php')).text).token;
  const body = {
    booking_id: Number(bookingId),
    action: intentTokens[0]
  };
  if (opts.comment !== undefined) {
    body.comment = opts.comment;
    body.remarks = opts.comment;
  }
  if (opts.vehicleId !== undefined && opts.vehicleId !== null) {
    body.vehicle_id = Number(opts.vehicleId);
  }
  if (opts.driverId !== undefined && opts.driverId !== null) {
    body.driver_id = Number(opts.driverId);
  }
  if (token) {
    body._csrf_token = token;
  }
  const headers = token ? { 'X-CSRF-Token': token } : {};

  const prefersJson = /application\/json/i.test(pageRes?.text || '');
  if (prefersJson) {
    const jsonRes = await client.postJson('/api/approval_action.php', body, { headers });
    if (jsonRes.status !== 415) return jsonRes;
  }

  const formRes = await client.postForm('/api/approval_action.php', body, { headers });
  if (formRes.status === 415 || /json/i.test(formRes.text || '')) {
    return client.postJson('/api/approval_action.php', body, { headers });
  }
  return formRes;
}

// ============================================================================
// T3-PW-01 .. T3-PW-17 (17 Pairwise Cross-Feature Tests)
// ============================================================================

test({
  id: 'T3-PW-01',
  tier: 3,
  feature: 'F2,F3,F6,F8',
  name: 'Auth (username & staff_id) + room booking with equipment + overlap & back-to-back conflict detection',
  async fn(ctx) {
    const tag = uniqueTag('PW01');
    const titleBase = `${tag}-Base O'Reilly & <Safe>`;
    const titleOverlap = `${tag}-Overlap`;
    const titleAdj = `${tag}-Adjacent`;
    const createdIds = [];
    registerBookingCleanup(ctx, () => createdIds);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const res1 = await submitRoomBooking(user1, {
      roomId: 3,
      roomCode: 'RM-201',
      title: titleBase,
      purpose: 'PW01 SQL-safe & equipment test; -- check',
      start: '2027-07-10 09:00:00',
      end: '2027-07-10 11:00:00',
      attendees: 15,
      equipment: ['microphone', 'projector']
    });
    ctx.assert.ok(res1.status < 400, `Base booking failed with HTTP ${res1.status}`);

    const baseRows = await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ?', [titleBase]);
    ctx.assert.equal(baseRows.length, 1, 'Expected 1 inserted base room booking');
    const base = baseRows[0];
    createdIds.push(base.id);
    ctx.assert.equal(Number(base.requester_id), 6);
    ctx.assert.equal(Number(base.room_id), 3);
    ctx.assert.equal(base.status, 'pending_head');
    ctx.assert.equal(Number(base.current_step), 1);
    const eqParsed = typeof base.equipment_requested === 'string'
      ? JSON.parse(base.equipment_requested || '[]')
      : (base.equipment_requested || []);
    ctx.assert.ok(eqParsed.includes('microphone') && eqParsed.includes('projector'), 'Equipment JSON should include microphone and projector');

    // Login user02 via staff_id EMP002
    const user2 = await ctx.http.login('EMP002', ctx.env.seedPassword);
    const homeRes = await user2.get('/index.php');
    ctx.assert.equal(homeRes.status, 200);
    ctx.assert.ok(/ธนพล|EMP002|user02/i.test(homeRes.text), 'staff_id login should display user02 identity');

    // Overlapping booking must be blocked
    const overlapRes = await submitRoomBooking(user2, {
      roomId: 3,
      roomCode: 'RM-201',
      title: titleOverlap,
      purpose: 'Overlap check',
      start: '2027-07-10 10:00:00',
      end: '2027-07-10 12:00:00',
      attendees: 10
    });
    assertBlockedOrError(ctx.assert, overlapRes, 'T3-PW-01 overlap booking');
    const overlapCount = await ctx.db.scalar('SELECT COUNT(*) FROM bookings WHERE title = ?', [titleOverlap]);
    ctx.assert.equal(Number(overlapCount), 0, 'Overlapping booking must not insert a DB row');

    // Back-to-back adjacent booking [11:00, 13:00) must succeed
    const adjRes = await submitRoomBooking(user2, {
      roomId: 3,
      roomCode: 'RM-201',
      title: titleAdj,
      purpose: 'Adjacent boundary check',
      start: '2027-07-10 11:00:00',
      end: '2027-07-10 13:00:00',
      attendees: 12
    });
    ctx.assert.ok(adjRes.status < 400, `Adjacent booking failed with HTTP ${adjRes.status}`);
    const adjRows = await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ?', [titleAdj]);
    ctx.assert.equal(adjRows.length, 1, 'Back-to-back adjacent booking must succeed');
    createdIds.push(adjRows[0].id);
    ctx.assert.equal(Number(adjRows[0].requester_id), 7);
    ctx.assert.equal(adjRows[0].status, 'pending_head');
  }
});

test({
  id: 'T3-PW-02',
  tier: 3,
  feature: 'F3,F6,F9',
  name: 'External registration + open-room PDF booking + Step 1 head approval',
  async fn(ctx) {
    const tag = uniqueTag('PW02');
    const email = `pw02_${Date.now()}_${Math.random().toString(36).slice(2, 6)}@example.org`;
    const title = `${tag}-ExtRoom`;
    let bookingId = null;
    let userId = null;

    ctx.onCleanup(async () => {
      if (bookingId) {
        await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [bookingId]).catch(() => {});
        await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [bookingId]).catch(() => {});
        await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [bookingId]).catch(() => {});
      }
      if (userId) {
        await ctx.db.execSql('DELETE FROM users WHERE id = ?', [userId]).catch(() => {});
      }
    });

    const { client: extClient } = await ctx.http.registerExternal({
      full_name: `External ${tag}`,
      organization: `Org ${tag}`,
      phone: '0890000002',
      email
    });

    const userRows = await ctx.db.queryJson('SELECT id, user_type, role, staff_id FROM users WHERE email = ?', [email]);
    ctx.assert.equal(userRows.length, 1);
    userId = userRows[0].id;
    ctx.assert.equal(userRows[0].user_type, 'external');
    ctx.assert.equal(userRows[0].role, 'external_user');
    ctx.assert.equal(userRows[0].staff_id, null);

    // Inspect available rooms for external user
    const roomFormRes = await extClient.get('/book_room.php');
    ctx.assert.equal(roomFormRes.status, 200);
    const roomForm = discoverForm(roomFormRes.text, { actionHint: 'book_room', fieldHints: ['room_id'] });
    const roomSel = pickField(roomForm, ['room_id', 'room']);
    ctx.assert.ok(roomSel && Array.isArray(roomSel.options), 'book_room.php must render room select');
    const optValues = roomSel.options.map((o) => String(o.value));
    ctx.assert.ok(optValues.includes('1') || optValues.includes('2'), 'External user must see open rooms');
    ctx.assert.ok(!optValues.includes('3') && !optValues.includes('4'), 'Internal-only rooms must not be offered to external users');

    // Direct POST targeting internal-only room 3 must be blocked
    const pdfBuf = loadFixtureBuffer(ctx, 'permission_letter.pdf', () => Buffer.from(VALID_MINIMAL_PDF, 'utf8'));
    const blockedIntRoom = await submitRoomBooking(extClient, {
      roomId: 3,
      roomCode: 'RM-201',
      title: `${tag}-BlockedInt`,
      purpose: 'Should fail internal room',
      start: '2027-07-12 09:00:00',
      end: '2027-07-12 12:00:00',
      attendees: 10,
      fileSpec: { buffer: pdfBuf, filename: 'pw02_blocked.pdf', contentType: 'application/pdf' }
    });
    assertBlockedOrError(ctx.assert, blockedIntRoom, 'T3-PW-02 internal-only room for external user');
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings WHERE title = ?', [`${tag}-BlockedInt`])), 0);

    // Valid external room booking with PDF
    const okRes = await submitRoomBooking(extClient, {
      roomId: 1,
      roomCode: 'RM-101',
      title,
      purpose: 'External seminar request',
      start: '2027-07-12 09:00:00',
      end: '2027-07-12 12:00:00',
      attendees: 30,
      fileSpec: { buffer: pdfBuf, filename: 'pw02_letter.pdf', contentType: 'application/pdf' }
    });
    ctx.assert.ok(okRes.status < 400, `External room booking failed: HTTP ${okRes.status}`);

    const bRows = await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ?', [title]);
    ctx.assert.equal(bRows.length, 1);
    const booking = bRows[0];
    bookingId = booking.id;
    registerUploadCleanup(ctx, booking.permission_letter_path);
    ctx.assert.equal(booking.requester_type, 'external');
    ctx.assert.equal(Number(booking.room_id), 1);
    ctx.assert.equal(booking.status, 'pending_head');
    ctx.assert.equal(Number(booking.current_step), 1);
    ctx.assert.match(String(booking.permission_letter_path || ''), /^uploads\/permissions\/.+\.pdf$/i);

    // Step 1 head01 approval
    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const approvalsPage = await head.get('/approvals.php');
    ctx.assert.equal(approvalsPage.status, 200);
    ctx.assert.ok(approvalsPage.text.includes(title) || approvalsPage.text.includes(booking.reference_code));

    const appRes = await performApprovalAction(head, bookingId, 'approve', { comment: 'PW02 Step 1 OK' });
    ctx.assert.ok(appRes.status < 400, `Head approval failed: HTTP ${appRes.status}`);

    const after = (await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [bookingId]))[0];
    ctx.assert.equal(after.status, 'pending_vice');
    ctx.assert.equal(Number(after.current_step), 2);

    const logs = await ctx.db.queryJson('SELECT * FROM approval_logs WHERE booking_id = ?', [bookingId]);
    ctx.assert.equal(logs.length, 1);
    ctx.assert.equal(Number(logs[0].step_number), 1);
    ctx.assert.equal(logs[0].step_role, 'head');
    ctx.assert.equal(Number(logs[0].approver_id), 3);
    ctx.assert.equal(logs[0].action, 'approved');

    // External user sees updated status in my_bookings & booking_detail
    const myRes = await extClient.get('/my_bookings.php');
    ctx.assert.equal(myRes.status, 200);
    ctx.assert.ok(myRes.text.includes(title));
    const detailRes = await extClient.get(`/booking_detail.php?id=${bookingId}`);
    ctx.assert.equal(detailRes.status, 200);
    ctx.assert.ok(/pending_vice|รองผู้อำนวยการ/i.test(detailRes.text));
  }
});

test({
  id: 'T3-PW-03',
  tier: 3,
  feature: 'F5,F6',
  name: 'Room booking + Master Calendar API & FullCalendar PII masking (user02/ext01 vs owner/head01)',
  async fn(ctx) {
    const tag = uniqueTag('PW03');
    const title = `${tag}-SecretRoom`;
    const sensitivePurpose = `${tag}-SensitiveBudget-9999`;
    const dateStr = nextMonthDay(15);
    const { start: monthStart, end: monthEnd } = nextMonthRange();
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const createRes = await submitRoomBooking(user1, {
      roomId: 1,
      roomCode: 'RM-101',
      title,
      purpose: sensitivePurpose,
      start: `${dateStr} 13:00:00`,
      end: `${dateStr} 15:00:00`,
      attendees: 20
    });
    ctx.assert.ok(createRes.status < 400);

    const bRows = await ctx.db.queryJson('SELECT id, reference_code FROM bookings WHERE title = ?', [title]);
    ctx.assert.equal(bRows.length, 1);
    bookingId = bRows[0].id;
    const refCode = bRows[0].reference_code;

    const apiPath = `/api/calendar_events.php?start=${encodeURIComponent(monthStart)}&end=${encodeURIComponent(monthEnd)}`;

    const findBookingEvent = (events) =>
      events.find(
        (ev) =>
          String(ev?.id || '').includes(String(bookingId)) ||
          String(ev?.extendedProps?.booking_id || '') === String(bookingId) ||
          String(ev?.extendedProps?.reference_code || '') === refCode ||
          (String(ev?.start || '').startsWith(dateStr) && String(ev?.extendedProps?.type || ev?.extendedProps?.booking_type || '').includes('room'))
      );

    // Non-owner user02 -> masked
    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    const evUser2Res = await user2.get(apiPath);
    ctx.assert.equal(evUser2Res.status, 200);
    const evUser2 = findBookingEvent(evUser2Res.json());
    ctx.assert.ok(evUser2, 'Booking must appear on calendar API for user02');
    ctx.assert.equal(evUser2.extendedProps?.is_masked, true, 'Calendar event must be masked for non-owner user02');
    const serializedUser2 = JSON.stringify(evUser2);
    ctx.assert.ok(!serializedUser2.includes(sensitivePurpose), 'Purpose leaked to user02');
    ctx.assert.ok(!serializedUser2.includes('081-111-0001'), 'Phone leaked to user02');
    ctx.assert.ok(!serializedUser2.includes('นางสาวกมลชนก'), 'Requester name leaked to user02');

    // Non-owner ext01 -> masked
    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    const evExt1Res = await ext1.get(apiPath);
    ctx.assert.equal(evExt1Res.status, 200);
    const evExt1 = findBookingEvent(evExt1Res.json());
    ctx.assert.ok(evExt1, 'Booking must appear on calendar API for ext01');
    ctx.assert.equal(evExt1.extendedProps?.is_masked, true, 'Calendar event must be masked for ext01');
    ctx.assert.ok(!JSON.stringify(evExt1).includes(sensitivePurpose), 'Purpose leaked to ext01');
    ctx.assert.ok(!JSON.stringify(evExt1).includes('นางสาวกมลชนก'), 'Requester name leaked to ext01');

    // Owner user01 -> unmasked
    const evUser1 = findBookingEvent((await user1.get(apiPath)).json());
    ctx.assert.ok(evUser1 && !evUser1.extendedProps?.is_masked, 'Owner should see unmasked event');
    ctx.assert.ok(JSON.stringify(evUser1).includes(title) || JSON.stringify(evUser1).includes(sensitivePurpose));

    // Privileged head01 -> unmasked
    const head1 = await ctx.http.login('head01', ctx.env.seedPassword);
    const evHead1 = findBookingEvent((await head1.get(apiPath)).json());
    ctx.assert.ok(evHead1 && !evHead1.extendedProps?.is_masked, 'head01 should see unmasked event');
    ctx.assert.ok(JSON.stringify(evHead1).includes('นางสาวกมลชนก') || JSON.stringify(evHead1).includes(sensitivePurpose));

    // Playwright Desktop check
    const pw = await openTestPage(ctx, ctx.browser.DESKTOP);
    try {
      await ctx.browser.loginUi(pw.page, 'user02', ctx.env.seedPassword);
      await pw.page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      await pw.page.locator('.fc-next-button').first().waitFor({ state: 'visible' });
      await pw.page.locator('.fc-next-button').first().click();
      await pw.page.locator('.fc-event').first().waitFor({ state: 'visible', timeout: 10000 });

      const eventsLoc = pw.page.locator('.fc-event');
      const evCount = await eventsLoc.count();
      ctx.assert.ok(evCount >= 1, 'Expected FullCalendar events rendered next month');
      await eventsLoc.first().click();
      await pw.page.waitForTimeout(300);
      const bodyText = await pw.page.locator('body').innerText();
      ctx.assert.ok(!bodyText.includes(sensitivePurpose), 'UI calendar must not expose sensitive purpose to user02');
      ctx.assert.ok(!bodyText.includes('081-111-0001'), 'UI calendar must not expose phone to user02');
      pw.errors.assertNoErrors('T3-PW-03 calendar PII masking');
    } finally {
      await pw.close();
    }
  }
});

test({
  id: 'T3-PW-04',
  tier: 3,
  feature: 'F7,F9',
  name: 'Vehicle booking (external 403) + Step 1 staff/head vehicle & driver assignment + forward to pending_vice',
  async fn(ctx) {
    const tag = uniqueTag('PW04');
    const title = `${tag}-VehicleReq`;
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    // External user blocked 403
    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    const extGet = await ext1.get('/book_vehicle.php');
    ctx.assert.equal(extGet.status, 403, 'External user must get 403 on book_vehicle.php');

    // Internal user submits vehicle request
    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const createRes = await submitVehicleBooking(user1, {
      title,
      purpose: 'PW04 official travel',
      start: '2027-07-16 08:30:00',
      end: '2027-07-16 16:30:00',
      destination: 'ศาลากลางจังหวัด',
      passengers: 5
    });
    ctx.assert.ok(createRes.status < 400);

    const rows = await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ?', [title]);
    ctx.assert.equal(rows.length, 1);
    const bk = rows[0];
    bookingId = bk.id;
    ctx.assert.equal(bk.booking_type, 'vehicle');
    ctx.assert.equal(bk.vehicle_id, null);
    ctx.assert.equal(bk.driver_id, null);
    ctx.assert.equal(bk.status, 'pending_head');
    ctx.assert.equal(Number(bk.current_step), 1);
    ctx.assert.equal(bk.destination, 'ศาลากลางจังหวัด');
    ctx.assert.equal(Number(bk.passenger_count), 5);

    // Head cannot approve without vehicle/driver assigned
    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const noAssignRes = await performApprovalAction(head, bookingId, 'approve', { comment: 'Try approve without vehicle' });
    assertBlockedOrError(ctx.assert, noAssignRes, 'T3-PW-04 approve without vehicle/driver');
    ctx.assert.equal(await ctx.db.scalar('SELECT status FROM bookings WHERE id = ?', [bookingId]), 'pending_head');

    // Staff assigns VEH-01 (id=1) & DRV-01 (id=1)
    const staff = await ctx.http.login('staff01', ctx.env.seedPassword);
    const assignRes = await performApprovalAction(staff, bookingId, 'assign_vehicle', {
      vehicleId: 1,
      driverId: 1,
      comment: 'Staff assigned VEH-01 + DRV-01'
    });
    ctx.assert.ok(assignRes.status < 400, `Staff vehicle assignment failed: HTTP ${assignRes.status}`);

    const afterAssign = (await ctx.db.queryJson('SELECT vehicle_id, driver_id, status FROM bookings WHERE id = ?', [bookingId]))[0];
    ctx.assert.equal(Number(afterAssign.vehicle_id), 1);
    ctx.assert.equal(Number(afterAssign.driver_id), 1);

    // Head approves Step 1 -> pending_vice
    const headApproveRes = await performApprovalAction(head, bookingId, 'approve', {
      vehicleId: 1,
      driverId: 1,
      comment: 'PW04 Head approved'
    });
    ctx.assert.ok(headApproveRes.status < 400);

    const afterHead = (await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [bookingId]))[0];
    ctx.assert.equal(afterHead.status, 'pending_vice');
    ctx.assert.equal(Number(afterHead.current_step), 2);
    const logCount = await ctx.db.scalar(
      "SELECT COUNT(*) FROM approval_logs WHERE booking_id = ? AND step_role = 'head' AND action = 'approved'",
      [bookingId]
    );
    ctx.assert.equal(Number(logCount), 1);
  }
});

test({
  id: 'T3-PW-05',
  tier: 3,
  feature: 'F8,F9',
  name: 'Step 1 vehicle/driver assignment enforces vehicle and driver overlap conflict detection',
  async fn(ctx) {
    const tag = uniqueTag('PW05');
    const titleA = `${tag}-VehA`;
    const titleB = `${tag}-VehB`;
    let idA = null;
    let idB = null;
    registerBookingCleanup(ctx, () => [idA, idB]);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);

    await submitVehicleBooking(user1, {
      title: titleA,
      purpose: 'PW05 Slot A',
      start: '2027-07-18 09:00:00',
      end: '2027-07-18 15:00:00',
      destination: 'จุดหมาย A',
      passengers: 4
    });
    await submitVehicleBooking(user2, {
      title: titleB,
      purpose: 'PW05 Slot B',
      start: '2027-07-18 11:00:00',
      end: '2027-07-18 14:00:00',
      destination: 'จุดหมาย B',
      passengers: 3
    });

    idA = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [titleA]);
    idB = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [titleB]);
    ctx.assert.ok(idA && idB, 'Both vehicle bookings must exist');

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const resA = await performApprovalAction(head, idA, 'approve', {
      vehicleId: 1,
      driverId: 1,
      comment: 'Assign VEH-01 + DRV-01 to A'
    });
    ctx.assert.ok(resA.status < 400);
    ctx.assert.equal(await ctx.db.scalar('SELECT status FROM bookings WHERE id = ?', [idA]), 'pending_vice');

    // Busy vehicle (1) + free driver (2) -> blocked
    const busyVehRes = await performApprovalAction(head, idB, 'approve', {
      vehicleId: 1,
      driverId: 2,
      comment: 'Busy vehicle attempt'
    });
    assertBlockedOrError(ctx.assert, busyVehRes, 'T3-PW-05 busy vehicle conflict');
    const bAfterVeh = (await ctx.db.queryJson('SELECT vehicle_id, driver_id, status FROM bookings WHERE id = ?', [idB]))[0];
    ctx.assert.equal(bAfterVeh.vehicle_id, null);
    ctx.assert.equal(bAfterVeh.status, 'pending_head');

    // Free vehicle (2) + busy driver (1) -> blocked
    const busyDrvRes = await performApprovalAction(head, idB, 'approve', {
      vehicleId: 2,
      driverId: 1,
      comment: 'Busy driver attempt'
    });
    assertBlockedOrError(ctx.assert, busyDrvRes, 'T3-PW-05 busy driver conflict');
    const bAfterDrv = (await ctx.db.queryJson('SELECT vehicle_id, driver_id, status FROM bookings WHERE id = ?', [idB]))[0];
    ctx.assert.equal(bAfterDrv.driver_id, null);
    ctx.assert.equal(bAfterDrv.status, 'pending_head');

    // Free vehicle (2) + free driver (2) -> succeeds
    const freeRes = await performApprovalAction(head, idB, 'approve', {
      vehicleId: 2,
      driverId: 2,
      comment: 'Free vehicle + driver'
    });
    ctx.assert.ok(freeRes.status < 400);
    const bAfterFree = (await ctx.db.queryJson('SELECT vehicle_id, driver_id, status FROM bookings WHERE id = ?', [idB]))[0];
    ctx.assert.equal(Number(bAfterFree.vehicle_id), 2);
    ctx.assert.equal(Number(bAfterFree.driver_id), 2);
    ctx.assert.equal(bAfterFree.status, 'pending_vice');
  }
});

test({
  id: 'T3-PW-06',
  tier: 3,
  feature: 'F9,F10',
  name: 'Step 2 return-to-head + re-forward + booking_detail.php Stepper timeline',
  async fn(ctx) {
    const tag = uniqueTag('PW06');
    const title = `${tag}-ReturnFlow`;
    const returnComment = `${tag}-ReturnComment: ขอรายละเอียดเพิ่ม`;
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 1,
      roomCode: 'RM-101',
      title,
      purpose: 'PW06 stepper verification',
      start: '2027-07-20 09:00:00',
      end: '2027-07-20 11:00:00',
      attendees: 18
    });
    bookingId = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [title]);
    ctx.assert.ok(bookingId);

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    await performApprovalAction(head, bookingId, 'approve', { comment: 'PW06 Head 1st pass' });
    ctx.assert.equal(await ctx.db.scalar('SELECT status FROM bookings WHERE id = ?', [bookingId]), 'pending_vice');

    // Role gate: head cannot act at pending_vice
    const wrongRoleRes = await performApprovalAction(head, bookingId, 'approve', { comment: 'Head out of turn' });
    assertBlockedOrError(ctx.assert, wrongRoleRes, 'T3-PW-06 head acting on pending_vice');
    ctx.assert.equal(await ctx.db.scalar('SELECT status FROM bookings WHERE id = ?', [bookingId]), 'pending_vice');

    // Vice director returns to head
    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    const retRes = await performApprovalAction(vice, bookingId, 'return', { comment: returnComment });
    ctx.assert.ok(retRes.status < 400);

    const afterRet = (await ctx.db.queryJson('SELECT status, current_step FROM bookings WHERE id = ?', [bookingId]))[0];
    ctx.assert.equal(afterRet.status, 'pending_head');
    ctx.assert.equal(Number(afterRet.current_step), 1);
    const retLogCount = await ctx.db.scalar(
      "SELECT COUNT(*) FROM approval_logs WHERE booking_id = ? AND step_role = 'vice_director' AND action = 'returned'",
      [bookingId]
    );
    ctx.assert.equal(Number(retLogCount), 1);

    // Playwright Desktop Stepper check while returned to Step 1
    const pw = await openTestPage(ctx, ctx.browser.DESKTOP);
    try {
      await ctx.browser.loginUi(pw.page, 'user01', ctx.env.seedPassword);
      await pw.page.goto(`${ctx.baseUrl}/booking_detail.php?id=${bookingId}`, { waitUntil: 'domcontentloaded' });
      const bodyReturned = await pw.page.locator('body').innerText();
      ctx.assert.ok(bodyReturned.includes(title), 'Detail page must show booking title');
      ctx.assert.ok(bodyReturned.includes(returnComment), 'Detail page must show vice return comment');
      ctx.assert.ok((await pw.page.locator('.stepper, .stepper-step').count()) >= 1, 'Stepper UI must render');
      pw.errors.assertNoErrors('T3-PW-06 returned stepper');

      // Re-forward Head -> Vice -> Director
      await performApprovalAction(head, bookingId, 'approve', { comment: 'PW06 Head re-approved' });
      await performApprovalAction(vice, bookingId, 'approve', { comment: 'PW06 Vice approved' });
      const director = await ctx.http.login('director01', ctx.env.seedPassword);
      await performApprovalAction(director, bookingId, 'approve', { comment: 'PW06 Director final OK' });

      ctx.assert.equal(await ctx.db.scalar('SELECT status FROM bookings WHERE id = ?', [bookingId]), 'approved');

      await pw.page.goto(`${ctx.baseUrl}/booking_detail.php?id=${bookingId}`, { waitUntil: 'domcontentloaded' });
      const bodyApproved = await pw.page.locator('body').innerText();
      ctx.assert.ok(bodyApproved.includes('PW06 Director final OK'), 'Final director comment must appear on timeline');
      const memoLinks = await pw.page.locator(`a[href*="print_memo.php"][href*="id=${bookingId}"]`).count();
      ctx.assert.ok(memoLinks >= 1, 'Approved booking detail page must include print_memo.php link');
      pw.errors.assertNoErrors('T3-PW-06 approved stepper');
    } finally {
      await pw.close();
    }
  }
});

test({
  id: 'T3-PW-07',
  tier: 3,
  feature: 'F9,F11',
  name: 'Multi-step approval transitions trigger notification dispatcher to head, vice_director, director, and requester',
  async fn(ctx) {
    const tag = uniqueTag('PW07');
    const title = `${tag}-NotifFlow`;
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    const beforeLogLen = ctx.db.readNotificationLog().length;
    const beforeMaxNotifId = Number(await ctx.db.scalar('SELECT COALESCE(MAX(id), 0) FROM notification_logs'));

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 1,
      roomCode: 'RM-101',
      title,
      purpose: 'PW07 notifications',
      start: '2027-07-22 10:00:00',
      end: '2027-07-22 12:00:00',
      attendees: 10
    });
    const bk = (await ctx.db.queryJson('SELECT id, reference_code FROM bookings WHERE title = ?', [title]))[0];
    ctx.assert.ok(bk);
    bookingId = bk.id;

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    await performApprovalAction(head, bookingId, 'approve', { comment: 'PW07 Head OK' });

    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    await performApprovalAction(vice, bookingId, 'approve', { comment: 'PW07 Vice OK' });

    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    await performApprovalAction(director, bookingId, 'approve', { comment: 'PW07 Director OK' });

    const dbNotifs = await ctx.db.queryJson(
      'SELECT * FROM notification_logs WHERE id > ? AND booking_id = ? ORDER BY id ASC',
      [beforeMaxNotifId, bookingId]
    );
    ctx.assert.ok(dbNotifs.length >= 4, `Expected >= 4 notification_logs rows for booking ${bookingId}, got ${dbNotifs.length}`);

    const events = dbNotifs.map((n) => String(n.event_name || '').toLowerCase());
    const roles = dbNotifs.map((n) => String(n.target_role || '').toLowerCase());
    ctx.assert.ok(events.includes('pending_head') || roles.includes('head'), 'Missing head notification');
    ctx.assert.ok(events.includes('pending_vice') || roles.includes('vice_director'), 'Missing vice_director notification');
    ctx.assert.ok(events.includes('pending_director') || roles.includes('director'), 'Missing director notification');
    ctx.assert.ok(
      dbNotifs.some((n) => n.event_name === 'approved' && (Number(n.target_user_id) === 6 || String(n.recipient || '').includes('user01'))),
      'Missing final approved notification targeting requester user01'
    );

    const newJsonl = ctx.db.readNotificationLog().slice(beforeLogLen);
    const matchingJsonl = newJsonl.filter((entry) => {
      const s = JSON.stringify(entry);
      return Number(entry.booking_id) === Number(bookingId) || s.includes(bk.reference_code) || s.includes(title);
    });
    ctx.assert.ok(matchingJsonl.length >= 4, `Expected >= 4 notifications.log JSONL lines for booking ${bookingId}, got ${matchingJsonl.length}`);
  }
});

test({
  id: 'T3-PW-08',
  tier: 3,
  feature: 'F8,F9,F11',
  name: 'Rejected booking releases resource slot for rebooking and sends rejection notification',
  async fn(ctx) {
    const tag = uniqueTag('PW08');
    const title1 = `${tag}-Rej1`;
    const title2 = `${tag}-Rebook2`;
    const rejReason = `${tag}-RejectReason: ปิดปรับปรุงชั่วคราว`;
    let id1 = null;
    let id2 = null;
    registerBookingCleanup(ctx, () => [id1, id2]);

    const beforeLogLen = ctx.db.readNotificationLog().length;
    const beforeMaxNotifId = Number(await ctx.db.scalar('SELECT COALESCE(MAX(id), 0) FROM notification_logs'));

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 1,
      roomCode: 'RM-101',
      title: title1,
      purpose: 'First booking to reject',
      start: '2027-07-24 09:00:00',
      end: '2027-07-24 11:00:00',
      attendees: 10
    });
    id1 = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [title1]);
    ctx.assert.ok(id1);

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    const rejRes = await performApprovalAction(head, id1, 'reject', { comment: rejReason });
    ctx.assert.ok(rejRes.status < 400);

    const row1 = (await ctx.db.queryJson('SELECT status, rejection_reason, reference_code FROM bookings WHERE id = ?', [id1]))[0];
    ctx.assert.equal(row1.status, 'rejected');
    ctx.assert.ok(String(row1.rejection_reason || '').includes(tag) || (await ctx.db.scalar("SELECT COUNT(*) FROM approval_logs WHERE booking_id = ? AND action = 'rejected'", [id1])) >= 1);

    const rejNotifs = await ctx.db.queryJson(
      "SELECT * FROM notification_logs WHERE id > ? AND booking_id = ? AND event_name = 'rejected'",
      [beforeMaxNotifId, id1]
    );
    ctx.assert.ok(rejNotifs.length >= 1, 'Expected rejection notification in notification_logs');
    const newJsonl = ctx.db.readNotificationLog().slice(beforeLogLen);
    ctx.assert.ok(
      newJsonl.some((e) => (Number(e.booking_id) === Number(id1) || JSON.stringify(e).includes(row1.reference_code)) && /rejected/i.test(JSON.stringify(e))),
      'Expected rejection entry in notifications.log'
    );

    // Rebook the exact same slot as user02
    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    const rebookRes = await submitRoomBooking(user2, {
      roomId: 1,
      roomCode: 'RM-101',
      title: title2,
      purpose: 'Rebooking rejected slot',
      start: '2027-07-24 09:00:00',
      end: '2027-07-24 11:00:00',
      attendees: 10
    });
    ctx.assert.ok(rebookRes.status < 400);
    id2 = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [title2]);
    ctx.assert.ok(id2, 'Rejected booking must not block rebooking of the same time slot');

    const calRes = await head.get('/api/calendar_events.php?start=2027-07-01&end=2027-08-01');
    ctx.assert.equal(calRes.status, 200);
    const calJsonStr = JSON.stringify(calRes.json());
    ctx.assert.ok(!calJsonStr.includes(title1), 'Calendar API must omit rejected booking');
    ctx.assert.ok(calJsonStr.includes(title2), 'Calendar API must include new active booking');
  }
});

test({
  id: 'T3-PW-09',
  tier: 3,
  feature: 'F9,F16',
  name: '3-step approved booking renders official A4 print memo with ordered approval_logs and IDOR protection',
  async fn(ctx) {
    const tag = uniqueTag('PW09');
    const title = `${tag}-Memo`;
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 1,
      roomCode: 'RM-101',
      title,
      purpose: 'PW09 official memo print test',
      start: '2027-07-26 13:00:00',
      end: '2027-07-26 16:00:00',
      attendees: 25
    });
    const bk = (await ctx.db.queryJson('SELECT id, reference_code FROM bookings WHERE title = ?', [title]))[0];
    ctx.assert.ok(bk);
    bookingId = bk.id;

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    await performApprovalAction(head, bookingId, 'approve', { comment: 'PW09 Head OK' });
    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    await performApprovalAction(vice, bookingId, 'approve', { comment: 'PW09 Vice OK' });
    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    await performApprovalAction(director, bookingId, 'approve', { comment: 'PW09 Director OK' });

    const logs = await ctx.db.queryJson('SELECT step_number, step_role FROM approval_logs WHERE booking_id = ? ORDER BY step_number ASC', [bookingId]);
    ctx.assert.deepEqual(
      logs.map((l) => [Number(l.step_number), l.step_role]),
      [[1, 'head'], [2, 'vice_director'], [3, 'director']]
    );

    // IDOR checks via HTTP
    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    ctx.assert.equal((await user2.get(`/print_memo.php?id=${bookingId}`)).status, 403, 'Non-owner user02 must receive 403 on print_memo.php');
    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    ctx.assert.equal((await ext1.get(`/print_memo.php?id=${bookingId}`)).status, 403, 'Non-owner ext01 must receive 403 on print_memo.php');

    // Playwright Desktop print view verification
    const pw = await openTestPage(ctx, ctx.browser.DESKTOP);
    try {
      await ctx.browser.loginUi(pw.page, 'user01', ctx.env.seedPassword);
      const navRes = await pw.page.goto(`${ctx.baseUrl}/print_memo.php?id=${bookingId}`, { waitUntil: 'domcontentloaded' });
      ctx.assert.equal(navRes.status(), 200);

      const memoText = await pw.page.locator('body').innerText();
      ctx.assert.ok(memoText.includes('บันทึกข้อความ'), 'Memo must contain บันทึกข้อความ');
      ctx.assert.ok(memoText.includes(bk.reference_code), 'Memo must contain booking reference_code');
      ctx.assert.ok(memoText.includes(title), 'Memo must contain booking title');
      ctx.assert.ok(memoText.includes('นางสาวกมลชนก'), 'Memo must contain requester name');

      const idxHead = memoText.indexOf('นางสาวสมหญิง');
      const idxVice = memoText.indexOf('นายวิชัย');
      const idxDir = memoText.indexOf('ดร.สุชาติ');
      ctx.assert.ok(idxHead !== -1 && idxVice !== -1 && idxDir !== -1, 'All 3 approver names must appear in memo');
      ctx.assert.ok(idxHead < idxVice && idxVice < idxDir, 'Signers must appear in head -> vice_director -> director order');

      const hasPrintStyle = await pw.page.evaluate(() => {
        const html = document.documentElement.outerHTML;
        return /print\.css|THSarabunNew/i.test(html);
      });
      ctx.assert.ok(hasPrintStyle, 'Print memo must reference print.css / THSarabunNew');

      await pw.page.emulateMedia({ media: 'print' });
      const noPrintCount = await pw.page.locator('.no-print').count();
      if (noPrintCount > 0) {
        const displayVal = await pw.page.locator('.no-print').first().evaluate((el) => getComputedStyle(el).display);
        ctx.assert.equal(displayVal, 'none', '.no-print elements must be hidden in print media');
      }
      pw.errors.assertNoErrors('T3-PW-09 print memo');
    } finally {
      await pw.close();
    }
  }
});

test({
  id: 'T3-PW-10',
  tier: 3,
  feature: 'F6,F15',
  name: 'Internal vs external bookings reconcile with Executive Dashboard KPIs and Chart.js split',
  async fn(ctx) {
    await ctx.resetDb();
    const ym = currentYearMonth();
    const day27 = `${ym}-27`;
    const tag = uniqueTag('PW10');
    const createdIds = [];
    registerBookingCleanup(ctx, () => createdIds);

    const baseStatsSql = async () => ({
      total: Number(await ctx.db.scalar("SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?", [ym])),
      internal: Number(await ctx.db.scalar("SELECT COUNT(*) FROM bookings WHERE booking_type='room' AND requester_type='internal' AND DATE_FORMAT(start_datetime, '%Y-%m') = ?", [ym])),
      external: Number(await ctx.db.scalar("SELECT COUNT(*) FROM bookings WHERE booking_type='room' AND requester_type='external' AND DATE_FORMAT(start_datetime, '%Y-%m') = ?", [ym])),
      pending: Number(await ctx.db.scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head','pending_vice','pending_director')"))
    });
    const baseDb = await baseStatsSql();

    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    const beforeApiRes = await director.get(`/api/dashboard_stats.php?month=${ym}`);
    ctx.assert.equal(beforeApiRes.status, 200);
    const beforeJson = JSON.stringify(beforeApiRes.json());

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 4,
      roomCode: 'RM-302',
      title: `${tag}-Int`,
      purpose: 'PW10 internal room',
      start: `${day27} 18:00:00`,
      end: `${day27} 20:00:00`,
      attendees: 10
    });
    const intId = await ctx.db.scalar('SELECT id FROM bookings WHERE title = ?', [`${tag}-Int`]);
    ctx.assert.ok(intId);
    createdIds.push(intId);

    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    const pdfBuf = loadFixtureBuffer(ctx, 'permission_letter.pdf', () => Buffer.from(VALID_MINIMAL_PDF, 'utf8'));
    await submitRoomBooking(ext1, {
      roomId: 1,
      roomCode: 'RM-101',
      title: `${tag}-Ext`,
      purpose: 'PW10 external room',
      start: `${day27} 18:00:00`,
      end: `${day27} 20:00:00`,
      attendees: 25,
      fileSpec: { buffer: pdfBuf, filename: 'pw10_letter.pdf', contentType: 'application/pdf' }
    });
    const extRow = (await ctx.db.queryJson('SELECT id, permission_letter_path FROM bookings WHERE title = ?', [`${tag}-Ext`]))[0];
    ctx.assert.ok(extRow);
    createdIds.push(extRow.id);
    registerUploadCleanup(ctx, extRow.permission_letter_path);

    // RBAC checks via HTTP
    ctx.assert.equal((await user1.get('/dashboard.php')).status, 403);
    ctx.assert.equal((await user1.get('/api/dashboard_stats.php')).status, 403);
    ctx.assert.equal((await ext1.get('/dashboard.php')).status, 403);

    const afterDb = await baseStatsSql();
    ctx.assert.equal(afterDb.internal, baseDb.internal + 1);
    ctx.assert.equal(afterDb.external, baseDb.external + 1);
    ctx.assert.equal(afterDb.pending, baseDb.pending + 2);

    const afterApiRes = await director.get(`/api/dashboard_stats.php?month=${ym}`);
    ctx.assert.equal(afterApiRes.status, 200);
    const afterJsonObj = afterApiRes.json();
    const afterJsonStr = JSON.stringify(afterJsonObj);
    ctx.assert.notEqual(afterJsonStr, beforeJson, 'Dashboard stats API payload must reflect newly inserted internal & external bookings');
    // Check that updated counts appear in numeric leaf values of the stats JSON
    const leafNumbers = new Set();
    const collectNums = (v) => {
      if (typeof v === 'number' && Number.isFinite(v)) leafNumbers.add(v);
      else if (Array.isArray(v)) v.forEach(collectNums);
      else if (v && typeof v === 'object') Object.values(v).forEach(collectNums);
    };
    collectNums(afterJsonObj);
    ctx.assert.ok(leafNumbers.has(afterDb.pending), `API stats must include updated pending count ${afterDb.pending}`);

    // Playwright Desktop & Mobile verification
    for (const vp of [ctx.browser.DESKTOP, ctx.browser.MOBILE]) {
      const pw = await openTestPage(ctx, vp);
      try {
        await ctx.browser.loginUi(pw.page, 'director01', ctx.env.seedPassword);
        await pw.page.goto(`${ctx.baseUrl}/dashboard.php`, { waitUntil: 'domcontentloaded' });
        await pw.page.locator('canvas').first().waitFor({ state: 'visible', timeout: 10000 });
        await pw.page.waitForTimeout(400);

        const canvasCount = await pw.page.locator('canvas').count();
        ctx.assert.ok(canvasCount >= 2, `Expected >= 2 Chart.js canvases, found ${canvasCount}`);
        const firstBox = await pw.page.locator('canvas').first().boundingBox();
        ctx.assert.ok(firstBox && firstBox.width > 0 && firstBox.height > 0, 'Chart.js canvas must have non-zero bounding box');

        if (vp === ctx.browser.MOBILE) {
          const overflow = await pw.page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          ctx.assert.ok(overflow <= 2, `Mobile dashboard overflow ${overflow}px exceeds tolerance`);
        }
        pw.errors.assertNoErrors(`T3-PW-10 dashboard ${vp.width}x${vp.height}`);
      } finally {
        await pw.close();
      }
    }
  }
});

test({
  id: 'T3-PW-11',
  tier: 3,
  feature: 'F6,F9,F17',
  name: 'Approved booking reconciles with monthly bookings CSV export (UTF-8 BOM & row accuracy)',
  async fn(ctx) {
    await ctx.resetDb();
    const ym = currentYearMonth();
    const day28 = `${ym}-28`;
    const tag = uniqueTag('PW11');
    const title = `${tag}-CsvRoom`;
    let bookingId = null;
    registerBookingCleanup(ctx, () => [bookingId]);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    await submitRoomBooking(user1, {
      roomId: 4,
      roomCode: 'RM-302',
      title,
      purpose: 'PW11 CSV export verification',
      start: `${day28} 08:00:00`,
      end: `${day28} 10:00:00`,
      attendees: 14
    });
    const bk = (await ctx.db.queryJson('SELECT id, reference_code FROM bookings WHERE title = ?', [title]))[0];
    ctx.assert.ok(bk);
    bookingId = bk.id;

    const head = await ctx.http.login('head01', ctx.env.seedPassword);
    await performApprovalAction(head, bookingId, 'approve', { comment: 'PW11 Head' });
    const vice = await ctx.http.login('vice01', ctx.env.seedPassword);
    await performApprovalAction(vice, bookingId, 'approve', { comment: 'PW11 Vice' });
    const director = await ctx.http.login('director01', ctx.env.seedPassword);
    await performApprovalAction(director, bookingId, 'approve', { comment: 'PW11 Director' });

    // RBAC
    ctx.assert.equal((await user1.get(`/export_csv.php?type=bookings&month=${ym}`)).status, 403);
    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    ctx.assert.equal((await ext1.get(`/export_csv.php?type=bookings&month=${ym}`)).status, 403);

    // Admin export
    const admin = await ctx.http.login('admin', ctx.env.seedPassword);
    const csvRes = await admin.get(`/export_csv.php?type=bookings&month=${ym}`);
    ctx.assert.equal(csvRes.status, 200);
    ctx.assert.match(csvRes.headers.get('content-type') || '', /text\/csv/i);
    ctx.assert.match(csvRes.headers.get('content-disposition') || '', /\.csv/i);
    ctx.assert.equal(csvRes.bodyBuffer[0], 0xef);
    ctx.assert.equal(csvRes.bodyBuffer[1], 0xbb);
    ctx.assert.equal(csvRes.bodyBuffer[2], 0xbf);

    const parsed = parseCsv(csvRes.bodyBuffer.toString('utf8'));
    ctx.assert.ok(parsed.length >= 2, 'CSV must include header + data rows');
    const dataRows = parsed.slice(1);
    const expectedDbCount = Number(
      await ctx.db.scalar("SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?", [ym])
    );
    ctx.assert.equal(dataRows.length, expectedDbCount, 'CSV row count must equal MySQL monthly bookings count');
    const matchRow = dataRows.find((r) => r.join('|').includes(bk.reference_code) || r.join('|').includes(title));
    ctx.assert.ok(matchRow, 'Exported CSV must contain newly approved booking row');
    ctx.assert.ok(/approved|อนุมัติแล้ว/i.test(matchRow.join('|')), 'Exported row must show approved status');
  }
});

test({
  id: 'T3-PW-12',
  tier: 3,
  feature: 'F3,F12',
  name: 'QR ?room_id=1 scan preserves login redirect and submits fault report with mobile photo upload',
  async fn(ctx) {
    const tag = uniqueTag('PW12');
    const issueDesc = `${tag}-QR-RoomFault`;
    let maintId = null;

    ctx.onCleanup(async () => {
      if (maintId) {
        await ctx.db.execSql('DELETE FROM maintenance_requests WHERE id = ?', [maintId]).catch(() => {});
      }
    });

    const pngBuf = loadFixtureBuffer(ctx, 'sample.png', () => Buffer.from(VALID_1X1_PNG_BASE64, 'base64'));
    const tmpPng = path.join(os.tmpdir(), `${tag}.png`);
    fs.writeFileSync(tmpPng, pngBuf);
    ctx.onCleanup(async () => {
      try { if (fs.existsSync(tmpPng)) fs.unlinkSync(tmpPng); } catch {}
    });

    const pw = await openTestPage(ctx, ctx.browser.MOBILE);
    try {
      await pw.page.goto(`${ctx.baseUrl}/maintenance.php?room_id=1`, { waitUntil: 'domcontentloaded' });
      const loginUrl = new URL(pw.page.url());
      ctx.assert.ok(loginUrl.pathname.endsWith('/login.php'), `Expected redirect to login.php, got ${pw.page.url()}`);
      const redirectParam = loginUrl.searchParams.get('redirect') || '';
      ctx.assert.ok(redirectParam.includes('maintenance.php') && redirectParam.includes('room_id=1'), `Redirect param lost query: ${redirectParam}`);

      await pw.page.locator('input[name="identifier"], input[data-testid="login-identifier"]').first().fill('user01');
      await pw.page.locator('input[name="password"], input[data-testid="login-password"]').first().fill(ctx.env.seedPassword);
      await Promise.all([
        pw.page.waitForURL((u) => new URL(u).pathname.endsWith('/maintenance.php'), { timeout: 10000 }),
        pw.page.locator('button[type="submit"], button[data-testid="login-submit"]').first().click()
      ]);
      await pw.page.waitForLoadState('domcontentloaded');

      const afterUrl = new URL(pw.page.url());
      ctx.assert.equal(afterUrl.searchParams.get('room_id'), '1', 'room_id=1 must be preserved after login redirect');

      const pageText = await pw.page.locator('body').innerText();
      ctx.assert.ok(/RM-101|ห้องประชุมราชพฤกษ์/i.test(pageText), 'Room 1 details must pre-populate on maintenance.php?room_id=1');

      const fileInput = pw.page.locator('input[type="file"]').first();
      ctx.assert.ok((await fileInput.count()) >= 1, 'Photo upload input required');
      const acceptAttr = (await fileInput.getAttribute('accept')) || '';
      ctx.assert.match(acceptAttr, /image/i, 'File input must accept images');
      const captureAttr = await fileInput.getAttribute('capture');
      ctx.assert.ok(captureAttr !== null, 'Mobile camera capture attribute expected on maintenance photo input');

      await fileInput.setInputFiles(tmpPng);

      // Fill empty location/equipment only if not pre-filled
      const locInput = pw.page.locator('input[name*="location" i], textarea[name*="location" i]').first();
      if ((await locInput.count()) > 0 && (await locInput.inputValue()).trim() === '') {
        await locInput.fill('อาคารอำนวยการ ชั้น 1 — ห้องประชุมราชพฤกษ์');
      }
      const eqInput = pw.page.locator('input[name*="equipment" i]').first();
      if ((await eqInput.count()) > 0 && (await eqInput.inputValue()).trim() === '') {
        await eqInput.fill('เครื่องปรับอากาศ');
      }

      const issueInput = pw.page.locator('textarea[name*="issue" i], textarea[name*="description" i], textarea').first();
      await issueInput.fill(issueDesc);

      await Promise.all([
        pw.page.waitForLoadState('domcontentloaded'),
        pw.page.locator('main button[type="submit"], form:not([action*="logout"]) button[type="submit"]').first().click()
      ]);
      await pw.page.waitForTimeout(300);

      const mRows = await ctx.db.queryJson('SELECT * FROM maintenance_requests WHERE issue_description = ?', [issueDesc]);
      ctx.assert.equal(mRows.length, 1, 'Expected maintenance_requests row inserted');
      const m = mRows[0];
      maintId = m.id;
      registerUploadCleanup(ctx, m.before_photo_path);
      ctx.assert.equal(Number(m.reporter_id), 6);
      ctx.assert.equal(Number(m.room_id), 1);
      ctx.assert.equal(m.status, 'รอรับเรื่อง');
      ctx.assert.ok(m.before_photo_path, 'before_photo_path must be saved');

      const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
      const dlRes = await user1.get(`/download.php?type=maintenance_before&id=${maintId}`);
      ctx.assert.equal(dlRes.status, 200);
      ctx.assert.match(dlRes.headers.get('content-type') || '', /image\/png/i);
      pw.errors.assertNoErrors('T3-PW-12 QR maintenance mobile');
    } finally {
      await pw.close();
    }
  }
});

test({
  id: 'T3-PW-13',
  tier: 3,
  feature: 'F12,F13',
  name: 'QR ?asset_id=4 maintenance creation + technician 4-status progression + after-photo download & IDOR',
  async fn(ctx) {
    const tag = uniqueTag('PW13');
    const issueDesc = `${tag}-AssetFault`;
    let maintId = null;
    ctx.onCleanup(async () => {
      if (maintId) {
        await ctx.db.execSql('DELETE FROM maintenance_requests WHERE id = ?', [maintId]).catch(() => {});
      }
    });

    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    const getMaint = await user2.get('/maintenance.php?asset_id=4');
    ctx.assert.equal(getMaint.status, 200);
    ctx.assert.ok(/AST-PRJ-01|เครื่องฉายโปรเจคเตอร์พกพา/i.test(getMaint.text), 'asset_id=4 details must pre-populate');

    const form = discoverForm(getMaint.text, { actionHint: 'maintenance', fieldHints: ['issue', 'description'] });
    const { payload, csrfToken } = seedFormPayload(form, getMaint.text);
    setField(payload, form, ['asset_id'], '4', 'asset_id');
    if (!pickField(form, ['location_text', 'location'])?.value) {
      setField(payload, form, ['location_text', 'location'], 'ห้องพัสดุกลาง ชั้น 1', 'location_text');
    }
    if (!pickField(form, ['equipment_name', 'equipment'])?.value) {
      setField(payload, form, ['equipment_name', 'equipment'], 'เครื่องฉายโปรเจคเตอร์พกพา', 'equipment_name');
    }
    setField(payload, form, ['issue_description', 'description', 'issue'], issueDesc, 'issue_description');

    const pngBuf = loadFixtureBuffer(ctx, 'sample.png', () => Buffer.from(VALID_1X1_PNG_BASE64, 'base64'));
    const fileField = findFileField(form, 'photo');
    const createRes = await user2.postMultipart(
      resolveActionUrl(form.action, '/maintenance.php?asset_id=4', getMaint.url),
      payload,
      { [fileField]: { buffer: pngBuf, filename: 'pw13_before.png', contentType: 'image/png' } },
      { headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {} }
    );
    ctx.assert.ok(createRes.status < 400);

    const mRows = await ctx.db.queryJson('SELECT * FROM maintenance_requests WHERE issue_description = ?', [issueDesc]);
    ctx.assert.equal(mRows.length, 1);
    maintId = mRows[0].id;
    registerUploadCleanup(ctx, mRows[0].before_photo_path);
    ctx.assert.equal(Number(mRows[0].reporter_id), 7);
    ctx.assert.equal(Number(mRows[0].asset_id), 4);
    ctx.assert.equal(mRows[0].status, 'รอรับเรื่อง');
    ctx.assert.equal(mRows[0].completed_at, null);

    // RBAC: regular user cannot access maintenance_manage.php
    ctx.assert.equal((await user2.get('/maintenance_manage.php')).status, 403);

    // Technician staff01 transitions through all 4 Thai statuses
    const staff = await ctx.http.login('staff01', ctx.env.seedPassword);
    const updateTicket = async (nextStatus, notes, afterFile = null) => {
      const mgRes = await staff.get('/maintenance_manage.php');
      ctx.assert.equal(mgRes.status, 200);
      const tForm = discoverForm(mgRes.text, {
        hiddenMatch: ['id', String(maintId)],
        actionHint: 'maintenance_manage',
        fieldHints: ['status', 'repair_notes']
      });
      const { payload: p, csrfToken: tCsrf } = seedFormPayload(tForm, mgRes.text);
      setField(p, tForm, ['id', 'ticket_id', 'maintenance_id'], String(maintId), 'id');
      setField(p, tForm, ['status', 'new_status'], nextStatus, 'status');
      setField(p, tForm, ['repair_notes', 'notes', 'note'], notes, 'repair_notes');
      const targetUrl = resolveActionUrl(tForm.action, '/maintenance_manage.php', mgRes.url);
      const hdrs = tCsrf ? { 'X-CSRF-Token': tCsrf } : {};
      if (afterFile) {
        const afField = findFileField(tForm, 'after_photo');
        return staff.postMultipart(targetUrl, p, { [afField]: afterFile }, { headers: hdrs });
      }
      return staff.postForm(targetUrl, p, { headers: hdrs });
    };

    await updateTicket('กำลังซ่อม', 'กำลังตรวจเช็กเครื่อง');
    const st2 = (await ctx.db.queryJson('SELECT status, assigned_staff_id FROM maintenance_requests WHERE id = ?', [maintId]))[0];
    ctx.assert.equal(st2.status, 'กำลังซ่อม');
    ctx.assert.equal(Number(st2.assigned_staff_id), 2);

    await updateTicket('รออะไหล่', 'รอหลอดภาพ');
    ctx.assert.equal(await ctx.db.scalar('SELECT status FROM maintenance_requests WHERE id = ?', [maintId]), 'รออะไหล่');

    await updateTicket('เสร็จสิ้น', 'ซ่อมเสร็จแล้ว', {
      buffer: pngBuf,
      filename: 'pw13_after.png',
      contentType: 'image/png'
    });
    const st4 = (await ctx.db.queryJson('SELECT status, completed_at, after_photo_path FROM maintenance_requests WHERE id = ?', [maintId]))[0];
    registerUploadCleanup(ctx, st4.after_photo_path);
    ctx.assert.equal(st4.status, 'เสร็จสิ้น');
    ctx.assert.notEqual(st4.completed_at, null);
    ctx.assert.ok(st4.after_photo_path, 'after_photo_path must be stored on completion');

    // Reporter can download after photo; unrelated user01 gets 403 IDOR block
    const dlOwner = await user2.get(`/download.php?type=maintenance_after&id=${maintId}`);
    ctx.assert.equal(dlOwner.status, 200);
    ctx.assert.match(dlOwner.headers.get('content-type') || '', /image\/png/i);

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const dlIdor = await user1.get(`/download.php?type=maintenance_after&id=${maintId}`);
    ctx.assert.equal(dlIdor.status, 403, 'Unrelated user01 must receive 403 on maintenance_after download');
  }
});

test({
  id: 'T3-PW-14',
  tier: 3,
  feature: 'F13,F15,F17',
  name: 'Maintenance 4-status lifecycle reconciles across Executive Dashboard stats and monthly maintenance CSV export',
  async fn(ctx) {
    await ctx.resetDb();
    const ym = currentYearMonth();
    const tag = uniqueTag('PW14');
    const issueDesc = `${tag}-MaintCsv`;
    let maintId = null;
    ctx.onCleanup(async () => {
      if (maintId) {
        await ctx.db.execSql('DELETE FROM maintenance_requests WHERE id = ?', [maintId]).catch(() => {});
      }
    });

    const getCounts = async () => ({
      pending: Number(await ctx.db.scalar("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'รอรับเรื่อง'")),
      done: Number(await ctx.db.scalar("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'เสร็จสิ้น'"))
    });
    const base = await getCounts();

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const getMaint = await user1.get('/maintenance.php?room_id=1');
    const form = discoverForm(getMaint.text, { actionHint: 'maintenance', fieldHints: ['issue', 'description'] });
    const { payload, csrfToken } = seedFormPayload(form, getMaint.text);
    setField(payload, form, ['room_id'], '1', 'room_id');
    if (!pickField(form, ['location_text', 'location'])?.value) {
      setField(payload, form, ['location_text', 'location'], 'อาคารอำนวยการ ชั้น 1', 'location_text');
    }
    setField(payload, form, ['equipment_name', 'equipment'], 'ระบบไฟฟ้า', 'equipment_name');
    setField(payload, form, ['issue_description', 'description', 'issue'], issueDesc, 'issue_description');
    await user1.postForm(resolveActionUrl(form.action, '/maintenance.php?room_id=1', getMaint.url), payload, {
      headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
    });

    const mRow = (await ctx.db.queryJson('SELECT id, ticket_code FROM maintenance_requests WHERE issue_description = ?', [issueDesc]))[0];
    ctx.assert.ok(mRow);
    maintId = mRow.id;

    const admin = await ctx.http.login('admin', ctx.env.seedPassword);
    const statsMid = await admin.get(`/api/dashboard_stats.php?month=${ym}`);
    ctx.assert.equal(statsMid.status, 200);
    ctx.assert.equal((await getCounts()).pending, base.pending + 1);

    const staff = await ctx.http.login('staff01', ctx.env.seedPassword);
    const transition = async (targetStatus, note) => {
      const mg = await staff.get('/maintenance_manage.php');
      const tf = discoverForm(mg.text, { hiddenMatch: ['id', String(maintId)], actionHint: 'maintenance_manage', fieldHints: ['status'] });
      const { payload: p, csrfToken: c } = seedFormPayload(tf, mg.text);
      setField(p, tf, ['id', 'ticket_id', 'maintenance_id'], String(maintId), 'id');
      setField(p, tf, ['status', 'new_status'], targetStatus, 'status');
      setField(p, tf, ['repair_notes', 'notes'], note, 'repair_notes');
      await staff.postForm(resolveActionUrl(tf.action, '/maintenance_manage.php', mg.url), p, { headers: c ? { 'X-CSRF-Token': c } : {} });
    };
    await transition('กำลังซ่อม', 'PW14 fixing');
    await transition('เสร็จสิ้น', 'PW14 done');

    const afterCounts = await getCounts();
    ctx.assert.equal(afterCounts.pending, base.pending);
    ctx.assert.equal(afterCounts.done, base.done + 1);

    const statsAfter = await admin.get(`/api/dashboard_stats.php?month=${ym}`);
    ctx.assert.equal(statsAfter.status, 200);
    ctx.assert.notEqual(JSON.stringify(statsAfter.json()), JSON.stringify(statsMid.json()), 'Dashboard stats must update after repair completion');

    const csvRes = await admin.get(`/export_csv.php?type=maintenance&month=${ym}`);
    ctx.assert.equal(csvRes.status, 200);
    ctx.assert.equal(csvRes.bodyBuffer[0], 0xef);
    ctx.assert.equal(csvRes.bodyBuffer[1], 0xbb);
    ctx.assert.equal(csvRes.bodyBuffer[2], 0xbf);
    const rows = parseCsv(csvRes.bodyBuffer.toString('utf8')).slice(1);
    const expectedDbCount = Number(
      await ctx.db.scalar("SELECT COUNT(*) FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ?", [ym])
    );
    ctx.assert.equal(rows.length, expectedDbCount);
    const foundCsv = rows.find((r) => r.join('|').includes(mRow.ticket_code) || r.join('|').includes(issueDesc));
    ctx.assert.ok(foundCsv && foundCsv.join('|').includes('เสร็จสิ้น'), 'Maintenance CSV must reflect completed ticket');
  }
});

test({
  id: 'T3-PW-15',
  tier: 3,
  feature: 'F3,F14',
  name: 'Asset/key borrow decrements stock atomically, blocks exhaustion, and logs return_handler_id on return',
  async fn(ctx) {
    await ctx.resetDb();
    ctx.onCleanup(async () => {
      await ctx.resetDb().catch(() => {});
    });

    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    ctx.assert.equal((await ext1.get('/assets_borrow.php')).status, 403, 'External user must get 403 on assets_borrow.php');

    const doBorrow = async (client, purpose) => {
      const pageRes = await client.get('/assets_borrow.php');
      ctx.assert.equal(pageRes.status, 200);
      const form = discoverForm(pageRes.text, { actionHint: 'assets_borrow', fieldHints: ['asset_id', 'quantity'] });
      const { payload, csrfToken } = seedFormPayload(form, pageRes.text);
      const assetOpt = pickSelectOption(form, ['asset_id', 'asset'], ['1', 'AST-KEY-101']);
      payload[assetOpt ? assetOpt.fieldName : 'asset_id'] = assetOpt ? assetOpt.value : '1';
      setField(payload, form, ['quantity', 'qty'], '1', 'quantity');
      setField(payload, form, ['purpose', 'notes'], purpose, 'purpose');
      const expField = pickField(form, ['expected_return_at', 'return_date', 'expected_return_date']);
      if (expField) {
        payload[expField.name] = expField.type === 'datetime-local' ? '2027-08-01T17:00' : (expField.type === 'date' ? '2027-08-01' : '2027-08-01 17:00:00');
      } else {
        payload.expected_return_at = '2027-08-01 17:00:00';
      }
      setField(payload, form, ['action', 'mode'], 'borrow', 'action');
      return client.postForm(resolveActionUrl(form.action, '/assets_borrow.php', pageRes.url), payload, {
        headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
      });
    };

    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const b1Res = await doBorrow(user1, 'PW15-Borrow-1');
    ctx.assert.ok(b1Res.status < 400);
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1')), 1);
    const brw1Id = await ctx.db.scalar("SELECT id FROM asset_borrowings WHERE purpose = 'PW15-Borrow-1'");
    ctx.assert.ok(brw1Id);

    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    const b2Res = await doBorrow(user2, 'PW15-Borrow-2');
    ctx.assert.ok(b2Res.status < 400);
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1')), 0);
    const brw2Id = await ctx.db.scalar("SELECT id FROM asset_borrowings WHERE purpose = 'PW15-Borrow-2'");
    ctx.assert.ok(brw2Id);

    // Exhausted stock guard
    const b3Res = await doBorrow(user1, 'PW15-Borrow-Exhausted');
    assertBlockedOrError(ctx.assert, b3Res, 'T3-PW-15 zero-stock borrow');
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1')), 0, 'Stock must never go negative');
    ctx.assert.equal(Number(await ctx.db.scalar("SELECT COUNT(*) FROM asset_borrowings WHERE purpose = 'PW15-Borrow-Exhausted'")), 0);

    // Return by staff01
    const staff = await ctx.http.login('staff01', ctx.env.seedPassword);
    const doReturn = async (borrowingId, note) => {
      const pageRes = await staff.get('/assets_borrow.php');
      ctx.assert.equal(pageRes.status, 200);
      const rForm = discoverForm(pageRes.text, {
        hiddenMatch: ['borrowing_id', String(borrowingId)],
        actionHint: 'return',
        fieldHints: ['return_note', 'borrowing_id']
      });
      const { payload, csrfToken } = seedFormPayload(rForm, pageRes.text);
      setField(payload, rForm, ['borrowing_id', 'borrow_id', 'id'], String(borrowingId), 'borrowing_id');
      setField(payload, rForm, ['action', 'mode'], 'return', 'action');
      setField(payload, rForm, ['return_note', 'note', 'notes'], note, 'return_note');
      return staff.postForm(resolveActionUrl(rForm.action, '/assets_borrow.php', pageRes.url), payload, {
        headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
      });
    };

    await doReturn(brw1Id, 'PW15-Returned-1');
    const row1After = (await ctx.db.queryJson('SELECT status, returned_at, return_handler_id, return_note FROM asset_borrowings WHERE id = ?', [brw1Id]))[0];
    ctx.assert.equal(row1After.status, 'returned');
    ctx.assert.notEqual(row1After.returned_at, null);
    ctx.assert.equal(Number(row1After.return_handler_id), 2);
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1')), 1);

    await doReturn(brw2Id, 'PW15-Returned-2');
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1')), 2);
  }
});

test({
  id: 'T3-PW-16',
  tier: 3,
  feature: 'F4,F5',
  name: 'Admin calendar event CRUD + FullCalendar rendering + role-based navigation and API RBAC',
  async fn(ctx) {
    const tag = uniqueTag('PW16');
    const title = `${tag}-AdminEvt`;
    const dateStr = nextMonthDay(15);
    const { start: monthStart, end: monthEnd } = nextMonthRange();
    let eventId = null;

    ctx.onCleanup(async () => {
      if (eventId) {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE id = ?', [eventId]).catch(() => {});
      }
      await ctx.db.execSql('DELETE FROM calendar_events WHERE title LIKE ?', [`${tag}%`]).catch(() => {});
    });

    // Role-based nav check for general user01
    const user1 = await ctx.http.login('user01', ctx.env.seedPassword);
    const homeRes = await user1.get('/index.php');
    ctx.assert.equal(homeRes.status, 200);
    for (const forbidden of ['approvals.php', 'dashboard.php', 'maintenance_manage.php', 'qr_codes.php']) {
      ctx.assert.ok(!homeRes.text.includes(forbidden), `user01 sidebar must hide ${forbidden}`);
    }
    for (const allowed of ['calendar.php', 'book_room.php', 'book_vehicle.php', 'my_bookings.php', 'maintenance.php', 'assets_borrow.php']) {
      ctx.assert.ok(homeRes.text.includes(allowed), `user01 sidebar must show ${allowed}`);
    }

    // API RBAC check
    const user1Cal = await user1.get('/calendar.php');
    const user1Csrf = extractCsrfToken(user1Cal.text).token;
    const unauthSave = await user1.postForm(
      '/api/calendar_event_save.php',
      { title: `${tag}-Unauth`, start_datetime: `${dateStr} 09:00:00`, end_datetime: `${dateStr} 11:00:00`, _csrf_token: user1Csrf || '' },
      { headers: user1Csrf ? { 'X-CSRF-Token': user1Csrf } : {} }
    );
    ctx.assert.equal(unauthSave.status, 403, 'user01 must receive 403 from calendar_event_save.php');

    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);
    const extCal = await ext1.get('/calendar.php');
    const extCsrf = extractCsrfToken(extCal.text).token;
    const extSave = await ext1.postForm(
      '/api/calendar_event_save.php',
      { title: `${tag}-ExtUnauth`, start_datetime: `${dateStr} 09:00:00`, end_datetime: `${dateStr} 11:00:00`, _csrf_token: extCsrf || '' },
      { headers: extCsrf ? { 'X-CSRF-Token': extCsrf } : {} }
    );
    ctx.assert.equal(extSave.status, 403, 'ext01 must receive 403 from calendar_event_save.php');

    // Admin creates event
    const admin = await ctx.http.login('admin', ctx.env.seedPassword);
    const adminCal = await admin.get('/calendar.php');
    const adminCsrf = extractCsrfToken(adminCal.text).token;
    const savePayload = {
      title,
      event_type: 'meeting',
      location: 'ห้องประชุมใหญ่',
      description: 'PW16 Admin created event',
      start_datetime: `${dateStr} 09:00:00`,
      end_datetime: `${dateStr} 12:00:00`,
      is_public: 1,
      _csrf_token: adminCsrf || ''
    };
    const adminHeaders = adminCsrf ? { 'X-CSRF-Token': adminCsrf } : {};
    let saveRes = await admin.postForm('/api/calendar_event_save.php', savePayload, { headers: adminHeaders });
    if (saveRes.status === 415 || /json/i.test(saveRes.text || '')) {
      saveRes = await admin.postJson('/api/calendar_event_save.php', savePayload, { headers: adminHeaders });
    }
    ctx.assert.ok(saveRes.status < 400, `Admin calendar_event_save failed: HTTP ${saveRes.status}`);

    const evRows = await ctx.db.queryJson('SELECT * FROM calendar_events WHERE title = ?', [title]);
    ctx.assert.equal(evRows.length, 1);
    eventId = evRows[0].id;
    ctx.assert.equal(Number(evRows[0].created_by), 1);

    const apiEventsRes = await user1.get(`/api/calendar_events.php?start=${encodeURIComponent(monthStart)}&end=${encodeURIComponent(monthEnd)}`);
    ctx.assert.equal(apiEventsRes.status, 200);
    ctx.assert.ok(JSON.stringify(apiEventsRes.json()).includes(title), 'Admin-created event must appear in calendar_events.php');

    // Playwright Desktop rendering
    const pw = await openTestPage(ctx, ctx.browser.DESKTOP);
    try {
      await ctx.browser.loginUi(pw.page, 'user01', ctx.env.seedPassword);
      await pw.page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      await pw.page.locator('.fc-next-button').first().waitFor({ state: 'visible' });
      await pw.page.locator('.fc-next-button').first().click();
      await pw.page.locator('.fc-event', { hasText: title }).first().waitFor({ state: 'visible', timeout: 10000 });
      pw.errors.assertNoErrors('T3-PW-16 calendar rendering');
    } finally {
      await pw.close();
    }
  }
});

test({
  id: 'T3-PW-17',
  tier: 3,
  feature: 'F3,F6',
  name: 'External permission letter upload validation (disguised & >2MB oversized) + download.php owner/approver vs IDOR',
  async fn(ctx) {
    const tag = uniqueTag('PW17');
    const validTitle = `${tag}-ExtPdf`;
    let bookingId = null;
    let ext2Id = null;

    ctx.onCleanup(async () => {
      if (bookingId) {
        await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [bookingId]).catch(() => {});
        await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [bookingId]).catch(() => {});
        await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [bookingId]).catch(() => {});
      }
      if (ext2Id) {
        await ctx.db.execSql('DELETE FROM users WHERE id = ?', [ext2Id]).catch(() => {});
      }
    });

    const ext1 = await ctx.http.login('ext01', ctx.env.seedPassword);

    // 1. Disguised PHP-in-PNG rejected
    const disguisedBuf = loadFixtureBuffer(ctx, 'disguised_image.png', () => Buffer.from("<?php echo 'disguised'; ?>", 'utf8'));
    const disRes = await submitRoomBooking(ext1, {
      roomId: 2,
      roomCode: 'RM-AUD',
      title: `${tag}-Disguised`,
      purpose: 'Invalid disguised upload',
      start: '2027-08-05 09:00:00',
      end: '2027-08-05 12:00:00',
      attendees: 50,
      fileSpec: { buffer: disguisedBuf, filename: 'evil.png', contentType: 'image/png' }
    });
    assertBlockedOrError(ctx.assert, disRes, 'T3-PW-17 disguised PHP image');
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings WHERE title = ?', [`${tag}-Disguised`])), 0);

    // 2. Oversized > 2MB image rejected via server-side postMultipart (bypasses app.js client resize)
    const oversizedBuf = loadFixtureBuffer(
      ctx,
      'oversized_dummy.jpg',
      () => Buffer.concat([Buffer.from([0xff, 0xd8, 0xff, 0xe0]), Buffer.alloc(2300000, 0x00), Buffer.from([0xff, 0xd9])])
    );
    const overRes = await submitRoomBooking(ext1, {
      roomId: 2,
      roomCode: 'RM-AUD',
      title: `${tag}-Oversized`,
      purpose: 'Oversized upload guard',
      start: '2027-08-05 09:00:00',
      end: '2027-08-05 12:00:00',
      attendees: 50,
      fileSpec: { buffer: oversizedBuf, filename: 'oversized_dummy.jpg', contentType: 'image/jpeg' }
    });
    assertBlockedOrError(ctx.assert, overRes, 'T3-PW-17 >2MB oversized image');
    ctx.assert.equal(Number(await ctx.db.scalar('SELECT COUNT(*) FROM bookings WHERE title = ?', [`${tag}-Oversized`])), 0);

    // 3. Valid PDF accepted
    const pdfBuf = loadFixtureBuffer(ctx, 'permission_letter.pdf', () => Buffer.from(VALID_MINIMAL_PDF, 'utf8'));
    const okRes = await submitRoomBooking(ext1, {
      roomId: 2,
      roomCode: 'RM-AUD',
      title: validTitle,
      purpose: 'Valid permission letter',
      start: '2027-08-05 09:00:00',
      end: '2027-08-05 12:00:00',
      attendees: 80,
      fileSpec: { buffer: pdfBuf, filename: 'pw17_letter.pdf', contentType: 'application/pdf' }
    });
    ctx.assert.ok(okRes.status < 400);

    const bRow = (await ctx.db.queryJson('SELECT id, requester_id, permission_letter_path FROM bookings WHERE title = ?', [validTitle]))[0];
    ctx.assert.ok(bRow);
    bookingId = bRow.id;
    registerUploadCleanup(ctx, bRow.permission_letter_path);
    ctx.assert.equal(Number(bRow.requester_id), 8);
    ctx.assert.match(String(bRow.permission_letter_path || ''), /^uploads\/permissions\/.+\.pdf$/i);

    // 4. Direct /uploads/ and unauthenticated download blocked
    const anon = ctx.http.createClient();
    ctx.assert.equal((await anon.get('/uploads/')).status, 403, 'Direct /uploads/ access must return 403');
    const anonDl = await anon.get(`/download.php?type=permission&id=${bookingId}`);
    ctx.assert.ok(
      anonDl.url.includes('/login.php') || anonDl.status === 401 || anonDl.status === 403,
      'Unauthenticated download must redirect to login or reject'
    );
    ctx.assert.ok(!anonDl.text.startsWith('%PDF-'), 'Unauthenticated download must never expose PDF bytes');

    // 5. Cross-user & cross-external IDOR blocked with 403
    const user2 = await ctx.http.login('user02', ctx.env.seedPassword);
    ctx.assert.equal((await user2.get(`/download.php?type=permission&id=${bookingId}`)).status, 403);

    const ext2Email = `pw17_ext2_${Date.now()}@example.org`;
    const { client: ext2Client } = await ctx.http.registerExternal({
      full_name: 'External Peer PW17',
      organization: 'Other Org',
      phone: '0890000017',
      email: ext2Email
    });
    ext2Id = await ctx.db.scalar('SELECT id FROM users WHERE email = ?', [ext2Email]);
    ctx.assert.equal((await ext2Client.get(`/download.php?type=permission&id=${bookingId}`)).status, 403, 'Cross-external IDOR must return 403');

    // 6. Owner and privileged approver download succeed
    const ownerDl = await ext1.get(`/download.php?type=permission&id=${bookingId}`);
    ctx.assert.equal(ownerDl.status, 200);
    ctx.assert.match(ownerDl.headers.get('content-type') || '', /application\/pdf/i);
    ctx.assert.ok(ownerDl.bodyBuffer.toString('utf8', 0, 5) === '%PDF-', 'Owner download must return valid PDF');

    const head1 = await ctx.http.login('head01', ctx.env.seedPassword);
    const headDl = await head1.get(`/download.php?type=permission&id=${bookingId}`);
    ctx.assert.equal(headDl.status, 200);
    ctx.assert.match(headDl.headers.get('content-type') || '', /application\/pdf/i);
    ctx.assert.ok(headDl.bodyBuffer.toString('utf8', 0, 5) === '%PDF-', 'Approver download must return valid PDF');
  }
});
