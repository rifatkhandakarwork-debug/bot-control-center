# Bot Control Center

A local XAMPP dashboard for the services in this workspace. It manages only the two entries explicitly listed in `api.php`; it does not accept shell commands or service paths from the browser.

## Start the dashboard

1. Start Apache in XAMPP.
2. Open `http://localhost/all_bot/` on this computer.
3. Use **Start**, **Stop**, **Restart**, or **Logs** on a service card.
4. The Telegram bot asks for its BotFather token whenever it starts or restarts. The token is passed to the child process environment and is not saved in the launch command, log, or state file.

## One-command setup on Windows

Open PowerShell on the other Windows PC and run:

```powershell
$repo = 'rifatkhandakarwork-debug/bot-control-center'; $script = (Invoke-WebRequest "https://raw.githubusercontent.com/$repo/main/install-windows.ps1").Content; & ([scriptblock]::Create($script)) -Repository $repo
```

The setup checks for XAMPP, Node.js, Google Chrome, and Tailscale, installing missing programs through Windows Package Manager (`winget`). If `winget` is missing, it tries to register or bootstrap Microsoft's App Installer/WinGet first. If Tailscale is not signed in, setup opens its app and waits while you connect this PC. It then downloads the repository into XAMPP's `htdocs\all_bot`, installs Playwright dependencies and Chromium, starts Apache and Playwright, configures Tailscale Funnel for port 3011, and prints this PC's Tailscale HTTPS link. It also opens the dashboard. The Telegram bot remains stopped until you enter its token in the dashboard. Windows may ask for administrator approval when installing system software. If automatic WinGet setup fails, install/update **App Installer** from Microsoft Store and rerun the command. The destination must not already exist; move/remove an older copy before running setup again. A private GitHub repository needs authentication and is not supported by this one-command download.

The script supports XAMPP installed at `C:\xampp` or `D:\Xampp`. It does not configure port conflicts or install XAMPP PHP extensions that are disabled; if Apache cannot start, resolve the port conflict in the XAMPP Control Panel. The Telegram bot token is still entered in the dashboard when starting that bot.

The PHP controller requires PHP 8+, cURL, `proc_open`, `mbstring`, Windows PowerShell, and the usual XAMPP PHP path (`D:\Xampp\php\php.exe` or `C:\xampp\php\php.exe`). The Playwright service requires Node.js, the installed npm dependencies, and Google Chrome at `C:\Program Files\Google\Chrome\Application\chrome.exe`.

Install/update Playwright dependencies from its folder with `npm install`. Its server listens on `127.0.0.1:3011`; setup configures Tailscale Funnel to forward HTTPS to that local port and saves this PC's `*.ts.net` hostname for the dashboard. The Funnel link is reachable over the public internet. Funnel needs Tailscale MagicDNS, HTTPS certificates, and Funnel permission in the tailnet policy; the first Funnel setup may ask a tailnet admin to enable it. The service itself still binds only to loopback. Apache access to the service source folders is denied by their `.htaccess` files. The dashboard API also requires a loopback client, a local Host header, and a custom same-origin request header for controls/logs.

## Dashboard health indicators

- **Telegram:** Running means the PHP process matches the configured bot script. Health becomes **Polling** after a successful Telegram `getUpdates` response. Polling/API errors and stale heartbeats are shown separately. A valid token is required; the dashboard cannot verify a real Telegram account without it.
- **Playwright:** Running means the Node PID matches the configured script. Health checks the local HTTP endpoint, then periodically launches Chrome and renders a small local page to verify browser automation. This check does not contact the external verification site. That site is contacted only when a client asks the service for a browser session.
- Process state is checked every dashboard refresh. Playwright browser health defaults to a 30-second interval and a 2-second HTTP timeout. Set `BOTDECK_HEALTH_INTERVAL` (10–300 seconds) or `BOTDECK_HEALTH_TIMEOUT` (1–8 seconds) in the Apache environment to change them; restart Apache after changing its environment.
- Stop requests first ask each service to shut down cleanly. If it does not exit within its timeout, the controller force-stops only the PID whose command line still matches that service script.

Runtime state and stdout/stderr logs live in the current Windows user's temp folder under `botdeck-<project-hash>`, outside Apache's document root. Logs are available in each service card. They are redacted before the dashboard returns them.

## Tests and checks

With Apache running, Chrome available, and port 3011 free, run from `playwright-service-upload`:

```powershell
npm test
```

The integration check exercises API security, concurrent start requests, PID/status checks, Playwright HTTP and browser health, CORS rejection, logs, clean and repeated stop, an occupied-port startup failure, and stale-PID handling. The UI smoke check uses Chrome at the path above (or `CHROME_PATH`) and verifies metrics, search/clear/filter, Playwright Start/Stop, dialogs, searchable/filterable/copyable logs, 20 long-content service cards, browser console, and layouts at 1440, 1280, 1024, 768, 390, and 360 pixels. Set `BOTDECK_API_URL` if the dashboard is hosted at a different local URL.

The Telegram bot test only checks invalid-token rejection; do not put a real bot token in test logs. Live Telegram polling requires a private valid token and is not exercised automatically.

## Troubleshooting

- **Controller unavailable:** Start Apache and check `http://localhost/all_bot/api.php?action=status`.
- **PHP will not start:** Confirm PHP 8+, cURL, `proc_open`, and the configured XAMPP PHP executable.
- **Playwright startup fails:** Check the service Logs action, make sure port 3011 is free, install dependencies with `npm install`, and verify the Chrome executable path.
- **Playwright says endpoint unreachable:** Confirm nothing else is using port 3011, that the service listens on loopback, and that Tailscale Funnel forwards the configured hostname to port 3011.
- **No Tailscale link appears:** Sign in to Tailscale, then confirm MagicDNS, HTTPS, and Funnel are enabled for the tailnet. Rerun `install-windows.ps1` after access is enabled.
- **Telegram polling error:** Check the token with BotFather and ensure the PHP host can reach `api.telegram.org`.
- **Controls return 403:** Open the dashboard through `http://localhost/all_bot/` or `http://127.0.0.1/all_bot/` and use the same host consistently; remote/LAN access is intentionally blocked.

Automatic restart/recovery and Windows startup integration are not enabled.
