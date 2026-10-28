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

if (-not (Get-Command winget.exe -ErrorAction SilentlyContinue)) {
    throw 'Windows Package Manager (winget) is required. Install/update App Installer from Microsoft Store, then rerun this command.'
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

Write-Host ''
Write-Host 'Setup complete. Apache and the Playwright service are running.' -ForegroundColor Green
Write-Host 'Open http://localhost/all_bot/ and start the services from the dashboard.'
Start-Process 'http://localhost/all_bot/'
