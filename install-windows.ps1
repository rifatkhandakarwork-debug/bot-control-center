param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$')]
    [string]$Repository,

    [ValidateSet('main', 'master')]
    [string]$Branch = 'main'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Find-XamppRoot {
    foreach ($candidate in @('C:\xampp', 'D:\Xampp')) {
        if ((Test-Path (Join-Path $candidate 'php\php.exe')) -and (Test-Path (Join-Path $candidate 'apache\bin\httpd.exe'))) {
            return $candidate
        }
    }
    return $null
}

function Install-WingetPackage([string]$Id, [string]$ExtraArgs = '') {
    Write-Host "Installing $Id..." -ForegroundColor Cyan
    $arguments = @('install', '--id', $Id, '--exact', '--source', 'winget', '--silent', '--accept-package-agreements', '--accept-source-agreements')
    if ($ExtraArgs) { $arguments += $ExtraArgs.Split(' ') }
    & winget @arguments
    if ($LASTEXITCODE -ne 0) { throw "winget could not install $Id (exit code $LASTEXITCODE). Install it manually and run this command again." }
}

function Find-TailscaleCli {
    foreach ($candidate in @((Join-Path $env:ProgramFiles 'Tailscale\tailscale.exe'), 'C:\Program Files\Tailscale\tailscale.exe')) {
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    $command = Get-Command tailscale.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    return $null
}

function Read-TailscaleStatus([string]$Cli) {
    $raw = & $Cli status --json 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $raw) { return $null }
    try { return ($raw | ConvertFrom-Json) } catch { return $null }
}

function Ensure-Winget {
    if (Get-Command winget.exe -ErrorAction SilentlyContinue) { return }

    Write-Host 'winget is missing. Bootstrapping Windows Package Manager...' -ForegroundColor Cyan
    $windowsApps = Join-Path $env:LOCALAPPDATA 'Microsoft\WindowsApps'
    try {
        $appInstaller = Get-AppxPackage -Name Microsoft.DesktopAppInstaller -ErrorAction SilentlyContinue
        if ($appInstaller) {
            Add-AppxPackage -RegisterByFamilyName -MainPackage Microsoft.DesktopAppInstaller_8wekyb3d8bbwe -ErrorAction Stop
        }
        if ((Test-Path (Join-Path $windowsApps 'winget.exe')) -and $env:Path -notlike "*$windowsApps*") {
            $env:Path = "$windowsApps;$env:Path"
        }
        if (-not (Get-Command winget.exe -ErrorAction SilentlyContinue)) {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Install-PackageProvider -Name NuGet -Force -Scope CurrentUser | Out-Null
            Install-Module -Name Microsoft.WinGet.Client -Force -Repository PSGallery -Scope CurrentUser -Confirm:$false
            Import-Module Microsoft.WinGet.Client -Force
            Repair-WinGetPackageManager -Force -Latest | Out-Null
        }
    } catch {
        throw "Automatic winget setup failed: $($_.Exception.Message) Install/update Microsoft's App Installer, then rerun this command."
    }

    if ($env:Path -notlike "*$windowsApps*") { $env:Path = "$windowsApps;$env:Path" }
    if (-not (Get-Command winget.exe -ErrorAction SilentlyContinue)) {
        $wingetPath = Join-Path $windowsApps 'winget.exe'
        if (Test-Path -LiteralPath $wingetPath) { $env:Path = "$windowsApps;$env:Path" }
    }
    if (-not (Get-Command winget.exe -ErrorAction SilentlyContinue)) {
        throw 'winget bootstrap completed but winget.exe is still unavailable. Restart PowerShell and rerun this command.'
    }
}

Ensure-Winget

$tailscale = Find-TailscaleCli
if (-not $tailscale) {
    Install-WingetPackage 'Tailscale.Tailscale'
    $tailscale = Find-TailscaleCli
}
if (-not $tailscale) { throw 'Tailscale was not found after installation. Install it from tailscale.com/download/windows and rerun setup.' }

$tailnetStatus = Read-TailscaleStatus $tailscale
if (-not $tailnetStatus -or $tailnetStatus.BackendState -ne 'Running') {
    $tailscaleUi = Join-Path (Split-Path -Parent $tailscale) 'tailscale-ipn.exe'
    if (Test-Path -LiteralPath $tailscaleUi) { Start-Process -FilePath $tailscaleUi }
    Write-Host 'Sign in to Tailscale in the window that opened. If needed, click the Tailscale tray icon and choose Log in. Waiting for this PC to connect...' -ForegroundColor Yellow
    for ($attempt = 0; $attempt -lt 120; $attempt++) {
        Start-Sleep -Seconds 5
        $tailnetStatus = Read-TailscaleStatus $tailscale
        if ($tailnetStatus -and $tailnetStatus.BackendState -eq 'Running') { break }
    }
}
if (-not $tailnetStatus -or $tailnetStatus.BackendState -ne 'Running') {
    throw 'Tailscale is not connected yet. Finish sign-in from its tray icon, then rerun this setup command.'
}
$tailnetHost = ([string]$tailnetStatus.Self.DNSName).Trim().TrimEnd('.').ToLowerInvariant()
if ($tailnetHost -notmatch '^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.ts\.net$') {
    throw "Could not read this device's Tailscale DNS name ($tailnetHost). Confirm MagicDNS is enabled, then rerun setup."
}

$xampp = Find-XamppRoot
if (-not $xampp) {
    Install-WingetPackage 'ApacheFriends.Xampp.8.2' '--location C:\xampp'
    $xampp = Find-XamppRoot
    if (-not $xampp) { throw 'XAMPP installation completed but C:\xampp was not found. Install XAMPP to C:\xampp and rerun this command.' }
}

if (-not (Get-Command node.exe -ErrorAction SilentlyContinue)) {
    Install-WingetPackage 'OpenJS.NodeJS.LTS'
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
}
if (-not (Get-Command node.exe -ErrorAction SilentlyContinue)) { throw 'Node.js was installed but is not available in PATH. Open a new PowerShell window and rerun this command.' }

$chromeCandidates = @('C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe')
if (-not ($chromeCandidates | Where-Object { Test-Path $_ })) {
    Install-WingetPackage 'Google.Chrome'
}

$php = Join-Path $xampp 'php\php.exe'
$phpModules = & $php -m
foreach ($extension in @('curl', 'mbstring')) {
    if ($phpModules -notcontains $extension) { throw "XAMPP PHP extension '$extension' is not enabled. Enable extension=$extension in $xampp\php\php.ini and rerun this command." }
}

$tempRoot = Join-Path ([IO.Path]::GetTempPath()) ('botdeck-setup-' + [guid]::NewGuid().ToString('N'))
$zipPath = Join-Path $tempRoot 'project.zip'
$extractPath = Join-Path $tempRoot 'extract'
$destination = Join-Path $xampp 'htdocs\all_bot'
New-Item -ItemType Directory -Path $extractPath -Force | Out-Null
try {
    $archiveUrl = "https://github.com/$Repository/archive/refs/heads/$Branch.zip"
    Write-Host "Downloading $Repository ($Branch)..." -ForegroundColor Cyan
    Invoke-WebRequest -Uri $archiveUrl -OutFile $zipPath
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractPath -Force
    $projectRoot = Get-ChildItem -LiteralPath $extractPath -Directory | Select-Object -First 1
    if (-not $projectRoot -or -not (Test-Path (Join-Path $projectRoot.FullName 'api.php'))) {
        throw 'The downloaded archive does not contain the expected project files. Check the repository and branch name.'
    }
    if (Test-Path $destination) {
        throw "Destination already exists: $destination. Move or remove it before installing again."
    }
    Move-Item -LiteralPath $projectRoot.FullName -Destination $destination
} finally {
    Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction SilentlyContinue
}

$servicePath = Join-Path $destination 'playwright-service-upload'
Set-Content -LiteralPath (Join-Path $servicePath '.tailscale-host') -Value $tailnetHost -NoNewline -Encoding Ascii
Push-Location $servicePath
try {
    Write-Host 'Installing Playwright dependencies and Chromium...' -ForegroundColor Cyan
    & npm.cmd ci
    if ($LASTEXITCODE -ne 0) { throw 'npm ci failed while installing Playwright dependencies.' }
} finally {
    Pop-Location
}

$httpd = Join-Path $xampp 'apache\bin\httpd.exe'
& $httpd -k start
if ($LASTEXITCODE -ne 0) { throw "Apache could not start. Check the XAMPP Control Panel and port 80 at $xampp\apache\logs\error.log." }

$playwrightUrl = 'http://localhost/all_bot/api.php?action=start'
$playwrightResult = Invoke-RestMethod -Method Post -Uri $playwrightUrl -Headers @{ Origin = 'http://localhost'; 'X-Botdeck-Request' = '1' } -ContentType 'application/json' -Body '{"id":"playwright"}'
if (-not $playwrightResult.ok) { throw "Apache is running, but the Playwright service did not start: $($playwrightResult.error)" }

Write-Host 'Enabling Tailscale Funnel for the Playwright service...' -ForegroundColor Cyan
$funnelOutput = & $tailscale funnel --bg --yes 3011 2>&1
if ($LASTEXITCODE -ne 0) { throw "Tailscale Funnel could not be enabled. Check Tailscale Admin Console policy (MagicDNS, HTTPS, and Funnel permissions), then rerun setup.`n$funnelOutput" }
$funnelStatus = (& $tailscale funnel status 2>&1 | Out-String)
if ($LASTEXITCODE -ne 0 -or $funnelStatus -notmatch [regex]::Escape($tailnetHost)) {
    throw "Tailscale Funnel did not report the expected hostname. Check Funnel settings and rerun setup.`n$funnelStatus"
}

Write-Host ''
Write-Host 'Setup complete. Apache, Playwright, and Tailscale Funnel are running.' -ForegroundColor Green
Write-Host "Playwright URL: https://$tailnetHost/session" -ForegroundColor Green
Write-Host 'Open http://localhost/all_bot/ and start the services from the dashboard.'
Start-Process 'http://localhost/all_bot/'
