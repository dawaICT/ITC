param(
    [string]$Php = 'C:\xampp\php\php.exe',
    [string]$Composer = 'C:\xampp\htdocs\wucportal\composer.phar'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    $version = & $Php -r 'echo PHP_VERSION;'
    if ([version]$version -lt [version]'8.2.0') { throw "PHP 8.2+ required; found $version" }
    & $Php $Composer validate --strict
    if ($LASTEXITCODE) { throw 'Composer validation failed' }
    & $Php $Composer install --no-dev --classmap-authoritative --no-interaction
    if ($LASTEXITCODE) { throw 'Dependency installation failed' }
    & $Php $Composer audit --locked
    if ($LASTEXITCODE) { throw 'Dependency audit failed' }
    & $Php scripts\lint_all.php
    if ($LASTEXITCODE) { throw 'PHP syntax validation failed' }
    & $Php scripts\backup_database.php
    if ($LASTEXITCODE) { throw 'Pre-deployment backup failed' }
    & $Php scripts\migrate.php
    if ($LASTEXITCODE) { throw 'Database migration failed' }
    & $Php scripts\production_preflight.php
    if ($LASTEXITCODE) { throw 'Production preflight failed' }
    Write-Host 'DEPLOYMENT GATES PASSED' -ForegroundColor Green
} finally {
    Pop-Location
}
