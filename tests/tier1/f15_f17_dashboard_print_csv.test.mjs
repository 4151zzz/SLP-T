import fs from 'node:fs/promises';

const THAI_MAINT_STATUSES = ['รอรับเรื่อง', 'กำลังซ่อม', 'รออะไหล่', 'เสร็จสิ้น'];

const THAI_MONTHS_FULL = [
  '',
  'มกราคม',
  'กุมภาพันธ์',
  'มีนาคม',
  'เมษายน',
  'พฤษภาคม',
  'มิถุนายน',
  'กรกฎาคม',
  'สิงหาคม',
  'กันยายน',
  'ตุลาคม',
  'พฤศจิกายน',
  'ธันวาคม'
];

const THAI_MONTHS_SHORT = [
  '',
  'ม.ค.',
  'ก.พ.',
  'มี.ค.',
  'เม.ย.',
  'พ.ค.',
  'มิ.ย.',
  'ก.ค.',
  'ส.ค.',
  'ก.ย.',
  'ต.ค.',
  'พ.ย.',
  'ธ.ค.'
];

function parseCsvRfc4180(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        field += ch;
      }
      continue;
    }

    if (ch === '"' && field.length === 0) {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(field);
      field = '';
    } else if (ch === '\r') {
      if (text[i + 1] === '\n') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else if (ch === '\n') {
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else {
      field += ch;
    }
  }

  if (inQuotes) {
    throw new Error('Malformed CSV: unterminated quoted field');
  }

  if (field.length > 0 || row.length > 0) {
    row.push(field);
    rows.push(row);
  }

  return rows;
}

function parseBomCsv(bodyBuffer, assert) {
  assert.ok(Buffer.isBuffer(bodyBuffer), 'Expected Buffer response body');
  assert.ok(bodyBuffer.length > 3, `CSV response too short (${bodyBuffer.length} bytes)`);
  assert.ok(
    bodyBuffer.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf])),
    `Expected UTF-8 BOM (0xEF 0xBB 0xBF), got ${bodyBuffer.subarray(0, 3).toString('hex')}`
  );
  const text = bodyBuffer.subarray(3).toString('utf8');
  assert.ok(!text.includes('\uFFFD'), 'CSV contains invalid UTF-8 replacement character');
  assertNoPhpErrors(text, assert, 'CSV output');
  const rows = parseCsvRfc4180(text);
  assert.ok(rows.length >= 1, 'Expected at least 1 header row in CSV');
  return { text, rows, header: rows[0], dataRows: rows.slice(1) };
}

function assertNoPhpErrors(text, assert, context = 'response') {
  const s = String(text || '');
  for (const pat of [/Fatal error:/i, /Parse error:/i, /Uncaught\s+\w+/i, /PDOException/i, /SQLSTATE\[/i]) {
    assert.ok(!pat.test(s), `Unexpected PHP/SQL error (${pat}) in ${context}`);
  }
}

function collectNumbers(node, out = []) {
  if (typeof node === 'number' && Number.isFinite(node)) {
    out.push(node);
  } else if (Array.isArray(node)) {
    for (const item of node) collectNumbers(item, out);
  } else if (node && typeof node === 'object') {
    for (const val of Object.values(node)) collectNumbers(val, out);
  }
  return out;
}

function flattenNumericLeaves(node, prefix = '', out = new Map()) {
  if (typeof node === 'number' && Number.isFinite(node)) {
    out.set(prefix || '$', node);
  } else if (Array.isArray(node)) {
    node.forEach((item, idx) => flattenNumericLeaves(item, `${prefix}[${idx}]`, out));
  } else if (node && typeof node === 'object') {
    for (const [k, v] of Object.entries(node)) {
      flattenNumericLeaves(v, prefix ? `${prefix}.${k}` : k, out);
    }
  }
  return out;
}

function extractLabelValuePairs(node, pairs = new Map()) {
  if (Array.isArray(node)) {
    for (const item of node) extractLabelValuePairs(item, pairs);
    return pairs;
  }
  if (!node || typeof node !== 'object') return pairs;

  if (Array.isArray(node.labels)) {
    const labelList = node.labels;
    const candidateArrays = [];
    if (Array.isArray(node.data)) candidateArrays.push(node.data);
    if (Array.isArray(node.values)) candidateArrays.push(node.values);
    if (Array.isArray(node.counts)) candidateArrays.push(node.counts);
    if (Array.isArray(node.datasets)) {
      for (const ds of node.datasets) {
        if (ds && Array.isArray(ds.data)) candidateArrays.push(ds.data);
      }
    }
    for (const arr of candidateArrays) {
      if (arr.length === labelList.length) {
        labelList.forEach((lbl, idx) => {
          if (typeof lbl === 'string' && typeof arr[idx] === 'number') {
            const key = lbl.trim();
            pairs.set(key, (pairs.get(key) || 0) + arr[idx]);
          }
        });
      }
    }
  }

  for (const [k, v] of Object.entries(node)) {
    if (typeof v === 'number' && Number.isFinite(v)) {
      pairs.set(k, v);
    } else if (v && typeof v === 'object') {
      if ('label' in v && typeof v.label === 'string') {
        const num = typeof v.count === 'number' ? v.count : typeof v.value === 'number' ? v.value : null;
        if (num !== null) pairs.set(v.label.trim(), num);
      }
      if ('status' in v && typeof v.status === 'string') {
        const num = typeof v.count === 'number' ? v.count : typeof v.total === 'number' ? v.total : null;
        if (num !== null) pairs.set(v.status.trim(), num);
      }
      extractLabelValuePairs(v, pairs);
    }
  }
  return pairs;
}

async function getCurrentMonth(db) {
  return db.scalar("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')");
}

async function getUserByUsername(db, username) {
  const rows = await db.queryJson(
    'SELECT id, username, role, user_type, full_name, department, organization FROM users WHERE username = ? LIMIT 1',
    [username]
  );
  if (!rows.length) throw new Error(`Seed user not found: ${username}`);
  return rows[0];
}

async function getBookingByRef(db, referenceCode) {
  const rows = await db.queryJson(
    `SELECT b.*, u.full_name AS requester_name, u.department AS requester_dept, u.organization AS requester_org,
            r.room_code, r.name AS room_name
     FROM bookings b
     JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.reference_code = ?
     LIMIT 1`,
    [referenceCode]
  );
  if (!rows.length) throw new Error(`Seed booking not found: ${referenceCode}`);
  return rows[0];
}

function registerSyntheticCleanup(ctx, tracker) {
  ctx.onCleanup(async () => {
    for (const bookingId of [...tracker.bookingIds].reverse()) {
      await ctx.db.execSql('DELETE FROM notification_logs WHERE booking_id = ?', [bookingId]);
      await ctx.db.execSql('DELETE FROM approval_logs WHERE booking_id = ?', [bookingId]);
      await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [bookingId]);
    }
    for (const maintId of [...tracker.maintenanceIds].reverse()) {
      await ctx.db.execSql('DELETE FROM maintenance_requests WHERE id = ?', [maintId]);
    }
  });
}

function uniqueTag(prefix) {
  return `${prefix}_${Date.now().toString(36).slice(-5)}_${Math.random().toString(36).slice(2, 6)}`.slice(0, 28);
}

export function register(test) {
  // =========================================================================
  // F15: Executive Dashboard & Chart.js (Tier 1)
  // =========================================================================

  test({
    id: 'T1-F15-01',
    tier: 1,
    feature: 'F15',
    name: 'Executive and admin roles can access dashboard page and stats API',
    async fn(ctx) {
      const { assert, http, env } = ctx;
      const fullRoles = ['head01', 'vice01', 'director01', 'admin'];

      for (const key of fullRoles) {
        const user = env.seedUsers[key];
        const client = await http.login(user.username, env.seedPassword);

        const pageRes = await client.get('/dashboard.php');
        assert.equal(pageRes.status, 200, `${key} should get 200 on /dashboard.php`);
        assert.ok(!new URL(pageRes.url).pathname.endsWith('/login.php'), `${key} should not redirect to login.php`);
        assert.ok(/<canvas\b/i.test(pageRes.text), `${key} /dashboard.php should render <canvas> elements`);
        assertNoPhpErrors(pageRes.text, assert, `${key} /dashboard.php`);

        const apiRes = await client.get('/api/dashboard_stats.php');
        assert.equal(apiRes.status, 200, `${key} should get 200 on /api/dashboard_stats.php`);
        const ct = apiRes.headers.get('content-type') || '';
        assert.ok(/application\/json/i.test(ct), `Expected JSON content-type for ${key}, got ${ct}`);
        const data = apiRes.json();
        assert.ok(data && typeof data === 'object', `Expected JSON object for ${key}`);
        assert.notEqual(data.ok, false, `Stats API returned ok:false for ${key}`);
      }

      // staff has optional read-only dashboard access in includes/helpers.php
      const staffClient = await http.login(env.seedUsers.staff01.username, env.seedPassword);
      const staffRes = await staffClient.get('/dashboard.php');
      assert.ok([200, 403].includes(staffRes.status), `staff01 /dashboard.php status must be 200 or 403, got ${staffRes.status}`);
      assertNoPhpErrors(staffRes.text, assert, 'staff01 /dashboard.php');
    }
  });

  test({
    id: 'T1-F15-02',
    tier: 1,
    feature: 'F15',
    name: 'Dashboard KPI summary cards render dynamic MySQL counts',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const totalBookings = Number(
        await db.scalar("SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?", [month])
      );
      const nonRejectedBookings = Number(
        await db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? AND status != 'rejected'",
          [month]
        )
      );
      const totalMaint = Number(
        await db.scalar("SELECT COUNT(*) FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ?", [month])
      );
      const pendingApprovals = Number(
        await db.scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director')")
      );

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.director01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=${encodeURIComponent(month)}`, {
        waitUntil: 'domcontentloaded'
      });

      await page.locator('main .card, [data-testid="main-content"] .card').first().waitFor({ state: 'visible' });
      const info = await page.evaluate(() => {
        const root = document.querySelector('main, [data-testid="main-content"]') || document.body;
        const cards = Array.from(root.querySelectorAll('.card'));
        return {
          cardCount: cards.length,
          text: root.innerText
        };
      });

      errors.assertNoErrors('T1-F15-02 dashboard KPI cards');
      assert.ok(info.cardCount >= 4, `Expected >= 4 KPI/dashboard cards, found ${info.cardCount}`);
      assert.ok(
        info.text.includes(String(totalBookings)) || info.text.includes(String(nonRejectedBookings)),
        `Dashboard text should include booking count (${totalBookings} or ${nonRejectedBookings})`
      );
      assert.ok(info.text.includes(String(totalMaint)), `Dashboard text should include maintenance count (${totalMaint})`);
      assert.ok(
        info.text.includes(String(pendingApprovals)),
        `Dashboard text should include pending approvals count (${pendingApprovals})`
      );
    }
  });

  test({
    id: 'T1-F15-03',
    tier: 1,
    feature: 'F15',
    name: 'All 4 Chart.js v4 canvases mount with non-zero bounding boxes and domain labels',
    async fn(ctx) {
      const { assert, browser, env } = ctx;
      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.head01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php`, { waitUntil: 'domcontentloaded' });

      await page.waitForFunction(
        () =>
          typeof window.Chart === 'function' &&
          Array.from(document.querySelectorAll('canvas')).filter((c) => Boolean(window.Chart.getChart(c))).length >= 4,
        { timeout: 10000 }
      );

      const chartSummary = await page.evaluate(() => {
        const canvases = Array.from(document.querySelectorAll('canvas'));
        const charts = canvases.map((c) => {
          const rect = c.getBoundingClientRect();
          const inst = window.Chart.getChart(c);
          const labels = Array.isArray(inst?.data?.labels) ? inst.data.labels.map(String) : [];
          const datasetLabels = Array.isArray(inst?.data?.datasets)
            ? inst.data.datasets.map((d) => String(d?.label ?? ''))
            : [];
          return {
            rectWidth: rect.width,
            rectHeight: rect.height,
            attrWidth: c.width,
            attrHeight: c.height,
            hasChart: Boolean(inst),
            datasetCount: inst?.data?.datasets?.length || 0,
            labels,
            datasetLabels
          };
        });
        return {
          chartVersion: String(window.Chart?.version || ''),
          canvasCount: canvases.length,
          charts
        };
      });

      errors.assertNoErrors('T1-F15-03 Chart.js canvases');
      assert.ok(
        chartSummary.chartVersion.startsWith('4.'),
        `Expected Chart.js v4, got ${chartSummary.chartVersion}`
      );
      assert.ok(chartSummary.canvasCount >= 4, `Expected >= 4 canvases, got ${chartSummary.canvasCount}`);

      const mountedCharts = chartSummary.charts.filter((c) => c.hasChart);
      assert.ok(mountedCharts.length >= 4, `Expected >= 4 active Chart.js instances, got ${mountedCharts.length}`);

      for (const ch of mountedCharts) {
        assert.ok(ch.rectWidth > 0 && ch.rectHeight > 0, `Canvas bounding box must be non-zero: ${JSON.stringify(ch)}`);
        assert.ok(ch.attrWidth > 0 && ch.attrHeight > 0, `Canvas buffer dimensions must be non-zero: ${JSON.stringify(ch)}`);
        assert.ok(ch.datasetCount >= 1, 'Each Chart.js instance must have at least 1 dataset');
      }

      const allLabelsText = mountedCharts.flatMap((c) => [...c.labels, ...c.datasetLabels]).join(' | ');
      for (const status of THAI_MAINT_STATUSES) {
        assert.ok(allLabelsText.includes(status), `Expected chart labels to include Thai status "${status}"`);
      }
    }
  });

  test({
    id: 'T1-F15-04',
    tier: 1,
    feature: 'F15',
    name: 'api/dashboard_stats.php JSON reflects current-month MySQL counts',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);

      const roomInternal = Number(
        await db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? AND booking_type = 'room' AND requester_type = 'internal' AND status != 'rejected'",
          [month]
        )
      );
      const roomExternal = Number(
        await db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? AND booking_type = 'room' AND requester_type = 'external' AND status != 'rejected'",
          [month]
        )
      );
      const vehicleCount = Number(
        await db.scalar(
          "SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? AND booking_type = 'vehicle' AND status != 'rejected'",
          [month]
        )
      );
      const maintRows = await db.queryJson(
        "SELECT status, COUNT(*) AS cnt FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ? GROUP BY status",
        [month]
      );
      const pendingRows = await db.queryJson(
        "SELECT status, COUNT(*) AS cnt FROM bookings WHERE status IN ('pending_head', 'pending_vice', 'pending_director') GROUP BY status"
      );

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const res = await client.get(`/api/dashboard_stats.php?month=${encodeURIComponent(month)}`);
      assert.equal(res.status, 200);
      const json = res.json();
      assert.ok(json && typeof json === 'object');
      assert.notEqual(json.ok, false);

      const raw = JSON.stringify(json);
      const nums = collectNumbers(json);
      const labelPairs = extractLabelValuePairs(json);

      for (const mr of maintRows) {
        assert.ok(raw.includes(mr.status), `Stats JSON should reference maintenance status "${mr.status}"`);
        if (labelPairs.has(mr.status)) {
          assert.equal(labelPairs.get(mr.status), Number(mr.cnt), `Mismatch for ${mr.status}`);
        }
      }

      const pendingTotal = pendingRows.reduce((s, r) => s + Number(r.cnt), 0);
      assert.ok(
        nums.includes(pendingTotal) || pendingRows.every((r) => nums.includes(Number(r.cnt))),
        `Stats JSON should contain pending approval counts (total=${pendingTotal})`
      );
      assert.ok(nums.includes(vehicleCount), `Stats JSON should include vehicle count (${vehicleCount})`);
      assert.ok(
        nums.includes(roomInternal) || nums.includes(roomInternal + roomExternal),
        `Stats JSON should include room usage counts (${roomInternal}, ${roomExternal})`
      );
    }
  });

  test({
    id: 'T1-F15-05',
    tier: 1,
    feature: 'F15',
    name: 'Month filter (?month=YYYY-MM) updates dashboard stats and UI control',
    async fn(ctx) {
      const { assert, http, browser, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const u1 = await getUserByUsername(db, 'user01');
      const ext = await getUserByUsername(db, 'ext01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const room2 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-AUD' LIMIT 1"))[0];
      assert.ok(room1 && room2);

      const tag = uniqueTag('T1F15M');
      const ins1 = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, 'Test internal room', '2098-04-10 09:00:00', '2098-04-10 11:00:00', 10, 3, 'approved', '2098-04-10 08:00:00')`,
        [`${tag}1`, u1.id, room1.id, `Internal ${tag}`]
      );
      tracker.bookingIds.push(Number(ins1.lastInsertId));

      const ins2 = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'external', ?, ?, 'Test external room', '2098-04-12 09:00:00', '2098-04-12 12:00:00', 20, 3, 'approved', '2098-04-12 08:00:00')`,
        [`${tag}2`, ext.id, room2.id, `External ${tag}`]
      );
      tracker.bookingIds.push(Number(ins2.lastInsertId));

      const ins3 = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, title, purpose, start_datetime, end_datetime, destination, passenger_count, current_step, status, created_at)
         VALUES (?, 'vehicle', ?, 'internal', ?, 'Test vehicle', '2098-04-15 08:00:00', '2098-04-15 16:00:00', 'Test Dest', 4, 1, 'pending_head', '2098-04-15 07:00:00')`,
        [`${tag}3`, u1.id, `Vehicle ${tag}`]
      );
      tracker.bookingIds.push(Number(ins3.lastInsertId));

      const m1 = await db.execSql(
        `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at)
         VALUES (?, ?, ?, 'Room 101', 'Aircon', 'Test maint 1', 'รอรับเรื่อง', '2098-04-05 10:00:00')`,
        [`${tag}M1`, u1.id, room1.id]
      );
      tracker.maintenanceIds.push(Number(m1.lastInsertId));

      const m2 = await db.execSql(
        `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at, completed_at)
         VALUES (?, ?, ?, 'Room 101', 'Lamp', 'Test maint 2', 'เสร็จสิ้น', '2098-04-06 10:00:00', '2098-04-07 10:00:00')`,
        [`${tag}M2`, u1.id, room1.id]
      );
      tracker.maintenanceIds.push(Number(m2.lastInsertId));

      const client = await http.login(env.seedUsers.head01.username, env.seedPassword);
      const emptyRes = await client.get('/api/dashboard_stats.php?month=2098-03');
      const popRes = await client.get('/api/dashboard_stats.php?month=2098-04');
      assert.equal(emptyRes.status, 200);
      assert.equal(popRes.status, 200);

      const emptyLeaves = flattenNumericLeaves(emptyRes.json());
      const popLeaves = flattenNumericLeaves(popRes.json());
      const deltas = [];
      for (const [path, afterVal] of popLeaves.entries()) {
        const beforeVal = emptyLeaves.get(path) ?? 0;
        if (afterVal !== beforeVal) deltas.push(afterVal - beforeVal);
      }
      assert.ok(deltas.length >= 3, `Expected multiple numeric stat deltas between 2098-03 and 2098-04, got ${JSON.stringify(deltas)}`);
      assert.ok(deltas.includes(1), `Expected +1 delta for 2098-04 room/vehicle/maintenance items, got ${JSON.stringify(deltas)}`);

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.head01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=2098-04`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(
        () => Array.from(document.querySelectorAll('canvas')).filter((c) => Boolean(window.Chart?.getChart(c))).length >= 4,
        { timeout: 10000 }
      );
      errors.assertNoErrors('T1-F15-05 dashboard ?month=2098-04');

      const monthControlVal = await page.evaluate(() => {
        const el = document.querySelector('input[name="month"], select[name="month"]');
        return el ? el.value : null;
      });
      if (monthControlVal !== null) {
        assert.equal(monthControlVal, '2098-04', 'Month filter input should reflect 2098-04');
      }
    }
  });

  test({
    id: 'T1-F15-06',
    tier: 1,
    feature: 'F15',
    name: 'Dashboard renders cleanly without horizontal overflow on desktop (1280x800) and mobile (375x812)',
    async fn(ctx) {
      const { assert, browser, env } = ctx;

      for (const [label, viewport] of [
        ['desktop', browser.DESKTOP],
        ['mobile', browser.MOBILE]
      ]) {
        const { page, errors } = await browser.openPage(viewport);
        await browser.loginUi(page, env.seedUsers.vice01.username, env.seedPassword);
        await page.goto(`${ctx.baseUrl}/dashboard.php`, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(
          () => Array.from(document.querySelectorAll('canvas')).filter((c) => Boolean(window.Chart?.getChart(c))).length >= 4,
          { timeout: 10000 }
        );
        errors.assertNoErrors(`T1-F15-06 ${label}`);

        const layout = await page.evaluate(() => {
          const doc = document.documentElement;
          const canvases = Array.from(document.querySelectorAll('canvas')).map((c) => {
            const r = c.getBoundingClientRect();
            return { w: r.width, h: r.height, right: r.right };
          });
          const toggle = document.querySelector('[data-testid="sidebar-toggle"]');
          const toggleVisible = toggle ? getComputedStyle(toggle).display !== 'none' : false;
          return {
            scrollWidth: doc.scrollWidth,
            clientWidth: doc.clientWidth,
            innerWidth: window.innerWidth,
            canvases,
            toggleVisible
          };
        });

        assert.ok(
          layout.scrollWidth <= layout.clientWidth + 1,
          `${label}: horizontal overflow detected (${layout.scrollWidth} > ${layout.clientWidth})`
        );
        assert.ok(layout.canvases.length >= 4, `${label}: expected >= 4 canvases`);
        for (const c of layout.canvases) {
          assert.ok(c.w > 0 && c.h > 0, `${label}: canvas has zero size ${JSON.stringify(c)}`);
          assert.ok(c.right <= viewport.width + 2, `${label}: canvas exceeds viewport (${c.right} > ${viewport.width})`);
        }
        if (label === 'mobile') {
          assert.ok(layout.toggleVisible, 'Mobile sidebar toggle should be visible at 375x812');
        }
      }
    }
  });

  // =========================================================================
  // F16: Official A4 Memo Print/PDF View (Tier 1)
  // =========================================================================

  test({
    id: 'T1-F16-01',
    tier: 1,
    feature: 'F16',
    name: 'print_memo.php is accessible to booking owners (internal & external) and privileged roles',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');
      const bk2 = await getBookingByRef(db, 'BK-002');

      const allowedForBk1 = ['user01', 'head01', 'vice01', 'director01', 'staff01', 'admin'];
      for (const key of allowedForBk1) {
        const client = await http.login(env.seedUsers[key].username, env.seedPassword);
        const res = await client.get(`/print_memo.php?id=${bk1.id}`);
        assert.equal(res.status, 200, `${key} should get 200 on print_memo.php?id=${bk1.id}`);
        assert.ok(!new URL(res.url).pathname.endsWith('/login.php'), `${key} redirected to login`);
        assertNoPhpErrors(res.text, assert, `${key} print_memo BK-001`);
        assert.ok(res.text.includes('BK-001'), `${key} memo response should contain BK-001`);
      }

      const extClient = await http.login(env.seedUsers.ext01.username, env.seedPassword);
      const extRes = await extClient.get(`/print_memo.php?id=${bk2.id}`);
      assert.equal(extRes.status, 200, `ext01 owner should get 200 on print_memo.php?id=${bk2.id}`);
      assertNoPhpErrors(extRes.text, assert, 'ext01 print_memo BK-002');
      assert.ok(extRes.text.includes('BK-002'), 'ext01 memo response should contain BK-002');
    }
  });

  test({
    id: 'T1-F16-02',
    tier: 1,
    feature: 'F16',
    name: 'print_memo.php renders official บันทึกข้อความ header and Thai Buddhist Era (พ.ศ.) dates',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');
      const startStr = String(bk1.start_datetime);
      const ceYear = Number(startStr.slice(0, 4));
      const monthNum = Number(startStr.slice(5, 7));
      const beYear = String(ceYear + 543);
      const monthFull = THAI_MONTHS_FULL[monthNum];
      const monthShort = THAI_MONTHS_SHORT[monthNum];

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk1.id}`, { waitUntil: 'domcontentloaded' });

      errors.assertNoErrors('T1-F16-02 print_memo');
      const bodyText = await page.evaluate(() => document.body.innerText);
      assert.ok(bodyText.includes('บันทึกข้อความ'), 'Memo must display official header "บันทึกข้อความ"');
      assert.ok(bodyText.includes(beYear), `Memo must display Thai Buddhist Era year "${beYear}"`);
      assert.ok(
        bodyText.includes(monthFull) || bodyText.includes(monthShort),
        `Memo must display Thai month "${monthFull}" or "${monthShort}"`
      );
    }
  });

  test({
    id: 'T1-F16-03',
    tier: 1,
    feature: 'F16',
    name: 'print_memo.php loads bundled THSarabunNew font and defines @page A4 and @media print .no-print rules',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      const assetResponses = [];
      page.on('response', (res) => {
        const u = res.url();
        if (/thsarabun|\.css(\?|$)/i.test(u)) {
          assetResponses.push({ url: u, status: res.status() });
        }
      });

      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk1.id}`, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);

      errors.assertNoErrors('T1-F16-03 font & print CSS');
      for (const ar of assetResponses) {
        assert.equal(ar.status, 200, `Asset ${ar.url} returned ${ar.status}`);
      }

      const fontAndCss = await page.evaluate(() => {
        const fontFamilies = Array.from(document.fonts).map((f) => ({
          family: f.family.replace(/["']/g, ''),
          status: f.status
        }));
        const sheetTarget = document.querySelector('.memo-sheet') || document.querySelector('main') || document.body;
        const computedFont = getComputedStyle(sheetTarget).fontFamily;

        let hasPageA4Rule = false;
        let hasPrintHideRule = false;
        for (const sheet of Array.from(document.styleSheets)) {
          let rules;
          try {
            rules = sheet.cssRules;
          } catch {
            continue;
          }
          if (!rules) continue;
          for (const rule of Array.from(rules)) {
            const cssText = rule.cssText || '';
            if (rule.type === CSSRule.PAGE_RULE && /A4/i.test(cssText)) {
              hasPageA4Rule = true;
            }
            if (rule.type === CSSRule.MEDIA_RULE && /print/i.test(rule.conditionText || rule.media?.mediaText || '')) {
              if (/\.no-print/i.test(cssText) && /display\s*:\s*none/i.test(cssText)) {
                hasPrintHideRule = true;
              }
            }
          }
        }
        return { fontFamilies, computedFont, hasPageA4Rule, hasPrintHideRule };
      });

      const sarabunLoaded = fontAndCss.fontFamilies.some(
        (f) => /THSarabunNew/i.test(f.family) && f.status === 'loaded'
      );
      assert.ok(sarabunLoaded, `Expected THSarabunNew loaded in document.fonts: ${JSON.stringify(fontAndCss.fontFamilies)}`);
      assert.ok(
        /THSarabunNew/i.test(fontAndCss.computedFont),
        `Expected computed font-family to include THSarabunNew, got ${fontAndCss.computedFont}`
      );
      assert.ok(fontAndCss.hasPageA4Rule, 'Expected @page { size: A4 ... } rule in stylesheets');
      assert.ok(fontAndCss.hasPrintHideRule, 'Expected @media print rule hiding .no-print');
    }
  });

  test({
    id: 'T1-F16-04',
    tier: 1,
    feature: 'F16',
    name: 'print_memo.php displays complete booking, room, equipment, and requester details',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk1.id}`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T1-F16-04 BK-001 details');

      const bodyText = await page.evaluate(() => document.body.innerText);
      assert.ok(bodyText.includes(bk1.reference_code), 'Missing reference_code BK-001');
      assert.ok(bodyText.includes(bk1.title), 'Missing booking title');
      assert.ok(bodyText.includes(bk1.purpose), 'Missing booking purpose');
      assert.ok(
        bodyText.includes(bk1.room_name) || bodyText.includes(bk1.room_code),
        `Missing room (${bk1.room_name} / ${bk1.room_code})`
      );
      assert.ok(bodyText.includes(bk1.requester_name), `Missing requester name (${bk1.requester_name})`);
      if (bk1.requester_dept) {
        assert.ok(bodyText.includes(bk1.requester_dept), `Missing requester department (${bk1.requester_dept})`);
      }
      assert.ok(bodyText.includes(String(bk1.attendee_count)), `Missing attendee count (${bk1.attendee_count})`);
      assert.ok(
        /ไมโครโฟน|microphone/i.test(bodyText) && /โปรเจคเตอร์|projector/i.test(bodyText),
        'Expected requested equipment (microphone & projector) in memo text'
      );
    }
  });

  test({
    id: 'T1-F16-05',
    tier: 1,
    feature: 'F16',
    name: 'print_memo.php displays ordered approval_logs (head -> vice_director -> director) with signers and timestamps',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');
      const logs = await db.queryJson(
        'SELECT step_number, step_role, signer_name, signer_position, comment, acted_at FROM approval_logs WHERE booking_id = ? ORDER BY step_number ASC, acted_at ASC',
        [bk1.id]
      );
      assert.equal(logs.length, 3, 'Expected 3 seeded approval_logs for BK-001');

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk1.id}`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T1-F16-05 approval_logs');

      const bodyText = await page.evaluate(() => document.body.innerText);
      const positions = [];
      for (const log of logs) {
        const idx = bodyText.indexOf(log.signer_name);
        assert.ok(idx !== -1, `Missing signer_name "${log.signer_name}"`);
        positions.push(idx);
        if (log.comment) {
          assert.ok(bodyText.includes(log.comment), `Missing approval comment "${log.comment}"`);
        }
        const actedBeYear = String(Number(String(log.acted_at).slice(0, 4)) + 543);
        assert.ok(bodyText.includes(actedBeYear), `Missing BE timestamp year "${actedBeYear}" for ${log.step_role}`);
      }

      assert.ok(
        positions[0] < positions[1] && positions[1] < positions[2],
        `Expected head -> vice_director -> director order, got indices ${JSON.stringify(positions)}`
      );
    }
  });

  test({
    id: 'T1-F16-06',
    tier: 1,
    feature: 'F16',
    name: 'Guest access to print_memo.php redirects to login.php with redirect param and booking_detail.php links to memo',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');

      const guest = http.createClient();
      const guestRes = await guest.get(`/print_memo.php?id=${bk1.id}`, { redirect: 'manual' });
      assert.ok([301, 302, 303].includes(guestRes.status), `Expected 302 redirect for guest, got ${guestRes.status}`);
      assert.ok(guestRes.location, 'Expected Location header on guest redirect');
      const locUrl = new URL(guestRes.location, ctx.baseUrl);
      assert.ok(locUrl.pathname.endsWith('/login.php'), `Expected redirect to login.php, got ${guestRes.location}`);
      const redirectParam = locUrl.searchParams.get('redirect') || '';
      assert.ok(redirectParam.includes('print_memo.php'), `Expected redirect param to preserve print_memo.php, got "${redirectParam}"`);

      const userClient = await http.login(env.seedUsers.user01.username, env.seedPassword);
      const detailRes = await userClient.get(`/booking_detail.php?id=${bk1.id}`);
      assert.equal(detailRes.status, 200, 'user01 should access booking_detail.php for BK-001');
      assertNoPhpErrors(detailRes.text, assert, 'booking_detail.php');
      assert.ok(
        new RegExp(`print_memo\\.php\\?[^"']*id=${bk1.id}`, 'i').test(detailRes.text),
        `booking_detail.php?id=${bk1.id} should contain link to print_memo.php?id=${bk1.id}`
      );
    }
  });

  // =========================================================================
  // F17: Monthly CSV/Excel Exports (Tier 1)
  // =========================================================================

  test({
    id: 'T1-F17-01',
    tier: 1,
    feature: 'F17',
    name: 'Bookings CSV export starts with UTF-8 BOM (0xEF 0xBB 0xBF) and parses cleanly',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);

      for (const key of ['admin', 'head01', 'vice01', 'director01']) {
        const client = await http.login(env.seedUsers[key].username, env.seedPassword);
        const res = await client.get(`/export_csv.php?type=bookings&month=${encodeURIComponent(month)}`);
        assert.equal(res.status, 200, `${key} should get 200 on bookings CSV export`);
        const { header, dataRows } = parseBomCsv(res.bodyBuffer, assert);
        assert.ok(header.length >= 5, `${key}: expected >= 5 CSV columns, got ${header.length}`);
        assert.ok(dataRows.length >= 1, `${key}: expected >= 1 data row in current month`);
      }
    }
  });

  test({
    id: 'T1-F17-02',
    tier: 1,
    feature: 'F17',
    name: 'Maintenance CSV export starts with UTF-8 BOM (0xEF 0xBB 0xBF) and parses cleanly',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const client = await http.login(env.seedUsers.head01.username, env.seedPassword);

      const res = await client.get(`/export_csv.php?type=maintenance&month=${encodeURIComponent(month)}`);
      assert.equal(res.status, 200);
      const { header, dataRows } = parseBomCsv(res.bodyBuffer, assert);
      assert.ok(header.length >= 5, `Expected >= 5 CSV columns, got ${header.length}`);
      assert.ok(dataRows.length >= 1, 'Expected >= 1 maintenance data row in current month');
    }
  });

  test({
    id: 'T1-F17-03',
    tier: 1,
    feature: 'F17',
    name: 'CSV exports return text/csv Content-Type and attachment .csv Content-Disposition headers',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const client = await http.login(env.seedUsers.director01.username, env.seedPassword);

      for (const type of ['bookings', 'maintenance']) {
        const res = await client.get(`/export_csv.php?type=${type}&month=${encodeURIComponent(month)}`);
        assert.equal(res.status, 200, `Expected 200 for type=${type}`);
        const ct = res.headers.get('content-type') || '';
        const cd = res.headers.get('content-disposition') || '';
        assert.ok(/text\/csv/i.test(ct), `Expected Content-Type text/csv for ${type}, got "${ct}"`);
        assert.ok(/attachment/i.test(cd), `Expected Content-Disposition attachment for ${type}, got "${cd}"`);
        assert.ok(/\.csv\b/i.test(cd), `Expected .csv filename in Content-Disposition for ${type}, got "${cd}"`);
      }
    }
  });

  test({
    id: 'T1-F17-04',
    tier: 1,
    feature: 'F17',
    name: 'Bookings CSV row count and reference codes match MySQL rows for the month',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const dbRows = await db.queryJson(
        "SELECT reference_code FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? ORDER BY id ASC",
        [month]
      );
      assert.ok(dbRows.length >= 6, `Expected >= 6 seeded bookings in ${month}`);

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const res = await client.get(`/export_csv.php?type=bookings&month=${encodeURIComponent(month)}`);
      assert.equal(res.status, 200);
      const { text, header, dataRows } = parseBomCsv(res.bodyBuffer, assert);

      assert.equal(
        dataRows.length,
        dbRows.length,
        `CSV bookings count (${dataRows.length}) must match DB count (${dbRows.length})`
      );
      for (const r of dataRows) {
        assert.equal(r.length, header.length, `CSV row column count (${r.length}) != header (${header.length})`);
      }
      for (const dbRow of dbRows) {
        assert.ok(text.includes(dbRow.reference_code), `Bookings CSV missing reference_code ${dbRow.reference_code}`);
      }
    }
  });

  test({
    id: 'T1-F17-05',
    tier: 1,
    feature: 'F17',
    name: 'Maintenance CSV row count, ticket codes, and all 4 Thai statuses match MySQL rows for the month',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const dbRows = await db.queryJson(
        "SELECT ticket_code, status FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ? ORDER BY id ASC",
        [month]
      );
      assert.ok(dbRows.length >= 4, `Expected >= 4 seeded maintenance rows in ${month}`);

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const res = await client.get(`/export_csv.php?type=maintenance&month=${encodeURIComponent(month)}`);
      assert.equal(res.status, 200);
      const { text, header, dataRows } = parseBomCsv(res.bodyBuffer, assert);

      assert.equal(
        dataRows.length,
        dbRows.length,
        `CSV maintenance count (${dataRows.length}) must match DB count (${dbRows.length})`
      );
      for (const r of dataRows) {
        assert.equal(r.length, header.length, `CSV row column count (${r.length}) != header (${header.length})`);
      }
      for (const dbRow of dbRows) {
        assert.ok(text.includes(dbRow.ticket_code), `Maintenance CSV missing ticket_code ${dbRow.ticket_code}`);
      }
      for (const st of THAI_MAINT_STATUSES) {
        assert.ok(text.includes(st), `Maintenance CSV missing Thai status "${st}"`);
      }
    }
  });

  test({
    id: 'T1-F17-06',
    tier: 1,
    feature: 'F17',
    name: 'Dashboard export links/buttons trigger valid CSV downloads with UTF-8 BOM and matching rows',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const month = await getCurrentMonth(db);
      const expectedBookings = Number(
        await db.scalar("SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ?", [month])
      );
      const expectedMaint = Number(
        await db.scalar("SELECT COUNT(*) FROM maintenance_requests WHERE DATE_FORMAT(reported_at, '%Y-%m') = ?", [month])
      );

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.director01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=${encodeURIComponent(month)}`, {
        waitUntil: 'domcontentloaded'
      });
      errors.assertNoErrors('T1-F17-06 dashboard load');

      const bookingsTrigger = page
        .locator(
          'a[href*="export_csv.php"][href*="bookings"], form[action*="export_csv.php"]:has(input[value="bookings"]) button[type="submit"], button[data-export="bookings"]'
        )
        .first();
      const maintTrigger = page
        .locator(
          'a[href*="export_csv.php"][href*="maintenance"], form[action*="export_csv.php"]:has(input[value="maintenance"]) button[type="submit"], button[data-export="maintenance"]'
        )
        .first();

      await bookingsTrigger.waitFor({ state: 'visible' });
      await maintTrigger.waitFor({ state: 'visible' });

      const [bkDownload] = await Promise.all([page.waitForEvent('download'), bookingsTrigger.click()]);
      assert.ok(bkDownload.suggestedFilename().toLowerCase().endsWith('.csv'), 'Bookings download must end in .csv');
      const bkPath = await bkDownload.path();
      const bkBuf = await fs.readFile(bkPath);
      const bkCsv = parseBomCsv(bkBuf, assert);
      assert.equal(bkCsv.dataRows.length, expectedBookings, 'Downloaded bookings CSV row count mismatch');

      const [mtDownload] = await Promise.all([page.waitForEvent('download'), maintTrigger.click()]);
      assert.ok(mtDownload.suggestedFilename().toLowerCase().endsWith('.csv'), 'Maintenance download must end in .csv');
      const mtPath = await mtDownload.path();
      const mtBuf = await fs.readFile(mtPath);
      const mtCsv = parseBomCsv(mtBuf, assert);
      assert.equal(mtCsv.dataRows.length, expectedMaint, 'Downloaded maintenance CSV row count mismatch');

      errors.assertNoErrors('T1-F17-06 after downloads');
    }
  });
}
