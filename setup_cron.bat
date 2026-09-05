@echo off
echo ===================================================
echo Mendaftarkan Task Scheduler untuk IoT Cron Reset...
echo ===================================================
echo.
echo Pastikan Anda menjalankan script ini sebagai Administrator (Klik Kanan -^> Run as Administrator)
echo.

:: Path XAMPP PHP dan cron_reset.php (sesuaikan jika XAMPP diinstall di tempat lain)
set PHP_PATH=C:\xampp\php\php.exe
set SCRIPT_PATH=C:\xampp\htdocs\iot\cron_reset.php

if not exist "%PHP_PATH%" (
    echo [ERROR] PHP tidak ditemukan di %PHP_PATH%
    echo Silakan edit file bat ini jika XAMPP ada di folder lain.
    pause
    exit /b
)

if not exist "%SCRIPT_PATH%" (
    echo [ERROR] Script cron_reset.php tidak ditemukan di %SCRIPT_PATH%
    pause
    exit /b
)

:: Mendaftarkan task scheduler untuk berjalan setiap 10 menit setiap hari
schtasks /create /tn "IOT_Cron_Reset" /tr "\"%PHP_PATH%\" -f \"%SCRIPT_PATH%\"" /sc minute /mo 10 /ru SYSTEM /rl HIGHEST /F

echo.
echo [SUKSES] Task "IOT_Cron_Reset" berhasil dibuat!
echo Cron job akan otomatis berjalan di background setiap 10 menit tanpa memunculkan jendela hitam.
echo.
pause
