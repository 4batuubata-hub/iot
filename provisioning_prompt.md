Kamu adalah DevOps & System Administrator AI. Tugasmu adalah melakukan "Zero-Touch Provisioning" (setup otomatis dari nol) pada server Windows RDP ini untuk lingkungan IoT. 

Gunakan tool eksekusi command (PowerShell) untuk menjalankan langkah-langkah di bawah ini secara berurutan. Jangan banyak bertanya, langsung eksekusi script-nya per tahap dan laporkan jika ada error.

**Langkah 1: Set Zona Waktu ke Jakarta (WIB)**
Jalankan command PowerShell ini untuk mengubah zona waktu Windows:
`Set-TimeZone -Id "SE Asia Standard Time"`

**Langkah 2: Install Dependensi Utama (Silent Install)**
Gunakan `winget` untuk menginstal Node.js, Git, XAMPP, dan Mosquitto secara otomatis tanpa konfirmasi:
`winget install -e --id OpenJS.NodeJS -h --accept-source-agreements --accept-package-agreements`
`winget install -e --id Git.Git -h --accept-source-agreements --accept-package-agreements`
`winget install -e --id ApacheFriends.Xampp.8.2 -h --accept-source-agreements --accept-package-agreements`
`winget install -e --id EclipseFoundation.Mosquitto -h --accept-source-agreements --accept-package-agreements`

**Langkah 3: Buka Port Firewall Windows**
Buka port untuk Web, MySQL, Node-RED, dan MQTT agar bisa diakses dari luar. Jalankan PowerShell:
`New-NetFirewallRule -DisplayName "IoT Services" -Direction Inbound -LocalPort 80,443,3306,1883,1880 -Protocol TCP -Action Allow`

**Langkah 4: Konfigurasi Mosquitto MQTT**
1. Tambahkan konfigurasi agar Mosquitto menerima koneksi dari luar. Jalankan PowerShell:
`Add-Content -Path "C:\Program Files\Mosquitto\mosquitto.conf" -Value "listener 1883`nallow_anonymous true"`
2. Restart service Mosquitto:
`Restart-Service -Name "mosquitto"`

**Langkah 5: Setup XAMPP (PHP Timezone & MySQL)**
1. Ubah timezone di `php.ini` XAMPP ke Jakarta. Jalankan PowerShell:
`(Get-Content "C:\xampp\php\php.ini") -replace ';date.timezone =', 'date.timezone = "Asia/Jakarta"' | Set-Content "C:\xampp\php\php.ini"`
2. Jalankan Apache dan MySQL (pastikan berjalan di background):
`Start-Process -FilePath "C:\xampp\xampp_start.exe" -WindowStyle Hidden`

**Langkah 6: Kloning Repositori GitHub**
Kloning repository project ke folder `htdocs` XAMPP:
`git clone https://github.com/4batuubata-hub/iot C:\xampp\htdocs\iot`

**Langkah 7: Import Database**
Setelah MySQL berjalan, import struktur database awal (asumsikan ada file `.sql` di repository, sesuaikan jika lokasinya berbeda):
`C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS iot;"`

**Langkah 8: Install & Jalankan Node-RED**
1. Install Node-RED secara global:
`npm install -g --unsafe-perm node-red`
2. Jalankan Node-RED sebagai background daemon/task menggunakan tool kamu (biarkan menyala di background).

**Langkah 9: Import Flow Node-RED**
1. Setelah Node-RED menyala (tunggu sekitar 10-15 detik), baca file flow target:
`C:\xampp\htdocs\iot\irfan\flows-FIX.json`
2. Identifikasi node koneksi MySQL di dalam file JSON tersebut. Pastikan username diset ke `"root"` dan password dikosongkan `""`.
3. Gunakan tool HTTP Request / PowerShell `Invoke-RestMethod` untuk mem-POST (mengimport) isi JSON flow tersebut ke API Node-RED lokal di: `http://localhost:1880/flow`
4. Pastikan flow sudah aktif.

Tolong eksekusi dari Langkah 1 sekarang dan laporkan statusnya jika sudah selesai sampai Langkah 9!
