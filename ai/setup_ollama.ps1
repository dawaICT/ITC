# ITC Portal — Ollama local AI setup (Windows / PowerShell)
#
# Installs chat + embedding models the portal expects, then verifies the API.
# Run after Ollama is installed:  powershell -ExecutionPolicy Bypass -File ai\setup_ollama.ps1

$ErrorActionPreference = "Stop"
$OllamaExe = $null
foreach ($candidate in @(
    "$env:LOCALAPPDATA\Programs\Ollama\ollama.exe",
    "C:\Program Files\Ollama\ollama.exe",
    (Get-Command ollama -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source)
)) {
    if ($candidate -and (Test-Path $candidate)) {
        $OllamaExe = $candidate
        break
    }
}

if (-not $OllamaExe) {
    Write-Host "FAIL: Ollama is not installed."
    Write-Host "Install with: winget install --id Ollama.Ollama -e"
    Write-Host "Or download: https://ollama.com/download/windows"
    exit 1
}

Write-Host "Using: $OllamaExe"
& $OllamaExe --version

# Ensure the API is up (installer usually starts the app)
$apiUp = $false
for ($i = 1; $i -le 30; $i++) {
    try {
        $null = Invoke-WebRequest -Uri "http://127.0.0.1:11434/api/tags" -UseBasicParsing -TimeoutSec 2
        $apiUp = $true
        break
    } catch {
        Start-Sleep -Seconds 2
    }
}
if (-not $apiUp) {
    Write-Host "Starting Ollama serve in background..."
    Start-Process -FilePath $OllamaExe -ArgumentList "serve" -WindowStyle Hidden
    Start-Sleep -Seconds 5
}

$models = @(
    "nomic-embed-text",   # Skill Discovery embeddings
    "llama3.2:3b",        # Default chat model (ai/config.php)
    "deepseek-r1:1.5b"    # Optional fallback chat model
)

foreach ($model in $models) {
    Write-Host ""
    Write-Host "=== Pulling $model ==="
    & $OllamaExe pull $model
    if ($LASTEXITCODE -ne 0) {
        Write-Host "WARN: pull failed for $model (continuing)"
    }
}

Write-Host ""
Write-Host "=== Installed models ==="
& $OllamaExe list

Write-Host ""
Write-Host "PASS: Ollama setup finished."
Write-Host "Portal tip: set WUC_AI_BACKEND=local and keep ENTERPRISE_AI_ENABLED=true for enterprise drafting."
Write-Host "Verify: C:\xampp\php\php.exe scripts\debug_enterprise_ai.php"
