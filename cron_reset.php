<?php
// cron_reset.php
// SMART AUTO-LEMBUR & PER-MACHINE RESET LOGIC
set_time_limit(0);
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);
$conn->query("SET time_zone = '+07:00'");

echo "Starting Smart Auto-Reset Process...\n";

// Load global reset settings
$sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
$row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
$jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:00:00';
$now_time = date('H:i:s');
$today_date = date('Y-m-d');
$current_timestamp = time();

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

function doMachineReset($conn, $mcID, $max_q, $max_ng, $max_dt, $shift_label, $tanggal_history, $batas_waktu_cap_ts, $ppt_seconds, $waktu_mulai) {
    echo "  -> Executing Reset for $mcID (Shift: $shift_label, Cap: " . date('Y-m-d H:i:s', $batas_waktu_cap_ts) . ")...\n";
    $batas_waktu_str = date('Y-m-d H:i:s', $batas_waktu_cap_ts);
    
    $conn->begin_transaction();
    try {
        if ($max_q > 0) {
            $conn->query("INSERT IGNORE INTO history_quality (timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount) SELECT timestamp, mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount FROM log_quality WHERE mcID='$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str'");
        }
        if ($max_ng > 0) {
            $conn->query("INSERT IGNORE INTO history_ng (timestamp, mcID, kode_proses, kode_ng, qty_ng) SELECT timestamp, mcID, kode_proses, kode_ng, qty_ng FROM log_ng WHERE mcID='$mcID' AND id <= $max_ng AND timestamp <= '$batas_waktu_str'");
        }
        if ($max_dt > 0) {
            $conn->query("INSERT IGNORE INTO history_downtime (mcID, kode_dt, durasi_detik, timestamp) SELECT mcID, kode_dt, durasi_detik, timestamp FROM log_downtime WHERE mcID='$mcID' AND id <= $max_dt AND timestamp <= '$batas_waktu_str'");
        }

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

        // Hitung Summary OEE
        $info_m = $conn->query("SELECT m.nama_mesin, c.part_name, c.ct_pcs FROM log_quality lq LEFT JOIN master_mesin m ON (lq.mcID = m.id_mesin OR lq.mcID = m.mcID) LEFT JOIN master_ct c ON lq.kode_proses = c.kode WHERE lq.mcID = '$mcID' AND lq.id <= $max_q AND lq.timestamp <= '$batas_waktu_str' ORDER BY lq.id DESC LIMIT 1")->fetch_assoc();
        
        $nama_m = $info_m['nama_mesin'] ?? $mcID; 
        $part_m = $info_m['part_name'] ?? 'Tidak Diketahui';
        $ideal_ct = floatval($info_m['ct_pcs'] ?? 0);
        
        $offset_produksi = $conn->query("SELECT offset_produksi FROM master_mesin WHERE id_mesin = '$mcID' OR mcID = '$mcID'")->fetch_assoc()['offset_produksi'] ?? 0;
        
        $sql_prod_logs = "SELECT SUM(delta_prodCount) as total_prod, MAX(timestamp) as max_ts FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND timestamp <= '$batas_waktu_str'";
        $res_prod_logs = $conn->query($sql_prod_logs)->fetch_assoc();
        $prod_r = $offset_produksi + (int)($res_prod_logs['total_prod'] ?? 0);
        $waktu_selesai = $res_prod_logs['max_ts'] ?? $batas_waktu_str;
        
        $ng_r = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM log_ng WHERE mcID = '$mcID' AND id <= $max_ng AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['qty'] ?? 0;
        
        $total_dt_r = $conn->query("SELECT COALESCE(SUM(durasi_detik), 0) as total_dt FROM history_downtime WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['total_dt'] ?? 0;
        
        $operating_time_seconds = $ppt_seconds - $total_dt_r;
        if ($operating_time_seconds < 0) $operating_time_seconds = 0;
        
        $a_r = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
        $p_r = ($operating_time_seconds > 0) ? (($ideal_ct * $prod_r) / $operating_time_seconds) * 100 : 0;
        $q_r = ($prod_r > 0) ? (($prod_r - $ng_r) / $prod_r) * 100 : 0;
        
        if ($a_r > 100) $a_r = 100;
        if ($p_r > 100) $p_r = 100;
        if ($q_r > 100) $q_r = 100;
        if ($q_r < 0) $q_r = 0;
        $oee_r = ($a_r * $p_r * $q_r) / 10000;
        
        $conn->query("INSERT IGNORE INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                      VALUES ('$tanggal_history', '$shift_label', '$mcID', '$nama_m', '$part_m', '$prod_r', '$ng_r', '$oee_r', '$a_r', '$p_r', '$q_r', '$waktu_mulai', '$waktu_selesai', NOW())");

        // Hapus HANYA data mesin ini dari tabel berjalan
        if ($max_q > 0) $conn->query("DELETE FROM log_quality WHERE mcID='$mcID' AND id <= $max_q");
        if ($max_ng > 0) $conn->query("DELETE FROM log_ng WHERE mcID='$mcID' AND id <= $max_ng");
        if ($max_dt > 0) $conn->query("DELETE FROM log_downtime WHERE mcID='$mcID' AND id <= $max_dt");
        
        // Hapus Override
        $conn->query("DELETE FROM mesin_override WHERE mcID='$mcID'");

        // Reset offset master mesin
        $last_row = $conn->query("SELECT prodCount, raw_prodCount FROM log_quality WHERE mcID = '$mcID' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $last_prod = $last_row['prodCount'] ?? 0;
        $last_raw = $last_row['raw_prodCount'] ?? 0;
        $conn->query("UPDATE master_mesin SET catStatus = 'OFF SHIFT', offset_produksi = '$last_prod', offset_raw_produksi = '$last_raw' WHERE mcID = '$mcID' OR id_mesin = '$mcID'");

        $conn->commit();
        echo "  -> Reset $mcID OK.\n";
    } catch (Exception $e) {
        $conn->rollback();
        echo "  -> Error on $mcID: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------
// MAIN PER-MACHINE CHECK
// ---------------------------------------------------------
// 1. Get Maximum IDs safely
$max_q = $conn->query("SELECT MAX(id) as m FROM log_quality")->fetch_assoc()['m'] ?? 0;
$max_ng = $conn->query("SELECT MAX(id) as m FROM log_ng")->fetch_assoc()['m'] ?? 0;
$max_dt = $conn->query("SELECT MAX(id) as m FROM log_downtime")->fetch_assoc()['m'] ?? 0;

$machines = $conn->query("SELECT mcID, MIN(timestamp) as min_ts FROM log_quality WHERE id <= $max_q GROUP BY mcID");

if ($machines && $machines->num_rows > 0) {
    while ($m = $machines->fetch_assoc()) {
        $mcID = $m['mcID'];
        $waktu_mulai = $m['min_ts'];
        $jam_mulai = date('H:i:s', strtotime($waktu_mulai));
        $tanggal_mulai = date('Y-m-d', strtotime($waktu_mulai));
        
        // Penentuan Shift berdasarkan kapan mesin pertama kali produksi
        $shift_label = 'SHIFT 1';
        $tanggal_history = $tanggal_mulai;
        if ($jam_reset_s1 <= $jam_reset_s2) { // e.g. S1 07:00, S2 19:00
            if ($jam_mulai >= $jam_reset_s1 || $jam_mulai < $jam_reset_s2) {
                $shift_label = 'SHIFT 2';
            }
        } else { // Shift 2 crosses midnight (e.g. S1 16:00, S2 06:00)
            if ($jam_mulai >= $jam_reset_s1 || $jam_mulai < $jam_reset_s2) {
                $shift_label = 'SHIFT 2';
                if ($jam_mulai < $jam_reset_s2) { // Masuk pagi buta, itu masih tanggal historis kemarin
                    $tanggal_history = date('Y-m-d', strtotime('-1 day', strtotime($tanggal_mulai)));
                }
            }
        }

        echo "\nProcessing Mesin: $mcID (Mulai: $waktu_mulai, Shift: $shift_label, Tanggal: $tanggal_history)\n";

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
        
        // Fallback jika tidak ada template detail
        if (empty($scheduled_end_jam)) {
            $scheduled_end_jam = ($shift_label == 'SHIFT 1') ? $jam_reset_s1 : $jam_reset_s2;
        }

        $scheduled_end_ts = strtotime($tanggal_mulai . ' ' . $scheduled_end_jam);
        
        // Koreksi menyeberang tengah malam untuk jadwal pulang
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
            // Tambahkan waktu lembur ke target
            $ppt_seconds += ((int)$ov['total_menit'] * 60);
            echo "  [INFO] Override Lembur Ditemukan: " . $ov['jam_selesai_lembur'] . "\n";
        }

        // Cek Aktivitas Terakhir (Untuk Lembur Siluman / Auto-Detect)
        $res_active = $conn->query("SELECT MAX(timestamp) as last_active FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND (mcStatus = 'Mesin Running' OR delta_prodCount > 0)");
        $last_active = $res_active->fetch_assoc()['last_active'];
        $last_active_ts = $last_active ? strtotime($last_active) : strtotime($waktu_mulai);
        
        // Kalkulasi Waktu End Dinamis (Yang Terbesar Diantara Jadwal / Override / Aktivitas)
        $dynamic_end_ts = max($scheduled_end_ts, $override_end_ts, $last_active_ts);

        // Jika tidak ada override tapi operator lanjut kerja melebih batas (Lembur Siluman)
        if ($override_end_ts == 0 && $last_active_ts > $scheduled_end_ts) {
            $extra_seconds = $last_active_ts - $scheduled_end_ts;
            $ppt_seconds += $extra_seconds;
            echo "  [INFO] Lembur Siluman Otomatis Terdeteksi: +" . round($extra_seconds/60) . " Menit\n";
        }

        // ----------------------------------------------------
        // TRIGGER LOGIC (SAPU RANJAU / AUTO RESET)
        // ----------------------------------------------------
        $is_past_schedule = ($current_timestamp >= $scheduled_end_ts);
        $idle_time = $current_timestamp - $last_active_ts;
        $is_idle = ($idle_time >= (15 * 60)); // 15 menit tidak ada produksi baru reset
        
        $force_reset = false;
        // Sapu Ranjau: Jika selisih dari $dynamic_end_ts sudah sangat jauh (e.g > 2 jam), 
        // artinya server mungkin mati dan baru nyala, jadi paksa reset.
        if ($current_timestamp > ($dynamic_end_ts + 7200)) {
            $force_reset = true;
        }

        echo "  -> Jadwal Pulang  : " . date('Y-m-d H:i:s', $scheduled_end_ts) . "\n";
        echo "  -> End Dinamis    : " . date('Y-m-d H:i:s', $dynamic_end_ts) . "\n";
        echo "  -> Aktif Terakhir : " . date('Y-m-d H:i:s', $last_active_ts) . " (Idle: ".round($idle_time/60)."m)\n";

        if ($force_reset || ($is_past_schedule && $is_idle)) {
            echo "  [ACTION] TRIGGER RESET MEMENUHI SYARAT!\n";
            doMachineReset($conn, $mcID, $max_q, $max_ng, $max_dt, $shift_label, $tanggal_history, $dynamic_end_ts, $ppt_seconds, $waktu_mulai);
        } else {
            echo "  [ACTION] Mesin Masih Aktif/Belum Waktunya. Tidak Direset.\n";
        }
    }
} else {
    echo "Tidak ada data produksi yang berjalan.\n";
}

echo "Done.\n";
?>
