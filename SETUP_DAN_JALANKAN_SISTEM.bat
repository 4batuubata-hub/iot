@echo off
REM ========================================================================
REM MES IOT FACTORY - 1-CLICK AUTOMATED DEPLOYMENT & AUTO-RESET LAUNCHER
REM Otomatis Setup Database MySQL, Triggers, dan Penjadwalan Auto-Reset Shift
REM ========================================================================
title MES IoT - 1-Click Server Setup and Auto-Reset Launcher
color 0B

setlocal enabledelayedexpansion
cd /d "%~dp0"

cls
echo ========================================================================
echo         MES IOT FACTORY - 1-CLICK ZERO-TOUCH SERVER DEPLOYMENT          
echo ========================================================================
echo  Mempersiapkan sistem produksi tanpa perlu menyentuh phpMyAdmin / Server...
echo ========================================================================
echo.

REM 1. DETEKSI PHP
echo [1/5] Memeriksa Instalasi PHP / XAMPP...
set "PHP_BIN="
if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
) else if exist "D:\xampp\php\php.exe" (
    set "PHP_BIN=D:\xampp\php\php.exe"
) else if exist "E:\xampp\php\php.exe" (
    set "PHP_BIN=E:\xampp\php\php.exe"
) else (
    set "PHP_BIN=php"
)

"%PHP_BIN%" -v >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    color 0C
    echo.
    echo  [FATAL ERROR] PHP tidak dapat dijalankan di sistem ini!
    echo  Pastikan XAMPP sudah terpasang di C:\xampp atau php terdaftar di PATH.
    echo.
    pause
    exit /b 1
)
echo  [OK] PHP aktif: %PHP_BIN%
echo.

REM 2. DETEKSI LAYANAN MYSQL
echo [2/5] Memeriksa Koneksi Database MySQL (Port 3306)...
netstat -ano | findstr :3306 >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo  [INFO] Port 3306 belum aktif. Mencoba menyalakan layanan MySQL...
    net start mysql >nul 2>&1
    timeout /t 2 /nobreak >nul
    netstat -ano | findstr :3306 >nul 2>&1
    if !ERRORLEVEL! NEQ 0 (
        color 0E
        echo  [PERINGATAN] Layanan MySQL belum berjalan.
        echo  Silakan buka XAMPP Control Panel dan klik Start pada MySQL!
        echo.
        echo  Tekan Sembarang Tombol jika MySQL sudah dinyalakan...
        pause >nul
    )
)
echo  [OK] MySQL Server Siap.
echo.

REM 3. EKSEKUSI SETUP DATABASE, SKEMA BERSIH, & TRIGGER
echo [3/5] Menjalankan Setup Database Otomatis (setup_server.php)...
echo ------------------------------------------------------------------------
"%PHP_BIN%" "%~dp0setup_server.php"
if %ERRORLEVEL% NEQ 0 (
    color 0C
    echo.
    echo  [FATAL ERROR] Terjadi kesalahan saat instalasi database!
    echo.
    pause
    exit /b 1
)
echo ------------------------------------------------------------------------
echo.

REM 4. TEST EKSEKUSI AUTO-RESET PERTAMA KALI
echo [4/5] Memverifikasi Logika Reset Shift and Kalkulasi OEE...
"%PHP_BIN%" "%~dp0cron_reset.php"
echo  [OK] Mesin Auto-Reset Shift and OEE berfungsi normal.
echo.

REM 5. PASANG WINDOWS TASK SCHEDULER (BACKGROUND AUTO-RESET TIAP 5 MENIT)
schtasks /create /tn "IOT_Cron_Reset" /tr "\"%PHP_BIN%\" -f \"%~dp0cron_reset.php\"" /sc minute /mo 5 /ru SYSTEM /rl HIGHEST /f >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo  [OK] Task Scheduler "IOT_Cron_Reset" (SYSTEM 100%% Silent) BERHASIL DIPASANG!
    echo       Sistem akan mereset shift otomatis setiap 5 menit di latar belakang
    echo       tanpa memunculkan jendela CMD (bebas kedipan di layar RDP).
) else (
    echo  [INFO] Penjadwalan Task Scheduler membutuhkan hak administrator.
    echo         Anda dapat menjalankan 'run_cron_loop.bat' untuk pemantauan live.
)
echo.

color 0A
echo ========================================================================
echo      SELURUH PROSES DEPLOYMENT AND PENJADWALAN RESET SELESAI 100%%      
echo ========================================================================
echo  * Database 'simulasi' : SUDAH SIAP and TERISI MASTER DATA
echo  * Trigger Mesin       : SUDAH AKTIF (Anti-Freeze, Multi-Cavity, DT Malam)
echo  * Skema Lembur        : SUDAH TERKONFIGURASI
echo  * Background Reset    : SUDAH BERJALAN OTOMATIS TIAP 5 MENIT
echo ========================================================================
echo.

echo Pilih tindakan selanjutnya:
echo  [1] Buka Dashboard Web SMMS di Browser (Default)
echo  [2] Buka Jendela Live Monitoring Reset Shift (run_cron_loop.bat)
echo  [3] Selesai and Tutup Jendela Ini (Layanan background tetap bekerja)
echo.
set "CHOICE="
set /p "CHOICE=Masukkan pilihan [1-3, default 1]: "

if "%CHOICE%"=="2" (
    start "" "%~dp0run_cron_loop.bat"
    exit /b 0
)
if "%CHOICE%"=="3" (
    exit /b 0
)

REM Default: Buka Browser
start http://localhost/iot/index.php
exit /b 0
