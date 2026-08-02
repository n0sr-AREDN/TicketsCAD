@echo off
REM ---------------------------------------------------------------------------
REM  TicketsCAD - Windows background job runner
REM
REM  The Windows counterpart to the systemd timers described in
REM  docs/MAINTENANCE-RUNBOOK.md. Windows has no systemd, so on a Windows/IIS
REM  install these two jobs need a Task Scheduler entry or they never run at
REM  all -- and nothing except Settings -> Status will say so.
REM  (GH openises/TicketsCAD#18)
REM
REM  Register it, from an ELEVATED prompt, adjusting the path:
REM
REM    schtasks /Create /TN "TicketsCAD Background Jobs" /SC MINUTE /MO 1 ^
REM      /RU SYSTEM /RL HIGHEST /F ^
REM      /TR "C:\inetpub\wwwroot\TicketsCAD\tools\run-scheduled-jobs.bat"
REM
REM  One minute is Windows' minimum repeat interval, and it happens to match
REM  both jobs' interval. One task drives both ticks: fewer moving parts than
REM  two tasks, and they are always in step.
REM
REM  Verify it is FIRING, not merely registered -- those look identical from
REM  the Task Scheduler UI. Watch the run counter climb on
REM  Settings -> Status -> Scheduled background jobs across ~75 seconds, or:
REM
REM    schtasks /Query /TN "TicketsCAD Background Jobs" /V /FO LIST
REM
REM  PHP: uses php.exe from PATH. Override by setting TICKETSCAD_PHP to a full
REM  path before calling, e.g. in the task's environment or by editing the
REM  SET line below. It must be php.exe (the CLI binary) -- both scripts
REM  refuse to run under a non-CLI SAPI, so php-cgi.exe will not work.
REM ---------------------------------------------------------------------------

setlocal

REM Repo root is one level up from tools\.
cd /d "%~dp0.." || exit /b 1

if "%TICKETSCAD_PHP%"=="" set "TICKETSCAD_PHP=php"

set "LOGDIR=%CD%\cache\job-logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" 2>nul

REM Fail loudly if PHP is not reachable, rather than writing an empty log
REM every minute forever. A silent scheduled job is the whole reason this
REM file exists.
"%TICKETSCAD_PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo [%DATE% %TIME%] FATAL: could not run "%TICKETSCAD_PHP%" - is PHP on PATH^? Set TICKETSCAD_PHP to the full path to php.exe.>> "%LOGDIR%\run-scheduled-jobs.log"
    exit /b 1
)

set "RC=0"

"%TICKETSCAD_PHP%" tools\par_tick.php >> "%LOGDIR%\par_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\pending_messages_tick.php >> "%LOGDIR%\pending_messages_tick.log" 2>&1
if errorlevel 1 set "RC=1"

REM Both jobs always run: a failure in one must not stop the other. The exit
REM code reports whether either failed, so Task Scheduler's "Last Run Result"
REM is meaningful.
exit /b %RC%
