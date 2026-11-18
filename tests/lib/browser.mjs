import fs from 'node:fs';
import { chromium } from 'playwright-core';
import { CONFIG } from './env.mjs';

export const DESKTOP = Object.freeze({ width: 1280, height: 800 });
export const MOBILE = Object.freeze({ width: 375, height: 812 });

export async function launchChrome(opts = {}) {
  const executablePath = opts.executablePath || CONFIG.chromePath;
  if (!fs.existsSync(executablePath)) {
    throw new Error(`Chrome executable not found at: ${executablePath}`);
  }

  const headless = opts.headless ?? (process.env.HEADLESS !== '0');
  const launchArgs = [
    '--no-sandbox',
    '--disable-gpu',
    '--disable-dev-shm-usage',
    '--disable-background-timer-throttling',
    '--disable-renderer-backgrounding',
    ...(opts.args || [])
  ];

  return chromium.launch({
    executablePath,
    headless,
    handleSIGINT: false,
    handleSIGTERM: false,
    timeout: opts.timeout ?? 30000,
    args: launchArgs
  });
}

export async function createContext(browser, viewport = DESKTOP, opts = {}) {
  const { timeout = 15000, ...restOpts } = opts;
  const context = await browser.newContext({
    viewport,
    locale: 'th-TH',
    timezoneId: 'Asia/Bangkok',
    ignoreHTTPSErrors: true,
    acceptDownloads: true,
    ...restOpts
  });
  context.setDefaultTimeout(timeout);
  return context;
}

export function attachErrorListeners(page, opts = {}) {
  const ignoreFavicon404 = opts.ignoreFavicon404 ?? true;
  const includeAborted = opts.includeAborted ?? false;

  const pageErrors = [];
  const consoleErrors = [];
  const serverErrors = [];
  const requestFailures = [];
  let recentFavicon404At = 0;

  const onPageError = (err) => {
    pageErrors.push({
      message: err?.message || String(err),
      stack: err?.stack || ''
    });
  };

  const onResponse = (res) => {
    try {
      const url = res.url();
      const status = res.status();
      if (status === 404 && url.toLowerCase().includes('favicon.ico')) {
        recentFavicon404At = Date.now();
      }
      if (status >= 500) {
        serverErrors.push({
          url,
          status,
          statusText: res.statusText()
        });
      }
    } catch {
      // ignore closed page inspection errors
    }
  };

  const onConsole = (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    const locUrl = msg.location()?.url || '';
    if (ignoreFavicon404) {
      const lowerLoc = locUrl.toLowerCase();
      const lowerText = text.toLowerCase();
      const isFaviconLoc = lowerLoc.includes('favicon.ico');
      const hasNonFaviconLoc = locUrl !== '' && !isFaviconLoc;
      const mentionsNonFaviconUrl = /https?:\/\/\S+/i.test(text) && !lowerText.includes('favicon.ico');
      const isGenericFavicon404 =
        !hasNonFaviconLoc &&
        !mentionsNonFaviconUrl &&
        text.includes('404') &&
        Date.now() - recentFavicon404At < 1500;
      if (isFaviconLoc || isGenericFavicon404) {
        return;
      }
    }
    consoleErrors.push({ text, url: locUrl });
  };

  const onRequestFailed = (req) => {
    const errorText = req.failure()?.errorText || 'unknown';
    if (!includeAborted && errorText === 'net::ERR_ABORTED') {
      return;
    }
    requestFailures.push({
      url: req.url(),
      method: req.method(),
      errorText
    });
  };

  page.on('pageerror', onPageError);
  page.on('response', onResponse);
  page.on('console', onConsole);
  page.on('requestfailed', onRequestFailed);

  const hasErrors = () =>
    pageErrors.length > 0 ||
    consoleErrors.length > 0 ||
    serverErrors.length > 0 ||
    requestFailures.length > 0;

  const summary = () => ({
    pageErrors: [...pageErrors],
    consoleErrors: [...consoleErrors],
    serverErrors: [...serverErrors],
    requestFailures: [...requestFailures]
  });

  const assertNoErrors = (contextLabel = 'page') => {
    if (!hasErrors()) return;
    const parts = [];
    if (pageErrors.length) parts.push(`pageErrors=${JSON.stringify(pageErrors)}`);
    if (consoleErrors.length) parts.push(`consoleErrors=${JSON.stringify(consoleErrors)}`);
    if (serverErrors.length) parts.push(`serverErrors=${JSON.stringify(serverErrors)}`);
    if (requestFailures.length) parts.push(`requestFailures=${JSON.stringify(requestFailures)}`);
    throw new Error(`Browser errors detected on ${contextLabel}: ${parts.join(' | ')}`);
  };

  const clear = () => {
    pageErrors.length = 0;
    consoleErrors.length = 0;
    serverErrors.length = 0;
    requestFailures.length = 0;
  };

  const detach = () => {
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
    page.off('console', onConsole);
    page.off('requestfailed', onRequestFailed);
  };

  return {
    pageErrors,
    consoleErrors,
    serverErrors,
    requestFailures,
    hasErrors,
    summary,
    assertNoErrors,
    clear,
    detach
  };
}

export async function loginUi(page, baseUrl, identifier, password = CONFIG.seedPassword, opts = {}) {
  const cleanBase = baseUrl.replace(/\/+$/, '');
  const loginUrl = opts.redirect
    ? `${cleanBase}/login.php?redirect=${encodeURIComponent(opts.redirect)}`
    : `${cleanBase}/login.php`;

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });

  const timeout = opts.timeout ?? 10000;
  await page.locator('input[type="password"]').first().waitFor({ state: 'visible', timeout });

  const passwordForms = page.locator('form').filter({ has: page.locator('input[type="password"]') });
  const scope = (await passwordForms.count()) > 0 ? passwordForms.first() : page;

  const findFirstInScope = async (selectors) => {
    for (const sel of selectors) {
      const loc = scope.locator(sel).first();
      if ((await loc.count()) > 0) {
        return loc;
      }
    }
    return null;
  };

  const idInput = await findFirstInScope([
    'input[name="identifier"]',
    'input[name="username"]',
    'input[name="login"]',
    'input[name="username_or_staff_id"]',
    'input[name="staff_id"]',
    'input[name="email"]',
    'input[data-testid="login-identifier"]',
    'input[type="text"]',
    'input[type="email"]'
  ]);
  if (!idInput) {
    throw new Error(`UI login could not find identifier input on ${page.url()}`);
  }

  const pwInput = await findFirstInScope([
    'input[name="password"]',
    'input[data-testid="login-password"]',
    'input[type="password"]'
  ]);
  if (!pwInput) {
    throw new Error(`UI login could not find password input on ${page.url()}`);
  }

  const submitBtn = await findFirstInScope([
    'button[type="submit"]',
    'input[type="submit"]',
    'button[data-testid="login-submit"]'
  ]);
  if (!submitBtn) {
    throw new Error(`UI login could not find submit button on ${page.url()}`);
  }

  await idInput.waitFor({ state: 'visible', timeout });
  await idInput.fill(identifier);
  await pwInput.fill(password);

  await Promise.all([
    page.waitForURL((u) => !new URL(u).pathname.endsWith('/login.php'), {
      timeout
    }).catch(() => {}),
    submitBtn.click()
  ]);
  await page.waitForLoadState('domcontentloaded').catch(() => {});

  const currentPath = new URL(page.url()).pathname;
  if (currentPath.endsWith('/login.php')) {
    const alertText = await page
      .locator('.alert-danger, [role="alert"], .error, .invalid-feedback')
      .first()
      .textContent()
      .catch(() => '');
    throw new Error(
      `UI login failed for "${identifier}" (stayed at ${page.url()}): ${(alertText || '').trim() || 'no alert message'}`
    );
  }

  return page.url();
}
