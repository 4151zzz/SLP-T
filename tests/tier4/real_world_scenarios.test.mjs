import fs from 'node:fs/promises';
import path from 'node:path';
import { test } from '../lib/harness.mjs';

const { DESKTOP, MOBILE } = (await import('../lib/browser.mjs'));

// ---------------------------------------------------------------------------
// Shared Date, CSRF, Upload Cleanup & CSV Utilities
// ---------------------------------------------------------------------------

function getBangkokNowParts() {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  });
  const parts = Object.fromEntries(
    fmt.formatToParts(new Date()).map((p) => [p.type, p.value])
  );
  const year = Number(parts.year);
  const month = parts.month;
  const day = parts.day;
  return {
    year,
    beYear: year + 543,
    month,
    day,
    yearMonth: `${parts.year}-${month}`,
    today: `${parts.year}-${month}-${day}`
  };
}

function currentMonthSlot(dayNum, startHour, startMin, endHour, endMin) {
  const { yearMonth } = getBangkokNowParts();
  const dd = String(dayNum).padStart(2, '0');
  const sh = String(startHour).padStart(2, '0');
  const sm = String(startMin).padStart(2, '0');
  const eh = String(endHour).padStart(2, '0');
  const em = String(endMin).padStart(2, '0');
  return {
    yearMonth,
    date: `${yearMonth}-${dd}`,
    startTime: `${sh}:${sm}`,
    endTime: `${eh}:${em}`,
    startLocal: `${yearMonth}-${dd}T${sh}:${sm}`,
    endLocal: `${yearMonth}-${dd}T${eh}:${em}`,
    startSql: `${yearMonth}-${dd} ${sh}:${sm}:00`,
    endSql: `${yearMonth}-${dd} ${eh}:${em}:00`
  };
}

function extractAppCsrf(html) {
  if (!html || typeof html !== 'string') return null;
  const inputRe = /<input\b([^>]*)>/gi;
  let match;
  while ((match = inputRe.exec(html)) !== null) {
    const attrs = match[1];
    const nameMatch = attrs.match(/\bname\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i);
    const name = (nameMatch?.[1] ?? nameMatch?.[2] ?? nameMatch?.[3] ?? '').toLowerCase();
    if (['_csrf_token', 'csrf_token', '_token', 'csrf', '__csrf_token'].includes(name)) {
      const valMatch = attrs.match(/\bvalue\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i);
      return valMatch?.[1] ?? valMatch?.[2] ?? valMatch?.[3] ?? '';
    }
  }
  const metaMatch = html.match(/<meta\b[^>]*name\s*=\s*["'](?:csrf-token|_csrf_token|csrf_token|_token)["'][^>]*content\s*=\s*["']([^"']*)["']/i);
  return metaMatch ? metaMatch[1] : null;
}

function registerUploadCleanup(ctx, relativePath) {
  if (!relativePath || typeof relativePath !== 'string') return;
  const uploadsRoot = path.resolve(ctx.env.projectRoot, 'uploads');
  const normalized = relativePath.replace(/^\/+/, '').replace(/\//g, path.sep);
  const absPath = normalized.startsWith('uploads' + path.sep)
    ? path.resolve(ctx.env.projectRoot, normalized)
    : path.resolve(uploadsRoot, normalized);

  if (!absPath.startsWith(uploadsRoot + path.sep)) {
    return;
  }
  ctx.onCleanup(async () => {
    await fs.unlink(absPath).catch(() => {});
  });
}

async function cleanupUploadPathsFromDb(ctx, sql, params = []) {
  const rows = await ctx.db.queryJson(sql, params).catch(() => []);
  for (const row of rows) {
    for (const val of Object.values(row)) {
      if (typeof val === 'string' && val.trim() !== '') {
        registerUploadCleanup(ctx, val.trim());
      }
    }
  }
}

function parseCsvRows(bufferOrText) {
  const buf = Buffer.isBuffer(bufferOrText)
    ? bufferOrText
    : Buffer.from(String(bufferOrText), 'utf8');
  const hasBom = buf.length >= 3 && buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf;
  const text = (hasBom ? buf.subarray(3) : buf).toString('utf8');

  const rows = [];
  let row = [];
  let cell = '';
  let inQuotes = false;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        cell += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(cell);
      cell = '';
    } else if (ch === '\r') {
      if (text[i + 1] === '\n') i++;
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else if (ch === '\n') {
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

  return {
    hasBom,
    rawText: text,
    headers: rows[0] || [],
    dataRows: rows.slice(1).filter((r) => r.some((c) => c !== ''))
  };
}

// ---------------------------------------------------------------------------
// Notification Delta Helpers
// ---------------------------------------------------------------------------

async function snapshotNotifications(ctx) {
  const fileCount = ctx.db.readNotificationLog().length;
  const maxDbId = Number(
    await ctx.db.scalar('SELECT COALESCE(MAX(id), 0) FROM notification_logs')
  );
  return { fileCount, maxDbId };
}

async function getNotificationDelta(ctx, snap) {
  const allFileEntries = ctx.db.readNotificationLog();
  const fileEntries = allFileEntries.slice(snap.fileCount);
  const dbRows = await ctx.db.queryJson(
    'SELECT * FROM notification_logs WHERE id > ? ORDER BY id ASC',
    [snap.maxDbId]
  );
  return { fileEntries, dbRows };
}

function assertNotificationRecorded(ctx, delta, { bookingId, eventName, targetRole }) {
  const matchDb = delta.dbRows.some(
    (r) =>
      (bookingId === undefined || Number(r.booking_id) === Number(bookingId)) &&
      (!eventName || String(r.event_name) === eventName) &&
      (!targetRole || String(r.target_role || '') === targetRole)
  );
  const matchFile = delta.fileEntries.some((entry) => {
    const raw = JSON.stringify(entry);
    const idOk = bookingId === undefined || Number(entry.booking_id) === Number(bookingId) || raw.includes(String(bookingId));
    const eventOk = !eventName || entry.event === eventName || entry.event_name === eventName || raw.includes(eventName);
    const roleOk = !targetRole || entry.target_role === targetRole || raw.includes(targetRole);
    return idOk && eventOk && roleOk;
  });
  ctx.assert.ok(
    matchDb || matchFile,
    `Expected notification for bookingId=${bookingId} event=${eventName} role=${targetRole}. DB delta=${JSON.stringify(delta.dbRows)}, file delta=${JSON.stringify(delta.fileEntries)}`
  );
}

// ---------------------------------------------------------------------------
// Playwright Browser Session & Resilient DOM / Form Helpers
// ---------------------------------------------------------------------------

async function withRolePage(ctx, viewport, identifier, fn, opts = {}) {
  let ownedBrowser = null;
  const useDirectLaunch = Boolean(opts.directLaunchChrome || !ctx.browser.getBrowser);
  const browser = useDirectLaunch
    ? (ownedBrowser = await ctx.browser.launchChrome())
    : await ctx.browser.getBrowser();

  if (ownedBrowser) {
    ctx.onCleanup(() => ownedBrowser.close().catch(() => {}));
  }

  const context = await ctx.browser.createContext(browser, viewport, opts.contextOpts || {});
  ctx.onCleanup(() => context.close().catch(() => {}));
  const page = await context.newPage();
  const errors = ctx.browser.attachErrorListeners(page, opts.errorOpts || {});
  ctx.onCleanup(() => errors.detach());

  try {
    if (identifier) {
      await ctx.browser.loginUi(
        page,
        ctx.baseUrl,
        identifier,
        opts.password ?? ctx.env.seedPassword,
        opts.loginOpts || {}
      );
    }
    const result = await fn({ page, context, browser, errors });
    if (!opts.allowHttpErrors) {
      errors.assertNoErrors(opts.label || `${identifier || 'guest'}@${viewport.width}x${viewport.height}`);
    }
    return result;
  } finally {
    errors.detach();
    await context.close().catch(() => {});
    if (ownedBrowser) {
      await ownedBrowser.close().catch(() => {});
    }
  }
}

async function assertNoHorizontalOverflow(ctx, page, label = 'page') {
  const metrics = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    innerWidth: window.innerWidth
  }));
  ctx.assert.ok(
    metrics.scrollWidth <= metrics.innerWidth + 1 && metrics.scrollWidth <= metrics.clientWidth + 1,
    `Horizontal overflow detected on ${label}: scrollWidth=${metrics.scrollWidth}, clientWidth=${metrics.clientWidth}, innerWidth=${metrics.innerWidth}`
  );
}

async function probeLocator(scope, selectors) {
  for (const sel of selectors) {
    const loc = scope.locator(sel).first();
    if ((await loc.count()) > 0) {
      return loc;
    }
  }
  return null;
}

async function fillFirst(scope, selectors, value, fieldLabel = 'field') {
  const loc = await probeLocator(scope, selectors);
  if (!loc) {
    throw new Error(`Could not find input for ${fieldLabel} (tried: ${selectors.join(', ')})`);
  }
  await loc.scrollIntoViewIfNeeded().catch(() => {});
  await loc.fill(String(value));
  return loc;
}

async function selectOptionResilient(scope, selectors, candidateValues = [], textNeedles = [], fieldLabel = 'select') {
  const sel = await probeLocator(scope, selectors);
  if (!sel) {
    throw new Error(`Could not find <select> for ${fieldLabel} (tried: ${selectors.join(', ')})`);
  }
  await sel.scrollIntoViewIfNeeded().catch(() => {});
  const options = await sel.locator('option').evaluateAll((els) =>
    els.map((el) => ({ value: el.value, text: (el.textContent || '').trim(), disabled: el.disabled }))
  );

  for (const val of candidateValues) {
    const found = options.find((o) => !o.disabled && o.value === String(val));
    if (found) {
      await sel.selectOption(found.value);
      return found;
    }
  }
  for (const needle of textNeedles) {
    const found = options.find((o) => !o.disabled && o.value !== '' && o.text.includes(needle));
    if (found) {
      await sel.selectOption(found.value);
      return found;
    }
  }
  throw new Error(`No matching option found for ${fieldLabel}. Available: ${JSON.stringify(options)}`);
}

async function fillDateTimeSlot(scope, prefix, slot) {
  const dtSel = [
    `input[name="${prefix}_datetime"]`,
    `input[name="${prefix}_at"]`,
    `input[name="${prefix}"]`,
    `input[data-testid="${prefix}-datetime"]`
  ];
  const dtInput = await probeLocator(scope, dtSel);
  if (dtInput) {
    const type = ((await dtInput.getAttribute('type')) || 'text').toLowerCase();
    const val = type === 'datetime-local'
      ? (prefix === 'start' ? slot.startLocal : slot.endLocal)
      : (prefix === 'start' ? slot.startSql : slot.endSql);
    await dtInput.fill(val);
    return;
  }

  const dateInput = await probeLocator(scope, [
    `input[name="${prefix}_date"]`,
    `input[data-testid="${prefix}-date"]`
  ]);
  const timeInput = await probeLocator(scope, [
    `input[name="${prefix}_time"]`,
    `input[data-testid="${prefix}-time"]`
  ]);
  if (dateInput && timeInput) {
    await dateInput.fill(slot.date);
    await timeInput.fill(prefix === 'start' ? slot.startTime : slot.endTime);
    return;
  }

  throw new Error(`Could not find ${prefix} datetime or split date/time fields`);
}

async function confirmModalIfOpen(page) {
  const okBtn = page.locator('#appConfirmModal.show [data-testid="confirm-ok-btn"], #appConfirmModal.show #appConfirmModalOk, .modal.show [data-testid="confirm-ok-btn"]').first();
  try {
    await okBtn.waitFor({ state: 'visible', timeout: 1200 });
    await okBtn.click();
    return true;
  } catch {
    return false;
  }
}

async function submitAndWait(page, scope, submitSelectors = ['button[type="submit"]', 'input[type="submit"]']) {
  const btn = await probeLocator(scope, submitSelectors);
  if (!btn) {
    throw new Error(`Could not find submit button (tried: ${submitSelectors.join(', ')})`);
  }
  await btn.scrollIntoViewIfNeeded().catch(() => {});
  const navPromise = page.waitForLoadState('domcontentloaded').catch(() => {});
  await btn.click();
  await confirmModalIfOpen(page);
  await navPromise;
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
}

// ---------------------------------------------------------------------------
// Mobile Drawer & Role Menu Verification Helpers
// ---------------------------------------------------------------------------

const ALL_NAV_TESTIDS = [
  'nav-calendar',
  'nav-book-room',
  'nav-book-vehicle',
  'nav-my-bookings',
  'nav-approvals',
  'nav-maintenance',
  'nav-maintenance-manage',
  'nav-assets-borrow',
  'nav-qr-codes',
  'nav-dashboard'
];

async function verifyRoleNavigation(ctx, page, expectedTestIds, isMobile) {
  const forbiddenTestIds = ALL_NAV_TESTIDS.filter((id) => !expectedTestIds.includes(id));

  if (isMobile) {
    await assertNoHorizontalOverflow(ctx, page, 'mobile before drawer open');
    const sidebar = page.locator('#appSidebar, [data-testid="sidebar-nav"]').first();
    const initiallyOpen = await sidebar.evaluate((el) => el.classList.contains('show')).catch(() => false);
    ctx.assert.equal(initiallyOpen, false, 'Mobile offcanvas #appSidebar should start collapsed');

    const toggle = await probeLocator(page, [
      '[data-testid="sidebar-toggle"]',
      '[data-bs-toggle="offcanvas"][data-bs-target="#appSidebar"]',
      '.navbar-toggler'
    ]);
    ctx.assert.ok(toggle, 'Mobile sidebar toggle button must exist');
    await toggle.click();

    await page.locator('#appSidebar.show, .offcanvas.show, .offcanvas-lg.show').first().waitFor({ state: 'visible', timeout: 5000 });
  } else {
    await page.locator('#appSidebar, [data-testid="sidebar-nav"]').first().waitFor({ state: 'visible', timeout: 5000 });
  }

  for (const tid of expectedTestIds) {
    const link = page.locator(`[data-testid="${tid}"]`).first();
    ctx.assert.equal(await link.count(), 1, `Expected nav link ${tid} to be present`);
    await link.waitFor({ state: 'visible', timeout: 5000 });
    if (isMobile) {
      const box = await link.boundingBox();
      ctx.assert.ok(box && box.height >= 44, `Mobile nav link ${tid} must satisfy >=44px touch target height (got ${box?.height})`);
    }
  }

  for (const tid of forbiddenTestIds) {
    const count = await page.locator(`[data-testid="${tid}"]`).count();
    ctx.assert.equal(count, 0, `Forbidden nav link ${tid} must be hidden for this role`);
  }

  if (isMobile) {
    await assertNoHorizontalOverflow(ctx, page, 'mobile with drawer open');
    const closeBtn = await probeLocator(page, [
      '#appSidebar [data-bs-dismiss="offcanvas"]',
      '.offcanvas.show .btn-close'
    ]);
    if (closeBtn) {
      await closeBtn.click();
      await page.waitForFunction(() => {
        const el = document.getElementById('appSidebar');
        return !el || !el.classList.contains('show');
      }, null, { timeout: 5000 }).catch(() => {});
    }
  }
}

// ---------------------------------------------------------------------------
// FullCalendar v6, Chart.js v4 & A4 @media print Helpers
// ---------------------------------------------------------------------------

async function verifyFullCalendar(ctx, page, {
  isMobile = false,
  expectColors = [],
  clickEventText = null,
  verifyModal = null
} = {}) {
  const calendarRespPromise = page.waitForResponse(
    (res) => res.url().includes('calendar_events.php') && res.status() === 200,
    { timeout: 8000 }
  ).catch(() => null);

  await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
  await calendarRespPromise;
  await page.locator('.fc').first().waitFor({ state: 'visible', timeout: 10000 });
  await page.waitForFunction(() => document.querySelectorAll('.fc-event').length > 0, null, { timeout: 8000 });

  if (isMobile) {
    await assertNoHorizontalOverflow(ctx, page, 'calendar.php mobile month view');
  }

  // Verify month / week / day view switches
  const weekBtn = page.locator('.fc-timeGridWeek-button').first();
  await weekBtn.scrollIntoViewIfNeeded();
  await weekBtn.click();
  await page.locator('.fc-timegrid').first().waitFor({ state: 'visible', timeout: 5000 });
  const weekCols = await page.locator('.fc-timegrid .fc-col-header-cell').count();
  ctx.assert.equal(weekCols, 7, `FullCalendar week view should show 7 day columns (got ${weekCols})`);
  if (isMobile) {
    await assertNoHorizontalOverflow(ctx, page, 'calendar.php mobile week view');
  }

  const dayBtn = page.locator('.fc-timeGridDay-button').first();
  await dayBtn.scrollIntoViewIfNeeded();
  await dayBtn.click();
  await page.locator('.fc-timegrid').first().waitFor({ state: 'visible', timeout: 5000 });
  const dayCols = await page.locator('.fc-timegrid .fc-col-header-cell').count();
  ctx.assert.equal(dayCols, 1, `FullCalendar day view should show 1 day column (got ${dayCols})`);

  const monthBtn = page.locator('.fc-dayGridMonth-button').first();
  await monthBtn.scrollIntoViewIfNeeded();
  await monthBtn.click();
  await page.locator('.fc-daygrid').first().waitFor({ state: 'visible', timeout: 5000 });

  if (expectColors.length > 0) {
    const renderedColors = await page.locator('.fc-event').evaluateAll((els) =>
      els.map((el) => {
        const cs = getComputedStyle(el);
        return {
          bg: cs.backgroundColor,
          border: cs.borderColor,
          text: (el.textContent || '').trim()
        };
      })
    );
    for (const expectedRgb of expectColors) {
      const matched = renderedColors.some(
        (c) => c.bg === expectedRgb || c.border === expectedRgb
      );
      ctx.assert.ok(
        matched,
        `Expected FullCalendar event with color ${expectedRgb}; rendered=${JSON.stringify(renderedColors)}`
      );
    }
  }

  if (clickEventText) {
    const targetEvent = page.locator('.fc-event', { hasText: clickEventText }).first();
    await targetEvent.waitFor({ state: 'visible', timeout: 5000 });
    await targetEvent.scrollIntoViewIfNeeded();
    await targetEvent.click();

    const modal = page.locator('.modal.show').first();
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    const modalText = (await modal.innerText()).trim();
    if (verifyModal) {
      await verifyModal(modalText, modal);
    }
    const closeModalBtn = modal.locator('.btn-close, [data-bs-dismiss="modal"]').first();
    if ((await closeModalBtn.count()) > 0) {
      await closeModalBtn.click();
      await modal.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
    }
  }
}

async function verifyDashboardCharts(ctx, page, viewportLabel) {
  const statsPromise = page.waitForResponse(
    (res) => res.url().includes('dashboard_stats.php') && res.status() === 200,
    { timeout: 8000 }
  ).catch(() => null);

  await page.goto(`${ctx.baseUrl}/dashboard.php`, { waitUntil: 'domcontentloaded' });
  await statsPromise;

  await page.waitForFunction(() => document.querySelectorAll('canvas').length >= 4, null, { timeout: 10000 });
  await page.waitForFunction(() => {
    const canvases = Array.from(document.querySelectorAll('canvas'));
    if (canvases.length < 4) return false;
    return canvases.every((canvas) => {
      const rect = canvas.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0 || canvas.width <= 0 || canvas.height <= 0) {
        return false;
      }
      if (typeof window.Chart !== 'undefined' && typeof window.Chart.getChart === 'function') {
        if (!window.Chart.getChart(canvas)) return false;
      }
      const ctx2d = canvas.getContext('2d');
      if (!ctx2d) return false;
      const data = ctx2d.getImageData(0, 0, canvas.width, canvas.height).data;
      for (let i = 3; i < data.length; i += 16) {
        if (data[i] > 0) return true;
      }
      return false;
    });
  }, null, { timeout: 10000 });

  const containerIssues = await page.evaluate(() => {
    const issues = [];
    const containers = document.querySelectorAll('.chart-container');
    for (const el of containers) {
      const rect = el.getBoundingClientRect();
      if (el.scrollWidth > el.clientWidth + 1 || rect.right > window.innerWidth + 1) {
        issues.push({
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
          right: rect.right,
          innerWidth: window.innerWidth
        });
      }
    }
    return {
      canvasCount: document.querySelectorAll('canvas').length,
      containerCount: containers.length,
      issues
    };
  });

  ctx.assert.ok(containerIssues.canvasCount >= 4, `${viewportLabel}: expected >=4 Chart.js canvases (got ${containerIssues.canvasCount})`);
  ctx.assert.equal(
    containerIssues.issues.length,
    0,
    `${viewportLabel}: .chart-container overflow detected: ${JSON.stringify(containerIssues.issues)}`
  );
  await assertNoHorizontalOverflow(ctx, page, `dashboard.php (${viewportLabel})`);
}

async function verifyPrintMemo(ctx, page, bookingId, { expectedTitle, expectedRef, expectedSignersInOrder }) {
  await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bookingId}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.emulateMedia({ media: 'print' });

  const printInspection = await page.evaluate(() => {
    const fonts = Array.from(document.fonts).map((f) => ({ family: f.family, status: f.status }));
    let hasPageA4Rule = false;
    for (const sheet of Array.from(document.styleSheets)) {
      let rules;
      try {
        rules = sheet.cssRules;
      } catch {
        continue;
      }
      if (!rules) continue;
      for (const r of Array.from(rules)) {
        if (r.type === CSSRule.PAGE_RULE || /@page/i.test(r.cssText || '')) {
          if (/A4|210mm|20mm/i.test(r.cssText || '')) {
            hasPageA4Rule = true;
          }
        }
      }
    }

    const navbar = document.querySelector('.app-navbar');
    const sidebar = document.querySelector('.app-sidebar');
    const memoSheet = document.querySelector('.memo-sheet');

    return {
      fonts,
      hasPageA4Rule,
      navbarDisplay: navbar ? getComputedStyle(navbar).display : 'absent',
      sidebarDisplay: sidebar ? getComputedStyle(sidebar).display : 'absent',
      memoDisplay: memoSheet ? getComputedStyle(memoSheet).display : 'absent',
      memoFontFamily: memoSheet ? getComputedStyle(memoSheet).fontFamily : '',
      bodyText: document.body.innerText
    };
  });

  ctx.assert.ok(
    printInspection.fonts.some((f) => f.family.replace(/['"]/g, '').includes('THSarabunNew') && f.status === 'loaded'),
    `Expected THSarabunNew loaded in document.fonts; got ${JSON.stringify(printInspection.fonts)}`
  );
  ctx.assert.ok(printInspection.hasPageA4Rule, 'Expected @page A4 CSS rule in print stylesheet');
  ctx.assert.ok(
    printInspection.navbarDisplay === 'none' || printInspection.navbarDisplay === 'absent',
    `Expected .app-navbar hidden in @media print (got ${printInspection.navbarDisplay})`
  );
  ctx.assert.ok(
    printInspection.sidebarDisplay === 'none' || printInspection.sidebarDisplay === 'absent',
    `Expected .app-sidebar hidden in @media print (got ${printInspection.sidebarDisplay})`
  );
  ctx.assert.notEqual(printInspection.memoDisplay, 'none', 'Expected .memo-sheet visible in @media print');
  ctx.assert.notEqual(printInspection.memoDisplay, 'absent', 'Expected .memo-sheet element present');
  ctx.assert.ok(
    printInspection.memoFontFamily.includes('THSarabunNew'),
    `Expected .memo-sheet font-family to include THSarabunNew (got ${printInspection.memoFontFamily})`
  );

  const { beYear } = getBangkokNowParts();
  ctx.assert.ok(printInspection.bodyText.includes('บันทึกข้อความ'), 'Memo must contain บันทึกข้อความ heading');
  ctx.assert.ok(printInspection.bodyText.includes(expectedRef), `Memo must contain reference ${expectedRef}`);
  ctx.assert.ok(printInspection.bodyText.includes(expectedTitle), `Memo must contain title ${expectedTitle}`);
  ctx.assert.ok(printInspection.bodyText.includes(String(beYear)), `Memo must contain Thai Buddhist Era year ${beYear}`);

  let prevIdx = -1;
  for (const signer of expectedSignersInOrder) {
    const idx = printInspection.bodyText.indexOf(signer);
    ctx.assert.ok(idx > prevIdx, `Expected signer "${signer}" in sequential approval order (prevIdx=${prevIdx}, idx=${idx})`);
    prevIdx = idx;
  }
}

// ---------------------------------------------------------------------------
// Resilient Workflow / Approvals / Maintenance / Assets Helpers
// ---------------------------------------------------------------------------

async function performApprovalStepUi(ctx, page, booking, {
  action, // 'approved' | 'rejected' | 'returned'
  comment = '',
  vehicleId = null,
  driverId = null
}) {
  await page.goto(`${ctx.baseUrl}/approvals.php`, { waitUntil: 'domcontentloaded' });

  const rowOrCard = page.locator('tr, .card, .list-group-item').filter({
    hasText: booking.reference_code
  }).first();
  const fallbackByTitle = page.locator('tr, .card, .list-group-item').filter({
    hasText: booking.title
  }).first();

  let scope = (await rowOrCard.count()) > 0 ? rowOrCard : fallbackByTitle;

  // Check if approvals.php renders inline form/action or links to booking_detail.php
  const hasInlineSubmit = (await scope.count()) > 0 &&
    ((await scope.locator('button[type="submit"], form').count()) > 0);

  if (!hasInlineSubmit) {
    const detailLink = (await scope.count()) > 0
      ? scope.locator(`a[href*="booking_detail.php"][href*="id=${booking.id}"], a[href*="booking_detail.php"]`).first()
      : null;
    if (detailLink && (await detailLink.count()) > 0) {
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        detailLink.click()
      ]);
    } else {
      await page.goto(`${ctx.baseUrl}/booking_detail.php?id=${booking.id}`, { waitUntil: 'domcontentloaded' });
    }
    scope = page.locator('main, [data-testid="main-content"]').first();
  }

  // Assign vehicle & driver if requested (Step 1)
  if (vehicleId !== null) {
    const vehSel = await probeLocator(scope, ['select[name="vehicle_id"]', 'select[name="vehicle"]']);
    if (vehSel) {
      await vehSel.selectOption(String(vehicleId));
    } else {
      await selectOptionResilient(page, ['select[name="vehicle_id"]', 'select[name="vehicle"]'], [String(vehicleId)], ['VEH-0'], 'vehicle_id');
    }
  }
  if (driverId !== null) {
    const drvSel = await probeLocator(scope, ['select[name="driver_id"]', 'select[name="driver"]']);
    if (drvSel) {
      await drvSel.selectOption(String(driverId));
    } else {
      await selectOptionResilient(page, ['select[name="driver_id"]', 'select[name="driver"]'], [String(driverId)], ['DRV-0'], 'driver_id');
    }
  }

  if (comment) {
    const commentField = await probeLocator(scope, [
      'textarea[name="comment"]',
      'input[name="comment"]',
      'textarea[name="remarks"]',
      'textarea[name="rejection_reason"]',
      'textarea'
    ]);
    if (commentField) {
      await commentField.fill(comment);
    } else {
      const globalComment = await probeLocator(page, ['textarea[name="comment"]', 'input[name="comment"]']);
      if (globalComment) await globalComment.fill(comment);
    }
  }

  // Try matching explicit action button first, else select[name="action"] + submit
  const buttonPatterns = {
    approved: [
      'button[name="action"][value="approved"]',
      'button[value="approved"]',
      'button[data-action="approved"]',
      'button:has-text("อนุมัติ")',
      'button:has-text("เห็นชอบ")',
      'button:has-text("ส่งต่อ")'
    ],
    rejected: [
      'button[name="action"][value="rejected"]',
      'button[value="rejected"]',
      'button[data-action="rejected"]',
      'button:has-text("ไม่อนุมัติ")',
      'button:has-text("ปฏิเสธ")'
    ],
    returned: [
      'button[name="action"][value="returned"]',
      'button[value="returned"]',
      'button[data-action="returned"]',
      'button:has-text("ส่งกลับ")',
      'button:has-text("ตีกลับ")'
    ],
    assigned_vehicle: [
      'button[name="action"][value="assigned_vehicle"]',
      'button[value="assigned_vehicle"]',
      'button[data-action="assigned_vehicle"]',
      'button:has-text("มอบหมาย")',
      'button:has-text("จัดรถ")'
    ]
  };

  const actionSelect = await probeLocator(scope, ['select[name="action"]']);
  if (actionSelect) {
    await actionSelect.selectOption(action).catch(() => {});
  }

  const actionBtn = await probeLocator(scope, buttonPatterns[action] || []);
  const submitTarget = actionBtn || await probeLocator(scope, ['button[type="submit"]', 'input[type="submit"]']);
  if (!submitTarget) {
    throw new Error(`Could not find action button "${action}" for booking ${booking.reference_code}`);
  }

  await submitTarget.scrollIntoViewIfNeeded().catch(() => {});
  const navPromise = page.waitForLoadState('domcontentloaded').catch(() => {});
  await submitTarget.click();
  await confirmModalIfOpen(page);
  await navPromise;
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
}

// ---------------------------------------------------------------------------
// Tier 4 Real-World Multi-Role Scenarios (T4-01 .. T4-09)
// ---------------------------------------------------------------------------

test({
  id: 'T4-01',
  tier: 4,
  feature: 'F3,F4,F5,F6,F8,F9,F10,F11,F16',
  name: 'Internal employee full room booking lifecycle, 3-step sequential approval, FullCalendar, and A4 THSarabunNew memo print (Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const slot = currentMonthSlot(24, 9, 0, 11, 30);
    const title = 'T4-01 Budget Review';

    // 1. Explicit launchChrome() -> createContext() -> attachErrorListeners() -> loginUi() -> try/finally + ctx.onCleanup
    const browser = await ctx.browser.launchChrome();
    ctx.onCleanup(() => browser.close().catch(() => {}));
    const userContext = await ctx.browser.createContext(browser, DESKTOP);
    ctx.onCleanup(() => userContext.close().catch(() => {}));
    const userPage = await userContext.newPage();
    const userErrors = ctx.browser.attachErrorListeners(userPage);
    ctx.onCleanup(() => userErrors.detach());

    let booking;
    let snapAfterCreate;
    try {
      await ctx.browser.loginUi(userPage, ctx.baseUrl, 'EMP001', ctx.env.seedPassword);
      await verifyRoleNavigation(ctx, userPage, [
        'nav-calendar',
        'nav-book-room',
        'nav-book-vehicle',
        'nav-my-bookings',
        'nav-maintenance',
        'nav-assets-borrow'
      ], false);

      const snapBeforeCreate = await snapshotNotifications(ctx);

      await userPage.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      const form = userPage.locator('form').filter({ has: userPage.locator('select[name="room_id"], select[name="room"]') }).first();
      await selectOptionResilient(form, ['select[name="room_id"]', 'select[name="room"]'], ['3'], ['RM-201', 'ห้องประชุมฝ่ายบริหาร'], 'room_id');
      await fillFirst(form, ['input[name="title"]', 'input[name="subject"]'], title, 'title');
      await fillFirst(form, ['textarea[name="purpose"]', 'textarea[name="description"]', 'input[name="purpose"]'], 'ทบทวนงบประมาณรายไตรมาส T4-01', 'purpose');
      await fillDateTimeSlot(form, 'start', slot);
      await fillDateTimeSlot(form, 'end', slot);
      const attendees = await probeLocator(form, ['input[name="attendee_count"]', 'input[name="attendees"]']);
      if (attendees) await attendees.fill('15');

      for (const eq of ['microphone', 'projector']) {
        const cb = form.locator(`input[type="checkbox"][value="${eq}"]`).first();
        if ((await cb.count()) > 0) await cb.check();
      }

      await submitAndWait(userPage, form);

      booking = (await ctx.db.queryJson(
        'SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1',
        [title]
      ))[0];
      ctx.assert.ok(booking, 'Room booking row must be created in MySQL');
      ctx.assert.equal(booking.status, 'pending_head');
      ctx.assert.equal(Number(booking.current_step), 1);
      ctx.assert.equal(Number(booking.room_id), 3);

      const createDelta = await getNotificationDelta(ctx, snapBeforeCreate);
      assertNotificationRecorded(ctx, createDelta, { bookingId: booking.id, eventName: 'pending_head', targetRole: 'head' });
      snapAfterCreate = await snapshotNotifications(ctx);
      userErrors.assertNoErrors('T4-01 user01 submit');
    } finally {
      userErrors.detach();
      await userContext.close().catch(() => {});
      await browser.close().catch(() => {});
    }

    // 2. Step 1: head01 -> pending_vice
    const snapBeforeHead = snapAfterCreate;
    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'H1-OK' });
    }, { label: 'T4-01 head01' });

    let updated = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(updated.status, 'pending_vice');
    ctx.assert.equal(Number(updated.current_step), 2);
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeHead), { bookingId: booking.id, eventName: 'pending_vice', targetRole: 'vice_director' });

    // 3. Step 2: vice01 -> pending_director
    const snapBeforeVice = await snapshotNotifications(ctx);
    await withRolePage(ctx, DESKTOP, 'vice01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'V2-OK' });
    }, { label: 'T4-01 vice01' });

    updated = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(updated.status, 'pending_director');
    ctx.assert.equal(Number(updated.current_step), 3);
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeVice), { bookingId: booking.id, eventName: 'pending_director', targetRole: 'director' });

    // 4. Step 3: director01 -> approved
    const snapBeforeDir = await snapshotNotifications(ctx);
    await withRolePage(ctx, DESKTOP, 'director01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'D3-APPROVED' });
    }, { label: 'T4-01 director01' });

    updated = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(updated.status, 'approved');
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeDir), { bookingId: booking.id, eventName: 'approved' });

    const logs = await ctx.db.queryJson(
      'SELECT * FROM approval_logs WHERE booking_id = ? ORDER BY step_number ASC, acted_at ASC, id ASC',
      [booking.id]
    );
    ctx.assert.equal(logs.length, 3, 'Expected 3 sequential approval_logs entries');
    ctx.assert.deepEqual(logs.map((l) => l.step_role), ['head', 'vice_director', 'director']);

    // 5. Requester verifies Stepper, FullCalendar (month/week/day + color + modal), and A4 Print Memo
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/booking_detail.php?id=${booking.id}`, { waitUntil: 'domcontentloaded' });
      const detailText = await page.locator('main, [data-testid="main-content"]').first().innerText();
      ctx.assert.ok(detailText.includes('H1-OK') && detailText.includes('V2-OK') && detailText.includes('D3-APPROVED'), 'Stepper detail must display all 3 reviewer comments');
      ctx.assert.ok((await page.locator('.stepper-step.is-completed').count()) >= 3, 'Expected completed stepper steps on approved booking');
      ctx.assert.ok((await page.locator(`a[href*="print_memo.php"][href*="id=${booking.id}"]`).count()) >= 1, 'Expected print_memo.php link on approved booking detail');

      await verifyFullCalendar(ctx, page, {
        isMobile: false,
        expectColors: ['rgb(37, 99, 235)', 'rgb(5, 150, 105)', 'rgb(124, 58, 237)'],
        clickEventText: title,
        verifyModal: async (modalText) => {
          ctx.assert.ok(modalText.includes(title), 'Owner event modal must display full booking title');
          ctx.assert.ok(modalText.includes('กมลชนก') || modalText.includes('RM-201'), 'Owner modal must display unmasked booking details');
        }
      });

      await verifyPrintMemo(ctx, page, booking.id, {
        expectedTitle: title,
        expectedRef: booking.reference_code,
        expectedSignersInOrder: [
          'นางสาวสมหญิง หัวหน้างาน',
          'นายวิชัย รองผู้อำนวยการ',
          'ดร.สุชาติ ผู้อำนวยการ'
        ]
      });
    }, { label: 'T4-01 user01 final verify' });
  }
});

test({
  id: 'T4-02',
  tier: 4,
  feature: 'F3,F4,F5,F6,F9,F10,F11',
  name: 'External org registration, open-room booking with PDF permission letter, PII-masked FullCalendar, and mobile Stepper (Mobile 375x812)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const uniq = Date.now();
    const extEmail = `t4_02_${uniq}@example.org`;
    const extFullName = `ผู้ประสานงานภายนอก ${uniq}`;
    const extOrg = `มูลนิธิทดสอบ ${uniq}`;
    const extPhone = '0891234567';
    const slot = currentMonthSlot(25, 13, 0, 16, 0);
    const title = `T4-02 External Seminar ${uniq}`;

    let booking;
    // 1. Register + Mobile drawer + Open-room PDF booking
    await withRolePage(ctx, MOBILE, null, async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/register.php`, { waitUntil: 'domcontentloaded' });
      await assertNoHorizontalOverflow(ctx, page, 'register.php mobile');

      await fillFirst(page, ['[data-testid="register-full-name"]', 'input[name="full_name"]'], extFullName, 'full_name');
      await fillFirst(page, ['[data-testid="register-organization"]', 'input[name="organization"]'], extOrg, 'organization');
      await fillFirst(page, ['[data-testid="register-phone"]', 'input[name="phone"]'], extPhone, 'phone');
      await fillFirst(page, ['[data-testid="register-email"]', 'input[name="email"]'], extEmail, 'email');
      await fillFirst(page, ['[data-testid="register-password"]', 'input[name="password"]'], ctx.env.seedPassword, 'password');
      await fillFirst(page, ['[data-testid="register-password-confirm"]', 'input[name="password_confirm"]'], ctx.env.seedPassword, 'password_confirm');
      await submitAndWait(page, page, ['[data-testid="register-submit"]', 'button[type="submit"]']);

      const userRow = (await ctx.db.queryJson('SELECT * FROM users WHERE email = ?', [extEmail]))[0];
      ctx.assert.ok(userRow, 'Registered external user must exist in MySQL');
      ctx.assert.equal(userRow.user_type, 'external');
      ctx.assert.equal(userRow.role, 'external_user');
      ctx.assert.equal(userRow.staff_id, null);

      await ctx.browser.loginUi(page, ctx.baseUrl, extEmail, ctx.env.seedPassword);
      await verifyRoleNavigation(ctx, page, [
        'nav-calendar',
        'nav-book-room',
        'nav-my-bookings',
        'nav-maintenance'
      ], true);

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await assertNoHorizontalOverflow(ctx, page, 'book_room.php external mobile');

      const roomSelect = await probeLocator(page, ['select[name="room_id"]', 'select[name="room"]']);
      ctx.assert.ok(roomSelect, 'Room select must exist on book_room.php');
      const roomOptions = await roomSelect.locator('option').evaluateAll((els) =>
        els.map((e) => ({ value: e.value, text: (e.textContent || '').trim() }))
      );
      const optJoined = roomOptions.map((o) => `${o.value}:${o.text}`).join(' | ');
      ctx.assert.ok(optJoined.includes('RM-101') || optJoined.includes('ราชพฤกษ์'), 'External user must see allow_external=1 room RM-101');
      ctx.assert.ok(!optJoined.includes('RM-201') && !optJoined.includes('RM-302'), `Internal-only rooms must be excluded for external_user: ${optJoined}`);

      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['1'], ['RM-101'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], title, 'title');
      await fillFirst(page, ['textarea[name="purpose"]', 'textarea[name="description"]'], 'สัมมนาความร่วมมือ T4-02', 'purpose');
      await fillDateTimeSlot(page, 'start', slot);
      await fillDateTimeSlot(page, 'end', slot);
      const attendees = await probeLocator(page, ['input[name="attendee_count"]', 'input[name="attendees"]']);
      if (attendees) await attendees.fill('30');

      const fileInput = await probeLocator(page, ['input[type="file"][name="permission_letter"]', 'input[type="file"]']);
      ctx.assert.ok(fileInput, 'Permission letter file input must exist on book_room.php');
      await fileInput.setInputFiles(ctx.fixturePath('permission_letter.pdf'));

      const snapBefore = await snapshotNotifications(ctx);
      await submitAndWait(page, page);

      booking = (await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1', [title]))[0];
      ctx.assert.ok(booking, 'External booking row must be saved');
      ctx.assert.equal(booking.requester_type, 'external');
      ctx.assert.equal(booking.status, 'pending_head');
      ctx.assert.ok(booking.permission_letter_path, 'permission_letter_path must be stored');
      ctx.assert.equal(booking.permission_letter_original_name, 'permission_letter.pdf');
      registerUploadCleanup(ctx, booking.permission_letter_path);

      assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBefore), { bookingId: booking.id, eventName: 'pending_head', targetRole: 'head' });

      // Check my_bookings.php mobile layout
      await page.goto(`${ctx.baseUrl}/my_bookings.php`, { waitUntil: 'domcontentloaded' });
      await assertNoHorizontalOverflow(ctx, page, 'my_bookings.php external mobile');
      const myBookingsText = await page.locator('main').innerText();
      ctx.assert.ok(myBookingsText.includes(title), 'my_bookings.php must show newly submitted external booking');
    }, { label: 'T4-02 external register & book' });

    // 2. Download permission letter IDOR checks via HTTP
    const ownerHttp = await ctx.http.login(extEmail, ctx.env.seedPassword);
    const dlOwner = await ownerHttp.get(`/download.php?type=permission&id=${booking.id}`);
    ctx.assert.equal(dlOwner.status, 200, 'Owner should download own permission PDF with 200');
    ctx.assert.ok((dlOwner.headers.get('content-type') || '').includes('application/pdf'), 'Permission download must serve application/pdf');

    const user2Http = await ctx.http.login('user02', ctx.env.seedPassword);
    const dlOther = await user2Http.get(`/download.php?type=permission&id=${booking.id}`);
    ctx.assert.equal(dlOther.status, 403, 'Unrelated user02 must get 403 on permission letter download');

    // 3. PII Masking check on FullCalendar JSON & Mobile UI for unrelated user02 and ext01
    for (const viewer of ['user02', 'ext01']) {
      const viewerHttp = await ctx.http.login(viewer, ctx.env.seedPassword);
      const apiRes = await viewerHttp.get(`/api/calendar_events.php?start=${slot.yearMonth}-01&end=${slot.yearMonth}-28`);
      ctx.assert.equal(apiRes.status, 200);
      const events = apiRes.json();
      const targetEv = events.find((ev) =>
        Number(ev.extendedProps?.booking_id ?? ev.extendedProps?.id ?? -1) === Number(booking.id) ||
        JSON.stringify(ev).includes(booking.reference_code) ||
        (ev.title || '').includes('RM-101')
      );
      ctx.assert.ok(targetEv, `Calendar API for ${viewer} should include non-rejected booking event`);
      ctx.assert.equal(targetEv.extendedProps?.is_masked, true, `Calendar API must set extendedProps.is_masked=true for non-owner ${viewer}`);
      const serialized = JSON.stringify(targetEv);
      ctx.assert.ok(!serialized.includes(extFullName) && !serialized.includes(extOrg) && !serialized.includes(extPhone), `Calendar API leaked external PII to ${viewer}: ${serialized}`);

      await withRolePage(ctx, MOBILE, viewer, async ({ page }) => {
        await verifyFullCalendar(ctx, page, {
          isMobile: true,
          clickEventText: targetEv.title || 'RM-101',
          verifyModal: async (modalText) => {
            ctx.assert.ok(!modalText.includes(extFullName) && !modalText.includes(extPhone) && !modalText.includes(extEmail), `Calendar modal leaked PII to ${viewer}`);
          }
        });
      }, { label: `T4-02 PII calendar ${viewer}` });
    }

    // 4. head01 approves Step 1 -> external user verifies Mobile Stepper at 375x812
    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'EXT-STEP1-OK' });
    }, { label: 'T4-02 head01 approve' });

    await withRolePage(ctx, MOBILE, extEmail, async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/booking_detail.php?id=${booking.id}`, { waitUntil: 'domcontentloaded' });
      await assertNoHorizontalOverflow(ctx, page, 'booking_detail.php external mobile');
      const text = await page.locator('main, [data-testid="main-content"]').first().innerText();
      ctx.assert.ok(text.includes('EXT-STEP1-OK'), 'External requester must see head comment in Stepper');
      ctx.assert.ok((await page.locator('.stepper-step.is-completed').count()) >= 1, 'Step 1 must show completed');
      ctx.assert.ok((await page.locator('.stepper-step.is-current').count()) >= 1, 'Step 2 (pending_vice) must show current');
    }, { label: 'T4-02 external mobile stepper' });
  }
});

test({
  id: 'T4-03',
  tier: 4,
  feature: 'F3,F7,F8,F9,F10,F11',
  name: 'Internal vehicle request, Step 1 staff/head vehicle & driver assignment, vice return, re-forward, and director final approval (Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const slot = currentMonthSlot(26, 8, 0, 17, 0);
    const title = 'T4-03 Field Survey';
    const destination = 'ศาลากลางจังหวัด';

    let booking;
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_vehicle.php`, { waitUntil: 'domcontentloaded' });
      const form = page.locator('form').filter({ has: page.locator('input[name="destination"], input[name="passenger_count"]') }).first();
      await fillFirst(form, ['input[name="title"]', 'input[name="subject"]'], title, 'title');
      await fillFirst(form, ['input[name="destination"]', 'textarea[name="destination"]'], destination, 'destination');
      await fillFirst(form, ['input[name="passenger_count"]', 'input[name="passengers"]'], '8', 'passenger_count');
      await fillFirst(form, ['textarea[name="purpose"]', 'textarea[name="description"]'], 'สำรวจพื้นที่โครงการ T4-03', 'purpose');
      await fillDateTimeSlot(form, 'start', slot);
      await fillDateTimeSlot(form, 'end', slot);
      await submitAndWait(page, form);

      booking = (await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1', [title]))[0];
      ctx.assert.ok(booking, 'Vehicle booking must be created in MySQL');
      ctx.assert.equal(booking.booking_type, 'vehicle');
      ctx.assert.equal(booking.status, 'pending_head');
      ctx.assert.equal(booking.vehicle_id, null);
      ctx.assert.equal(booking.driver_id, null);
    }, { label: 'T4-03 user01 book vehicle' });

    // Step 1a: staff01 assigns vehicle VEH-01 (id=1, 12 seats) & driver DRV-01 (id=1)
    await withRolePage(ctx, DESKTOP, 'staff01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, {
        action: 'assigned_vehicle',
        comment: 'จัดรถตู้ VEH-01 และคนขับ DRV-01',
        vehicleId: 1,
        driverId: 1
      });
    }, { label: 'T4-03 staff01 assign vehicle' });

    let state = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(Number(state.vehicle_id), 1, 'staff01 must assign vehicle_id=1');
    ctx.assert.equal(Number(state.driver_id), 1, 'staff01 must assign driver_id=1');

    // Step 1b: head01 approves -> pending_vice
    const snapBeforeHead1 = await snapshotNotifications(ctx);
    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, {
        action: 'approved',
        comment: 'H1-VEHICLE-FORWARD',
        vehicleId: state.vehicle_id ? null : 1,
        driverId: state.driver_id ? null : 1
      });
    }, { label: 'T4-03 head01 forward' });

    state = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(state.status, 'pending_vice');
    ctx.assert.equal(Number(state.current_step), 2);
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeHead1), { bookingId: booking.id, eventName: 'pending_vice', targetRole: 'vice_director' });

    // Step 2: vice01 returns to head (pending_head)
    const snapBeforeReturn = await snapshotNotifications(ctx);
    const returnComment = 'กรุณาระบุเลขหนังสือเชิญประชุมเพิ่มเติม';
    await withRolePage(ctx, DESKTOP, 'vice01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, {
        action: 'returned',
        comment: returnComment
      });
    }, { label: 'T4-03 vice01 return' });

    state = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(state.status, 'pending_head', 'Returned booking must revert to pending_head');
    ctx.assert.equal(Number(state.current_step), 1, 'Returned booking must revert current_step to 1');
    const returnLogs = await ctx.db.queryJson(
      "SELECT * FROM approval_logs WHERE booking_id = ? AND action = 'returned'",
      [booking.id]
    );
    ctx.assert.equal(returnLogs.length, 1, 'approval_logs must record returned action');
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeReturn), { bookingId: booking.id, eventName: 'returned_head', targetRole: 'head' });

    // Requester checks Stepper shows returned comment
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/booking_detail.php?id=${booking.id}`, { waitUntil: 'domcontentloaded' });
      const text = await page.locator('main').innerText();
      ctx.assert.ok(text.includes(returnComment), 'Requester timeline must show Vice Director return comment');
      ctx.assert.ok(text.includes('VEH-01') || text.includes('ฮข-1024'), 'Requester detail must show assigned vehicle');
    }, { label: 'T4-03 user01 view return' });

    // Re-forward: head01 -> vice01 -> director01
    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'แนบเลขหนังสือแล้ว ส่งต่อ' });
    }, { label: 'T4-03 head01 re-forward' });

    await withRolePage(ctx, DESKTOP, 'vice01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'V2-REAPPROVED' });
    }, { label: 'T4-03 vice01 re-approve' });

    const snapBeforeFinal = await snapshotNotifications(ctx);
    await withRolePage(ctx, DESKTOP, 'director01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, booking, { action: 'approved', comment: 'D3-VEHICLE-APPROVED' });
    }, { label: 'T4-03 director01 final approve' });

    state = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [booking.id]))[0];
    ctx.assert.equal(state.status, 'approved');
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeFinal), { bookingId: booking.id, eventName: 'approved' });

    const trail = await ctx.db.queryJson(
      'SELECT action FROM approval_logs WHERE booking_id = ? ORDER BY id ASC',
      [booking.id]
    );
    ctx.assert.ok(trail.length >= 5, `Expected full audit trail including return & re-forward (got ${trail.length})`);
    ctx.assert.ok(trail.some((t) => t.action === 'returned'), 'Audit trail must include returned');
    ctx.assert.equal(trail[trail.length - 1].action, 'approved', 'Last audit trail action must be approved');

    // Verify vehicle booking appears on FullCalendar in emerald green #059669 -> rgb(5, 150, 105)
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await verifyFullCalendar(ctx, page, {
        isMobile: false,
        expectColors: ['rgb(5, 150, 105)'],
        clickEventText: title,
        verifyModal: async (modalText) => {
          ctx.assert.ok(modalText.includes(destination) || modalText.includes(title));
        }
      });
    }, { label: 'T4-03 calendar vehicle color' });
  }
});

test({
  id: 'T4-04',
  tier: 4,
  feature: 'F6,F7,F8',
  name: 'Overlapping resource conflict rejection, back-to-back boundary acceptance, concurrent Promise.all race, rejected non-blocking, and vehicle/driver conflict (Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const baseSlot = currentMonthSlot(27, 10, 0, 12, 0);
    const overlapSlot = currentMonthSlot(27, 11, 0, 13, 0);
    const adjacentSlot = currentMonthSlot(27, 12, 0, 14, 0);
    const raceSlot = currentMonthSlot(28, 9, 0, 11, 0);
    const rejectedSlot = currentMonthSlot(22, 10, 0, 12, 0); // matches seeded BK-006 (room 1, rejected)

    // 1. Baseline booking on Room 1 day 27 10:00-12:00
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['1'], ['RM-101'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], 'T4-04 Base Slot', 'title');
      await fillDateTimeSlot(page, 'start', baseSlot);
      await fillDateTimeSlot(page, 'end', baseSlot);
      await submitAndWait(page, page);
    }, { label: 'T4-04 baseline' });

    let day27Count = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM bookings WHERE room_id = 1 AND DATE(start_datetime) = ? AND status != 'rejected'",
      [baseSlot.date]
    ));
    ctx.assert.equal(day27Count, 1);

    // 2. Overlapping rejection + Back-to-back boundary acceptance (user02 UI)
    await withRolePage(ctx, DESKTOP, 'user02', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['1'], ['RM-101'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], 'T4-04 Overlap Attempt', 'title');
      await fillDateTimeSlot(page, 'start', overlapSlot);
      await fillDateTimeSlot(page, 'end', overlapSlot);
      await submitAndWait(page, page);

      const alertText = await page.locator('.alert-danger, [role="alert"]').first().innerText().catch(() => '');
      ctx.assert.ok(alertText.length > 0, 'Overlapping room booking must display conflict warning alert');

      day27Count = Number(await ctx.db.scalar(
        "SELECT COUNT(*) FROM bookings WHERE room_id = 1 AND DATE(start_datetime) = ? AND status != 'rejected'",
        [baseSlot.date]
      ));
      ctx.assert.equal(day27Count, 1, 'Overlapping attempt must NOT insert a booking row');

      // Back-to-back exact boundary 12:00-14:00 (existing_end === new_start) -> accepted
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['1'], ['RM-101'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], 'T4-04 Back-to-Back', 'title');
      await fillDateTimeSlot(page, 'start', adjacentSlot);
      await fillDateTimeSlot(page, 'end', adjacentSlot);
      await submitAndWait(page, page);

      day27Count = Number(await ctx.db.scalar(
        "SELECT COUNT(*) FROM bookings WHERE room_id = 1 AND DATE(start_datetime) = ? AND status != 'rejected'",
        [baseSlot.date]
      ));
      ctx.assert.equal(day27Count, 2, 'Back-to-back slot (12:00-14:00) must be accepted');
    }, { label: 'T4-04 overlap & back-to-back' });

    // 3. Concurrent Promise.all HTTP race on Room 4 day 28 09:00-11:00 (using _csrf_token!)
    const [c1, c2] = await Promise.all([
      ctx.http.login('user01', ctx.env.seedPassword),
      ctx.http.login('user02', ctx.env.seedPassword)
    ]);
    const [g1, g2] = await Promise.all([c1.get('/book_room.php'), c2.get('/book_room.php')]);
    const tok1 = extractAppCsrf(g1.text);
    const tok2 = extractAppCsrf(g2.text);
    ctx.assert.ok(tok1 && tok2, 'Must extract _csrf_token from book_room.php');

    await Promise.all([
      c1.postForm('/book_room.php', {
        _csrf_token: tok1,
        room_id: '4',
        title: 'T4-04 Race A',
        purpose: 'Concurrent test A',
        start_datetime: raceSlot.startLocal,
        end_datetime: raceSlot.endLocal,
        attendee_count: '10'
      }),
      c2.postForm('/book_room.php', {
        _csrf_token: tok2,
        room_id: '4',
        title: 'T4-04 Race B',
        purpose: 'Concurrent test B',
        start_datetime: raceSlot.startLocal,
        end_datetime: raceSlot.endLocal,
        attendee_count: '10'
      })
    ]);

    const raceCount = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM bookings WHERE room_id = 4 AND start_datetime = ? AND status != 'rejected'",
      [raceSlot.startSql]
    ));
    ctx.assert.equal(raceCount, 1, `FOR UPDATE locking must allow exactly 1 concurrent winner (got ${raceCount})`);

    // 4. Rejected booking (seeded BK-006 on Room 1 day 22 10:00-12:00) must NOT block rebooking
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['1'], ['RM-101'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], 'T4-04 Non-Blocked Slot', 'title');
      await fillDateTimeSlot(page, 'start', rejectedSlot);
      await fillDateTimeSlot(page, 'end', rejectedSlot);
      await submitAndWait(page, page);

      const newRow = (await ctx.db.queryJson(
        "SELECT * FROM bookings WHERE title = 'T4-04 Non-Blocked Slot' AND status = 'pending_head'",
        []
      ))[0];
      ctx.assert.ok(newRow, 'Booking overlapping only a rejected booking must succeed');
    }, { label: 'T4-04 rejected non-blocking' });

    // 5. Vehicle & Driver conflict detection against seeded BK-005 (day 20 09:00-15:30, VEH-02 id=2, DRV-02 id=2)
    const vehOverlap = currentMonthSlot(20, 10, 0, 14, 0);
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_vehicle.php`, { waitUntil: 'domcontentloaded' });
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], 'T4-04 Vehicle Conflict', 'title');
      await fillFirst(page, ['input[name="destination"]', 'textarea[name="destination"]'], 'ศูนย์ราชการ', 'destination');
      await fillFirst(page, ['input[name="passenger_count"]', 'input[name="passengers"]'], '3', 'passenger_count');
      await fillDateTimeSlot(page, 'start', vehOverlap);
      await fillDateTimeSlot(page, 'end', vehOverlap);
      await submitAndWait(page, page);
    }, { label: 'T4-04 create vehicle request' });

    const vehBooking = (await ctx.db.queryJson(
      "SELECT * FROM bookings WHERE title = 'T4-04 Vehicle Conflict' ORDER BY id DESC LIMIT 1"
    ))[0];
    ctx.assert.ok(vehBooking);

    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, vehBooking, {
        action: 'approved',
        comment: 'Attempt conflicting VEH-02 / DRV-02',
        vehicleId: 2,
        driverId: 2
      });
      const alertText = await page.locator('.alert-danger, [role="alert"]').first().innerText().catch(() => '');
      ctx.assert.ok(alertText.length > 0, 'Assigning already-booked VEH-02/DRV-02 must display conflict warning');
    }, { label: 'T4-04 head01 vehicle conflict' });

    const vehCheck = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [vehBooking.id]))[0];
    ctx.assert.equal(vehCheck.status, 'pending_head', 'Conflicting vehicle assignment must block forwarding');
    ctx.assert.notEqual(Number(vehCheck.vehicle_id), 2, 'Conflicting vehicle_id=2 must not be assigned');
  }
});

test({
  id: 'T4-05',
  tier: 4,
  feature: 'F3,F4,F12,F13',
  name: 'QR maintenance mobile workflow (?room_id & ?asset_id), login redirect preservation, camera upload, and 4-status technician repair w/ after photo (Mobile 375x812 & Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const roomIssue = 'แอร์น้ำหยด T4-05 Room';
    const assetIssue = 'หลอดภาพมืด T4-05 Asset';
    let roomTicket;
    let assetTicket;

    // 1. Unauthenticated QR scan ?room_id=1 -> redirect to login -> back to ?room_id=1 -> submit mobile photo
    await withRolePage(ctx, MOBILE, null, async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/maintenance.php?room_id=1`, { waitUntil: 'domcontentloaded' });
      ctx.assert.ok(new URL(page.url()).pathname.endsWith('/login.php'), 'Unauthenticated QR scan must redirect to /login.php');
      ctx.assert.ok(page.url().includes('redirect='), 'Login URL must preserve redirect query parameter');

      await fillFirst(page, ['[data-testid="login-identifier"]', 'input[name="identifier"]'], 'user01', 'identifier');
      await fillFirst(page, ['[data-testid="login-password"]', 'input[name="password"]'], ctx.env.seedPassword, 'password');
      await submitAndWait(page, page, ['[data-testid="login-submit"]', 'button[type="submit"]']);

      ctx.assert.ok(page.url().includes('maintenance.php') && page.url().includes('room_id=1'), `Expected redirect back to maintenance.php?room_id=1 (got ${page.url()})`);
      await assertNoHorizontalOverflow(ctx, page, 'maintenance.php?room_id=1 mobile');

      const bodyText = await page.locator('main').innerText();
      const locInputVal = await page.locator('input[name="location_text"], input[name="location"]').first().inputValue().catch(() => '');
      ctx.assert.ok(
        bodyText.includes('RM-101') || bodyText.includes('ราชพฤกษ์') || locInputVal.includes('ราชพฤกษ์'),
        'QR ?room_id=1 must auto-populate Room 1 details'
      );

      const photoInput = await probeLocator(page, ['input[type="file"][name="before_photo"]', 'input[type="file"][name="photo"]', 'input[type="file"]']);
      ctx.assert.ok(photoInput, 'Maintenance form must have photo file input');
      const acceptAttr = (await photoInput.getAttribute('accept')) || '';
      const captureAttr = await photoInput.getAttribute('capture');
      ctx.assert.ok(acceptAttr.includes('image'), `Photo input must accept image/* (got "${acceptAttr}")`);
      ctx.assert.ok(captureAttr !== null, 'Photo input must support mobile camera capture attribute');

      await fillFirst(page, ['input[name="equipment_name"]', 'input[name="equipment"]'], 'เครื่องปรับอากาศ RM-101', 'equipment_name');
      await fillFirst(page, ['textarea[name="issue_description"]', 'textarea[name="description"]'], roomIssue, 'issue_description');
      await photoInput.setInputFiles(ctx.fixturePath('sample.png'));
      await submitAndWait(page, page);

      roomTicket = (await ctx.db.queryJson(
        'SELECT * FROM maintenance_requests WHERE issue_description = ? ORDER BY id DESC LIMIT 1',
        [roomIssue]
      ))[0];
      ctx.assert.ok(roomTicket, 'Room maintenance ticket must be created');
      ctx.assert.equal(Number(roomTicket.room_id), 1);
      ctx.assert.equal(roomTicket.status, 'รอรับเรื่อง');
      ctx.assert.ok(roomTicket.before_photo_path, 'before_photo_path must be stored');
      registerUploadCleanup(ctx, roomTicket.before_photo_path);

      // Second QR URL: ?asset_id=4 (AST-PRJ-01)
      await page.goto(`${ctx.baseUrl}/maintenance.php?asset_id=4`, { waitUntil: 'domcontentloaded' });
      const assetText = await page.locator('main').innerText();
      const equipVal = await page.locator('input[name="equipment_name"], input[name="equipment"]').first().inputValue().catch(() => '');
      ctx.assert.ok(
        assetText.includes('AST-PRJ-01') || assetText.includes('โปรเจคเตอร์') || equipVal.includes('โปรเจคเตอร์'),
        'QR ?asset_id=4 must pre-fill asset details'
      );
      await fillFirst(page, ['textarea[name="issue_description"]', 'textarea[name="description"]'], assetIssue, 'issue_description');
      const photoInput2 = await probeLocator(page, ['input[type="file"][name="before_photo"]', 'input[type="file"][name="photo"]', 'input[type="file"]']);
      await photoInput2.setInputFiles(ctx.fixturePath('sample.jpg'));
      await submitAndWait(page, page);

      assetTicket = (await ctx.db.queryJson(
        'SELECT * FROM maintenance_requests WHERE issue_description = ? ORDER BY id DESC LIMIT 1',
        [assetIssue]
      ))[0];
      ctx.assert.ok(assetTicket && Number(assetTicket.asset_id) === 4, 'Asset maintenance ticket must be saved with asset_id=4');
      registerUploadCleanup(ctx, assetTicket.before_photo_path);
    }, { label: 'T4-05 user01 mobile QR maintenance' });

    // 2. Technician (staff01) 4-status progression on Desktop 1280x800 + after_photo upload
    await withRolePage(ctx, DESKTOP, 'staff01', async ({ page }) => {
      const transitions = [
        { status: 'กำลังซ่อม', note: 'ตรวจเช็กท่อน้ำทิ้ง' },
        { status: 'รออะไหล่', note: 'รอเบิกข้อต่อท่อน้ำทิ้ง' },
        { status: 'เสร็จสิ้น', note: 'เปลี่ยนข้อต่อเรียบร้อย', attachAfter: true }
      ];

      for (const step of transitions) {
        await page.goto(`${ctx.baseUrl}/maintenance_manage.php`, { waitUntil: 'domcontentloaded' });
        const scope = page.locator('tr, .card, .list-group-item').filter({
          hasText: roomTicket.ticket_code
        }).first();
        await scope.waitFor({ state: 'visible', timeout: 5000 });

        const statusSel = await probeLocator(scope, ['select[name="status"]']);
        ctx.assert.ok(statusSel, 'Status <select> must exist for technician ticket management');
        await statusSel.selectOption(step.status);

        const noteField = await probeLocator(scope, ['textarea[name="repair_notes"]', 'input[name="repair_notes"]', 'textarea[name="notes"]']);
        if (noteField) await noteField.fill(step.note);

        if (step.attachAfter) {
          const afterInput = await probeLocator(scope, ['input[type="file"][name="after_photo"]', 'input[type="file"]']);
          ctx.assert.ok(afterInput, 'after_photo file input must exist on maintenance_manage.php');
          await afterInput.setInputFiles(ctx.fixturePath('sample.jpg'));
        }

        await submitAndWait(page, scope);

        const row = (await ctx.db.queryJson('SELECT * FROM maintenance_requests WHERE id = ?', [roomTicket.id]))[0];
        ctx.assert.equal(row.status, step.status, `Ticket status must transition to ${step.status}`);
        ctx.assert.equal(Number(row.assigned_staff_id), 2, 'assigned_staff_id must be set to staff01 (id=2)');
        if (step.attachAfter) {
          ctx.assert.ok(row.after_photo_path, 'after_photo_path must be stored on completion');
          ctx.assert.ok(row.completed_at !== null, 'completed_at must be populated on เสร็จสิ้น');
          registerUploadCleanup(ctx, row.after_photo_path);
        }
      }
    }, { label: 'T4-05 staff01 4-status repair' });

    // 3. Download verification (owner 200, ext01 403)
    const ownerHttp = await ctx.http.login('user01', ctx.env.seedPassword);
    const beforeDl = await ownerHttp.get(`/download.php?type=maintenance_before&id=${roomTicket.id}`);
    const afterDl = await ownerHttp.get(`/download.php?type=maintenance_after&id=${roomTicket.id}`);
    ctx.assert.equal(beforeDl.status, 200);
    ctx.assert.equal(afterDl.status, 200);

    const extHttp = await ctx.http.login('ext01', ctx.env.seedPassword);
    const extBlocked = await extHttp.get(`/download.php?type=maintenance_before&id=${roomTicket.id}`);
    ctx.assert.equal(extBlocked.status, 403, 'External user must get 403 downloading internal maintenance photo');
  }
});

test({
  id: 'T4-06',
  tier: 4,
  feature: 'F3,F4,F14',
  name: 'Shared equipment & key borrow/return lifecycle: atomic stock decrement, zero-stock guard, and multi-user return handler audit (Mobile 375x812 & Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const expectedSlot = currentMonthSlot(28, 17, 0, 17, 0);

    const initialStock = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(initialStock, 2, 'Seeded AST-KEY-101 (id=1) should start with available_stock=2');

    let borrowRow;
    // 1. user01 borrows quantity=2 of Asset 1 at Mobile 375x812
    await withRolePage(ctx, MOBILE, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
      await assertNoHorizontalOverflow(ctx, page, 'assets_borrow.php mobile');

      const form = page.locator('form').filter({ has: page.locator('select[name="asset_id"], input[name="quantity"]') }).first();
      await selectOptionResilient(form, ['select[name="asset_id"]', 'select[name="asset"]'], ['1'], ['AST-KEY-101'], 'asset_id');
      await fillFirst(form, ['input[name="quantity"]', 'input[name="qty"]'], '2', 'quantity');

      const retField = await probeLocator(form, ['input[name="expected_return_at"]', 'input[name="expected_return_date"]', 'input[name="return_date"]']);
      if (retField) {
        const type = ((await retField.getAttribute('type')) || 'text').toLowerCase();
        await retField.fill(type === 'datetime-local' ? expectedSlot.startLocal : type === 'date' ? expectedSlot.date : expectedSlot.startSql);
      }

      const purposeField = await probeLocator(form, ['input[name="purpose"]', 'textarea[name="purpose"]']);
      if (purposeField) await purposeField.fill('ยืมกุญแจจัดเตรียมห้องประชุม T4-06');

      await submitAndWait(page, form);

      borrowRow = (await ctx.db.queryJson(
        "SELECT * FROM asset_borrowings WHERE asset_id = 1 AND borrower_id = 6 AND status = 'borrowed' ORDER BY id DESC LIMIT 1"
      ))[0];
      ctx.assert.ok(borrowRow, 'Borrow record must be created for user01 (id=6)');
      ctx.assert.equal(Number(borrowRow.quantity), 2);
    }, { label: 'T4-06 user01 mobile borrow' });

    let stockNow = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(stockNow, 0, 'Borrowing 2 of 2 must decrement available_stock to 0');

    // 2. Zero-stock guard: user02 attempts to borrow Asset 1 (UI + HTTP POST guard)
    await withRolePage(ctx, DESKTOP, 'user02', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
      const assetSelect = await probeLocator(page, ['select[name="asset_id"]', 'select[name="asset"]']);
      const canSelectAsset1 = assetSelect
        ? await assetSelect.locator('option[value="1"]').evaluate((el) => Boolean(el && !el.disabled)).catch(() => false)
        : false;
      if (canSelectAsset1) {
        await assetSelect.selectOption('1');
        await fillFirst(page, ['input[name="quantity"]', 'input[name="qty"]'], '1', 'quantity');
        const retField = await probeLocator(page, ['input[name="expected_return_at"]', 'input[name="expected_return_date"]']);
        if (retField) {
          const t = ((await retField.getAttribute('type')) || '').toLowerCase();
          await retField.fill(t === 'datetime-local' ? expectedSlot.startLocal : expectedSlot.date);
        }
        await submitAndWait(page, page);
      }
    }, { label: 'T4-06 user02 zero-stock UI' });

    // Force-test HTTP POST zero-stock guard regardless of whether UI disabled the option
    const user2Http = await ctx.http.login('user02', ctx.env.seedPassword);
    const borrowPage = await user2Http.get('/assets_borrow.php');
    const csrf2 = extractAppCsrf(borrowPage.text);
    await user2Http.postForm('/assets_borrow.php', {
      _csrf_token: csrf2,
      action: 'borrow',
      asset_id: '1',
      quantity: '1',
      expected_return_at: expectedSlot.startLocal,
      purpose: 'Attempt zero-stock borrow'
    });

    stockNow = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(stockNow, 0, 'available_stock must remain 0 and never become negative');
    const extraBorrows = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM asset_borrowings WHERE asset_id = 1 AND borrower_id = 7 AND status = 'borrowed'"
    ));
    ctx.assert.equal(extraBorrows, 0, 'Zero-stock borrow attempt must not insert asset_borrowings row');

    // 3. Multi-user return handler audit: staff01 returns user01's borrowing at Desktop 1280x800
    await withRolePage(ctx, DESKTOP, 'staff01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/assets_borrow.php`, { waitUntil: 'domcontentloaded' });
      const row = page.locator('tr, .card').filter({ hasText: borrowRow.borrow_code }).first();
      await row.waitFor({ state: 'visible', timeout: 5000 });

      const returnNote = await probeLocator(row, ['input[name="return_note"]', 'textarea[name="return_note"]']);
      if (returnNote) await returnNote.fill('ตรวจรับกุญแจครบ 2 ชุด');

      const returnBtn = await probeLocator(row, [
        'button[name="action"][value="return"]',
        'button:has-text("รับคืน")',
        'button:has-text("คืน")',
        'button[type="submit"]'
      ]);
      ctx.assert.ok(returnBtn, 'Return button must exist on active borrow row for staff01');
      await submitAndWait(page, row, [
        'button[name="action"][value="return"]',
        'button:has-text("รับคืน")',
        'button:has-text("คืน")',
        'button[type="submit"]'
      ]);
    }, { label: 'T4-06 staff01 return' });

    const returnedRow = (await ctx.db.queryJson('SELECT * FROM asset_borrowings WHERE id = ?', [borrowRow.id]))[0];
    ctx.assert.equal(returnedRow.status, 'returned');
    ctx.assert.ok(returnedRow.returned_at !== null, 'returned_at must be populated');
    ctx.assert.equal(Number(returnedRow.return_handler_id), 2, 'return_handler_id must record staff01 (id=2), distinct from borrower_id (6)');

    stockNow = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(stockNow, 2, 'Returning borrowing must restore available_stock to 2');

    // 4. Double-return guard via HTTP POST
    const staffHttp = await ctx.http.login('staff01', ctx.env.seedPassword);
    const staffPage = await staffHttp.get('/assets_borrow.php');
    const csrfStaff = extractAppCsrf(staffPage.text);
    await staffHttp.postForm('/assets_borrow.php', {
      _csrf_token: csrfStaff,
      action: 'return',
      borrowing_id: String(borrowRow.id),
      borrow_id: String(borrowRow.id),
      return_note: 'Duplicate return test'
    });

    stockNow = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = 1'));
    ctx.assert.equal(stockNow, 2, 'Double-return must not push available_stock above total_stock=2');
  }
});

test({
  id: 'T4-07',
  tier: 4,
  feature: 'F4,F15,F17',
  name: 'Executive Dashboard KPIs & 4 Chart.js v4 canvases + Monthly Bookings/Maintenance CSV exports (Mobile 375x812 & Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const { yearMonth } = getBangkokNowParts();

    const dbTotalBookings = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?",
      [yearMonth]
    ));
    const dbPending = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director')"
    ));
    const dbMaintTotal = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ?",
      [yearMonth]
    ));

    // 1. Mobile 375x812 executive view (director01)
    await withRolePage(ctx, MOBILE, 'director01', async ({ page }) => {
      await verifyRoleNavigation(ctx, page, [
        'nav-calendar',
        'nav-book-room',
        'nav-book-vehicle',
        'nav-my-bookings',
        'nav-approvals',
        'nav-maintenance',
        'nav-assets-borrow',
        'nav-dashboard'
      ], true);

      await verifyDashboardCharts(ctx, page, 'MOBILE 375x812');

      const dashText = await page.locator('main, [data-testid="main-content"]').first().innerText();
      ctx.assert.ok(dashText.includes(String(dbTotalBookings)), `Mobile dashboard KPI must reflect total bookings=${dbTotalBookings}`);
      ctx.assert.ok(dashText.includes(String(dbPending)), `Mobile dashboard KPI must reflect pending=${dbPending}`);
      ctx.assert.ok(dashText.includes(String(dbMaintTotal)), `Mobile dashboard KPI must reflect maintenance=${dbMaintTotal}`);
    }, { label: 'T4-07 director01 mobile dashboard' });

    // 2. Desktop 1280x800 executive view + CSV exports (admin)
    await withRolePage(ctx, DESKTOP, 'admin', async ({ page }) => {
      await verifyDashboardCharts(ctx, page, 'DESKTOP 1280x800');

      const bookingsExportLink = page.locator('a[href*="export_csv.php"][href*="bookings"], button[data-export="bookings"]').first();
      const [bookingsDownload] = await Promise.all([
        page.waitForEvent('download', { timeout: 10000 }),
        (await bookingsExportLink.count()) > 0
          ? bookingsExportLink.click()
          : page.evaluate((ym) => { window.location.href = `/export_csv.php?type=bookings&month=${ym}`; }, yearMonth)
      ]);

      const bookingsBuf = await fs.readFile(await bookingsDownload.path());
      const bookingsCsv = parseCsvRows(bookingsBuf);
      ctx.assert.equal(bookingsCsv.hasBom, true, 'Bookings CSV must start with UTF-8 BOM (0xEF 0xBB 0xBF)');
      ctx.assert.equal(bookingsCsv.dataRows.length, dbTotalBookings, `Bookings CSV row count must match MySQL (${dbTotalBookings})`);
      ctx.assert.ok(bookingsCsv.rawText.includes('BK-001') && bookingsCsv.rawText.includes('BK-006'), 'Bookings CSV must contain seeded BK-001..BK-006');

      const maintExportLink = page.locator('a[href*="export_csv.php"][href*="maintenance"], button[data-export="maintenance"]').first();
      const [maintDownload] = await Promise.all([
        page.waitForEvent('download', { timeout: 10000 }),
        (await maintExportLink.count()) > 0
          ? maintExportLink.click()
          : page.evaluate((ym) => { window.location.href = `/export_csv.php?type=maintenance&month=${ym}`; }, yearMonth)
      ]);

      const maintBuf = await fs.readFile(await maintDownload.path());
      const maintCsv = parseCsvRows(maintBuf);
      ctx.assert.equal(maintCsv.hasBom, true, 'Maintenance CSV must start with UTF-8 BOM');
      ctx.assert.equal(maintCsv.dataRows.length, dbMaintTotal, `Maintenance CSV row count must match MySQL (${dbMaintTotal})`);
      for (const st of ['รอรับเรื่อง', 'กำลังซ่อม', 'รออะไหล่', 'เสร็จสิ้น']) {
        ctx.assert.ok(maintCsv.rawText.includes(st), `Maintenance CSV must contain Thai status ${st}`);
      }
    }, { label: 'T4-07 admin desktop dashboard & CSV' });
  }
});

test({
  id: 'T4-08',
  tier: 4,
  feature: 'F3,F4,F5,F6,F7,F13,F16,F17',
  name: 'Admin calendar event management + complete 7-role menu visibility & HTTP RBAC boundary sweep (Mobile 375x812 & Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const slot = currentMonthSlot(5, 9, 0, 12, 0);
    const tempSlot = currentMonthSlot(6, 13, 0, 15, 0);
    const evTitle = 'T4-08 Big Assembly';
    const evUpdatedTitle = 'T4-08 Big Assembly Updated';
    const tempTitle = 'T4-08 Temp Event';

    // 1. Admin creates, updates, and deletes calendar_events on /calendar.php (with API fallback if modal form uses AJAX)
    await withRolePage(ctx, DESKTOP, 'admin', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/calendar.php`, { waitUntil: 'domcontentloaded' });
      const addBtn = await probeLocator(page, [
        '[data-testid="add-event-btn"]',
        'button:has-text("เพิ่มกิจกรรม")',
        'button:has-text("สร้างกิจกรรม")',
        '[data-bs-target*="event"]'
      ]);
      ctx.assert.ok(addBtn, 'Admin must see add-calendar-event button on calendar.php');
      await addBtn.click();

      const modal = page.locator('.modal.show').first();
      await modal.waitFor({ state: 'visible', timeout: 5000 });
      await fillFirst(modal, ['input[name="title"]', 'input[name="event_title"]'], evTitle, 'event title');
      const typeSel = await probeLocator(modal, ['select[name="event_type"]']);
      if (typeSel) await typeSel.selectOption('meeting');
      const locInput = await probeLocator(modal, ['input[name="location"]', 'input[name="event_location"]']);
      if (locInput) await locInput.fill('หอประชุมใหญ่');
      await fillDateTimeSlot(modal, 'start', slot);
      await fillDateTimeSlot(modal, 'end', slot);
      await submitAndWait(page, modal);

      const createdEv = (await ctx.db.queryJson(
        'SELECT * FROM calendar_events WHERE title = ? ORDER BY id DESC LIMIT 1',
        [evTitle]
      ))[0];
      ctx.assert.ok(createdEv, 'Admin calendar event must be inserted in calendar_events');

      // Update event via API (extracting _csrf_token from calendar.php) and create+delete a temporary event
      const csrf = await page.evaluate(() => {
        const el = document.querySelector('input[name="_csrf_token"], input[name="csrf_token"], meta[name="csrf-token"]');
        return el ? (el.value || el.content || '') : '';
      });

      const adminHttp = await ctx.http.login('admin', ctx.env.seedPassword);
      const calHtml = await adminHttp.get('/calendar.php');
      const httpCsrf = csrf || extractAppCsrf(calHtml.text);

      const updRes = await adminHttp.postJson('/api/calendar_event_save.php', {
        _csrf_token: httpCsrf,
        action: 'update',
        id: createdEv.id,
        title: evUpdatedTitle,
        event_type: 'meeting',
        location: 'หอประชุมใหญ่',
        start_datetime: slot.startSql,
        end_datetime: slot.endSql,
        is_public: 1
      }, { headers: { 'X-CSRF-Token': httpCsrf } });
      ctx.assert.ok(updRes.status < 400, `Update calendar event failed: status=${updRes.status}`);

      const tempCreate = await adminHttp.postJson('/api/calendar_event_save.php', {
        _csrf_token: httpCsrf,
        action: 'create',
        title: tempTitle,
        event_type: 'general',
        start_datetime: tempSlot.startSql,
        end_datetime: tempSlot.endSql,
        is_public: 1
      }, { headers: { 'X-CSRF-Token': httpCsrf } });
      ctx.assert.ok(tempCreate.status < 400);

      const tempRow = (await ctx.db.queryJson('SELECT * FROM calendar_events WHERE title = ? ORDER BY id DESC LIMIT 1', [tempTitle]))[0];
      ctx.assert.ok(tempRow, 'Temp calendar event should exist before delete');

      const delRes = await adminHttp.postJson('/api/calendar_event_save.php', {
        _csrf_token: httpCsrf,
        action: 'delete',
        id: tempRow.id
      }, { headers: { 'X-CSRF-Token': httpCsrf } });
      ctx.assert.ok(delRes.status < 400);

      const afterDelCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE id = ?', [tempRow.id]));
      ctx.assert.equal(afterDelCount, 0, 'Deleted calendar event must be removed from DB');

      await verifyFullCalendar(ctx, page, {
        isMobile: false,
        expectColors: ['rgb(124, 58, 237)'],
        clickEventText: evUpdatedTitle,
        verifyModal: async (modalText) => {
          ctx.assert.ok(modalText.includes(evUpdatedTitle), 'Updated event title must appear on FullCalendar');
        }
      });
    }, { label: 'T4-08 admin calendar CRUD' });

    // 2. 7-Role Menu Matrix & Security Boundary Sweep (Mobile 375x812 + HTTP 403 checks)
    const roleMatrix = [
      {
        user: 'user01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-book-vehicle', 'nav-my-bookings', 'nav-maintenance', 'nav-assets-borrow'],
        forbiddenGets: ['/approvals.php', '/maintenance_manage.php', '/qr_codes.php', '/dashboard.php', '/export_csv.php?type=bookings&month=2026-01'],
        forbiddenSaveEvent: true
      },
      {
        user: 'ext01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-my-bookings', 'nav-maintenance'],
        forbiddenGets: ['/book_vehicle.php', '/assets_borrow.php', '/approvals.php', '/maintenance_manage.php', '/qr_codes.php', '/dashboard.php', '/export_csv.php?type=bookings&month=2026-01', '/print_memo.php?id=1'],
        forbiddenSaveEvent: true
      },
      {
        user: 'head01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-book-vehicle', 'nav-my-bookings', 'nav-approvals', 'nav-maintenance', 'nav-assets-borrow', 'nav-dashboard'],
        forbiddenGets: ['/maintenance_manage.php', '/qr_codes.php'],
        forbiddenSaveEvent: true
      },
      {
        user: 'vice01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-book-vehicle', 'nav-my-bookings', 'nav-approvals', 'nav-maintenance', 'nav-assets-borrow', 'nav-dashboard'],
        forbiddenGets: ['/maintenance_manage.php', '/qr_codes.php'],
        forbiddenSaveEvent: true
      },
      {
        user: 'director01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-book-vehicle', 'nav-my-bookings', 'nav-approvals', 'nav-maintenance', 'nav-assets-borrow', 'nav-dashboard'],
        forbiddenGets: ['/maintenance_manage.php', '/qr_codes.php'],
        forbiddenSaveEvent: true
      },
      {
        user: 'staff01',
        nav: ['nav-calendar', 'nav-book-room', 'nav-book-vehicle', 'nav-my-bookings', 'nav-approvals', 'nav-maintenance', 'nav-maintenance-manage', 'nav-assets-borrow', 'nav-qr-codes', 'nav-dashboard'],
        forbiddenGets: [],
        forbiddenSaveEvent: false
      },
      {
        user: 'admin',
        nav: ALL_NAV_TESTIDS,
        forbiddenGets: [],
        forbiddenSaveEvent: false
      }
    ];

    for (const entry of roleMatrix) {
      await withRolePage(ctx, MOBILE, entry.user, async ({ page }) => {
        await verifyRoleNavigation(ctx, page, entry.nav, true);
        if (entry.user === 'ext01') {
          await verifyFullCalendar(ctx, page, {
            isMobile: true,
            clickEventText: evUpdatedTitle
          });
        }
      }, { label: `T4-08 menu ${entry.user} mobile` });

      const client = await ctx.http.login(entry.user, ctx.env.seedPassword);
      for (const pathUrl of entry.forbiddenGets) {
        const res = await client.get(pathUrl);
        ctx.assert.equal(res.status, 403, `Role ${entry.user} accessing ${pathUrl} must return HTTP 403 (got ${res.status})`);
      }
      if (entry.forbiddenSaveEvent) {
        const calPage = await client.get('/calendar.php');
        const tok = extractAppCsrf(calPage.text);
        const evRes = await client.postJson('/api/calendar_event_save.php', {
          _csrf_token: tok,
          action: 'create',
          title: 'Unauthorized Event',
          event_type: 'general',
          start_datetime: tempSlot.startSql,
          end_datetime: tempSlot.endSql
        }, { headers: tok ? { 'X-CSRF-Token': tok } : {} });
        ctx.assert.equal(evRes.status, 403, `Role ${entry.user} saving calendar_event must return HTTP 403 (got ${evRes.status})`);
      }
    }

    // IDOR check on print_memo.php?id=1 (owned by user01) for unrelated user02
    const user2Client = await ctx.http.login('user02', ctx.env.seedPassword);
    const memoIdor = await user2Client.get('/print_memo.php?id=1');
    ctx.assert.equal(memoIdor.status, 403, 'Unrelated user02 must get 403 on print_memo.php?id=1');
  }
});

test({
  id: 'T4-09',
  tier: 4,
  feature: 'F6,F8,F9,F10,F11,F15,F17',
  name: 'Multi-role rejection at Step 2, post-rejection rebooking of identical slot through final approval, notification log, and Dashboard/CSV reconciliation (Desktop 1280x800)',
  fn: async (ctx) => {
    await ctx.resetDb();
    const { yearMonth } = getBangkokNowParts();
    const slot = currentMonthSlot(28, 9, 0, 12, 0);
    const firstTitle = 'T4-09 First Attempt';
    const rebookTitle = 'T4-09 Rebooked Slot';
    const rejectReason = 'งบประมาณกิจกรรมยังไม่ผ่านการอนุมัติ';

    const snapStart = await snapshotNotifications(ctx);

    // 1. First booking: user01 -> head01 approves -> vice01 rejects
    let firstBooking;
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['4'], ['RM-302'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], firstTitle, 'title');
      await fillDateTimeSlot(page, 'start', slot);
      await fillDateTimeSlot(page, 'end', slot);
      await submitAndWait(page, page);

      firstBooking = (await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1', [firstTitle]))[0];
      ctx.assert.ok(firstBooking);
    }, { label: 'T4-09 user01 first booking' });

    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, firstBooking, { action: 'approved', comment: 'H1-PASS' });
    }, { label: 'T4-09 head01 approve first' });

    const snapBeforeReject = await snapshotNotifications(ctx);
    await withRolePage(ctx, DESKTOP, 'vice01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, firstBooking, { action: 'rejected', comment: rejectReason });
    }, { label: 'T4-09 vice01 reject first' });

    const rejectedRow = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [firstBooking.id]))[0];
    ctx.assert.equal(rejectedRow.status, 'rejected');
    ctx.assert.ok((rejectedRow.rejection_reason || '').includes(rejectReason), 'rejection_reason must be persisted');
    assertNotificationRecorded(ctx, await getNotificationDelta(ctx, snapBeforeReject), { bookingId: firstBooking.id, eventName: 'rejected' });

    // Verify rejected Stepper UI for user01, then rebook exact same slot RM-302 day 28 09:00-12:00
    let secondBooking;
    await withRolePage(ctx, DESKTOP, 'user01', async ({ page }) => {
      await page.goto(`${ctx.baseUrl}/booking_detail.php?id=${firstBooking.id}`, { waitUntil: 'domcontentloaded' });
      const detailText = await page.locator('main').innerText();
      ctx.assert.ok(detailText.includes(rejectReason), 'Rejected booking detail must show rejection reason');
      ctx.assert.ok((await page.locator('.stepper-step.is-rejected').count()) >= 1, 'Rejected booking Stepper must show .is-rejected step');

      await page.goto(`${ctx.baseUrl}/book_room.php`, { waitUntil: 'domcontentloaded' });
      await selectOptionResilient(page, ['select[name="room_id"]', 'select[name="room"]'], ['4'], ['RM-302'], 'room_id');
      await fillFirst(page, ['input[name="title"]', 'input[name="subject"]'], rebookTitle, 'title');
      await fillDateTimeSlot(page, 'start', slot);
      await fillDateTimeSlot(page, 'end', slot);
      await submitAndWait(page, page);

      secondBooking = (await ctx.db.queryJson('SELECT * FROM bookings WHERE title = ? ORDER BY id DESC LIMIT 1', [rebookTitle]))[0];
      ctx.assert.ok(secondBooking, 'Rebooking exact same slot after rejection must be allowed');
      ctx.assert.equal(secondBooking.status, 'pending_head');
    }, { label: 'T4-09 user01 rebook same slot' });

    // 2. Full 3-step approval of rebooked slot
    await withRolePage(ctx, DESKTOP, 'head01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, secondBooking, { action: 'approved', comment: 'H1-REBOOK-OK' });
    }, { label: 'T4-09 head01 approve rebook' });
    await withRolePage(ctx, DESKTOP, 'vice01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, secondBooking, { action: 'approved', comment: 'V2-REBOOK-OK' });
    }, { label: 'T4-09 vice01 approve rebook' });
    await withRolePage(ctx, DESKTOP, 'director01', async ({ page }) => {
      await performApprovalStepUi(ctx, page, secondBooking, { action: 'approved', comment: 'D3-REBOOK-OK' });
    }, { label: 'T4-09 director01 approve rebook' });

    const approvedRebook = (await ctx.db.queryJson('SELECT * FROM bookings WHERE id = ?', [secondBooking.id]))[0];
    ctx.assert.equal(approvedRebook.status, 'approved');

    // Verify full notification delta for rebooked booking
    const fullDelta = await getNotificationDelta(ctx, snapStart);
    for (const ev of ['pending_head', 'pending_vice', 'pending_director', 'approved']) {
      assertNotificationRecorded(ctx, fullDelta, { bookingId: secondBooking.id, eventName: ev });
    }

    // 3. Dashboard & CSV reconciliation (6 seeded + 2 new = 8 current-month bookings)
    const totalCurrentMonth = Number(await ctx.db.scalar(
      "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?",
      [yearMonth]
    ));
    ctx.assert.equal(totalCurrentMonth, 8, 'Current month should contain 6 seeded + 2 new = 8 bookings');

    await withRolePage(ctx, DESKTOP, 'admin', async ({ page }) => {
      await verifyDashboardCharts(ctx, page, 'T4-09 DESKTOP');
      const dashText = await page.locator('main').innerText();
      ctx.assert.ok(dashText.includes(String(totalCurrentMonth)), `Dashboard must reconcile total current-month bookings=${totalCurrentMonth}`);

      const [csvDownload] = await Promise.all([
        page.waitForEvent('download', { timeout: 10000 }),
        page.evaluate((ym) => { window.location.href = `/export_csv.php?type=bookings&month=${ym}`; }, yearMonth)
      ]);
      const csv = parseCsvRows(await fs.readFile(await csvDownload.path()));
      ctx.assert.equal(csv.hasBom, true);
      ctx.assert.equal(csv.dataRows.length, totalCurrentMonth, `Bookings CSV must reconcile ${totalCurrentMonth} rows`);
      ctx.assert.ok(csv.rawText.includes(firstTitle) && csv.rawText.includes('rejected'), 'CSV must include rejected first attempt');
      ctx.assert.ok(csv.rawText.includes(rebookTitle) && csv.rawText.includes('approved'), 'CSV must include approved rebooking');
    }, { label: 'T4-09 dashboard & CSV reconciliation' });
  }
});
