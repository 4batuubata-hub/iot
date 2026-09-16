@echo off
:: ========================================================================
:: MES IOT FACTORY - SILENT RESET WORKER
:: Berjalan otomatis di latar belakang via Windows Task Scheduler
:: ========================================================================
setlocal
cd /d "%~dp0"

:: Deteksi Lokasi PHP
set "PHP_BIN=C:\xampp\php\php.exe"
if not exist "%PHP_BIN%" (
    if exist "D:\xampp\php\php.exe" set "PHP_BIN=D:\xampp\php\php.exe"
)
if not exist "%PHP_BIN%" (
    if exist "E:\xampp\php\php.exe" set "PHP_BIN=E:\xampp\php\php.exe"
)
if not exist "%PHP_BIN%" (
    for %%P in (php.exe) do set "PHP_BIN=%%~$PATH:P"
)
if "%PHP_BIN%"=="" set "PHP_BIN=php"

:: Log eksekusi dan jalankan reset
echo [%date% %time%] Menjalankan pemeriksaan reset shift... >> "%~dp0cron_reset.log"
"%PHP_BIN%" "%~dp0cron_reset.php" >> "%~dp0cron_reset.log" 2>&1
echo [%date% %time%] Selesai. >> "%~dp0cron_reset.log"
exit /b 0
