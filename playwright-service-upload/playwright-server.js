'use strict';

const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { chromium } = require('playwright');

const HOST = '127.0.0.1';
const PORT = Number(process.env.PORT) || 3011;
const PUBLIC_HOST = String(process.env.BOTDECK_PUBLIC_HOST || '').toLowerCase();
const MAX_BODY = 10_000;
const CONTROL_TOKEN = process.env.BOTDECK_CONTROL_TOKEN || '';
const BROWSER_HEALTH_INTERVAL_MS = Math.max(5_000, Math.min(300_000, Number(process.env.BOTDECK_BROWSER_HEALTH_INTERVAL_MS) || 15_000));
const NETWORK_CHECK_INTERVAL_MS = Math.max(5_000, Math.min(60_000, Number(process.env.BOTDECK_NETWORK_CHECK_INTERVAL_MS) || 10_000));
const NETWORK_FAILURES_BEFORE_PAUSE = 2;
const sessions = new Map();
const queue = [];
let active = 0;
let browser = null;
let idleTimer = null;
let closing = false;
let lastBrowserCheck = null;
let networkAvailable = true;
let networkFailures = 0;
let networkCheckRunning = false;
let serverListening = false;

function log(level, event, message = '') {
  const safe = String(message).replace(/\b\d{5,15}:[A-Za-z0-9_-]{20,}\b/g, '[REDACTED TOKEN]').replace(/[\r\n\t]+/g, ' ').slice(0, 500);
  process.stdout.write(`${JSON.stringify({ time: new Date().toISOString(), level, event, message: safe })}\n`);
}

function json(res, status, value) {
  if (res.destroyed || res.writableEnded) return;
  const body = Buffer.from(JSON.stringify(value));
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': body.length, 'Cache-Control': 'no-store' });
  res.end(body);
}

function localOrigin(origin) {
  if (!origin) return false;
  try {
    const parsed = new URL(origin);
    return parsed.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname);
  } catch { return false; }
}

function localHostHeader(value) {
  return ['127.0.0.1:3011', 'localhost:3011', '[::1]:3011'].includes(String(value || '').toLowerCase());
}

function publicHostHeader(value) {
  return Boolean(PUBLIC_HOST) && String(value || '').toLowerCase() === PUBLIC_HOST;
}

function publicOrigin(origin) {
  if (!PUBLIC_HOST || !origin) return false;
  try {
    const parsed = new URL(origin);
    return parsed.protocol === 'https:' && parsed.host.toLowerCase() === PUBLIC_HOST;
  } catch { return false; }
}

function browserOptions() {
  const options = { headless: true, args: ['--disable-gpu', '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-extensions'] };
  if (process.platform === 'win32') {
    const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    options.executablePath = chrome;
    options.windowsHide = true;
  }
  return options;
}

async function getBrowser() {
  if (closing) throw new Error('Service is shutting down.');
  if (!networkAvailable) throw Object.assign(new Error('Internet connection is unavailable. The service will resume automatically when it returns.'), { status: 503 });
  if (!browser || !browser.isConnected()) {
    browser = await chromium.launch(browserOptions());
    browser.on('disconnected', () => { browser = null; });
  }
  return browser;
}

function acquire() {
  if (closing) return Promise.reject(new Error('Service is shutting down.'));
  active += 1;
  return Promise.resolve();
}

function release() {
  active = Math.max(0, active - 1);
}

function scheduleIdleClose() {
  clearTimeout(idleTimer);
  if (!sessions.size && browser) {
    idleTimer = setTimeout(async () => {
      if (!sessions.size && browser) {
        const current = browser;
        browser = null;
        await current.close().catch(() => {});
        log('info', 'browser_idle_closed', 'Browser closed after the idle timeout.');
      }
    }, 60_000);
  }
}

async function closeSession(id, reason = 'closed') {
  const item = sessions.get(id);
  if (!item) return;
  sessions.delete(id);
  await item.context.close().catch(() => {});
  release();
  scheduleIdleClose();
  log('info', 'session_closed', `Session ${id} closed: ${reason}.`);
}

function checkNetwork() {
  return new Promise(resolve => {
    const request = https.request('https://everify.bdris.gov.bd/', { method: 'HEAD', timeout: 5_000 }, response => {
      response.resume();
      resolve(true);
    });
    request.on('timeout', () => request.destroy(new Error('Network check timed out.')));
    request.on('error', () => resolve(false));
    request.end();
  });
}

async function refreshNetworkState() {
  if (closing || networkCheckRunning) return;
  networkCheckRunning = true;
  try {
    if (await checkNetwork()) {
      networkFailures = 0;
      if (!networkAvailable) {
        networkAvailable = true;
        lastBrowserCheck = null;
        if (!serverListening) {
          server.listen(PORT, HOST, () => {
            serverListening = true;
            log('info', 'network_restored', `Internet connectivity restored; Playwright service restarted on http://${HOST}:${PORT}.`);
          });
        } else {
          log('info', 'network_restored', 'Internet connectivity restored; Playwright service is accepting requests again.');
        }
      }
      return;
    }
    networkFailures += 1;
    if (networkAvailable && networkFailures >= NETWORK_FAILURES_BEFORE_PAUSE) {
      networkAvailable = false;
      lastBrowserCheck = { ok: false, checkedAt: Date.now(), error: 'Internet connection is unavailable.' };
      clearTimeout(idleTimer);
      log('warn', 'network_lost', 'Internet connection lost; stopping the HTTP service and closing browser sessions.');
      if (serverListening) {
        serverListening = false;
        server.close();
        server.closeAllConnections();
      }
      await Promise.all([...sessions.keys()].map(id => closeSession(id, 'network disconnected')));
      if (browser) {
        const current = browser;
        browser = null;
        await current.close().catch(() => {});
      }
    }
  } finally {
    networkCheckRunning = false;
  }
}

async function openSession(key = '') {
  if (key) {
    const previous = [...sessions].find(([, item]) => item.key === key);
    if (previous) await closeSession(previous[0], 'replaced by a new request');
  }
  let lastError;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    let context;
    try {
      context = await (await getBrowser()).newContext();
      const page = await context.newPage();
      await page.goto('https://everify.bdris.gov.bd/', { waitUntil: 'domcontentloaded', timeout: 30_000 });
      const id = crypto.randomUUID();
      sessions.set(id, { context, page, key, created: Date.now() });
      return { id, page };
    } catch (error) {
      lastError = error;
      await context?.close().catch(() => {});
      if (attempt < 3) await new Promise(resolve => setTimeout(resolve, attempt * 1000));
    }
  }
  throw lastError || new Error('Browser session could not be created.');
}

async function captchaImage(page) {
  const image = page.locator('img[src*="DefaultCaptcha/Generate"]').first();
  await image.waitFor({ state: 'visible', timeout: 15_000 });
  return image.screenshot();
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    let tooLarge = false;
    req.on('data', chunk => {
      if (tooLarge) return;
      body += chunk.toString('utf8');
      if (Buffer.byteLength(body) > MAX_BODY) {
        tooLarge = true;
        reject(Object.assign(new Error('Request body is too large.'), { status: 413 }));
        req.resume();
      }
    });
    req.on('end', () => {
      if (tooLarge) return;
      try { resolve(JSON.parse(body || '{}')); }
      catch { reject(Object.assign(new Error('Request body must be valid JSON.'), { status: 400 })); }
    });
    req.on('error', error => { if (!tooLarge) reject(error); });
  });
}

async function checkBrowser() {
  const now = Date.now();
  if (!networkAvailable) return { ok: false, checkedAt: now, error: 'Internet connection is unavailable.' };
  if (lastBrowserCheck && now - lastBrowserCheck.checkedAt < BROWSER_HEALTH_INTERVAL_MS) return lastBrowserCheck;
  let context;
  try {
    const instance = await getBrowser();
    context = await instance.newContext();
    const page = await context.newPage();
    await page.setContent('<title>Bot Deck health check</title><main>ready</main>', { waitUntil: 'domcontentloaded', timeout: 5_000 });
    const ready = await page.locator('main').innerText() === 'ready';
    if (!ready) throw new Error('Browser health page did not render.');
    lastBrowserCheck = { ok: true, checkedAt: now, error: '' };
  } catch (error) {
    lastBrowserCheck = { ok: false, checkedAt: now, error: error.message };
  } finally {
    await context?.close().catch(() => {});
    scheduleIdleClose();
  }
  return lastBrowserCheck;
}

async function gracefulShutdown() {
  if (closing) return;
  closing = true;
  clearTimeout(idleTimer);
  for (const item of queue.splice(0)) { clearTimeout(item.timer); item.reject(new Error('Service is shutting down.')); }
  await Promise.all([...sessions.keys()].map(id => closeSession(id, 'service shutdown')));
  if (browser) { const current = browser; browser = null; await current.close().catch(() => {}); }
  server.close(() => { log('info', 'service_stopped', 'Graceful shutdown complete.'); process.exit(0); });
  setTimeout(() => { server.closeAllConnections(); process.exit(0); }, 8_000).unref();
}

const server = http.createServer(async (req, res) => {
  const requestId = crypto.randomUUID();
  const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);
  const origin = req.headers.origin || '';
  const host = String(req.headers.host || '').toLowerCase();
  const isPublicRequest = publicHostHeader(host);
  const isLocalRequest = localHostHeader(host);

  if (!isLocalRequest && !isPublicRequest) return json(res, 403, { error: 'Host is not allowed.', requestId });

  if (origin && !localOrigin(origin) && !(isPublicRequest && publicOrigin(origin))) return json(res, 403, { error: 'Origin is not allowed.', requestId });
  if (origin) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Vary', 'Origin');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type,X-Client-Session');
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  }
  res.setHeader('Cache-Control', 'no-store');

  if (req.method === 'OPTIONS') return origin && (localOrigin(origin) || (isPublicRequest && publicOrigin(origin))) ? (res.writeHead(204).end()) : json(res, 403, { error: 'Origin is not allowed.', requestId });
  if (url.pathname === '/health' && req.method === 'GET') {
    if (!networkAvailable) return json(res, 503, { ok: false, process: 'running', http: 'responding', network: 'offline', browserAutomation: false, active, queued: queue.length });
    if (url.searchParams.get('deep') === '1') {
      const result = await checkBrowser();
      return json(res, result.ok ? 200 : 503, { ok: result.ok, process: 'running', http: 'responding', browserAutomation: result.ok, browserCheckedAt: result.checkedAt, error: result.error || undefined, active, queued: queue.length });
    }
    return json(res, 200, { ok: true, process: 'running', http: 'responding', browserAutomation: lastBrowserCheck?.ok ?? null, browserCheckedAt: lastBrowserCheck?.checkedAt ?? null, active, queued: queue.length });
  }
  if (url.pathname === '/shutdown' && req.method === 'POST') {
    const remote = String(req.socket.remoteAddress || '');
    const loopback = remote === '127.0.0.1' || remote === '::1' || remote === '::ffff:127.0.0.1';
    const supplied = Buffer.from(String(req.headers['x-botdeck-control'] || ''));
    const expected = Buffer.from(CONTROL_TOKEN);
    if (!loopback || !CONTROL_TOKEN || supplied.length !== expected.length || !crypto.timingSafeEqual(supplied, expected)) return json(res, 403, { error: 'Control request is not authorized.', requestId });
    json(res, 200, { ok: true, shuttingDown: true });
    setImmediate(gracefulShutdown);
    return;
  }

  if (url.pathname === '/session' || url.pathname === '/submit') {
    const directSessionNavigation = req.method === 'GET'
      && url.pathname === '/session'
      && !origin
      && (isLocalRequest || isPublicRequest)
      && req.headers['sec-fetch-mode'] === 'navigate'
      && req.headers['sec-fetch-dest'] === 'document';
    if ((!localOrigin(origin) && !(isPublicRequest && publicOrigin(origin)) && !directSessionNavigation) || (!isLocalRequest && !isPublicRequest)) {
      return json(res, 403, { error: 'Local browser origin is required.', requestId });
    }
  }
  if (url.pathname === '/session' && req.method === 'GET') {
    log('info', 'session_request_received', `Request ${requestId} received via ${isPublicRequest ? 'Tailscale Funnel' : 'localhost'}.`);
    let acquired = false;
    let session;
    try {
      const clientKey = String(req.headers['x-client-session'] || '');
      const previous = [...sessions].find(([, item]) => clientKey && item.key === clientKey);
      if (previous) await closeSession(previous[0], 'replaced by a new request');
      await acquire();
      acquired = true;
      if (res.destroyed) { release(); log('info', 'session_request_cancelled', `Request ${requestId} disconnected before the browser session opened.`); return; }
      session = await openSession(clientKey);
      log('info', 'session_created', `Session ${session.id} opened; waiting for CAPTCHA image (request ${requestId}).`);
      const image = await captchaImage(session.page);
      if (res.destroyed) { await closeSession(session.id, 'request disconnected before CAPTCHA response'); return; }
      json(res, 200, { sessionId: session.id, captcha: image.toString('base64'), requestId });
      log('info', 'captcha_returned', `Session ${session.id}: CAPTCHA image returned with HTTP 200 (request ${requestId}).`);
      return;
    } catch (error) {
      if (session) await closeSession(session.id, 'session creation failed');
      else if (acquired) release();
      log('error', 'session_create_failed', `${error.message} (request ${requestId}).`);
      return json(res, error.status || 502, { error: error.message, requestId });
    }
  }
  if (url.pathname === '/submit' && req.method === 'POST') {
    log('info', 'submit_request_received', `Verification submit request ${requestId} received.`);
    if (!String(req.headers['content-type'] || '').toLowerCase().startsWith('application/json')) {
      log('warn', 'submit_rejected', `Request ${requestId}: expected application/json (HTTP 415).`);
      return json(res, 415, { error: 'Content-Type must be application/json.', requestId });
    }
    let data;
    try { data = await readJsonBody(req); }
    catch (error) {
      log('warn', 'submit_rejected', `Request ${requestId}: ${error.message} (HTTP ${error.status || 400}).`);
      return json(res, error.status || 400, { error: error.message, requestId });
    }
    const sessionId = String(data.sessionId || '');
    const item = sessions.get(sessionId);
    if (!item) {
      log('warn', 'submit_rejected', `Request ${requestId}: session ${sessionId || '(missing)'} was not active (HTTP 404).`);
      return json(res, 404, { error: 'Session expired. Create a new session and try again.', requestId });
    }
    const birthNumber = String(data.birthNumber || '');
    const dob = String(data.dob || '');
    const captchaAnswer = String(data.captchaAnswer || '');
    if (!/^\d{1,32}$/.test(birthNumber) || !/^\d{4}-\d{2}-\d{2}$/.test(dob) || captchaAnswer.length < 1 || captchaAnswer.length > 30) {
      log('warn', 'submit_rejected', `Request ${requestId}: invalid input format for session ${sessionId} (HTTP 400).`);
      return json(res, 400, { error: 'Birth number, date, or CAPTCHA format is invalid.', requestId });
    }
    const submitStartedAt = Date.now();
    let submitCompleted = false;
    log('info', 'verification_submit_started', `Submission started for session ${sessionId} (request ${requestId}).`);
    try {
      await item.page.locator('#ubrn').fill(birthNumber);
      await item.page.locator('#BirthDate').fill(dob);
      await item.page.locator('#CaptchaInputText').fill(captchaAnswer);
      const body = item.page.locator('body');
      const initialText = await body.innerText();
      await item.page.locator('input[type="submit"]').evaluate(element => element.click());
      const deadline = Date.now() + 15_000;
      let result = initialText;
      let lastChangeAt = Date.now();
      while (Date.now() < deadline) {
        await item.page.waitForTimeout(300);
        const currentText = await body.innerText();
        if (currentText !== result) {
          result = currentText;
          lastChangeAt = Date.now();
        } else if (result !== initialText && Date.now() - lastChangeAt >= 1_200) {
          break;
        }
      }
      submitCompleted = true;
      log('info', 'verification_submit_completed', `Submission finished for session ${sessionId}; response received in ${Date.now() - submitStartedAt} ms (request ${requestId}).`);
      return json(res, 200, { sessionId, result: result.slice(0, 4_000), requestId });
    } catch (error) {
      log('error', 'verification_submit_failed', `${error.message} (session ${sessionId}, request ${requestId}).`);
      return json(res, 502, { error: 'The verification site could not complete the request. Create a new session and retry.', requestId });
    } finally { await closeSession(sessionId, submitCompleted ? 'verification submitted' : 'verification failed'); }
  }
  return json(res, 404, { error: 'Route not found.', requestId });
});

server.requestTimeout = 15_000;
server.headersTimeout = 10_000;
server.listen(PORT, HOST, () => {
  serverListening = true;
  log('info', 'service_started', `HTTP service listening on http://${HOST}:${PORT}.`);
});
server.on('error', error => { log('error', 'server_error', error.message); process.exitCode = 1; });
process.on('SIGINT', gracefulShutdown);
process.on('SIGTERM', gracefulShutdown);
setInterval(async () => {
  for (const [id, item] of sessions) if (Date.now() - item.created > 600_000) await closeSession(id, 'expired after ten minutes');
}, 30_000).unref();
setInterval(refreshNetworkState, NETWORK_CHECK_INTERVAL_MS).unref();
refreshNetworkState();
