@echo off
REM ---------------------------------------------------------------------------
REM  Zello proxy - unattended launcher (Windows)
REM
REM  The Windows counterpart to proxy/newui-zello-proxy.service.example.
REM  That unit file gives Linux installs Restart=on-failure, RestartSec=5s and
REM  log redirection; this gives Windows the same three things, since there is
REM  no equivalent shipped and the interactive start-proxy.bat cannot be used
REM  unattended (it ends in PAUSE, so it needs a logged-in session).
REM  (GH openises/TicketsCAD#18)
REM
REM  Register it, from an ELEVATED prompt, adjusting the path:
REM
REM    schtasks /Create /TN "TicketsCAD Zello Proxy" /SC ONSTART ^
REM      /RU SYSTEM /RL HIGHEST /F ^
REM      /TR "C:\inetpub\wwwroot\TicketsCAD\proxy\start-proxy-service.bat"
REM
REM  /SC ONSTART means it comes back after a reboot, which a process started
REM  by hand in a console window does not. Start it now without rebooting:
REM
REM    schtasks /Run /TN "TicketsCAD Zello Proxy"
REM
REM  Stop it with:
REM
REM    schtasks /End /TN "TicketsCAD Zello Proxy"
REM    taskkill /F /IM php.exe        (only if you have no other PHP CLI work)
REM
REM  PHP: uses php.exe from PATH. Override with TICKETSCAD_PHP.
REM ---------------------------------------------------------------------------

setlocal

cd /d "%~dp0.." || exit /b 1

if "%TICKETSCAD_PHP%"=="" set "TICKETSCAD_PHP=php"

set "LOGDIR=%CD%\cache\job-logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" 2>nul
set "LOGFILE=%LOGDIR%\zello-proxy.log"

"%TICKETSCAD_PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo [%DATE% %TIME%] FATAL: could not run "%TICKETSCAD_PHP%" - is PHP on PATH^? Set TICKETSCAD_PHP to the full path to php.exe.>> "%LOGFILE%"
    exit /b 1
)

REM Restart loop, mirroring the unit file's Restart=on-failure / RestartSec=5s.
REM No attempt limit: a proxy that gives up permanently after a transient
REM failure is how an install ends up silently without Zello for a shift.
:loop
echo [%DATE% %TIME%] starting zello-proxy.php>> "%LOGFILE%"
"%TICKETSCAD_PHP%" proxy\zello-proxy.php >> "%LOGFILE%" 2>&1
echo [%DATE% %TIME%] zello-proxy.php exited with %ERRORLEVEL% - restarting in 5s>> "%LOGFILE%"
timeout /t 5 /nobreak >nul
goto loop
