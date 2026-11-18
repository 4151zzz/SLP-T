import fs from 'node:fs/promises';
import path from 'node:path';

const MIME_MAP = Object.freeze({
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.pdf': 'application/pdf',
  '.txt': 'text/plain',
  '.csv': 'text/csv',
  '.json': 'application/json',
  '.php': 'text/x-php',
  '.html': 'text/html',
  '.htm': 'text/html'
});

function mimeFromFilename(filename) {
  const ext = path.extname(String(filename || '')).toLowerCase();
  return MIME_MAP[ext] || 'application/octet-stream';
}

function decodeHtmlEntities(str) {
  return String(str)
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#0*39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>');
}

function parseAttributes(attrText) {
  const attrs = {};
  const re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let match;
  while ((match = re.exec(attrText)) !== null) {
    const key = match[1].toLowerCase();
    const rawVal = match[2] ?? match[3] ?? match[4] ?? '';
    attrs[key] = decodeHtmlEntities(rawVal);
  }
  return attrs;
}

export function extractCsrf(html) {
  if (!html || typeof html !== 'string') {
    return null;
  }

  const priorityNames = ['csrf_token', '_token', 'csrf', '__csrf_token'];
  const inputMatches = html.matchAll(/<input\b([^>]*)>/gi);
  const foundInputs = new Map();

  for (const m of inputMatches) {
    const attrs = parseAttributes(m[1]);
    if (!attrs.name) continue;
    const lowerName = attrs.name.toLowerCase();
    if (priorityNames.includes(lowerName) && !foundInputs.has(lowerName)) {
      foundInputs.set(lowerName, attrs.value ?? '');
    }
  }

  for (const name of priorityNames) {
    if (foundInputs.has(name)) {
      return foundInputs.get(name);
    }
  }

  const metaMatches = html.matchAll(/<meta\b([^>]*)>/gi);
  for (const m of metaMatches) {
    const attrs = parseAttributes(m[1]);
    const name = (attrs.name || '').toLowerCase();
    if (/^(?:csrf-token|csrf_token|_token)$/.test(name) && typeof attrs.content === 'string') {
      return attrs.content;
    }
  }

  return null;
}

export function extractFormInfo(html, actionHint = null) {
  if (!html || typeof html !== 'string') {
    return { action: '', method: 'POST', csrfFieldName: null, csrfToken: null, inputs: [] };
  }

  const forms = [];
  const formRe = /<form\b([^>]*)>([\s\S]*?)<\/form>/gi;
  let formMatch;
  while ((formMatch = formRe.exec(html)) !== null) {
    const formAttrs = parseAttributes(formMatch[1]);
    forms.push({
      attrs: formAttrs,
      body: formMatch[2]
    });
  }

  let selectedForm = null;
  if (forms.length > 0) {
    if (actionHint) {
      const hint = String(actionHint).toLowerCase();
      selectedForm = forms.find((f) => (f.attrs.action || '').toLowerCase().includes(hint)) || null;
    }
    if (!selectedForm) {
      selectedForm = forms[0];
    }
  }

  const scopeHtml = selectedForm ? selectedForm.body : html;
  const action = selectedForm?.attrs.action ?? '';
  const method = (selectedForm?.attrs.method || 'GET').toUpperCase();

  const inputs = [];
  const tagRe = /<(input|select|textarea)\b([^>]*)>/gi;
  let tagMatch;
  while ((tagMatch = tagRe.exec(scopeHtml)) !== null) {
    const tag = tagMatch[1].toLowerCase();
    const attrs = parseAttributes(tagMatch[2]);
    if (!attrs.name) continue;
    inputs.push({
      tag,
      type: (attrs.type || (tag === 'input' ? 'text' : tag)).toLowerCase(),
      name: attrs.name,
      value: attrs.value ?? ''
    });
  }

  const priorityNames = ['csrf_token', '_token', 'csrf', '__csrf_token'];
  let csrfFieldName = null;
  let csrfToken = null;
  for (const name of priorityNames) {
    const found = inputs.find((inp) => inp.name.toLowerCase() === name);
    if (found) {
      csrfFieldName = found.name;
      csrfToken = found.value;
      break;
    }
  }

  if (csrfToken === null) {
    csrfToken = extractCsrf(html);
    if (csrfToken !== null && !csrfFieldName) {
      csrfFieldName = 'csrf_token';
    }
  }

  return {
    action,
    method,
    csrfFieldName,
    csrfToken,
    inputs
  };
}

function defaultCookiePath(pathname) {
  if (!pathname || !pathname.startsWith('/')) {
    return '/';
  }
  if (pathname === '/') {
    return '/';
  }
  const idx = pathname.lastIndexOf('/');
  if (idx <= 0) {
    return '/';
  }
  return pathname.slice(0, idx);
}

function cookiePathMatches(reqPath, cookiePath) {
  if (reqPath === cookiePath) return true;
  if (!reqPath.startsWith(cookiePath)) return false;
  if (cookiePath.endsWith('/')) return true;
  return reqPath[cookiePath.length] === '/';
}

export class HttpClient {
  constructor(baseUrl, options = {}) {
    if (!baseUrl || typeof baseUrl !== 'string') {
      throw new Error('HttpClient requires a non-empty baseUrl');
    }
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.originUrl = new URL(this.baseUrl + '/');
    this.cookies = new Map();
    this.defaultHeaders = { ...(options.headers || {}) };
    this.timeoutMs = options.timeoutMs ?? 15000;
    this.maxRedirects = options.maxRedirects ?? 10;
  }

  clearCookies() {
    this.cookies.clear();
  }

  extractCsrf(html) {
    return extractCsrf(html);
  }

  extractFormInfo(html, actionHint = null) {
    return extractFormInfo(html, actionHint);
  }

  ingestSetCookieHeaders(setCookieList, requestUrl) {
    if (!Array.isArray(setCookieList) || setCookieList.length === 0) {
      return;
    }
    const reqHost = requestUrl.hostname.toLowerCase();
    const reqPath = requestUrl.pathname || '/';
    const now = Date.now();

    for (const rawHeader of setCookieList) {
      if (!rawHeader || typeof rawHeader !== 'string') continue;
      const parts = rawHeader.split(';');
      const nameValPart = parts[0].trim();
      const eqIdx = nameValPart.indexOf('=');
      if (eqIdx <= 0) continue;

      const name = nameValPart.slice(0, eqIdx).trim();
      const value = nameValPart.slice(eqIdx + 1).trim();

      let domain = reqHost;
      let hostOnly = true;
      let cookiePath = defaultCookiePath(reqPath);
      let expiresAt = null;
      let maxAgeProvided = false;
      let secure = false;
      let httpOnly = false;

      for (let i = 1; i < parts.length; i++) {
        const attr = parts[i].trim();
        if (!attr) continue;
        const attrEq = attr.indexOf('=');
        const attrKey = (attrEq === -1 ? attr : attr.slice(0, attrEq)).trim().toLowerCase();
        const attrVal = attrEq === -1 ? '' : attr.slice(attrEq + 1).trim();

        if (attrKey === 'domain' && attrVal) {
          domain = attrVal.replace(/^\.+/, '').toLowerCase();
          hostOnly = false;
        } else if (attrKey === 'path' && attrVal.startsWith('/')) {
          cookiePath = attrVal;
        } else if (attrKey === 'max-age') {
          if (/^-?\d+$/.test(attrVal)) {
            const secs = Number(attrVal);
            if (Number.isFinite(secs)) {
              maxAgeProvided = true;
              expiresAt = secs <= 0 ? 0 : now + secs * 1000;
            }
          }
        } else if (attrKey === 'expires' && !maxAgeProvided) {
          const parsed = Date.parse(attrVal);
          if (!Number.isNaN(parsed)) {
            expiresAt = parsed;
          }
        } else if (attrKey === 'secure') {
          secure = true;
        } else if (attrKey === 'httponly') {
          httpOnly = true;
        }
      }

      if (!hostOnly && reqHost !== domain && !reqHost.endsWith('.' + domain)) {
        continue;
      }

      const key = `${domain}\t${cookiePath}\t${name}`;
      if (expiresAt !== null && expiresAt <= now) {
        this.cookies.delete(key);
        continue;
      }

      this.cookies.set(key, {
        name,
        value,
        domain,
        hostOnly,
        path: cookiePath,
        expiresAt,
        secure,
        httpOnly
      });
    }
  }

  getCookies(pathOrUrl = '/') {
    const targetUrl = new URL(pathOrUrl, this.baseUrl + '/');
    const host = targetUrl.hostname.toLowerCase();
    const reqPath = targetUrl.pathname || '/';
    const isHttps = targetUrl.protocol === 'https:';
    const now = Date.now();
    const matched = [];

    for (const [key, cookie] of this.cookies.entries()) {
      if (cookie.expiresAt !== null && cookie.expiresAt <= now) {
        this.cookies.delete(key);
        continue;
      }
      if (cookie.secure && !isHttps) continue;
      const hostMatch = cookie.hostOnly
        ? host === cookie.domain
        : host === cookie.domain || host.endsWith('.' + cookie.domain);
      if (!hostMatch) continue;
      if (!cookiePathMatches(reqPath, cookie.path)) continue;
      matched.push(cookie);
    }

    matched.sort((a, b) => b.path.length - a.path.length);
    return matched;
  }

  getCookie(name, pathOrUrl = '/') {
    const matched = this.getCookies(pathOrUrl);
    const found = matched.find((c) => c.name === name);
    return found ? found.value : null;
  }

  buildCookieHeader(targetUrl) {
    const matched = this.getCookies(targetUrl.toString());
    return matched.map((c) => `${c.name}=${c.value}`).join('; ');
  }

  async request(method, pathOrUrl, opts = {}) {
    let currentMethod = String(method || 'GET').toUpperCase();
    let currentUrl = new URL(pathOrUrl, this.baseUrl + '/');
    let currentBody = opts.body;
    const baseHeaders = { ...this.defaultHeaders, ...(opts.headers || {}) };
    const maxRedirects = opts.maxRedirects ?? this.maxRedirects;
    const followRedirects = opts.redirect !== 'manual' && opts.followRedirects !== false;
    const timeoutMs = opts.timeoutMs ?? this.timeoutMs;
    const redirects = [];

    for (let hop = 0; hop <= maxRedirects; hop++) {
      const headers = new Headers(baseHeaders);
      const cookieHeader = this.buildCookieHeader(currentUrl);
      if (cookieHeader && !headers.has('cookie')) {
        headers.set('cookie', cookieHeader);
      }

      const fetchOpts = {
        method: currentMethod,
        headers,
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs)
      };

      if (currentBody !== undefined && currentMethod !== 'GET' && currentMethod !== 'HEAD') {
        fetchOpts.body = currentBody;
      }

      const resp = await fetch(currentUrl.toString(), fetchOpts);
      const setCookies = typeof resp.headers.getSetCookie === 'function' ? resp.headers.getSetCookie() : [];
      this.ingestSetCookieHeaders(setCookies, currentUrl);

      const location = resp.headers.get('location');
      const isRedirect = [301, 302, 303, 307, 308].includes(resp.status) && Boolean(location);

      if (followRedirects && isRedirect) {
        if (hop === maxRedirects) {
          await resp.body?.cancel().catch(() => {});
          throw new Error(`Exceeded max redirects (${maxRedirects}) at ${currentUrl.toString()}`);
        }
        redirects.push({
          status: resp.status,
          from: currentUrl.toString(),
          location
        });
        await resp.body?.cancel().catch(() => {});

        const nextUrl = new URL(location, currentUrl);
        if (resp.status === 303 || ((resp.status === 301 || resp.status === 302) && currentMethod !== 'GET' && currentMethod !== 'HEAD')) {
          currentMethod = 'GET';
          currentBody = undefined;
          for (const key of Object.keys(baseHeaders)) {
            const lower = key.toLowerCase();
            if (lower === 'content-type' || lower === 'content-length') {
              delete baseHeaders[key];
            }
          }
        }
        currentUrl = nextUrl;
        continue;
      }

      const bodyBuffer = Buffer.from(await resp.arrayBuffer());
      const text = bodyBuffer.toString('utf8');

      return {
        status: resp.status,
        ok: resp.ok,
        url: currentUrl.toString(),
        headers: resp.headers,
        location,
        redirected: redirects.length > 0,
        redirects,
        bodyBuffer,
        text,
        json: () => JSON.parse(text),
        csrfToken: () => extractCsrf(text)
      };
    }

    throw new Error('Unexpected redirect loop termination');
  }

  async get(pathOrUrl, opts = {}) {
    return this.request('GET', pathOrUrl, opts);
  }

  async postForm(pathOrUrl, fields = {}, opts = {}) {
    const params = new URLSearchParams();
    for (const [key, val] of Object.entries(fields || {})) {
      if (val === undefined || val === null) continue;
      if (Array.isArray(val)) {
        for (const item of val) {
          if (item !== undefined && item !== null) {
            params.append(key, String(item));
          }
        }
      } else {
        params.append(key, String(val));
      }
    }

    return this.request('POST', pathOrUrl, {
      ...opts,
      headers: {
        'content-type': 'application/x-www-form-urlencoded; charset=utf-8',
        ...(opts.headers || {})
      },
      body: params.toString()
    });
  }

  async postMultipart(pathOrUrl, fields = {}, files = {}, opts = {}) {
    const form = new FormData();

    for (const [key, val] of Object.entries(fields || {})) {
      if (val === undefined || val === null) continue;
      if (Array.isArray(val)) {
        for (const item of val) {
          if (item !== undefined && item !== null) {
            form.append(key, String(item));
          }
        }
      } else {
        form.append(key, String(val));
      }
    }

    const appendFileEntry = async (fieldName, spec) => {
      if (spec === undefined || spec === null) return;

      if (typeof spec === 'string') {
        const buffer = await fs.readFile(spec);
        const filename = path.basename(spec);
        const type = mimeFromFilename(filename);
        form.append(fieldName, new File([buffer], filename, { type }));
        return;
      }

      if (spec instanceof Blob) {
        const filename = spec.name || 'upload.bin';
        form.append(fieldName, spec, filename);
        return;
      }

      if (typeof spec === 'object') {
        if (spec.buffer !== undefined) {
          const filename = spec.filename || 'upload.bin';
          const type = spec.contentType || spec.mimeType || mimeFromFilename(filename);
          form.append(fieldName, new File([spec.buffer], filename, { type }));
          return;
        }
        if (spec.path) {
          const buffer = await fs.readFile(spec.path);
          const filename = spec.filename || path.basename(spec.path);
          const type = spec.contentType || spec.mimeType || mimeFromFilename(filename);
          form.append(fieldName, new File([buffer], filename, { type }));
          return;
        }
      }

      throw new Error(`Unsupported file specification for field "${fieldName}"`);
    };

    for (const [fieldName, spec] of Object.entries(files || {})) {
      if (Array.isArray(spec)) {
        for (const item of spec) {
          await appendFileEntry(fieldName, item);
        }
      } else {
        await appendFileEntry(fieldName, spec);
      }
    }

    const headers = { ...(opts.headers || {}) };
    delete headers['content-type'];
    delete headers['Content-Type'];

    return this.request('POST', pathOrUrl, {
      ...opts,
      headers,
      body: form
    });
  }

  async postJson(pathOrUrl, payload = {}, opts = {}) {
    return this.request('POST', pathOrUrl, {
      ...opts,
      headers: {
        'content-type': 'application/json; charset=utf-8',
        ...(opts.headers || {})
      },
      body: JSON.stringify(payload)
    });
  }
}

export default HttpClient;
