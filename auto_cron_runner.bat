@echo off
REM =============================================================
REM  auto_cron_runner.bat
REM  
REM  Jalankan file ini untuk mengeksekusi cron_auto_recalc.php
REM  secara otomatis setiap 30 menit.
REM
REM  CARA SETUP OTOMATIS (Windows Task Scheduler):
REM  1. Buka Task Scheduler (taskschd.msc)
REM  2. Create Task > Name: "IoT Auto Recalc"
REM  3. Trigger: Daily, repeat every 30 minutes
REM  4. Action: Start a program
REM     Program: C:\xampp\htdocs\iot\auto_cron_runner.bat
REM  5. Conditions: uncheck "Start only if AC power"
REM  6. Settings: check "Run task as soon as possible after missed"
REM =============================================================

set PHP_PATH=C:\xampp\php\php.exe
set SCRIPT_PATH=C:\xampp\htdocs\iot\cron_auto_recalc.php
set LOG_PATH=C:\xampp\htdocs\iot\logs\cron_auto_recalc.log

REM Buat folder logs jika belum ada
if not exist "C:\xampp\htdocs\iot\logs" mkdir "C:\xampp\htdocs\iot\logs"

echo.
echo ============================================
echo  IoT Auto Recalc - %DATE% %TIME%
echo ============================================

REM Jalankan PHP script dan log outputnya
"%PHP_PATH%" "%SCRIPT_PATH%" >> "%LOG_PATH%" 2>&1

echo Selesai. Log disimpan di: %LOG_PATH%
