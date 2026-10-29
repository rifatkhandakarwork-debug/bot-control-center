<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $value, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function localHost(string $host): bool
{
    $name = strtolower((string)(parse_url('http://' . $host, PHP_URL_HOST) ?? ''));
    return in_array($name, ['localhost', '127.0.0.1', '::1'], true);
}

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !localHost($_SERVER['HTTP_HOST'] ?? '')) {
    respond(['ok' => false, 'error' => 'This dashboard can only be used from this computer.'], 403);
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' || $action === 'logs') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $originHost = $origin !== '' ? (string)(parse_url($origin, PHP_URL_HOST) ?? '') : '';
    $badOrigin = $origin !== '' && (!localHost($originHost) || parse_url($origin, PHP_URL_SCHEME) !== 'http');
    if (($method === 'POST' && $origin === '') || $badOrigin || ($_SERVER['HTTP_X_BOTDECK_REQUEST'] ?? '') !== '1') {
        respond(['ok' => false, 'error' => 'Request origin could not be verified. Refresh the dashboard and try again.'], 403);
    }
}

$root = __DIR__;
$stateDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'botdeck-' . substr(hash('sha256', $root), 0, 12);
$healthInterval = max(10, min(300, (int)(getenv('BOTDECK_HEALTH_INTERVAL') ?: 30)));
$healthTimeout = max(1, min(8, (int)(getenv('BOTDECK_HEALTH_TIMEOUT') ?: 2)));
if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
    respond(['ok' => false, 'error' => 'Could not create the local runtime folder.'], 500);
}

$playwrightHostFile = $root . DIRECTORY_SEPARATOR . 'playwright-service-upload' . DIRECTORY_SEPARATOR . '.tailscale-host';
$configuredPlaywrightHost = is_file($playwrightHostFile) ? trim((string)@file_get_contents($playwrightHostFile)) : '';
$playwrightPublicHost = trim((string)($configuredPlaywrightHost ?: getenv('BOTDECK_PUBLIC_HOST') ?: 'desktop-4ajaj6b.taile1603c.ts.net'));
$definitions = [
    'birth' => ['name' => 'Birth Auto Telegram', 'kind' => 'Telegram bot', 'description' => 'Receives Telegram messages using long polling.', 'runtime' => 'PHP 8 · cURL', 'endpoint' => 'Telegram Bot API', 'script' => $root . DIRECTORY_SEPARATOR . 'Birth_auto_tg' . DIRECTORY_SEPARATOR . 'bot.php', 'workdir' => $root . DIRECTORY_SEPARATOR . 'Birth_auto_tg', 'interpreter' => 'php'],
    'playwright' => ['name' => 'Playwright Service', 'kind' => 'Browser automation', 'description' => 'Creates and manages browser sessions through Tailscale.', 'runtime' => 'Node.js · Playwright', 'endpoint' => 'https://' . $playwrightPublicHost . '/session', 'script' => $root . DIRECTORY_SEPARATOR . 'playwright-service-upload' . DIRECTORY_SEPARATOR . 'playwright-server.js', 'workdir' => $root . DIRECTORY_SEPARATOR . 'playwright-service-upload', 'interpreter' => 'node'],
];

function statePath(string $id, string $suffix = '.json'): string { global $stateDir; return $stateDir . DIRECTORY_SEPARATOR . $id . $suffix; }
function readState(string $id): array
{
    $decoded = json_decode((string)@file_get_contents(statePath($id)), true);
    return is_array($decoded) ? $decoded : [];
}
function writeState(string $id, array $state): void
{
    $path = statePath($id);
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    @rename($tmp, $path);
}
function runPowerShell(string $script, ?array $environment = null): array
{
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $powershell = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($powershell)) return [1, '', 'Windows PowerShell was not found.'];
    $command = '"' . $powershell . '" -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded;
    global $stateDir;
    $stdoutPath = tempnam($stateDir, 'ps-out-');
    $stderrPath = tempnam($stateDir, 'ps-err-');
    if ($stdoutPath === false || $stderrPath === false) return [1, '', 'Could not create temporary process-control output files.'];
    $pipes = [];
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) { @unlink($stdoutPath); @unlink($stderrPath); return [1, '', 'Could not start Windows PowerShell.']; }
    $code = proc_close($process);
    $stdout = (string)@file_get_contents($stdoutPath);
    $stderr = (string)@file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);
    return [$code, trim((string)$stdout), trim((string)$stderr)];
}
function psLiteral(string $value): string { return "[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('" . base64_encode($value) . "'))"; }
function powershellProcessSelection(string $id, array $definition): string
{
    $needle = psLiteral($definition['script']);
    $legacy = '';
    if ($id === 'birth') {
        $launcher = psLiteral($definition['workdir'] . DIRECTORY_SEPARATOR . 'start_bot.bat');
        $legacy = '; if(-not $matched -and $candidate.Name -ieq \'php.exe\' -and $candidate.CommandLine -match \'(?i)(^|\\s)"?bot\\.php"?(\\s|$)\'){$parent=$all | Where-Object {$_.ProcessId -eq $candidate.ParentProcessId} | Select-Object -First 1; $matched=$parent -and $parent.CommandLine -and $parent.CommandLine.IndexOf(' . $launcher . ',[StringComparison]::OrdinalIgnoreCase) -ge 0}';
    }
    return '$all=@(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue); $target=' . $needle . '; $serviceProcesses=@($all | Where-Object {$candidate=$_; $matched=$candidate.CommandLine -and $candidate.CommandLine.IndexOf($target,[StringComparison]::OrdinalIgnoreCase) -ge 0' . $legacy . '; $matched})';
}
function wmiTimestamp(string $value): int
{
    if (ctype_digit($value)) return max(0, (int)((int)$value / 10_000_000 - 11_644_473_600));
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\.\d{6}([+-])(\d{3})$/', $value, $m)) return 0;
    $offset = intdiv((int)$m[8], 60);
    $minutes = (int)$m[8] % 60;
    $timezone = $m[7] . sprintf('%02d:%02d', $offset, $minutes);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", new DateTimeZone($timezone));
    return $date ? $date->getTimestamp() : 0;
}
function findServiceProcesses(string $id, array $definition): array
{
    $script = powershellProcessSelection($id, $definition) . '; foreach($candidate in $serviceProcesses){Write-Output ("{0}|{1}" -f $candidate.ProcessId,$candidate.CreationDate.ToUniversalTime().ToFileTimeUtc())}';
    [$code, $output] = runPowerShell($script);
    if ($code !== 0 || $output === '') return [];
    $found = [];
    foreach (preg_split('/\R/', $output) as $line) {
        $parts = explode('|', trim($line), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) continue;
        $found[] = ['pid' => (int)$parts[0], 'createdAt' => wmiTimestamp($parts[1])];
    }
    return $found;
}
function processRunning(string $id, array $definition, array $state): bool
{
    $pid = (int)($state['pid'] ?? 0);
    if ($pid > 0 && empty($state['unmanaged'])) {
        $needle = psLiteral($definition['script']);
        $script = '$p=Get-CimInstance Win32_Process -Filter \'ProcessId = ' . $pid . '\' -ErrorAction SilentlyContinue; if($p -and $p.CommandLine.IndexOf(' . $needle . ',[StringComparison]::OrdinalIgnoreCase) -ge 0){exit 0}else{exit 1}';
        [$code] = runPowerShell($script);
        if ($code === 0) return true;
    }
    return findServiceProcesses($id, $definition) !== [];
}
function httpJson(string $path, int $timeout = 2): array
{
    $socket = @fsockopen('127.0.0.1', 3011, $errno, $errstr, $timeout);
    if (!$socket) return ['ok' => false, 'error' => 'HTTP endpoint did not respond.'];
    stream_set_timeout($socket, $timeout);
    fwrite($socket, "GET " . $path . " HTTP/1.1\r\nHost: 127.0.0.1:3011\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);
    $parts = preg_split("\r?\n\r?\n", (string)$response, 2);
    $status = preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/', $parts[0] ?? '', $match) ? (int)$match[1] : 0;
    $body = json_decode($parts[1] ?? '', true);
    if ($status !== 200 || !is_array($body) || empty($body['ok'])) return ['ok' => false, 'error' => $status ? 'HTTP health check returned ' . $status . '.' : 'HTTP endpoint did not respond.'];
    return $body;
}
function publicHealth(string $host, int $timeout = 2): array
{
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host)) return ['ok' => false, 'error' => 'Public hostname is invalid.'];
    $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Accept: application/json\r\nConnection: close\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents('https://' . $host . '/health', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($data) || empty($data['ok']) || ($data['process'] ?? '') !== 'running') {
        return ['ok' => false, 'error' => $status ? 'Public endpoint returned HTTP ' . $status . ' instead of Playwright health.' : 'Public endpoint did not respond.'];
    }
    return ['ok' => true];
}
function readBotHealth(array $state): array
{
    $path = (string)($state['healthFile'] ?? '');
    if ($path === '' || !is_file($path)) return ['status' => 'starting', 'checkedAt' => 0, 'lastError' => ''];
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) return ['status' => 'unknown', 'checkedAt' => 0, 'lastError' => 'Polling health file is unreadable.'];
    $checkedAt = (int)($data['updatedAt'] ?? 0);
    $status = (string)($data['status'] ?? 'unknown');
    if ($checkedAt < time() - 60) $status = 'stale';
    return ['status' => $status, 'checkedAt' => $checkedAt, 'lastError' => (string)($data['lastError'] ?? '')];
}
function botList(): array
{
    global $definitions;
    $list = [];
    foreach ($definitions as $id => $definition) {
        $state = readState($id);
        $processes = findServiceProcesses($id, $definition);
        $running = $processes !== [];
        $pidList = array_column($processes, 'pid');
        $tracked = $running && in_array((int)($state['pid'] ?? 0), $pidList, true) && empty($state['unmanaged']);
        $activePid = $running ? (int)$processes[0]['pid'] : 0;
        $processStart = $running ? (int)($processes[0]['createdAt'] ?? 0) : 0;
        if ($running && !in_array($activePid, $pidList, true)) $activePid = (int)$pidList[0];
        if ($running && !$tracked) {
            $state['pid'] = $activePid;
            $state['startedAt'] = (int)($state['startedAt'] ?? 0) ?: $processStart;
            $state['lastStartedAt'] = (int)($state['lastStartedAt'] ?? 0) ?: $processStart;
            $state['unmanaged'] = true;
            $state['stoppedByUser'] = false;
            writeState($id, $state);
        }
        if (!$running && !empty($state['pid']) && empty($state['stoppedByUser']) && empty($state['transition']) && empty($state['lastError'])) {
            $state['lastError'] = 'Process exited unexpectedly.';
            $state['transition'] = '';
            writeState($id, $state);
        }
        $health = $running ? ($id === 'birth' ? (!empty($state['healthFile']) ? readBotHealth($state) : ['status' => 'unverified', 'checkedAt' => 0, 'lastError' => 'Process was started outside Bot Manager; polling heartbeat is unavailable.']) : (array)($state['health'] ?? ['status' => 'checking', 'checkedAt' => 0, 'lastError' => ''])) : ['status' => 'offline', 'checkedAt' => 0, 'lastError' => ''];
        $transition = (string)($state['transition'] ?? '');
        $lastError = (string)($state['lastError'] ?? '');
        $duplicates = count($processes) > 1;
        $stateName = $transition !== '' ? $transition : ($duplicates ? 'error' : ($running ? 'running' : ($lastError !== '' ? 'error' : 'stopped')));
        $lastEvent = $duplicates ? count($processes) . ' matching processes detected; stop duplicate instances.' : ($running ? ($health['lastError'] !== '' ? $health['lastError'] : (!empty($state['unmanaged']) ? 'External process detected; health may be unverified.' : 'Process active')) : ($lastError !== '' ? $lastError : 'Ready to start'));
        $list[] = ['id' => $id, 'name' => $definition['name'], 'kind' => $definition['kind'], 'description' => $definition['description'], 'runtime' => $definition['runtime'], 'endpoint' => $definition['endpoint'], 'running' => $running, 'managed' => $tracked, 'instances' => count($processes), 'state' => $stateName, 'health' => $health, 'pid' => $running ? $activePid : null, 'startedAt' => $running ? ((int)($state['startedAt'] ?? 0) ?: $processStart) : 0, 'lastStartedAt' => (int)($state['lastStartedAt'] ?? $processStart), 'lastEvent' => $lastEvent];
    }
    return $list;
}
function findNodeExecutable(): string
{
    foreach (['C:\\Program Files\\nodejs\\node.exe', 'C:\\nodejs\\node.exe'] as $candidate) if (is_file($candidate)) return $candidate;
    [$code, $path] = runPowerShell('$cmd=Get-Command node.exe -ErrorAction Stop; Write-Output $cmd.Source');
    return $code === 0 && is_file($path) ? $path : '';
}
function startService(string $id, array $definition, string $token = ''): array
{
    global $healthInterval, $playwrightPublicHost;
    $state = readState($id);
    if (processRunning($id, $definition, $state)) return ['ok' => true, 'alreadyRunning' => true];
    if (!is_file($definition['script'])) return ['ok' => false, 'error' => 'Configured service script was not found.'];
    $php = 'D:\\Xampp\\php\\php.exe';
    if ($definition['interpreter'] === 'php') {
        if (!is_file($php)) $php = 'C:\\xampp\\php\\php.exe';
        if (!is_file($php)) return ['ok' => false, 'error' => 'PHP was not found in D:\\Xampp\\php or C:\\xampp\\php.'];
        if (!extension_loaded('curl')) return ['ok' => false, 'error' => 'The XAMPP PHP cURL extension is disabled.'];
        $executable = $php;
    } else {
        $executable = findNodeExecutable();
        if ($executable === '') return ['ok' => false, 'error' => 'Node.js was not found. Install Node.js or add node.exe to PATH.'];
    }

    $healthFile = statePath($id, '.health.json');
    $stopFile = statePath($id, '.stop');
    $log = statePath($id, '.out.log');
    $errorLog = statePath($id, '.err.log');
    $controlToken = bin2hex(random_bytes(32));
    @unlink($stopFile);
    @unlink($healthFile);
    writeState($id, ['transition' => 'starting', 'lastStartedAt' => (int)($state['lastStartedAt'] ?? 0), 'stoppedByUser' => false]);

    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['BOTDECK_HEALTH_FILE'] = $healthFile;
    $environment['BOTDECK_STOP_FILE'] = $stopFile;
    $environment['BOTDECK_BROWSER_HEALTH_INTERVAL_MS'] = (string)($healthInterval * 1000);
    if ($id === 'birth') $environment['TELEGRAM_BOT_TOKEN'] = $token;
    else {
        $environment['BOTDECK_CONTROL_TOKEN'] = $controlToken;
        $environment['BOTDECK_PUBLIC_HOST'] = $playwrightPublicHost;
        $environment['PORT'] = '3011';
    }

    $exeExpr = psLiteral($executable);
    $scriptExpr = psLiteral($definition['script']);
    $dirExpr = psLiteral($definition['workdir']);
    $outExpr = psLiteral($log);
    $errExpr = psLiteral($errorLog);
    $argument = '"' . $definition['script'] . '"';
    $argExpr = psLiteral($argument);
    $ps = '$exe=' . $exeExpr . '; $script=' . $scriptExpr . '; $dir=' . $dirExpr . '; $out=' . $outExpr . '; $err=' . $errExpr . '; $arg=' . $argExpr . '; Set-Location -LiteralPath $dir; $p=Start-Process -FilePath $exe -ArgumentList $arg -WorkingDirectory $dir -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -WindowStyle Hidden; Write-Output $p.Id';
    [$code, $output, $error] = runPowerShell($ps, $environment);
    $pid = (int)$output;
    if ($code !== 0 || $pid <= 0) {
        $message = $error !== '' ? redact($error) : 'Windows could not start the configured service.';
        writeState($id, ['transition' => '', 'lastStartedAt' => (int)($state['lastStartedAt'] ?? 0), 'lastError' => $message, 'stoppedByUser' => false]);
        return ['ok' => false, 'error' => $message];
    }
    $startedAt = time();
    $newState = ['pid' => $pid, 'startedAt' => $startedAt, 'lastStartedAt' => $startedAt, 'healthFile' => $healthFile, 'stopFile' => $stopFile, 'controlToken' => $controlToken, 'log' => $log, 'errorLog' => $errorLog, 'transition' => '', 'stoppedByUser' => false, 'lastError' => ''];
    writeState($id, $newState);
    usleep(500000);
    if (!processRunning($id, $definition, $newState)) {
        $message = startupLogMessage($log, $errorLog);
        $newState['lastError'] = $message;
        $newState['pid'] = null;
        $newState['startedAt'] = 0;
        writeState($id, $newState);
        return ['ok' => false, 'error' => $message];
    }
    return ['ok' => true, 'pid' => $pid];
}
function redact(string $value): string
{
    $value = preg_replace('/\b\d{5,15}:[A-Za-z0-9_-]{20,}\b/', '[REDACTED TOKEN]', $value) ?? $value;
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    return substr(trim($value), 0, 500);
}
function startupLogMessage(string $out, string $err): string
{
    $combined = '';
    foreach ([$err, $out] as $path) if (is_file($path)) $combined .= implode(' ', array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -3));
    return $combined !== '' ? 'Service exited during startup: ' . redact($combined) : 'Service exited during startup. Check runtime and configuration.';
}
function stopMatchingProcess(string $id, array $definition, int $pid): array
{
    $script = powershellProcessSelection($id, $definition) . '; $selected=$serviceProcesses | Where-Object {$_.ProcessId -eq ' . $pid . '} | Select-Object -First 1; if($selected){Stop-Process -Id ' . $pid . ' -Force -ErrorAction Stop; exit 0}else{exit 1}';
    return runPowerShell($script);
}
function stopService(string $id, array $definition): array
{
    $state = readState($id);
    $processes = findServiceProcesses($id, $definition);
    if ($processes === []) {
        writeState($id, ['lastStartedAt' => (int)($state['lastStartedAt'] ?? $state['startedAt'] ?? 0), 'stoppedByUser' => true, 'transition' => '', 'lastError' => '']);
        return ['ok' => true, 'graceful' => true];
    }
    $pids = array_column($processes, 'pid');
    $managed = empty($state['unmanaged']) && !empty($state['healthFile']) && in_array((int)($state['pid'] ?? 0), $pids, true);
    $state['transition'] = 'stopping';
    writeState($id, $state);
    $graceful = false;
    if ($managed && $id === 'birth') {
        $stopFile = (string)($state['stopFile'] ?? '');
        if ($stopFile !== '') @file_put_contents($stopFile, 'stop');
        $deadline = microtime(true) + 29;
        while (microtime(true) < $deadline) {
            usleep(400000);
            if (!processRunning($id, $definition, $state)) { $graceful = true; break; }
        }
    } elseif ($managed) {
        $token = (string)($state['controlToken'] ?? '');
        if ($token !== '' && function_exists('curl_init')) {
            $ch = curl_init('http://127.0.0.1:3011/shutdown');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-BotDeck-Control: ' . $token], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
            curl_exec($ch);
            curl_close($ch);
        }
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            usleep(250000);
            if (!processRunning($id, $definition, $state)) { $graceful = true; break; }
        }
    }
    $remaining = findServiceProcesses($id, $definition);
    foreach ($remaining as $candidate) {
        [$code, , $err] = stopMatchingProcess($id, $definition, (int)$candidate['pid']);
        if ($code !== 0 && processRunning($id, $definition, $state)) return ['ok' => false, 'error' => 'Graceful shutdown timed out and the process could not be stopped. ' . redact($err)];
        $graceful = false;
    }
    writeState($id, ['lastStartedAt' => (int)($state['lastStartedAt'] ?? $state['startedAt'] ?? 0), 'stoppedAt' => time(), 'pid' => null, 'stoppedByUser' => true, 'transition' => '', 'lastError' => '']);
    return ['ok' => true, 'graceful' => $graceful];
}
function logEntries(string $id): array
{
    $state = readState($id);
    $paths = [(string)($state['log'] ?? statePath($id, '.out.log')), (string)($state['errorLog'] ?? statePath($id, '.err.log'))];
    $entries = [];
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -100);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $event = trim((string)($decoded['event'] ?? ''));
                $message = redact((string)($decoded['message'] ?? 'Service log'));
                if ($event !== '') $message = '[' . $event . '] ' . $message;
                $entries[] = ['time' => (string)($decoded['time'] ?? ''), 'level' => (string)($decoded['level'] ?? 'info'), 'message' => $message];
            }
            else $entries[] = ['time' => date(DATE_ATOM, filemtime($path) ?: time()), 'level' => str_contains(strtolower($path), 'err.log') ? 'error' : 'info', 'message' => redact($line)];
        }
    }
    usort($entries, static fn(array $a, array $b): int => strcmp($a['time'], $b['time']));
    return array_slice($entries, -150);
}

if ($action === 'status' && $method === 'GET') {
    $bots = botList();
    foreach ($bots as &$bot) {
        if ($bot['id'] === 'playwright' && $bot['running']) {
            $state = readState('playwright');
            $http = httpJson('/health', $healthTimeout);
            $public = publicHealth($playwrightPublicHost, $healthTimeout);
            if (!$http['ok']) $bot['health'] = ['status' => 'unreachable', 'checkedAt' => time(), 'lastError' => $http['error'], 'publicEndpoint' => $public['ok'] ? 'healthy' : 'unreachable'];
            elseif (!$public['ok']) $bot['health'] = ['status' => 'unreachable', 'checkedAt' => time(), 'lastError' => $public['error'], 'publicEndpoint' => 'unreachable'];
            else {
                $cachedAt = (int)($state['health']['checkedAt'] ?? 0);
                if ($cachedAt < time() - $healthInterval) {
                    $deep = httpJson('/health?deep=1', min(15, max(8, $healthTimeout)));
                    $state['health'] = ['status' => !empty($deep['browserAutomation']) ? 'healthy' : 'degraded', 'checkedAt' => time(), 'http' => 'responding', 'publicEndpoint' => 'healthy', 'browserAutomation' => !empty($deep['browserAutomation']), 'lastError' => (string)($deep['error'] ?? '')];
                    writeState('playwright', $state);
                }
                if (($state['transition'] ?? '') === 'starting' && !empty($state['health']['browserAutomation'])) {
                    $state['transition'] = '';
                    writeState('playwright', $state);
                    $bot['state'] = 'running';
                    $bot['lastEvent'] = 'HTTP and browser health checks passed.';
                }
                $bot['health'] = $state['health'] ?? ['status' => 'checking', 'checkedAt' => 0, 'lastError' => ''];
            }
            if (!in_array($bot['health']['status'] ?? '', ['healthy'], true) && !in_array($bot['state'], ['starting', 'stopping'], true)) {
                $bot['state'] = 'degraded';
                if (($bot['health']['lastError'] ?? '') !== '') $bot['lastEvent'] = (string)$bot['health']['lastError'];
            }
        }
    }
    unset($bot);
    respond(['ok' => true, 'checkedAt' => time(), 'bots' => $bots]);
}

if (!in_array($action, ['start', 'stop', 'restart', 'logs'], true)) respond(['ok' => false, 'error' => 'Unknown action.'], 404);
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$id = $action === 'logs' ? (string)($_GET['id'] ?? '') : (is_array($input) ? (string)($input['id'] ?? '') : '');
if (!isset($definitions[$id])) respond(['ok' => false, 'error' => 'Unknown service.'], 400);
if ($action === 'logs' && $method === 'GET') respond(['ok' => true, 'entries' => logEntries($id)]);
if ($method !== 'POST') respond(['ok' => false, 'error' => 'POST is required for this action.'], 405);

$lock = fopen(statePath('actions', '.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) respond(['ok' => false, 'error' => 'Another service action is in progress. Please wait and retry.'], 409);
set_time_limit(0);
$definition = $definitions[$id];
$token = '';
if ($id === 'birth' && in_array($action, ['start', 'restart'], true)) {
    $token = trim((string)($input['token'] ?? ''));
    if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{20,}$/', $token)) respond(['ok' => false, 'error' => 'Enter a valid Telegram bot token from BotFather.'], 400);
}
if ($action === 'stop') $result = stopService($id, $definition);
elseif ($action === 'restart') {
    $stopped = stopService($id, $definition);
    $result = !$stopped['ok'] ? $stopped : startService($id, $definition, $token);
}
else $result = startService($id, $definition, $token);
flock($lock, LOCK_UN);
fclose($lock);
respond($result, !empty($result['ok']) ? 200 : 500);
