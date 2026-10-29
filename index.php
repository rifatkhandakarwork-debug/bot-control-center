<?php
declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4f6fa">
  <title>Bot Control Center</title>
  <style>
    :root {
      color-scheme: light;
      --ink: #18263a;
      --muted: #53657a;
      --quiet: #617286;
      --line: #dfe6ee;
      --line-soft: #e9edf2;
      --panel: #fff;
      --canvas: #f4f6fa;
      --blue: #315fce;
      --blue-hover: #244fae;
      --green: #126b48;
      --amber: #815309;
      --red: #a92e2b;
      --shadow: 0 2px 8px #1b2d430c;
      --space-1: 4px;
      --space-2: 8px;
      --space-3: 12px;
      --space-4: 16px;
      --space-5: 24px;
      --space-6: 32px;
      --radius-sm: 7px;
      --radius-md: 10px;
      --control-height: 40px;
    }

    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background: var(--canvas);
      color: var(--ink);
      font: 14px/1.5 Inter, "Segoe UI", Arial, sans-serif;
    }
    button, input, select { font: inherit; }
    button { color: inherit; }
    .app { max-width: 1180px; margin: 0 auto; padding: 24px 28px 40px; }
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    .topbar {
      min-height: 48px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: var(--space-4);
      border-bottom: 1px solid var(--line);
      padding-bottom: var(--space-3);
      margin-bottom: 28px;
    }
    .brand { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 720; letter-spacing: -.25px; }
    .brand-icon {
      width: 36px;
      height: 36px;
      display: grid;
      place-items: center;
      border-radius: var(--radius-sm);
      background: #203b70;
      color: #fff;
      font-weight: 800;
    }
    .connection {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      padding: 7px 11px;
      border: 1px solid var(--line);
      border-radius: 999px;
      background: #fff;
      color: var(--muted);
      font-size: 12px;
      font-weight: 650;
      white-space: nowrap;
    }
    .connection-dot { width: 8px; height: 8px; border-radius: 50%; background: #7c8998; }
    .connection.online .connection-dot { background: #16845a; box-shadow: 0 0 0 3px #16845a1b; }
    .connection.offline { color: var(--red); border-color: #e8c9c7; background: #fff9f8; }
    .connection.offline .connection-dot { background: #c84c47; }

    .heading {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: var(--space-4);
      margin-bottom: 20px;
    }
    .eyebrow { color: var(--blue); font-size: 11px; font-weight: 700; letter-spacing: .55px; }
    .heading h1 { margin: 3px 0 5px; font-size: 30px; line-height: 1.2; letter-spacing: -.9px; }
    .heading p { margin: 0; color: var(--muted); font-size: 14px; }
    .heading-actions { display: flex; align-items: center; gap: var(--space-3); }
    .updated { color: var(--muted); font-size: 12px; white-space: nowrap; }

    .overview { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--space-3); margin-bottom: 28px; }
    .metric {
      position: relative;
      min-width: 0;
      overflow: hidden;
      padding: 15px 16px;
      border: 1px solid var(--line);
      border-radius: var(--radius-md);
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .metric::before { position: absolute; inset: 0 auto 0 0; width: 3px; background: #8998ab; content: ""; }
    .metric:nth-child(2)::before { background: #22845f; }
    .metric:nth-child(3)::before { background: #bd852a; }
    .metric:nth-child(4)::before { background: #c84c47; }
    .metric-label { color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .35px; }
    .metric-value { margin-top: 2px; font-size: 27px; font-weight: 740; line-height: 1.25; letter-spacing: -.6px; font-variant-numeric: tabular-nums; }
    .metric-foot { color: var(--quiet); font-size: 12px; }

    .list-head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-3); }
    .list-title { display: flex; align-items: baseline; gap: var(--space-2); min-width: 0; }
    .list-title h2 { margin: 0; font-size: 16px; line-height: 1.3; letter-spacing: -.15px; }
    .result-count { color: var(--quiet); font-size: 12px; white-space: nowrap; }
    .list-tools { display: flex; align-items: center; gap: var(--space-2); }
    .search-wrap { position: relative; }
    .search-wrap::before {
      position: absolute;
      top: 12px;
      left: 13px;
      width: 11px;
      height: 11px;
      border: 1.6px solid #65768b;
      border-radius: 50%;
      content: "";
      pointer-events: none;
    }
    .search-wrap::after {
      position: absolute;
      top: 23px;
      left: 24px;
      width: 6px;
      height: 1.6px;
      transform: rotate(45deg);
      transform-origin: left center;
      background: #65768b;
      content: "";
      pointer-events: none;
    }
    .search, .filter, .refresh, .clear-filter, .log-search, .log-filter {
      min-height: var(--control-height);
      border: 1px solid #cbd5e1;
      border-radius: var(--radius-sm);
      background: #fff;
      color: #35465b;
      font-size: 13px;
    }
    .search { width: 230px; padding: 0 12px 0 36px; outline: none; }
    .filter { min-width: 136px; padding: 0 11px; cursor: pointer; }
    .refresh, .clear-filter { padding: 0 13px; font-weight: 650; cursor: pointer; }
    .clear-filter { color: var(--blue); }
    .clear-filter[hidden] { display: none; }
    .search:focus, .filter:focus, .log-search:focus, .log-filter:focus { border-color: #6686d5; outline: none; box-shadow: 0 0 0 3px #315fce1b; }
    .refresh:hover, .clear-filter:hover { border-color: #aabbd3; background: #f8faff; }

    .services { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
    .service {
      min-width: 0;
      padding: 18px;
      border: 1px solid var(--line);
      border-radius: var(--radius-md);
      background: var(--panel);
      box-shadow: var(--shadow);
      transition: border-color .16s ease, box-shadow .16s ease;
    }
    .service:hover { border-color: #c8d5e5; box-shadow: 0 5px 16px #1b2d4312; }
    .service[hidden] { display: none; }
    .service-top { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
    .service-heading { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .service-heading > div { min-width: 0; }
    .service-avatar {
      width: 40px;
      height: 40px;
      flex: 0 0 40px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      background: #edf2ff;
      color: #315fce;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .2px;
    }
    .service-avatar.purple { background: #f1edff; color: #7655bf; }
    .service-title { overflow-wrap: anywhere; font-size: 16px; font-weight: 720; line-height: 1.3; letter-spacing: -.15px; }
    .service-kind { margin-top: 2px; color: var(--muted); font-size: 12px; }
    .badge {
      display: inline-flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 7px;
      padding: 6px 9px;
      border-radius: 999px;
      background: #f0f2f5;
      color: #53657a;
      font-size: 11px;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge::before { width: 7px; height: 7px; border-radius: 50%; background: #8996a5; content: ""; }
    .badge.running { background: #eaf7f0; color: var(--green); }
    .badge.running::before { background: #21865f; }
    .badge.starting, .badge.stopping { background: #fff5df; color: var(--amber); }
    .badge.starting::before, .badge.stopping::before { background: #c78b22; animation: pulse 1.2s ease-in-out infinite; }
    .badge.error { background: #fff0ef; color: var(--red); }
    .badge.error::before { background: #c84c47; }
    .badge.degraded { background: #fff5df; color: var(--amber); }
    .badge.degraded::before { background: #c78b22; }
    .badge.unknown { background: #f0f2f5; color: var(--muted); }
    .description { min-height: 40px; margin: 13px 0; color: #43566d; font-size: 13px; line-height: 1.5; }
    .details { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 13px 15px; padding-top: 13px; border-top: 1px solid var(--line-soft); }
    .detail-label { display: block; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .25px; }
    .detail-value { display: block; margin-top: 3px; color: #30435a; font-size: 13px; font-weight: 620; line-height: 1.4; overflow-wrap: anywhere; }
    .detail-value.mono { font-family: Consolas, "SFMono-Regular", monospace; font-size: 12px; font-weight: 500; }
    .health-good { color: var(--green); }
    .health-warn { color: var(--amber); }
    .health-bad { color: var(--red); }
    .service-bottom { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line-soft); }
    .event { min-width: 0; color: var(--muted); font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    .actions { display: flex; flex: 0 0 auto; gap: var(--space-2); }
    .btn {
      min-width: 72px;
      min-height: 38px;
      padding: 0 11px;
      border: 0;
      border-radius: var(--radius-sm);
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .btn.start { background: var(--blue); color: #fff; }
    .btn.start:hover { background: var(--blue-hover); }
    .btn.stop { background: #edf0f4; color: #35465b; }
    .btn.stop:hover, .btn.cancel:hover, .btn.logs:hover { background: #e3e8ef; }
    .btn.restart { background: #fff4df; color: var(--amber); }
    .btn.restart:hover { background: #ffebc2; }
    .btn.logs, .btn.cancel { background: #f0f2f5; color: #35465b; }
    .btn.confirm-danger { background: #b93834; color: #fff; }
    .btn.confirm-danger:hover { background: #a42e2a; }
    .btn:disabled, .refresh:disabled, .clear-filter:disabled { opacity: .56; cursor: wait; }
    button:focus-visible { outline: 3px solid #315fce55; outline-offset: 2px; }

    .loading, .empty-state, .load-error {
      grid-column: 1 / -1;
      padding: 28px 20px;
      border: 1px dashed #cbd5e1;
      border-radius: var(--radius-md);
      background: #fff;
      color: var(--muted);
      font-size: 13px;
      text-align: center;
    }
    .loading { display: flex; align-items: center; justify-content: center; gap: 10px; }
    .spinner { width: 16px; height: 16px; border: 2px solid #d6dfec; border-top-color: var(--blue); border-radius: 50%; animation: spin .8s linear infinite; }
    .empty-state strong { display: block; margin-bottom: 4px; color: #30435a; font-size: 15px; }
    .empty-state p { margin: 0 0 13px; }
    .load-error { border-color: #e7c4c2; background: #fff9f8; color: var(--red); }
    .footer { margin-top: 17px; color: var(--muted); font-size: 11px; }

    .toast {
      position: fixed;
      right: 20px;
      bottom: 20px;
      z-index: 20;
      max-width: min(440px, calc(100vw - 40px));
      padding: 12px 15px;
      border: 1px solid var(--line);
      border-radius: 9px;
      background: #fff;
      color: #26364b;
      box-shadow: 0 8px 28px #1b2d431c;
      font-size: 13px;
      display: none;
    }
    .toast.show { display: block; }
    .modal { position: fixed; inset: 0; z-index: 30; display: none; place-items: center; padding: 18px; background: #14203367; }
    .modal.show { display: grid; }
    .dialog { width: min(520px, 100%); max-height: min(82vh, 720px); overflow: auto; padding: 22px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 20px 60px #15203330; }
    .dialog h2 { margin: 0 0 6px; font-size: 19px; letter-spacing: -.2px; }
    .dialog > p { margin: 0 0 16px; color: var(--muted); font-size: 13px; }
    .dialog input, .log-filter { width: 100%; min-height: 42px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); background: #fff; color: #35465b; font-size: 13px; outline: none; }
    .dialog input:focus { border-color: #6686d5; box-shadow: 0 0 0 3px #315fce1b; }
    .dialog-actions { display: flex; justify-content: flex-end; gap: var(--space-2); margin-top: 18px; }
    .btn.modal-button { min-height: 40px; padding: 0 14px; }
    .log-tools { display: grid; grid-template-columns: minmax(0, 1fr) 130px auto; align-items: center; gap: var(--space-2); margin-bottom: 12px; }
    .log-search { min-width: 0; min-height: 40px; padding: 0 11px; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); color: #35465b; font-size: 13px; outline: none; }
    .log-filter { min-height: 40px; padding: 0 8px; font-size: 12px; }
    .log-copy { min-width: 106px; min-height: 40px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); background: #fff; color: #35465b; font-size: 12px; font-weight: 650; cursor: pointer; }
    .log-copy:hover { background: #f6f8fb; }
    .log-list { display: flex; max-height: 48vh; flex-direction: column; gap: var(--space-2); overflow: auto; }
    .log-entry { min-width: 0; padding: 10px 11px; border: 1px solid var(--line-soft); border-radius: var(--radius-sm); background: #fbfcfe; }
    .log-meta { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: 11px; }
    .log-level { font-weight: 750; text-transform: capitalize; }
    .log-level.error, .log-level.warning { color: var(--red); }
    .log-message { margin-top: 4px; color: #30435a; font: 12px/1.5 Consolas, "SFMono-Regular", monospace; overflow-wrap: anywhere; white-space: pre-wrap; }
    .log-empty { padding: 24px 12px; color: var(--muted); font-size: 13px; text-align: center; }

    @keyframes pulse { 50% { opacity: .4; } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 820px) {
      .app { padding: 20px 20px 32px; }
      .services { grid-template-columns: 1fr; }
      .overview { gap: 10px; }
      .metric { padding: 14px; }
    }
    @media (max-width: 560px) {
      .app { padding: 16px 14px 28px; }
      .topbar { margin-bottom: 22px; }
      .brand { font-size: 15px; }
      .connection { gap: 7px; padding: 7px 9px; font-size: 11px; }
      .heading { align-items: flex-start; flex-direction: column; gap: 12px; }
      .heading h1 { font-size: 27px; }
      .heading-actions { width: 100%; justify-content: space-between; }
      .overview { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin-bottom: 24px; }
      .metric { padding: 12px 13px; }
      .metric-value { font-size: 24px; }
      .list-head { align-items: flex-start; flex-direction: column; }
      .list-tools { width: 100%; flex-wrap: wrap; }
      .search-wrap { flex: 1 1 100%; }
      .search { width: 100%; }
      .filter { flex: 1; }
      .clear-filter { flex: 0 0 auto; }
      .service { padding: 15px; }
      .details { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
      .service-bottom { align-items: flex-start; flex-direction: column; }
      .actions { width: 100%; }
      .actions .btn { flex: 1; min-width: 0; min-height: 44px; padding: 0 8px; }
      .log-tools { grid-template-columns: minmax(0, 1fr) 112px; }
      .log-search { grid-column: 1 / -1; }
      .log-copy { min-height: 40px; }
    }
    @media (max-width: 380px) {
      .app { padding-right: 12px; padding-left: 12px; }
      .brand { gap: 8px; font-size: 14px; }
      .connection { font-size: 10px; }
      .service-top { align-items: flex-start; }
      .badge { padding: 5px 7px; font-size: 10px; }
      .service-title { font-size: 15px; }
      .actions { gap: 6px; }
      .actions .btn { font-size: 11px; }
    }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
    }
  </style>
</head>
<body>
  <main class="app">
    <header class="topbar">
      <div class="brand"><span class="brand-icon" aria-hidden="true">B</span><span>Bot Control Center</span></div>
      <div class="connection" id="connection" data-state="checking" role="status"><span class="connection-dot" aria-hidden="true"></span><span id="connectionText">Connecting to local controller</span></div>
    </header>

    <section class="heading">
      <div>
        <div class="eyebrow">Workspace / Services</div>
        <h1>Bot operations</h1>
        <p>Monitor process health and manage your local services.</p>
      </div>
      <div class="heading-actions">
        <span class="updated" id="updated">Checking services…</span>
        <button class="refresh" id="refresh" type="button">Refresh</button>
      </div>
    </section>

    <section class="overview" aria-label="Service summary">
      <div class="metric"><div class="metric-label">Total services</div><div class="metric-value" id="total">—</div><div class="metric-foot">Configured in this workspace</div></div>
      <div class="metric"><div class="metric-label">Processes alive</div><div class="metric-value" id="running">—</div><div class="metric-foot">May need service attention</div></div>
      <div class="metric"><div class="metric-label">Stopped</div><div class="metric-value" id="stopped">—</div><div class="metric-foot">Not currently running</div></div>
      <div class="metric"><div class="metric-label">Errors</div><div class="metric-value" id="errors">—</div><div class="metric-foot">Needs attention</div></div>
    </section>

    <section aria-labelledby="servicesTitle">
      <div class="list-head">
        <div class="list-title"><h2 id="servicesTitle">Configured services</h2><span class="result-count" id="resultCount"></span></div>
        <div class="list-tools">
          <label class="search-wrap"><span class="sr-only">Search services</span><input class="search" id="search" name="service-filter-query" type="search" placeholder="Search services" autocomplete="off" autocapitalize="off" spellcheck="false" readonly></label>
          <label><span class="sr-only">Filter services by status</span><select class="filter" id="filter"><option value="all">All statuses</option><option value="running">Running</option><option value="stopped">Stopped</option><option value="error">Errors</option></select></label>
          <button class="clear-filter" id="clearFilters" type="button" hidden>Clear filters</button>
        </div>
      </div>
      <div class="services" id="services"><div class="loading"><span class="spinner" aria-hidden="true"></span>Connecting to the local controller…</div></div>
    </section>

    <footer class="footer">Status refreshes every 5 seconds. Process controls are restricted to this computer.</footer>
  </main>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <div class="modal" id="actionModal" aria-hidden="true">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="actionTitle" aria-describedby="actionText">
      <h2 id="actionTitle"></h2>
      <p id="actionText"></p>
      <label class="sr-only" for="tokenInput">Telegram bot token</label>
      <input id="tokenInput" type="password" placeholder="Telegram bot token" autocomplete="off" hidden>
      <div class="dialog-actions"><button class="btn cancel modal-button" id="modalCancel" type="button">Cancel</button><button class="btn confirm-danger modal-button" id="modalConfirm" type="button">Confirm</button></div>
    </section>
  </div>

  <div class="modal" id="logsModal" aria-hidden="true">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="logsTitle" aria-describedby="logsDescription">
      <h2 id="logsTitle">Service logs</h2>
      <p id="logsDescription">Recent process output. Secrets are filtered before display.</p>
      <div class="log-tools">
        <label><span class="sr-only">Search log messages</span><input class="log-search" id="logSearch" type="search" placeholder="Search logs"></label>
        <label><span class="sr-only">Filter logs by severity</span><select class="log-filter" id="logFilter"><option value="all">All levels</option><option value="error">Error</option><option value="warning">Warning</option><option value="info">Info</option><option value="debug">Debug</option></select></label>
        <button class="log-copy" id="logCopy" type="button">Copy visible</button>
      </div>
      <div class="log-list" id="logList" aria-live="polite"><div class="log-empty">Choose Logs on a service to view output.</div></div>
      <div class="dialog-actions"><button class="btn cancel modal-button" id="logsClose" type="button">Close</button></div>
    </section>
  </div>

  <script>
    const $ = id => document.getElementById(id);
    let currentBots = [];
    let currentLogs = [];
    let polling = false;
    let actionBusy = false;
    let modalAction = null;
    let actionFocus = null;
    let logsFocus = null;
    let toastTimer = null;

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    }

    function showToast(message, isError = false) {
      const toast = $('toast');
      toast.textContent = message;
      toast.style.borderColor = isError ? '#e7c4c2' : '#c8e0d2';
      toast.style.color = isError ? '#a92e2b' : '#126b48';
      toast.classList.add('show');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.classList.remove('show'), 4200);
    }

    function setConnection(online) {
      const connection = $('connection');
      connection.className = `connection ${online ? 'online' : 'offline'}`;
      connection.dataset.state = online ? 'online' : 'offline';
      $('connectionText').textContent = online ? 'Local controller online' : 'Local controller unavailable';
    }

    function relativeTime(timestamp) {
      if (!timestamp) return '—';
      const seconds = Math.max(0, Math.floor(Date.now() / 1000 - timestamp));
      if (seconds < 60) return `${seconds}s ago`;
      if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
      if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m ago`;
      return `${Math.floor(seconds / 86400)}d ${Math.floor(seconds % 86400 / 3600)}h ago`;
    }

    function uptime(timestamp) {
      if (!timestamp) return '—';
      let seconds = Math.max(0, Math.floor(Date.now() / 1000 - timestamp));
      const days = Math.floor(seconds / 86400);
      seconds %= 86400;
      const hours = Math.floor(seconds / 3600);
      seconds %= 3600;
      const minutes = Math.floor(seconds / 60);
      return days ? `${days}d ${hours}h` : hours ? `${hours}h ${minutes}m` : `${minutes}m`;
    }

    function formattedTime(timestamp) {
      return timestamp ? new Date(timestamp * 1000).toLocaleString() : 'Never';
    }

    function healthInfo(bot) {
      if (!bot.running) return { label: bot.state === 'error' ? 'Unavailable' : 'Offline', className: bot.state === 'error' ? 'health-bad' : '' };
      const health = bot.health || {};
      if (bot.id === 'birth') {
        if (health.status === 'healthy') return { label: `Polling · ${relativeTime(health.checkedAt)}`, className: 'health-good' };
        if (health.status === 'error' || health.status === 'stale') return { label: health.status === 'stale' ? 'Polling stale' : 'Poll error', className: 'health-bad' };
        if (health.status === 'unverified') return { label: 'Polling unverified', className: 'health-warn' };
        return { label: 'Waiting for first poll', className: 'health-warn' };
      }
      if (health.status === 'healthy') return { label: 'HTTP + browser ready', className: 'health-good' };
      if (health.status === 'unreachable') return { label: health.publicEndpoint === 'unreachable' ? 'Public endpoint unreachable' : 'Endpoint unreachable', className: 'health-bad' };
      if (health.status === 'degraded') return { label: 'Browser check failed', className: 'health-bad' };
      return { label: 'Health check pending', className: 'health-warn' };
    }

    function cardTemplate(bot) {
      const avatar = bot.id === 'birth' ? 'TG' : 'PW';
      return `<article class="service" data-id="${escapeHtml(bot.id)}">
        <div class="service-top">
          <div class="service-heading"><span class="service-avatar ${bot.id === 'birth' ? 'purple' : ''}" aria-hidden="true">${avatar}</span><div><div class="service-title" data-field="name"></div><div class="service-kind" data-field="kind"></div></div></div>
          <span class="badge unknown" data-field="badge"></span>
        </div>
        <p class="description" data-field="description"></p>
        <div class="details">
          <div><span class="detail-label">Runtime</span><span class="detail-value" data-field="runtime"></span></div>
          <div><span class="detail-label">Health</span><span class="detail-value" data-field="health"></span></div>
          <div><span class="detail-label">Process ID</span><span class="detail-value mono" data-field="pid"></span></div>
          <div><span class="detail-label">Endpoint</span><span class="detail-value mono" data-field="endpoint"></span></div>
          <div><span class="detail-label">Uptime</span><span class="detail-value" data-field="uptime"></span></div>
          <div><span class="detail-label">Last started</span><span class="detail-value" data-field="lastStarted"></span></div>
        </div>
        <div class="service-bottom"><span class="event" data-field="event"></span><div class="actions">
          <button class="btn logs" type="button" data-action="logs">Logs</button>
          <button class="btn restart" type="button" data-action="restart">Restart</button>
          <button class="btn start" type="button" data-action="toggle">Start</button>
        </div></div>
      </article>`;
    }

    function stateLabel(bot) {
      return ({ running: 'Running', stopped: 'Stopped', error: 'Error', degraded: 'Needs attention', starting: 'Starting', stopping: 'Stopping', unknown: 'Unknown' })[bot.state] || 'Unknown';
    }

    function hasError(bot) {
      return bot.state === 'error' || ['unreachable', 'degraded', 'error', 'stale'].includes(bot.health?.status);
    }

    function matchesFilters(bot) {
      const query = $('search').value.trim().toLowerCase();
      const filter = $('filter').value;
      const text = [bot.name, bot.kind, bot.description, bot.runtime, bot.endpoint].join(' ').toLowerCase();
      if (query && !text.includes(query)) return false;
      if (filter === 'running' && !bot.running) return false;
      if (filter === 'stopped' && bot.running) return false;
      if (filter === 'error' && !hasError(bot)) return false;
      return true;
    }

    function setEndpoint(card, value) {
      let element = card.querySelector('[data-field="endpoint"]');
      const text = String(value ?? '—');
      const isLink = /^https?:\/\//i.test(text);
      if (isLink && element.tagName !== 'A') {
        const link = document.createElement('a');
        link.className = element.className;
        link.dataset.field = 'endpoint';
        element.replaceWith(link);
        element = link;
      } else if (!isLink && element.tagName === 'A') {
        const label = document.createElement('span');
        label.className = element.className;
        label.dataset.field = 'endpoint';
        element.replaceWith(label);
        element = label;
      }
      element.textContent = text;
      if (isLink) {
        element.href = text;
        element.target = '_blank';
        element.rel = 'noopener noreferrer';
      }
    }

    function updateCard(bot) {
      const card = document.querySelector(`.service[data-id="${CSS.escape(bot.id)}"]`);
      if (!card) return;
      const setText = (field, value) => {
        const element = card.querySelector(`[data-field="${field}"]`);
        if (element) element.textContent = String(value ?? '—');
      };
      const health = healthInfo(bot);
      const badge = card.querySelector('[data-field="badge"]');
      badge.textContent = stateLabel(bot);
      badge.className = `badge ${['running', 'stopped', 'starting', 'stopping', 'error', 'degraded'].includes(bot.state) ? bot.state : 'unknown'}`;
      setText('name', bot.name);
      setText('kind', bot.kind);
      setText('description', bot.description);
      setText('runtime', bot.runtime);
      setText('health', health.label);
      card.querySelector('[data-field="health"]').className = `detail-value ${health.className}`;
      setText('pid', bot.running ? bot.pid : '—');
      setEndpoint(card, bot.endpoint);
      setText('uptime', bot.running ? uptime(bot.startedAt) : '—');
      setText('lastStarted', bot.lastStartedAt ? formattedTime(bot.lastStartedAt) : 'Never');
      setText('event', bot.lastEvent);
      const toggle = card.querySelector('[data-action="toggle"]');
      const transitioning = ['starting', 'stopping'].includes(bot.state);
      const verb = bot.running ? 'Stop' : 'Start';
      toggle.textContent = transitioning ? stateLabel(bot) : verb;
      toggle.setAttribute('aria-label', `${verb} ${bot.name}`);
      toggle.className = `btn ${bot.running ? 'stop' : 'start'}`;
      toggle.disabled = actionBusy || transitioning;
      card.querySelector('[data-action="restart"]').setAttribute('aria-label', `Restart ${bot.name}`);
      card.querySelector('[data-action="logs"]').setAttribute('aria-label', `View logs for ${bot.name}`);
      card.querySelectorAll('[data-action="restart"], [data-action="logs"]').forEach(button => { button.disabled = actionBusy || transitioning; });
      card.hidden = !matchesFilters(bot);
    }

    function updateEmptyState() {
      const host = $('services');
      const visible = [...host.querySelectorAll('.service')].filter(card => !card.hidden).length;
      const oldEmpty = host.querySelector('.empty-state');
      if (oldEmpty) oldEmpty.remove();
      if (currentBots.length && visible === 0) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.innerHTML = '<strong>No services match these filters</strong><p>Change the search or status filter to see more services.</p><button class="btn start" type="button" data-action="clear-filters">Clear filters</button>';
        host.append(empty);
      }
      $('resultCount').textContent = `${visible} of ${currentBots.length}`;
      $('clearFilters').hidden = !$('search').value && $('filter').value === 'all';
    }

    function render(bots, checkedAt) {
      currentBots = bots;
      const active = bots.filter(bot => bot.running).length;
      const errors = bots.filter(hasError).length;
      $('total').textContent = bots.length;
      $('running').textContent = active;
      $('stopped').textContent = bots.length - active;
      $('errors').textContent = errors;
      $('updated').textContent = `Updated ${new Date(checkedAt * 1000).toLocaleTimeString()}`;

      const host = $('services');
      const incomingIds = new Set(bots.map(bot => String(bot.id)));
      host.querySelectorAll('.service').forEach(card => { if (!incomingIds.has(card.dataset.id)) card.remove(); });
      if (!bots.length) {
        host.innerHTML = '<div class="empty-state"><strong>No services configured</strong><p>Add an allowed service in the local controller configuration.</p></div>';
        $('resultCount').textContent = '0 services';
        $('clearFilters').hidden = true;
        return;
      }
      host.querySelectorAll('.loading, .load-error').forEach(state => state.remove());
      const existingIds = new Set([...host.querySelectorAll('.service')].map(card => card.dataset.id));
      bots.forEach(bot => {
        if (!existingIds.has(String(bot.id))) host.insertAdjacentHTML('beforeend', cardTemplate(bot));
        updateCard(bot);
      });
      updateEmptyState();
    }

    async function request(action, { method = 'GET', data = null, id = null } = {}) {
      const url = new URL('api.php', location.href);
      url.searchParams.set('action', action);
      if (id) url.searchParams.set('id', id);
      const headers = { 'X-BotDeck-Request': '1' };
      if (data) headers['Content-Type'] = 'application/json';
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), action === 'stop' || action === 'restart' ? 40_000 : 12_000);
      try {
        const response = await fetch(url, { method, headers, body: data ? JSON.stringify(data) : undefined, signal: controller.signal, cache: 'no-store' });
        let result;
        try { result = await response.json(); }
        catch { throw new Error('The controller returned an invalid response. Check the PHP error log.'); }
        if (!response.ok || !result.ok) throw new Error(result.error || `Request failed (${response.status}).`);
        return result;
      } catch (error) {
        if (error.name === 'AbortError') throw new Error('The request timed out. Check the service status and retry.');
        throw error;
      } finally { clearTimeout(timeout); }
    }

    async function refresh() {
      if (polling) return;
      polling = true;
      $('refresh').disabled = true;
      try {
        const result = await request('status');
        setConnection(true);
        render(result.bots, result.checkedAt);
      } catch (error) {
        setConnection(false);
        $('updated').textContent = 'Status unavailable';
        if (!$('services').querySelector('.service')) {
          $('services').innerHTML = `<div class="load-error">${escapeHtml(error.message)} <button class="refresh" type="button" data-action="retry">Retry</button></div>`;
        } else showToast(error.message, true);
      } finally {
        polling = false;
        $('refresh').disabled = false;
      }
    }

    function openAction(action, bot) {
      actionFocus = document.activeElement;
      modalAction = { action, id: bot.id };
      const start = action === 'start';
      const restart = action === 'restart';
      const needsToken = bot.id === 'birth' && (start || restart);
      $('actionTitle').textContent = `${start ? 'Start' : restart ? 'Restart' : 'Stop'} ${bot.name}`;
      $('actionText').textContent = needsToken
        ? 'Enter the Telegram token from BotFather. It is passed to the local process and is not written to a file.'
        : start ? 'This will start the configured local service.'
          : restart ? 'This will stop and start the service. Any current sessions will be closed.'
            : !bot.managed ? 'This process was started outside Bot Manager and has no graceful-stop channel. It will be force-stopped after confirming the service script and process ID.'
              : 'This stops the service. Active sessions or requests may be interrupted if graceful shutdown times out.';
      $('tokenInput').hidden = !needsToken;
      $('tokenInput').value = '';
      $('modalConfirm').textContent = start ? 'Start service' : restart ? 'Restart service' : 'Stop service';
      $('modalConfirm').className = `btn modal-button ${start ? 'start' : 'confirm-danger'}`;
      $('actionModal').classList.add('show');
      $('actionModal').setAttribute('aria-hidden', 'false');
      setTimeout(() => (needsToken ? $('tokenInput') : $('modalConfirm')).focus(), 0);
    }

    function closeActionModal() {
      if (actionBusy) return;
      $('actionModal').classList.remove('show');
      $('actionModal').setAttribute('aria-hidden', 'true');
      actionFocus?.focus();
      actionFocus = null;
      modalAction = null;
    }

    async function executeAction() {
      if (!modalAction || actionBusy) return;
      const { action, id } = modalAction;
      const token = $('tokenInput').value.trim();
      if (id === 'birth' && (action === 'start' || action === 'restart') && !token) {
        showToast('Enter the Telegram bot token.', true);
        $('tokenInput').focus();
        return;
      }
      actionBusy = true;
      document.querySelectorAll('.service button').forEach(button => { button.disabled = true; });
      $('modalConfirm').disabled = true;
      $('modalCancel').disabled = true;
      $('modalConfirm').textContent = action === 'stop' ? 'Stopping…' : action === 'restart' ? 'Restarting…' : 'Starting…';
      try {
        const result = await request(action, { method: 'POST', data: { id, token } });
        $('actionModal').classList.remove('show');
        $('actionModal').setAttribute('aria-hidden', 'true');
        modalAction = null;
        showToast(action === 'stop'
          ? result.graceful ? 'Service stopped cleanly.' : 'Service was force-stopped after confirming its process.'
          : action === 'restart' ? 'Service restarted.'
            : result.alreadyRunning ? 'Service is already running.' : 'Service started.');
      } catch (error) { showToast(error.message, true); }
      finally {
        actionBusy = false;
        $('modalConfirm').disabled = false;
        $('modalCancel').disabled = false;
        $('modalConfirm').textContent = modalAction?.action === 'stop' ? 'Stop service' : modalAction?.action === 'restart' ? 'Restart service' : 'Start service';
        await refresh();
        if (!modalAction) {
          actionFocus?.focus();
          actionFocus = null;
        }
      }
    }

    function renderLogs() {
      const query = $('logSearch').value.trim().toLowerCase();
      const level = $('logFilter').value;
      const entries = currentLogs.filter(entry => {
        const text = `${entry.time} ${entry.level} ${entry.message}`.toLowerCase();
        return (!query || text.includes(query)) && (level === 'all' || entry.level === level);
      });
      const list = $('logList');
      list.replaceChildren();
      if (!entries.length) {
        const empty = document.createElement('div');
        empty.className = 'log-empty';
        empty.textContent = currentLogs.length ? 'No log entries match these filters.' : 'No log entries yet.';
        list.append(empty);
      }
      for (const entry of entries) {
        const row = document.createElement('article');
        row.className = 'log-entry';
        const meta = document.createElement('div');
        meta.className = 'log-meta';
        const safeLevel = ['error', 'warning', 'info', 'debug'].includes(entry.level) ? entry.level : 'info';
        const severity = document.createElement('span');
        severity.className = `log-level ${safeLevel}`;
        severity.textContent = safeLevel;
        const time = document.createElement('time');
        time.textContent = entry.time ? new Date(entry.time).toLocaleString() : '';
        meta.append(severity, time);
        const message = document.createElement('div');
        message.className = 'log-message';
        message.textContent = entry.message;
        row.append(meta, message);
        list.append(row);
      }
      $('logCopy').disabled = entries.length === 0;
    }

    async function copyVisibleLogs() {
      const query = $('logSearch').value.trim().toLowerCase();
      const level = $('logFilter').value;
      const entries = currentLogs.filter(entry => `${entry.time} ${entry.level} ${entry.message}`.toLowerCase().includes(query) && (level === 'all' || entry.level === level));
      const content = entries.map(entry => `${entry.time} [${entry.level}] ${entry.message}`).join('\n');
      try {
        await navigator.clipboard.writeText(content);
        showToast(`Copied ${entries.length} log ${entries.length === 1 ? 'entry' : 'entries'}.`);
      } catch { showToast('Clipboard access is unavailable in this browser.', true); }
    }

    async function showLogs(bot) {
      logsFocus = document.activeElement;
      currentLogs = [];
      $('logsTitle').textContent = `${bot.name} · Logs`;
      $('logsDescription').textContent = 'Recent process output. Secrets are filtered before display.';
      $('logSearch').value = '';
      $('logFilter').value = 'all';
      $('logCopy').disabled = true;
      $('logList').innerHTML = '<div class="log-empty">Loading logs…</div>';
      $('logsModal').classList.add('show');
      $('logsModal').setAttribute('aria-hidden', 'false');
      $('logsClose').focus();
      try {
        const result = await request('logs', { id: bot.id });
        currentLogs = result.entries;
        renderLogs();
      } catch (error) {
        $('logList').textContent = error.message;
        showToast(error.message, true);
      }
    }

    function closeLogsModal() {
      $('logsModal').classList.remove('show');
      $('logsModal').setAttribute('aria-hidden', 'true');
      logsFocus?.focus();
      logsFocus = null;
    }

    $('services').addEventListener('click', event => {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      if (button.dataset.action === 'clear-filters') { $('search').value = ''; $('filter').value = 'all'; currentBots.forEach(updateCard); updateEmptyState(); return; }
      if (button.dataset.action === 'retry') { refresh(); return; }
      const card = button.closest('.service');
      const bot = currentBots.find(item => item.id === card?.dataset.id);
      if (!bot) return;
      if (button.dataset.action === 'toggle') openAction(bot.running ? 'stop' : 'start', bot);
      else if (button.dataset.action === 'restart') openAction('restart', bot);
      else if (button.dataset.action === 'logs') showLogs(bot);
    });

    $('modalConfirm').addEventListener('click', executeAction);
    $('modalCancel').addEventListener('click', closeActionModal);
    $('logsClose').addEventListener('click', closeLogsModal);
    $('actionModal').addEventListener('click', event => { if (event.target.id === 'actionModal') closeActionModal(); });
    $('logsModal').addEventListener('click', event => { if (event.target.id === 'logsModal') closeLogsModal(); });
    $('tokenInput').addEventListener('keydown', event => { if (event.key === 'Enter') executeAction(); });
    $('logSearch').addEventListener('input', renderLogs);
    $('logFilter').addEventListener('change', renderLogs);
    $('logCopy').addEventListener('click', copyVisibleLogs);
    $('refresh').addEventListener('click', refresh);
    $('clearFilters').addEventListener('click', () => { $('search').value = ''; $('filter').value = 'all'; currentBots.forEach(updateCard); updateEmptyState(); });
    $('search').addEventListener('input', () => { currentBots.forEach(updateCard); updateEmptyState(); });
    // Keep Chrome and other browsers from restoring a saved email or previous
    // query into this non-account search field. Unlock only when the user focuses it.
    $('search').addEventListener('focus', () => $('search').removeAttribute('readonly'), { once: true });
    window.addEventListener('pageshow', () => {
      $('search').value = '';
      $('filter').value = 'all';
      currentBots.forEach(updateCard);
      updateEmptyState();
    });
    $('filter').addEventListener('change', () => { currentBots.forEach(updateCard); updateEmptyState(); });

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        if ($('actionModal').classList.contains('show')) closeActionModal();
        else if ($('logsModal').classList.contains('show')) closeLogsModal();
      }
      if (event.key === 'Tab') {
        const modal = $('actionModal').classList.contains('show') ? $('actionModal') : $('logsModal').classList.contains('show') ? $('logsModal') : null;
        if (!modal) return;
        const focusable = [...modal.querySelectorAll('button:not(:disabled), input:not([hidden]), select:not(:disabled)')];
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });

    refresh();
    setInterval(refresh, 5000);
    setInterval(() => currentBots.forEach(updateCard), 1000);
  </script>
</body>
</html>
