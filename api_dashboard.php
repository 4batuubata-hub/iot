<?php
session_start();
set_time_limit(0);
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die(json_encode(["error" => "Koneksi Gagal: " . $conn->connect_error]));
$conn->query("SET time_zone = '+07:00'");

// ==========================================
// MESIN AUTO-RESET (SAFE TRANSACTION LOGIC)
// ==========================================
// Dipindahkan ke cron_reset.php untuk mengurangi beban dashboard API.
// ==========================================

require_once __DIR__ . '/helper_jadwal.php';


$today_db = date('Y-m-d');
$sample_shift = getActiveShift($conn, 'LINE 1');
$shift_date = date('Y-m-d', strtotime($sample_shift['mulai']));

$sql_over_all = "SELECT mcID, jenis, jam_mulai, jam_selesai FROM mesin_override WHERE tanggal = '$today_db' OR tanggal = '$shift_date'";
$res_over_all = $conn->query($sql_over_all);
$overrides = [];
if($res_over_all && $res_over_all->num_rows > 0) {
    while($ro = $res_over_all->fetch_assoc()){ $overrides[$ro['mcID']] = $ro; }
}

$lineFilter = $_GET['line'] ?? 'ALL'; $sortOee = $_GET['sort_oee'] ?? 'NONE'; 
$statusFilter = isset($_GET['status']) && !empty($_GET['status']) ? explode(',', $_GET['status']) : ['RUNNING', 'STANDBY', 'ALARM', 'OFF'];

$sql = "SELECT mm.id_mesin, mm.mcID as numeric_mcID, mm.nama_mesin, mm.offset_produksi, lq.mcStatus, lq.mcInfo, TIMESTAMPDIFF(SECOND, lq.timestamp, NOW()) as last_update_sec, lq.timestamp as last_ts, mc.part_name, mc.ct_pcs, mc.line
        FROM master_mesin mm
        LEFT JOIN (SELECT l1.* FROM log_quality l1 INNER JOIN (SELECT mcID, MAX(id) as max_id FROM log_quality GROUP BY mcID) l2 ON l1.mcID = l2.mcID AND l1.id = l2.max_id) lq ON (mm.id_mesin = lq.mcID OR mm.mcID = lq.mcID)
        LEFT JOIN (SELECT mcID, kode_proses FROM log_quality WHERE id IN (SELECT MAX(id) FROM log_quality WHERE kode_proses IS NOT NULL AND kode_proses != '' GROUP BY mcID)) lq_kp ON (mm.id_mesin = lq_kp.mcID OR mm.mcID = lq_kp.mcID)
        LEFT JOIN master_ct mc ON lq_kp.kode_proses = mc.kode ORDER BY mm.id_mesin ASC";
$result = $conn->query($sql);

$all_mesin_data = []; $dynamic_lines = []; $shift_cache = []; 

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $line = $row['line'] ?: 'ALL';
        if (!empty($row['line']) && !in_array($row['line'], $dynamic_lines)) { $dynamic_lines[] = $row['line']; }

        if (!isset($shift_cache[$line])) { $shift_cache[$line] = getActiveShift($conn, $line); }
        $shift_info = $shift_cache[$line]; 
        
        $waktu_mulai = $shift_info['mulai'];
        $waktu_selesai = $shift_info['selesai'];
        $mcID = $row['id_mesin'];
        $numeric_mcID = $row['numeric_mcID'] ?? '';
        $all_ids = array_unique(array_filter([$mcID, $numeric_mcID]));
        $in_ids = "'" . implode("','", array_map([$conn, 'real_escape_string'], $all_ids)) . "'";
        $mc_where = "mcID IN ($in_ids)";
        $lq_where = "lq.mcID IN ($in_ids)";
        
        $is_lembur = false;
        if(isset($overrides[$mcID]) || (!empty($numeric_mcID) && isset($overrides[$numeric_mcID]))) {
            $ov = $overrides[$mcID] ?? $overrides[$numeric_mcID];
            if($ov['jenis'] == 'LEMBUR_AWAL' || $ov['jenis'] == 'LEMBUR_AKHIR' || $ov['jenis'] == 'LEMBUR') {
                $is_lembur = true;
                $j_mulai = $ov['jam_mulai'];
                $j_selesai = $ov['jam_selesai'];
                
                $base_date = date('Y-m-d', strtotime($waktu_mulai));
                if ($shift_info['shift'] == 'SHIFT 2') {
                    $s_date = ($j_mulai < '12:00:00') ? date('Y-m-d', strtotime('+1 day', strtotime($base_date))) : $base_date;
                    $e_date = ($j_selesai < '12:00:00' || $j_mulai > $j_selesai) ? date('Y-m-d', strtotime('+1 day', strtotime($base_date))) : $base_date;
                    $lembur_start_dt = "$s_date $j_mulai";
                    $lembur_end_dt = "$e_date $j_selesai";
                } else {
                    $lembur_start_dt = date('Y-m-d H:i:s', strtotime($base_date . ' ' . $j_mulai));
                    $lembur_end_dt = date('Y-m-d H:i:s', strtotime($base_date . ' ' . $j_selesai));
                    if ($j_mulai > $j_selesai) { 
                        $lembur_end_dt = date('Y-m-d H:i:s', strtotime('+1 day', strtotime($base_date . ' ' . $j_selesai)));
                    }
                }

                if ($lembur_start_dt < $waktu_mulai) $waktu_mulai = $lembur_start_dt;
                if ($lembur_end_dt > $waktu_selesai) $waktu_selesai = $lembur_end_dt;
            }
        }

        $offset_produksi = (int)($row['offset_produksi'] ?? 0);
        
        $ideal_ct = (float)($row['ct_pcs'] ?? 0);
        if ($ideal_ct <= 0 && !empty($row['part_name'])) {
            $pname_esc = $conn->real_escape_string($row['part_name']);
            $res_ct_fb = $conn->query("SELECT ct_pcs FROM master_ct WHERE part_name = '$pname_esc' LIMIT 1");
            if ($res_ct_fb && $res_ct_fb->num_rows > 0) {
                $ideal_ct = (float)$res_ct_fb->fetch_assoc()['ct_pcs'];
            }
        }
        
        $prodCount = $offset_produksi;
        
        // Single Source of Truth (SSOT) Akumulasi Produksi Shift:
        // Menghitung delta secara berurutan, tahan reset hardware ESP32 / ganti model (delta < 0),
        // dan dilengkapi proteksi lonjakan reconnect dump. 100% sinkron dengan user/detail.php.
        $shift_prod = calculateShiftProductionCount($conn, $mc_where, $waktu_mulai, $waktu_selesai, $ideal_ct);
        
        // Fallback ke SUM(delta_prodCount) trigger DB jika data logs belum terisi
        if ($shift_prod <= 0) {
            $sql_delta = "SELECT COALESCE(SUM(lq.delta_prodCount), 0) as s_delta FROM log_quality lq WHERE $lq_where AND lq.timestamp >= '$waktu_mulai' AND lq.timestamp <= '$waktu_selesai'";
            $res_delta = $conn->query($sql_delta);
            $shift_prod = ($res_delta && $res_delta->num_rows > 0) ? (int)$res_delta->fetch_assoc()['s_delta'] : 0;
        }
        
        $prodCount += $shift_prod;

        $total_ideal_sec = $prodCount * $ideal_ct;

        $sql_ng = "SELECT COALESCE(SUM(qty_ng), 0) as shift_ng FROM log_ng WHERE $mc_where AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
        $NGCount = ($conn->query($sql_ng)->fetch_assoc()['shift_ng']) ?? 0;

        $mcStatus = strtolower($row['mcStatus'] ?? 'off'); $infoAsli = trim($row['mcInfo'] ?? 'Off');
        $isTimeout = ($row['last_update_sec'] === null || $row['last_update_sec'] > 180);

        $kuning = ['Toilet', 'Minum', 'Sholat', 'Operator Izin']; 
        $oren = ['Dandory', 'Refill Material', '5P/5S', 'PSM', 'Teaching', 'OJT', 'Tambahan Proses', 'Wire Las Macet', 'Nozzle / Contac Tip']; 
        $merah = ['Problem Mesin', 'Problem Qualitas', 'Problem Insp Jig', 'Problem Jig Proses', 'Perawatan Maintanance', 'QC Trial', 'ENG Trial', 'Material Habis', 'No Planning', 'TPM', 'Sarana', 'Tidak Ada Planning', 'Tunggu Material', 'Persiapan Sarana Proses'];

        $current_ts = time();
        $template_aktif = $shift_info['template'] ?? 'JADWAL KERJA A';
        $shift_target = $shift_info['shift'] ?? 'SHIFT 1';
        $hari_aktif = $shift_info['hari'] ?? 'SENIN-KAMIS';
        
        // Single Source of Truth ISO 22400-2 Planned Production Time (PPT)
        $ppt_seconds = calculateShiftPPTSeconds($conn, $template_aktif, $shift_target, $hari_aktif, $waktu_mulai, $current_ts);

        if ($is_lembur) {
            $l_s = strtotime($lembur_start_dt);
            $l_e = strtotime($lembur_end_dt);
            if ($current_ts > $l_s) {
                $elapsed_lembur = min($current_ts, $l_e) - $l_s;
                $ppt_seconds += $elapsed_lembur;
            }
        }

        if ($isTimeout) { 
            $catStatus = 'OFF'; $statusText = "OFF / DISCONNECTED"; $statusClass = "status-off"; 
        } 
        elseif ($mcStatus == 'run' || $mcStatus == 'running' || strcasecmp($infoAsli, 'Running') == 0) { $catStatus = 'RUNNING'; $statusText = "RUNNING"; $statusClass = "status-running"; } 
        elseif (in_array(strtoupper($infoAsli), array_map('strtoupper', $kuning)) || $mcStatus == 'standby') { $catStatus = 'STANDBY'; $statusText = strtoupper($infoAsli); $statusClass = "status-kuning"; } 
        elseif ($mcStatus == 'alarm' || in_array(strtoupper($infoAsli), array_map('strtoupper', $oren)) || in_array(strtoupper($infoAsli), array_map('strtoupper', $merah))) { 
            $catStatus = 'ALARM'; $statusText = strtoupper($infoAsli); 
            $statusClass = in_array(strtoupper($infoAsli), array_map('strtoupper', $oren)) ? "status-oren" : "status-merah"; 
        } 
        else { $catStatus = 'OFF'; $statusText = "OFF"; $statusClass = "status-off"; }

        // 1. Ambil Data Downtime Historis - STANDAR ISO 22400-2 (Clipped to Shift)
        $sql_dt_details = "SELECT ld.kode_dt, md.label_dt, ld.durasi_detik, ld.timestamp FROM log_downtime ld LEFT JOIN master_downtime md ON ld.kode_dt = md.kode_dt WHERE ld.mcID IN ($in_ids) AND ld.timestamp >= '$waktu_mulai' AND ld.timestamp <= '$waktu_selesai'";
        $res_dt_details = $conn->query($sql_dt_details);
        $historical_real_dt = 0;
        if ($res_dt_details && $res_dt_details->num_rows > 0) {
            $shift_start_ts = strtotime($shift_info['work_start'] ?? $waktu_mulai);
            $shift_end_ts = strtotime($shift_info['work_end'] ?? $waktu_selesai);
            while($dt_row = $res_dt_details->fetch_assoc()) {
                $end_ts = strtotime($dt_row['timestamp']);
                $start_ts = $end_ts - (int)$dt_row['durasi_detik'];
                $clipped_dur = clipIntervalToShift($start_ts, $end_ts, $shift_start_ts, $shift_end_ts);
                if ($clipped_dur > 0) {
                    $historical_real_dt += $clipped_dur;
                }
            }
        }
        
        // 2. Ambil Data Downtime Real-Time (Ongoing) - STANDAR ISO 22400-2
        $ongoing_real_dt = 0;
        
        if ($catStatus != 'RUNNING' && $catStatus != 'OFF SHIFT') {
            if (strcasecmp($infoAsli, 'Mesin Running') != 0 && strcasecmp($infoAsli, 'Running') != 0) {
                $infoEsc = $conn->real_escape_string($infoAsli);
                $end_time_expr = ($isTimeout && isset($row['last_ts'])) ? "'{$row['last_ts']}'" : "NOW()";
                
                // Cari waktu mulai downtime saat ini
                $sql_start = "SELECT timestamp FROM log_quality WHERE (mcID = '$mcID' OR mcID = '$numeric_mcID') AND mcInfo != '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp DESC LIMIT 1";
                $res_start = $conn->query($sql_start);
                $downtime_start_ts = null;
                
                if ($res_start && $res_start->num_rows > 0) {
                    $downtime_start_ts = $res_start->fetch_assoc()['timestamp'];
                } else {
                    $sql_first = "SELECT timestamp FROM log_quality WHERE (mcID = '$mcID' OR mcID = '$numeric_mcID') AND mcInfo = '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp ASC LIMIT 1";
                    $res_first = $conn->query($sql_first);
                    $downtime_start_ts = ($res_first && $res_first->num_rows > 0) ? $res_first->fetch_assoc()['timestamp'] : $waktu_mulai;
                }
                
                if ($downtime_start_ts) {
                    $shift_start_ts = strtotime($shift_info['work_start'] ?? $waktu_mulai);
                    $shift_end_ts = strtotime($shift_info['work_end'] ?? $waktu_selesai);
                    $end_ts_ongoing = min(time(), $shift_end_ts);
                    $ongoing_clipped = clipIntervalToShift(strtotime($downtime_start_ts), $end_ts_ongoing, $shift_start_ts, $shift_end_ts);
                    if ($ongoing_clipped > 0) {
                        $ongoing_real_dt = $ongoing_clipped;
                    }
                }
            }
        }
        
        // 2.5 PRE-PRODUCTION IMPLICIT LOSSTIME
        $pre_prod_loss_sec = 0;
        $work_start_ts = strtotime($shift_info['work_start'] ?? $waktu_mulai);
        $sql_first_data = "SELECT MIN(timestamp) as first_ts FROM log_quality WHERE $mc_where AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
        $res_first_data = $conn->query($sql_first_data);
        if ($res_first_data && $res_first_data->num_rows > 0) {
            $fd = $res_first_data->fetch_assoc();
            if (!empty($fd['first_ts'])) {
                $first_data_ts = strtotime($fd['first_ts']);
                if ($first_data_ts > $work_start_ts) {
                    $pre_prod_loss_sec = $first_data_ts - $work_start_ts;
                    if ($pre_prod_loss_sec > 7200) $pre_prod_loss_sec = 0; // CAP 2 jam
                }
            }
        }

        // 3. Kalkulasi Standar ISO 22400-2 (SSOT calculateStandardOEE)
        $total_loss_detik = $historical_real_dt + $ongoing_real_dt + $pre_prod_loss_sec;
        $stdOEE = calculateStandardOEE($ppt_seconds, $total_loss_detik, $prodCount, $NGCount, $ideal_ct);
        $availability = $stdOEE['availability'];
        $performance = $stdOEE['performance'];
        $quality = $stdOEE['quality'];
        $oee = $stdOEE['oee'];

        $row['catStatus'] = $catStatus; $row['statusText'] = $statusText; $row['statusClass'] = $statusClass;
        $row['calc_q'] = $quality; $row['calc_a'] = $availability; $row['calc_p'] = $performance; $row['calc_oee'] = $oee;
        
        $active_shift_text = $shift_info['shift'];
        if ($is_lembur) {
            $l_s = strtotime($lembur_start_dt);
            $l_e = strtotime($lembur_end_dt);
            if ($current_ts >= $l_s && $current_ts <= $l_e) {
                $active_shift_text = 'LEMBUR';
            }
        }
        $row['active_shift'] = $active_shift_text;
        
        $row['ppt_seconds'] = $ppt_seconds;
        
        $all_mesin_data[] = $row;
    }
}
sort($dynamic_lines);

$mesin_data = [];
foreach ($all_mesin_data as $row) {
    $check_status = $row['catStatus'];
    if ($check_status === 'OFF SHIFT' || $check_status === 'DOWNTIME') {
        $check_status = 'OFF';
    }
    
    if (($lineFilter === 'ALL' || $row['line'] === $lineFilter) && in_array($check_status, $statusFilter)) {
        $mesin_data[] = $row;
    }
}
if ($sortOee === 'DESC') { usort($mesin_data, function($a, $b) { return $b['calc_oee'] <=> $a['calc_oee']; }); } 
elseif ($sortOee === 'ASC') { usort($mesin_data, function($a, $b) { return $a['calc_oee'] <=> $b['calc_oee']; }); }

header('Content-Type: application/json');
echo json_encode([
    'lines' => $dynamic_lines,
    'mesin' => $mesin_data,
    'time' => date('H:i:s'),
    'totalPages' => ceil(count($mesin_data) / 10)
]);
