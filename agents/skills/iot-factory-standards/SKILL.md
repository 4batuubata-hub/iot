---
name: iot-factory-standards
description: >-
  Standar arsitektur manufaktur internasional (ISO 22400-2, TPM Six Big Losses, SEMI E10),
  firmware Arduino RTC fisik, database triggers, shift reset kebal server mati, dan panduan deployment server perusahaan.
---

# IoT Factory Standards & Architecture Guidelines (ISO 22400-2 & TPM)

## Overview
Skill ini merangkum aturan arsitektur, standar manufaktur internasional, perangkat keras, dan infrastruktur yang **WAJIB dipatuhi** oleh semua AI dan Developer yang memelihara atau mengembangkan sistem Smart Machine Monitoring System (SMMS) dan OEE di PT. CNC.

Sistem telah dimigrasikan secara penuh ke standar internasional:
1. **ISO 22400-2**: *Automation systems and integration — Key performance indicators (KPIs) for manufacturing operations management*.
2. **TPM (Total Productive Maintenance) Six Big Losses**: Kategorisasi kerugian produksi tanpa manipulasi whitelist.
3. **SEMI E10**: Standar keandalan, ketersediaan, dan pemeliharaan mesin (*Equipment Reliability, Availability, and Maintainability*).

---

## 1. Standar Metrik Manufaktur & OEE (ISO 22400-2)

### A. Formula Matematis OEE Murni (Bebas Manipulasi)
Sistem menghitung 3 pilar OEE murni sesuai ISO 22400-2 via `calculateStandardOEE()` di `helper_jadwal.php`:
- **Planned Production Time (PPT)**:
  $$PPT = \text{Total Waktu Kerja Efektif Slot Shift} + \text{Durasi Lembur Resmi}$$
- **Operating Time (OT)**:
  $$OT = \max(0, PPT - \text{Total Real Downtime})$$
- **Availability ($A$)**:
  $$A = \frac{OT}{PPT} \times 100\% \quad (\text{dibatasi maksimal } 100\%)$$
- **Performance ($P$)**:
  $$P = \frac{\text{Total Produk OK} \times \text{Cycle Time Ideal (detik)}}{OT} \times 100\%$$
- **Quality ($Q$)**:
  $$Q = \frac{\text{Total Produk OK}}{\text{Total Produk OK} + \text{Scrap Defect}} \times 100\% \quad (\text{dibatasi maksimal } 100\%)$$
- **OEE Overall**:
  $$OEE = \frac{A \times P \times Q}{10000} \quad (\%)$$

### B. Penghapusan Deceptive Whitelist
- **TIDAK ADA SMART BREAK / WHITELIST:** Segala bentuk manipulasi yang menganulir losstime personal (Toilet, Minum, Sholat) saat target tercapai telah **DIHAPUS SECARA TOTAL**.
- Semua status non-running dicatat murni ke dalam Pareto Losstime dan diklasifikasikan ke dalam 6 TPM Big Losses.

### C. Batasan Clipping Waktu Shift (Time Clipping)
- **Perlindungan Jam Pra-Shift (`work_start` & `work_end`):**
  Clipping downtime menggunakan `work_start` dan `work_end` riil template (Jadwal A: 07:00–16:00, Jadwal B: 07:30–16:30), BUKAN jam reset cron (06:00/06:30).
  Hal ini melindungi jam persiapan (06:00–07:00) agar mesin mati sebelum jam kerja TIDAK dihukum sebagai downtime.
- **Akumulasi Blackout Berjalan (Ongoing Downtime):**
  Jika mesin mati/timeout pada jam shift, downtime terus bertambah hingga $\min(\text{time}(), \text{shift\_end\_ts})$ sebagai *Unplanned Downtime* sah.

---

## 2. Standar Firmware Arduino & Transmisi Edge (Node-RED)

### A. Cap Waktu Fisik Perangkat Keras (RTC)
- Firmware Arduino/ESP32 (`top3.ino` dan `top8-FIX.ino`) membaca waktu fisik riil dari modul RTC DS3231/DS1307 dan menyertakannya ke payload JSON MQTT:
  ```cpp
  DateTime now = rtc.now();
  snprintf(timestamp, sizeof(timestamp), "%04d-%02d-%02dT%02d:%02d:%02d",
           now.year(), now.month(), now.day(), now.hour(), now.minute(), now.second());
  doc["timestamp"] = timestamp;
  ```
- Node-RED (`flows-FIX.json` node `cb6629c14e852d22`) men-sanitasi dan memasukkan `d.timestamp` ke MySQL:
  `let ts = d.timestamp ? d.timestamp.replace('T', ' ') : null;`
  Hal ini menjamin data membawa cap waktu fisik nyata meskipun terjadi delay jaringan atau buffering.

### B. Tipe Data Counter & Wear-Leveling EEPROM
- **32-Bit Unsigned Long:** Semua variabel counter (`prodCount`, `OKCount`, `NGCount`) WAJIB bertipe `unsigned long` 32-bit untuk mencegah overflow 16-bit (-32K).
- **Ring Buffer Wear-Leveling 100 Slot:** Autosave counter ke EEPROM dilakukan berkala dengan sistem rolling buffer 100 slot agar cip EEPROM tidak aus/terbakar.
- **Ethernet Cold Boot:** Wajib menambahkan `delay(2000)` dan `digitalWrite(4, HIGH)` saat inisialisasi SPI Ethernet W5100/W5500 untuk menonaktifkan SD Card bawaan.

### C. Batasan Perangkat Keras RFID (SANGAT KETAT)
- **RFID HANYA untuk Autentikasi Operator & Skill Matrix:**
  Modul RFID RC522 pada mesin fisik berfungsi EKSKLUSIF untuk otentikasi login operator dan interlock relay mesin (Skill Matrix Level 1–4).
- **DILARANG MENGGUNAKAN KARTU RFID UNTUK LEMBUR:**
  Persetujuan lembur (*Overtime Approval*) dilakukan murni secara DIGITAL melalui menu web `mesin_override` oleh Foreman/Supervisor dengan kolom status `status_lembur ENUM('NONE', 'PENDING', 'APPROVED', 'REJECTED')`.

---

## 3. Benteng Pertahanan Database (MySQL Triggers)

### A. Trigger `before_log_quality_insert`
- Menghitung `delta_prodCount` otomatis dari selisih `NEW.prodCount` terhadap `raw_prodCount` sebelumnya.
- Mengalikan output dengan `cavity` dari tabel `master_ct`.
- Toleransi lonjakan wajar ($\Delta \le 1000$) untuk mengakomodasi reconection setelah server mati atau lag jaringan.
- Auto-recovery jika Arduino reboot (counter berbalik ke $\le 5$).

### B. Trigger `after_log_quality_insert`
- Otomatis mencatat durasi downtime saat mesin berpindah dari status non-running ke status lain.
- **Physical Capping & Jeda Antar-Shift:**
  ```sql
  SET v_durasi = TIMESTAMPDIFF(SECOND, v_start_time, NEW.timestamp);
  IF v_durasi > 14400 OR v_durasi < 0 THEN
      SET v_durasi = 0;
  END IF;
  ```
  Durasi $> 4$ jam (14.400 detik) otomatis diabaikan karena merupakan jeda mesin mati antar-shift / libur akhir pekan, bukan downtime aktif shift.
- **Dukungan Shift 2 Lintas Tengah Malam:**
  Dengan evaluasi `TIMESTAMPDIFF`, kerusakan mesin Shift 2 yang melintasi tengah malam (misal 23:45 s/d 00:15 = 30 menit) tercatat presisi 1.800 detik tanpa terhapus oleh pergantian tanggal.
- **Filter Durasi Mikro:** Hanya durasi $\ge 30$ detik yang dicatat ke `log_downtime`.

---

## 4. Urutan Eksekusi Tutup Buku Shift (`cron_reset.php`)

### A. Urutan Eksekusi Anti-Macet (Strict Execution Order)
Saat pergantian shift (Sapu Ranjau), `cron_reset.php` dan `proses_reset.php` WAJIB mengeksekusi tahapan dengan urutan:
1. **LANGKAH 1 (Snapshot Baseline):** Ambil counter mentah terakhir (`raw_prodCount` / `prodCount`) dari `log_quality`.
2. **LANGKAH 2 (Simpan Baseline):** Simpan nilai tersebut ke `master_mesin.offset_raw_produksi` dan reset `offset_produksi = '0'`.
3. **LANGKAH 3 (Arsip History):** Rekap data shift dengan `calculateStandardOEE()` dan tulis ke `history_summary`.
4. **LANGKAH 4 (Pembersihan Log):** Baru jalankan `DELETE FROM log_quality WHERE mcID = ...`.

*Pelanggaran urutan ini (DELETE sebelum ambil counter) akan menyebabkan counter mesin macet membeku di shift berikutnya!*

### B. Single Source of Truth (SSOT) Produksi
Dashboard live (`api_dashboard.php`) dan halaman detail (`user/detail.php`) menggunakan formula identik:
`$totalProd = $offset_produksi + SUM(delta_prodCount);`
Menjamin konsistensi angka produksi 100% antara rekap harian dan live monitoring.

---

## 5. Kompatibilitas Server Perusahaan (Linux & Windows Production)

1. **Huruf Kecil Konsisten (Linux Case-Sensitivity):**
   Semua query SQL di file PHP WAJIB menggunakan huruf kecil untuk nama tabel (`master_mesin`, `log_quality`, `master_jam_statis`, dll.) agar tidak terjadi fatal error pada server Linux (`lower_case_table_names = 0`).
2. **Ketiadaan Hardcoded Path:**
   Gunakan selalu path relatif (`../` atau `__DIR__`). Dilarang mencantumkan path lokal seperti `C:\xampp\`.
3. **PHP 8.x Type-Safety:**
   Selalu berikan nilai default untuk array key (`$info['work_start'] ?? $waktu_mulai`) dan lindungi seluruh operasi pembagian dari division by zero.
4. **Perintah Update Telegram:**
   Jika user mengucapkan kata kunci `update tele`, jalankan perintah:
   ```bash
   C:\xampp\php\php.exe C:\xampp\htdocs\iot\agents\scripts\send_tele.php "Deskripsi update"
   ```

---

## Common Mistakes to Avoid
- **Menganulir Downtime:** Jangan pernah membuat pengecualian downtime untuk alasan "target sudah tercapai". Ini melanggar ISO 22400-2.
- **Menggunakan `DATE != DATE` di Trigger:** Menghancurkan kalkulasi downtime Shift 2 yang melintasi tengah malam.
- **Menggunakan RFID untuk Lembur:** RFID hanya untuk operator shift & interlock mesin, lembur disetujui via digital override web.
- **Menghapus Log Sebelum Ambil Offset:** Selalu simpan offset counter ke `master_mesin` sebelum menjalankan `DELETE`.
