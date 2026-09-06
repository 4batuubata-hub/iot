<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");
$current_timestamp = time();

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

$sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
$row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
$jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:00:00';

$conn->begin_transaction();
try {
    $machines = $conn->query("SELECT mcID, MIN(timestamp) as min_ts FROM log_quality WHERE id <= $max_q GROUP BY mcID");

    if ($machines && $machines->num_rows > 0) {
        while ($m = $machines->fetch_assoc()) {
            $mcID = $m['mcID'];
            $waktu_mulai = $m['min_ts'];
            $jam_mulai = date('H:i:s', strtotime($waktu_mulai));
            $tanggal_mulai = date('Y-m-d', strtotime($waktu_mulai));
            
            // Tentukan Shift
            $shift_label = 'SHIFT 1';
            $tanggal_history = $tanggal_mulai;
            if ($jam_reset_s1 <= $jam_reset_s2) {
                if ($jam_mulai >= $jam_reset_s1 || $jam_mulai < $jam_reset_s2) {
                    $shift_label = 'SHIFT 2';
                }
            } else {
                if ($jam_mulai >= $jam_reset_s1 || $jam_mulai < $jam_reset_s2) {
                    $shift_label = 'SHIFT 2';
                    if ($jam_mulai < $jam_reset_s2) {
                        $tanggal_history = date('Y-m-d', strtotime('-1 day', strtotime($tanggal_mulai)));
                    }
                }
            }

            // Cari Template & Hitung Waktu Efektif Basis (Scheduled)
            $line_m = $conn->query("SELECT lq.kode_proses, c.line FROM log_quality lq LEFT JOIN master_ct c ON lq.kode_proses = c.kode WHERE lq.mcID = '$mcID' ORDER BY lq.id ASC LIMIT 1")->fetch_assoc()['line'] ?? '';
            $template_aktif = 'DEFAULT';
            if (!empty($line_m)) {
                $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line_m' LIMIT 1");
                if ($res_tpl && $res_tpl->num_rows > 0) $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
            }

            $hari_history = getLogicalDay(strtotime($waktu_mulai));
            $tpl_esc = $conn->real_escape_string($template_aktif);
            $sql_slots = "SELECT jam, menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift_label' AND (hari = '$hari_history' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
            $res_slots = $conn->query($sql_slots);
            
            $ppt_seconds = 0;
            $scheduled_end_jam = '';
            if ($res_slots && $res_slots->num_rows > 0) {
                while($r_slot = $res_slots->fetch_assoc()) {
                    $ppt_seconds += ((int)$r_slot['menit_efektif'] * 60);
                    $parts = explode('-', $r_slot['jam']);
                    if (count($parts) == 2) {
                        $scheduled_end_jam = trim($parts[1]);
                    }
                }
            }
            if (empty($scheduled_end_jam)) {
                $scheduled_end_jam = ($shift_label == 'SHIFT 1') ? $jam_reset_s1 : $jam_reset_s2;
            }

            $scheduled_end_ts = strtotime($tanggal_mulai . ' ' . $scheduled_end_jam);
            if ($shift_label == 'SHIFT 2' && $scheduled_end_jam < '12:00:00' && $jam_mulai >= '12:00:00') {
                $scheduled_end_ts = strtotime('+1 day', $scheduled_end_ts);
            }

            // Cek Override Lembur
            $override_end_ts = 0;
            $res_ov = $conn->query("SELECT jam_selesai_lembur, total_menit FROM mesin_override WHERE mcID = '$mcID' AND tanggal = '$tanggal_history' LIMIT 1");
            if ($res_ov && $res_ov->num_rows > 0) {
                $ov = $res_ov->fetch_assoc();
                $override_end_ts = strtotime($tanggal_history . ' ' . $ov['jam_selesai_lembur']);
                if ($ov['jam_selesai_lembur'] < '12:00:00' && $jam_mulai >= '12:00:00') {
                    $override_end_ts = strtotime('+1 day', $override_end_ts);
                }
                $ppt_seconds += ((int)$ov['total_menit'] * 60);
            }

            // Cek Aktivitas Terakhir (Lembur Siluman)
            $res_active = $conn->query("SELECT MAX(timestamp) as last_active FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND (mcStatus = 'Mesin Running' OR delta_prodCount > 0)");
            $last_active = $res_active->fetch_assoc()['last_active'];
            $last_active_ts = $last_active ? strtotime($last_active) : strtotime($waktu_mulai);
            
            $dynamic_end_ts = max($scheduled_end_ts, $override_end_ts, $last_active_ts);

            // Tambahkan ekstra waktu efektif jika lembur siluman
            if ($override_end_ts == 0 && $last_active_ts > $scheduled_end_ts) {
                $ppt_seconds += ($last_active_ts - $scheduled_end_ts);
            }

            // BATAS WAKTU CAP: 
            // Jika reset manual ditekan sebelum waktunya (Early Close), cap pada current_timestamp
            // Jika reset manual ditekan terlambat (Late Close), cap pada dynamic_end_ts (Standby yang hangus)
            $batas_waktu_cap_ts = min($current_timestamp, $dynamic_end_ts);
            $batas_waktu_str = date('Y-m-d H:i:s', $batas_waktu_cap_ts);

            // --- INSERT HISTORY ---
            if ($max_q > 0) $conn->query("INSERT IGNORE INTO history_quality (timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount) SELECT timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount FROM log_quality WHERE mcID='$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str'");
            if ($max_ng > 0) $conn->query("INSERT IGNORE INTO history_ng (timestamp, mcID, kode_proses, kode_ng, qty_ng) SELECT timestamp, mcID, kode_proses, kode_ng, qty_ng FROM log_ng WHERE mcID='$mcID' AND id <= $max_ng AND timestamp <= '$batas_waktu_str'");
            if ($max_dt > 0) $conn->query("INSERT IGNORE INTO history_downtime (mcID, kode_dt, durasi_detik, timestamp) SELECT mcID, kode_dt, durasi_detik, timestamp FROM log_downtime WHERE mcID='$mcID' AND id <= $max_dt AND timestamp <= '$batas_waktu_str'");

            // Cek Ongoing Downtime (Potong secara proporsional sesuai batas_waktu_cap_ts)
            $res_ongoing = $conn->query("SELECT mcInfo, id as last_id FROM log_quality WHERE mcID='$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str' ORDER BY id DESC LIMIT 1");
            if ($res_ongoing && $res_ongoing->num_rows > 0) {
                $ro = $res_ongoing->fetch_assoc();
                $last_info = trim($ro['mcInfo']);
                $non_downtime = ['Mesin Running', 'Running', 'Off'];
                if (!empty($last_info) && !in_array($last_info, $non_downtime, true)) {
                    $last_info_esc = $conn->real_escape_string($last_info);
                    $res_diff = $conn->query("SELECT id FROM log_quality WHERE mcID = '$mcID' AND mcInfo != '$last_info_esc' AND id <= $max_q AND timestamp <= '$batas_waktu_str' ORDER BY id DESC LIMIT 1");
                    $diff_id = ($res_diff && $res_diff->num_rows > 0) ? $res_diff->fetch_assoc()['id'] : 0;
                    $res_start = $conn->query("SELECT timestamp FROM log_quality WHERE mcID = '$mcID' AND id > $diff_id AND id <= $max_q AND timestamp <= '$batas_waktu_str' ORDER BY id ASC LIMIT 1");
                    if ($res_start && $res_start->num_rows > 0) {
                        $start_time = strtotime($res_start->fetch_assoc()['timestamp']);
                        $durasi_detik = $batas_waktu_cap_ts - $start_time;
                        if ($durasi_detik > 0) {
                            $conn->query("INSERT IGNORE INTO history_downtime (mcID, kode_dt, durasi_detik, timestamp) VALUES ('$mcID', '$last_info_esc', $durasi_detik, '$batas_waktu_str')");
                        }
                    }
                }
            }

            // Hitung Summary OEE (Manual reset kita anggap REKAP HARIAN)
            $info_m = $conn->query("SELECT m.nama_mesin, c.part_name, c.ct_pcs FROM log_quality lq LEFT JOIN master_mesin m ON (lq.mcID = m.id_mesin OR lq.mcID = m.mcID) LEFT JOIN master_ct c ON lq.kode_proses = c.kode WHERE lq.mcID = '$mcID' AND lq.id <= $max_q AND lq.timestamp <= '$batas_waktu_str' ORDER BY lq.id DESC LIMIT 1")->fetch_assoc();
            
            $nama_mesin = $info_m['nama_mesin'] ?? $mcID; 
            $part_name = $info_m['part_name'] ?? 'Tidak Diketahui';
            $ideal_ct = floatval($info_m['ct_pcs'] ?? 0);
            
            // Hitung Total Produksi dengan Delta-Accumulation (Tahan Reset ESP32)
            $res_p = $conn->query("SELECT prodCount FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str' ORDER BY id ASC");
            $prod = 0;
            if ($res_p && $res_p->num_rows > 0) {
                $offset_produksi = $conn->query("SELECT offset_produksi FROM master_mesin WHERE id_mesin = '$mcID' OR mcID = '$mcID'")->fetch_assoc()['offset_produksi'] ?? 0;
                $prev_p = $offset_produksi;
                while ($rp = $res_p->fetch_assoc()) {
                    $curr_p = (int)$rp['prodCount'];
                    $delta = $curr_p - $prev_p;
                    if ($delta > 0) $prod += $delta;
                    else if ($delta < 0) $prod += $curr_p;
                    $prev_p = $curr_p;
                }
            }
            $waktu_selesai = $conn->query("SELECT MAX(timestamp) as max_ts FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['max_ts'] ?? $batas_waktu_str;
            
            $ng = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM log_ng WHERE mcID = '$mcID' AND id <= $max_ng AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['qty'] ?? 0;
            $total_dt_r = $conn->query("SELECT COALESCE(SUM(durasi_detik), 0) as total_dt FROM history_downtime WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['total_dt'] ?? 0;
            
            $operating_time_seconds = $ppt_seconds - $total_dt_r;
            if ($operating_time_seconds < 0) $operating_time_seconds = 0;
            
            $a = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
            $p = ($operating_time_seconds > 0) ? (($ideal_ct * $prod) / $operating_time_seconds) * 100 : 0;
            $q = ($prod > 0) ? (($prod - $ng) / $prod) * 100 : 0;
            
            if ($a > 100) $a = 100;
            if ($p > 100) $p = 100;
            if ($q > 100) $q = 100;
            if ($q < 0) $q = 0;
            $oee = ($a * $p * $q) / 10000;

            $conn->query("INSERT IGNORE INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                          VALUES ('$tanggal_history', 'REKAP HARIAN', '$mcID', '$nama_mesin', '$part_name', '$prod', '$ng', '$oee', '$a', '$p', '$q', '$waktu_mulai', '$waktu_selesai', NOW())");
            
            // Solusi Zombie Data ESP32: Simpan prodCount terakhir sebagai offset untuk shift berikutnya
            $last_row = $conn->query("SELECT prodCount, raw_prodCount FROM log_quality WHERE mcID = '$mcID' ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $last_prod = $last_row['prodCount'] ?? 0;
            $last_raw = $last_row['raw_prodCount'] ?? 0;
            $conn->query("UPDATE master_mesin SET catStatus = 'OFF SHIFT', offset_produksi = '$last_prod', offset_raw_produksi = '$last_raw' WHERE mcID = '$mcID' OR id_mesin = '$mcID'");
        }
    }

    // 4. KOSONGKAN TABEL TRANSAKSI UNTUK SELURUH MESIN YANG DI-RESET
    if ($max_q > 0) $conn->query("DELETE FROM log_quality WHERE id <= $max_q");
    if ($max_ng > 0) $conn->query("DELETE FROM log_ng WHERE id <= $max_ng");
    if ($max_dt > 0) $conn->query("DELETE FROM log_downtime WHERE id <= $max_dt");
    
    // Override lembur dibersihkan semuanya pada manual reset
    $conn->query("TRUNCATE TABLE mesin_override");

    $conn->commit();
    echo "<script>alert('Tutup Buku Berhasil! Dashboard telah dikosongkan secara proporsional dan data masuk ke History dengan AMAN.'); window.location.href='index.php';</script>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<script>alert('Terjadi kesalahan SQL saat memindahkan history: " . addslashes($e->getMessage()) . "'); window.location.href='index.php';</script>";
}
?>