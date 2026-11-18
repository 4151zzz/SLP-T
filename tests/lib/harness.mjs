import assert from 'node:assert/strict';
import path from 'node:path';
import { CONFIG } from './env.mjs';
import * as db from './db.mjs';
import { HttpClient } from './http.mjs';
import * as auth from './auth.mjs';
import * as browserHelpers from './browser.mjs';

export const AssertionError = assert.AssertionError;

const registry = new Map();
let currentFile = '';

export function setCurrentTestFile(filePath) {
  currentFile = filePath || '';
}

export function clearTests() {
  registry.clear();
}

export function test(spec) {
  if (!spec || typeof spec !== 'object') {
    throw new Error('test(spec) requires a test specification object');
  }
  const id = typeof spec.id === 'string' ? spec.id.trim() : '';
  const tier = Number(spec.tier);
  const feature = typeof spec.feature === 'string' ? spec.feature.trim() : '';
  const name = typeof spec.name === 'string' ? spec.name.trim() : '';
  const fn = spec.fn;

  if (!id) throw new Error('Test spec requires non-empty string "id"');
  if (![1, 2, 3, 4].includes(tier)) throw new Error(`Test "${id}" requires tier 1..4 (got ${spec.tier})`);
  if (!feature) throw new Error(`Test "${id}" requires non-empty string "feature"`);
  if (!name) throw new Error(`Test "${id}" requires non-empty string "name"`);
  if (typeof fn !== 'function') throw new Error(`Test "${id}" requires function "fn"`);

  if (registry.has(id)) {
    throw new Error(`Duplicate test id: ${id}`);
  }

  registry.set(id, {
    id,
    tier,
    feature,
    name,
    file: spec.file || currentFile,
    fn
  });
}

export function getTests({ tier = 'all', filter = '' } = {}) {
  let allowedTiers = null;
  if (tier !== undefined && tier !== null && tier !== 'all') {
    if (Array.isArray(tier)) {
      allowedTiers = new Set(tier.map(Number));
    } else {
      allowedTiers = new Set([Number(tier)]);
    }
  }

  const needle = String(filter || '').trim().toLowerCase();
  const list = [];

  for (const tc of registry.values()) {
    if (allowedTiers && !allowedTiers.has(tc.tier)) continue;
    if (needle) {
      const hay = `${tc.id} ${tc.feature} ${tc.name} ${tc.file}`.toLowerCase();
      if (!hay.includes(needle)) continue;
    }
    list.push({ ...tc });
  }

  list.sort((a, b) => a.tier - b.tier || a.id.localeCompare(b.id, 'en', { numeric: true }));
  return list;
}

export function summarizeTests(tests = getTests()) {
  const byTier = { 1: 0, 2: 0, 3: 0, 4: 0 };
  const byFeature = {};

  for (const tc of tests) {
    byTier[tc.tier] = (byTier[tc.tier] || 0) + 1;
    byFeature[tc.feature] = (byFeature[tc.feature] || 0) + 1;
  }

  const sortedFeatures = {};
  for (const key of Object.keys(byFeature).sort((a, b) => a.localeCompare(b, 'en', { numeric: true }))) {
    sortedFeatures[key] = byFeature[key];
  }

  return {
    total: tests.length,
    byTier,
    byFeature: sortedFeatures
  };
}

function serializeError(err) {
  if (!err) return null;
  return {
    name: err.name || 'Error',
    message: err.message || String(err),
    code: err.code ?? undefined,
    actual: err.actual !== undefined ? String(err.actual) : undefined,
    expected: err.expected !== undefined ? String(err.expected) : undefined,
    stack: err.stack || ''
  };
}

function createDefaultHttpApi(baseUrl) {
  return {
    HttpClient,
    createClient: (opts) => new HttpClient(baseUrl, opts),
    login: (identifier, password, opts) => auth.loginHttp(baseUrl, identifier, password, opts),
    attemptLogin: (identifier, password, opts) => auth.attemptLoginHttp(baseUrl, identifier, password, opts),
    registerExternal: (userData, opts) => auth.registerExternalHttp(baseUrl, userData, opts),
    logout: (client, opts) => auth.logoutHttp(client, opts),
    get: (p, opts) => new HttpClient(baseUrl).get(p, opts),
    postForm: (p, fields, opts) => new HttpClient(baseUrl).postForm(p, fields, opts),
    postMultipart: (p, fields, files, opts) => new HttpClient(baseUrl).postMultipart(p, fields, files, opts),
    postJson: (p, payload, opts) => new HttpClient(baseUrl).postJson(p, payload, opts)
  };
}

function createDefaultContext(tc, { baseUrl = '', addCleanup }) {
  return {
    baseUrl,
    fixturesDir: CONFIG.fixturesDir,
    env: CONFIG,
    resetDb: () => db.resetAndSeed(),
    http: baseUrl ? createDefaultHttpApi(baseUrl) : { HttpClient, ...auth },
    browser: browserHelpers,
    db,
    assert,
    fixturePath: (name) => path.join(CONFIG.fixturesDir, name),
    onCleanup: addCleanup
  };
}

export async function runTests(options = {}) {
  const {
    tier = 'all',
    filter = '',
    json = false,
    bail = false,
    timeoutMs = 90000,
    baseUrl = '',
    createContext,
    onTestStart,
    onTestEnd
  } = options;

  const selected = getTests({ tier, filter });
  const suiteStart = Date.now();
  const results = [];
  let passed = 0;
  let failed = 0;

  for (const tc of selected) {
    if (onTestStart) {
      await onTestStart(tc);
    }

    const cleanups = [];
    const addCleanup = (fn) => {
      if (typeof fn === 'function') cleanups.push(fn);
    };

    const ctx = createContext
      ? await createContext(tc, { baseUrl, addCleanup, defaultContext: () => createDefaultContext(tc, { baseUrl, addCleanup }) })
      : createDefaultContext(tc, { baseUrl, addCleanup });

    const startedAt = Date.now();
    let error = null;
    let timerId = null;

    try {
      const runPromise = Promise.resolve().then(() => tc.fn(ctx));
      if (timeoutMs > 0) {
        const timeoutPromise = new Promise((_, reject) => {
          timerId = setTimeout(() => {
            reject(new Error(`Test "${tc.id}" timed out after ${timeoutMs}ms`));
          }, timeoutMs);
        });
        await Promise.race([runPromise, timeoutPromise]);
      } else {
        await runPromise;
      }
      passed++;
    } catch (err) {
      error = err;
      failed++;
    } finally {
      if (timerId !== null) {
        clearTimeout(timerId);
      }
      for (let i = cleanups.length - 1; i >= 0; i--) {
        try {
          await cleanups[i]();
        } catch (cleanupErr) {
          if (!json) {
            console.error(`Cleanup warning in ${tc.id}: ${cleanupErr?.message || cleanupErr}`);
          }
        }
      }
    }

    const durationMs = Date.now() - startedAt;
    const record = {
      id: tc.id,
      tier: tc.tier,
      feature: tc.feature,
      name: tc.name,
      status: error ? 'failed' : 'passed',
      durationMs,
      error: serializeError(error)
    };
    results.push(record);

    if (!json) {
      const tag = error ? 'FAIL' : 'PASS';
      console.log(`[${tag}] ${tc.id} (${tc.feature}, T${tc.tier}) ${tc.name} [${durationMs}ms]`);
      if (error) {
        console.error(`       ${error.message || error}`);
      }
    }

    if (onTestEnd) {
      await onTestEnd(record);
    }

    if (error && bail) {
      break;
    }
  }

  return {
    ok: failed === 0,
    matched: selected.length,
    total: selected.length,
    passed,
    failed,
    durationMs: Date.now() - suiteStart,
    results
  };
}
