@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
set "PID_FILE=%SCRIPT_DIR%bot.pid"

set "TARGET_PID=%~1"

if "%TARGET_PID%"=="" (
  if exist "%PID_FILE%" (
    set /p TARGET_PID=<"%PID_FILE%"
  ) else (
    echo NO_PID
    exit /b 1
  )
)

set "PID_FILE_ENV=%PID_FILE%"
set "TARGET_PID_ENV=%TARGET_PID%"

powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $pidFile = $env:PID_FILE_ENV; $pid = [int]$env:TARGET_PID_ENV; $proc = Get-Process -Id $pid -ErrorAction Stop; $proc.CloseMainWindow() | Out-Null; Start-Sleep -Milliseconds 500; if (-not $proc.HasExited) { $proc.Kill() } if (Test-Path $pidFile) { Remove-Item $pidFile -ErrorAction SilentlyContinue } Write-Output 'STOPPED' } catch { if (Test-Path $env:PID_FILE_ENV) { Remove-Item $env:PID_FILE_ENV -ErrorAction SilentlyContinue } Write-Output 'NOT_FOUND'; exit 1 }"

endlocal
