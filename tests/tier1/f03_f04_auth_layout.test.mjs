import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { extractFormInfo } from '../lib/http.mjs';

function buildRegisterFieldMap(inputs, userData) {
  const payload = {};
  const csrfNames = new Set(['csrf_token', '_token', 'csrf', '__csrf_token']);
  for (const inp of inputs || []) {
    if (inp.type === 'hidden' && inp.name && !csrfNames.has(inp.name.toLowerCase())) {
      payload[inp.name] = inp.value ?? '';
    }
  }
  const mappings = [
    [['full_name', 'fullname', 'name'], userData.full_name],
    [['organization', 'org', 'company', 'department'], userData.organization],
    [['phone', 'tel', 'mobile'], userData.phone],
    [['email', 'mail'], userData.email],
    [['username', 'user'], userData.username],
    [['password'], userData.password],
    [['password_confirm', 'confirm_password', 'password_confirmation', 'confirm'], userData.password]
  ];
  for (const [candidates, val] of mappings) {
    const m = (inputs || []).find((inp) => candidates.includes(inp.name.toLowerCase()));
    if (m && val !== undefined) payload[m.name] = val;
  }
  return payload;
}

async function ensureOwnerBookingFile(ctx, ownerUsername = 'ext01') {
  const users = await ctx.db.queryJson('SELECT id, user_type FROM users WHERE username = ? LIMIT 1', [ownerUsername]);
  ctx.assert.equal(users.length, 1, `Expected seed user "${ownerUsername}" in DB`);
  const ownerId = Number(users[0].id);
  const ownerType = users[0].user_type || 'external';

  const existing = await ctx.db.queryJson(
    `SELECT id, permission_letter_path
     FROM bookings
     WHERE requester_id = ?
       AND permission_letter_path IS NOT NULL
       AND permission_letter_path != ''
     ORDER BY id ASC
     LIMIT 5`,
    [ownerId]
  );

  for (const row of existing) {
    const rel = String(row.permission_letter_path).replace(/^\/+/, '');
    const abs = path.join(ctx.env.projectRoot, rel);
    if (fs.existsSync(abs) && fs.statSync(abs).size > 0) {
      return { bookingId: Number(row.id), relPath: rel, absPath: abs };
    }
  }

  const fixturePdf = ctx.fixturePath('permission_letter.pdf');
  ctx.assert.ok(fs.existsSync(fixturePdf), `Missing fixture file: ${fixturePdf}`);

  const permDir = path.join(ctx.env.projectRoot, 'uploads', 'permissions');
  fs.mkdirSync(permDir, { recursive: true });
  const hex = crypto.randomBytes(12).toString('hex');
  const fileName = `e2ef3_${hex}.pdf`;
  const relPath = `uploads/permissions/${fileName}`;
  const absPath = path.join(permDir, fileName);
  fs.copyFileSync(fixturePdf, absPath);

  ctx.onCleanup(() => {
    try {
      if (fs.existsSync(absPath)) fs.unlinkSync(absPath);
    } catch {
      // ignore cleanup error
    }
  });

  let rooms = await ctx.db.queryJson('SELECT id FROM rooms WHERE allow_external = 1 ORDER BY id ASC LIMIT 1');
  if (!rooms.length) {
    rooms = await ctx.db.queryJson('SELECT id FROM rooms ORDER BY id ASC LIMIT 1');
  }
  ctx.assert.ok(rooms.length >= 1, 'Expected at least one seeded room to create temp booking');
  const roomId = Number(rooms[0].id);

  const refCode = `E2EF3-${Date.now()}-${hex.slice(0, 6)}`;
  const ins = await ctx.db.execSql(
    `INSERT INTO bookings
      (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose,
       start_datetime, end_datetime, attendee_count, permission_letter_path, permission_letter_original_name,
       current_step, status)
     VALUES (?, 'room', ?, ?, ?, ?, ?, '2026-12-20 09:00:00', '2026-12-20 10:00:00', 5, ?, 'permission_letter.pdf', 1, 'pending_head')`,
    [refCode, ownerId, ownerType, roomId, 'E2E Permission Download Test', 'E2E verification', relPath]
  );
  const bookingId = Number(ins.lastInsertId);

  ctx.onCleanup(async () => {
    await ctx.db.execSql('DELETE FROM bookings WHERE id = ?', [bookingId]).catch(() => {});
  });

  return { bookingId, relPath, absPath };
}

async function resolveDownloadUrl(ctx, client, { bookingId, relPath }) {
  const candidates = [];

  for (const pagePath of [`/booking_detail.php?id=${bookingId}`, '/my_bookings.php']) {
    const pageRes = await client.get(pagePath, { redirect: 'manual' });
    if (pageRes.status === 200 && pageRes.text) {
      const matches = pageRes.text.matchAll(/href=["']([^"']*download\.php[^"']*)["']/gi);
      for (const m of matches) {
        const rawHref = m[1].replace(/&amp;/gi, '&');
        if (!candidates.includes(rawHref)) candidates.push(rawHref);
      }
    }
  }

  const probePaths = [
    `/download.php?path=${encodeURIComponent(relPath)}`,
    `/download.php?file=${encodeURIComponent(relPath)}`,
    `/download.php?booking_id=${bookingId}`,
    `/download.php?type=booking&id=${bookingId}`,
    `/download.php?type=permission&id=${bookingId}`,
    `/download.php?id=${bookingId}`
  ];
  for (const p of probePaths) {
    if (!candidates.includes(p)) candidates.push(p);
  }

  const attempts = [];
  for (const url of candidates) {
    const res = await client.get(url, { redirect: 'manual' });
    const isPdf = res.status === 200 && res.bodyBuffer.subarray(0, 5).toString('ascii') === '%PDF-';
    attempts.push(`${url} -> ${res.status} (${res.bodyBuffer.length}B)`);
    if (isPdf) {
      return { url, res };
    }
  }

  throw new Error(`Could not download owner PDF via download.php. Tried: ${attempts.join('; ')}`);
}

async function getOverflowMetrics(page) {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    clientWidth: document.documentElement.clientWidth
  }));
}

async function collectVisibleNavBasenames(page) {
  return page.evaluate(() => {
    const containers = document.querySelectorAll('nav, aside, .sidebar, .offcanvas, header, [role="navigation"]');
    const visibleBasenames = new Set();
    const allBasenames = new Set();
    for (const root of containers) {
      for (const a of root.querySelectorAll('a[href]')) {
        const rawHref = a.getAttribute('href') || '';
        if (!rawHref || rawHref.startsWith('#') || rawHref.startsWith('javascript:')) continue;
        let pathname = '';
        try {
          pathname = new URL(a.href, window.location.origin).pathname;
        } catch {
          continue;
        }
        const base = pathname.split('/').pop() || '';
        if (!base.endsWith('.php')) continue;
        allBasenames.add(base);

        let hiddenByAncestor = false;
        let cur = a;
        while (cur && cur !== document.body) {
          const cs = getComputedStyle(cur);
          if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '1') === 0) {
            hiddenByAncestor = true;
            break;
          }
          cur = cur.parentElement;
        }
        const rect = a.getBoundingClientRect();
        const onScreen = rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth;
        if (!hiddenByAncestor && onScreen) {
          visibleBasenames.add(base);
        }
      }
    }
    return {
      visible: [...visibleBasenames],
      all: [...allBasenames]
    };
  });
}

async function resolveOffcanvasSelector(page) {
  return page.evaluate(() => {
    const toggle = document.querySelector('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"], .navbar-toggler[data-bs-target]');
    const target = toggle?.getAttribute('data-bs-target') || toggle?.getAttribute('href') || '';
    if (target && target.startsWith('#') && document.querySelector(target)) {
      return target;
    }
    const controls = toggle?.getAttribute('aria-controls');
    if (controls && document.getElementById(controls)) {
      return `#${controls}`;
    }
    return '.offcanvas';
  });
}

export function register(test) {
  // ---------------------------------------------------------------------------
  // F3: Internal & External Authentication (Tier 1)
  // ---------------------------------------------------------------------------

  test({
    id: 'T1-F3-01',
    tier: 1,
    feature: 'F3',
    name: 'Internal login works via username (user01) in HTTP and UI',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      assert.ok(client.getCookies('/').length > 0, 'Expected session cookie after HTTP login');

      const protectedPages = ['/index.php', '/my_bookings.php', '/calendar.php'];
      let okRes = null;
      for (const p of protectedPages) {
        const res = await client.get(p, { redirect: 'manual' });
        if (res.status === 200) {
          okRes = res;
          break;
        }
      }
      assert.ok(okRes, 'Expected authenticated user01 to access at least one protected page with HTTP 200');
      assert.ok(!new URL(okRes.url).pathname.endsWith('/login.php'), 'Authenticated request stayed on login.php');

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      assert.ok(!new URL(page.url()).pathname.endsWith('/login.php'), 'UI login did not leave /login.php');
      const cookies = await page.context().cookies();
      assert.ok(cookies.length > 0, 'Expected browser session cookie after UI login');
      errors.assertNoErrors('T1-F3-01');
    }
  });

  test({
    id: 'T1-F3-02',
    tier: 1,
    feature: 'F3',
    name: 'Internal login works via staff_id (EMP001) in HTTP and UI',
    fn: async (ctx) => {
      const { assert } = ctx;
      const staffId = ctx.env.seedUsers.user01.staffId;
      const dbRow = await ctx.db.queryJson(
        'SELECT username, user_type, role, staff_id FROM users WHERE staff_id = ? LIMIT 1',
        [staffId]
      );
      assert.equal(dbRow.length, 1, `Expected user with staff_id "${staffId}"`);
      assert.equal(dbRow[0].username, 'user01');
      assert.equal(dbRow[0].user_type, 'internal');
      assert.equal(dbRow[0].role, 'user');

      const client = await ctx.http.login(staffId, ctx.env.seedPassword);
      const res = await client.get('/index.php', { redirect: 'manual' });
      assert.equal(res.status, 200, `Expected /index.php 200 after staff_id login, got ${res.status}`);

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, staffId, ctx.env.seedPassword);
      assert.ok(!new URL(page.url()).pathname.endsWith('/login.php'), 'UI staff_id login did not leave /login.php');
      errors.assertNoErrors('T1-F3-02');
    }
  });

  test({
    id: 'T1-F3-03',
    tier: 1,
    feature: 'F3',
    name: 'External user registration creates external/external_user in DB and logs in',
    fn: async (ctx) => {
      const { assert } = ctx;
      const unique = `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
      const email = `ext_t1_${unique}@example.org`;
      const username = `ext_t1_${unique}`;
      const userData = {
        full_name: 'ผู้ขอใช้ภายนอก ทดสอบ',
        organization: 'มูลนิธิทดสอบ E2E',
        phone: '0891234567',
        email,
        username,
        password: ctx.env.seedPassword
      };

      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM users WHERE email = ?', [email]).catch(() => {});
      });

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      const regView = await page.goto(`${ctx.baseUrl}/register.php`, { waitUntil: 'load' });
      assert.equal(regView?.status(), 200, 'Expected GET /register.php in browser to return 200');
      assert.ok((await page.locator('form input[type="password"]').count()) >= 1, 'Expected password input on /register.php');
      errors.assertNoErrors('T1-F3-03 register page render');

      const reg = await ctx.http.registerExternal(userData);
      assert.ok(reg.client.getCookies('/').length > 0, 'Expected authenticated session after external registration');

      const dbRows = await ctx.db.queryJson(
        'SELECT username, full_name, organization, phone, email, user_type, role, staff_id FROM users WHERE email = ? LIMIT 1',
        [email]
      );
      assert.equal(dbRows.length, 1, 'Expected newly registered external user in DB');
      assert.equal(dbRows[0].user_type, 'external');
      assert.equal(dbRows[0].role, 'external_user');
      assert.equal(dbRows[0].staff_id, null);
      assert.equal(dbRows[0].full_name, userData.full_name);
      assert.equal(dbRows[0].organization, userData.organization);
      assert.equal(dbRows[0].phone, userData.phone);

      const idxRes = await reg.client.get('/index.php', { redirect: 'manual' });
      assert.notEqual(idxRes.status, 302, 'Newly registered external client should remain authenticated on /index.php');

      const freshClient = await ctx.http.login(email, userData.password);
      assert.ok(freshClient.getCookies('/').length > 0, 'Fresh login with registered email should succeed');
    }
  });

  test({
    id: 'T1-F3-04',
    tier: 1,
    feature: 'F3',
    name: 'Safe relative ?redirect= parameter is preserved across login',
    fn: async (ctx) => {
      const { assert } = ctx;
      const baseOrigin = new URL(ctx.baseUrl).origin;

      for (const target of ['/my_bookings.php', '/maintenance.php?room_id=1']) {
        const attempt = await ctx.http.attemptLogin(ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
          redirect: target,
          postOpts: { redirect: 'manual' }
        });
        assert.equal(attempt.authenticated, true, `Expected login with redirect=${target} to authenticate`);
        assert.ok([302, 303].includes(attempt.postRes.status), `Expected 302/303 redirect, got ${attempt.postRes.status}`);
        assert.ok(attempt.postRes.location, 'Expected Location header after login');
        const resolved = new URL(attempt.postRes.location, ctx.baseUrl);
        assert.equal(resolved.origin, baseOrigin, 'Redirect must stay on same origin');
        assert.equal(
          `${resolved.pathname}${resolved.search}`,
          target,
          `Expected redirect to preserve "${target}", got "${resolved.pathname}${resolved.search}"`
        );
      }

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
        redirect: '/index.php?from=redirect_test'
      });
      const landed = new URL(page.url());
      assert.equal(landed.origin, baseOrigin);
      assert.equal(landed.pathname, '/index.php');
      assert.equal(landed.searchParams.get('from'), 'redirect_test');
      errors.assertNoErrors('T1-F3-04');
    }
  });

  test({
    id: 'T1-F3-05',
    tier: 1,
    feature: 'F3',
    name: 'logout.php invalidates session server-side and redirects subsequent requests to login.php',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const sessCookie = client.getCookie('PHPSESSID') || client.getCookie('phpsessid');
      assert.ok(sessCookie, 'Expected PHPSESSID cookie after login');

      const anon = ctx.http.createClient();
      let protectedPath = null;
      let protectedHtml = '';
      for (const candidate of ['/my_bookings.php', '/book_room.php', '/index.php']) {
        const authRes = await client.get(candidate, { redirect: 'manual' });
        const anonRes = await anon.get(candidate, { redirect: 'manual' });
        const anonBlocks =
          anonRes.status === 401 ||
          ([301, 302, 303, 307, 308].includes(anonRes.status) && String(anonRes.location || '').includes('login.php'));
        if (authRes.status === 200 && anonBlocks) {
          protectedPath = candidate;
          protectedHtml = authRes.text || '';
          break;
        }
      }
      assert.ok(protectedPath, 'Could not find a protected page returning 200 when logged in and redirecting anon to login.php');

      const logoutForm = extractFormInfo(protectedHtml, 'logout');
      if (logoutForm && logoutForm.action.toLowerCase().includes('logout') && logoutForm.method === 'POST') {
        const payload = buildRegisterFieldMap(logoutForm.inputs, {});
        if (logoutForm.csrfToken !== null) {
          payload[logoutForm.csrfFieldName || 'csrf_token'] = logoutForm.csrfToken;
        }
        const actionUrl = new URL(logoutForm.action, `${ctx.baseUrl}${protectedPath}`).toString();
        await client.postForm(actionUrl, payload);
      } else {
        const linkMatch = protectedHtml.match(/<a\b[^>]*href=["']([^"']*logout\.php[^"']*)["']/i);
        if (linkMatch) {
          const href = linkMatch[1].replace(/&amp;/gi, '&');
          await client.get(new URL(href, `${ctx.baseUrl}${protectedPath}`).toString());
        } else {
          await ctx.http.logout(client);
        }
      }

      const afterLogout = await client.get(protectedPath, { redirect: 'manual' });
      const blockedAfterLogout =
        afterLogout.status === 401 ||
        ([301, 302, 303, 307, 308].includes(afterLogout.status) && String(afterLogout.location || '').includes('login.php'));
      assert.ok(
        blockedAfterLogout,
        `Expected ${protectedPath} after logout to redirect to login.php or return 401, got ${afterLogout.status} (${afterLogout.location})`
      );

      const replayClient = ctx.http.createClient();
      const replayRes = await replayClient.get(protectedPath, {
        redirect: 'manual',
        headers: { cookie: `PHPSESSID=${sessCookie}` }
      });
      const replayBlocked =
        replayRes.status === 401 ||
        ([301, 302, 303, 307, 308].includes(replayRes.status) && String(replayRes.location || '').includes('login.php'));
      assert.ok(
        replayBlocked,
        `Replaying pre-logout PHPSESSID should be rejected, got ${replayRes.status} (${replayRes.location})`
      );
    }
  });

  test({
    id: 'T1-F3-06',
    tier: 1,
    feature: 'F3',
    name: 'Authenticated download.php serves owner file accurately',
    fn: async (ctx) => {
      const { assert } = ctx;
      const fileInfo = await ensureOwnerBookingFile(ctx, 'ext01');
      const client = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);

      const { res } = await resolveDownloadUrl(ctx, client, fileInfo);
      assert.equal(res.status, 200);
      assert.equal(res.bodyBuffer.subarray(0, 5).toString('ascii'), '%PDF-', 'Downloaded body must start with %PDF-');

      const expectedBytes = fs.readFileSync(fileInfo.absPath);
      assert.equal(
        Buffer.compare(res.bodyBuffer, expectedBytes),
        0,
        'Downloaded file bytes must match the owner file on disk'
      );

      const ct = (res.headers.get('content-type') || '').toLowerCase();
      assert.ok(
        ct.includes('application/pdf') || ct.includes('application/octet-stream'),
        `Unexpected download Content-Type "${ct}"`
      );
    }
  });

  // ---------------------------------------------------------------------------
  // F4: Mobile-First Layout & Role Menus (Tier 1)
  // ---------------------------------------------------------------------------

  test({
    id: 'T1-F4-01',
    tier: 1,
    feature: 'F4',
    name: 'Desktop layout (1280x800) displays navbar & sidebar with zero horizontal overflow',
    fn: async (ctx) => {
      const { assert } = ctx;
      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });

      const navbar = page.locator('header, nav.navbar, .navbar').first();
      assert.ok((await navbar.count()) > 0, 'Expected navbar/header element on desktop');
      assert.equal(await navbar.isVisible(), true, 'Expected navbar to be visible on desktop');
      const navBox = await navbar.boundingBox();
      assert.ok(navBox && navBox.width >= 300 && navBox.height > 0, 'Expected non-collapsed navbar bounding box');

      const navInfo = await collectVisibleNavBasenames(page);
      for (const reqLink of ['calendar.php', 'book_room.php', 'my_bookings.php', 'maintenance.php']) {
        assert.ok(navInfo.visible.includes(reqLink), `Expected visible desktop nav link "${reqLink}", got: ${navInfo.visible.join(', ')}`);
      }

      const offcanvasToggle = page.locator('[data-bs-toggle="offcanvas"]').first();
      if ((await offcanvasToggle.count()) > 0) {
        assert.equal(await offcanvasToggle.isVisible(), false, 'Offcanvas toggle should be hidden at 1280x800 (>=992px)');
      }

      const metrics = await getOverflowMetrics(page);
      assert.ok(
        metrics.scrollWidth <= metrics.innerWidth,
        `Desktop horizontal overflow: scrollWidth=${metrics.scrollWidth} > innerWidth=${metrics.innerWidth}`
      );
      errors.assertNoErrors('T1-F4-01');
    }
  });

  test({
    id: 'T1-F4-02',
    tier: 1,
    feature: 'F4',
    name: 'Mobile layout (375x812) offcanvas hamburger menu opens and closes cleanly with zero horizontal overflow',
    fn: async (ctx) => {
      const { assert } = ctx;
      const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });

      const closedMetrics = await getOverflowMetrics(page);
      assert.ok(
        closedMetrics.scrollWidth <= closedMetrics.innerWidth,
        `Mobile closed overflow: scrollWidth=${closedMetrics.scrollWidth} > innerWidth=${closedMetrics.innerWidth}`
      );

      const toggle = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"], .navbar-toggler[data-bs-target]').first();
      assert.ok((await toggle.count()) > 0, 'Expected mobile offcanvas toggle button');
      assert.equal(await toggle.isVisible(), true, 'Expected offcanvas toggle to be visible at 375x812');
      const toggleBox = await toggle.boundingBox();
      assert.ok(toggleBox && toggleBox.x >= 0 && toggleBox.x + toggleBox.width <= 375, 'Toggle button overflows mobile viewport');

      const offcanvasSel = await resolveOffcanvasSelector(page);
      const offcanvas = page.locator(offcanvasSel).first();
      assert.ok((await offcanvas.count()) > 0, `Expected offcanvas container (${offcanvasSel})`);
      const initiallyShown = await offcanvas.evaluate((el) => el.classList.contains('show'));
      assert.equal(initiallyShown, false, 'Offcanvas drawer should not have .show initially');

      const hiddenNavLink = offcanvas.locator('a[href*="calendar.php"]').first();
      if ((await hiddenNavLink.count()) > 0) {
        const linkOnScreen = await hiddenNavLink.evaluate((el) => {
          const r = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          return cs.visibility !== 'hidden' && cs.display !== 'none' && r.right > 0 && r.left < window.innerWidth;
        });
        assert.equal(linkOnScreen, false, 'Offcanvas link should not be visible on-screen before opening drawer');
      }

      await toggle.click();
      await page.waitForFunction(
        (sel) => {
          const el = document.querySelector(sel);
          if (!el || !el.classList.contains('show')) return false;
          const r = el.getBoundingClientRect();
          return getComputedStyle(el).visibility === 'visible' && r.width > 0 && r.left >= 0;
        },
        offcanvasSel,
        { timeout: 5000 }
      );

      const expanded = await toggle.getAttribute('aria-expanded');
      if (expanded !== null) {
        assert.equal(expanded, 'true', 'Expected aria-expanded="true" when offcanvas is open');
      }

      for (const reqLink of ['calendar.php', 'book_room.php', 'my_bookings.php', 'maintenance.php']) {
        const lnk = offcanvas.locator(`a[href*="${reqLink}"]`).first();
        assert.ok((await lnk.count()) > 0, `Missing ${reqLink} inside opened offcanvas`);
        assert.equal(await lnk.isVisible(), true, `Expected ${reqLink} to be visible inside opened offcanvas`);
      }

      const openMetrics = await getOverflowMetrics(page);
      assert.ok(
        openMetrics.scrollWidth <= openMetrics.innerWidth,
        `Mobile open-drawer overflow: scrollWidth=${openMetrics.scrollWidth} > innerWidth=${openMetrics.innerWidth}`
      );

      const closeBtn = offcanvas.locator('[data-bs-dismiss="offcanvas"], .btn-close').first();
      assert.ok((await closeBtn.count()) > 0, 'Expected close button inside offcanvas');
      assert.equal(await closeBtn.isVisible(), true, 'Expected close button to be visible');
      await closeBtn.click();

      await page.waitForFunction(
        (sel) => {
          const el = document.querySelector(sel);
          return !el || !el.classList.contains('show');
        },
        offcanvasSel,
        { timeout: 5000 }
      );

      const reclosedMetrics = await getOverflowMetrics(page);
      assert.ok(reclosedMetrics.scrollWidth <= reclosedMetrics.innerWidth, 'Mobile horizontal overflow after closing drawer');
      errors.assertNoErrors('T1-F4-02');
    }
  });

  test({
    id: 'T1-F4-03',
    tier: 1,
    feature: 'F4',
    name: 'Role-based menus differentiate user (internal) vs external_user accurately',
    fn: async (ctx) => {
      const { assert } = ctx;

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });
        const nav = await collectVisibleNavBasenames(page);

        for (const shown of ['calendar.php', 'book_room.php', 'my_bookings.php', 'maintenance.php', 'book_vehicle.php', 'assets_borrow.php']) {
          assert.ok(nav.visible.includes(shown), `Internal user01 should see "${shown}" in nav`);
        }
        for (const hidden of ['approvals.php', 'maintenance_manage.php', 'qr_codes.php', 'dashboard.php']) {
          assert.ok(!nav.visible.includes(hidden), `Internal user01 must NOT see "${hidden}" in nav`);
        }
        errors.assertNoErrors('T1-F4-03 user01');
      }

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });
        const nav = await collectVisibleNavBasenames(page);

        for (const shown of ['calendar.php', 'book_room.php', 'my_bookings.php', 'maintenance.php']) {
          assert.ok(nav.visible.includes(shown), `External ext01 should see "${shown}" in nav`);
        }
        for (const hidden of ['book_vehicle.php', 'assets_borrow.php', 'approvals.php', 'maintenance_manage.php', 'qr_codes.php', 'dashboard.php']) {
          assert.ok(!nav.visible.includes(hidden), `External ext01 must NOT see "${hidden}" in nav`);
        }
        errors.assertNoErrors('T1-F4-03 ext01');
      }
    }
  });

  test({
    id: 'T1-F4-04',
    tier: 1,
    feature: 'F4',
    name: 'Executive/approver menus show approvals.php and dashboard.php to head, vice_director, and director',
    fn: async (ctx) => {
      const { assert } = ctx;
      const roles = [ctx.env.seedUsers.head01, ctx.env.seedUsers.vice01, ctx.env.seedUsers.director01];
      const expectedShown = [
        'calendar.php',
        'book_room.php',
        'book_vehicle.php',
        'my_bookings.php',
        'approvals.php',
        'maintenance.php',
        'assets_borrow.php',
        'dashboard.php'
      ];
      const expectedHidden = ['maintenance_manage.php', 'qr_codes.php'];

      for (const u of roles) {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, u.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });
        const nav = await collectVisibleNavBasenames(page);

        for (const link of expectedShown) {
          assert.ok(nav.visible.includes(link), `${u.username} (${u.role}) should see "${link}"`);
        }
        for (const link of expectedHidden) {
          assert.ok(!nav.visible.includes(link), `${u.username} (${u.role}) must NOT see staff-only "${link}"`);
        }
        const m = await getOverflowMetrics(page);
        assert.ok(m.scrollWidth <= m.innerWidth, `Horizontal overflow for ${u.username}`);
        errors.assertNoErrors(`T1-F4-04 ${u.username}`);
      }
    }
  });

  test({
    id: 'T1-F4-05',
    tier: 1,
    feature: 'F4',
    name: 'Staff & admin menus show maintenance_manage.php, qr_codes.php, approvals.php, and dashboard.php (for admin)',
    fn: async (ctx) => {
      const { assert } = ctx;

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });
        const nav = await collectVisibleNavBasenames(page);

        const adminExpected = [
          'calendar.php',
          'book_room.php',
          'book_vehicle.php',
          'my_bookings.php',
          'approvals.php',
          'maintenance.php',
          'maintenance_manage.php',
          'assets_borrow.php',
          'qr_codes.php',
          'dashboard.php'
        ];
        for (const link of adminExpected) {
          assert.ok(nav.visible.includes(link), `admin should see "${link}" in nav`);
        }
        errors.assertNoErrors('T1-F4-05 admin');
      }

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.staff01.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });
        const nav = await collectVisibleNavBasenames(page);

        // Note: per PROJECT.md:110, dashboard.php is optional read-only for staff.
        const staffExpected = [
          'calendar.php',
          'book_room.php',
          'my_bookings.php',
          'approvals.php',
          'maintenance.php',
          'maintenance_manage.php',
          'assets_borrow.php',
          'qr_codes.php'
        ];
        for (const link of staffExpected) {
          assert.ok(nav.visible.includes(link), `staff01 should see "${link}" in nav`);
        }
        errors.assertNoErrors('T1-F4-05 staff01');
      }
    }
  });

  test({
    id: 'T1-F4-06',
    tier: 1,
    feature: 'F4',
    name: 'Tablet layout (768x1024) renders without horizontal overflow and supports offcanvas navigation',
    fn: async (ctx) => {
      const { assert } = ctx;
      const { page, errors } = await ctx.browser.openPage({ width: 768, height: 1024 });
      await ctx.browser.loginUi(page, ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
      await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });

      const m1 = await getOverflowMetrics(page);
      assert.ok(m1.scrollWidth <= m1.innerWidth, `Tablet horizontal overflow: ${m1.scrollWidth} > ${m1.innerWidth}`);

      const toggle = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"], .navbar-toggler[data-bs-target]').first();
      assert.equal(await toggle.isVisible(), true, 'Expected offcanvas toggle visible at 768x1024 (<992px)');

      const offcanvasSel = await resolveOffcanvasSelector(page);
      await toggle.click();
      await page.waitForFunction(
        (sel) => {
          const el = document.querySelector(sel);
          return Boolean(el && el.classList.contains('show') && getComputedStyle(el).visibility === 'visible');
        },
        offcanvasSel,
        { timeout: 5000 }
      );

      const offcanvas = page.locator(offcanvasSel).first();
      for (const link of ['dashboard.php', 'approvals.php', 'maintenance_manage.php']) {
        assert.equal(await offcanvas.locator(`a[href*="${link}"]`).first().isVisible(), true, `Expected ${link} visible in tablet drawer`);
      }

      const m2 = await getOverflowMetrics(page);
      assert.ok(m2.scrollWidth <= m2.innerWidth, 'Tablet overflow while drawer open');

      await offcanvas.locator('[data-bs-dismiss="offcanvas"], .btn-close').first().click();
      await page.waitForFunction(
        (sel) => {
          const el = document.querySelector(sel);
          return !el || !el.classList.contains('show');
        },
        offcanvasSel,
        { timeout: 5000 }
      );
      errors.assertNoErrors('T1-F4-06');
    }
  });
}
