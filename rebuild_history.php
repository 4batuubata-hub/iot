<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

echo "Memulai proses rebuild history_summary...\n";

// Get boundaries
$sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
$row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
$jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '18:00:00';
$jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:30:00';

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

// 1. Truncate
$conn->query("TRUNCATE TABLE history_summary");
echo "Tabel history_summary berhasil di-truncate.\n";

// 2. Find date range
$res_range = $conn->query("SELECT MIN(DATE(timestamp)) as min_date, MAX(DATE(timestamp)) as max_date FROM history_quality");
$range = $res_range->fetch_assoc();
$start_date = $range['min_date'];
$end_date = $range['max_date'];

if (!$start_date) {
    echo "Tidak ada data di history_quality.\n";
    exit;
}

echo "Membangun ulang dari tanggal $start_date hingga $end_date...\n";

$current_date = $start_date;
while (strtotime($current_date) <= strtotime($end_date)) {
    
    // Shift 1 boundaries
    $s1_start = "$current_date $jam_reset_s2";
    $s1_end = "$current_date $jam_reset_s1";
    
    // Shift 2 boundaries
    $s2_start = "$current_date $jam_reset_s1";
    $next_day = date('Y-m-d', strtotime($current_date . ' +1 day'));
    $s2_end = "$next_day $jam_reset_s2";
    
    $shifts = [
        ['label' => 'SHIFT 1', 'start' => $s1_start, 'end' => $s1_end, 'tanggal' => $current_date],
        ['label' => 'SHIFT 2', 'start' => $s2_start, 'end' => $s2_end, 'tanggal' => $current_date]
    ];
    
    foreach ($shifts as $shift) {
        $s_label = $shift['label'];
        $s_start = $shift['start'];
        $s_end = $shift['end'];
        $s_tanggal = $shift['tanggal'];
        
        // Find distinct machines active in this shift
        $res_machines = $conn->query("SELECT DISTINCT mcID FROM history_quality WHERE timestamp > '$s_start' AND timestamp <= '$s_end'");
        if (!$res_machines || $res_machines->num_rows == 0) continue;
        
        while ($rm = $res_machines->fetch_assoc()) {
            $mcID = $rm['mcID'];
            
            // Reconstruct total_ok
            $total_ok = 0;
            // Get initial count before this shift to calculate first delta
            $res_prev = $conn->query("SELECT prodCount FROM history_quality WHERE mcID = '$mcID' AND timestamp <= '$s_start' ORDER BY timestamp DESC LIMIT 1");
            $prev_count = ($res_prev && $res_prev->num_rows > 0) ? (int)$res_prev->fetch_assoc()['prodCount'] : 0;
            
            $res_logs = $conn->query("SELECT prodCount FROM history_quality WHERE mcID = '$mcID' AND timestamp > '$s_start' AND timestamp <= '$s_end' ORDER BY timestamp ASC");
            while ($row_log = $res_logs->fetch_assoc()) {
                $current_count = (int)$row_log['prodCount'];
                if ($current_count >= $prev_count) {
                    $total_ok += ($current_count - $prev_count);
                } else {
                    $total_ok += $current_count; // Counter reset
                }
                $prev_count = $current_count;
            }
            
            // Add offset_produksi
            $offset_produksi = $conn->query("SELECT offset_produksi FROM master_mesin WHERE id_mesin = '$mcID' OR mcID = '$mcID'")->fetch_assoc()['offset_produksi'] ?? 0;
            $total_ok += (int)$offset_produksi;
            
            // Get waktu_mulai & waktu_selesai
            $res_waktu = $conn->query("SELECT MIN(timestamp) as w_mulai, MAX(timestamp) as w_selesai FROM history_quality WHERE mcID = '$mcID' AND timestamp > '$s_start' AND timestamp <= '$s_end'");
            $rw = $res_waktu->fetch_assoc();
            $waktu_mulai = $rw['w_mulai'];
            $waktu_selesai = $rw['w_selesai'];
            
            // Info Mesin
            $info_m = $conn->query("SELECT m.nama_mesin, c.part_name, c.ct_pcs, c.line FROM history_quality hq LEFT JOIN master_mesin m ON (hq.mcID = m.id_mesin OR hq.mcID = m.mcID) LEFT JOIN master_ct c ON hq.kode_proses = c.kode WHERE hq.mcID = '$mcID' AND hq.timestamp > '$s_start' AND hq.timestamp <= '$s_end' ORDER BY hq.id DESC LIMIT 1")->fetch_assoc();
            $nama_m = $info_m['nama_mesin'] ?? $mcID;
            $part_m = $info_m['part_name'] ?? 'Tidak Diketahui';
            $ideal_ct = floatval($info_m['ct_pcs'] ?? 0);
            $line_m = $info_m['line'] ?? '';
            
            // Calculate PPT
            $template_aktif = 'DEFAULT';
            if (!empty($line_m)) {
                $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line_m' LIMIT 1");
                if ($res_tpl && $res_tpl->num_rows > 0) $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
            }
            $hari_history = getLogicalDay(strtotime($waktu_mulai));
            $tpl_esc = $conn->real_escape_string($template_aktif);
            $sql_slots = "SELECT menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$s_label' AND (hari = '$hari_history' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
            $res_slots = $conn->query($sql_slots);
            $ppt_seconds = 0;
            if ($res_slots && $res_slots->num_rows > 0) {
                while($r_slot = $res_slots->fetch_assoc()) $ppt_seconds += ((int)$r_slot['menit_efektif'] * 60);
            }
            
            // Get NG & Downtime
            $ng_r = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM history_ng WHERE mcID = '$mcID' AND timestamp > '$s_start' AND timestamp <= '$s_end'")->fetch_assoc()['qty'] ?? 0;
            
            // --- SMART BREAK PROPORTIONAL (HISTORIKAL) ---
            $forgiven_labels = ['Stand By', 'Mesin Off', 'Toilet', 'Minum', 'Sholat'];
            $sql_dt_details = "SELECT ld.kode_dt, md.label_dt, ld.durasi_detik FROM history_downtime ld LEFT JOIN master_downtime md ON ld.kode_dt = md.kode_dt WHERE ld.mcID = '$mcID' AND ld.timestamp > '$s_start' AND ld.timestamp <= '$s_end'";
            $res_dt_details = $conn->query($sql_dt_details);
            $historical_real_dt = 0;
            $historical_whitelist_dt = 0;
            if ($res_dt_details && $res_dt_details->num_rows > 0) {
                while($dt_row = $res_dt_details->fetch_assoc()) {
                    $lbl = (strtoupper($dt_row['kode_dt']) == 'SB' || strtoupper($dt_row['kode_dt']) == 'STAND BY') ? 'Stand By' : (strtoupper($dt_row['kode_dt']) == 'MESIN OFF' ? 'Mesin Off' : ($dt_row['label_dt'] ? $dt_row['label_dt'] : $dt_row['kode_dt']));
                    if (in_array($lbl, $forgiven_labels)) {
                        $historical_whitelist_dt += $dt_row['durasi_detik'];
                    } else {
                        $historical_real_dt += $dt_row['durasi_detik'];
                    }
                }
            }
            $ideal_time_sec = $total_ok * $ideal_ct;
            $allowed_whitelist_sec = max(0, $ppt_seconds - $ideal_time_sec - $historical_real_dt);
            $final_whitelist_dt = min($historical_whitelist_dt, $allowed_whitelist_sec);
            $total_dt_r = $historical_real_dt + $final_whitelist_dt;
            // ---------------------------------------------
            
            // Calculate OEE
            $operating_time_seconds = $ppt_seconds - $total_dt_r;
            if ($operating_time_seconds < 0) $operating_time_seconds = 0;
            
            $a_r = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
            $p_r = ($operating_time_seconds > 0) ? (($ideal_ct * $total_ok) / $operating_time_seconds) * 100 : 0;
            $q_r = (($total_ok + $ng_r) > 0) ? ($total_ok / ($total_ok + $ng_r)) * 100 : 0;
            
            if ($q_r > 100) $q_r = 100;
            if ($a_r > 100) $a_r = 100;
            if ($q_r < 0) $q_r = 0;
            
            $oee_r = ($a_r * $p_r * $q_r) / 10000;
            
            // Insert
            $conn->query("INSERT INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                          VALUES ('$s_tanggal', '$s_label', '$mcID', '$nama_m', '$part_m', '$total_ok', '$ng_r', '$oee_r', '$a_r', '$p_r', '$q_r', '$waktu_mulai', '$waktu_selesai', '$s_end')");
        }
    }
    
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

echo "Rebuild selesai.\n";
?>
