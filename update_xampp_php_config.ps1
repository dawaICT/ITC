# PowerShell Script to Update XAMPP PHP Configuration for 500MB Uploads
# Run this as Administrator

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  XAMPP PHP Upload Config Updater" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$phpIniPath = "C:\xampp\php\php.ini"

# Check if running as administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "WARNING: Not running as Administrator!" -ForegroundColor Yellow
    Write-Host "Some operations may fail. Right-click and 'Run as Administrator'`n" -ForegroundColor Yellow
}

# Check if php.ini exists
if (-not (Test-Path $phpIniPath)) {
    Write-Host "ERROR: php.ini not found at: $phpIniPath" -ForegroundColor Red
    Write-Host "Please check your XAMPP installation path.`n" -ForegroundColor Red
    pause
    exit
}

Write-Host "Found php.ini at: $phpIniPath" -ForegroundColor Green

# Backup php.ini
$backupPath = "$phpIniPath.backup." + (Get-Date -Format "yyyyMMdd_HHmmss")
Write-Host "Creating backup: $backupPath" -ForegroundColor Yellow
Copy-Item $phpIniPath $backupPath
Write-Host "Backup created successfully!`n" -ForegroundColor Green

# Read current content
$content = Get-Content $phpIniPath

# Settings to update
$settings = @{
    'upload_max_filesize' = '500M'
    'post_max_size' = '550M'
    'max_execution_time' = '300'
    'max_input_time' = '300'
    'memory_limit' = '512M'
}

Write-Host "Updating PHP configuration..." -ForegroundColor Cyan

$updated = $false
$newContent = @()

foreach ($line in $content) {
    $lineUpdated = $false
    
    foreach ($setting in $settings.Keys) {
        # Match both commented and uncommented lines
        if ($line -match "^;?\s*$setting\s*=") {
            $newLine = "$setting = $($settings[$setting])"
            Write-Host "  $setting : $($settings[$setting])" -ForegroundColor Green
            $newContent += $newLine
            $lineUpdated = $true
            $updated = $true
            break
        }
    }
    
    if (-not $lineUpdated) {
        $newContent += $line
    }
}

# Write updated content
if ($updated) {
    $newContent | Set-Content $phpIniPath -Encoding UTF8
    Write-Host "`nConfiguration file updated successfully!" -ForegroundColor Green
} else {
    Write-Host "`nWARNING: No settings were found to update!" -ForegroundColor Yellow
    Write-Host "The php.ini file may have an unexpected format.`n" -ForegroundColor Yellow
}

# Show restart instructions
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Next Steps" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "1. Open XAMPP Control Panel" -ForegroundColor White
Write-Host "2. Stop Apache" -ForegroundColor White
Write-Host "3. Start Apache" -ForegroundColor White
Write-Host "4. Test at: http://localhost/wucportal/update_php_config.php`n" -ForegroundColor White

# Ask if user wants to restart Apache automatically
$restart = Read-Host "Do you want to restart Apache now? (Y/N)"

if ($restart -eq 'Y' -or $restart -eq 'y') {
    Write-Host "`nRestarting Apache..." -ForegroundColor Yellow
    
    # Try to stop Apache
    $apacheService = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
    if ($apacheService) {
        Stop-Process -Name "httpd" -Force
        Write-Host "Apache stopped" -ForegroundColor Green
        Start-Sleep -Seconds 2
    }
    
    # Try to start Apache (requires XAMPP path)
    $xamppPath = "C:\xampp\apache\bin\httpd.exe"
    if (Test-Path $xamppPath) {
        Start-Process $xamppPath -WorkingDirectory "C:\xampp\apache\bin"
        Write-Host "Apache started" -ForegroundColor Green
    } else {
        Write-Host "Could not find Apache executable." -ForegroundColor Yellow
        Write-Host "Please restart manually from XAMPP Control Panel." -ForegroundColor Yellow
    }
} else {
    Write-Host "`nPlease restart Apache manually from XAMPP Control Panel.`n" -ForegroundColor Yellow
}

Write-Host "========================================`n" -ForegroundColor Cyan
Write-Host "Configuration complete!" -ForegroundColor Green
Write-Host "Backup saved at: $backupPath`n" -ForegroundColor Cyan

pause
