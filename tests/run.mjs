import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import assert from 'node:assert/strict';
import { CONFIG } from './lib/env.mjs';
import * as db from './lib/db.mjs';
import { HttpClient } from './lib/http.mjs';
import * as auth from './lib/auth.mjs';
import * as browserLib from './lib/browser.mjs';
import {
  test as registerTest,
  getTests,
  summarizeTests,
  runTests,
  setCurrentTestFile
} from './lib/harness.mjs';

const __filename = fileURLToPath(import.meta.url);
const testsRoot = path.dirname(__filename);
const projectRoot = path.resolve(testsRoot, '..');

function printHelp() {
  console.log(`Usage: node run.mjs [options]

Options:
  --tier=1|2|3|4|all   Filter tests by tier (default: all)
  --filter=<substr>    Filter tests by id/feature/name substring
  --list               List discovered tests without starting DB/server/browser
  --port=<port>        Port for ephemeral PHP built-in server
  --base-url=<url>     Use an already-running server URL instead of spawning PHP
  --no-server          Do not spawn PHP built-in server
  --no-reset           Skip running sql/reset_and_seed.php before execution
  --bail               Stop after the first failed test
  --json               Emit machine-readable JSON output to stdout
  --help               Show this help message`);
}

function parseCliArgs(argv) {
  const opts = {
    tier: 'all',
    filter: '',
    list: false,
    port: null,
    baseUrl: '',
    noServer: false,
    noReset: false,
    bail: false,
    json: false,
    help: false
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];

    const readVal = (flagName) => {
      const eqIdx = arg.indexOf('=');
      if (eqIdx !== -1) return arg.slice(eqIdx + 1);
      if (i + 1 < argv.length) return argv[++i];
      throw new Error(`Missing value for ${flagName}`);
    };

    if (arg === '--help' || arg === '-h') {
      opts.help = true;
    } else if (arg === '--list') {
      opts.list = true;
    } else if (arg === '--no-server') {
      opts.noServer = true;
    } else if (arg === '--no-reset') {
      opts.noReset = true;
    } else if (arg === '--bail') {
      opts.bail = true;
    } else if (arg === '--json') {
      opts.json = true;
    } else if (arg === '--tier' || arg.startsWith('--tier=')) {
      const val = readVal('--tier').trim().toLowerCase();
      if (!['1', '2', '3', '4', 'all'].includes(val)) {
        throw new Error(`Invalid --tier value "${val}". Expected 1, 2, 3, 4, or all.`);
      }
      opts.tier = val === 'all' ? 'all' : Number(val);
    } else if (arg === '--filter' || arg.startsWith('--filter=')) {
      opts.filter = readVal('--filter');
    } else if (arg === '--port' || arg.startsWith('--port=')) {
      const val = Number(readVal('--port'));
      if (!Number.isInteger(val) || val < 1 || val > 65535) {
        throw new Error(`Invalid --port value: ${val}`);
      }
      opts.port = val;
    } else if (arg === '--base-url' || arg.startsWith('--base-url=')) {
      opts.baseUrl = readVal('--base-url').replace(/\/+$/, '');
    } else {
      throw new Error(`Unknown CLI flag: ${arg}`);
    }
  }

  return opts;
}

async function discoverAndLoadTests() {
  const registrar = (spec) => registerTest(spec);
  registrar.test = registrar;

  for (const tier of [1, 2, 3, 4]) {
    const tierDir = path.join(testsRoot, `tier${tier}`);
    if (!fs.existsSync(tierDir)) continue;

    const files = fs
      .readdirSync(tierDir, { withFileTypes: true })
      .filter((entry) => entry.isFile() && entry.name.endsWith('.test.mjs'))
      .map((entry) => path.join(tierDir, entry.name))
      .sort((a, b) => a.localeCompare(b, 'en'));

    for (const filePath of files) {
      setCurrentTestFile(path.relative(testsRoot, filePath).replace(/\\/g, '/'));
      const mod = await import(pathToFileURL(filePath).href);
      if (typeof mod.register === 'function') {
        await mod.register(registrar);
      }
    }
  }

  setCurrentTestFile('');
}

function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const addr = srv.address();
      const port = addr && typeof addr === 'object' ? addr.port : 0;
      srv.close((err) => {
        if (err) reject(err);
        else resolve(port);
      });
    });
  });
}

function assertPortFree(port) {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.once('error', (err) => {
      reject(new Error(`Port ${port} is not available on 127.0.0.1: ${err.message}`));
    });
    srv.listen(port, '127.0.0.1', () => {
      srv.close((closeErr) => {
        if (closeErr) reject(closeErr);
        else resolve();
      });
    });
  });
}

async function startPhpServer(port) {
  await assertPortFree(port);

  const args = [
    '-d',
    'upload_max_filesize=10M',
    '-d',
    'post_max_size=12M',
    '-S',
    `127.0.0.1:${port}`,
    '-t',
    projectRoot
  ];

  const proc = spawn('php', args, {
    cwd: projectRoot,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true
  });

  let stderrTail = '';
  let stdoutTail = '';
  let exited = false;
  let exitCode = null;

  proc.stdout?.on('data', (chunk) => {
    stdoutTail = (stdoutTail + chunk.toString('utf8')).slice(-2000);
  });
  proc.stderr?.on('data', (chunk) => {
    stderrTail = (stderrTail + chunk.toString('utf8')).slice(-2000);
  });
  proc.on('exit', (code) => {
    exited = true;
    exitCode = code;
  });

  const url = `http://127.0.0.1:${port}`;
  const deadline = Date.now() + 10000;

  while (Date.now() < deadline) {
    if (exited) {
      throw new Error(`PHP server exited prematurely (code=${exitCode}): ${stderrTail || stdoutTail}`);
    }
    try {
      await fetch(`${url}/`, {
        redirect: 'manual',
        signal: AbortSignal.timeout(1000)
      });
      await new Promise((r) => setTimeout(r, 50));
      if (exited || proc.exitCode !== null) {
        throw new Error(`PHP server exited immediately after probe (code=${exitCode ?? proc.exitCode}): ${stderrTail || stdoutTail}`);
      }
      return { proc, url };
    } catch {
      if (exited || proc.exitCode !== null) {
        throw new Error(`PHP server exited prematurely (code=${exitCode ?? proc.exitCode}): ${stderrTail || stdoutTail}`);
      }
      await new Promise((r) => setTimeout(r, 100));
    }
  }

  killProcessTree(proc);
  throw new Error(`Timed out waiting for PHP server at ${url}. stderr: ${stderrTail}`);
}

function killProcessTree(proc) {
  if (!proc || !proc.pid) return;
  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/PID', String(proc.pid), '/T', '/F'], {
      stdio: 'ignore',
      windowsHide: true
    });
  } else {
    try {
      proc.kill('SIGTERM');
    } catch {
      // ignore already exited
    }
  }
}

async function main() {
  let opts;
  try {
    opts = parseCliArgs(process.argv.slice(2));
  } catch (err) {
    console.error(err.message);
    process.exit(2);
  }

  if (opts.help) {
    printHelp();
    process.exit(0);
  }

  await discoverAndLoadTests();
  const tests = getTests({ tier: opts.tier, filter: opts.filter });
  const summary = summarizeTests(tests);

  if (opts.list) {
    const payload = {
      ...summary,
      tests: tests.map(({ id, tier, feature, name, file }) => ({ id, tier, feature, name, file }))
    };
    if (opts.json) {
      console.log(JSON.stringify(payload, null, 2));
    } else {
      console.log(`Discovered ${summary.total} tests (T1=${summary.byTier[1]}, T2=${summary.byTier[2]}, T3=${summary.byTier[3]}, T4=${summary.byTier[4]})`);
      for (const tc of tests) {
        console.log(`  [T${tc.tier}] ${tc.id} (${tc.feature}) - ${tc.name}`);
      }
    }
    process.exit(0);
  }

  let phpProc = null;
  let sharedBrowser = null;
  let cleaningUp = false;

  const cleanup = async () => {
    if (cleaningUp) return;
    cleaningUp = true;

    if (sharedBrowser) {
      try {
        await sharedBrowser.close();
      } catch {
        // ignore browser close errors during teardown
      }
      sharedBrowser = null;
    }

    if (phpProc) {
      killProcessTree(phpProc);
      phpProc = null;
    }
  };

  const onSigInt = () => {
    cleanup().finally(() => process.exit(130));
  };
  const onSigTerm = () => {
    cleanup().finally(() => process.exit(143));
  };
  process.on('SIGINT', onSigInt);
  process.on('SIGTERM', onSigTerm);

  try {
    if (!opts.noReset) {
      if (fs.existsSync(CONFIG.resetScriptPath)) {
        await db.resetAndSeed();
      } else if (!opts.json) {
        console.error(`Notice: ${CONFIG.resetScriptPath} not found; skipping DB reset.`);
      }
    }

    let baseUrl = opts.baseUrl;
    if (!baseUrl) {
      if (opts.noServer) {
        baseUrl = `http://127.0.0.1:${opts.port || 8000}`;
      } else {
        const port = opts.port || (await getFreePort());
        const started = await startPhpServer(port);
        phpProc = started.proc;
        baseUrl = started.url;
      }
    }

    const ensureBrowser = async () => {
      if (!sharedBrowser) {
        sharedBrowser = await browserLib.launchChrome();
      }
      return sharedBrowser;
    };

    const result = await runTests({
      tier: opts.tier,
      filter: opts.filter,
      json: opts.json,
      bail: opts.bail,
      baseUrl,
      createContext: async (tc, { addCleanup }) => {
        const http = {
          HttpClient,
          createClient: (clientOpts) => new HttpClient(baseUrl, clientOpts),
          login: (identifier, password, loginOpts) => auth.loginHttp(baseUrl, identifier, password, loginOpts),
          attemptLogin: (identifier, password, loginOpts) => auth.attemptLoginHttp(baseUrl, identifier, password, loginOpts),
          registerExternal: (userData, regOpts) => auth.registerExternalHttp(baseUrl, userData, regOpts),
          logout: (client, logoutOpts) => auth.logoutHttp(client, logoutOpts),
          get: (p, reqOpts) => new HttpClient(baseUrl).get(p, reqOpts),
          postForm: (p, fields, reqOpts) => new HttpClient(baseUrl).postForm(p, fields, reqOpts),
          postMultipart: (p, fields, files, reqOpts) => new HttpClient(baseUrl).postMultipart(p, fields, files, reqOpts),
          postJson: (p, payload, reqOpts) => new HttpClient(baseUrl).postJson(p, payload, reqOpts)
        };

        const browser = {
          DESKTOP: browserLib.DESKTOP,
          MOBILE: browserLib.MOBILE,
          launchChrome: browserLib.launchChrome,
          createContext: browserLib.createContext,
          attachErrorListeners: browserLib.attachErrorListeners,
          loginUi: (page, identifier, password, uiOpts) =>
            browserLib.loginUi(page, baseUrl, identifier, password, uiOpts),
          getBrowser: ensureBrowser,
          openPage: async (viewport = browserLib.DESKTOP, ctxOpts = {}) => {
            const b = await ensureBrowser();
            const context = await browserLib.createContext(b, viewport, ctxOpts);
            addCleanup(() => context.close());
            const page = await context.newPage();
            const errors = browserLib.attachErrorListeners(page, ctxOpts);
            addCleanup(() => errors.detach());
            return { browser: b, context, page, errors };
          }
        };

        return {
          baseUrl,
          fixturesDir: CONFIG.fixturesDir,
          env: CONFIG,
          resetDb: () => db.resetAndSeed(),
          http,
          browser,
          db,
          assert,
          fixturePath: (name) => path.join(CONFIG.fixturesDir, name),
          onCleanup: addCleanup
        };
      }
    });

    const outputPayload = {
      ...result,
      baseUrl,
      summary
    };

    if (opts.json) {
      console.log(JSON.stringify(outputPayload, null, 2));
    } else {
      console.log(
        `\nCompleted ${result.passed}/${result.matched} tests (${result.failed} failed) in ${result.durationMs}ms`
      );
    }

    await cleanup();
    process.exit(result.ok ? 0 : 1);
  } catch (err) {
    await cleanup();
    if (opts.json) {
      console.log(
        JSON.stringify({
          ok: false,
          error: err?.message || String(err)
        }, null, 2)
      );
    } else {
      console.error(`Runner error: ${err?.stack || err}`);
    }
    process.exit(1);
  } finally {
    process.off('SIGINT', onSigInt);
    process.off('SIGTERM', onSigTerm);
  }
}

main();
