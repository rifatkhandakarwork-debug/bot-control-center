# Bot Manager audit

Reviewed before the reliability changes on 2026-10-28. Scope: dashboard, PHP controller and bot, Playwright service, launch scripts, package manifest/lockfile, and project layout. The project has no root `AGENTS.md`, test suite, or automated test script. The Playwright folder contains its own `.git` directory and dependencies under the Apache document root.

## Findings

### Critical

None identified in the reviewed source.

### High

- **Cross-site access to Playwright actions:** `playwright-server.js` exposes `GET /session`, which opens a browser and visits the configured verification site, but does not validate the request's `Origin`. CORS only limits reading responses; it does not prevent a cross-origin GET from causing this work.
- **Racy process control:** `api.php` has no lock around start/stop. Concurrent start requests can both launch a service before either stores its PID.
- **No service health evidence:** `api.php` equates a matching process command line with Running. It does not check Playwright HTTP/browser health or whether the Telegram polling loop is making progress.
- **Forced shutdown only:** `api.php` terminates services with `Stop-Process -Force`, which can interrupt in-flight work and gives no graceful-shutdown window.
- **Secret in launch command:** The Telegram token is embedded in the encoded PowerShell command text. It is not saved to a file, but can be visible in the PowerShell process command line during launch.

### Medium

- **Stale process state:** PID is checked against the expected script, which helps reject a reused PID for another program. However, an exited process is reported as ready/stopped and the previous failure is not surfaced.
- **Logs are not usable from the dashboard:** stdout/stderr go to files outside the web root, but the UI cannot read them; records have no consistent timestamp/severity format.
- **No CSRF token/custom request guard:** the PHP controller checks loopback `REMOTE_ADDR` and validates service IDs, but state-changing browser requests do not require a same-origin/custom header.
- **Playwright queue/body edge cases:** active sessions are capped at five, but queued requests are not capped. Oversized JSON requests reject the handler without a clear 413/drain path.
- **Web root contains source/dependencies:** the nested `.git`, `node_modules`, bot source, launch scripts, and package metadata are under `htdocs`; no Apache deny rules were present.
- **No automated project tests:** `package.json` defines `start` and `postinstall` only. No PHPUnit or Node test suite is present.

### Low

- `express` is listed as a dependency but the Playwright service uses Node's built-in `http` module.
- PHP/Node launch paths and Chrome's executable path are machine-specific; XAMPP PHP has two fallback locations but Node is resolved through `PATH`.

## Existing behavior confirmed

- Dashboard status refreshes every five seconds without reloading the page.
- PHP controller uses a fixed service allowlist, checks localhost client addresses, and compares a stored PID's command line with the configured script before stopping it.
- Runtime state and logs are placed in the OS temp directory, outside the web root.
- Playwright listens on `127.0.0.1:3011`; its `/health` route reports the Node process and queue counts only.
- Telegram polling catches API exceptions, avoids logging submitted phone/date values, retries after three seconds, and keeps conversation data in memory.
- The configured lookup is explicitly a placeholder; the bot does not perform a real lookup.

## Verification limits at audit time

No services were running during inspection. No Telegram token was available, so successful Telegram API polling cannot be verified without exposing/using the user's credential. No automated test suite exists in the repository.

## Implemented changes and verification

The initial process snapshot was incomplete. A follow-up inspected process ancestry and connections and found Birth Auto Telegram already running from `start_bot.bat`, outside Bot Manager. That live process was not stopped. The controller now discovers it, reports its PID/start time, prevents a duplicate launch, and labels Telegram polling as **unverified** because that legacy process has no dashboard heartbeat.

- **Process control:** Added a shared non-blocking action lock, exact allowlisted process matching, stale PID detection, process-start timestamps, duplicate-instance reporting, and a graceful shutdown path with a bounded force-stop fallback. A manually started process has no graceful control channel; the UI confirms before force-stopping it.
- **Health:** Playwright status checks the PID, HTTP endpoint, and a periodic Chrome render check. Telegram writes a heartbeat after successful polling and a redacted error summary after polling failures.
- **Security:** Telegram token is passed through the PowerShell child environment rather than its encoded command line; it is not written to state/logs. PHP mutations require a loopback/local Host, same-origin `Origin`, and custom request header. Playwright session/submit routes enforce local Origin and Host. Logs are loopback-only and token-redacted. Apache denies direct access to source/dependency folders and the archive.
- **Playwright reliability:** Added graceful control-token shutdown, capped the queue and wait duration, clean 413 handling for oversized payloads, request format checks, structured logs, and an explicit browser health probe.
- **UI:** Refined the existing blue/white design with readable type/spacing tokens, live controller status, four KPIs, searchable/filterable services with a clear empty state, process/health/uptime details, Start/Stop/Restart, and searchable/filterable/copyable logs. Dialogs have keyboard focus management; controls have visible focus and mobile touch targets.
- **Dependencies:** Removed the unused Express dependency. `npm audit --omit=dev` reports zero vulnerabilities.
- **Tests:** Added `tests/integration.cjs` and `tests/ui-smoke.cjs`, exposed through `npm test`. Latest results: integration passed for CSRF/token rejection, concurrent start without duplicates, PID/HTTP/browser health, cross-origin denial, logs, graceful/repeated stop, occupied-port startup failure, stale PID, and endpoint unreachable after stop. UI smoke passed for KPIs, search/clear/filter, Playwright Start/Stop, dialog keyboard flow, log search/severity/copy, 20 long-content cards, and 1440/1280/1024/768/390/360px layouts without horizontal overflow. PHP lint and Node syntax checks passed. Apache returned 403 for direct bot source, Playwright source, archive, and nested `.git` access.

## Remaining verification limits

- The existing unmanaged Telegram bot was intentionally left running. Its process and outbound Telegram connection were observed, but polling freshness cannot be proven without restarting it through Bot Manager with a valid token. The integration suite validates invalid-token rejection only.
- The managed Telegram graceful-stop path is implemented but was not exercised against a real Telegram token/process.
- Browser automation health proves Chrome can launch and render locally; it does not prove the external verification site is reachable or operational.
- Automatic recovery and Windows startup integration remain disabled.
