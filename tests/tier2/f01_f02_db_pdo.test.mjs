import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';

function buildPhpEnv(envCfg, overrides = {}) {
  return {
    ...process.env,
    DB_HOST: String(envCfg.dbHost),
    DB_PORT: String(envCfg.dbPort),
    DB_NAME: String(envCfg.dbName),
    DB_USER: String(envCfg.dbUser),
    DB_PASS: String(envCfg.dbPass ?? ''),
    ...overrides
  };
}

async function getCalendarEventDefaults(ctx) {
  const sample = await ctx.db.queryJson(
    'SELECT event_type, start_datetime, end_datetime, created_by FROM calendar_events ORDER BY id ASC LIMIT 1'
  );
  if (sample.length > 0) {
    return {
      eventType: sample[0].event_type || 'general',
      startDatetime: sample[0].start_datetime || '2026-12-01 09:00:00',
      endDatetime: sample[0].end_datetime || '2026-12-01 10:00:00',
      createdBy: sample[0].created_by || (await ctx.db.scalar('SELECT id FROM users ORDER BY id ASC LIMIT 1'))
    };
  }
  const firstUserId = await ctx.db.scalar('SELECT id FROM users ORDER BY id ASC LIMIT 1');
  return {
    eventType: 'general',
    startDatetime: '2026-12-01 09:00:00',
    endDatetime: '2026-12-01 10:00:00',
    createdBy: firstUserId
  };
}

function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const addr = srv.address();
      const port = addr && typeof addr === 'object' ? addr.port : 0;
      srv.close((err) => (err ? reject(err) : resolve(port)));
    });
  });
}

async function waitForHttpServer(url, proc, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (proc.exitCode !== null) {
      throw new Error(`Ephemeral PHP server exited prematurely with code ${proc.exitCode}`);
    }
    try {
      await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(1000) });
      return;
    } catch {
      await new Promise((r) => setTimeout(r, 100));
    }
  }
  throw new Error(`Timed out waiting for ephemeral PHP server at ${url}`);
}

function killProcessTree(proc) {
  if (!proc || proc.pid === undefined || proc.exitCode !== null) return;
  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/PID', String(proc.pid), '/T', '/F'], {
      stdio: 'ignore',
      shell: false,
      timeout: 5000
    });
  } else {
    try {
      proc.kill('SIGKILL');
    } catch {
      // ignore already exited
    }
  }
}

export function register(test) {
  // ---------------------------------------------------------------------------
  // F1: Portable MariaDB & SQL Setup (Tier 2 - Security, Constraints & Failures)
  // ---------------------------------------------------------------------------

  test({
    id: 'T2-F1-01',
    tier: 2,
    feature: 'F1',
    name: 'HTTP requests to /sql/reset_and_seed.php are blocked with 403 without resetting DB',
    fn: async (ctx) => {
      const { assert } = ctx;
      const marker = `__e2e_t2_f1_01_sentinel_${Date.now()}`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title = ?', [marker]).catch(() => {});
      });

      const defaults = await getCalendarEventDefaults(ctx);
      await ctx.db.execSql(
        'INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)',
        [marker, defaults.eventType, defaults.startDatetime, defaults.endDatetime, defaults.createdBy]
      );

      const client = ctx.http.createClient();
      const getRes = await client.get('/sql/reset_and_seed.php', { redirect: 'manual' });
      assert.equal(getRes.status, 403, `Expected GET /sql/reset_and_seed.php to return 403, got ${getRes.status}`);

      const postRes = await client.postForm('/sql/reset_and_seed.php', { confirm: '1' }, { redirect: 'manual' });
      assert.equal(postRes.status, 403, `Expected POST /sql/reset_and_seed.php to return 403, got ${postRes.status}`);

      const stillExists = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE title = ?', [marker]));
      assert.equal(stillExists, 1, 'Sentinel row was wiped; /sql/reset_and_seed.php must not execute over HTTP');
    }
  });

  test({
    id: 'T2-F1-02',
    tier: 2,
    feature: 'F1',
    name: 'UNIQUE constraints reject duplicate username, email, and room_code',
    fn: async (ctx) => {
      const { assert } = ctx;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM users WHERE username LIKE ? OR email LIKE ?', ['__e2e_t2_f1_02_%', '__e2e_t2_f1_02_%']).catch(() => {});
        await ctx.db.execSql('DELETE FROM rooms WHERE name LIKE ?', ['__e2e_t2_f1_02_%']).catch(() => {});
      });

      const existingUsers = await ctx.db.queryJson('SELECT * FROM users WHERE username = ? LIMIT 1', ['user01']);
      assert.equal(existingUsers.length, 1, 'Seed user01 not found');
      const pwCol = Object.prototype.hasOwnProperty.call(existingUsers[0], 'password_hash') ? 'password_hash' : 'password';
      const pwHash = existingUsers[0][pwCol];

      await assert.rejects(
        () =>
          ctx.db.execSql(
            `INSERT INTO users (username, email, ${pwCol}, full_name, user_type, role) VALUES (?, ?, ?, ?, ?, ?)`,
            ['user01', `__e2e_t2_f1_02_a_${Date.now()}@example.org`, pwHash, 'Dup Username', 'internal', 'user']
          ),
        /1062|Duplicate entry/i,
        'Expected UNIQUE constraint failure on duplicate username'
      );

      await assert.rejects(
        () =>
          ctx.db.execSql(
            `INSERT INTO users (username, email, ${pwCol}, full_name, user_type, role) VALUES (?, ?, ?, ?, ?, ?)`,
            [`__e2e_t2_f1_02_u_${Date.now()}`, existingUsers[0].email, pwHash, 'Dup Email', 'internal', 'user']
          ),
        /1062|Duplicate entry/i,
        'Expected UNIQUE constraint failure on duplicate email'
      );

      const roomRow = await ctx.db.queryJson('SELECT room_code, building, floor, capacity FROM rooms ORDER BY id ASC LIMIT 1');
      assert.equal(roomRow.length, 1, 'Expected at least one seeded room');
      await assert.rejects(
        () =>
          ctx.db.execSql(
            'INSERT INTO rooms (room_code, name, building, floor, capacity) VALUES (?, ?, ?, ?, ?)',
            [
              roomRow[0].room_code,
              `__e2e_t2_f1_02_room_${Date.now()}`,
              roomRow[0].building || 'Building A',
              roomRow[0].floor || '1',
              Number(roomRow[0].capacity || 10)
            ]
          ),
        /1062|Duplicate entry/i,
        'Expected UNIQUE constraint failure on duplicate room_code'
      );
    }
  });

  test({
    id: 'T2-F1-03',
    tier: 2,
    feature: 'F1',
    name: 'Foreign key constraints reject orphaned bookings, approval_logs, and asset_borrowings',
    fn: async (ctx) => {
      const { assert } = ctx;
      const missingId = 9999999;

      await assert.rejects(
        () =>
          ctx.db.execSql(
            `INSERT INTO bookings (reference_code, booking_type, requester_id, requester_type, title, start_datetime, end_datetime, current_step, status)
             VALUES (?, 'room', ?, 'internal', 'Orphan Booking', '2026-12-01 09:00:00', '2026-12-01 10:00:00', 1, 'pending_head')`,
            [`ORPHAN-${Date.now()}`, missingId]
          ),
        /1452|foreign key constraint/i,
        'Expected FK error for orphaned bookings.requester_id'
      );

      await assert.rejects(
        () =>
          ctx.db.execSql(
            `INSERT INTO approval_logs (booking_id, step_number, step_role, approver_id, action)
             VALUES (?, 1, 'head', ?, 'approved')`,
            [missingId, missingId]
          ),
        /1452|foreign key constraint/i,
        'Expected FK error for orphaned approval_logs'
      );

      await assert.rejects(
        () =>
          ctx.db.execSql(
            `INSERT INTO asset_borrowings (borrow_code, asset_id, borrower_id, quantity, status)
             VALUES (?, ?, ?, 1, 'borrowed')`,
            [`ORPHAN-BRW-${Date.now()}`, missingId, missingId]
          ),
        /1452|foreign key constraint/i,
        'Expected FK error for orphaned asset_borrowings'
      );
    }
  });

  test({
    id: 'T2-F1-04',
    tier: 2,
    feature: 'F1',
    name: 'INT UNSIGNED prevents negative asset available_stock at DB layer',
    fn: async (ctx) => {
      const { assert } = ctx;
      const assets = await ctx.db.queryJson('SELECT id, available_stock FROM assets ORDER BY id ASC LIMIT 1');
      assert.equal(assets.length, 1, 'Expected at least one seeded asset');
      const assetId = Number(assets[0].id);
      const origStock = Number(assets[0].available_stock);

      ctx.onCleanup(async () => {
        await ctx.db.execSql('UPDATE assets SET available_stock = ? WHERE id = ?', [origStock, assetId]).catch(() => {});
      });

      await assert.rejects(
        () => ctx.db.execSql('UPDATE assets SET available_stock = CAST(available_stock AS SIGNED) - 99999 WHERE id = ?', [assetId]),
        /1264|1690|out of range/i,
        'Expected INT UNSIGNED subtraction underflow to be rejected'
      );

      await assert.rejects(
        () => ctx.db.execSql('UPDATE assets SET available_stock = -1 WHERE id = ?', [assetId]),
        /1264|1690|out of range/i,
        'Expected negative integer update to available_stock to be rejected'
      );

      const after = Number(await ctx.db.scalar('SELECT available_stock FROM assets WHERE id = ?', [assetId]));
      assert.equal(after, origStock, 'Asset available_stock should remain unchanged after rejected underflow');
      assert.ok(after >= 0);
    }
  });

  test({
    id: 'T2-F1-05',
    tier: 2,
    feature: 'F1',
    name: 'Repeated consecutive execution of sql/reset_and_seed.php is idempotent',
    fn: async (ctx) => {
      const { assert } = ctx;
      assert.ok(fs.existsSync(ctx.env.resetScriptPath), `Missing reset script: ${ctx.env.resetScriptPath}`);

      const allTables = [
        'users',
        'rooms',
        'vehicles',
        'drivers',
        'bookings',
        'approval_logs',
        'maintenance_requests',
        'asset_borrowings',
        'calendar_events',
        'assets',
        'notification_logs'
      ];

      const res1 = await ctx.resetDb();
      assert.equal(res1.ok, true);
      const counts1 = {};
      for (const t of allTables) {
        counts1[t] = Number(await ctx.db.scalar(`SELECT COUNT(*) FROM ${t}`));
      }

      const res2 = await ctx.resetDb();
      assert.equal(res2.ok, true);
      for (const t of allTables) {
        const c2 = Number(await ctx.db.scalar(`SELECT COUNT(*) FROM ${t}`));
        assert.equal(c2, counts1[t], `Table "${t}" row count changed between consecutive resets (${counts1[t]} -> ${c2})`);
      }
    }
  });

  test({
    id: 'T2-F1-06',
    tier: 2,
    feature: 'F1',
    name: 'Invalid DB_NAME on config/db.php and invalid DB credentials/port on reset_and_seed.php fail cleanly',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);
      assert.ok(fs.existsSync(ctx.env.resetScriptPath), `Missing reset script: ${ctx.env.resetScriptPath}`);

      const badDbRes = spawnSync(
        'php',
        ['-r', 'try { $r = require $argv[1]; exit(0); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }', dbPhpPath],
        {
          encoding: 'utf8',
          shell: false,
          timeout: 5000,
          env: buildPhpEnv(ctx.env, { DB_NAME: 'slp_missing_db_99999' })
        }
      );
      assert.notEqual(badDbRes.status, 0, 'Expected config/db.php with missing DB_NAME to fail');

      const badUserReset = spawnSync('php', [ctx.env.resetScriptPath], {
        cwd: ctx.env.projectRoot,
        encoding: 'utf8',
        shell: false,
        timeout: 5000,
        env: buildPhpEnv(ctx.env, { DB_USER: 'invalid_reset_user_999', DB_PASS: 'wrong_reset_pass' })
      });
      assert.notEqual(badUserReset.status, 0, 'Expected reset_and_seed.php with invalid credentials to exit non-zero');

      const badPortReset = spawnSync('php', [ctx.env.resetScriptPath], {
        cwd: ctx.env.projectRoot,
        encoding: 'utf8',
        shell: false,
        timeout: 5000,
        env: buildPhpEnv(ctx.env, { DB_HOST: '127.0.0.1', DB_PORT: '39999' })
      });
      assert.notEqual(badPortReset.status, 0, 'Expected reset_and_seed.php with unreachable DB_PORT to exit non-zero');
    }
  });

  // ---------------------------------------------------------------------------
  // F2: PDO DB Config & Query Discipline (Tier 2 - SQLi & Error Leak Defense)
  // ---------------------------------------------------------------------------

  test({
    id: 'T2-F2-01',
    tier: 2,
    feature: 'F2',
    name: 'SQLi payloads on login.php do not bypass authentication, leak SQL errors, or drop users',
    fn: async (ctx) => {
      const { assert } = ctx;
      const baseClient = ctx.http.createClient();
      const baselineGet = await baseClient.get('/login.php');
      assert.equal(baselineGet.status, 200, `Expected GET /login.php to return 200, got ${baselineGet.status}`);
      assert.match(baselineGet.text, /<form\b/i, 'Expected login.php to render a <form>');
      assert.match(baselineGet.text, /type=["']password["']/i, 'Expected login.php form to include a password input');

      const baselineUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      const payloads = [
        { id: "' OR '1'='1", pw: "x' OR '1'='1" },
        { id: "admin'--", pw: 'anything' },
        { id: "'; DROP TABLE users;--", pw: ctx.env.seedPassword },
        { id: "user01' OR 1=1#", pw: 'wrong' }
      ];

      const leakRegex = /(SQLSTATE|PDOException|SQL syntax|Stack trace:|Fatal error)/i;
      for (const p of payloads) {
        const res = await ctx.http.attemptLogin(p.id, p.pw, { postOpts: { redirect: 'manual' } });
        assert.equal(res.authenticated, false, `SQLi payload ${p.id} must NOT authenticate`);
        assert.ok(res.postRes.status < 500, `SQLi payload ${p.id} caused HTTP ${res.postRes.status}`);
        assert.ok(!leakRegex.test(res.postRes.text || ''), `SQL/error leak detected for payload ${p.id}`);
      }

      const afterUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      assert.equal(afterUsers, baselineUsers, 'Users table row count must remain unchanged after SQLi login probes');
    }
  });

  test({
    id: 'T2-F2-02',
    tier: 2,
    feature: 'F2',
    name: 'SQLi payloads on register.php are safely parameterized and leave users intact',
    fn: async (ctx) => {
      const { assert } = ctx;
      const checkClient = ctx.http.createClient();
      const regPage = await checkClient.get('/register.php');
      assert.equal(regPage.status, 200, `Expected GET /register.php to return 200, got ${regPage.status}`);
      assert.match(regPage.text, /<form\b/i, 'Expected register.php to render a <form>');

      const uniqueEmail = `sqli_reg_${Date.now()}@example.org`;
      const badEmail = `sqli' OR '1'='1_${Date.now()}@example.org`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM users WHERE email IN (?, ?)', [uniqueEmail, badEmail]).catch(() => {});
      });

      const baselineUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      const sqliName = "Test'; DROP TABLE users;--";
      const sqliOrg = "' OR '1'='1";
      const leakRegex = /(SQLSTATE|PDOException|SQL syntax|Stack trace:|Fatal error)/i;

      const r1 = await ctx.http.registerExternal(
        {
          full_name: sqliName,
          organization: sqliOrg,
          phone: '0811112222',
          email: uniqueEmail,
          username: `sqli_u_${Date.now()}`,
          password: ctx.env.seedPassword
        },
        { expectSuccess: false, ensureLoggedIn: false }
      );
      assert.ok(r1.postRes.status < 500, `Register SQLi probe 1 caused HTTP ${r1.postRes.status}`);
      assert.ok(!leakRegex.test(r1.postRes.text || ''), 'SQL/error leak detected on register SQLi probe 1');

      const r2 = await ctx.http.registerExternal(
        {
          full_name: 'Bad Email SQLi',
          organization: 'Org',
          phone: '0811112223',
          email: badEmail,
          username: `sqli_u2_${Date.now()}`,
          password: ctx.env.seedPassword
        },
        { expectSuccess: false, ensureLoggedIn: false }
      );
      assert.ok(r2.postRes.status < 500, `Register SQLi probe 2 caused HTTP ${r2.postRes.status}`);
      assert.ok(!leakRegex.test(r2.postRes.text || ''), 'SQL/error leak detected on register SQLi probe 2');

      const afterUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      assert.ok(afterUsers >= baselineUsers, 'Users table must remain intact after SQLi register attempts');

      const created = await ctx.db.queryJson('SELECT full_name, organization FROM users WHERE email = ?', [uniqueEmail]);
      if (created.length > 0) {
        assert.equal(created[0].full_name, sqliName, 'Stored full_name must preserve literal parameterized value');
        assert.equal(created[0].organization, sqliOrg, 'Stored organization must preserve literal parameterized value');
      }
    }
  });

  test({
    id: 'T2-F2-03',
    tier: 2,
    feature: 'F2',
    name: 'SQLi payloads in authenticated query parameters do not cause 500 or SQL leaks',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const baselineUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      const leakRegex = /(SQLSTATE|PDOException|SQL syntax|Fatal error|Stack trace:)/i;

      const probes = [
        "/download.php?id=' OR 1=1--",
        "/download.php?file=../../etc/passwd' OR '1'='1",
        "/login.php?redirect=' OR '1'='1"
      ];
      for (const pathProbe of probes) {
        const res = await client.get(pathProbe, { redirect: 'manual' });
        assert.ok(res.status < 500, `Probe ${pathProbe} caused HTTP ${res.status}`);
        assert.ok(!leakRegex.test(res.text || ''), `SQL/stack trace leak on ${pathProbe}`);
      }

      const afterUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      assert.equal(afterUsers, baselineUsers);
    }
  });

  test({
    id: 'T2-F2-04',
    tier: 2,
    feature: 'F2',
    name: 'DB connection/query errors never leak raw DB credentials or stack traces over HTTP',
    fn: async (ctx) => {
      const { assert } = ctx;
      const baseClient = ctx.http.createClient();
      const okLogin = await baseClient.get('/login.php');
      assert.equal(okLogin.status, 200, `Baseline /login.php must return 200 before leak test, got ${okLogin.status}`);

      const freePort = await getFreePort();
      const ephemBase = `http://127.0.0.1:${freePort}`;
      const badSecrets = {
        DB_HOST: '127.0.0.1',
        DB_PORT: String(ctx.env.dbPort),
        DB_NAME: 'bad_e2e_dbname_secret',
        DB_USER: 'bad_e2e_user_secret',
        DB_PASS: 'bad_e2e_pass_secret'
      };

      const proc = spawn('php', ['-S', `127.0.0.1:${freePort}`, '-t', ctx.env.projectRoot], {
        cwd: ctx.env.projectRoot,
        env: { ...process.env, ...badSecrets },
        stdio: 'ignore',
        shell: false
      });
      ctx.onCleanup(() => killProcessTree(proc));

      await waitForHttpServer(`${ephemBase}/login.php`, proc, 8000);

      const ephemClient = new ctx.http.HttpClient(ephemBase);
      const getRes = await ephemClient.get('/login.php', { redirect: 'manual' });
      const postRes = await ephemClient.postForm(
        '/login.php',
        { identifier: 'user01', password: ctx.env.seedPassword },
        { redirect: 'manual' }
      );

      const forbiddenTokens = [
        'bad_e2e_pass_secret',
        'bad_e2e_user_secret',
        'bad_e2e_dbname_secret',
        'PDO->__construct',
        'SQLSTATE',
        'Stack trace:'
      ];
      for (const res of [getRes, postRes]) {
        const body = res.text || '';
        for (const token of forbiddenTokens) {
          assert.ok(!body.includes(token), `Sensitive DB/stack trace token "${token}" leaked in HTTP response`);
        }
      }
    }
  });

  test({
    id: 'T2-F2-05',
    tier: 2,
    feature: 'F2',
    name: 'Invalid, negative, non-numeric, and array-typed query/form params are rejected cleanly without 500',
    fn: async (ctx) => {
      const { assert } = ctx;
      const client = await ctx.http.login(ctx.env.seedUsers.user01.username, ctx.env.seedPassword);
      const errorRegex = /(Fatal error|Uncaught TypeError|Array to string conversion|PDOException|Stack trace:)/i;

      const getProbes = [
        '/download.php',
        '/download.php?id=abc',
        '/download.php?id=-1',
        '/download.php?id[]=1&id[]=2',
        '/login.php?redirect[]=evil'
      ];
      for (const probe of getProbes) {
        const res = await client.get(probe, { redirect: 'manual' });
        assert.ok(res.status < 500, `GET ${probe} returned HTTP ${res.status}`);
        assert.ok(!errorRegex.test(res.text || ''), `PHP error leaked on GET ${probe}`);
      }

      const anon = ctx.http.createClient();
      const postRes = await anon.postForm(
        '/login.php',
        { 'identifier[]': 'admin', 'password[]': 'x' },
        { redirect: 'manual' }
      );
      assert.ok(postRes.status < 500, `Array-typed POST /login.php returned HTTP ${postRes.status}`);
      assert.ok(!errorRegex.test(postRes.text || ''), 'PHP error leaked on array-typed POST /login.php');
    }
  });

  test({
    id: 'T2-F2-06',
    tier: 2,
    feature: 'F2',
    name: 'Isolated config/db.php throws PDOException on invalid SQL and blocks multi-statement injection',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const baselineUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      const phpCode = `
        $ret = require $argv[1];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { fwrite(STDERR, 'No PDO'); exit(2); }

        $threwMissingTable = false;
        try {
          $pdo->query('SELECT * FROM nonexistent_table_e2e_999');
        } catch (PDOException $e) {
          $threwMissingTable = true;
        }

        $threwMissingCol = false;
        try {
          $st = $pdo->prepare('SELECT * FROM users WHERE nonexistent_col_e2e = ?');
          $st->execute(['x']);
        } catch (PDOException $e) {
          $threwMissingCol = true;
        }

        $sqliStmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $sqliStmt->execute(["user01'; DROP TABLE users;--"]);
        $sqliRows = $sqliStmt->fetchAll();

        echo json_encode([
          'threwMissingTable' => $threwMissingTable,
          'threwMissingCol' => $threwMissingCol,
          'sqliRowCount' => count($sqliRows)
        ]);
      `;
      const res = spawnSync('php', ['-r', phpCode, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 10000,
        env: buildPhpEnv(ctx.env)
      });
      assert.equal(res.status, 0, `Isolated PDO exception check failed: ${res.stderr} ${res.stdout}`);

      const out = JSON.parse(res.stdout.trim());
      assert.equal(out.threwMissingTable, true, 'Expected PDOException on nonexistent table');
      assert.equal(out.threwMissingCol, true, 'Expected PDOException on nonexistent column');
      assert.equal(out.sqliRowCount, 0, 'Prepared statement must treat SQLi string as literal username and return 0 rows');

      const afterUsers = Number(await ctx.db.scalar('SELECT COUNT(*) FROM users'));
      assert.equal(afterUsers, baselineUsers, 'Users table must remain intact');
    }
  });
}
