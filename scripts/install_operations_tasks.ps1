param(
    [string]$Php = 'C:\xampp\php\php.exe',
    [string]$PortalRoot = 'C:\xampp\htdocs\wucportal'
)
$ErrorActionPreference = 'Stop'
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$backupAction = New-ScheduledTaskAction -Execute $Php -Argument 'scripts\backup_database.php' -WorkingDirectory $PortalRoot
$backupTrigger = New-ScheduledTaskTrigger -Daily -At '02:00'
Register-ScheduledTask -TaskName 'WUCPortal-DailyBackup' -Action $backupAction -Trigger $backupTrigger -User $identity -RunLevel Highest -Force | Out-Null

$checkAction = New-ScheduledTaskAction -Execute $Php -Argument 'scripts\check_operations.php' -WorkingDirectory $PortalRoot
$checkTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName 'WUCPortal-OperationsCheck' -Action $checkAction -Trigger $checkTrigger -User $identity -RunLevel Highest -Force | Out-Null
Write-Host 'Installed WUCPortal-DailyBackup and WUCPortal-OperationsCheck.'
