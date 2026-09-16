@echo off
:: ========================================================================
:: MES IOT FACTORY - HENTIKAN / HAPUS LAYANAN AUTO-RESET
:: ========================================================================
title MES IoT - Hentikan Layanan Auto Reset
color 0C
cls
echo ========================================================================
echo         MES IOT FACTORY - HAPUS PENJADWALAN AUTO-RESET
echo ========================================================================
echo.
echo Menghapus Task Scheduler 'MES_IoT_AutoReset' dan 'IOT_Cron_Reset'...
schtasks /delete /tn "MES_IoT_AutoReset" /f >nul 2>&1
schtasks /delete /tn "IOT_Cron_Reset" /f >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo.
    echo [OK] Layanan background auto-reset berhasil dihentikan dan dihapus!
) else (
    echo.
    echo [INFO] Layanan tidak ditemukan atau sudah dihapus sebelumnya.
)
echo.
pause
