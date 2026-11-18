import fs from 'node:fs';
import path from 'node:path';
import { test } from '../lib/harness.mjs';
import { extractFormInfo } from '../lib/http.mjs';

const CSRF_NAMES = ['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token'];

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
  while ((m = re.exec(attrText)) !== null) {
    attrs[m[1].toLowerCase()] = decodeEntities(m[2] ?? m[3] ?? m[4] ?? '');
  }
  return attrs;
}

function extractCsrfToken(html) {
  if (!html || typeof html !== 'string') return null;
  const found = new Map();
  for (const m of html.matchAll(/<input\b([^>]*)>/gi)) {
    const attrs = parseTagAttrs(m[1]);
    if (!attrs.name) continue;
    const lower = attrs.name.toLowerCase();
    if (CSRF_NAMES.includes(lower) && !found.has(lower)) {
      found.set(lower, attrs.value ?? '');
    }
  }
  for (const name of CSRF_NAMES) {
    if (found.has(name)) return found.get(name);
  }
  for (const m of html.matchAll(/<meta\b([^>]*)>/gi)) {
    const attrs = parseTagAttrs(m[1]);
    const name = (attrs.name || '').toLowerCase();
    if (/^(?:_?csrf[-_]?token|_token)$/.test(name) && typeof attrs.content === 'string') {
      return attrs.content;
    }
  }
  return null;
}

function extractAllForms(html) {
  const forms = [];
  if (!html || typeof html !== 'string') return forms;
  const formRe = /<form\b([^>]*)>([\s\S]*?)<\/form>/gi;
  let fm;
  while ((fm = formRe.exec(html)) !== null) {
    const attrs = parseTagAttrs(fm[1]);
    const body = fm[2];
    const inputs = [];
    for (const tm of body.matchAll(/<(input|select|textarea|button)\b([^>]*)>/gi)) {
      const tag = tm[1].toLowerCase();
      const tAttrs = parseTagAttrs(tm[2]);
      if (!tAttrs.name) continue;
      inputs.push({
        tag,
        type: (tAttrs.type || (tag === 'input' ? 'text' : tag)).toLowerCase(),
        name: tAttrs.name,
        value: tAttrs.value ?? ''
      });
    }
    let csrfFieldName = null;
    let csrfToken = null;
    for (const cName of CSRF_NAMES) {
      const match = inputs.find((inp) => inp.name.toLowerCase() === cName);
      if (match) {
        csrfFieldName = match.name;
        csrfToken = match.value;
        break;
      }
    }
    if (csrfToken === null) {
      csrfToken = extractCsrfToken(html);
      if (csrfToken !== null && !csrfFieldName) {
        csrfFieldName = '_csrf_token';
      }
    }
    const defaults = {};
    for (const inp of inputs) {
      if (CSRF_NAMES.includes(inp.name.toLowerCase())) continue;
      if (inp.type === 'hidden') {
        defaults[inp.name] = inp.value;
      }
    }
    forms.push({
      action: attrs.action ?? '',
      method: (attrs.method || 'GET').toUpperCase(),
      enctype: attrs.enctype ?? '',
      body,
      inputs,
      csrfFieldName,
      csrfToken,
      defaults
    });
  }
  return forms;
}

function extractFormWithCsrf(html, actionHint = null, bodyPredicate = null) {
  const forms = extractAllForms(html);
  const nonLogoutForms = forms.filter((f) => !/logout/i.test(f.action) && !/logout/i.test(f.body));
  const pool = nonLogoutForms.length > 0 ? nonLogoutForms : forms;

  let chosen = null;
  if (typeof bodyPredicate === 'function') {
    chosen = pool.find((f) => bodyPredicate(f)) || forms.find((f) => bodyPredicate(f)) || null;
  }
  if (!chosen && actionHint) {
    const hint = String(actionHint).toLowerCase();
    chosen = pool.find((f) => f.action.toLowerCase().includes(hint)) || null;
  }
  if (!chosen) {
    chosen = pool[0] || null;
  }

  if (!chosen) {
    const base = extractFormInfo(html, actionHint);
    const token = extractCsrfToken(html);
    return {
      action: base.action,
      method: base.method,
      enctype: '',
      body: '',
      inputs: base.inputs,
      csrfFieldName: base.csrfFieldName || (token ? '_csrf_token' : null),
      csrfToken: token ?? base.csrfToken,
      defaults: {}
    };
  }

  return chosen;
}

function pickField(inputs, candidates, fallback, typeFilter = null) {
  for (const cand of candidates) {
    const hit = (inputs || []).find(
      (inp) => inp.name.toLowerCase() === cand.toLowerCase() && (!typeFilter || typeFilter(inp))
    );
    if (hit) return hit.name;
  }
  return fallback;
}

function resolveActionUrl(action, fallbackPath, currentUrl) {
  if (!action) return fallbackPath;
  try {
    return new URL(action, currentUrl).toString();
  } catch {
    return action;
  }
}

function formatDateOffset(daysOffset, timeStr = '17:00') {
  const d = new Date(Date.now() + daysOffset * 86400 * 1000);
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return {
    date: `${yyyy}-${mm}-${dd}`,
    datetimeLocal: `${yyyy}-${mm}-${dd}T${timeStr}`,
    mysql: `${yyyy}-${mm}-${dd} ${timeStr}:00`
  };
}

function formatDatetimeForInput(inputs, fieldName, daysOffset = 2, timeStr = '17:00') {
  const f = formatDateOffset(daysOffset, timeStr);
  const inp = (inputs || []).find((i) => i.name === fieldName);
  if (inp?.type === 'date') return f.date;
  if (inp?.type === 'datetime-local') return f.datetimeLocal;
  return f.datetimeLocal;
}

function registerMaintenanceFileCleanup(ctx, relPath) {
  if (!relPath || typeof relPath !== 'string') return;
  const cleanRel = relPath.replace(/^\/+/, '').replace(/\\/g, '/');
  if (!cleanRel.startsWith('uploads/maintenance/')) return;
  const fullPath = path.resolve(ctx.env.projectRoot, cleanRel);
  const maintDir = path.resolve(ctx.env.projectRoot, 'uploads', 'maintenance');
  if (!fullPath.toLowerCase().startsWith((maintDir + path.sep).toLowerCase())) return;
  ctx.onCleanup(async () => {
    try {
      if (fs.existsSync(fullPath)) {
        fs.unlinkSync(fullPath);
      }
    } catch {
      // ignore cleanup unlink errors
    }
  });
}

function registerTicketCleanup(ctx, ticketId) {
  ctx.onCleanup(async () => {
    try {
      const rows = await ctx.db.queryJson(
        'SELECT before_photo_path, after_photo_path FROM maintenance_requests WHERE id = ?',
        [Number(ticketId)]
      );
      if (rows[0]) {
        for (const p of [rows[0].before_photo_path, rows[0].after_photo_path]) {
          if (p && typeof p === 'string') {
            const cleanRel = p.replace(/^\/+/, '').replace(/\\/g, '/');
            if (cleanRel.startsWith('uploads/maintenance/')) {
              const full = path.resolve(ctx.env.projectRoot, cleanRel);
              if (fs.existsSync(full)) fs.unlinkSync(full);
            }
          }
        }
      }
      await ctx.db.execSql('DELETE FROM maintenance_requests WHERE id = ?', [Number(ticketId)]);
    } catch {
      // best-effort cleanup
    }
  });
}

async function confirmModalIfShown(page) {
  const modal = page.locator('#appConfirmModal.show, #appConfirmModal[aria-modal="true"]');
  try {
    await modal.first().waitFor({ state: 'visible', timeout: 450 });
    const okBtn = page.locator('#appConfirmModalOk, [data-testid="confirm-ok-btn"]').first();
    await okBtn.click();
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(250);
  } catch {
    // no confirm modal appeared
  }
}

async function createMaintTicketHttp(ctx, identifier, opts = {}) {
  const client = await ctx.http.login(identifier);
  const query = opts.roomId
    ? `?room_id=${encodeURIComponent(opts.roomId)}`
    : opts.assetId
      ? `?asset_id=${encodeURIComponent(opts.assetId)}`
      : '?room_id=1';
  const getRes = await client.get(`/maintenance.php${query}`);
  ctx.assert.equal(getRes.status, 200, `GET /maintenance.php${query} failed (${getRes.status})`);

  const form = extractFormWithCsrf(
    getRes.text,
    'maintenance',
    (f) => f.inputs.some((i) => /issue|description|fault|detail/i.test(i.name))
  );
  const issueField = pickField(
    form.inputs,
    ['issue_description', 'description', 'fault_description', 'issue', 'details'],
    'issue_description'
  );
  const locField = pickField(form.inputs, ['location_text', 'location'], 'location_text');
  const equipField = pickField(form.inputs, ['equipment_name', 'equipment', 'asset_name'], 'equipment_name');
  const fileInp = form.inputs.find((i) => i.type === 'file');
  const photoField = fileInp ? fileInp.name : 'before_photo';

  const marker = opts.issueDescription || `[T1-${Date.now()}-${Math.random().toString(36).slice(2, 6)}] ทดสอบแจ้งซ่อม`;
  const fields = {
    ...form.defaults,
    [issueField]: marker
  };
  if (opts.roomId && form.inputs.some((i) => i.name === 'room_id')) {
    fields.room_id = String(opts.roomId);
  }
  if (opts.assetId && form.inputs.some((i) => i.name === 'asset_id')) {
    fields.asset_id = String(opts.assetId);
  }
  if (form.inputs.some((i) => i.name === locField) && !fields[locField]) {
    fields[locField] = opts.locationText || 'อาคารอำนวยการ ชั้น 1 — ห้องประชุมราชพฤกษ์';
  }
  if (form.inputs.some((i) => i.name === equipField) && !fields[equipField]) {
    fields[equipField] = opts.equipmentName || 'อุปกรณ์ห้องประชุม';
  }
  if (form.inputs.some((i) => i.name === 'urgency') && !fields.urgency) {
    fields.urgency = opts.urgency || 'normal';
  }
  if (form.csrfToken) {
    fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
  }

  const files = opts.photoFixture ? { [photoField]: ctx.fixturePath(opts.photoFixture) } : {};
  const actionUrl = resolveActionUrl(form.action, `/maintenance.php${query}`, getRes.url);
  const res = await client.postMultipart(actionUrl, fields, files);
  ctx.assert.ok(res.status < 400, `POST /maintenance.php returned HTTP ${res.status}`);

  const rows = await ctx.db.queryJson(
    'SELECT * FROM maintenance_requests WHERE issue_description = ? ORDER BY id DESC LIMIT 1',
    [marker]
  );
  ctx.assert.ok(rows.length === 1, `Expected maintenance_requests row for marker ${marker}`);
  const ticket = rows[0];
  registerTicketCleanup(ctx, ticket.id);
  return { client, ticket, res, marker };
}

function findTicketFormInHtml(html, ticket) {
  return extractFormWithCsrf(
    html,
    'maintenance_manage',
    (f) => {
      const hasTicketId = f.inputs.some(
        (i) => /^(id|ticket_id|request_id)$/i.test(i.name) && String(i.value) === String(ticket.id)
      );
      if (hasTicketId) return true;
      return f.body.includes(String(ticket.ticket_code)) || f.body.includes(String(ticket.issue_description));
    }
  );
}

// ============================================================================
// F12 — QR Maintenance Helpdesk (Tier 1: T1-F12-01 .. T1-F12-06)
// ============================================================================

test({
  id: 'T1-F12-01',
  tier: 1,
  feature: 'F12',
  name: 'maintenance.php?room_id=1 auto-populates room name and location',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/maintenance.php?room_id=1`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F12-01 maintenance?room_id=1');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(
      bodyText.includes('ห้องประชุมราชพฤกษ์'),
      'Expected page to display room name ห้องประชุมราชพฤกษ์'
    );
    ctx.assert.ok(
      bodyText.includes('อาคารอำนวยการ') || bodyText.includes('RM-101') || bodyText.includes('ห้องประชุมราชพฤกษ์'),
      'Expected page to show room building/code location info'
    );

    const roomIdVal = await page.evaluate(() => {
      const el = document.querySelector('input[name="room_id"], select[name="room_id"]');
      return el ? String(el.value) : '';
    });
    ctx.assert.equal(roomIdVal, '1', 'Expected room_id field value to equal 1');

    const locVal = await page.evaluate(() => {
      const el = document.querySelector('input[name="location_text"], input[name="location"]');
      return el ? String(el.value) : '';
    });
    if (locVal) {
      ctx.assert.ok(
        locVal.includes('ห้องประชุมราชพฤกษ์') || locVal.includes('อาคารอำนวยการ') || locVal.includes('RM-101'),
        `Expected location input "${locVal}" to be pre-filled for room 1`
      );
    }
  }
});

test({
  id: 'T1-F12-02',
  tier: 1,
  feature: 'F12',
  name: 'maintenance.php?asset_id=1 auto-populates equipment and asset location',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/maintenance.php?asset_id=1`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F12-02 maintenance?asset_id=1');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(
      bodyText.includes('กุญแจห้องประชุมราชพฤกษ์') || bodyText.includes('AST-KEY-101'),
      'Expected page to display asset name กุญแจห้องประชุมราชพฤกษ์ or AST-KEY-101'
    );

    const assetIdVal = await page.evaluate(() => {
      const el = document.querySelector('input[name="asset_id"], select[name="asset_id"]');
      return el ? String(el.value) : '';
    });
    ctx.assert.equal(assetIdVal, '1', 'Expected asset_id field value to equal 1');

    const equipVal = await page.evaluate(() => {
      const el = document.querySelector('input[name="equipment_name"], input[name="equipment"], input[name="asset_name"]');
      return el ? String(el.value) : '';
    });
    if (equipVal) {
      ctx.assert.ok(
        equipVal.includes('กุญแจห้องประชุมราชพฤกษ์') || equipVal.includes('AST-KEY-101'),
        `Expected equipment input "${equipVal}" to be pre-filled for asset 1`
      );
    }

    const locVal = await page.evaluate(() => {
      const el = document.querySelector('input[name="location_text"], input[name="location"]');
      return el ? String(el.value) : '';
    });
    ctx.assert.ok(
      locVal.includes('เคาน์เตอร์บริหารทั่วไป') ||
        locVal.includes('ห้องประชุมราชพฤกษ์') ||
        bodyText.includes('เคาน์เตอร์บริหารทั่วไป'),
      'Expected asset location เคาน์เตอร์บริหารทั่วไป to be pre-filled or displayed'
    );
  }
});

test({
  id: 'T1-F12-03',
  tier: 1,
  feature: 'F12',
  name: 'Guest QR scan redirects to login.php with redirect param and returns to maintenance.php?room_id=1',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
    await page.goto(`${ctx.baseUrl}/maintenance.php?room_id=1`, { waitUntil: 'domcontentloaded' });

    const redirectedUrl = new URL(page.url());
    ctx.assert.ok(
      redirectedUrl.pathname.endsWith('/login.php'),
      `Expected unauthenticated QR scan to redirect to /login.php, got ${page.url()}`
    );
    const redirectParam = redirectedUrl.searchParams.get('redirect') || '';
    ctx.assert.ok(
      redirectParam.includes('/maintenance.php') && redirectParam.includes('room_id=1'),
      `Expected redirect query param to preserve /maintenance.php?room_id=1, got "${redirectParam}"`
    );

    const form = page.locator('form').filter({ has: page.locator('input[type="password"]') }).first();
    const idInput = form.locator('input[name="identifier"], input[name="username"], input[type="text"]').first();
    const pwInput = form.locator('input[name="password"], input[type="password"]').first();
    const submitBtn = form.locator('button[type="submit"], input[type="submit"]').first();

    await idInput.fill('user01');
    await pwInput.fill(ctx.env.seedPassword);
    await Promise.all([
      page.waitForURL((u) => !new URL(u).pathname.endsWith('/login.php'), { timeout: 10000 }),
      submitBtn.click()
    ]);
    await page.waitForLoadState('domcontentloaded');
    errors.assertNoErrors('T1-F12-03 post-login redirect');

    const finalUrl = new URL(page.url());
    ctx.assert.ok(
      finalUrl.pathname.endsWith('/maintenance.php') && finalUrl.searchParams.get('room_id') === '1',
      `Expected final URL to be /maintenance.php?room_id=1, got ${page.url()}`
    );
    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(
      bodyText.includes('ห้องประชุมราชพฤกษ์'),
      'Expected room name ห้องประชุมราชพฤกษ์ to be pre-filled after redirect login'
    );
  }
});

test({
  id: 'T1-F12-04',
  tier: 1,
  feature: 'F12',
  name: 'Mobile fault submission with before_photo creates waiting ticket and sets camera capture attrs',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F12-04-${ts}] แอร์น้ำหยด`;

    const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/maintenance.php?room_id=1`, { waitUntil: 'domcontentloaded' });

    const form = page
      .locator('form')
      .filter({ has: page.locator('textarea, input[name*="issue"], input[name*="description"]') })
      .first();
    await form.waitFor({ state: 'visible' });

    const fileInput = form.locator('input[type="file"]').first();
    ctx.assert.ok((await fileInput.count()) > 0, 'Expected file upload input on maintenance form');
    const acceptAttr = (await fileInput.getAttribute('accept')) || '';
    const captureAttr = (await fileInput.getAttribute('capture')) || '';
    ctx.assert.match(acceptAttr, /image\//i, `Expected accept="image/*" on file input, got "${acceptAttr}"`);
    ctx.assert.match(
      captureAttr,
      /environment/i,
      `Expected capture="environment" on maintenance camera input, got "${captureAttr}"`
    );

    const issueInput = form
      .locator('textarea[name="issue_description"], textarea[name*="issue"], textarea[name*="description"], textarea')
      .first();
    await issueInput.fill(marker);

    const equipInput = form.locator('input[name="equipment_name"], input[name="equipment"]').first();
    if ((await equipInput.count()) > 0 && !(await equipInput.inputValue())) {
      await equipInput.fill('เครื่องปรับอากาศ');
    }

    await fileInput.setInputFiles(ctx.fixturePath('sample.png'));

    const submitBtn = form.locator('button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await confirmModalIfShown(page);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(300);
    errors.assertNoErrors('T1-F12-04 submit maintenance');

    const rows = await ctx.db.queryJson(
      'SELECT * FROM maintenance_requests WHERE issue_description LIKE ? ORDER BY id DESC LIMIT 1',
      [`%T1-F12-04-${ts}%`]
    );
    ctx.assert.equal(rows.length, 1, 'Expected maintenance ticket inserted into DB');
    const ticket = rows[0];
    registerTicketCleanup(ctx, ticket.id);

    ctx.assert.equal(ticket.status, 'รอรับเรื่อง');
    ctx.assert.equal(Number(ticket.reporter_id), 6);
    ctx.assert.equal(Number(ticket.room_id), 1);
    ctx.assert.ok(
      typeof ticket.before_photo_path === 'string' && ticket.before_photo_path.startsWith('uploads/maintenance/'),
      `Expected before_photo_path under uploads/maintenance/, got ${ticket.before_photo_path}`
    );

    const diskPath = path.resolve(ctx.env.projectRoot, ticket.before_photo_path);
    ctx.assert.ok(fs.existsSync(diskPath), `Expected uploaded photo file on disk at ${diskPath}`);

    const dlClient = await ctx.http.login('user01');
    const dlRes = await dlClient.get(`/download.php?type=maintenance_before&id=${ticket.id}`);
    ctx.assert.equal(dlRes.status, 200, 'Expected download.php to return 200 for before_photo');
    ctx.assert.match(dlRes.headers.get('content-type') || '', /^image\/png/i);
  }
});

test({
  id: 'T1-F12-05',
  tier: 1,
  feature: 'F12',
  name: 'qr_codes.php renders QR codes for rooms and assets for staff and admin',
  fn: async (ctx) => {
    const adminClient = await ctx.http.login('admin');
    const adminRes = await adminClient.get('/qr_codes.php');
    ctx.assert.equal(adminRes.status, 200, 'Expected admin to access /qr_codes.php');
    ctx.assert.ok(adminRes.text.includes('RM-101') && adminRes.text.includes('AST-KEY-101'));

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/qr_codes.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('canvas, img[src^="data:image"], svg', { timeout: 8000 });
    await page.waitForTimeout(300);
    errors.assertNoErrors('T1-F12-05 qr_codes.php');

    const pageInfo = await page.evaluate(() => {
      const bodyText = document.body.innerText || '';
      const html = document.body.innerHTML || '';
      const canvases = Array.from(document.querySelectorAll('canvas')).filter(
        (c) => c.width > 0 && c.height > 0
      );
      const imgs = Array.from(document.querySelectorAll('img')).filter(
        (img) => img.src.startsWith('data:image') && img.naturalWidth > 0
      );
      const svgs = Array.from(document.querySelectorAll('svg')).filter(
        (s) => s.getBoundingClientRect().width > 20
      );
      return {
        bodyText,
        html,
        qrCount: canvases.length + imgs.length + svgs.length
      };
    });

    ctx.assert.ok(
      pageInfo.bodyText.includes('RM-101') || pageInfo.bodyText.includes('ห้องประชุมราชพฤกษ์'),
      'Expected RM-101 / ห้องประชุมราชพฤกษ์ on qr_codes.php'
    );
    ctx.assert.ok(
      pageInfo.bodyText.includes('AST-KEY-101') || pageInfo.bodyText.includes('AST-PRJ-01'),
      'Expected AST-KEY-101 / AST-PRJ-01 on qr_codes.php'
    );
    ctx.assert.ok(
      pageInfo.html.includes('maintenance.php?room_id=') && pageInfo.html.includes('maintenance.php?asset_id='),
      'Expected QR target URLs with maintenance.php?room_id= and maintenance.php?asset_id='
    );
    ctx.assert.ok(
      pageInfo.qrCount >= 2,
      `Expected >= 2 rendered QR code elements (canvas/img/svg), found ${pageInfo.qrCount}`
    );
  }
});

test({
  id: 'T1-F12-06',
  tier: 1,
  feature: 'F12',
  name: 'External user ext01 can submit maintenance request with sample.jpg and view own ticket',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F12-06-${ts}] โปรเจคเตอร์ไม่ติด`;
    const { client, ticket } = await createMaintTicketHttp(ctx, 'ext01', {
      roomId: 2,
      issueDescription: marker,
      equipmentName: 'โปรเจคเตอร์หอประชุม',
      photoFixture: 'sample.jpg'
    });

    ctx.assert.equal(Number(ticket.reporter_id), 8);
    ctx.assert.equal(Number(ticket.room_id), 2);
    ctx.assert.equal(ticket.status, 'รอรับเรื่อง');
    ctx.assert.ok(
      typeof ticket.before_photo_path === 'string' && ticket.before_photo_path.endsWith('.jpg'),
      `Expected .jpg before_photo_path, got ${ticket.before_photo_path}`
    );

    const listRes = await client.get('/maintenance.php');
    ctx.assert.equal(listRes.status, 200);
    ctx.assert.ok(listRes.text.includes(marker), 'Expected ext01 own ticket marker on maintenance.php');
    ctx.assert.ok(listRes.text.includes('รอรับเรื่อง'), 'Expected waiting status label on maintenance.php');
  }
});

// ============================================================================
// F13 — Technician Repair Management (Tier 1: T1-F13-01 .. T1-F13-06)
// ============================================================================

test({
  id: 'T1-F13-01',
  tier: 1,
  feature: 'F13',
  name: 'maintenance_manage.php accessible to staff and admin with seeded tickets visible',
  fn: async (ctx) => {
    const adminClient = await ctx.http.login('admin');
    const adminRes = await adminClient.get('/maintenance_manage.php');
    ctx.assert.equal(adminRes.status, 200, 'Expected admin GET /maintenance_manage.php to return 200');
    for (const code of ['MT-001', 'MT-002', 'MT-003', 'MT-004']) {
      ctx.assert.ok(adminRes.text.includes(code), `Expected ${code} visible to admin`);
    }

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/maintenance_manage.php`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F13-01 staff01 maintenance_manage.php');

    const text = await page.locator('body').innerText();
    for (const code of ['MT-001', 'MT-002', 'MT-003', 'MT-004']) {
      ctx.assert.ok(text.includes(code), `Expected ${code} visible to staff01 in UI`);
    }
  }
});

test({
  id: 'T1-F13-02',
  tier: 1,
  feature: 'F13',
  name: 'Filter tabs filter maintenance tickets accurately across all 4 Thai statuses',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/maintenance_manage.php`, { waitUntil: 'domcontentloaded' });

    const labels = ['ทั้งหมด', 'รอรับเรื่อง', 'กำลังซ่อม', 'รออะไหล่', 'เสร็จสิ้น'];
    for (const label of labels) {
      const tab = page.locator('a, button').filter({ hasText: label }).first();
      ctx.assert.ok((await tab.count()) > 0, `Expected filter control for "${label}"`);
    }

    const clickFilterAndCheck = async (label, mustHave, mustExclude) => {
      const tab = page
        .locator('a.nav-link, button.nav-link, a.btn, button.btn, [role="tab"]')
        .filter({ hasText: label })
        .first();
      const target = (await tab.count()) > 0 ? tab : page.locator('a, button').filter({ hasText: label }).first();
      await target.click();
      await page.waitForLoadState('domcontentloaded').catch(() => {});
      await page.waitForTimeout(200);
      const bodyText = await page.locator('body').innerText();
      for (const code of mustHave) {
        ctx.assert.ok(bodyText.includes(code), `Filter "${label}": expected ${code} to be visible`);
      }
      for (const code of mustExclude) {
        ctx.assert.ok(!bodyText.includes(code), `Filter "${label}": expected ${code} NOT to be visible`);
      }
    };

    await clickFilterAndCheck('ทั้งหมด', ['MT-001', 'MT-002', 'MT-003', 'MT-004'], []);
    await clickFilterAndCheck('รอรับเรื่อง', ['MT-001'], ['MT-004']);
    await clickFilterAndCheck('กำลังซ่อม', ['MT-002'], ['MT-001']);
    await clickFilterAndCheck('รออะไหล่', ['MT-003'], ['MT-001']);
    await clickFilterAndCheck('เสร็จสิ้น', ['MT-004'], ['MT-001']);

    errors.assertNoErrors('T1-F13-02 filter tabs');
  }
});

test({
  id: 'T1-F13-03',
  tier: 1,
  feature: 'F13',
  name: 'Technician transitions ticket across all 4 Thai repair statuses sequentially',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F13-03-${ts}] เปลี่ยนสถานะครบ 4 ขั้นตอน`;
    const { ticket } = await createMaintTicketHttp(ctx, 'user01', {
      roomId: 1,
      issueDescription: marker
    });

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');

    const updateStatusUi = async (nextStatus) => {
      await page.goto(`${ctx.baseUrl}/maintenance_manage.php`, { waitUntil: 'domcontentloaded' });
      const cardOrRow = page
        .locator('tr, .card, form, [data-ticket-id]')
        .filter({ hasText: marker })
        .filter({ has: page.locator('select, button[type="submit"], input[type="submit"]') })
        .last();
      await cardOrRow.waitFor({ state: 'visible', timeout: 8000 });

      let formLoc = cardOrRow.locator('form').first();
      if ((await formLoc.count()) === 0) {
        const tag = await cardOrRow.evaluate((el) => el.tagName.toLowerCase());
        formLoc = tag === 'form' ? cardOrRow : page.locator('form').filter({ hasText: marker }).first();
      }
      if ((await formLoc.locator('select').count()) === 0) {
        const openBtn = cardOrRow.locator('button, a').filter({ hasText: /อัปเดต|จัดการ|แก้ไข|ซ่อม/ }).first();
        if ((await openBtn.count()) > 0) {
          await openBtn.click();
          formLoc = page.locator('.modal.show form').first();
        }
      }

      const statusSelect = formLoc.locator('select[name*="status"], select').first();
      await statusSelect.selectOption(nextStatus);
      const submitBtn = formLoc.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await confirmModalIfShown(page);
      await page.waitForLoadState('domcontentloaded').catch(() => {});
      await page.waitForTimeout(250);

      const dbStatus = await ctx.db.scalar('SELECT status FROM maintenance_requests WHERE id = ?', [Number(ticket.id)]);
      ctx.assert.equal(dbStatus, nextStatus, `Expected DB status to become "${nextStatus}"`);
    };

    await updateStatusUi('กำลังซ่อม');
    await updateStatusUi('รออะไหล่');
    await updateStatusUi('เสร็จสิ้น');
    errors.assertNoErrors('T1-F13-03 4-status transitions');
  }
});

test({
  id: 'T1-F13-04',
  tier: 1,
  feature: 'F13',
  name: 'Technician uploads after_photo and photo is downloadable by staff and reporter',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F13-04-${ts}] อัปโหลดรูปหลังซ่อม`;
    const { ticket } = await createMaintTicketHttp(ctx, 'user01', {
      roomId: 1,
      issueDescription: marker
    });

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/maintenance_manage.php`, { waitUntil: 'domcontentloaded' });

    const container = page
      .locator('tr, .card, form, [data-ticket-id]')
      .filter({ hasText: marker })
      .filter({ has: page.locator('input[type="file"], select') })
      .last();
    await container.waitFor({ state: 'visible', timeout: 8000 });

    let formLoc = container.locator('form').first();
    if ((await formLoc.count()) === 0) {
      const tag = await container.evaluate((el) => el.tagName.toLowerCase());
      formLoc = tag === 'form' ? container : page.locator('form').filter({ hasText: marker }).first();
    }
    if ((await formLoc.locator('input[type="file"]').count()) === 0) {
      const openBtn = container.locator('button, a').filter({ hasText: /อัปเดต|จัดการ|แก้ไข/ }).first();
      if ((await openBtn.count()) > 0) {
        await openBtn.click();
        formLoc = page.locator('.modal.show form').first();
      }
    }

    const statusSelect = formLoc.locator('select[name*="status"], select').first();
    await statusSelect.selectOption('เสร็จสิ้น');

    const fileInput = formLoc.locator('input[type="file"]').first();
    ctx.assert.ok((await fileInput.count()) > 0, 'Expected after_photo file input in maintenance_manage form');
    await fileInput.setInputFiles(ctx.fixturePath('sample.jpg'));

    const submitBtn = formLoc.locator('button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await confirmModalIfShown(page);
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(300);
    errors.assertNoErrors('T1-F13-04 upload after_photo');

    const updated = (
      await ctx.db.queryJson('SELECT status, after_photo_path FROM maintenance_requests WHERE id = ?', [
        Number(ticket.id)
      ])
    )[0];
    ctx.assert.equal(updated.status, 'เสร็จสิ้น');
    ctx.assert.ok(
      typeof updated.after_photo_path === 'string' && updated.after_photo_path.startsWith('uploads/maintenance/'),
      `Expected after_photo_path under uploads/maintenance/, got ${updated.after_photo_path}`
    );
    registerMaintenanceFileCleanup(ctx, updated.after_photo_path);

    const diskPath = path.resolve(ctx.env.projectRoot, updated.after_photo_path);
    ctx.assert.ok(fs.existsSync(diskPath), `Expected after_photo file on disk at ${diskPath}`);

    for (const user of ['staff01', 'user01']) {
      const c = await ctx.http.login(user);
      const dl = await c.get(`/download.php?type=maintenance_after&id=${ticket.id}`);
      ctx.assert.equal(dl.status, 200, `Expected ${user} download of maintenance_after to return 200`);
      ctx.assert.match(dl.headers.get('content-type') || '', /^image\/jpeg/i);
    }
  }
});

test({
  id: 'T1-F13-05',
  tier: 1,
  feature: 'F13',
  name: 'Completing ticket saves completed_at, repair_notes, and assigned_staff_id in MySQL',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F13-05-${ts}] ตรวจบันทึกวันปิดงาน`;
    const note = `[T1-F13-05-${ts}] เปลี่ยนสายไฟเรียบร้อย`;
    const { ticket } = await createMaintTicketHttp(ctx, 'user01', {
      roomId: 1,
      issueDescription: marker
    });

    const staffClient = await ctx.http.login('staff01');
    const getRes = await staffClient.get('/maintenance_manage.php');
    ctx.assert.equal(getRes.status, 200);
    const form = findTicketFormInHtml(getRes.text, ticket);

    const idField = pickField(form.inputs, ['id', 'ticket_id', 'request_id'], 'id');
    const statusField = pickField(form.inputs, ['status', 'repair_status'], 'status');
    const noteField = pickField(form.inputs, ['repair_notes', 'notes', 'note', 'remarks'], 'repair_notes');

    const fields = {
      ...form.defaults,
      [idField]: String(ticket.id),
      [statusField]: 'เสร็จสิ้น',
      [noteField]: note
    };
    if (form.csrfToken) {
      fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
    }

    const actionUrl = resolveActionUrl(form.action, '/maintenance_manage.php', getRes.url);
    const postRes = await staffClient.postMultipart(actionUrl, fields, {});
    ctx.assert.ok(postRes.status < 400, `Expected < 400 on complete, got ${postRes.status}`);

    const row = (
      await ctx.db.queryJson(
        'SELECT status, completed_at, repair_notes, assigned_staff_id FROM maintenance_requests WHERE id = ?',
        [Number(ticket.id)]
      )
    )[0];
    ctx.assert.equal(row.status, 'เสร็จสิ้น');
    ctx.assert.ok(row.completed_at !== null && String(row.completed_at).length > 0, 'Expected completed_at populated');
    ctx.assert.equal(row.repair_notes, note);
    ctx.assert.equal(Number(row.assigned_staff_id), 2);

    const verifyRes = await staffClient.get('/maintenance_manage.php');
    ctx.assert.ok(verifyRes.text.includes(note), 'Expected repair_notes rendered on maintenance_manage.php');
    ctx.assert.ok(verifyRes.text.includes('เสร็จสิ้น'));
  }
});

test({
  id: 'T1-F13-06',
  tier: 1,
  feature: 'F13',
  name: 'admin can update repair status and repair_notes on maintenance_manage.php',
  fn: async (ctx) => {
    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const marker = `[T1-F13-06-${ts}] Admin update test`;
    const note = `[T1-F13-06-${ts}] Admin assigned`;
    const { ticket } = await createMaintTicketHttp(ctx, 'user01', {
      roomId: 1,
      issueDescription: marker
    });

    const adminClient = await ctx.http.login('admin');
    const getRes = await adminClient.get('/maintenance_manage.php');
    ctx.assert.equal(getRes.status, 200);
    const form = findTicketFormInHtml(getRes.text, ticket);

    const idField = pickField(form.inputs, ['id', 'ticket_id', 'request_id'], 'id');
    const statusField = pickField(form.inputs, ['status', 'repair_status'], 'status');
    const noteField = pickField(form.inputs, ['repair_notes', 'notes', 'note', 'remarks'], 'repair_notes');

    const fields = {
      ...form.defaults,
      [idField]: String(ticket.id),
      [statusField]: 'กำลังซ่อม',
      [noteField]: note
    };
    if (form.csrfToken) {
      fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
    }

    const actionUrl = resolveActionUrl(form.action, '/maintenance_manage.php', getRes.url);
    const postRes = await adminClient.postMultipart(actionUrl, fields, {});
    ctx.assert.ok(postRes.status < 400, `Expected < 400 from admin update, got ${postRes.status}`);

    const row = (
      await ctx.db.queryJson('SELECT status, repair_notes FROM maintenance_requests WHERE id = ?', [Number(ticket.id)])
    )[0];
    ctx.assert.equal(row.status, 'กำลังซ่อม');
    ctx.assert.equal(row.repair_notes, note);
  }
});

// ============================================================================
// F14 — Asset & Key Borrowing / Return (Tier 1: T1-F14-01 .. T1-F14-06)
// ============================================================================

async function submitBorrowHttp(ctx, identifier, assetId, quantity, purpose, daysAhead = 2) {
  const client = await ctx.http.login(identifier);
  const getRes = await client.get('/assets_borrow.php');
  ctx.assert.equal(getRes.status, 200);

  const form = extractFormWithCsrf(
    getRes.text,
    'assets_borrow',
    (f) =>
      f.inputs.some((i) => /quantity|qty/i.test(i.name)) &&
      (f.inputs.some((i) => i.name === 'asset_id' && (i.tag === 'select' || String(i.value) === String(assetId))) ||
        f.body.includes(`value="${assetId}"`) ||
        f.body.includes(`value='${assetId}'`))
  );

  const assetField = pickField(form.inputs, ['asset_id', 'item_id'], 'asset_id');
  const qtyField = pickField(form.inputs, ['quantity', 'qty', 'amount'], 'quantity');
  const retField = pickField(
    form.inputs,
    ['expected_return_at', 'expected_return_date', 'return_date', 'due_date'],
    'expected_return_at'
  );
  const purposeField = pickField(form.inputs, ['purpose', 'notes', 'note', 'reason'], 'purpose');

  const fields = {
    ...form.defaults,
    [assetField]: String(assetId),
    [qtyField]: String(quantity),
    [retField]: formatDatetimeForInput(form.inputs, retField, daysAhead),
    [purposeField]: purpose
  };
  if (form.inputs.some((i) => i.name === 'action') && !fields.action) {
    fields.action = 'borrow';
  }
  if (form.csrfToken) {
    fields[form.csrfFieldName || '_csrf_token'] = form.csrfToken;
  }

  const actionUrl = resolveActionUrl(form.action, '/assets_borrow.php', getRes.url);
  const postRes = await client.postForm(actionUrl, fields);
  ctx.assert.ok(postRes.status < 400, `Expected < 400 on borrow POST, got ${postRes.status}`);

  const rows = await ctx.db.queryJson(
    'SELECT * FROM asset_borrowings WHERE purpose = ? ORDER BY id DESC LIMIT 1',
    [purpose]
  );
  ctx.assert.equal(rows.length, 1, `Expected asset_borrowings row for purpose "${purpose}"`);
  return { client, borrowing: rows[0], res: postRes };
}

async function fillBorrowFormUi(page, assetId, quantity, purpose, daysAhead = 2) {
  const selectLoc = page.locator('select[name="asset_id"], select[name="item_id"]').first();
  let formLoc;
  if ((await selectLoc.count()) > 0) {
    await selectLoc.selectOption(String(assetId));
    formLoc = page.locator('form').filter({ has: selectLoc }).first();
  } else {
    formLoc = page
      .locator('form')
      .filter({ has: page.locator(`input[name="asset_id"][value="${assetId}"]`) })
      .first();
  }

  const qtyInput = formLoc.locator('input[name="quantity"], input[name="qty"]').first();
  await qtyInput.fill(String(quantity));

  const retInput = formLoc
    .locator(
      'input[name="expected_return_at"], input[name="expected_return_date"], input[name="return_date"], input[name="due_date"]'
    )
    .first();
  if ((await retInput.count()) > 0) {
    const type = ((await retInput.getAttribute('type')) || 'text').toLowerCase();
    const f = formatDateOffset(daysAhead, '17:00');
    await retInput.fill(type === 'date' ? f.date : type === 'datetime-local' ? f.datetimeLocal : f.mysql);
  }

  const purposeInput = formLoc
    .locator('textarea[name="purpose"], input[name="purpose"], textarea[name="notes"], input[name="notes"]')
    .first();
  if ((await purposeInput.count()) > 0) {
    await purposeInput.fill(purpose);
  }

  const submitBtn = formLoc.locator('button[type="submit"], input[type="submit"]').first();
  await submitBtn.click();
  await confirmModalIfShown(page);
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(300);
}

test({
  id: 'T1-F14-01',
  tier: 1,
  feature: 'F14',
  name: 'assets_borrow.php lists available equipment and keys with accurate available_stock',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F14-01 assets_borrow.php');

    const bodyText = await page.locator('body').innerText();
    const html = await page.content();
    for (const expected of [
      'กุญแจห้องประชุมราชพฤกษ์',
      'AST-KEY-101',
      'ไมโครโฟนไร้สายแบบพกพา',
      'AST-MIC-01',
      'เครื่องฉายโปรเจคเตอร์พกพา',
      'AST-PRJ-01',
      'ปลั๊กพ่วงสายไฟยาว 10 เมตร',
      'AST-EXT-01'
    ]) {
      ctx.assert.ok(
        bodyText.includes(expected) || html.includes(expected),
        `Expected "${expected}" on assets_borrow.php`
      );
    }
  }
});

test({
  id: 'T1-F14-02',
  tier: 1,
  feature: 'F14',
  name: 'Borrowing equipment item decrements available_stock in MySQL and creates borrowed row',
  fn: async (ctx) => {
    const initialStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 5'));
    ctx.assert.ok(initialStock >= 2, `Expected AST-EXT-01 available_stock >= 2, got ${initialStock}`);
    let createdBorrowId = null;

    ctx.onCleanup(async () => {
      if (createdBorrowId) {
        await ctx.db.execSql('DELETE FROM asset_borrowings WHERE id = ?', [Number(createdBorrowId)]).catch(() => {});
      }
      await ctx.db.execSql('UPDATE assets SET available_stock = ? WHERE id = 5', [initialStock]).catch(() => {});
    });

    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const purpose = `[T1-F14-02-${ts}] ยืมปลั๊กพ่วงจัดงาน`;

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
    await fillBorrowFormUi(page, 5, 2, purpose, 2);
    errors.assertNoErrors('T1-F14-02 borrow submit');

    const rows = await ctx.db.queryJson(
      'SELECT * FROM asset_borrowings WHERE purpose = ? ORDER BY id DESC LIMIT 1',
      [purpose]
    );
    ctx.assert.equal(rows.length, 1, 'Expected new asset_borrowings row');
    createdBorrowId = rows[0].id;

    ctx.assert.equal(Number(rows[0].asset_id), 5);
    ctx.assert.equal(Number(rows[0].borrower_id), 6);
    ctx.assert.equal(Number(rows[0].quantity), 2);
    ctx.assert.equal(rows[0].status, 'borrowed');

    const afterStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 5'));
    ctx.assert.equal(afterStock, initialStock - 2, 'Expected available_stock decremented by 2');
  }
});

test({
  id: 'T1-F14-03',
  tier: 1,
  feature: 'F14',
  name: 'Borrowing a room key AST-KEY-101 records expected_return_at and decrements key stock',
  fn: async (ctx) => {
    const initialStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.ok(initialStock >= 1, `Expected AST-KEY-101 available_stock >= 1, got ${initialStock}`);
    let createdBorrowId = null;

    ctx.onCleanup(async () => {
      if (createdBorrowId) {
        await ctx.db.execSql('DELETE FROM asset_borrowings WHERE id = ?', [Number(createdBorrowId)]).catch(() => {});
      }
      await ctx.db.execSql('UPDATE assets SET available_stock = ? WHERE id = 1', [initialStock]).catch(() => {});
    });

    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const purpose = `[T1-F14-03-${ts}] ยืมกุญแจห้องประชุม`;
    const tomorrow = formatDateOffset(1, '17:00');

    const { borrowing } = await submitBorrowHttp(ctx, 'user01', 1, 1, purpose, 1);
    createdBorrowId = borrowing.id;

    ctx.assert.equal(Number(borrowing.asset_id), 1);
    ctx.assert.equal(borrowing.status, 'borrowed');
    ctx.assert.ok(
      String(borrowing.expected_return_at).startsWith(tomorrow.date),
      `Expected expected_return_at on ${tomorrow.date}, got ${borrowing.expected_return_at}`
    );

    const afterStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(afterStock, initialStock - 1);
  }
});

test({
  id: 'T1-F14-04',
  tier: 1,
  feature: 'F14',
  name: 'Returning borrowed item restores available_stock and logs return_handler_id and returned_at',
  fn: async (ctx) => {
    const initialStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 4'));
    ctx.assert.ok(initialStock >= 1);
    let createdBorrowId = null;

    ctx.onCleanup(async () => {
      if (createdBorrowId) {
        await ctx.db.execSql('DELETE FROM asset_borrowings WHERE id = ?', [Number(createdBorrowId)]).catch(() => {});
      }
      await ctx.db.execSql('UPDATE assets SET available_stock = ? WHERE id = 4', [initialStock]).catch(() => {});
    });

    const ts = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    const purpose = `[T1-F14-04-${ts}] ยืมโปรเจคเตอร์แล้วคืน`;
    const { borrowing } = await submitBorrowHttp(ctx, 'user01', 4, 1, purpose, 1);
    createdBorrowId = borrowing.id;

    const midStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 4'));
    ctx.assert.equal(midStock, initialStock - 1);

    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });

    const targetMarker = borrowing.borrow_code || purpose;
    const rowOrCard = page
      .locator('tr, .card, form')
      .filter({ hasText: targetMarker })
      .filter({ has: page.locator('button[type="submit"], input[type="submit"], button:has-text("คืน")') })
      .last();
    await rowOrCard.waitFor({ state: 'visible', timeout: 8000 });

    const noteInput = rowOrCard.locator('input[name*="note"], textarea[name*="note"]').first();
    if ((await noteInput.count()) > 0) {
      await noteInput.fill('คืนสภาพสมบูรณ์');
    }

    const returnBtn = rowOrCard
      .locator('button[type="submit"], input[type="submit"], button:has-text("คืน")')
      .first();
    await returnBtn.click();
    await confirmModalIfShown(page);
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(300);
    errors.assertNoErrors('T1-F14-04 return asset');

    const updated = (
      await ctx.db.queryJson(
        'SELECT status, returned_at, return_handler_id FROM asset_borrowings WHERE id = ?',
        [Number(borrowing.id)]
      )
    )[0];
    ctx.assert.equal(updated.status, 'returned');
    ctx.assert.ok(updated.returned_at !== null && String(updated.returned_at).length > 0);
    ctx.assert.equal(Number(updated.return_handler_id), 2);

    const restoredStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 4'));
    ctx.assert.equal(restoredStock, initialStock, 'Expected stock restored to initial value');
  }
});

test({
  id: 'T1-F14-05',
  tier: 1,
  feature: 'F14',
  name: 'Borrow/return history displays borrower and return handler names',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
    await ctx.browser.loginUi(page, 'staff01');
    await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F14-05 staff01 history');

    const bodyText = await page.locator('body').innerText();
    ctx.assert.ok(bodyText.includes('BRW-002'), 'Expected BRW-002 in borrow/return history');
    ctx.assert.ok(bodyText.includes('นายธนพล'), 'Expected borrower name นายธนพล in history');
    ctx.assert.ok(bodyText.includes('นายสมชาย'), 'Expected return handler name นายสมชาย in history');
    ctx.assert.ok(
      /returned|คืนแล้ว|รับคืน/i.test(bodyText),
      'Expected returned status label in borrow/return history'
    );
  }
});

test({
  id: 'T1-F14-06',
  tier: 1,
  feature: 'F14',
  name: 'assets_borrow.php renders cleanly on mobile (375x812) without horizontal overflow',
  fn: async (ctx) => {
    const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
    await ctx.browser.loginUi(page, 'user01');
    await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
    errors.assertNoErrors('T1-F14-06 mobile assets_borrow.php');

    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      hasFormControl: Boolean(
        document.querySelector('form input[name="quantity"], form input[name="qty"], form select[name="asset_id"]')
      )
    }));

    ctx.assert.ok(
      layout.scrollWidth <= layout.innerWidth + 1,
      `Expected no mobile horizontal overflow (scrollWidth=${layout.scrollWidth}, innerWidth=${layout.innerWidth})`
    );
    ctx.assert.ok(layout.hasFormControl, 'Expected borrow form controls visible on mobile');
  }
});
