import { CONFIG } from './env.mjs';
import { HttpClient, extractFormInfo } from './http.mjs';

function resolveFormAction(rawAction, fallbackPath, currentUrl) {
  if (!rawAction) {
    return fallbackPath;
  }
  try {
    return new URL(rawAction, currentUrl).toString();
  } catch {
    return rawAction;
  }
}

function findInputName(inputs, candidates, excludeTypes = new Set(['hidden', 'password', 'submit', 'button', 'checkbox', 'radio'])) {
  for (const candidate of candidates) {
    const match = inputs.find(
      (inp) => inp.name.toLowerCase() === candidate && !excludeTypes.has(inp.type)
    );
    if (match) return match.name;
  }
  return null;
}

function hasSessionCookie(client) {
  const sessionNames = ['PHPSESSID', 'phpsessid'];
  for (const name of sessionNames) {
    if (client.getCookie(name)) return true;
  }
  return client.getCookies('/').length > 0;
}

function looksLikeLoginPage(res) {
  try {
    const pathname = new URL(res.url).pathname;
    if (pathname.endsWith('/login.php')) {
      return true;
    }
  } catch {
    // ignore URL parse error
  }
  return /<form\b[^>]*>[\s\S]*?<input\b[^>]*type=["']password["'][^>]*>[\s\S]*?<\/form>/i.test(res.text || '') &&
    /(login\.php|sign\s*in|เข้าสู่ระบบ)/i.test(res.text || '');
}

function extractErrorAlert(html) {
  if (!html || typeof html !== 'string') return null;
  const patterns = [
    /<div\b[^>]*class=["'][^"']*\b(?:alert-danger|alert-error|error-message|invalid-feedback)\b[^"']*["'][^>]*>([\s\S]*?)<\/div>/i,
    /<div\b(?![^>]*\balert-(?:success|info|warning)\b)[^>]*role=["']alert["'][^>]*>([\s\S]*?)<\/div>/i,
    /<p\b[^>]*class=["'][^"']*\b(?:alert-danger|error|text-danger)\b[^"']*["'][^>]*>([\s\S]*?)<\/p>/i
  ];
  for (const re of patterns) {
    const match = html.match(re);
    if (match) {
      const cleaned = match[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      if (cleaned) return cleaned;
    }
  }
  return null;
}

function seedHiddenInputs(payload, inputs, csrfFieldName) {
  const csrfNames = new Set(['csrf_token', '_token', 'csrf', '__csrf_token']);
  if (csrfFieldName) {
    csrfNames.add(csrfFieldName.toLowerCase());
  }
  let hasHiddenRedirect = false;
  for (const inp of inputs || []) {
    if (inp.type !== 'hidden' || !inp.name) continue;
    const lower = inp.name.toLowerCase();
    if (csrfNames.has(lower)) continue;
    payload[inp.name] = inp.value ?? '';
    if (lower === 'redirect' && (inp.value ?? '') !== '') {
      hasHiddenRedirect = true;
    }
  }
  return { hasHiddenRedirect };
}

export async function attemptLoginHttp(baseUrl, identifier, password = CONFIG.seedPassword, opts = {}) {
  const client = opts.client ?? new HttpClient(baseUrl);
  const loginPath = opts.loginPath ?? (opts.redirect ? `/login.php?redirect=${encodeURIComponent(opts.redirect)}` : '/login.php');

  const getRes = await client.get(loginPath);
  const form = extractFormInfo(getRes.text, 'login');

  const idField =
    opts.identifierField ||
    findInputName(form.inputs, ['identifier', 'username', 'login', 'username_or_staff_id', 'user', 'email', 'staff_id']) ||
    'identifier';

  const pwInput = form.inputs.find((inp) => inp.type === 'password');
  const pwField = opts.passwordField || pwInput?.name || 'password';

  const payload = {};
  const { hasHiddenRedirect } = seedHiddenInputs(payload, form.inputs, form.csrfFieldName);
  payload[idField] = identifier;
  payload[pwField] = password;

  if (!opts.omitCsrf) {
    const csrfName = form.csrfFieldName || 'csrf_token';
    const csrfVal = opts.csrfOverride !== undefined ? opts.csrfOverride : form.csrfToken;
    if (csrfVal !== null && csrfVal !== undefined) {
      payload[csrfName] = csrfVal;
    }
  }

  let actionUrl = resolveFormAction(form.action, loginPath, getRes.url);
  if (opts.redirect && !hasHiddenRedirect) {
    try {
      const parsedAction = new URL(actionUrl, getRes.url);
      if (!parsedAction.searchParams.has('redirect')) {
        parsedAction.searchParams.set('redirect', opts.redirect);
        actionUrl = parsedAction.toString();
      }
    } catch {
      // keep resolved actionUrl as-is
    }
  }

  const postRes = await client.postForm(actionUrl, payload, opts.postOpts || {});

  const isManualRedirect = opts.postOpts?.redirect === 'manual';
  let authenticated = false;
  if (isManualRedirect && postRes.status >= 300 && postRes.status < 400 && postRes.location) {
    let locationIsLogin = false;
    try {
      const locUrl = new URL(postRes.location, postRes.url);
      locationIsLogin = locUrl.pathname.endsWith('/login.php');
    } catch {
      locationIsLogin = false;
    }
    authenticated = hasSessionCookie(client) && !locationIsLogin;
  } else {
    authenticated =
      postRes.status < 400 &&
      hasSessionCookie(client) &&
      !looksLikeLoginPage(postRes);
  }

  return {
    client,
    getRes,
    postRes,
    authenticated
  };
}

export async function loginHttp(baseUrl, identifier, password = CONFIG.seedPassword, opts = {}) {
  const attempt = await attemptLoginHttp(baseUrl, identifier, password, opts);
  if (!attempt.authenticated) {
    const snippet = (attempt.postRes.text || '')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 220);
    throw new Error(
      `HTTP login failed for "${identifier}" (status=${attempt.postRes.status}, url=${attempt.postRes.url}, hasSession=${hasSessionCookie(attempt.client)}): ${snippet}`
    );
  }
  return attempt.client;
}

export async function registerExternalHttp(baseUrl, userData = {}, opts = {}) {
  const unique = `${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
  const user = {
    full_name: userData.full_name ?? `External Tester ${unique}`,
    organization: userData.organization ?? `Org ${unique}`,
    phone: userData.phone ?? '0812345678',
    email: userData.email ?? `ext_${unique}@example.org`,
    username: userData.username ?? `ext_${unique}`,
    password: userData.password ?? CONFIG.seedPassword
  };

  const client = opts.client ?? new HttpClient(baseUrl);
  const registerPath = opts.registerPath ?? '/register.php';
  const getRes = await client.get(registerPath);
  const form = extractFormInfo(getRes.text, 'register');

  const payload = {};
  seedHiddenInputs(payload, form.inputs, form.csrfFieldName);
  if (!opts.omitCsrf && form.csrfToken !== null && form.csrfToken !== undefined) {
    payload[form.csrfFieldName || 'csrf_token'] = form.csrfToken;
  }

  const fieldMappings = [
    [['full_name', 'fullname', 'name'], user.full_name],
    [['organization', 'org', 'company', 'department'], user.organization],
    [['phone', 'tel', 'mobile'], user.phone],
    [['email', 'mail'], user.email],
    [['username', 'user'], user.username],
    [['password'], user.password],
    [['password_confirm', 'confirm_password', 'password_confirmation', 'confirm'], user.password]
  ];

  for (const [candidates, value] of fieldMappings) {
    const matchedInput = form.inputs.find((inp) => candidates.includes(inp.name.toLowerCase()));
    if (matchedInput) {
      payload[matchedInput.name] = value;
    }
  }

  // Fallback defaults if register.php inputs could not be parsed cleanly
  if (!Object.keys(payload).some((k) => ['email', 'mail'].includes(k.toLowerCase()))) {
    payload.full_name = user.full_name;
    payload.organization = user.organization;
    payload.phone = user.phone;
    payload.email = user.email;
    payload.password = user.password;
  }

  const actionUrl = resolveFormAction(form.action, registerPath, getRes.url);
  const postRes = await client.postForm(actionUrl, payload, opts.postOpts || {});

  if (opts.expectSuccess !== false) {
    if (postRes.status >= 400) {
      throw new Error(`External registration failed with HTTP ${postRes.status} at ${postRes.url}`);
    }
    const alertErr = extractErrorAlert(postRes.text);
    if (alertErr) {
      throw new Error(`External registration failed at ${postRes.url}: ${alertErr}`);
    }
    let stayedOnRegister = false;
    try {
      const regPathName = new URL(registerPath, baseUrl).pathname;
      const postPathName = new URL(postRes.url).pathname;
      stayedOnRegister = postPathName === regPathName && !postRes.redirected;
    } catch {
      stayedOnRegister = false;
    }
    const hasSuccessBanner = /\balert-success\b/i.test(postRes.text || '');
    if (stayedOnRegister && !hasSuccessBanner) {
      const snippet = (postRes.text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
      throw new Error(`External registration did not leave ${postRes.url} (possible validation failure): ${snippet}`);
    }
    if (opts.ensureLoggedIn !== false && (!hasSessionCookie(client) || looksLikeLoginPage(postRes) || stayedOnRegister)) {
      await loginHttp(baseUrl, user.email, user.password, { client });
    }
  }

  return {
    client,
    user,
    getRes,
    postRes
  };
}

export async function logoutHttp(client, opts = {}) {
  const logoutPath = opts.logoutPath ?? '/logout.php';
  const res = await client.get(logoutPath);
  if (res.status === 405 || /method\s+not\s+allowed/i.test(res.text || '')) {
    const csrf = res.csrfToken();
    const fields = csrf ? { csrf_token: csrf } : {};
    return client.postForm(logoutPath, fields);
  }
  return res;
}
