'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('../playwright-service-upload/node_modules/playwright');

const baseUrl = process.env.BOTDECK_URL || 'http://localhost/all_bot/';
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const viewports = [1440, 1280, 1024, 768, 390, 360];

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chromePath });
  let playwrightStartedByTest = false;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('requestfailed', request => errors.push(`${request.method()} ${request.url()} failed`));
    await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('.service').first().waitFor();
    assert.equal(await page.locator('.service').count(), 2, 'configured services render');
    assert.equal(await page.locator('.loading').count(), 0, 'loading indicator clears after status arrives');
    assert.equal(await page.locator('#total').innerText(), '2', 'total KPI is accurate');
    assert.equal(await page.locator('#errors').innerText(), '0', 'error KPI loads');
    assert.equal(await page.locator('#connection').getAttribute('data-state'), 'online', 'local controller connection is live');
    assert.equal(await page.locator('#search').evaluate(input => input.readOnly), true, 'search stays locked until the user focuses it');
    await page.evaluate(() => {
      document.querySelector('#search').value = 'saved@example.com';
      document.querySelector('#filter').value = 'running';
      window.dispatchEvent(new Event('pageshow'));
    });
    assert.equal(await page.locator('#search').inputValue(), '', 'restored search text is cleared on page show');
    assert.equal(await page.locator('#filter').inputValue(), 'all', 'status filter resets on page show');
    await page.locator('#search').click();
    assert.equal(await page.locator('#search').evaluate(input => input.readOnly), false, 'search unlocks when the user focuses it');
    const birthHealth = await page.locator('.service[data-id="birth"] [data-field="health"]').innerText();
    if (birthHealth !== 'Offline') assert.match(birthHealth, /Polling unverified|Polling ·/, 'Telegram polling health is not overstated');

    await page.locator('#search').fill('telegram');
    assert.equal(await page.locator('.service:visible').count(), 1, 'search filters services');
    await page.locator('#search').fill('no-such-service');
    assert.equal(await page.locator('.empty-state').isVisible(), true, 'no-match state explains empty results');
    await page.locator('.empty-state [data-action="clear-filters"]').click();
    assert.equal(await page.locator('#search').inputValue(), '', 'empty-state action clears search');
    const runningCount = Number(await page.locator('#running').innerText());
    await page.locator('#filter').selectOption('running');
    assert.equal(await page.locator('.service:visible').count(), runningCount, 'running filter uses actual state');
    await page.locator('#filter').selectOption('all');

    await page.locator('.service[data-id="birth"] [data-action="toggle"]').click();
    assert.equal(await page.getByRole('dialog').isVisible(), true, 'Telegram action dialog opens');
    const dialogFirst = await page.locator('#tokenInput').isVisible() ? 'tokenInput' : 'modalConfirm';
    await page.waitForFunction(expected => document.activeElement.id === expected, dialogFirst);
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'modalCancel', 'dialog keyboard focus advances logically');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), dialogFirst, 'dialog focus wraps at the first control');
    await page.keyboard.press('Escape');

    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(baseUrl).origin });
    await page.locator('.service[data-id="playwright"] [data-action="logs"]').click();
    assert.equal(await page.getByRole('dialog').isVisible(), true, 'log dialog opens');
    await page.locator('#logSearch').fill('service listening');
    await page.locator('#logFilter').selectOption('info');
    const visibleLogs = await page.locator('.log-entry').count();
    if (visibleLogs) {
      await page.locator('#logCopy').click();
      assert.match(await page.evaluate(() => navigator.clipboard.readText()), /service listening/i, 'copy action copies filtered log text');
    }
    await page.locator('#logFilter').selectOption('error');
    assert.equal(await page.locator('.log-entry').count(), 0, 'severity filter applies to logs');
    await page.locator('#logsClose').click();

    const playwrightCard = page.locator('.service[data-id="playwright"]');
    const playwrightIsRunning = (await playwrightCard.locator('[data-field="badge"]').innerText()) === 'Running';
    if (!playwrightIsRunning) {
      await playwrightCard.locator('[data-action="toggle"]').click();
      assert.match(await page.locator('#actionTitle').innerText(), /Start Playwright Service/);
      playwrightStartedByTest = true;
      await page.locator('#modalConfirm').click();
      await page.waitForFunction(() => document.querySelector('.service[data-id="playwright"] [data-field="badge"]')?.textContent === 'Running', undefined, { timeout: 20_000 });
      await playwrightCard.locator('[data-action="toggle"]').click();
      assert.match(await page.locator('#actionTitle').innerText(), /Stop Playwright Service/);
      await page.locator('#modalConfirm').click();
      await page.waitForFunction(() => document.querySelector('.service[data-id="playwright"] [data-field="badge"]')?.textContent === 'Stopped', undefined, { timeout: 20_000 });
      playwrightStartedByTest = false;
    }
    if (process.env.BOTDECK_SCREENSHOT) await page.screenshot({ path: process.env.BOTDECK_SCREENSHOT, fullPage: true });

    await page.route('**/api.php?action=status*', async route => {
      const response = await route.fetch();
      const payload = await response.json();
      const fixtures = [...payload.bots];
      while (fixtures.length < 20) {
        const index = fixtures.length + 1;
        fixtures.push({
          ...payload.bots[1],
          id: `layout-fixture-${index}`,
          name: `Long service name ${index} ${'verification-worker-'.repeat(3)}`,
          description: `A longer description used to verify card alignment ${'and responsive text wrapping '.repeat(3)}`,
          endpoint: `http://127.0.0.1:3011/${'long-endpoint-segment-'.repeat(10)}${index}`,
          running: false,
          state: 'stopped',
          pid: null,
          startedAt: 0,
          lastStartedAt: payload.checkedAt,
          health: { status: 'offline', checkedAt: 0, lastError: '' },
        });
      }
      payload.bots = fixtures;
      route.fulfill({ response, body: JSON.stringify(payload) });
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('.service').nth(19).waitFor();
    assert.equal(await page.locator('#total').innerText(), '20', 'service list reconciles added cards');

    for (const width of viewports) {
      await page.setViewportSize({ width, height: 900 });
      const layout = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.service')];
        const actions = [...document.querySelectorAll('.service .actions')];
        return {
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          columns: getComputedStyle(document.querySelector('.services')).gridTemplateColumns.split(/\s+/).length,
          cardsInside: cards.every(card => { const rect = card.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; }),
          actionsInside: actions.every(actionsRow => { const rect = actionsRow.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; }),
          tinyFieldText: [...document.querySelectorAll('.detail-label')].some(label => parseFloat(getComputedStyle(label).fontSize) < 10),
        };
      });
      assert.equal(layout.overflow, false, `${width}px layout has no horizontal page overflow`);
      assert.equal(layout.cardsInside, true, `${width}px service cards fit the viewport`);
      assert.equal(layout.actionsInside, true, `${width}px action controls are not clipped`);
      assert.equal(layout.tinyFieldText, false, `${width}px metadata labels stay readable`);
      assert.equal(layout.columns, width <= 820 ? 1 : 2, `${width}px service grid uses the intended columns`);
      if (width === 390 && process.env.BOTDECK_MOBILE_SCREENSHOT) await page.screenshot({ path: process.env.BOTDECK_MOBILE_SCREENSHOT, fullPage: true });
    }

    assert.deepEqual(errors, [], 'browser console and network have no errors');
    console.log('UI smoke passed: live KPIs, search/clear/filter, Playwright Start/Stop, dialogs, log search/severity/copy, 20 long-content cards, and 1440/1280/1024/768/390/360px layouts.');
  } finally {
    if (playwrightStartedByTest) {
      const stopUrl = new URL('api.php?action=stop', baseUrl);
      await fetch(stopUrl, { method: 'POST', headers: { Origin: stopUrl.origin, 'X-BotDeck-Request': '1', 'Content-Type': 'application/json' }, body: JSON.stringify({ id: 'playwright' }), signal: AbortSignal.timeout(40_000) }).catch(() => {});
    }
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
