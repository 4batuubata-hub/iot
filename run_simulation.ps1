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
    $stopTimeInput = Read-Host "Masukkan jam berhenti (contoh: 11:30) atau tekan Enter untuk default 8 jam"
    
    $hours = 8.0
    if (![string]::IsNullOrWhiteSpace($stopTimeInput)) {
        try {
            $now = Get-Date
            $stopTime = Get-Date $stopTimeInput
            
            if ($stopTime -lt $now) {
                # Jika waktu yang dimasukkan sudah lewat hari ini, asumsikan untuk besok
                $stopTime = $stopTime.AddDays(1)
            }
            
            $timeDiff = $stopTime - $now
            $hours = $timeDiff.TotalHours
            
            Write-Host "Simulasi akan otomatis berhenti pada: $($stopTime.ToString('HH:mm:ss')) (Durasi: $([math]::Round($hours, 2)) jam)" -ForegroundColor Green
        } catch {
            Write-Host "Format jam tidak valid. Menggunakan default 8 Jam." -ForegroundColor Red
        }
    } else {
        Write-Host "Menjalankan mode default: 8 Jam (Realtime speed 1.0x)..." -ForegroundColor Green
    }

    Write-Host "Tips: Anda bisa menjalankan dengan kecepatan lebih tinggi, misal:" -ForegroundColor Gray
    Write-Host "  .\run_simulation.ps1 --speed 5.0 --hours 8.0" -ForegroundColor Gray
    Write-Host "---------------------------------------------------------" -ForegroundColor DarkGray
    
    $hoursStr = [math]::Round($hours, 4).ToString([System.Globalization.CultureInfo]::InvariantCulture)
    & uv run --with pymysql --with paho-mqtt python simulate_production.py --speed 1.0 --hours $hoursStr
}
