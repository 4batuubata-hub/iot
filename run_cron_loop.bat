@echo off
:: ========================================================================
:: MES IOT FACTORY - BACKGROUND AUTO RESET ENGINE (LIVE MONITOR)
:: ========================================================================
title MES IoT - Background Auto Reset Engine (Live Monitor)
color 0A

setlocal enabledelayedexpansion
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

cls
echo ========================================================================
echo         MES IOT FACTORY - BACKGROUND AUTO-RESET MONITOR CONSOLE
echo ========================================================================
echo  PHP Path    : %PHP_BIN%
echo  Direktori   : %~dp0
echo  Interval    : Setiap 60 Detik
echo  Fungsi      : Memeriksa pergantian Shift 1, Shift 2, Lembur, dan OEE
echo  Status      : AKTIF (JANGAN TUTUP JENDELA INI JIKA MONITORING AKTIF)
echo ========================================================================
echo.

:loop
echo [%date% %time%] Memeriksa status shift dan auto-reset pabrik...
"%PHP_BIN%" "%~dp0cron_reset.php"
echo [%date% %time%] Pengecekan selesai. Menunggu siklus berikutnya (60 detik)...
echo ------------------------------------------------------------------------
timeout /t 60 /nobreak >nul
goto loop
