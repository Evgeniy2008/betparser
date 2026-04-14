@echo off
setlocal

cd /d "%~dp0"

set "BETPARSER_SOURCE_URL=https://snecked-lucio-unskinned.ngrok-free.dev"
set "BETPARSER_PULL_INTERVAL_MS=8000"

echo ==========================================
echo Betparser: запуск pull-воркера
echo SOURCE: %BETPARSER_SOURCE_URL%
echo INTERVAL: %BETPARSER_PULL_INTERVAL_MS% ms
echo ==========================================
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js не найден в PATH.
  pause
  exit /b 1
)

node tools\live_pull_worker.js
