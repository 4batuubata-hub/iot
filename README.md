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
- **`history/proses_reset.php`**
  Script yang dieksekusi setiap pergantian shift (otomatis atau manual via "Tutup Buku"). Memindahkan data realtime dari tabel `log_*` ke tabel `history_*`, dan menghitung final OEE untuk disimpan di `history_summary`.

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
6. **Reset Shift**: Jam mencapai 16:00 atau 06:00 (sesuai setting). `api_dashboard.php` mengeksekusi fungsi "Safe Reset". Memindahkan `log_*` ke `history_*`. Mesin memulai produksi dengan counter mulai dari 0 untuk shift berikutnya.

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

## 🛠️ Konfigurasi Tambahan

- **Database**: `koneksi.php`
- **Default Login IT**: `admin` / `password` (jika sistem auth diaktifkan).
- **Timezone**: Seluruh query di PHP memaksakan zona waktu `SET time_zone = '+07:00'` (WIB / Waktu Indonesia Barat) agar tidak terjadi selisih jam saat deploy server.
