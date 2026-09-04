<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");
$tanggal_history = date('Y-m-d'); // Tanggal data ini disimpan

function getLogicalDay($timestamp = null) {
    if ($timestamp === null) $timestamp = time();
    $hour = date('H', $timestamp);
    $day_num = date('N', $timestamp); // 1 = Monday, 7 = Sunday
    
    if ($hour < 6) {
        $day_num--;
        if ($day_num == 0) $day_num = 7; // Sunday wrap
    }
    
    if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
    if ($day_num == 5) return 'JUMAT';
    if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
    
    return 'SENIN-KAMIS';
}

// 1. Get Maximum IDs for each table to safely isolate records
$max_q = $conn->query("SELECT MAX(id) as m FROM log_quality")->fetch_assoc()['m'] ?? 0;
$max_ng = $conn->query("SELECT MAX(id) as m FROM log_ng")->fetch_assoc()['m'] ?? 0;
$max_dt = $conn->query("SELECT MAX(id) as m FROM log_downtime")->fetch_assoc()['m'] ?? 0;

if ($max_q == 0 && $max_ng == 0 && $max_dt == 0) {
    echo "<script>alert('Tidak ada data produksi untuk di-reset!'); window.location.href='index.php';</script>";
    exit;
}

// Start Transaction to avoid data loss
$conn->begin_transaction();
try {
    // 2. COPY KE TABEL HISTORY MENGGUNAKAN BATAS MAKSIMAL ID (Pilih Kolom Secara Eksplisit)
    if ($max_q > 0) $conn->query("INSERT IGNORE INTO history_quality (timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, raw_prodCount, NGCount) SELECT timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, raw_prodCount, NGCount FROM log_quality WHERE id <= $max_q");
    if ($max_ng > 0) $conn->query("INSERT IGNORE INTO history_ng (timestamp, mcID, kode_proses, kode_ng, qty_ng) SELECT timestamp, mcID, kode_proses, kode_ng, qty_ng FROM log_ng WHERE id <= $max_ng");
    if ($max_dt > 0) $conn->query("INSERT IGNORE INTO history_downtime (mcID, kode_dt, durasi_detik, timestamp) SELECT mcID, kode_dt, durasi_detik, timestamp FROM log_downtime WHERE id <= $max_dt");

    // 3. HITUNG SUMMARY UNTUK KARTU HISTORY SEBELUM DIHAPUS
    $res_mesin = $conn->query("SELECT mcID, MIN(timestamp) as min_ts, MAX(timestamp) as max_ts FROM log_quality WHERE id <= $max_q GROUP BY mcID");

    if ($res_mesin && $res_mesin->num_rows > 0) {
        while($row = $res_mesin->fetch_assoc()) {
            $mcID = $row['mcID'];
            $waktu_mulai = $row['min_ts'];
            $waktu_selesai = $row['max_ts'];
            
            // Ambil info nama mesin & part terakhir sesuai batasan id
            $info = $conn->query("SELECT m.nama_mesin, c.part_name, c.ct_pcs, c.line FROM log_quality lq LEFT JOIN master_mesin m ON (lq.mcID = m.id_mesin OR lq.mcID = m.mcID) LEFT JOIN master_ct c ON lq.kode_proses = c.kode WHERE lq.mcID = '$mcID' AND lq.id <= $max_q ORDER BY lq.id DESC LIMIT 1")->fetch_assoc();
            
            $nama_mesin = $info['nama_mesin'] ?? $mcID;
            $part_name = $info['part_name'] ?? 'Tidak Diketahui';
            $line_m = $info['line'] ?? '';
            $template_aktif = 'DEFAULT';
            if (!empty($line_m)) {
                $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line_m' LIMIT 1");
                if ($res_tpl && $res_tpl->num_rows > 0) $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
            }
            $ideal_ct = floatval($info['ct_pcs'] ?? 0);

            // Hitung Total Produksi dengan Delta-Accumulation (Tahan Reset ESP32 & Filter Noise)
            $res_p = $conn->query("SELECT lq.prodCount, IFNULL(c.cavity, 1) as cavity 
                                   FROM log_quality lq 
                                   LEFT JOIN master_ct c ON lq.kode_proses = c.kode 
                                   WHERE lq.mcID = '$mcID' AND lq.id <= $max_q 
                                   ORDER BY lq.id ASC");
            $prod = 0;
            if ($res_p && $res_p->num_rows > 0) {
                $offset_produksi = $conn->query("SELECT offset_produksi FROM master_mesin WHERE id_mesin = '$mcID' OR mcID = '$mcID'")->fetch_assoc()['offset_produksi'] ?? 0;
                $prev_p = (int)$offset_produksi;
                while ($rp = $res_p->fetch_assoc()) {
                    $curr_p = (int)$rp['prodCount'];
                    if ($curr_p < 0) continue; // Abaikan overflow negatif

                    $cavity = (int)($rp['cavity'] ?? 1);
                    if ($cavity < 1) $cavity = 1;
                    $max_delta = 50 * $cavity;

                    $delta = $curr_p - $prev_p;
                    if ($delta > 0 && $delta <= $max_delta) {
                        $prod += $delta;
                        $prev_p = $curr_p;
                    } else if ($delta < 0 && $curr_p <= $max_delta) {
                        // Reset dari 0
                        $prod += $curr_p;
                        $prev_p = $curr_p;
                    } else if ($delta == 0) {
                        // Tidak ada perubahan stroke
                    } else {
                        // Delta > max_delta: Noise spike! Abaikan tanpa memajukan prev_p
                        continue;
                    }
                }
            }
            $ng = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM log_ng WHERE mcID = '$mcID' AND id <= $max_ng")->fetch_assoc()['qty'] ?? 0;
            
            // Hitung True OEE Historis
            $hari_history = getLogicalDay(strtotime($tanggal_history));
            $tpl_esc = $conn->real_escape_string($template_aktif);
            // REKAP HARIAN mencakup semua shift (TIDAK DIBATASI by shift)
            $sql_slots = "SELECT menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND (hari = '$hari_history' OR hari = 'SETIAP HARI')";
            $res_slots = $conn->query($sql_slots);
            $ppt_seconds = 0;
            if ($res_slots && $res_slots->num_rows > 0) {
                while($r_slot = $res_slots->fetch_assoc()) {
                    $ppt_seconds += ((int)$r_slot['menit_efektif'] * 60);
                }
            }
            
            $total_dt_r = $conn->query("SELECT COALESCE(SUM(durasi_detik), 0) as total_dt FROM log_downtime WHERE mcID = '$mcID' AND id <= $max_dt")->fetch_assoc()['total_dt'] ?? 0;
            $operating_time_seconds = $ppt_seconds - $total_dt_r;
            if ($operating_time_seconds < 0) $operating_time_seconds = 0;
            
            $a = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
            $p = ($operating_time_seconds > 0) ? (($ideal_ct * $prod) / $operating_time_seconds) * 100 : 0;
            $q = ($prod > 0) ? (($prod - $ng) / $prod) * 100 : 0;
            
            if ($a > 100) $a = 100;
            if ($p > 100) $p = 100;
            if ($q < 0) $q = 0;
            
            $oee = ($a * $p * $q) / 10000;

            // Simpan ke Summary (Manual reset kita anggap REKAP HARIAN)
            $conn->query("INSERT IGNORE INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                          VALUES ('$tanggal_history', 'REKAP HARIAN', '$mcID', '$nama_mesin', '$part_name', '$prod', '$ng', '$oee', '$a', '$p', '$q', '$waktu_mulai', '$waktu_selesai', NOW())");
            
            // Solusi Zombie Data ESP32: Simpan prodCount terakhir sebagai offset untuk shift berikutnya
            $last_row = $conn->query("SELECT prodCount, raw_prodCount FROM log_quality WHERE mcID = '$mcID' ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $last_prod = $last_row['prodCount'] ?? 0;
            $last_raw = $last_row['raw_prodCount'] ?? 0;
            $conn->query("UPDATE master_mesin SET catStatus = 'OFF SHIFT', offset_produksi = '$last_prod', offset_raw_produksi = '$last_raw' WHERE mcID = '$mcID' OR id_mesin = '$mcID'");
        }
    }

    // 4. KOSONGKAN TABEL TRANSAKSI
    // PENTING: Kita menggunakan DELETE dan bukan TRUNCATE karena TRUNCATE menyebabkan 
    // Implicit Commit di MySQL yang akan merusak fungsi ROLLBACK jika terjadi error.
    $conn->query("DELETE FROM log_quality");
    $conn->query("DELETE FROM log_ng");
    $conn->query("DELETE FROM log_downtime");
    
    // Override lembur bisa dibersihkan semuanya pada manual reset
    $conn->query("TRUNCATE TABLE mesin_override");

    $conn->commit();
    echo "<script>alert('Tutup Buku Berhasil! Dashboard telah dikosongkan dan data masuk ke History dengan AMAN.'); window.location.href='index.php';</script>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<script>alert('Terjadi kesalahan SQL saat memindahkan history: " . addslashes($e->getMessage()) . "'); window.location.href='index.php';</script>";
}
?>