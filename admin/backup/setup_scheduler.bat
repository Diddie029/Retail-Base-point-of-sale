@echo off
REM Backup Scheduler Setup for Windows
REM This script helps set up automatic backups using Windows Task Scheduler

echo ========================================
echo POS System Backup Scheduler Setup
echo ========================================
echo.

REM Get current directory
set SCRIPT_DIR=%~dp0
set PHP_PATH=C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe
set SCHEDULER_SCRIPT=%SCRIPT_DIR%scheduler.php

echo Current directory: %SCRIPT_DIR%
echo PHP path: %PHP_PATH%
echo Scheduler script: %SCHEDULER_SCRIPT%
echo.

REM Check if PHP exists
if not exist "%PHP_PATH%" (
    echo ERROR: PHP not found at %PHP_PATH%
    echo Please update the PHP_PATH variable in this script to match your PHP installation
    echo Common paths:
    echo   - C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe
    echo   - C:\xampp\php\php.exe
    echo   - C:\wamp64\bin\php\php8.1.10\php.exe
    pause
    exit /b 1
)

echo PHP found: %PHP_PATH%
echo.

REM Test the scheduler script
echo Testing scheduler script...
"%PHP_PATH%" "%SCHEDULER_SCRIPT%"
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Scheduler script test failed
    pause
    exit /b 1
)

echo Scheduler script test successful!
echo.

echo ========================================
echo Setting up Windows Task Scheduler
echo ========================================
echo.

REM Create daily backup task
echo Creating daily backup task...
schtasks /create /tn "POS_System_Daily_Backup" /tr "\"%PHP_PATH%\" \"%SCHEDULER_SCRIPT%\"" /sc daily /st 02:00 /f
if %ERRORLEVEL% EQU 0 (
    echo SUCCESS: Daily backup task created
) else (
    echo ERROR: Failed to create daily backup task
)

REM Create weekly backup task
echo Creating weekly backup task...
schtasks /create /tn "POS_System_Weekly_Backup" /tr "\"%PHP_PATH%\" \"%SCHEDULER_SCRIPT%\"" /sc weekly /d SUN /st 03:00 /f
if %ERRORLEVEL% EQU 0 (
    echo SUCCESS: Weekly backup task created
) else (
    echo ERROR: Failed to create weekly backup task
)

echo.
echo ========================================
echo Setup Complete
echo ========================================
echo.
echo The following scheduled tasks have been created:
echo 1. POS_System_Daily_Backup - Runs daily at 2:00 AM
echo 2. POS_System_Weekly_Backup - Runs weekly on Sunday at 3:00 AM
echo.
echo You can manage these tasks using:
echo - Windows Task Scheduler (taskschd.msc)
echo - Command line: schtasks /query /tn "POS_System_Daily_Backup"
echo.
echo To remove tasks:
echo - schtasks /delete /tn "POS_System_Daily_Backup" /f
echo - schtasks /delete /tn "POS_System_Weekly_Backup" /f
echo.

pause