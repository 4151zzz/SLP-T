import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { extractFormInfo } from '../lib/http.mjs';

function buildRegisterPayload(inputs, userData, extraFields = {}) {
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
  if (!Object.keys(payload).some((k) => ['email', 'mail'].includes(k.toLowerCase()))) {
    payload.full_name = userData.full_name;
    payload.organization = userData.organization;
    payload.phone = userData.phone;
    payload.email = userData.email;
    payload.password = userData.password;
  }
  return { ...payload, ...extraFields };
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
  const relPath = `uploads/permissions/e2ef3_${hex}.pdf`;
  const absPath = path.join(permDir, `e2ef3_${hex}.pdf`);
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
  ctx.assert.ok(rooms.length >= 1, 'Expected at least one seeded room');
  const roomId = Number(rooms[0].id);

  const refCode = `E2EF3-T2-${Date.now()}-${hex.slice(0, 6)}`;
  const ins = await ctx.db.execSql(
    `INSERT INTO bookings
      (reference_code, booking_type, requester_id, requester_type, room_id, title, purpose,
       start_datetime, end_datetime, attendee_count, permission_letter_path, permission_letter_original_name,
       current_step, status)
     VALUES (?, 'room', ?, ?, ?, ?, ?, '2026-12-21 09:00:00', '2026-12-21 10:00:00', 5, ?, 'permission_letter.pdf', 1, 'pending_head')`,
    [refCode, ownerId, ownerType, roomId, 'E2E Tier2 Permission Test', 'E2E IDOR verification', relPath]
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
    attempts.push(`${url} -> ${res.status}`);
    if (isPdf) return url;
  }

  throw new Error(`Could not resolve working owner download URL. Tried: ${attempts.join('; ')}`);
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
  // F3: Internal & External Authentication (Tier 2 - Edge Cases & Security)
  // ---------------------------------------------------------------------------

  test({
    id: 'T2-F3-01',
    tier: 2,
    feature: 'F3',
    name: 'Wrong password, unknown username, unknown staff_id, and empty credentials fail safely',
    fn: async (ctx) => {
      const { assert } = ctx;
      const attempts = [
        [ctx.env.seedUsers.user01.username, 'WrongPass!999'],
        ['unknown_user_999', ctx.env.seedPassword],
        ['NOPE999', ctx.env.seedPassword],
        ['', '']
      ];

      for (const [id, pw] of attempts) {
        const res = await ctx.http.attemptLogin(id, pw, { postOpts: { redirect: 'manual' } });
        assert.equal(res.authenticated, false, `Login should fail for "${id}"`);
        assert.ok(res.postRes.status < 500, `Login failure caused HTTP ${res.postRes.status} for "${id}"`);
        if (res.postRes.status >= 300 && res.postRes.status < 400 && res.postRes.location) {
          const loc = new URL(res.postRes.location, ctx.baseUrl);
          assert.ok(loc.pathname.endsWith('/login.php'), `Failed login redirected to ${res.postRes.location} instead of login.php`);
        }

        const probe = await res.client.get('/my_bookings.php', { redirect: 'manual' });
        const blocked =
          probe.status === 401 ||
          ([301, 302, 303, 307, 308].includes(probe.status) && String(probe.location || '').includes('login.php'));
        assert.ok(blocked, `Unauthenticated client after failed login "${id}" accessed /my_bookings.php (status=${probe.status})`);
      }
    }
  });

  test({
    id: 'T2-F3-02',
    tier: 2,
    feature: 'F3',
    name: 'Missing or invalid CSRF rejected on login, register, and logout (GET & POST)',
    fn: async (ctx) => {
      const { assert } = ctx;

      // 1. Login missing/invalid CSRF
      const loginNoCsrf = await ctx.http.attemptLogin(ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
        omitCsrf: true,
        postOpts: { redirect: 'manual' }
      });
      assert.equal(loginNoCsrf.authenticated, false, 'Login without CSRF must be rejected');

      const loginBadCsrf = await ctx.http.attemptLogin(ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
        csrfOverride: 'bad_csrf_xyz_123',
        postOpts: { redirect: 'manual' }
      });
      assert.equal(loginBadCsrf.authenticated, false, 'Login with invalid CSRF must be rejected');

      // 2. Register missing/invalid CSRF
      const u1Email = `csrf_no_${Date.now()}@example.org`;
      const u2Email = `csrf_bad_${Date.now()}@example.org`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM users WHERE email IN (?, ?)', [u1Email, u2Email]).catch(() => {});
      });

      await ctx.http.registerExternal(
        { email: u1Email, username: `csrf_no_${Date.now()}`, password: ctx.env.seedPassword },
        { omitCsrf: true, expectSuccess: false, ensureLoggedIn: false }
      );
      assert.equal(Number(await ctx.db.scalar('SELECT COUNT(*) FROM users WHERE email = ?', [u1Email])), 0, 'Register without CSRF must not create user');

      const regClient = ctx.http.createClient();
      const getReg = await regClient.get('/register.php');
      assert.equal(getReg.status, 200, 'Expected GET /register.php to return 200');
      const regForm = extractFormInfo(getReg.text, 'register');
      const badCsrfPayload = buildRegisterPayload(regForm.inputs, {
        full_name: 'Bad CSRF User',
        organization: 'Org',
        phone: '0812345678',
        email: u2Email,
        username: `csrf_bad_${Date.now()}`,
        password: ctx.env.seedPassword
      });
      badCsrfPayload[regForm.csrfFieldName || 'csrf_token'] = 'bad_csrf_xyz_123';
      const regAction = regForm.action ? new URL(regForm.action, getReg.url).toString() : '/register.php';
      await regClient.postForm(regAction, badCsrfPayload);
      assert.equal(Number(await ctx.db.scalar('SELECT COUNT(*) FROM users WHERE email = ?', [u2Email])), 0, 'Register with invalid CSRF must not create user');

      // 3. Logout missing/invalid CSRF (POST & GET)
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      let protectedPath = '/index.php';
      for (const p of ['/my_bookings.php', '/book_room.php', '/index.php']) {
        const r = await client.get(p, { redirect: 'manual' });
        if (r.status === 200) {
          protectedPath = p;
          break;
        }
      }

      const postNoCsrf = await client.postForm('/logout.php', {}, { redirect: 'manual' });
      assert.ok([400, 403, 405, 419, 422].includes(postNoCsrf.status) || postNoCsrf.status >= 400, `POST /logout.php without CSRF returned ${postNoCsrf.status}`);

      const postBadCsrf = await client.postForm(
        '/logout.php',
        { csrf_token: 'bad_csrf_xyz_123', _token: 'bad_csrf_xyz_123' },
        { redirect: 'manual' }
      );
      assert.ok([400, 403, 405, 419, 422].includes(postBadCsrf.status) || postBadCsrf.status >= 400, `POST /logout.php with invalid CSRF returned ${postBadCsrf.status}`);

      await client.get('/logout.php', { redirect: 'manual' });
      await client.get('/logout.php?csrf_token=bad_csrf_xyz_123', { redirect: 'manual' });

      const stillAuth = await client.get(protectedPath, { redirect: 'manual' });
      assert.equal(stillAuth.status, 200, `Session must remain valid after invalid GET/POST logout CSRF probes (got ${stillAuth.status})`);
    }
  });

  test({
    id: 'T2-F3-03',
    tier: 2,
    feature: 'F3',
    name: 'External registration rejects duplicate email and ignores spoofed role=admin / user_type=internal',
    fn: async (ctx) => {
      const { assert } = ctx;
      const spoofEmail = `spoof_${Date.now()}@example.org`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM users WHERE email = ?', [spoofEmail]).catch(() => {});
      });

      // 1. Duplicate email rejection
      const c1 = ctx.http.createClient();
      const g1 = await c1.get('/register.php');
      assert.equal(g1.status, 200);
      const f1 = extractFormInfo(g1.text, 'register');
      const dupPayload = buildRegisterPayload(f1.inputs, {
        full_name: 'Duplicate Attacker',
        organization: 'Attacker Org',
        phone: '0899999999',
        email: ctx.env.seedUsers.ext01.email,
        username: `dup_ext_${Date.now()}`,
        password: ctx.env.seedPassword
      });
      if (f1.csrfToken !== null) dupPayload[f1.csrfFieldName || 'csrf_token'] = f1.csrfToken;
      const dupRes = await c1.postForm(f1.action ? new URL(f1.action, g1.url).toString() : '/register.php', dupPayload);
      assert.ok(dupRes.status < 500, `Duplicate email registration returned HTTP ${dupRes.status}`);

      const extRows = await ctx.db.queryJson('SELECT role, full_name FROM users WHERE email = ?', [ctx.env.seedUsers.ext01.email]);
      assert.equal(extRows.length, 1, 'Duplicate email must not insert second row');
      assert.equal(extRows[0].role, 'external_user');
      assert.notEqual(extRows[0].full_name, 'Duplicate Attacker', 'Duplicate email registration must not overwrite existing user');

      // 2. Spoofed role/user_type/staff_id ignored
      const c2 = ctx.http.createClient();
      const g2 = await c2.get('/register.php');
      const f2 = extractFormInfo(g2.text, 'register');
      const spoofPayload = buildRegisterPayload(
        f2.inputs,
        {
          full_name: 'Privilege Escalator',
          organization: 'External Org',
          phone: '0898887777',
          email: spoofEmail,
          username: `spoof_${Date.now()}`,
          password: ctx.env.seedPassword
        },
        { role: 'admin', user_type: 'internal', staff_id: 'ADM999' }
      );
      if (f2.csrfToken !== null) spoofPayload[f2.csrfFieldName || 'csrf_token'] = f2.csrfToken;
      const spoofRes = await c2.postForm(f2.action ? new URL(f2.action, g2.url).toString() : '/register.php', spoofPayload);
      assert.ok(spoofRes.status < 500);

      const created = await ctx.db.queryJson(
        'SELECT user_type, role, staff_id FROM users WHERE email = ? LIMIT 1',
        [spoofEmail]
      );
      if (created.length > 0) {
        assert.equal(created[0].user_type, 'external', 'Spoofed user_type=internal must be ignored');
        assert.equal(created[0].role, 'external_user', 'Spoofed role=admin must be ignored');
        assert.equal(created[0].staff_id, null, 'Spoofed staff_id must be ignored');
      }
    }
  });

  test({
    id: 'T2-F3-04',
    tier: 2,
    feature: 'F3',
    name: 'Open redirect payloads are rejected across login redirect parameter',
    fn: async (ctx) => {
      const { assert } = ctx;
      const baseOrigin = new URL(ctx.baseUrl).origin;
      const payloads = [
        'https://evil.com',
        'http://evil.com/phish',
        '//evil.com',
        '//evil.com/steal',
        '/\\evil.com',
        'javascript:alert(1)'
      ];

      for (const payload of payloads) {
        const res = await ctx.http.attemptLogin(ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
          redirect: payload,
          postOpts: { redirect: 'manual' }
        });
        assert.ok(res.postRes.status < 500, `Redirect payload "${payload}" caused HTTP ${res.postRes.status}`);
        if (res.postRes.location) {
          const loc = res.postRes.location;
          assert.ok(!loc.startsWith('//') && !loc.startsWith('/\\'), `Protocol-relative/backslash open redirect leaked: ${loc}`);
          assert.ok(!/evil\.com/i.test(loc) && !/^javascript:/i.test(loc), `Unsafe redirect destination: ${loc}`);
          const parsed = new URL(loc, ctx.baseUrl);
          assert.equal(parsed.origin, baseOrigin, `Redirect escaped base origin: ${loc}`);
        }
      }

      const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
      await page.route('https://evil.com/**', (route) => route.abort());
      await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword, {
        redirect: 'https://evil.com'
      });
      assert.equal(new URL(page.url()).origin, baseOrigin, 'Browser login redirected outside application origin');
      errors.assertNoErrors('T2-F3-04');
    }
  });

  test({
    id: 'T2-F3-05',
    tier: 2,
    feature: 'F3',
    name: 'uploads/index.php and /uploads/ return 403 without exposing directory listing or source',
    fn: async (ctx) => {
      const { assert } = ctx;
      const anon = ctx.http.createClient();
      const userClient = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const adminClient = await ctx.http.login(ctx.env.seedUsers.admin.username, ctx.env.seedPassword);

      const checks = [
        [anon, '/uploads/index.php'],
        [anon, '/uploads/'],
        [userClient, '/uploads/index.php'],
        [adminClient, '/uploads/index.php']
      ];
      for (const [client, target] of checks) {
        const res = await client.get(target, { redirect: 'manual' });
        assert.equal(res.status, 403, `Expected 403 on ${target}, got ${res.status}`);
        assert.ok(!/Index of/i.test(res.text || ''), `Directory listing exposed on ${target}`);
        assert.ok(!(res.text || '').includes('<?php'), `PHP source exposed on ${target}`);
      }
    }
  });

  test({
    id: 'T2-F3-06',
    tier: 2,
    feature: 'F3',
    name: 'Unauthenticated/non-owner download.php returns 401/403 (IDOR) and blocks path traversal',
    fn: async (ctx) => {
      const { assert } = ctx;
      const fileInfo = await ensureOwnerBookingFile(ctx, 'ext01');
      const ownerClient = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const downloadUrl = await resolveDownloadUrl(ctx, ownerClient, fileInfo);

      const anon = ctx.http.createClient();
      const anonRes = await anon.get(downloadUrl, { redirect: 'manual' });
      const anonBlocked =
        anonRes.status === 401 ||
        anonRes.status === 403 ||
        ([301, 302, 303, 307, 308].includes(anonRes.status) && String(anonRes.location || '').includes('login.php'));
      assert.ok(anonBlocked, `Unauthenticated download must be blocked, got ${anonRes.status} (${anonRes.location})`);
      assert.ok(!anonRes.bodyBuffer.toString('ascii').includes('%PDF-'), 'Unauthenticated response leaked PDF bytes');

      const otherClient = await ctx.http.login(ctx.env.seedUsers.user02.username, ctx.env.seedPassword);
      const idorRes = await otherClient.get(downloadUrl, { redirect: 'manual' });
      assert.ok([401, 403].includes(idorRes.status), `Non-owner IDOR download must return 401/403, got ${idorRes.status}`);
      assert.ok(!idorRes.bodyBuffer.toString('ascii').includes('%PDF-'), 'Non-owner IDOR response leaked PDF bytes');

      const user01 = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      for (const trav of [
        '/download.php?path=../../config/db.php',
        '/download.php?file=../config/db.php',
        '/download.php?path=sql/seed.sql'
      ]) {
        const res = await user01.get(trav, { redirect: 'manual' });
        assert.ok([400, 403, 404].includes(res.status), `Traversal probe ${trav} returned unexpected HTTP ${res.status}`);
        const body = res.text || '';
        assert.ok(
          !body.includes('<?php') && !body.includes('PDO') && !body.includes(ctx.env.seedPassword),
          `Traversal probe ${trav} leaked sensitive file contents`
        );
      }
    }
  });

  // ---------------------------------------------------------------------------
  // F4: Mobile-First Layout & Role Menus (Tier 2 - RBAC & Cross-Page Audit)
  // ---------------------------------------------------------------------------

  test({
    id: 'T2-F4-01',
    tier: 2,
    feature: 'F4',
    name: 'external_user receives HTTP 403 on all internal/approver/staff/executive/export endpoints',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
      const forbidden = [
        '/book_vehicle.php',
        '/assets_borrow.php',
        '/approvals.php',
        '/maintenance_manage.php',
        '/dashboard.php',
        '/qr_codes.php',
        '/export_csv.php?type=bookings&month=2026-11'
      ];
      for (const p of forbidden) {
        const res = await client.get(p, { redirect: 'manual' });
        assert.equal(res.status, 403, `Expected 403 for external_user on ${p}, got ${res.status}`);
      }

      const allowed = await client.get('/book_room.php', { redirect: 'manual' });
      assert.equal(allowed.status, 200, `Expected 200 for external_user on /book_room.php, got ${allowed.status}`);
    }
  });

  test({
    id: 'T2-F4-02',
    tier: 2,
    feature: 'F4',
    name: 'Internal user receives HTTP 403 on approver/staff/executive/export endpoints while allowed on vehicle & asset pages',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const forbidden = [
        '/approvals.php',
        '/maintenance_manage.php',
        '/dashboard.php',
        '/qr_codes.php',
        '/export_csv.php?type=bookings&month=2026-11'
      ];
      for (const p of forbidden) {
        const res = await client.get(p, { redirect: 'manual' });
        assert.equal(res.status, 403, `Expected 403 for internal user on ${p}, got ${res.status}`);
      }

      for (const p of ['/book_vehicle.php', '/assets_borrow.php']) {
        const res = await client.get(p, { redirect: 'manual' });
        assert.equal(res.status, 200, `Expected 200 for internal user on ${p}, got ${res.status}`);
      }
    }
  });

  test({
    id: 'T2-F4-03',
    tier: 2,
    feature: 'F4',
    name: 'Approver/executive roles get 403 on staff-only maintenance_manage.php & qr_codes.php while staff is allowed',
    fn: async (ctx) => {
      const { assert } = ctx;
      for (const uname of ['head01', 'vice01', 'director01']) {
        const c = await ctx.http.login(uname, ctx.env.seedPassword);
        for (const p of ['/maintenance_manage.php', '/qr_codes.php']) {
          const res = await c.get(p, { redirect: 'manual' });
          assert.equal(res.status, 403, `Expected 403 for ${uname} on ${p}, got ${res.status}`);
        }
        for (const p of ['/approvals.php', '/dashboard.php']) {
          const res = await c.get(p, { redirect: 'manual' });
          assert.equal(res.status, 200, `Expected 200 for ${uname} on ${p}, got ${res.status}`);
        }
      }

      // Per PROJECT.md:108-110, staff01 is allowed on approvals.php and dashboard.php is optional read-only.
      const staffClient = await ctx.http.login(ctx.env.seedUsers.staff01.username, ctx.env.seedPassword);
      for (const p of ['/maintenance_manage.php', '/qr_codes.php', '/approvals.php']) {
        const res = await staffClient.get(p, { redirect: 'manual' });
        assert.equal(res.status, 200, `Expected 200 for staff01 on ${p}, got ${res.status}`);
      }
    }
  });

  test({
    id: 'T2-F4-04',
    tier: 2,
    feature: 'F4',
    name: 'Unauthenticated requests to all protected pages redirect to login.php with relative redirect param',
    fn: async (ctx) => {
      const { assert } = ctx;
      const anon = ctx.http.createClient();
      const protectedPaths = [
        '/index.php',
        '/calendar.php',
        '/book_room.php',
        '/book_vehicle.php',
        '/my_bookings.php',
        '/approvals.php',
        '/maintenance.php',
        '/maintenance_manage.php',
        '/assets_borrow.php',
        '/qr_codes.php',
        '/dashboard.php',
        '/export_csv.php?type=bookings&month=2026-11'
      ];

      for (const p of protectedPaths) {
        const res = await anon.get(p, { redirect: 'manual' });
        assert.ok([301, 302, 303, 307, 308].includes(res.status), `Expected redirect for unauthenticated ${p}, got ${res.status}`);
        assert.ok(res.location, `Missing Location header on ${p}`);
        const locUrl = new URL(res.location, ctx.baseUrl);
        assert.ok(locUrl.pathname.endsWith('/login.php'), `Expected redirect to login.php for ${p}, got ${res.location}`);
        const redir = locUrl.searchParams.get('redirect') || '';
        assert.ok(redir.startsWith('/') && !redir.startsWith('//'), `Expected relative redirect param for ${p}, got "${redir}"`);
      }
    }
  });

  test({
    id: 'T2-F4-05',
    tier: 2,
    feature: 'F4',
    name: 'Zero console.error, pageerror, HTTP >=500, failed requests, and zero horizontal overflow across all authenticated pages at 1280x800 & 375x812',
    fn: async (ctx) => {
      const { assert } = ctx;
      const pages = [
        '/index.php',
        '/calendar.php',
        '/book_room.php',
        '/book_vehicle.php',
        '/my_bookings.php',
        '/approvals.php',
        '/maintenance.php',
        '/maintenance_manage.php',
        '/assets_borrow.php',
        '/qr_codes.php',
        '/dashboard.php'
      ];

      for (const viewport of [ctx.browser.DESKTOP, ctx.browser.MOBILE]) {
        const { page, errors } = await ctx.browser.openPage(viewport);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.admin.username, ctx.env.seedPassword);
        errors.clear();

        for (const p of pages) {
          const res = await page.goto(`${ctx.baseUrl}${p}`, { waitUntil: 'domcontentloaded' });
          await page.waitForLoadState('load').catch(() => {});
          await page.waitForTimeout(150);

          assert.ok(res && res.status() < 400, `Unexpected HTTP ${res?.status()} on ${p} at ${viewport.width}x${viewport.height}`);
          assert.ok(!new URL(page.url()).pathname.endsWith('/login.php'), `Unexpected redirect to login.php on ${p}`);

          const m = await getOverflowMetrics(page);
          assert.ok(
            m.scrollWidth <= m.innerWidth,
            `Horizontal overflow on ${p} at ${viewport.width}x${viewport.height}: ${m.scrollWidth} > ${m.innerWidth}`
          );

          const bodyText = (await page.locator('body').innerText()).trim();
          assert.ok(bodyText.length > 0, `Empty rendered body on ${p} at ${viewport.width}x${viewport.height}`);

          errors.assertNoErrors(`${viewport.width}x${viewport.height} ${p}`);
          errors.clear();
        }
      }
    }
  });

  test({
    id: 'T2-F4-06',
    tier: 2,
    feature: 'F4',
    name: 'Unauthorized menu links are omitted from server-rendered nav HTML and mobile (375x812) drawer navigation works for external_user',
    fn: async (ctx) => {
      const { assert } = ctx;

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.MOBILE);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.ext01.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });

        const nav = await collectVisibleNavBasenames(page);
        for (const forbidden of [
          'book_vehicle.php',
          'assets_borrow.php',
          'approvals.php',
          'maintenance_manage.php',
          'qr_codes.php',
          'dashboard.php',
          'export_csv.php'
        ]) {
          assert.ok(!nav.all.includes(forbidden), `Unauthorized link "${forbidden}" present in ext01 nav HTML`);
        }

        const toggle = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"], .navbar-toggler[data-bs-target]').first();
        assert.equal(await toggle.isVisible(), true, 'Expected mobile offcanvas toggle for ext01');
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

        const roomLink = page.locator(offcanvasSel).first().locator('a[href*="book_room.php"]').first();
        assert.equal(await roomLink.isVisible(), true, 'Expected book_room.php inside ext01 mobile drawer');
        await Promise.all([
          page.waitForURL((u) => new URL(u).pathname.endsWith('/book_room.php'), { timeout: 10000 }),
          roomLink.click()
        ]);
        await page.waitForLoadState('load').catch(() => {});

        const m = await getOverflowMetrics(page);
        assert.ok(m.scrollWidth <= m.innerWidth, `Mobile overflow on /book_room.php for ext01: ${m.scrollWidth} > ${m.innerWidth}`);
        errors.assertNoErrors('T2-F4-06 ext01');
      }

      {
        const { page, errors } = await ctx.browser.openPage(ctx.browser.DESKTOP);
        await ctx.browser.loginUi(page, ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
        await page.goto(`${ctx.baseUrl}/index.php`, { waitUntil: 'load' });

        const nav = await collectVisibleNavBasenames(page);
        for (const forbidden of ['approvals.php', 'maintenance_manage.php', 'qr_codes.php', 'dashboard.php', 'export_csv.php']) {
          assert.ok(!nav.all.includes(forbidden), `Unauthorized link "${forbidden}" present in user01 nav HTML`);
        }
        errors.assertNoErrors('T2-F4-06 user01');
      }
    }
  });
}
