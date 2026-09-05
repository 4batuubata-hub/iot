# 🏭 OEE Dashboard & SMMS IoT System (PT. CNC)

Sistem OEE (*Overall Equipment Effectiveness*) Dashboard ini terintegrasi langsung dengan hardware IoT (Arduino Mega/ESP32) di lantai produksi PT. CNC.

Sistem terdiri dari dua bagian utama:
1. **IoT Firmware (`SMMS_Mega.ino`)**: Menangani hardware mesin, RFID, tombol downtime, perhitungan cycle time, dan pengiriman data ke server MQTT via Ethernet/WiFi.
2. **Web Dashboard (PHP + MySQL)**: Menerima data (via Node-RED ke MySQL), menghitung OEE secara *realtime*, dan menyimpan *history* produksi.

---

## 📁 Struktur File & Penjelasan

### 1. Dashboard Utama (Realtime)
- **`index.php`**
  Halaman utama yang menampilkan status seluruh mesin secara *realtime*. Mesin yang statusnya "RUN" berkedip hijau, "STANDBY" kuning, dan "ALARM" merah. Data direfresh setiap 2 detik via AJAX.
- **`api_dashboard.php`**
  Backend API (dipanggil oleh `index.php`). Menghitung OEE *realtime* (Availability, Performance, Quality) untuk shift yang sedang berjalan.
- **`detail.php`**
  Halaman detail saat sebuah mesin di-klik dari dashboard. Menampilkan rincian performa per-jam (*dekidaka*), profil operator aktif (berdasarkan login RFID), dan tabel pareto *downtime* (jika mesin *standby* namun tidak mencetak barang, maka akan dihitung sebagai *speed loss*).

### 2. History & Arsip Produksi
- **`history/index.php`**
  Dashboard *history* produksi yang menampilkan data produksi hari-hari sebelumnya berdasarkan Shift. Dilengkapi filter kalender.
- **`history/detail.php`**
  Rincian data produksi *historical* mesin. Menampilkan *dekidaka* lengkap, profil operator yang bertugas pada shift tersebut, dan total perhitungan scrap/repair.
- **`cron_reset.php`** (Arsitektur Baru)
  Script background (CLI) yang dieksekusi secara periodik setiap pergantian shift. Script ini memindahkan data ratusan ribu baris secara massal ke `history_*` tanpa membebani dan membuat hang *dashboard* frontend.
- **`setup_cron.bat`** (Baru)
  Script otomasi / *installer* Windows Task Scheduler. Menjadwalkan `cron_reset.php` agar berjalan menggunakan *user* `SYSTEM` di *background* tiap 10 menit. Karena berjalan sebagai SYSTEM, reset shift dan perpindahan data tetap bekerja penuh meskipun PC server hanya sampai *Lock Screen* (belum *login* user).
- **`history/proses_reset.php`**
  Didepresiasi/digantikan oleh `cron_reset.php`. Sebelumnya digunakan untuk manual reset, namun tidak direkomendasikan untuk skala data besar perusahaan.

### 3. Pengaturan Master Data
- **`pengaturan_line.php`**
  Pengaturan jam *auto-reset* per-shift, dan penempatan mesin ke Line tertentu.
- **`pengaturan_jam.php`**
  Membuat Template Jam Kerja (contoh: JADWAL KERJA A, JADWAL B). Mengatur jam mulai/selesai istirahat (jam non-efektif).
- **`skill_matrix.php`**
  Pemetaan keahlian operator (`master_operator`) pada mesin (`master_mesin` menggunakan kode mesin / `mcID`). Level 1-4. IoT akan mengunci interlock jika level < 3.
- **`data_operator.php`**
  Kelola data NIK, Nama, dan Foto operator.

### 4. Keamanan & Akses (RBAC)
- **`login.php`**
  Halaman login (jika auth diaktifkan).
- **`logout.php`**
  Keluar dari sistem.
- **`auth_check.php`**
  Di-include di atas semua file PHP untuk memblokir akses jika user belum login.
- **`settings_auth.php`**
  Halaman khusus Admin/IT untuk menambah user (Admin, IT, User) dan menyalakan/mematikan paksa kewajiban login.

---

## 🔄 Alur Integrasi IoT ke Web

1. **Setup Mesin**: Teknisi menekan CTRL + PROGRAM+ di mesin, memasukkan ID Mesin. Node-RED mengecek `master_mesin` di MySQL dan membalas valid/invalid. Tersimpan di EEPROM.
2. **Login RFID**: Operator menempelkan ID Card (RFID PN532). Node-RED mencari NIK, mengecek `skill_matrix`. Jika skill < 3, mesin tetap *Interlock* (terkunci). Jika skill >= 3, mesin *Unlock*.
3. **Produksi**: IoT membaca sensor *Counter* dan status mesin (RUN/STANDBY/ALARM). IoT mem-publish JSON secara konstan ke Node-RED, yang me-Log datanya ke tabel `log_quality`.
4. **Downtime**: Operator menekan 1 dari 25 tombol downtime. IoT mengirim durasinya saat mesin kembali RUN. Masuk ke tabel `log_downtime`.
5. **Dashboard Web**: `index.php` membaca `log_quality`, menghitung OEE berdasarkan `master_ct` dan jam kerja aktual, lalu menampilkannya secara langsung.
6. **Reset Shift (Cron/Task Scheduler)**: Pada jam pergantian shift (misal 16:00 atau 06:00), Windows Task Scheduler memicu `cron_reset.php`. Tabel `log_*` yang sangat besar secara background dipindahkan ke `history_*`. Mesin memulai produksi di lembaran tabel baru yang kosong sehingga akses data tetap super cepat.

---

## 🎯 Fitur Khusus: Smart Break (Target-Aware Loss Time)

Sistem OEE CNC dilengkapi dengan algoritma toleransi istirahat otomatis yang menjamin keadilan perhitungan performa (OEE) bagi operator yang bekerja cepat. 

- **Masalah:** Operator yang berhasil menyelesaikan target harian lebih cepat (atau dalam *bucket* jam fleksibel/overlap) sering kali dihukum oleh OEE jika mesin mereka berstatus `Stand By` atau `Mesin Off` di sisa waktu luang mereka.
- **Solusi Smart Break:** Sistem akan mengevaluasi pencapaian Aktual vs Target di setiap jam. Jika di suatu jam **Aktual $\ge$ Target**, maka sistem akan secara otomatis **menganulir (menghapus)** status-status *downtime* yang bersifat personal/istirahat dari perhitungan *Loss Time* dan *Pareto Chart*.
- **Daftar Putih (Whitelist) Status yang Dimaafkan:**
  1. `Stand By`
  2. `Mesin Off`
  3. `Toilet`, `Minum`, `Sholat`
- **Dampak pada OEE:** Karena *Loss Time* personal dianulir saat target tercapai, nilai **Availability** akan tetap 100%. Waktu Operasi (*Operating Time*) tetap utuh, sehingga nilai **Performance** operator bisa meroket secara proporsional hingga di atas 100% (contoh: 115%, 145%) sesuai dengan rasio kecepatan kerja mereka yang melampaui standar *Cycle Time*. 
- **Catatan Penting:** Kerusakan mesin (`Alarm`, `Problem Mesin`, dll) **TETAP DICATAT** sebagai *Loss Time* mutlak, terlepas dari target tercapai atau tidak, agar tetap masuk laporan Maintenance.

---

## 🕒 Fitur Khusus: Otomatis Lembur (*Sequential Overtime*)

Dashboard OEE dan Grafik History dilengkapi dengan logika perakitan keranjang waktu (bucket) dinamis jika jam produksi mesin melampaui jadwal standar (khususnya untuk *shift* malam dimana admin absen):
- **Sequential Buckets**: Jika mesin memproduksi barang melebihi jadwal akhir shift (contoh: > 04:30 pagi), sistem akan dengan cerdas menyambung waktu lembur dalam rentang 1 jam berurutan yang menempel erat dari sisa jadwal terakhir (`04:30 - 05:30`, `05:30 - 06:30`, dst).
- **Anti Tumpang Tindih**: Menghilangkan masalah *visual glitch* grafik bertabrakan/tumpang tindih (overlap) antara jam riil dan jam lembur (menghindari keranjang buatan seperti `04:00 - 05:00` yang menindih `03:30 - 04:30`).
- **Otomasi Penuh**: Admin tidak perlu lagi menekan tombol 'Quick Action Lembur' secara manual. Selama mesin mencetak produk, grafik *realtime* maupun *history* akan otomatis meregang ke samping secara presisi tanpa campur tangan manusia.

---

## ⚡ Arsitektur Performa Tinggi (Enterprise-Scale)

Untuk mengatasi masalah *hang* / `Connecting to Machine...` yang disebabkan oleh ratusan ribu data per-shift, sistem menggunakan arsitektur performa tinggi:

1. **`delta_prodCount` & MySQL Trigger**: 
   Database tidak lagi membebani *web server* PHP dengan *loop array* besar untuk menghitung total produk. Kolom `delta_prodCount` merekam penambahan produksi di tingkat DB menggunakan trigger `before_log_quality_insert`. Trigger ini secara otomatis memfilter lonjakan data (*noise*), menangani hitungan *cavity* produk, dan menyimpan selisih penambahan akhir.
2. **`SUM(delta_prodCount)` Aggregation**: 
   Script `api_dashboard.php` bekerja dengan sangat cepat (~1 detik) hanya bermodalkan satu perintah SQL Agregat `SUM(delta_prodCount)` dari database.
3. **Decoupled Background Shift Reset (`cron_reset.php`)**:
   Logika pembersihan data akhir shift (Safe Reset) sepenuhnya dipisahkan dari frontend dashboard web, untuk mencegah tabel MySQL ter-*lock* saat user sedang mengakses web.

---

## 🛠️ Konfigurasi Tambahan

- **Database**: `koneksi.php`
- **Default Login IT**: `admin` / `password` (jika sistem auth diaktifkan).
- **Timezone**: Seluruh query di PHP memaksakan zona waktu `SET time_zone = '+07:00'` (WIB / Waktu Indonesia Barat) agar tidak terjadi selisih jam saat deploy server.
