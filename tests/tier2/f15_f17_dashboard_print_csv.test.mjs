const THAI_MAINT_STATUSES = ['รอรับเรื่อง', 'กำลังซ่อม', 'รออะไหล่', 'เสร็จสิ้น'];

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

function assertNoPhpErrors(text, assert, context = 'response') {
  const s = String(text || '');
  for (const pat of [
    /Fatal error:/i,
    /Parse error:/i,
    /Uncaught\s+\w+/i,
    /PDOException/i,
    /SQLSTATE\[/i,
    /\bWarning:/i,
    /\bNotice:/i,
    /Undefined\s+(array key|variable|index|property)/i
  ]) {
    assert.ok(!pat.test(s), `Unexpected PHP/SQL diagnostic (${pat}) in ${context}`);
  }
}

function parseBomCsv(bodyBuffer, assert) {
  assert.ok(Buffer.isBuffer(bodyBuffer), 'Expected Buffer response body');
  assert.ok(bodyBuffer.length >= 3, `CSV response too short (${bodyBuffer.length} bytes)`);
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
    `SELECT b.*, u.full_name AS requester_name, r.room_code, r.name AS room_name,
            v.vehicle_code, v.plate_number, v.brand_model, d.driver_code, d.full_name AS driver_name
     FROM bookings b
     JOIN users u ON u.id = b.requester_id
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN vehicles v ON v.id = b.vehicle_id
     LEFT JOIN drivers d ON d.id = b.driver_id
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
  // F15: Executive Dashboard & Chart.js (Tier 2)
  // =========================================================================

  test({
    id: 'T2-F15-01',
    tier: 2,
    feature: 'F15',
    name: 'Dashboard and stats API return 403 for user/external_user and gate unauthenticated requests',
    async fn(ctx) {
      const { assert, http, env } = ctx;

      for (const key of ['user01', 'ext01']) {
        const client = await http.login(env.seedUsers[key].username, env.seedPassword);
        const pageRes = await client.get('/dashboard.php');
        assert.equal(pageRes.status, 403, `${key} must receive 403 on /dashboard.php`);

        const apiRes = await client.get('/api/dashboard_stats.php');
        assert.equal(apiRes.status, 403, `${key} must receive 403 on /api/dashboard_stats.php`);
        const apiJson = apiRes.json();
        assert.equal(apiJson.ok, false, `${key} /api/dashboard_stats.php should return ok: false`);
      }

      const guest = http.createClient();
      const guestPage = await guest.get('/dashboard.php', { redirect: 'manual' });
      assert.ok([301, 302, 303].includes(guestPage.status), `Guest /dashboard.php should redirect, got ${guestPage.status}`);
      assert.ok(guestPage.location && /login\.php/i.test(guestPage.location), 'Guest /dashboard.php should redirect to login.php');

      const guestApi = await guest.get('/api/dashboard_stats.php', { redirect: 'manual' });
      assert.ok([302, 401, 403].includes(guestApi.status), `Guest /api/dashboard_stats.php should gate access, got ${guestApi.status}`);
      assert.notEqual(guestApi.status, 200, 'Guest /api/dashboard_stats.php must never return 200');
    }
  });

  test({
    id: 'T2-F15-02',
    tier: 2,
    feature: 'F15',
    name: 'Empty future month (?month=2099-01) renders zero-value stats and 4 Chart.js canvases without errors',
    async fn(ctx) {
      const { assert, http, browser, env } = ctx;
      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const res = await client.get('/api/dashboard_stats.php?month=2099-01');
      assert.equal(res.status, 200);
      assert.ok(!/\bNaN\b/i.test(res.text), 'Stats JSON must not contain NaN');
      const json = res.json();
      assert.notEqual(json.ok, false);

      const leaves = flattenNumericLeaves(json);
      const nonZeroMonthly = [];
      for (const [k, v] of leaves.entries()) {
        if (/(pending|year|month|day)/i.test(k)) continue;
        if (v !== 0) nonZeroMonthly.push(`${k}=${v}`);
      }
      assert.equal(
        nonZeroMonthly.length,
        0,
        `Expected zero monthly usage/maintenance counts for 2099-01, got: ${nonZeroMonthly.join(', ')}`
      );

      const labelPairs = extractLabelValuePairs(json);
      for (const st of THAI_MAINT_STATUSES) {
        if (labelPairs.has(st)) {
          assert.equal(labelPairs.get(st), 0, `Expected ${st}=0 in 2099-01`);
        }
      }

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.director01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=2099-01`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(
        () => Array.from(document.querySelectorAll('canvas')).filter((c) => Boolean(window.Chart?.getChart(c))).length >= 4,
        { timeout: 10000 }
      );
      errors.assertNoErrors('T2-F15-02 empty month 2099-01');

      const boxes = await page.evaluate(() =>
        Array.from(document.querySelectorAll('canvas')).map((c) => {
          const r = c.getBoundingClientRect();
          return { width: r.width, height: r.height, hasChart: Boolean(window.Chart?.getChart(c)) };
        })
      );
      assert.ok(boxes.filter((b) => b.hasChart && b.width > 0 && b.height > 0).length >= 4);
    }
  });

  test({
    id: 'T2-F15-03',
    tier: 2,
    feature: 'F15',
    name: 'Invalid ?month= query parameters are handled gracefully without 500 or SQL/PHP error leaks',
    async fn(ctx) {
      const { assert, http, browser, env } = ctx;
      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);

      const badMonths = ['bad', '2026-13', '2026-00', "' OR 1=1--"];
      for (const m of badMonths) {
        const q = encodeURIComponent(m);
        const apiRes = await client.get(`/api/dashboard_stats.php?month=${q}`);
        assert.ok([200, 400].includes(apiRes.status), `Stats API for month=${m} returned ${apiRes.status}`);
        assertNoPhpErrors(apiRes.text, assert, `api/dashboard_stats.php?month=${m}`);

        const pageRes = await client.get(`/dashboard.php?month=${q}`);
        assert.ok([200, 400].includes(pageRes.status), `Dashboard page for month=${m} returned ${pageRes.status}`);
        assertNoPhpErrors(pageRes.text, assert, `dashboard.php?month=${m}`);
      }

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.admin.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=bad`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T2-F15-03 dashboard ?month=bad');
    }
  });

  test({
    id: 'T2-F15-04',
    tier: 2,
    feature: 'F15',
    name: 'Dynamic DB mutations produce exact count deltas in api/dashboard_stats.php',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const beforeRes = await client.get('/api/dashboard_stats.php?month=2098-06');
      assert.equal(beforeRes.status, 200);
      const beforeJson = beforeRes.json();
      const beforeLeaves = flattenNumericLeaves(beforeJson);
      const beforePairs = extractLabelValuePairs(beforeJson);

      const u1 = await getUserByUsername(db, 'user01');
      const ext = await getUserByUsername(db, 'ext01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const room2 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-AUD' LIMIT 1"))[0];
      const tag = uniqueTag('T2F15D');

      // 1 internal approved room booking
      const b1 = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, '2098-06-15 09:00:00', '2098-06-15 11:00:00', 10, 3, 'approved', '2098-06-15 08:00:00')`,
        [`${tag}1`, u1.id, room1.id, `Int ${tag}`]
      );
      tracker.bookingIds.push(Number(b1.lastInsertId));

      // 2 external approved room bookings
      for (const idx of [2, 3]) {
        const bExt = await db.execSql(
          `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
           VALUES (?, 'room', ?, 'external', ?, ?, '2098-06-15 12:00:00', '2098-06-15 14:00:00', 30, 3, 'approved', '2098-06-15 08:00:00')`,
          [`${tag}${idx}`, ext.id, room2.id, `Ext ${tag} ${idx}`]
        );
        tracker.bookingIds.push(Number(bExt.lastInsertId));
      }

      // 1 pending_vice vehicle booking
      const bVeh = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, title, start_datetime, end_datetime, destination, passenger_count, current_step, status, created_at)
         VALUES (?, 'vehicle', ?, 'internal', ?, '2098-06-15 08:00:00', '2098-06-15 17:00:00', 'Ministry', 4, 2, 'pending_vice', '2098-06-15 07:00:00')`,
        [`${tag}4`, u1.id, `Veh ${tag}`]
      );
      tracker.bookingIds.push(Number(bVeh.lastInsertId));

      // 3 maintenance requests: 2x กำลังซ่อม, 1x รออะไหล่
      for (const [idx, st] of [
        [1, 'กำลังซ่อม'],
        [2, 'กำลังซ่อม'],
        [3, 'รออะไหล่']
      ]) {
        const m = await db.execSql(
          `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at)
           VALUES (?, ?, ?, 'Room 101', 'Eq', 'Issue', ?, '2098-06-15 10:00:00')`,
          [`${tag}M${idx}`, u1.id, room1.id, st]
        );
        tracker.maintenanceIds.push(Number(m.lastInsertId));
      }

      const afterRes = await client.get('/api/dashboard_stats.php?month=2098-06');
      assert.equal(afterRes.status, 200);
      const afterJson = afterRes.json();
      const afterLeaves = flattenNumericLeaves(afterJson);
      const afterPairs = extractLabelValuePairs(afterJson);

      const leafDeltas = [];
      for (const [k, afterVal] of afterLeaves.entries()) {
        const beforeVal = beforeLeaves.get(k) ?? 0;
        if (afterVal !== beforeVal) leafDeltas.push(afterVal - beforeVal);
      }

      assert.ok(leafDeltas.includes(1), `Expected +1 delta in stats leaves, got ${JSON.stringify(leafDeltas)}`);
      assert.ok(leafDeltas.includes(2), `Expected +2 delta in stats leaves, got ${JSON.stringify(leafDeltas)}`);

      if (afterPairs.has('กำลังซ่อม')) {
        assert.equal(afterPairs.get('กำลังซ่อม') - (beforePairs.get('กำลังซ่อม') || 0), 2, 'กำลังซ่อม delta must be +2');
      }
      if (afterPairs.has('รออะไหล่')) {
        assert.equal(afterPairs.get('รออะไหล่') - (beforePairs.get('รออะไหล่') || 0), 1, 'รออะไหล่ delta must be +1');
      }
    }
  });

  test({
    id: 'T2-F15-05',
    tier: 2,
    feature: 'F15',
    name: 'Rejected bookings do not inflate active/approved usage counts',
    async fn(ctx) {
      const { assert, http, browser, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      const beforeRes = await client.get('/api/dashboard_stats.php?month=2098-09');
      assert.equal(beforeRes.status, 200);
      const beforeLeaves = flattenNumericLeaves(beforeRes.json());

      const u1 = await getUserByUsername(db, 'user01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const tag = uniqueTag('T2F15R');

      const bApp = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, '2098-09-10 09:00:00', '2098-09-10 11:00:00', 10, 3, 'approved', '2098-09-10 08:00:00')`,
        [`${tag}A`, u1.id, room1.id, `App ${tag}`]
      );
      tracker.bookingIds.push(Number(bApp.lastInsertId));

      const bRej = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, start_datetime, end_datetime, attendee_count, current_step, status, rejection_reason, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, '2098-09-11 09:00:00', '2098-09-11 11:00:00', 10, 1, 'rejected', 'Conflict test', '2098-09-11 08:00:00')`,
        [`${tag}R`, u1.id, room1.id, `Rej ${tag}`]
      );
      tracker.bookingIds.push(Number(bRej.lastInsertId));

      const afterRes = await client.get('/api/dashboard_stats.php?month=2098-09');
      assert.equal(afterRes.status, 200);
      const afterLeaves = flattenNumericLeaves(afterRes.json());

      const roomUsageDeltas = [];
      for (const [k, afterVal] of afterLeaves.entries()) {
        const delta = afterVal - (beforeLeaves.get(k) ?? 0);
        if (delta !== 0 && /room|internal|active|approved|usage/i.test(k) && !/total_all|including_rejected/i.test(k)) {
          roomUsageDeltas.push({ k, delta });
        }
      }
      if (roomUsageDeltas.length > 0) {
        assert.ok(
          roomUsageDeltas.some((d) => d.delta === 1),
          `Expected active/internal room usage delta of 1 (not 2), got ${JSON.stringify(roomUsageDeltas)}`
        );
      } else {
        const allDeltas = Array.from(afterLeaves.entries()).map(([k, v]) => v - (beforeLeaves.get(k) ?? 0));
        assert.ok(allDeltas.includes(1), `Expected +1 active booking delta, got ${JSON.stringify(allDeltas)}`);
      }

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.admin.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/dashboard.php?month=2098-09`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T2-F15-05 dashboard ?month=2098-09');
    }
  });

  test({
    id: 'T2-F15-06',
    tier: 2,
    feature: 'F15',
    name: 'Mobile 375x812 viewport has zero horizontal overflow across populated and empty months',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const currentMonth = await getCurrentMonth(db);

      const { page, errors } = await browser.openPage(browser.MOBILE);
      await browser.loginUi(page, env.seedUsers.head01.username, env.seedPassword);

      for (const month of [currentMonth, '2099-01']) {
        await page.goto(`${ctx.baseUrl}/dashboard.php?month=${encodeURIComponent(month)}`, {
          waitUntil: 'domcontentloaded'
        });
        await page.waitForFunction(
          () => Array.from(document.querySelectorAll('canvas')).filter((c) => Boolean(window.Chart?.getChart(c))).length >= 4,
          { timeout: 10000 }
        );
        errors.assertNoErrors(`T2-F15-06 mobile month=${month}`);

        const metrics = await page.evaluate(() => {
          const doc = document.documentElement;
          const outOfBounds = [];
          for (const el of document.querySelectorAll('.card, canvas, a[href*="export_csv.php"]')) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) continue;
            if (r.left < -1 || r.right > window.innerWidth + 2) {
              outOfBounds.push({ tag: el.tagName, cls: el.className, left: r.left, right: r.right });
            }
          }
          const containers = Array.from(document.querySelectorAll('.chart-container')).map((c) => {
            const r = c.getBoundingClientRect();
            return { w: r.width, h: r.height };
          });
          return {
            scrollWidth: doc.scrollWidth,
            clientWidth: doc.clientWidth,
            outOfBounds,
            containers
          };
        });

        assert.ok(
          metrics.scrollWidth <= metrics.clientWidth + 1,
          `Mobile overflow for ${month}: ${metrics.scrollWidth} > ${metrics.clientWidth}`
        );
        assert.equal(
          metrics.outOfBounds.length,
          0,
          `Mobile out-of-bounds elements for ${month}: ${JSON.stringify(metrics.outOfBounds)}`
        );
        for (const c of metrics.containers) {
          assert.ok(c.w > 0 && c.h > 0, `Mobile .chart-container has zero size: ${JSON.stringify(c)}`);
        }
      }
    }
  });

  // =========================================================================
  // F16: Official A4 Memo Print/PDF View (Tier 2)
  // =========================================================================

  test({
    id: 'T2-F16-01',
    tier: 2,
    feature: 'F16',
    name: 'print_memo.php prevents IDOR across non-owner internal and external users (403)',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001'); // owner user01
      const bk2 = await getBookingByRef(db, 'BK-002'); // owner ext01
      const bk4 = await getBookingByRef(db, 'BK-004'); // owner user02

      const user02 = await http.login(env.seedUsers.user02.username, env.seedPassword);
      const r1 = await user02.get(`/print_memo.php?id=${bk1.id}`);
      assert.equal(r1.status, 403, 'user02 must get 403 on user01 booking BK-001');
      assert.ok(!r1.text.includes(bk1.title), '403 response must not leak BK-001 title');

      const ext01 = await http.login(env.seedUsers.ext01.username, env.seedPassword);
      const r2 = await ext01.get(`/print_memo.php?id=${bk1.id}`);
      assert.equal(r2.status, 403, 'ext01 must get 403 on user01 booking BK-001');
      assert.ok(!r2.text.includes(bk1.title), '403 response must not leak BK-001 title');

      const user01 = await http.login(env.seedUsers.user01.username, env.seedPassword);
      const r3 = await user01.get(`/print_memo.php?id=${bk2.id}`);
      assert.equal(r3.status, 403, 'user01 must get 403 on ext01 booking BK-002');
      assert.ok(!r3.text.includes(bk2.title), '403 response must not leak BK-002 title');

      const r4 = await user01.get(`/print_memo.php?id=${bk4.id}`);
      assert.equal(r4.status, 403, 'user01 must get 403 on user02 booking BK-004');
      assert.ok(!r4.text.includes(bk4.title), '403 response must not leak BK-004 title');
    }
  });

  test({
    id: 'T2-F16-02',
    tier: 2,
    feature: 'F16',
    name: 'print_memo.php handles missing, invalid, non-numeric, and non-existent ?id= without 500 or PHP errors',
    async fn(ctx) {
      const { assert, http, env } = ctx;
      const admin = await http.login(env.seedUsers.admin.username, env.seedPassword);

      const badUrls = [
        '/print_memo.php',
        '/print_memo.php?id=',
        '/print_memo.php?id=0',
        '/print_memo.php?id=-1',
        '/print_memo.php?id=abc',
        '/print_memo.php?id=999999',
        '/print_memo.php?id=1%20OR%201=1'
      ];

      for (const u of badUrls) {
        const res = await admin.get(u);
        assert.ok([400, 404].includes(res.status), `Expected 400 or 404 for ${u}, got ${res.status}`);
        assertNoPhpErrors(res.text, assert, u);
      }
    }
  });

  test({
    id: 'T2-F16-03',
    tier: 2,
    feature: 'F16',
    name: 'Print media emulation hides .no-print, removes shadows, keeps A4 fit, and exports valid %PDF-',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk1 = await getBookingByRef(db, 'BK-001');

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk1.id}`, { waitUntil: 'networkidle' });
      errors.assertNoErrors('T2-F16-03 screen load');

      const screenCheck = await page.evaluate(() => {
        const noPrints = Array.from(document.querySelectorAll('.no-print'));
        const anyVisible = noPrints.some((el) => getComputedStyle(el).display !== 'none');
        const sheet = document.querySelector('.memo-sheet');
        return {
          noPrintCount: noPrints.length,
          anyVisible,
          sheetWidth: sheet ? sheet.getBoundingClientRect().width : null
        };
      });
      assert.ok(screenCheck.noPrintCount >= 1, 'Expected at least 1 .no-print element');
      assert.ok(screenCheck.anyVisible, 'Expected at least 1 .no-print control visible in screen media');
      if (screenCheck.sheetWidth !== null) {
        assert.ok(
          screenCheck.sheetWidth <= 820,
          `Expected .memo-sheet width <= 820px in screen media, got ${screenCheck.sheetWidth}`
        );
      }

      await page.emulateMedia({ media: 'print' });
      const printCheck = await page.evaluate(() => {
        const noPrints = Array.from(document.querySelectorAll('.no-print'));
        const allHidden = noPrints.every((el) => getComputedStyle(el).display === 'none');
        const sheet = document.querySelector('.memo-sheet');
        const sheetStyle = sheet ? getComputedStyle(sheet) : null;
        return {
          allHidden,
          sheetDisplay: sheetStyle ? sheetStyle.display : 'block',
          sheetShadow: sheetStyle ? sheetStyle.boxShadow : 'none'
        };
      });
      assert.ok(printCheck.allHidden, 'All .no-print elements must have display:none under media:print');
      assert.notEqual(printCheck.sheetDisplay, 'none', '.memo-sheet must stay visible under media:print');
      assert.equal(printCheck.sheetShadow, 'none', '.memo-sheet boxShadow must be none under media:print');

      await page.setViewportSize({ width: 794, height: 1123 });
      const a4Fit = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth
      }));
      assert.ok(
        a4Fit.scrollWidth <= a4Fit.innerWidth + 2,
        `A4 print viewport overflow: ${a4Fit.scrollWidth} > ${a4Fit.innerWidth}`
      );

      const pdfBuffer = await page.pdf({ format: 'A4', printBackground: true });
      assert.ok(pdfBuffer.length > 1000, `Generated PDF too small (${pdfBuffer.length} bytes)`);
      assert.equal(pdfBuffer.subarray(0, 5).toString('ascii'), '%PDF-', 'Generated PDF must start with %PDF-');
    }
  });

  test({
    id: 'T2-F16-04',
    tier: 2,
    feature: 'F16',
    name: 'Vehicle memo (BK-005) displays destination, passenger count, assigned vehicle, driver, and partial sign-offs',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const bk5 = await getBookingByRef(db, 'BK-005');
      const logs = await db.queryJson(
        'SELECT step_role, signer_name FROM approval_logs WHERE booking_id = ? ORDER BY step_number ASC',
        [bk5.id]
      );
      assert.equal(logs.length, 2, 'Expected 2 seeded logs on BK-005');
      const directorUser = await getUserByUsername(db, 'director01');

      const { page, errors } = await browser.openPage(browser.DESKTOP);
      await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
      await page.goto(`${ctx.baseUrl}/print_memo.php?id=${bk5.id}`, { waitUntil: 'domcontentloaded' });
      errors.assertNoErrors('T2-F16-04 BK-005 vehicle memo');

      const bodyText = await page.evaluate(() => document.body.innerText);
      assert.ok(bodyText.includes('BK-005'));
      assert.ok(bodyText.includes(bk5.destination), `Missing destination "${bk5.destination}"`);
      assert.ok(bodyText.includes(String(bk5.passenger_count)), `Missing passenger_count "${bk5.passenger_count}"`);
      assert.ok(
        bodyText.includes(bk5.plate_number) || bodyText.includes(bk5.brand_model) || bodyText.includes(bk5.vehicle_code),
        'Missing assigned vehicle details'
      );
      assert.ok(bodyText.includes(bk5.driver_name), `Missing driver name "${bk5.driver_name}"`);
      for (const l of logs) {
        assert.ok(bodyText.includes(l.signer_name), `Missing existing signer "${l.signer_name}"`);
      }
      assert.ok(!bodyText.includes(directorUser.full_name), 'Pending director signer must not appear as signed yet');
    }
  });

  test({
    id: 'T2-F16-05',
    tier: 2,
    feature: 'F16',
    name: 'Pending 0-log (BK-003) and rejected (BK-006) memos render safely without PHP warnings or errors',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const bk3 = await getBookingByRef(db, 'BK-003');
      const bk6 = await getBookingByRef(db, 'BK-006');

      const user01 = await http.login(env.seedUsers.user01.username, env.seedPassword);
      const res3 = await user01.get(`/print_memo.php?id=${bk3.id}`);
      assert.equal(res3.status, 200);
      assertNoPhpErrors(res3.text, assert, 'print_memo BK-003');
      assert.ok(res3.text.includes('BK-003'), 'BK-003 memo missing reference_code');
      assert.ok(res3.text.includes(bk3.destination), 'BK-003 memo missing destination');

      const user02 = await http.login(env.seedUsers.user02.username, env.seedPassword);
      const res6 = await user02.get(`/print_memo.php?id=${bk6.id}`);
      assert.equal(res6.status, 200);
      assertNoPhpErrors(res6.text, assert, 'print_memo BK-006');
      assert.ok(res6.text.includes('BK-006'), 'BK-006 memo missing reference_code');
      assert.ok(res6.text.includes(bk6.rejection_reason), 'BK-006 memo missing rejection_reason');
    }
  });

  test({
    id: 'T2-F16-06',
    tier: 2,
    feature: 'F16',
    name: 'Synthetic booking with XSS payload and explicit BE date renders HTML-escaped on mobile/desktop and cleans up',
    async fn(ctx) {
      const { assert, browser, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const u1 = await getUserByUsername(db, 'user01');
      const head = await getUserByUsername(db, 'head01');
      const vice = await getUserByUsername(db, 'vice01');
      const dir = await getUserByUsername(db, 'director01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const tag = uniqueTag('T2F16');
      const xssPayload = '<script>window.__xss=1</script>';

      const ins = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, ?, '2026-06-15 09:00:00', '2026-06-15 12:00:00', 10, 3, 'approved', '2026-06-15 08:00:00')`,
        [tag, u1.id, room1.id, `Title ${xssPayload}`, `Purpose ${xssPayload}`]
      );
      const synId = Number(ins.lastInsertId);
      tracker.bookingIds.push(synId);

      await db.execSql(
        `INSERT INTO approval_logs (booking_id, step_number, step_role, approver_id, action, comment, signer_name, signer_position, digital_signature, acted_at)
         VALUES (?, 1, 'head', ?, 'approved', 'OK head', ?, 'หัวหน้างาน', ?, '2026-06-15 09:10:00'),
                (?, 2, 'vice_director', ?, 'approved', 'OK vice', ?, 'รองผู้อำนวยการ', ?, '2026-06-15 10:10:00'),
                (?, 3, 'director', ?, 'approved', 'OK dir', ?, 'ผู้อำนวยการ', ?, '2026-06-15 11:10:00')`,
        [
          synId, head.id, head.full_name, head.full_name,
          synId, vice.id, vice.full_name, vice.full_name,
          synId, dir.id, dir.full_name, dir.full_name
        ]
      );

      for (const [label, viewport] of [
        ['mobile', browser.MOBILE],
        ['desktop', browser.DESKTOP]
      ]) {
        const { page, errors } = await browser.openPage(viewport);
        await browser.loginUi(page, env.seedUsers.user01.username, env.seedPassword);
        await page.goto(`${ctx.baseUrl}/print_memo.php?id=${synId}`, { waitUntil: 'domcontentloaded' });
        errors.assertNoErrors(`T2-F16-06 ${label}`);

        const res = await page.evaluate(() => ({
          xssFlag: window.__xss,
          text: document.body.innerText
        }));
        assert.equal(res.xssFlag, undefined, `${label}: XSS script executed!`);
        assert.ok(res.text.includes(xssPayload), `${label}: escaped XSS literal missing from rendered text`);
        assert.ok(res.text.includes('2569'), `${label}: expected BE year 2569 for 2026-06-15`);
        assert.ok(
          res.text.includes('มิถุนายน') || res.text.includes('มิ.ย.'),
          `${label}: expected Thai month มิถุนายน/มิ.ย.`
        );
      }
    }
  });

  // =========================================================================
  // F17: Monthly CSV/Excel Exports (Tier 2)
  // =========================================================================

  test({
    id: 'T2-F17-01',
    tier: 2,
    feature: 'F17',
    name: 'CSV formula injection triggers (=, +, -, @, tab, CR) are neutralized with leading apostrophe',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const u1 = await getUserByUsername(db, 'user01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const tag = uniqueTag('T2F17I');

      const payloads = [
        { key: `${tag}EQ`, val: `=cmd|' /C calc'!A0_${tag}EQ` },
        { key: `${tag}PL`, val: `+1+1_${tag}PL` },
        { key: `${tag}MN`, val: `-1+1_${tag}MN` },
        { key: `${tag}AT`, val: `@SUM(1+1)_${tag}AT` },
        { key: `${tag}TB`, val: `\t=1+1_${tag}TB` },
        { key: `${tag}CR`, val: `\r=1+1_${tag}CR` }
      ];

      for (let i = 0; i < payloads.length; i += 2) {
        const pA = payloads[i];
        const pB = payloads[i + 1];
        const b = await db.execSql(
          `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
           VALUES (?, 'room', ?, 'internal', ?, ?, ?, '2098-10-10 09:00:00', '2098-10-10 11:00:00', 5, 1, 'pending_head', '2098-10-10 08:00:00')`,
          [`${tag}B${i}`, u1.id, room1.id, pA.val, pB.val]
        );
        tracker.bookingIds.push(Number(b.lastInsertId));

        const m = await db.execSql(
          `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at)
           VALUES (?, ?, ?, ?, ?, ?, 'รอรับเรื่อง', '2098-10-10 10:00:00')`,
          [`${tag}M${i}`, u1.id, room1.id, pA.val, pB.val, pA.val]
        );
        tracker.maintenanceIds.push(Number(m.lastInsertId));
      }

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      for (const type of ['bookings', 'maintenance']) {
        const res = await client.get(`/export_csv.php?type=${type}&month=2098-10`);
        assert.equal(res.status, 200);
        const { dataRows } = parseBomCsv(res.bodyBuffer, assert);
        const allCells = dataRows.flat();

        for (const p of payloads) {
          const matched = allCells.filter((cell) => cell.includes(p.key));
          assert.ok(matched.length >= 1, `${type} CSV missing payload marker ${p.key}`);
          for (const cell of matched) {
            assert.ok(
              cell.startsWith("'"),
              `${type} CSV cell for ${p.key} must start with apostrophe neutralization, got ${JSON.stringify(cell)}`
            );
          }
        }

        for (const cell of allCells) {
          assert.ok(
            !/^[=+@\t\r]/.test(cell) && !/^-.+/.test(cell),
            `${type} CSV contains unescaped formula-leading cell: ${JSON.stringify(cell)}`
          );
        }
      }
    }
  });

  test({
    id: 'T2-F17-02',
    tier: 2,
    feature: 'F17',
    name: 'CSV exports return 403 for user/external_user and gate unauthenticated requests',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const month = await getCurrentMonth(db);

      for (const key of ['user01', 'ext01']) {
        const client = await http.login(env.seedUsers[key].username, env.seedPassword);
        for (const type of ['bookings', 'maintenance']) {
          const res = await client.get(`/export_csv.php?type=${type}&month=${encodeURIComponent(month)}`);
          assert.equal(res.status, 403, `${key} must receive 403 on export_csv.php?type=${type}`);
          assert.ok(!res.bodyBuffer.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf])), '403 must not emit CSV BOM');
          assert.ok(!res.text.includes('BK-001') && !res.text.includes('MT-001'), '403 must not leak CSV records');
        }
      }

      const guest = http.createClient();
      const guestRes = await guest.get(`/export_csv.php?type=bookings&month=${encodeURIComponent(month)}`, {
        redirect: 'manual'
      });
      assert.ok([301, 302, 303, 401, 403].includes(guestRes.status), `Guest export should gate access, got ${guestRes.status}`);
      assert.notEqual(guestRes.status, 200, 'Guest export must never return 200');
    }
  });

  test({
    id: 'T2-F17-03',
    tier: 2,
    feature: 'F17',
    name: 'Empty future month (2099-01) returns valid UTF-8 BOM header-only CSV (0 data rows)',
    async fn(ctx) {
      const { assert, http, env } = ctx;
      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);

      for (const type of ['bookings', 'maintenance']) {
        const res = await client.get(`/export_csv.php?type=${type}&month=2099-01`);
        assert.equal(res.status, 200);
        const ct = res.headers.get('content-type') || '';
        assert.ok(/text\/csv/i.test(ct), `Expected text/csv for ${type}, got ${ct}`);
        const { rows, header, dataRows } = parseBomCsv(res.bodyBuffer, assert);
        assert.equal(rows.length, 1, `${type} empty month should have exactly 1 header row, got ${rows.length}`);
        assert.ok(header.length >= 5, `${type} header should have >= 5 columns`);
        assert.equal(dataRows.length, 0, `${type} empty month must have 0 data rows`);
      }
    }
  });

  test({
    id: 'T2-F17-04',
    tier: 2,
    feature: 'F17',
    name: 'Invalid type and month parameters are rejected with 400 without 500 or SQL/PHP leaks',
    async fn(ctx) {
      const { assert, http, env } = ctx;
      const client = await http.login(env.seedUsers.head01.username, env.seedPassword);

      const invalidQueries = [
        '/export_csv.php?type=unknown&month=2026-11',
        '/export_csv.php?type=users&month=2026-11',
        '/export_csv.php?type=bookings&month=bad',
        '/export_csv.php?type=maintenance&month=2026-13',
        '/export_csv.php?type=bookings&month=2026-00'
      ];

      for (const q of invalidQueries) {
        const res = await client.get(q);
        assert.equal(res.status, 400, `Expected 400 for ${q}, got ${res.status}`);
        assertNoPhpErrors(res.text, assert, q);
      }
    }
  });

  test({
    id: 'T2-F17-05',
    tier: 2,
    feature: 'F17',
    name: 'Rich Thai UTF-8 text and Thai numerals export without mojibake or replacement characters',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const u1 = await getUserByUsername(db, 'user01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const tag = uniqueTag('T2F17TH');
      const thaiText = `ระบบงานบริหารทั่วไป ครั้งที่ ๑/๒๕๖๙ ฯลฯ ${tag}`;

      const b = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, ?, '2098-11-10 09:00:00', '2098-11-10 11:00:00', 8, 3, 'approved', '2098-11-10 08:00:00')`,
        [`${tag}B`, u1.id, room1.id, thaiText, thaiText]
      );
      tracker.bookingIds.push(Number(b.lastInsertId));

      const m = await db.execSql(
        `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at)
         VALUES (?, ?, ?, 'อาคาร ๑', 'อุปกรณ์ทดสอบ', ?, 'รอรับเรื่อง', '2098-11-10 10:00:00')`,
        [`${tag}M`, u1.id, room1.id, thaiText]
      );
      tracker.maintenanceIds.push(Number(m.lastInsertId));

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      for (const type of ['bookings', 'maintenance']) {
        const res = await client.get(`/export_csv.php?type=${type}&month=2098-11`);
        assert.equal(res.status, 200);
        const { dataRows } = parseBomCsv(res.bodyBuffer, assert);
        assert.equal(dataRows.length, 1, `Expected 1 ${type} row in 2098-11`);
        assert.ok(
          dataRows[0].some((cell) => cell.includes(thaiText)),
          `${type} CSV did not preserve exact Thai UTF-8 text "${thaiText}"`
        );
      }
    }
  });

  test({
    id: 'T2-F17-06',
    tier: 2,
    feature: 'F17',
    name: 'RFC-4180 escaping preserves commas, double quotes, and embedded newlines without column splitting',
    async fn(ctx) {
      const { assert, http, db, env } = ctx;
      const tracker = { bookingIds: [], maintenanceIds: [] };
      registerSyntheticCleanup(ctx, tracker);

      const u1 = await getUserByUsername(db, 'user01');
      const room1 = (await db.queryJson("SELECT id FROM rooms WHERE room_code = 'RM-101' LIMIT 1"))[0];
      const tag = uniqueTag('T2F17RFC');
      const specialText = `หัวข้อ ${tag}, "ทดสอบพิเศษ"\nบรรทัดสอง`;

      const b = await db.execSql(
        `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose, start_datetime, end_datetime, attendee_count, current_step, status, created_at)
         VALUES (?, 'room', ?, 'internal', ?, ?, ?, '2098-12-10 09:00:00', '2098-12-10 11:00:00', 8, 3, 'approved', '2098-12-10 08:00:00')`,
        [`${tag}B`, u1.id, room1.id, specialText, specialText]
      );
      tracker.bookingIds.push(Number(b.lastInsertId));

      const m = await db.execSql(
        `INSERT INTO maintenance_requests (ticket_code, reporter_id, room_id, location_text, equipment_name, issue_description, status, reported_at)
         VALUES (?, ?, ?, 'อาคาร ๑', 'อุปกรณ์', ?, 'รอรับเรื่อง', '2098-12-10 10:00:00')`,
        [`${tag}M`, u1.id, room1.id, specialText]
      );
      tracker.maintenanceIds.push(Number(m.lastInsertId));

      const client = await http.login(env.seedUsers.admin.username, env.seedPassword);
      for (const type of ['bookings', 'maintenance']) {
        const res = await client.get(`/export_csv.php?type=${type}&month=2098-12`);
        assert.equal(res.status, 200);
        const { header, dataRows } = parseBomCsv(res.bodyBuffer, assert);
        assert.equal(dataRows.length, 1, `${type} 2098-12 must parse as exactly 1 data row despite embedded newline`);
        assert.equal(
          dataRows[0].length,
          header.length,
          `${type} 2098-12 column count (${dataRows[0].length}) must equal header count (${header.length})`
        );
        const found = dataRows[0].some((cell) => cell.replace(/\r\n/g, '\n').includes(specialText));
        assert.ok(found, `${type} CSV did not preserve RFC-4180 multiline quoted value`);
      }
    }
  });
}
