@echo off
setlocal

cd /d "%~dp0"

rem ==================================================
rem Remote push (for scripts running on separate VPS)
rem Uncomment and set your real site URL/token:
rem set "BETPARSER_PUSH_URL=https://websitebets.bionrgg.com/push_live_data.php"
rem set "BETPARSER_PUSH_TOKEN=change_me_secret_token"
rem ==================================================

echo ==========================================
echo Betparser: запуск всех live-воркеров
echo ==========================================
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js не найден в PATH.
  echo Установите Node.js и перезапустите терминал.
  pause
  exit /b 1
)

echo [1/4] Parik24 Football...
start "Parik24 Football" cmd /k "cd /d "%~dp0" && node tools\parik24_live_worker.js"

echo [2/4] Parik24 Basketball...
start "Parik24 Basketball" cmd /k "cd /d "%~dp0" && node tools\parik24_basketball_live_worker.js"

echo [3/4] Parik24 Tennis...
start "Parik24 Tennis" cmd /k "cd /d "%~dp0" && node tools\parik24_tennis_live_worker.js"

echo [4/4] Pinnacle Multi-Sport...
start "Pinnacle Multi-Sport" cmd /k "cd /d "%~dp0" && node tools\pinnacle_live_worker.js"

echo.
echo Все процессы запущены в отдельных окнах.
echo Закрытие окна процесса = остановка соответствующего воркера.
echo.
pause
