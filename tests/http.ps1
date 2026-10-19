param(
    [Parameter(Mandatory = $true)]
    [Uri] $BaseUrl
)

$ErrorActionPreference = 'Stop'
if ($BaseUrl.Scheme -notin @('http', 'https') -or
    $BaseUrl.Host -notin @('localhost', '127.0.0.1', '[::1]') -or
    $BaseUrl.UserInfo -ne '' -or $BaseUrl.Query -ne '' -or $BaseUrl.Fragment -ne '') {
    throw 'HTTP checks require a loopback Apache URL without credentials, query, or fragment.'
}

function Get-HttpStatus([string] $RelativePath) {
    $url = $BaseUrl.AbsoluteUri.TrimEnd('/') + '/' + $RelativePath
    try {
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -MaximumRedirection 0 -TimeoutSec 10
        return [int] $response.StatusCode
    } catch [System.Net.WebException] {
        if ($null -eq $_.Exception.Response) {
            throw
        }
        return [int] $_.Exception.Response.StatusCode
    }
}

$root = Split-Path $PSScriptRoot -Parent
$publicFiles = @(Get-ChildItem -LiteralPath $root -Filter '*.php' -File | ForEach-Object { $_.Name })
$publicFiles += @(Get-ChildItem -LiteralPath (Join-Path $root 'assets') -File | ForEach-Object { 'assets/' + $_.Name })
$count = 0
foreach ($path in $publicFiles) {
    $status = Get-HttpStatus $path
    $expected = if ($path -in @('my-exhibitions.php', 'new-exhibition.php', 'studio.php')) { 303 } elseif ($path -eq 'logout.php') { 405 } else { 200 }
    if ($status -ne $expected) {
        throw "Public resource failed: $path (HTTP $status)"
    }
    $count++
}

foreach ($path in @(
    '.env', '.env.example', '.env.production', '.git/config', '.git/HEAD', '%2egit/config',
    'Dockerfile', 'README.md', '.htaccess', 'assets/',
    'backend/database.php', 'backend/exhibitions.php', 'backend/migrations.php', 'backend/auth.php', 'backend/auth-page.php', 'backend/auth-migrations.php', 'backend/schema.php', 'backend/smtp.php', 'backend/resend.php', 'backend/mail.php', 'backend/mail-worker.php', 'backend/',
    'database/migrate.php', 'database/auth-migrate.php', 'database/auth-preflight.ps1', 'database/migrations/001_initial.sql', 'database/migrations/002_authentication.sql',
    'tests/run.php', 'tests/exhibitions.php', 'tests/exhibition-workflow.php', 'tests/postgres.php', 'tests/auth-migrations.php', 'tests/mail.php', 'tests/validate-live.php', 'tests/http.ps1',
    'docs/database.md', 'docs/authentication-activation.md', 'docker/apache-private.conf', 'docker/php-production.ini'
)) {
    $status = Get-HttpStatus $path
    if ($status -notin @(403, 404)) {
        throw "Private resource exposed: $path (HTTP $status)"
    }
    $count++
}

Write-Output "PASS: $count HTTP checks; public screens/assets load and private resources are denied."
