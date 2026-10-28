'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

const apiUrl = new URL(process.env.BOTDECK_API_URL || 'http://localhost/all_bot/api.php');
const origin = apiUrl.origin;
const headers = { Origin: origin, 'X-BotDeck-Request': '1', 'Content-Type': 'application/json' };
const root = path.resolve(__dirname, '..');
const stateDir = path.join(os.tmpdir(), `botdeck-${crypto.createHash('sha256').update(root).digest('hex').slice(0, 12)}`);
const stateFile = path.join(stateDir, 'playwright.json');

async function api(action, body, extraHeaders = headers) {
  const endpoint = new URL(apiUrl);
  endpoint.searchParams.set('action', action);
  if (action === 'logs') endpoint.searchParams.set('id', 'playwright');
  const response = await fetch(endpoint, {
    method: body ? 'POST' : 'GET',
    headers: extraHeaders,
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(action === 'stop' ? 40_000 : 20_000),
  });
  let data;
  try { data = await response.json(); } catch { throw new Error(`${action}: controller returned invalid JSON (${response.status})`); }
  return { status: response.status, data };
}

(async () => {
  const originalState = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
  let startedByTest = false;
  try {
    const initial = await api('status');
    assert.equal(initial.status, 200, 'local status endpoint responds');
    const initialService = initial.data.bots.find(bot => bot.id === 'playwright');
    assert.ok(initialService, 'Playwright is configured');
    assert.equal(initialService.running, false, 'Playwright is stopped before lifecycle test');
    const birth = initial.data.bots.find(bot => bot.id === 'birth');
    if (birth.running) {
      assert.equal(birth.managed, false, 'an existing manually launched Telegram bot is not mistaken for a managed process');
      assert.equal(birth.health.status, 'unverified', 'polling is not claimed healthy without a dashboard heartbeat');
    }

    const csrf = await api('stop', { id: 'playwright' }, { 'Content-Type': 'application/json' });
    assert.equal(csrf.status, 403, 'state change without same-origin request marker is rejected');
    const badToken = await api('start', { id: 'birth', token: 'not-a-valid-token' });
    assert.equal(badToken.status, 400, 'invalid Telegram token is rejected before launch');

    const starts = await Promise.all([api('start', { id: 'playwright' }), api('start', { id: 'playwright' })]);
    startedByTest = starts.some(result => result.status === 200);
    assert.ok(starts.every(result => [200, 409].includes(result.status)), 'concurrent starts are serialized or rejected cleanly');
    assert.ok(starts.some(result => result.status === 200), 'one concurrent start succeeds');
    const running = await api('status');
    const service = running.data.bots.find(bot => bot.id === 'playwright');
    assert.equal(service.running, true, 'PID and script command line confirm the process');
    assert.ok(service.pid > 0, 'process ID is reported');
    assert.equal(service.instances, 1, 'concurrent requests do not create duplicate service processes');
    assert.equal(service.health.status, 'healthy', 'HTTP endpoint and real browser page health check pass');
    assert.equal(service.health.http, 'responding');
    assert.equal(service.health.browserAutomation, true);

    const hostile = await fetch('http://127.0.0.1:3011/session', { headers: { Origin: 'https://example.invalid' } });
    assert.equal(hostile.status, 403, 'Playwright rejects a cross-origin session request');
    const oversized = await fetch('http://127.0.0.1:3011/submit', { method: 'POST', headers: { Origin: origin, Host: '127.0.0.1:3011', 'Content-Type': 'application/json' }, body: 'x'.repeat(10_100) });
    assert.equal(oversized.status, 413, 'oversized request body is rejected cleanly');
    const logResult = await api('logs', null, { Origin: origin, 'X-BotDeck-Request': '1' });
    assert.equal(logResult.status, 200, 'local log viewer endpoint responds');
    assert.ok(Array.isArray(logResult.data.entries), 'log endpoint returns structured entries');

    const stop = await api('stop', { id: 'playwright' });
    assert.equal(stop.status, 200, 'service stop succeeds');
    assert.equal(stop.data.graceful, true, 'service exits through its authenticated graceful-shutdown route');
    const repeatedStop = await api('stop', { id: 'playwright' });
    assert.equal(repeatedStop.status, 200, 'repeated stop is safe');
    let endpointUnavailable = false;
    try { await fetch('http://127.0.0.1:3011/health', { signal: AbortSignal.timeout(1_000) }); }
    catch { endpointUnavailable = true; }
    assert.equal(endpointUnavailable, true, 'Playwright endpoint is unreachable after stop');
    startedByTest = false;

    const blocker = http.createServer((_req, res) => res.end('port reserved for startup-failure check'));
    await new Promise((resolve, reject) => { blocker.once('error', reject); blocker.listen(3011, '127.0.0.1', resolve); });
    try {
      const failed = await api('start', { id: 'playwright' });
      assert.equal(failed.status, 500, 'occupied port is reported as a failed startup');
      const failedState = await api('status');
      assert.equal(failedState.data.bots.find(bot => bot.id === 'playwright').state, 'error', 'failed startup is visible in status');
      await api('stop', { id: 'playwright' });
    } finally { await new Promise(resolve => blocker.close(resolve)); }

    fs.writeFileSync(stateFile, JSON.stringify({ pid: 2147483647, startedAt: 1, lastStartedAt: 1, stoppedByUser: false, transition: '', lastError: '' }));
    const stale = await api('status');
    assert.equal(stale.data.bots.find(bot => bot.id === 'playwright').state, 'error', 'stale PID is detected and surfaced');
    await api('stop', { id: 'playwright' });
    console.log('Integration passed: CSRF/token rejection, concurrent start, PID/HTTP/browser health, CORS, logs, graceful/repeated stop, failed startup, and stale PID.');
  } finally {
    if (startedByTest) await api('stop', { id: 'playwright' }).catch(() => {});
    if (originalState === null) fs.rmSync(stateFile, { force: true });
    else fs.writeFileSync(stateFile, originalState);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
