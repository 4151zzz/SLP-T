import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import { CONFIG } from './env.mjs';

function getDbSettings() {
  return {
    host: process.env.DB_HOST || CONFIG.dbHost,
    port: String(process.env.DB_PORT || CONFIG.dbPort),
    name: process.env.DB_NAME || CONFIG.dbName,
    user: process.env.DB_USER || CONFIG.dbUser,
    pass: process.env.DB_PASS !== undefined ? process.env.DB_PASS : CONFIG.dbPass
  };
}

function buildDbEnv() {
  const db = getDbSettings();
  return {
    ...process.env,
    DB_HOST: db.host,
    DB_PORT: db.port,
    DB_NAME: db.name,
    DB_USER: db.user,
    DB_PASS: db.pass
  };
}

function runPhpBridge(phpCode, payload, opts = {}) {
  const timeoutMs = opts.timeoutMs ?? 30000;
  const res = spawnSync('php', ['-r', phpCode], {
    input: JSON.stringify(payload),
    encoding: 'utf8',
    shell: false,
    timeout: timeoutMs,
    env: buildDbEnv()
  });

  if (res.error) {
    if (res.error.code === 'ETIMEDOUT') {
      throw new Error(`PHP CLI bridge timed out after ${timeoutMs}ms`);
    }
    throw new Error(`Failed to execute php CLI: ${res.error.message}`);
  }

  return res;
}

export async function isDbReachable(opts = {}) {
  const { checkDb = false, timeoutSec = 2 } = opts;
  const timeoutMs = opts.timeoutMs ?? Math.max(5000, timeoutSec * 1000 + 3000);
  const db = getDbSettings();
  const phpCode = `
    $in = json_decode(stream_get_contents(STDIN), true);
    try {
      $dsn = sprintf('mysql:host=%s;port=%s%s;charset=utf8mb4',
        $in['host'],
        $in['port'],
        !empty($in['checkDb']) ? ';dbname=' . $in['name'] : ''
      );
      new PDO($dsn, $in['user'], $in['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => (int)($in['timeoutSec'] ?? 2)
      ]);
      echo 'OK';
      exit(0);
    } catch (Throwable $e) {
      fwrite(STDERR, $e->getMessage());
      exit(1);
    }
  `;

  try {
    const res = runPhpBridge(phpCode, { ...db, checkDb, timeoutSec }, { timeoutMs });
    return res.status === 0 && res.stdout.trim() === 'OK';
  } catch {
    return false;
  }
}

export async function resetAndSeed(opts = {}) {
  const scriptPath = CONFIG.resetScriptPath;
  if (!fs.existsSync(scriptPath)) {
    throw new Error(`Reset script not found: ${scriptPath}`);
  }

  const timeoutMs = opts.timeoutMs ?? 30000;
  const res = spawnSync('php', [scriptPath], {
    cwd: CONFIG.projectRoot,
    encoding: 'utf8',
    shell: false,
    timeout: timeoutMs,
    env: buildDbEnv()
  });

  if (res.error) {
    if (res.error.code === 'ETIMEDOUT') {
      throw new Error(`reset_and_seed.php timed out after ${timeoutMs}ms`);
    }
    throw new Error(`Failed to run reset_and_seed.php: ${res.error.message}`);
  }

  if (res.status !== 0) {
    throw new Error(
      `reset_and_seed.php exited with code ${res.status}\nstdout: ${res.stdout || ''}\nstderr: ${res.stderr || ''}`
    );
  }

  return {
    ok: true,
    stdout: res.stdout || '',
    stderr: res.stderr || ''
  };
}

function executePdo(mode, sql, params = [], opts = {}) {
  const db = getDbSettings();
  const phpCode = `
    $in = json_decode(stream_get_contents(STDIN), true);
    try {
      $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $in['host'], $in['port'], $in['name']);
      $pdo = new PDO($dsn, $in['user'], $in['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5
      ]);
      $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'");
      $stmt = $pdo->prepare($in['sql']);
      $params = $in['params'] ?? [];
      if (is_array($params)) {
        $isList = array_is_list($params);
        foreach ($params as $k => $v) {
          $paramKey = $isList ? ((int)$k + 1) : (str_starts_with((string)$k, ':') ? (string)$k : ':' . (string)$k);
          $type = is_int($v)
            ? PDO::PARAM_INT
            : (is_bool($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
          $val = is_bool($v) ? ($v ? 1 : 0) : $v;
          $stmt->bindValue($paramKey, $val, $type);
        }
      }
      $stmt->execute();
      if ($in['mode'] === 'query') {
        $rows = $stmt->columnCount() > 0 ? $stmt->fetchAll() : [];
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
      } else {
        echo json_encode([
          'ok' => true,
          'rowCount' => $stmt->rowCount(),
          'lastInsertId' => $pdo->lastInsertId()
        ], JSON_UNESCAPED_UNICODE);
      }
      exit(0);
    } catch (Throwable $e) {
      echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
      exit(1);
    }
  `;

  const res = runPhpBridge(phpCode, { ...db, mode, sql, params }, opts);
  let parsed = null;
  if (res.stdout && res.stdout.trim()) {
    try {
      parsed = JSON.parse(res.stdout.trim());
    } catch {
      parsed = null;
    }
  }

  if (res.status !== 0 || !parsed || !parsed.ok) {
    const errMsg = parsed?.error || res.stderr?.trim() || res.stdout?.trim() || `Exit code ${res.status}`;
    throw new Error(`DB ${mode} failed: ${errMsg}`);
  }

  return parsed;
}

export async function queryJson(sql, params = [], opts = {}) {
  const result = executePdo('query', sql, params, opts);
  return result.rows ?? [];
}

export async function scalar(sql, params = [], opts = {}) {
  const rows = await queryJson(sql, params, opts);
  if (!rows.length) {
    return null;
  }
  const values = Object.values(rows[0]);
  return values.length ? (values[0] ?? null) : null;
}

export async function execSql(sql, params = [], opts = {}) {
  const result = executePdo('exec', sql, params, opts);
  return {
    rowCount: result.rowCount ?? 0,
    lastInsertId: result.lastInsertId ?? '0'
  };
}

export function readNotificationLog(logPath = CONFIG.notificationLogPath) {
  if (!fs.existsSync(logPath)) {
    return [];
  }
  const content = fs.readFileSync(logPath, 'utf8');
  if (!content.trim()) {
    return [];
  }
  return content
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      try {
        return JSON.parse(line);
      } catch {
        return { raw: line };
      }
    });
}
