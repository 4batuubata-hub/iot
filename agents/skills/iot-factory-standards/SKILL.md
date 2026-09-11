---
name: iot-factory-standards
description: >-
  Standar arsitektur dan logika bisnis untuk sistem IoT Pabrik PT. Mencakup algoritma EEPROM Wear-Leveling, aturan Time-Capping OEE, bypass Cron via Node-RED, dan penanganan Overflow Integer 32-bit (Anti-Glitch EMI).
---

# IoT Factory Standards & Architecture Guidelines

## Overview
Skill ini merangkum aturan-aturan logika, perangkat keras, dan infrastruktur yang harus ditaati oleh semua AI dan Developer yang mengerjakan proyek sistem IoT di lingkungan pabrik ini.
Skill ini dirancang khusus berdasarkan riwayat masalah (`update_perkembangan.txt`) untuk mengatasi berbagai limitasi nyata di lapangan, seperti pemadaman listrik, restriksi Administrator IT, noise elektromagnetik (EMI) pada mesin pabrik, dan manipulasi jadwal lembur.

## Workflow

Kapanpun Anda (AI) ditugaskan untuk memperbaiki, menambah fitur, atau merombak sistem IoT (baik web PHP, Arduino, atau Node-RED) di proyek ini, Anda WAJIB mematuhi instruksi berikut:

### 1. Perhitungan OEE dan Waktu Shift (Time Capping)
- **TIDAK ADA STATUS "OFF SHIFT" DI DASHBOARD LIVE:** Halaman dashboard utama tidak boleh menimpa status mesin menjadi "OFF SHIFT". Tampilan status harus murni berdasarkan kondisi operasional terakhir dari mesin (Running, Standby, Offline). Status 'OFF SHIFT' hanya boleh dituliskan sementara oleh fungsi Cron di tabel master saat malam penutupan buku.
- **RUMUS PPT (Planned Production Time) IRISAN:** Jangan menghitung PPT secara mentah berdasarkan jam kerja utuh. PPT harus dihitung dari IRISAN (intersection) antara slot jam jadwal kerja resmi melawan waktu mulai (start) aktual mesin berjalan. Jika mesin telat menyala 2 jam, maka durasi 2 jam tersebut tidak boleh dimasukkan ke dalam PPT (sehingga mesin tidak menerima hukuman *downtime* 2 jam tersebut).
- **PENGUNCIAN WAKTU (Time Capping) UNTUK RESET SHIFT:** Sistem auto-reset (*Sapu Ranjau*) tidak boleh sembarangan mengiris jam mesin. 
    - Saat melakukan reset di malam/pagi hari, batas jam akhir shift yang dicatat (Dynamic End Time) adalah yang TERBESAR dari: Jadwal Pulang vs Waktu Aktivitas Terakhir Mesin.
    - **Lembur Siluman:** Jika mesin masih bekerja melebihi jadwal pulang, maka batas di-lock ke detik terakhir mesin berproduksi.
    - **Pulang Cepat:** Jika mesin dimatikan sebelum jadwal, batas di-lock ke jam jadwal pulang, sehingga selisih waktu tersebut dihukum sebagai Waktu Kosong (Downtime). 
    - **Kesimpulan:** Waktu kosong dari jadwal akhir shift ke waktu patroli Cron (jam 18:00) tidak pernah dimasukkan ke dalam losstime OEE.

### 2. Standar Kode Arduino (Hardware & EEPROM)
- **ANTI INTEGER OVERFLOW (-32K):** Semua perhitungan *counter* produksi (OKCount, NGCount, prodCount) WAJIB menggunakan tipe data `unsigned long` 32-bit untuk mencegah limit memori 16-bit (angka 32.767 berbalik menjadi minus).
- **FILTER I2C & EMI (Anti-Glitch):** Berikan filter saat membaca data sensor (khususnya I2C dari Nano). Jika nilai yang didapat adalah `65535` atau terjadi lonjakan >500 barang dalam 1 detik, anggap sebagai *noise / EMI / Glitch* dan buang data tersebut.
- **ISU HARDWARE COLD BOOT ETHERNET (W5100/W5500):** Modul Ethernet sering gagal *booting* saat mesin pertama kali dinyalakan (Cold Boot). WAJIB menambahkan `delay(2000);` dan `pinMode(4, OUTPUT); digitalWrite(4, HIGH);` pada awal fungsi setup Ethernet untuk memastikan ketersediaan jalur SPI dan menonaktifkan SD Card bawaan Shield.
- **EFISIENSI DATA EEPROM:** Jangan menyimpan akumulasi total durasi Downtime secara lokal ke EEPROM karena data Downtime sifatnya dikirim langsung secara *real-time* ke MQTT. EEPROM harus didedikasikan sepenuhnya HANYA untuk menyimpan *Total Cycle Count* dan *NG Count* (variabel 4-byte `unsigned long`) agar jika mesin mati, hitungan produksi tidak ter- *reset* dari 0.
- **AUTOSAVE EEPROM DENGAN WEAR-LEVELING:** Jangan mengandalkan RAM yang mudah terhapus saat mesin berkedip. Hitungan mesin harus di-*autosave* setiap 1 menit ke EEPROM. 
    - Karena EEPROM mudah rusak/aus, **WAJIB** menerapkan struktur "Ring Buffer Wear-Leveling" sebesar 100-slot (atau lebih). 
    - Fungsi `setup()` harus bisa otomatis membaca dan memuat slot EEPROM mana yang terbaru saat mesin mati hidup.
    - *Long-Press* tombol fisik selama 5 detik digunakan sebagai "Hard-Reset" yang akan membersihkan total (zero-out) semua memori EEPROM kembali ke 0.
    - *Long-Press* tombol CTRL selama 3 detik digunakan sebagai "Software Reboot" yang akan menyimpan otomatis EEPROM sebelum reboot.

### 3. Infrastruktur Latar Belakang (Cron Jobs)
- **DILARANG MENGGUNAKAN WINDOWS TASK SCHEDULER:** Prosedur eksekusi rutinitas otomatis *(Cron Job)* tidak boleh bergantung pada file `.bat` atau Task Scheduler bawaan OS karena sistem keamanan perusahaan memblokir hak akses Administrator.
- **GUNAKAN NODE-RED UNTUK SCHEDULING:** Selalu gunakan **Node-RED** bawaan sistem untuk membuat otomasi jadwal waktu. 
    - Pasangkan `Inject` node (mode ulangi / Interval) dan sambungkan ke `HTTP Request` node (Method: GET).
    - Biarkan Node-RED memanggil target API PHP setiap X menit di latar belakang tanpa halangan dari IT Security.

### 4. Pelaporan Update via Telegram
- **KATA KUNCI "update tele":** Jika user mengucapkan kata kunci `update tele`, Anda (AI) WAJIB langsung menjalankan skrip PHP pengirim otomatis dengan perintah berikut di terminal:
  ```bash
  C:\xampp\php\php.exe C:\xampp\htdocs\iot\agents\scripts\send_tele.php "Isi dengan deskripsi detail perubahan yang baru saja dilakukan"
  ```
- **Prosedur Internal Skrip:** Skrip `send_tele.php` tersebut sudah terprogram secara mandiri untuk mengompres (*zip*) folder `iot` (mengecualikan `git`), menguncinya dengan password `Merah123`, dan mengirimkannya langsung ke Telegram (`@Createmoonbot`) milik user. 
- **Kompatibilitas ZIP Lintas OS:** Direktori `agents` dan `git` tidak lagi menggunakan awalan titik (dot) untuk menghindari error / hidden file pada Windows bawaan saat diekstrak. Skrip akan membackup folder-folder tersebut ke dalam ZIP secara utuh.
- **Kompatibilitas Ekstraktor Windows Bawaan:** Windows Explorer versi standar TIDAK mendukung enkripsi ZIP modern (AES-256) dan akan memunculkan *error* atau file korup saat diekstrak. Skrip ZIP berbasis PHP WAJIB dikonfigurasi menggunakan metode enkripsi tradisional (`ZipArchive::EM_TRAD_PKWARE`) agar user bisa langsung *Extract All...* dari Windows tanpa butuh WinRAR/7-Zip.
- **Kredensial Telegram (Referensi AI):**
  - **Chat ID User:** `2057077079`
  - **Bot Token:** `5619749746:AAET5C5PoczxCnd-p_-DQ9HraceecRAMYRs`
  - **Password ZIP:** `Merah123`

### 5. Arsip Sejarah Pengembangan
Seluruh catatan panjang riwayat pengembangan, *trial and error*, dan studi kasus mesin telah dipindahkan dari lokasi awal (`irfan/update_perkembangan.txt`) dan kini diamankan (diarsipkan) secara permanen di dalam *Skill* ini pada direktori:
`C:\xampp\htdocs\iot\.agents\skills\iot-factory-standards\update_perkembangan.txt`
Jika Anda (AI) butuh konteks mendalam mengenai *error* masa lalu, silakan baca file arsip tersebut.

## Common Mistakes
- **Menggunakan Integers 16-Bit:** Sering terjadi saat mengembangkan Arduino Uno/Nano/Mega secara natural, namun mengakibatkan data pabrik menghilang tiba-tiba saat menyentuh angka 32 ribu.
- **Pemaksaan Reset Tepat Jam Berakhir:** Jangan memaksa program PHP untuk me-reset data tepat di menit akhir jam kerja. Biarkan program reset berpatroli (Sapu Ranjau), karena ada konsep Lembur Otomatis. 
- **Membuang Logika EEPROM Wear-Leveling:** Menulis nilai OEE secara konstan ke alamat `0` dari EEPROM secara terus-menerus akan menghancurkan (membakar) cip hanya dalam 1 bulan operasi pabrik.
