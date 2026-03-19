@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
set "APP_DIR=%SCRIPT_DIR%app"
set "PID_FILE=%SCRIPT_DIR%bot.pid"
set "VENV_PYTHON=%SCRIPT_DIR%venv\Scripts\python.exe"

if exist "%PID_FILE%" del "%PID_FILE%"

if not exist "%APP_DIR%" (
  echo ERROR: App directory missing>&2
  exit /b 1
)

set "PYTHON_EXE=%PYTHON_BOT_EXE%"
if "%PYTHON_EXE%"=="" (
  if exist "%VENV_PYTHON%" (
    set "PYTHON_EXE=%VENV_PYTHON%"
  ) else (
    set "PYTHON_EXE=python"
  )
)

set "APP_DIR_ENV=%APP_DIR%"
set "PID_FILE_ENV=%PID_FILE%"
set "PYTHON_EXE_ENV=%PYTHON_EXE%"

powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $appDir = $env:APP_DIR_ENV; $pidFile = $env:PID_FILE_ENV; $pythonExe = $env:PYTHON_EXE_ENV; if (-not (Test-Path $appDir)) { throw 'App directory missing.' }; $scriptPath = Join-Path $appDir 'ws_main.py'; try { $resolved = (Get-Command $pythonExe -ErrorAction Stop).Source } catch { throw ('Python executable not found: ' + $pythonExe) }; $args = @('-u', $scriptPath); $proc = Start-Process -FilePath $resolved -ArgumentList $args -WorkingDirectory $appDir -WindowStyle Hidden -PassThru; Set-Content -Path $pidFile -Value $proc.Id -Encoding ASCII; Write-Output ('PID:' + $proc.Id) } catch { Write-Output ('ERROR:' + $_.Exception.Message); exit 1 }"

endlocal
