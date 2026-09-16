@echo off
REM ============================================================
REM  Startup Reconciliation - Jalankan saat Windows boot
REM  Mendaftarkan di Task Scheduler:
REM    schtasks /create /tn "IoT_Startup_Reconcile" /tr "C:\xampp\htdocs\iot\setup_startup.bat" /sc onstart /ru SYSTEM
REM ============================================================

REM Tunggu 30 detik agar MySQL dan Apache siap
timeout /t 30 /nobreak

REM Jalankan reconciliation script
C:\xampp\php\php.exe C:\xampp\htdocs\iot\startup_reconcile.php >> C:\xampp\htdocs\iot\startup_log.txt 2>&1

echo [%date% %time%] Startup Reconciliation Completed >> C:\xampp\htdocs\iot\startup_log.txt
