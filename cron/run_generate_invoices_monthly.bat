@echo off
REM Run monthly invoice generation (Windows VPS only - use with Task Scheduler).
REM Schedule: 12:01 AM on the 1st of every month, timezone Eastern (USA).
REM On Linux/macOS use crontab instead (see cron/README.md).
REM Edit SCRIPT_DIR and PHP_EXE below if needed.

set SCRIPT_DIR=C:\wamp64\www\sinegu-api
REM Use latest PHP (edit path if your WAMP PHP version is different)
set PHP_EXE=C:\wamp64\bin\php\php8.4.0\php.exe

"%PHP_EXE%" "%SCRIPT_DIR%\generate_invoices.php"
