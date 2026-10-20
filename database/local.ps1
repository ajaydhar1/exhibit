param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('initialize', 'start', 'stop', 'status', 'check', 'inbox')]
    [string]$Command
)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$homePath = Join-Path $env:LOCALAPPDATA 'Exhibit\development'
$runtime = Join-Path $env:LOCALAPPDATA 'Exhibit\runtimes\postgresql-18.6\pgsql'
$bin = Join-Path $runtime 'bin'
$data = Join-Path $homePath 'data'
$settingsPath = Join-Path $homePath 'settings.json'
$php = 'C:\Program Files\Ampps\php\php.exe'
$webAddress = 'http://127.0.0.1:8085'
$databasePort = 55432

function New-Secret {
    $bytes = New-Object byte[] 32
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Protect-Secret([string]$Value) {
    return ConvertFrom-SecureString (ConvertTo-SecureString $Value -AsPlainText -Force)
}

function Read-Secret([string]$Value) {
    return [System.Net.NetworkCredential]::new('', (ConvertTo-SecureString $Value)).Password
}

function Process-Info([string]$File, [string[]]$Arguments, [hashtable]$Environment = @{}) {
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $File
    $info.WorkingDirectory = $project
    $info.UseShellExecute = $false
    $info.Arguments = ($Arguments | ForEach-Object {
        if ($_.Contains('"') -or $_.EndsWith('\')) { throw 'Unsupported process argument.' }
        '"' + $_ + '"'
    }) -join ' '
    foreach ($key in @($info.EnvironmentVariables.Keys)) {
        if ($key -match '^(DATABASE_|DB_|TEST_|AUTH_|APP_|SMTP_|MAIL_|RESEND_|EXHIBIT_|PG)') {
            $info.EnvironmentVariables.Remove($key)
        }
    }
    foreach ($key in $Environment.Keys) { $info.EnvironmentVariables[$key] = [string]$Environment[$key] }
    return $info
}

function Run-Program([string]$File, [string[]]$Arguments, [hashtable]$Environment = @{}) {
    $process = [System.Diagnostics.Process]::Start((Process-Info $File $Arguments $Environment))
    $process.WaitForExit()
    $code = $process.ExitCode
    $process.Dispose()
    if ($code -ne 0) { throw "Local command failed (exit $code): $([System.IO.Path]::GetFileName($File)). Check private local logs." }
}

function Php-Arguments {
    return @('-n', '-d', "extension_dir=$([System.IO.Path]::GetDirectoryName($php))\ext",
        '-d', 'extension=openssl', '-d', 'extension=pdo_pgsql',
        '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=1',
        '-d', 'zend.exception_ignore_args=1', '-d', "error_log=$homePath\php-error.log",
        '-d', "session.save_path=$homePath\sessions")
}

function Load-Settings {
    if (!(Test-Path -LiteralPath $settingsPath)) {
        throw 'No development environment initialized. Run: powershell -File database\local.ps1 initialize'
    }
    $settings = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
    if ($settings.version -ne 1 -or $settings.project -ne $project) {
        throw 'Development settings belong to another project or format. Inspect before changing anything.'
    }
    return $settings
}

function App-Environment($Settings, [switch]$Admin) {
    $role = 'exhibit_dev_app'
    $password = Read-Secret $Settings.application
    if ($Admin) { $role = 'exhibit_dev_admin'; $password = Read-Secret $Settings.admin }
    return @{
        APP_ENV = 'development'
        APP_URL = $webAddress
        DATABASE_URL = "postgresql://${role}:${password}@127.0.0.1:${databasePort}/exhibit_development"
        DB_SSLMODE = 'disable'
        AUTH_KEY = (Read-Secret $Settings.auth)
        EXHIBIT_LOCAL_APP_PASSWORD = $(if ($Admin) { Read-Secret $Settings.application } else { '' })
        MAIL_TRANSPORT = 'local-inbox'
    }
}

function Assert-AvailablePort([int]$Port) {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
    try { $listener.Start() } catch { throw "Loopback port $Port is occupied. No existing process will be stopped." }
    finally { $listener.Stop() }
}

function Database-Pid {
    if (Test-Path -LiteralPath "$data\postmaster.pid") {
        $lines = Get-Content -LiteralPath "$data\postmaster.pid"
        $serverPid = 0
        if ($lines.Length -lt 4 -or ![int]::TryParse($lines[0], [ref]$serverPid) -or
            [System.IO.Path]::GetFullPath($lines[1]) -ne $data -or [int]$lines[3] -ne $databasePort) {
            throw 'PostgreSQL PID identity does not match this development cluster.'
        }
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $serverPid"
        if ($process -and $process.ExecutablePath -eq "$bin\postgres.exe" -and $process.CommandLine.Replace('/', '\').Contains($data)) {
            return $serverPid
        }
        if ($process) { throw 'PID belongs to a different process; refusing to operate on it.' }
        throw 'Stale PostgreSQL PID file. Inspect the private data directory before recovery; nothing was deleted.'
    }
    return $null
}

function Web-Process {
    if (!(Test-Path -LiteralPath "$homePath\web.json")) { return $null }
    $state = Get-Content -LiteralPath "$homePath\web.json" -Raw | ConvertFrom-Json
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$state.pid)"
    if (!$process) { return $null }
    if ($process.ExecutablePath -ne $php -or !$process.CommandLine.Contains("$project\backend\local-router.php") -or
        $process.CommandLine -notmatch '127\.0\.0\.1:8085') {
        throw 'Web PID does not match the guarded Exhibit development server; refusing to stop it.'
    }
    return $process
}

function Start-Database {
    if (Database-Pid) { return }
    Assert-AvailablePort $databasePort
    Run-Program "$bin\pg_ctl.exe" @('-D', $data, '-l', "$homePath\postgres.log", '-w', 'start')
    if (!(Database-Pid)) { throw 'Could not verify the started development PostgreSQL process.' }
}

try {
    if (!(Test-Path -LiteralPath $php)) { throw 'AMPPS PHP executable is missing.' }
    if ($Command -eq 'initialize') {
        if (Test-Path -LiteralPath $homePath) {
            throw 'Development directory already exists. Initialization never resets or overwrites persistent data.'
        }
        foreach ($required in @("$bin\initdb.exe", "$bin\pg_ctl.exe", "$runtime\share\postgres.bki")) {
            if (!(Test-Path -LiteralPath $required)) { throw "Complete PostgreSQL 18.6 runtime is missing: $required" }
        }
        Assert-AvailablePort $databasePort
        New-Item -ItemType Directory -Path $homePath | Out-Null
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        & icacls.exe $homePath /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot secure local private directory; initialization stopped.' }
        New-Item -ItemType Directory -Path "$homePath\sessions" | Out-Null
        $admin = New-Secret
        $settings = @{
            version = 1; project = $project
            admin = (Protect-Secret $admin)
            application = (Protect-Secret (New-Secret))
            auth = (Protect-Secret (New-Secret))
        }
        $settings | ConvertTo-Json | Set-Content -LiteralPath $settingsPath -Encoding UTF8
        $passwordPath = "$homePath\initial-password.tmp"
        try {
            [System.IO.File]::WriteAllText($passwordPath, $admin)
            Run-Program "$bin\initdb.exe" @('-D', $data, '-U', 'exhibit_dev_admin',
                "--pwfile=$passwordPath", '--auth=scram-sha-256', '--encoding=UTF8', '--locale=C')
        } finally {
            if (Test-Path -LiteralPath $passwordPath) { Remove-Item -LiteralPath $passwordPath -Force }
        }
        @"
listen_addresses = '127.0.0.1'
port = 55432
ssl = off
max_connections = 30
shared_buffers = '64MB'
log_statement = 'none'
log_min_error_statement = 'panic'
log_parameter_max_length_on_error = 0
"@ | Add-Content -LiteralPath "$data\postgresql.conf" -Encoding ASCII
        @"
host exhibit_development exhibit_dev_app 127.0.0.1/32 scram-sha-256
host all exhibit_dev_app 127.0.0.1/32 reject
host all exhibit_dev_admin 127.0.0.1/32 scram-sha-256
host all all 0.0.0.0/0 reject
host all all ::0/0 reject
"@ | Set-Content -LiteralPath "$data\pg_hba.conf" -Encoding ASCII
        Start-Database
        try {
            $environment = App-Environment $settings -Admin
            $pgEnvironment = @{ PGPASSWORD = $admin; PGSSLMODE = 'disable' }
            Run-Program "$bin\createdb.exe" @('-w', '-h', '127.0.0.1', '-p', '55432', '-U',
                'exhibit_dev_admin', '-O', 'exhibit_dev_admin', 'exhibit_development') $pgEnvironment
            Run-Program $php ((Php-Arguments) + @("$project\backend\local.php", 'initialize')) $environment
            Run-Program $php ((Php-Arguments) + @("$project\backend\local.php", 'check')) (App-Environment $settings)
        } finally {
            Run-Program "$bin\pg_ctl.exe" @('-D', $data, '-m', 'fast', '-w', 'stop')
        }
        Write-Host 'Persistent development environment initialized and stopped. No application accounts were imported.'
        Write-Host 'Next: powershell -File database\local.ps1 start'
        exit 0
    }
    $settings = Load-Settings
    if ($Command -eq 'status') {
        $databasePid = Database-Pid
        $webProcess = Web-Process
        Write-Host "Development directory: $homePath"
        Write-Host "PostgreSQL: $(if ($databasePid) { 'running, PID ' + $databasePid } else { 'stopped' })"
        Write-Host "PHP server: $(if ($webProcess) { 'running, PID ' + $webProcess.ProcessId } else { 'stopped' })"
        Write-Host "Application: $webAddress"
        Write-Host 'Database: 127.0.0.1:55432 / exhibit_development (credentials withheld)'
        if ($databasePid) {
            Run-Program $php ((Php-Arguments) + @("$project\backend\local.php", 'check')) (App-Environment $settings)
        }
        exit 0
    }
    if ($Command -eq 'stop') {
        $webProcess = Web-Process
        if ($webProcess) { Stop-Process -Id $webProcess.ProcessId -ErrorAction Stop }
        if (Database-Pid) { Run-Program "$bin\pg_ctl.exe" @('-D', $data, '-m', 'fast', '-w', 'stop') }
        Write-Host 'Local servers stopped. Persistent development records and private configuration were retained.'
        exit 0
    }
    if ($Command -in @('check', 'inbox')) {
        if (!(Database-Pid)) { throw 'Development PostgreSQL is stopped. Run the start command first.' }
        Run-Program $php ((Php-Arguments) + @("$project\backend\local.php", $Command)) (App-Environment $settings)
        exit 0
    }
    if (Web-Process) { throw 'The guarded development web server is already running. Use status or stop.' }
    Assert-AvailablePort 8085
    Start-Database
    Run-Program $php ((Php-Arguments) + @("$project\backend\local.php", 'check')) (App-Environment $settings)
    $arguments = (Php-Arguments) + @('-S', '127.0.0.1:8085', '-t', $project, "$project\backend\local-router.php")
    $server = [System.Diagnostics.Process]::Start((Process-Info $php $arguments (App-Environment $settings)))
    @{ pid = $server.Id } | ConvertTo-Json | Set-Content -LiteralPath "$homePath\web.json" -Encoding ASCII
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if ($server.HasExited) { throw 'PHP server exited during startup; check the private PHP log.' }
        try {
            $response = Invoke-WebRequest "$webAddress/sign-in.php" -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch [System.Net.WebException] { Start-Sleep -Milliseconds 200 }
    }
    if (!$ready) {
        if (!$server.HasExited) { Stop-Process -Id $server.Id }
        throw 'Local sign-in readiness check failed. PostgreSQL remains available; inspect logs and run stop.'
    }
    Write-Host "Exhibit is ready at $webAddress. Keep this terminal open."
    Write-Host 'Stop safely from another terminal: powershell -File database\local.ps1 stop'
    $server.WaitForExit()
} catch {
    Write-Error $_
    exit 1
}
