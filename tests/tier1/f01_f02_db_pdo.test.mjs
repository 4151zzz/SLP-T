import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

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

function runPhpInline(phpCode, { env, input, timeout = 10000 } = {}) {
  const res = spawnSync('php', ['-r', phpCode], {
    input,
    encoding: 'utf8',
    shell: false,
    timeout,
    env: env || process.env
  });
  if (res.error) {
    throw new Error(`PHP CLI execution failed: ${res.error.message}`);
  }
  return res;
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

export function register(test) {
  // ---------------------------------------------------------------------------
  // F1: Portable MariaDB & SQL Setup (Tier 1)
  // ---------------------------------------------------------------------------

  test({
    id: 'T1-F1-01',
    tier: 1,
    feature: 'F1',
    name: 'All 9 required tables plus assets and notification_logs exist with expected key columns',
    fn: async (ctx) => {
      const { assert } = ctx;
      const reachable = await ctx.db.isDbReachable({ checkDb: true });
      assert.equal(reachable, true, `Expected database "${ctx.env.dbName}" to be reachable`);

      const tables = await ctx.db.queryJson(
        'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'
      );
      const tableSet = new Set(tables.map((r) => String(r.TABLE_NAME || r.table_name).toLowerCase()));

      const expectedTables = [
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
      for (const tbl of expectedTables) {
        assert.ok(tableSet.has(tbl), `Missing required table "${tbl}" in database "${ctx.env.dbName}"`);
      }

      const columns = await ctx.db.queryJson(
        `SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('users', 'bookings', 'assets', 'approval_logs', 'maintenance_requests', 'asset_borrowings')`
      );
      const colMap = new Map();
      for (const c of columns) {
        const tbl = String(c.TABLE_NAME || c.table_name).toLowerCase();
        const col = String(c.COLUMN_NAME || c.column_name).toLowerCase();
        const ctype = String(c.COLUMN_TYPE || c.column_type).toLowerCase();
        colMap.set(`${tbl}.${col}`, ctype);
      }

      const requiredCols = [
        'users.user_type',
        'users.role',
        'users.staff_id',
        'bookings.booking_type',
        'bookings.current_step',
        'bookings.status',
        'approval_logs.booking_id',
        'approval_logs.approver_id',
        'maintenance_requests.status',
        'asset_borrowings.status',
        'assets.total_stock',
        'assets.available_stock'
      ];
      for (const key of requiredCols) {
        assert.ok(colMap.has(key), `Missing required column "${key}"`);
      }

      assert.ok(
        colMap.get('assets.total_stock').includes('unsigned'),
        `Expected assets.total_stock to be UNSIGNED, got "${colMap.get('assets.total_stock')}"`
      );
      assert.ok(
        colMap.get('assets.available_stock').includes('unsigned'),
        `Expected assets.available_stock to be UNSIGNED, got "${colMap.get('assets.available_stock')}"`
      );
    }
  });

  test({
    id: 'T1-F1-02',
    tier: 1,
    feature: 'F1',
    name: '8 seeded users cover all 7 roles with expected identifiers and verifiable password hashes',
    fn: async (ctx) => {
      const { assert } = ctx;
      const rows = await ctx.db.queryJson('SELECT * FROM users');
      assert.ok(rows.length >= 8, `Expected at least 8 seeded users, got ${rows.length}`);

      const byUsername = new Map(rows.map((r) => [String(r.username), r]));
      const roleSet = new Set(rows.map((r) => String(r.role)));

      const expectedRoles = ['user', 'external_user', 'head', 'vice_director', 'director', 'staff', 'admin'];
      for (const role of expectedRoles) {
        assert.ok(roleSet.has(role), `Missing seeded user with role "${role}"`);
      }

      for (const expected of Object.values(ctx.env.seedUsers)) {
        const actual = byUsername.get(expected.username);
        assert.ok(actual, `Missing seeded user "${expected.username}"`);
        assert.equal(actual.user_type, expected.userType, `User "${expected.username}" user_type mismatch`);
        assert.equal(actual.role, expected.role, `User "${expected.username}" role mismatch`);
        assert.equal(actual.staff_id ?? null, expected.staffId, `User "${expected.username}" staff_id mismatch`);
        assert.equal(actual.email, expected.email, `User "${expected.username}" email mismatch`);
      }

      const hashesToVerify = ['admin', 'user01', 'ext01'].map((uname) => {
        const u = byUsername.get(uname);
        const hash = u.password_hash ?? u.password ?? '';
        assert.ok(typeof hash === 'string' && hash.length > 20, `User "${uname}" missing password hash`);
        return { username: uname, hash };
      });

      const phpCode = `
        $in = json_decode(stream_get_contents(STDIN), true);
        $pass = $in['password'] ?? '';
        $failed = [];
        foreach (($in['items'] ?? []) as $item) {
          if (!password_verify($pass, $item['hash'])) {
            $failed[] = $item['username'];
          }
        }
        echo json_encode(['failed' => $failed]);
        exit(count($failed) === 0 ? 0 : 1);
      `;
      const res = runPhpInline(phpCode, {
        env: buildPhpEnv(ctx.env),
        input: JSON.stringify({ password: ctx.env.seedPassword, items: hashesToVerify })
      });
      assert.equal(res.status, 0, `password_verify failed for seed users: ${res.stdout} ${res.stderr}`);
    }
  });

  test({
    id: 'T1-F1-03',
    tier: 1,
    feature: 'F1',
    name: 'Seeded rooms, vehicles, drivers, and assets satisfy minimum counts and stock invariants',
    fn: async (ctx) => {
      const { assert } = ctx;
      const rooms = await ctx.db.queryJson('SELECT id, room_code, allow_external FROM rooms');
      assert.ok(rooms.length >= 4, `Expected >= 4 seeded rooms, got ${rooms.length}`);
      const extRooms = rooms.filter((r) => Number(r.allow_external) === 1);
      const internalOnlyRooms = rooms.filter((r) => Number(r.allow_external) === 0);
      assert.ok(extRooms.length >= 2, `Expected >= 2 rooms with allow_external=1, got ${extRooms.length}`);
      assert.ok(internalOnlyRooms.length >= 2, `Expected >= 2 rooms with allow_external=0, got ${internalOnlyRooms.length}`);

      const vehicles = await ctx.db.queryJson('SELECT id FROM vehicles');
      assert.ok(vehicles.length >= 3, `Expected >= 3 seeded vehicles, got ${vehicles.length}`);

      const drivers = await ctx.db.queryJson('SELECT id FROM drivers');
      assert.ok(drivers.length >= 3, `Expected >= 3 seeded drivers, got ${drivers.length}`);

      const assets = await ctx.db.queryJson('SELECT id, total_stock, available_stock FROM assets');
      assert.ok(assets.length >= 4, `Expected >= 4 seeded assets, got ${assets.length}`);
      let withStock2 = 0;
      for (const a of assets) {
        const total = Number(a.total_stock);
        const avail = Number(a.available_stock);
        assert.ok(total >= 0 && avail >= 0, `Asset #${a.id} has negative stock`);
        assert.ok(avail <= total, `Asset #${a.id} available_stock (${avail}) > total_stock (${total})`);
        if (avail >= 2) withStock2++;
      }
      assert.ok(withStock2 >= 4, `Expected >= 4 seeded assets with available_stock >= 2, got ${withStock2}`);
    }
  });

  test({
    id: 'T1-F1-04',
    tier: 1,
    feature: 'F1',
    name: 'Seeded bookings span workflow statuses with approval_logs, calendar_events, maintenance, and borrowings',
    fn: async (ctx) => {
      const { assert } = ctx;
      const bookings = await ctx.db.queryJson('SELECT id, booking_type, status FROM bookings');
      const statuses = new Set(bookings.map((b) => String(b.status)));

      for (const st of ['approved', 'pending_head', 'pending_vice', 'pending_director', 'rejected']) {
        assert.ok(statuses.has(st), `Expected at least one seeded booking with status "${st}"`);
      }

      const approvedIds = bookings.filter((b) => b.status === 'approved').map((b) => Number(b.id));
      const logs = await ctx.db.queryJson('SELECT booking_id, step_role FROM approval_logs');
      const hasFullChain = approvedIds.some((bid) => {
        const roles = new Set(logs.filter((l) => Number(l.booking_id) === bid).map((l) => String(l.step_role)));
        return roles.has('head') && roles.has('vice_director') && roles.has('director');
      });
      assert.ok(hasFullChain, 'Expected at least one approved booking with head, vice_director, and director approval_logs');

      const eventCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events'));
      assert.ok(eventCount >= 3, `Expected >= 3 calendar_events, got ${eventCount}`);

      const maintCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM maintenance_requests'));
      assert.ok(maintCount >= 1, `Expected >= 1 maintenance_requests, got ${maintCount}`);

      const borrowCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM asset_borrowings'));
      assert.ok(borrowCount >= 1, `Expected >= 1 asset_borrowings, got ${borrowCount}`);
    }
  });

  test({
    id: 'T1-F1-05',
    tier: 1,
    feature: 'F1',
    name: 'CLI sql/reset_and_seed.php performs deterministic database reset',
    fn: async (ctx) => {
      const { assert } = ctx;
      assert.ok(fs.existsSync(ctx.env.resetScriptPath), `Missing reset script: ${ctx.env.resetScriptPath}`);

      const tables = ['users', 'rooms', 'bookings', 'calendar_events', 'assets'];
      const baseline = {};
      for (const t of tables) {
        baseline[t] = Number(await ctx.db.scalar(`SELECT COUNT(*) FROM ${t}`));
      }

      const marker = '__e2e_t1_f1_05_marker__';
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title = ?', [marker]).catch(() => {});
      });

      const defaults = await getCalendarEventDefaults(ctx);
      await ctx.db.execSql(
        'INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)',
        [marker, defaults.eventType, defaults.startDatetime, defaults.endDatetime, defaults.createdBy]
      );
      const insertedCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE title = ?', [marker]));
      assert.equal(insertedCount, 1, 'Expected marker row to be inserted before reset');

      const resetRes = await ctx.resetDb();
      assert.equal(resetRes.ok, true, 'Expected resetAndSeed() to succeed');

      const afterMarkerCount = Number(await ctx.db.scalar('SELECT COUNT(*) FROM calendar_events WHERE title = ?', [marker]));
      assert.equal(afterMarkerCount, 0, 'Expected reset_and_seed.php to clear temporary marker row');

      for (const t of tables) {
        const afterCount = Number(await ctx.db.scalar(`SELECT COUNT(*) FROM ${t}`));
        assert.equal(afterCount, baseline[t], `Expected deterministic count for "${t}" after reset`);
      }
    }
  });

  test({
    id: 'T1-F1-06',
    tier: 1,
    feature: 'F1',
    name: 'Database schema, Thai UTF-8 text, and +07:00 timezone are properly configured',
    fn: async (ctx) => {
      const { assert } = ctx;
      const schemaRow = await ctx.db.queryJson(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
      );
      assert.equal(schemaRow.length, 1, 'Expected schema metadata row');
      const charset = String(schemaRow[0].DEFAULT_CHARACTER_SET_NAME || schemaRow[0].default_character_set_name).toLowerCase();
      assert.equal(charset, 'utf8mb4', `Expected schema default charset utf8mb4, got "${charset}"`);

      const tblRows = await ctx.db.queryJson(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'
      );
      assert.ok(tblRows.length >= 11, `Expected >= 11 tables in schema, got ${tblRows.length}`);
      for (const tr of tblRows) {
        const name = String(tr.TABLE_NAME || tr.table_name);
        const engine = String(tr.ENGINE || tr.engine);
        const coll = String(tr.TABLE_COLLATION || tr.table_collation).toLowerCase();
        assert.equal(engine.toUpperCase(), 'INNODB', `Table "${name}" must use InnoDB engine, got "${engine}"`);
        assert.ok(coll.startsWith('utf8mb4_'), `Table "${name}" must use utf8mb4_* collation, got "${coll}"`);
      }

      const tz = await ctx.db.scalar('SELECT @@session.time_zone');
      assert.equal(tz, '+07:00', `Expected session time_zone +07:00, got "${tz}"`);

      const roomRows = await ctx.db.queryJson('SELECT name FROM rooms');
      const hasThai = roomRows.some((r) => /[\u0E00-\u0E7F]/.test(String(r.name || '')));
      assert.ok(hasThai, 'Expected seeded rooms to contain valid Thai Unicode characters');
      for (const r of roomRows) {
        assert.ok(!String(r.name || '').includes('???'), `Corrupted Thai characters detected in room name: ${r.name}`);
      }

      const thaiTitle = `__e2e_t1_f1_06_ห้องประชุมใหญ่_🏢_${Date.now()}`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title = ?', [thaiTitle]).catch(() => {});
      });
      const defaults = await getCalendarEventDefaults(ctx);
      const ins = await ctx.db.execSql(
        'INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)',
        [thaiTitle, defaults.eventType, defaults.startDatetime, defaults.endDatetime, defaults.createdBy]
      );
      const fetched = await ctx.db.scalar('SELECT title FROM calendar_events WHERE id = ?', [Number(ins.lastInsertId)]);
      assert.equal(fetched, thaiTitle, 'Thai + emoji string did not round-trip intact in MariaDB');
    }
  });

  // ---------------------------------------------------------------------------
  // F2: PDO DB Config & Query Discipline (Tier 1)
  // ---------------------------------------------------------------------------

  test({
    id: 'T1-F2-01',
    tier: 1,
    feature: 'F2',
    name: 'config/db.php returns PDO with ERRMODE_EXCEPTION, FETCH_ASSOC, and EMULATE_PREPARES=false',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const phpCode = `
        $dbPhp = $argv[1];
        $ret = require $dbPhp;
        if (!($ret instanceof PDO)) {
          fwrite(STDERR, 'config/db.php did not return a PDO instance');
          exit(2);
        }
        $stmt = $ret->prepare('SELECT 1 AS num');
        $stmt->execute();
        $row = $stmt->fetch();
        echo json_encode([
          'errmode' => $ret->getAttribute(PDO::ATTR_ERRMODE),
          'expected_errmode' => PDO::ERRMODE_EXCEPTION,
          'fetch_mode' => $ret->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
          'expected_fetch_mode' => PDO::FETCH_ASSOC,
          'emulate' => $ret->getAttribute(PDO::ATTR_EMULATE_PREPARES),
          'num_is_int' => is_int($row['num'] ?? null),
          'num_val' => $row['num'] ?? null
        ]);
      `;
      const res = spawnSync('php', ['-r', phpCode, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 10000,
        env: buildPhpEnv(ctx.env)
      });
      assert.equal(res.status, 0, `PDO inspection failed (status=${res.status}): ${res.stderr} ${res.stdout}`);

      const info = JSON.parse(res.stdout.trim());
      assert.equal(info.errmode, info.expected_errmode, 'Expected PDO::ATTR_ERRMODE === PDO::ERRMODE_EXCEPTION');
      assert.equal(info.fetch_mode, info.expected_fetch_mode, 'Expected PDO::ATTR_DEFAULT_FETCH_MODE === PDO::FETCH_ASSOC');
      assert.ok(
        info.emulate === false || info.emulate === 0,
        `Expected PDO::ATTR_EMULATE_PREPARES to be false/0, got ${JSON.stringify(info.emulate)}`
      );
      assert.equal(info.num_is_int, true, 'Expected native prepared statement to preserve integer type (is_int)');
      assert.equal(info.num_val, 1);
    }
  });

  test({
    id: 'T1-F2-02',
    tier: 1,
    feature: 'F2',
    name: 'config/db.php respects DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS env overrides',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const phpValid = `
        $ret = require $argv[1];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { exit(2); }
        echo $pdo->query('SELECT DATABASE()')->fetchColumn();
      `;
      const okRes = spawnSync('php', ['-r', phpValid, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 10000,
        env: buildPhpEnv(ctx.env)
      });
      assert.equal(okRes.status, 0, `Valid env check failed: ${okRes.stderr}`);
      assert.equal(okRes.stdout.trim(), ctx.env.dbName, 'config/db.php did not connect to configured DB_NAME');

      const phpTryRequire = `
        try {
          $ret = require $argv[1];
          $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
          echo 'CONNECTED';
          exit(0);
        } catch (Throwable $e) {
          echo get_class($e) . ': ' . $e->getMessage();
          exit(1);
        }
      `;

      const badDbRes = spawnSync('php', ['-r', phpTryRequire, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 5000,
        env: buildPhpEnv(ctx.env, { DB_NAME: 'slp_nonexistent_db_override_test' })
      });
      assert.notEqual(badDbRes.status, 0, 'Expected invalid DB_NAME env override to fail');

      const badPortRes = spawnSync('php', ['-r', phpTryRequire, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 5000,
        env: buildPhpEnv(ctx.env, { DB_HOST: '127.0.0.1', DB_PORT: '39999' })
      });
      assert.notEqual(badPortRes.status, 0, 'Expected invalid DB_PORT env override to fail');

      const badUserRes = spawnSync('php', ['-r', phpTryRequire, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 5000,
        env: buildPhpEnv(ctx.env, { DB_USER: 'invalid_e2e_user_xyz', DB_PASS: 'wrong_pass' })
      });
      assert.notEqual(badUserRes.status, 0, 'Expected invalid DB_USER/DB_PASS env override to fail');
    }
  });

  test({
    id: 'T1-F2-03',
    tier: 1,
    feature: 'F2',
    name: 'config/db.php performs lossless Thai UTF-8 and 4-byte emoji round-trip',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const marker = `__e2e_t1_f2_03_ระบบบริหารทั่วไป_ห้องประชุม_🏢_${Date.now()}`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title LIKE ?', ['__e2e_t1_f2_03_%']).catch(() => {});
      });

      const defaults = await getCalendarEventDefaults(ctx);
      const phpCode = `
        $in = json_decode(stream_get_contents(STDIN), true);
        $ret = require $in['dbPhp'];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { fwrite(STDERR, 'No PDO'); exit(2); }
        $stmt = $pdo->prepare('INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$in['title'], $in['eventType'], $in['startDatetime'], $in['endDatetime'], (int)$in['createdBy']]);
        $id = (int)$pdo->lastInsertId();
        $sel = $pdo->prepare('SELECT title FROM calendar_events WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch();
        $del = $pdo->prepare('DELETE FROM calendar_events WHERE id = ?');
        $del->execute([$id]);
        echo json_encode(['title' => $row['title'] ?? null], JSON_UNESCAPED_UNICODE);
      `;
      const res = runPhpInline(phpCode, {
        env: buildPhpEnv(ctx.env),
        input: JSON.stringify({ dbPhp: dbPhpPath, title: marker, ...defaults })
      });
      assert.equal(res.status, 0, `UTF-8 round-trip failed: ${res.stderr} ${res.stdout}`);
      const parsed = JSON.parse(res.stdout.trim());
      assert.equal(parsed.title, marker, 'Thai UTF-8 + 4-byte emoji string did not round-trip cleanly via config/db.php');
    }
  });

  test({
    id: 'T1-F2-04',
    tier: 1,
    feature: 'F2',
    name: 'Prepared statements via config/db.php execute parameterized CRUD cleanly',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const initialTitle = `__e2e_t1_f2_04_init_${Date.now()}`;
      const updatedTitle = `__e2e_t1_f2_04_upd_${Date.now()}`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title LIKE ?', ['__e2e_t1_f2_04_%']).catch(() => {});
      });

      const defaults = await getCalendarEventDefaults(ctx);
      const phpCode = `
        $in = json_decode(stream_get_contents(STDIN), true);
        $ret = require $in['dbPhp'];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { fwrite(STDERR, 'No PDO'); exit(2); }

        $uStmt = $pdo->prepare('SELECT id, username FROM users WHERE username = :username LIMIT 1');
        $uStmt->execute([':username' => 'user01']);
        $userRow = $uStmt->fetch();

        $ins = $pdo->prepare('INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$in['initialTitle'], $in['eventType'], $in['startDatetime'], $in['endDatetime'], (int)$in['createdBy']]);
        $insCount = $ins->rowCount();
        $id = (int)$pdo->lastInsertId();

        $upd = $pdo->prepare('UPDATE calendar_events SET title = :title WHERE id = :id');
        $upd->execute([':title' => $in['updatedTitle'], ':id' => $id]);
        $updCount = $upd->rowCount();

        $sel = $pdo->prepare('SELECT title FROM calendar_events WHERE id = :id');
        $sel->execute([':id' => $id]);
        $afterUpd = $sel->fetch();

        $del = $pdo->prepare('DELETE FROM calendar_events WHERE id = ?');
        $del->execute([$id]);
        $delCount = $del->rowCount();

        echo json_encode([
          'username' => $userRow['username'] ?? null,
          'insCount' => $insCount,
          'updCount' => $updCount,
          'afterUpdTitle' => $afterUpd['title'] ?? null,
          'delCount' => $delCount
        ]);
      `;
      const res = runPhpInline(phpCode, {
        env: buildPhpEnv(ctx.env),
        input: JSON.stringify({ dbPhp: dbPhpPath, initialTitle, updatedTitle, ...defaults })
      });
      assert.equal(res.status, 0, `Parameterized CRUD via config/db.php failed: ${res.stderr} ${res.stdout}`);
      const out = JSON.parse(res.stdout.trim());
      assert.equal(out.username, 'user01');
      assert.equal(out.insCount, 1);
      assert.equal(out.updCount, 1);
      assert.equal(out.afterUpdTitle, updatedTitle);
      assert.equal(out.delCount, 1);
    }
  });

  test({
    id: 'T1-F2-05',
    tier: 1,
    feature: 'F2',
    name: 'config/db.php and config/app.php align timezone to +07:00 / Asia/Bangkok',
    fn: async (ctx) => {
      const { assert } = ctx;
      const appPhpPath = path.join(ctx.env.projectRoot, 'config', 'app.php');
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(appPhpPath), `Missing config/app.php at ${appPhpPath}`);
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const phpCode = `
        require_once $argv[1];
        $ret = require $argv[2];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { fwrite(STDERR, 'No PDO'); exit(2); }
        $row = $pdo->query('SELECT @@session.time_zone AS tz, TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), "%H:%i:%s") AS offset_diff')->fetch();
        echo json_encode([
          'php_tz' => date_default_timezone_get(),
          'db_tz' => $row['tz'] ?? null,
          'offset_diff' => $row['offset_diff'] ?? null
        ]);
      `;
      const res = spawnSync('php', ['-r', phpCode, appPhpPath, dbPhpPath], {
        encoding: 'utf8',
        shell: false,
        timeout: 10000,
        env: buildPhpEnv(ctx.env)
      });
      assert.equal(res.status, 0, `Timezone alignment check failed: ${res.stderr} ${res.stdout}`);
      const out = JSON.parse(res.stdout.trim());
      assert.equal(out.php_tz, 'Asia/Bangkok', `Expected PHP timezone Asia/Bangkok, got "${out.php_tz}"`);
      assert.equal(out.db_tz, '+07:00', `Expected DB session timezone +07:00, got "${out.db_tz}"`);
      assert.equal(out.offset_diff, '07:00:00', `Expected NOW()-UTC_TIMESTAMP() === 07:00:00, got "${out.offset_diff}"`);
    }
  });

  test({
    id: 'T1-F2-06',
    tier: 1,
    feature: 'F2',
    name: 'config/db.php supports InnoDB transactions and clean rollback',
    fn: async (ctx) => {
      const { assert } = ctx;
      const dbPhpPath = path.join(ctx.env.projectRoot, 'config', 'db.php');
      assert.ok(fs.existsSync(dbPhpPath), `Missing config/db.php at ${dbPhpPath}`);

      const marker = `__e2e_t1_f2_06_rollback_${Date.now()}`;
      ctx.onCleanup(async () => {
        await ctx.db.execSql('DELETE FROM calendar_events WHERE title = ?', [marker]).catch(() => {});
      });

      const defaults = await getCalendarEventDefaults(ctx);
      const phpCode = `
        $in = json_decode(stream_get_contents(STDIN), true);
        $ret = require $in['dbPhp'];
        $pdo = $ret instanceof PDO ? $ret : ($pdo ?? null);
        if (!($pdo instanceof PDO)) { fwrite(STDERR, 'No PDO'); exit(2); }
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO calendar_events (title, event_type, start_datetime, end_datetime, created_by) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$in['title'], $in['eventType'], $in['startDatetime'], $in['endDatetime'], (int)$in['createdBy']]);
        $sel = $pdo->prepare('SELECT COUNT(*) FROM calendar_events WHERE title = ?');
        $sel->execute([$in['title']]);
        $inTxnCount = (int)$sel->fetchColumn();
        $pdo->rollBack();
        $sel->execute([$in['title']]);
        $afterRollbackCount = (int)$sel->fetchColumn();
        echo json_encode(['inTxnCount' => $inTxnCount, 'afterRollbackCount' => $afterRollbackCount]);
      `;
      const res = runPhpInline(phpCode, {
        env: buildPhpEnv(ctx.env),
        input: JSON.stringify({ dbPhp: dbPhpPath, title: marker, ...defaults })
      });
      assert.equal(res.status, 0, `Transaction rollback check failed: ${res.stderr} ${res.stdout}`);
      const out = JSON.parse(res.stdout.trim());
      assert.equal(out.inTxnCount, 1, 'Expected row to be visible inside uncommitted transaction');
      assert.equal(out.afterRollbackCount, 0, 'Expected row to disappear after PDO::rollBack()');
    }
  });
}
