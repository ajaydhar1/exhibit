param(
    [Parameter(Mandatory = $true)]
    [string] $Database,
    [string] $CaBundle,
    [string] $PhpHome = (Join-Path $env:LOCALAPPDATA 'Exhibit\runtimes\php-8.4.26-nts-x64'),
    [switch] $ApplyApprovedMigration,
    [string] $ApprovedSha256,
    [string] $ExpectedEndpointSha256
)

$ErrorActionPreference = 'Stop'
$php = Join-Path $PhpHome 'php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'The isolated PHP executable must exist.'
}
if ($Database -notmatch '^[a-zA-Z0-9_.-]+$') {
    throw 'Use the exact database name shown in the Render dashboard.'
}
if ($ApplyApprovedMigration -and ($ApprovedSha256 -notmatch '^[a-f0-9]{64}$' -or
    $ExpectedEndpointSha256 -notmatch '^[a-f0-9]{64}$')) {
    throw 'Approved apply requires the exact approved SQL checksum and endpoint fingerprint.'
}
if (-not $ApplyApprovedMigration -and ($ApprovedSha256 -or $ExpectedEndpointSha256)) {
    throw 'Approval bindings are only accepted with the explicit ApplyApprovedMigration switch.'
}

function Read-OperationReport([string[]] $Output) {
    $match = [regex]::Match(($Output -join "`n"), '(?ms)^\{.*?^\}')
    if (-not $match.Success) {
        throw 'No complete sanitized operation report was returned. No automatic retry is permitted.'
    }
    return ($match.Value | ConvertFrom-Json)
}

$names = @('DATABASE_URL', 'DB_SSLMODE', 'DB_SSLROOTCERT')
$previous = @{}
foreach ($name in $names) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$secure = $null
$pointer = [IntPtr]::Zero
try {
    if ([string]::IsNullOrEmpty($env:DATABASE_URL)) {
        Write-Output 'Enter the existing Render external PostgreSQL URL privately. Do not paste it into chat.'
        $secure = Read-Host 'DATABASE_URL (input hidden)' -AsSecureString
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        $env:DATABASE_URL = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    $sslMode = $env:DB_SSLMODE
    if ([string]::IsNullOrEmpty($sslMode)) {
        $sslMode = 'verify-full'
    }
    if ($sslMode -notin @('require', 'verify-full')) {
        throw 'DB_SSLMODE must be require or verify-full for the remote preflight.'
    }
    $env:DB_SSLMODE = $sslMode
    if ($sslMode -eq 'verify-full') {
        if ([string]::IsNullOrEmpty($CaBundle) -or
            -not (Test-Path -LiteralPath $CaBundle -PathType Leaf)) {
            throw 'A readable trusted CA bundle is required for verify-full.'
        }
        $env:DB_SSLROOTCERT = (Resolve-Path -LiteralPath $CaBundle).Path
    }
    $phpArguments = @('-n', '-d', "extension_dir=$PhpHome\ext", '-d', 'extension=pdo_pgsql', '-d', 'zend.exception_ignore_args=1')
    $runner = Join-Path $PSScriptRoot 'auth-migrate.php'
    $preflightOutput = & $php @phpArguments $runner --check "--database=$Database"
    $preflightExit = $LASTEXITCODE
    $preflightOutput | Write-Output
    if ($preflightExit -ne 0) {
        throw 'Read-only authentication preflight failed. Share only the sanitized failure, never credentials.'
    }
    if (-not $ApplyApprovedMigration) {
        Write-Output 'STOP: Preflight only. Authentication migration has NOT been applied; separate approval is required.'
    } else {
        $preflight = Read-OperationReport $preflightOutput
        if ($preflight.operation -ne 'preflight' -or $preflight.database -cne $Database -or
            $preflight.endpoint_sha256 -cne $ExpectedEndpointSha256 -or
            $preflight.migration_sha256 -cne $ApprovedSha256 -or
            $preflight.read_only -ne $true -or $preflight.tls_mode -ne $sslMode -or
            $preflight.tls_active -ne $true) {
            throw 'Fresh preflight does not match the approved target/checksum/TLS policy. Nothing applied.'
        }
        Write-Output 'Executing the explicitly approved one-time authentication migration.'
        & $php @phpArguments $runner --apply "--database=$Database" "--sha256=$ApprovedSha256"
        if ($LASTEXITCODE -ne 0) {
            throw 'Apply did not report success. Do not rerun apply; inspect using the read-only verifier.'
        }
        $verificationOutput = & $php @phpArguments $runner --verify "--database=$Database"
        $verificationExit = $LASTEXITCODE
        $verificationOutput | Write-Output
        if ($verificationExit -ne 0) {
            throw 'Apply reported a commit but read-only verification failed. Do not rerun apply.'
        }
        $verification = Read-OperationReport $verificationOutput
        if ($verification.operation -ne 'verification' -or $verification.database -cne $Database -or
            $verification.endpoint_sha256 -cne $ExpectedEndpointSha256 -or
            $verification.migration_sha256 -cne $ApprovedSha256 -or $verification.read_only -ne $true -or
            $verification.tls_mode -ne $sslMode -or $verification.tls_active -ne $true -or
            $verification.schema -ne 'authentication verified') {
            throw 'Post-commit report does not match the approved target and verified schema. Do not rerun apply.'
        }
        Write-Output 'PASS: Approved migration committed once and independent read-only verification passed. Do not rerun apply.'
    }
} finally {
    foreach ($name in $names) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
    if ($pointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
    if ($null -ne $secure) {
        $secure.Dispose()
    }
}
