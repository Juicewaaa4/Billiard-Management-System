@echo off
title Zoey's Billiard House - Launcher
echo ============================================
echo   Zoey's Billiard House Management System
echo ============================================
echo.

:: Check if XAMPP is installed
if not exist "C:\xampp\xampp-control.exe" (
    echo [ERROR] XAMPP not found at C:\xampp
    echo Please install XAMPP first, or adjust the path in this script.
    pause
    exit /b 1
)

:: Start Apache if not running
echo Checking Apache...
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I "httpd.exe" >NUL
if %ERRORLEVEL% NEQ 0 (
    echo Starting Apache...
    start "" /B "C:\xampp\apache\bin\httpd.exe"
    timeout /t 2 >NUL
    echo Apache started.
) else (
    echo Apache is already running.
)

:: Start MySQL if not running
echo Checking MySQL...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if %ERRORLEVEL% NEQ 0 (
    echo Starting MySQL...
    start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    timeout /t 3 >NUL
    echo MySQL started.
) else (
    echo MySQL is already running.
)

echo.
echo Starting Billiard System in browser...
timeout /t 1 >NUL

:: Try to open in App Mode (Chrome kiosk-like) for a native feel
where chrome >NUL 2>&1
if %ERRORLEVEL% EQU 0 (
    start "" chrome --app="http://localhost/Billiard%%20System/" --window-size=1280,800
) else (
    :: Fallback: open default browser
    start "" "http://localhost/Billiard%%20System/"
)

echo.
echo System is running! You can close this window.
echo To stop: Open XAMPP Control Panel and stop Apache + MySQL.
timeout /t 5 >NUL
