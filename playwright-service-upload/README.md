# Local Playwright service

`playwright-server.js` is the active Node service. It binds to `127.0.0.1:3011`. Local requests must use HTTP localhost; requests through Tailscale Funnel must use the configured HTTPS hostname in `BOTDECK_PUBLIC_HOST`. `/session` and `/submit` validate both Origin and Host. Do not expose this service on a public interface outside the configured Tailscale Funnel.

## Start on Windows

1. Install Node.js and Google Chrome. Sign in to Tailscale, then enable Funnel for this tailnet if prompted.
2. From this folder, run `npm install` once.
3. Start it from Bot Manager or run `start-local.bat` for manual use.
4. Run `tailscale funnel --bg --yes 3011` to configure the HTTPS endpoint. Check `http://127.0.0.1:3011/health` locally, or `https://<this-pc>.ts.net/health` through Tailscale Funnel. Add `?deep=1` to verify Chrome can render a local page; this check does not contact the verification site.

Bot Manager shuts down its managed instance through a random process control token held in the local process state. A manually launched instance has no dashboard control token; stop it from its original terminal with Ctrl+C. Run only one instance on port 3011.

The service contacts `https://everify.bdris.gov.bd/` only after a client requests `/session`. A person must solve the CAPTCHA and submit the verification. Active sessions expire after four minutes; browser contexts close after use, and the browser closes after an idle period.

## Tests

From this folder, run `npm test` with Apache serving the dashboard at `http://localhost/all_bot/` and port 3011 free. It runs a process/API integration check and a browser UI smoke check at 1440, 1280, 1024, 768, 390, and 360 pixels, including Playwright Start/Stop, 20 long-content cards, and log search/filter/copy; an already-running Telegram bot is left untouched and shown as unverified if it was started manually. Set `BOTDECK_API_URL` or `CHROME_PATH` to override those defaults.
