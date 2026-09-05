<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);
$conn->query("SET time_zone = '+07:00'");

echo "Starting Reset Process...\n";

$sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2, jam_reset FROM setting_pabrik LIMIT 1");
$row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
$jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? ($row_setting['jam_reset'] ?? '06:00:00');
$now_time = date('H:i:s');
$today_date = date('Y-m-d');

function getLogicalDay($time) {
    $now = date('H:i:s', $time);
    $day_num = date('N', $time); 
    
    if ($now < '07:00:00') {
        $day_num = $day_num - 1;
        if ($day_num == 0) $day_num = 7; 
    }
    
    if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
    if ($day_num == 5) return 'JUMAT';
    if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
    
    return 'SENIN-KAMIS';
}

function doSafeReset($conn, $shift_label, $tanggal_history) {
    echo "Running reset for $shift_label (Tanggal: $tanggal_history)...\n";
    
    $max_q = $conn->query("SELECT MAX(id) as m FROM log_quality")->fetch_assoc()['m'] ?? 0;
    $max_ng = $conn->query("SELECT MAX(id) as m FROM log_ng")->fetch_assoc()['m'] ?? 0;
    $max_dt = $conn->query("SELECT MAX(id) as m FROM log_downtime")->fetch_assoc()['m'] ?? 0;

    $conn->begin_transaction();
    try {
        if ($max_q > 0) {
            if (!$conn->query("INSERT IGNORE INTO history_quality (timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount) SELECT timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount FROM log_quality WHERE id <= $max_q")) throw new Exception("Gagal insert history_quality");
        }
        if ($max_ng > 0) {
            if (!$conn->query("INSERT IGNORE INTO history_ng (timestamp, mcID, kode_proses, kode_ng, qty_ng) SELECT timestamp, mcID, kode_proses, kode_ng, qty_ng FROM log_ng WHERE id <= $max_ng")) throw new Exception("Gagal insert history_ng");
        }
        if ($max_dt > 0) {
            if (!$conn->query("INSERT IGNORE INTO history_downtime (mcID, kode_dt, durasi_detik, timestamp) SELECT mcID, kode_dt, durasi_detik, timestamp FROM log_downtime WHERE id <= $max_dt")) throw new Exception("Gagal insert history_downtime");
        }

        $res_mesin_reset = $conn->query("SELECT mcID, MIN(timestamp) as min_ts, MAX(timestamp) as max_ts FROM log_quality WHERE id <= $max_q GROUP BY mcID");
        if ($res_mesin_reset && $res_mesin_reset->num_rows > 0) {
            while($rm = $res_mesin_reset->fetch_assoc()) {
                $mcID_r = $rm['mcID'];
                $waktu_mulai = $rm['min_ts'];
                $waktu_selesai = $rm['max_ts'];
                $info_m = $conn->query("SELECT m.nama_mesin, c.part_name, c.ct_pcs, c.line FROM log_quality lq LEFT JOIN master_mesin m ON (lq.mcID = m.id_mesin OR lq.mcID = m.mcID) LEFT JOIN master_ct c ON lq.kode_proses = c.kode WHERE lq.mcID = '$mcID_r' AND lq.id <= $max_q ORDER BY lq.id DESC LIMIT 1")->fetch_assoc();
                
                $nama_m = $info_m['nama_mesin'] ?? $mcID_r; $part_m = $info_m['part_name'] ?? 'Tidak Diketahui';
                $line_m = $info_m['line'] ?? '';
                $template_aktif = 'DEFAULT';
                if (!empty($line_m)) {
                    $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line_m' LIMIT 1");
                    if ($res_tpl && $res_tpl->num_rows > 0) $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
                }
                $ideal_ct = floatval($info_m['ct_pcs'] ?? 0);
                
                $offset_produksi = $conn->query("SELECT offset_produksi FROM master_mesin WHERE id_mesin = '$mcID_r' OR mcID = '$mcID_r'")->fetch_assoc()['offset_produksi'] ?? 0;
                
                $sql_prod_logs = "SELECT SUM(delta_prodCount) as total_prod FROM log_quality WHERE mcID = '$mcID_r' AND id <= $max_q";
                $res_prod_logs = $conn->query($sql_prod_logs);
                
                $prod_r = $offset_produksi;
                if ($res_prod_logs && $res_prod_logs->num_rows > 0) {
                    $prod_r += (int)$res_prod_logs->fetch_assoc()['total_prod'];
                }
                
                $ng_r = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM log_ng WHERE mcID = '$mcID_r' AND id <= $max_ng")->fetch_assoc()['qty'] ?? 0;
                
                $hari_history = getLogicalDay(strtotime($tanggal_history));
                $tpl_esc = $conn->real_escape_string($template_aktif);
                $sql_slots = "SELECT menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift_label' AND (hari = '$hari_history' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
                $res_slots = $conn->query($sql_slots);
                $ppt_seconds = 0;
                if ($res_slots && $res_slots->num_rows > 0) {
                    while($r_slot = $res_slots->fetch_assoc()) {
                        $ppt_seconds += ((int)$r_slot['menit_efektif'] * 60);
                    }
                }
                
                $total_dt_r = $conn->query("SELECT COALESCE(SUM(durasi_detik), 0) as total_dt FROM log_downtime WHERE mcID = '$mcID_r' AND id <= $max_dt")->fetch_assoc()['total_dt'] ?? 0;
                $operating_time_seconds = $ppt_seconds - $total_dt_r;
                if ($operating_time_seconds < 0) $operating_time_seconds = 0;
                
                $a_r = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
                $p_r = ($operating_time_seconds > 0) ? (($ideal_ct * $prod_r) / $operating_time_seconds) * 100 : 0;
                $q_r = ($prod_r > 0) ? (($prod_r - $ng_r) / $prod_r) * 100 : 0;
                
                if ($q_r > 100) $q_r = 100;
                if ($q_r < 0) $q_r = 0;
                
                $oee_r = ($a_r * $p_r * $q_r) / 10000;
                
                $conn->query("INSERT IGNORE INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                              VALUES ('$tanggal_history', '$shift_label', '$mcID_r', '$nama_m', '$part_m', '$prod_r', '$ng_r', '$oee_r', '$a_r', '$p_r', '$q_r', '$waktu_mulai', '$waktu_selesai', NOW())");
            }
        }

        if ($max_q > 0) {
            if (!$conn->query("DELETE FROM log_quality WHERE id <= $max_q")) throw new Exception("Gagal delete log_quality");
        }
        if ($max_ng > 0) {
            if (!$conn->query("DELETE FROM log_ng WHERE id <= $max_ng")) throw new Exception("Gagal delete log_ng");
        }
        if ($max_dt > 0) {
            if (!$conn->query("DELETE FROM log_downtime WHERE id <= $max_dt")) throw new Exception("Gagal delete log_downtime");
        }
        if ($shift_label == 'SHIFT 2' || $shift_label == 'REKAP HARIAN') {
            $conn->query("TRUNCATE TABLE mesin_override");
        }

        $conn->commit();
        echo "Reset $shift_label successful.\n";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// FORCE FLAG CHECK
$force_shift = $argv[1] ?? null;

if ($force_shift == "SHIFT 1") {
    doSafeReset($conn, 'SHIFT 1', $today_date);
} else if ($force_shift == "SHIFT 2") {
    $tanggal_history = ($jam_reset_s2 < '12:00:00' && $now_time < '12:00:00') ? date('Y-m-d', strtotime('-1 day')) : $today_date;
    doSafeReset($conn, 'SHIFT 2', $tanggal_history);
} else {
    // Normal Cron Execution
    // Check Reset Shift 1
    if ($now_time >= $jam_reset_s1 && $now_time < '23:59:59') {
        $cek_history_s1 = $conn->query("SELECT id FROM history_summary WHERE DATE(waktu_reset) = '$today_date' AND shift = 'SHIFT 1' LIMIT 1");
        if ($cek_history_s1 && $cek_history_s1->num_rows == 0) {
            doSafeReset($conn, 'SHIFT 1', $today_date);
        }
    }

    // Check Reset Shift 2 / Rekap Harian
    if ($now_time >= $jam_reset_s2) {
        $cek_history_s2 = $conn->query("SELECT id FROM history_summary WHERE DATE(waktu_reset) = '$today_date' AND (shift = 'SHIFT 2' OR shift = 'REKAP HARIAN') LIMIT 1");
        if ($cek_history_s2 && $cek_history_s2->num_rows == 0) {
            $tanggal_history = ($jam_reset_s2 < '12:00:00') ? date('Y-m-d', strtotime('-1 day')) : $today_date;
            doSafeReset($conn, 'SHIFT 2', $tanggal_history);
        }
    }
}

echo "Done.\n";
?>
