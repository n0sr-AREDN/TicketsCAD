@echo off
REM ---------------------------------------------------------------------------
REM  Zello proxy - interactive launcher (Windows)
REM
REM  For running the proxy in a console window while you watch it. For an
REM  unattended install that survives logoff and reboot, use
REM  start-proxy-service.bat with Task Scheduler instead -- this one ends in
REM  PAUSE and so needs a human at the keyboard.
REM
REM  PHP: uses php.exe from PATH. Override with TICKETSCAD_PHP if it is not
REM  there. It must be php.exe, not php-cgi.exe.
REM  (This previously hardcoded one developer's versioned XAMPP interpreter
REM  path, which exists on exactly one machine -- GH openises/TicketsCAD#18.
REM  The path is deliberately not repeated here: tests/test_scheduled_jobs_windows.php
REM  greps every shipped .bat for it, and a gate that reads comments cannot
REM  tell an example from the real thing.)
REM ---------------------------------------------------------------------------

setlocal

title Zello Proxy Server
cd /d "%~dp0.." || exit /b 1

if "%TICKETSCAD_PHP%"=="" set "TICKETSCAD_PHP=php"

"%TICKETSCAD_PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo.
    echo   Could not run "%TICKETSCAD_PHP%".
    echo.
    echo   PHP is not on PATH. Either add it, or set TICKETSCAD_PHP to the
    echo   full path to php.exe, for example:
    echo.
    echo       set TICKETSCAD_PHP=C:\PHP84\php.exe
    echo.
    pause
    exit /b 1
)

"%TICKETSCAD_PHP%" proxy\zello-proxy.php
pause
