# Script Peluncur Simulator OEE PT CNC
# Menjamin uv dan python runtime tersedia, lalu menjalankan simulator.

$env:Path = "$HOME\.local\bin;$env:Path"

Write-Host "=========================================================" -ForegroundColor Cyan
Write-Host "  MEMULAI SIMULATOR PRODUKSI 10 MESIN - PT CNC OEE       " -ForegroundColor Yellow
Write-Host "=========================================================" -ForegroundColor Cyan

# Parameter default atau teruskan dari argumen baris perintah
$speed = "1.0"
$hours = "8.0"

if ($args.Count -gt 0) {
    & uv run --with pymysql --with paho-mqtt python simulate_production.py @args
} else {
    Write-Host "Menjalankan mode default: 8 Jam (Realtime speed 1.0x)..." -ForegroundColor Green
    Write-Host "Tips: Anda bisa menjalankan dengan kecepatan lebih tinggi, misal:" -ForegroundColor Gray
    Write-Host "  .\run_simulation.ps1 --speed 5.0 --hours 8.0" -ForegroundColor Gray
    Write-Host "---------------------------------------------------------" -ForegroundColor DarkGray
    & uv run --with pymysql --with paho-mqtt python simulate_production.py --speed 1.0 --hours 8.0
}
