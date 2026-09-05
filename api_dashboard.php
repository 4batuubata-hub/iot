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

function getLogicalDay() {
    $now = date('H:i:s');
    $day_num = date('N'); // 1 (Monday) to 7 (Sunday)
    
    // Shift 2 usually runs past midnight into the next day. 
    // If it's before 07:00 AM, it logically belongs to the previous day's shift schedule.
    if ($now < '07:00:00') {
        $day_num = $day_num - 1;
        if ($day_num == 0) $day_num = 7; // Sunday wrap
    }
    
    if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
    if ($day_num == 5) return 'JUMAT';
    if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
    
    return 'SENIN-KAMIS';
}

function getActiveShift($conn, $line) {
    $sql_tpl = "SELECT nama_template FROM master_line WHERE nama_line = '$line' LIMIT 1";
    $res_tpl = $conn->query($sql_tpl);
    $row_tpl = ($res_tpl && $res_tpl->num_rows > 0) ? $res_tpl->fetch_assoc() : [];
    
    $template = $row_tpl['nama_template'] ?? 'DEFAULT';

    $now = date('H:i:s'); $today = date('Y-m-d');
    $hari = getLogicalDay();
    
    $templates_to_check = array_unique([$template, 'DEFAULT']);
    
    foreach ($templates_to_check as $tpl) {
        if (empty($tpl)) continue;
        $tpl_esc = $conn->real_escape_string($tpl);
        $sql = "SELECT shift, rentang_jam, menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND (hari = '$hari' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
        $res = $conn->query($sql);
        
        if (!$res || $res->num_rows == 0) continue;

        $shift_data = [];
        while($r = $res->fetch_assoc()) {
            $p = explode('-', $r['rentang_jam']);
            if(count($p) == 2) {
                $start = trim($p[0]).":00"; $end = trim($p[1]).":00"; $s_name = $r['shift'];
                if(!isset($shift_data[$s_name])) { 
                    $shift_data[$s_name] = ['start' => $start, 'end' => $end, 'slots' => []]; 
                } else { 
                    $shift_data[$s_name]['end'] = $end; 
                }
                $shift_data[$s_name]['slots'][] = ['start' => $start, 'end' => $end, 'menit_efektif' => (int)$r['menit_efektif']];
            }
        }
        
        $best_past_shift = null;
        $min_diff = PHP_INT_MAX;
        
        foreach($shift_data as $s_name => $times) {
            $s = $times['start']; $e = $times['end']; $slots = $times['slots'];
            
            if ($s <= $e) { 
                if ($now >= $s && $now <= $e) return ['shift' => $s_name, 'mulai' => "$today $s", 'selesai' => "$today $e", 'template' => $tpl, 'hari' => $hari, 'slots' => $slots];
                $ts_e = strtotime("$today $e");
                $ts_e_y = strtotime("-1 day", $ts_e);
                
                $m_today = "$today $s"; $s_today = "$today $e";
                $m_yest = date('Y-m-d', strtotime('-1 day'))." $s"; $s_yest = date('Y-m-d', strtotime('-1 day'))." $e";
            } else { 
                if ($now >= $s) return ['shift' => $s_name, 'mulai' => "$today $s", 'selesai' => date('Y-m-d', strtotime('+1 day'))." $e", 'template' => $tpl, 'hari' => $hari, 'slots' => $slots];
                elseif ($now <= $e) return ['shift' => $s_name, 'mulai' => date('Y-m-d', strtotime('-1 day'))." $s", 'selesai' => "$today $e", 'template' => $tpl, 'hari' => $hari, 'slots' => $slots];
                
                $ts_e = strtotime("+1 day", strtotime("$today $e"));
                $ts_e_y = strtotime("$today $e");
                
                $m_today = "$today $s"; $s_today = date('Y-m-d', strtotime('+1 day'))." $e";
                $m_yest = date('Y-m-d', strtotime('-1 day'))." $s"; $s_yest = "$today $e";
            }
            
            $current_time = time();
            if ($current_time >= $ts_e) {
                if ($current_time - $ts_e < $min_diff) {
                    $min_diff = $current_time - $ts_e;
                    $best_past_shift = ['shift' => 'OFF SHIFT', 'mulai' => $m_today, 'selesai' => $s_today, 'template' => $tpl, 'hari' => $hari, 'slots' => $slots];
                }
            }
            
            if ($current_time >= $ts_e_y) {
                if ($current_time - $ts_e_y < $min_diff) {
                    $min_diff = $current_time - $ts_e_y;
                    $best_past_shift = ['shift' => 'OFF SHIFT', 'mulai' => $m_yest, 'selesai' => $s_yest, 'template' => $tpl, 'hari' => $hari, 'slots' => $slots];
                }
            }
        }
        
        if ($best_past_shift) return $best_past_shift;
    }
    return ['shift' => 'OFF SHIFT', 'mulai' => "$today 00:00:00", 'selesai' => "$today 23:59:59", 'template' => $template, 'hari' => getLogicalDay()];
}

$today_db = date('Y-m-d');
$sql_over_all = "SELECT mcID, jenis, jam_mulai, jam_selesai FROM mesin_override WHERE tanggal = '$today_db'";
$res_over_all = $conn->query($sql_over_all);
$overrides = [];
if($res_over_all && $res_over_all->num_rows > 0) {
    while($ro = $res_over_all->fetch_assoc()){ $overrides[$ro['mcID']] = $ro; }
}

$lineFilter = $_GET['line'] ?? 'ALL'; $sortOee = $_GET['sort_oee'] ?? 'NONE'; 
$statusFilter = isset($_GET['status']) && !empty($_GET['status']) ? explode(',', $_GET['status']) : ['RUNNING', 'STANDBY', 'ALARM', 'OFF'];

$sql = "SELECT mm.id_mesin, mm.nama_mesin, mm.offset_produksi, lq.mcStatus, lq.mcInfo, TIMESTAMPDIFF(SECOND, lq.timestamp, NOW()) as last_update_sec, lq.timestamp as last_ts, mc.part_name, mc.ct_pcs, mc.line
        FROM master_mesin mm
        LEFT JOIN (SELECT l1.* FROM log_quality l1 INNER JOIN (SELECT mcID, MAX(id) as max_id FROM log_quality GROUP BY mcID) l2 ON l1.mcID = l2.mcID AND l1.id = l2.max_id) lq ON mm.id_mesin = lq.mcID
        LEFT JOIN (SELECT mcID, kode_proses FROM log_quality WHERE id IN (SELECT MAX(id) FROM log_quality WHERE kode_proses IS NOT NULL AND kode_proses != '' GROUP BY mcID)) lq_kp ON mm.id_mesin = lq_kp.mcID
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
        
        $is_lembur = false;
        if(isset($overrides[$mcID])) {
            if($overrides[$mcID]['jenis'] == 'LEMBUR_AWAL' || $overrides[$mcID]['jenis'] == 'LEMBUR_AKHIR' || $overrides[$mcID]['jenis'] == 'LEMBUR') {
                $is_lembur = true;
                $j_mulai = $overrides[$mcID]['jam_mulai'];
                $j_selesai = $overrides[$mcID]['jam_selesai'];
                
                $lembur_start_dt = date('Y-m-d H:i:s', strtotime($today_db . ' ' . $j_mulai));
                $lembur_end_dt = date('Y-m-d H:i:s', strtotime($today_db . ' ' . $j_selesai));
                
                if ($j_mulai > $j_selesai) { 
                    $lembur_end_dt = date('Y-m-d H:i:s', strtotime('+1 day', strtotime($today_db . ' ' . $j_selesai)));
                }

                if ($lembur_start_dt < $waktu_mulai) $waktu_mulai = $lembur_start_dt;
                if ($lembur_end_dt > $waktu_selesai) $waktu_selesai = $lembur_end_dt;
            }
        }

        $offset_produksi = (int)($row['offset_produksi'] ?? 0);
        
        $sql_prod_logs = "SELECT SUM(delta_prodCount) as total_prod FROM log_quality WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
        $res_prod_logs = $conn->query($sql_prod_logs);
        
        $prodCount = $offset_produksi;
        if ($res_prod_logs && $res_prod_logs->num_rows > 0) {
            $prodCount += (int)$res_prod_logs->fetch_assoc()['total_prod'];
        }

        $sql_ng = "SELECT COALESCE(SUM(qty_ng), 0) as shift_ng FROM log_ng WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
        $NGCount = ($conn->query($sql_ng)->fetch_assoc()['shift_ng']) ?? 0;

        $mcStatus = strtolower($row['mcStatus'] ?? 'off'); $infoAsli = trim($row['mcInfo'] ?? 'Off');
        $isTimeout = ($row['last_update_sec'] === null || $row['last_update_sec'] > 180);

        $kuning = ['Toilet', 'Minum', 'Sholat', 'Operator Izin']; 
        $oren = ['Dandory', 'Refill Material', '5P/5S', 'PSM', 'Teaching', 'OJT', 'Tambahan Proses', 'Wire Las Macet', 'Nozzle / Contac Tip']; 
        $merah = ['Problem Mesin', 'Problem Qualitas', 'Problem Insp Jig', 'Problem Jig Proses', 'Perawatan Maintanance', 'QC Trial', 'ENG Trial', 'Material Habis', 'No Planning', 'TPM', 'Sarana', 'Tidak Ada Planning', 'Tunggu Material', 'Persiapan Sarana Proses'];

        $is_in_shift = false;
        $ppt_seconds = 0;
        $current_ts = time();
        $start_shift_date = date('Y-m-d', strtotime($waktu_mulai));
        $shift_mulai_ts = strtotime($waktu_mulai);
        
        if (isset($shift_info['slots'])) {
            foreach ($shift_info['slots'] as $slot) {
                $s_ts = strtotime($start_shift_date . ' ' . $slot['start']);
                $e_ts = strtotime($start_shift_date . ' ' . $slot['end']);
                if ($e_ts < $s_ts) { $e_ts += 86400; }
                if ($s_ts < $shift_mulai_ts) {
                    $s_ts += 86400; $e_ts += 86400;
                }
                if ($current_ts >= $s_ts && $current_ts <= $e_ts) {
                    $is_in_shift = true;
                }
                $slot_duration_sec = $e_ts - $s_ts;
                $effective_sec = (isset($slot['menit_efektif']) ? $slot['menit_efektif'] : 0) * 60;
                
                if ($current_ts > $s_ts) {
                    $elapsed_in_slot = min($current_ts, $e_ts) - $s_ts;
                    if ($slot_duration_sec > 0) {
                        $ppt_seconds += ($elapsed_in_slot / $slot_duration_sec) * $effective_sec;
                    }
                }
            }
        }

        if ($is_lembur) {
            $l_s = strtotime($lembur_start_dt);
            $l_e = strtotime($lembur_end_dt);
            if ($current_ts >= $l_s && $current_ts <= $l_e) {
                $is_in_shift = true;
            }
            // Add lembur duration to ppt_seconds
            if ($current_ts > $l_s) {
                $elapsed_lembur = min($current_ts, $l_e) - $l_s;
                $ppt_seconds += $elapsed_lembur; // Asumsi 100% efektif
            }
        }

        if (!$is_in_shift) { 
            $catStatus = 'OFF SHIFT'; $statusText = "OFF SHIFT"; $statusClass = "status-off"; 
        } else {
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
        }

        // 1. Ambil Data Downtime Historis (Gabungkan Semua)
        $sql_all_dt = "SELECT COALESCE(SUM(durasi_detik), 0) as total_dt FROM log_downtime WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
        $total_loss_detik = ($conn->query($sql_all_dt)->fetch_assoc()['total_dt']) ?? 0;

        // 2. Ambil Data Downtime Real-Time (Ongoing)
        if ($catStatus != 'RUNNING' && $catStatus != 'OFF SHIFT') {
            if (strcasecmp($infoAsli, 'Mesin Running') != 0 && strcasecmp($infoAsli, 'Running') != 0) {
                $infoEsc = $conn->real_escape_string($infoAsli);
                $end_time_expr = ($isTimeout && isset($row['last_ts'])) ? "'{$row['last_ts']}'" : "NOW()";
                
                // Cari waktu mulai downtime saat ini dengan lebih akurat
                // Langkah 1: Cari log terakhir dimana mcInfo BERBEDA
                $sql_start = "SELECT timestamp FROM log_quality WHERE mcID = '$mcID' AND mcInfo != '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp DESC LIMIT 1";
                $res_start = $conn->query($sql_start);
                $downtime_start_ts = null;
                
                if ($res_start && $res_start->num_rows > 0) {
                    $downtime_start_ts = $res_start->fetch_assoc()['timestamp'];
                } else {
                    // Langkah 2: Cari log pertama dengan status yang sama
                    $sql_first = "SELECT timestamp FROM log_quality WHERE mcID = '$mcID' AND mcInfo = '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp ASC LIMIT 1";
                    $res_first = $conn->query($sql_first);
                    if ($res_first && $res_first->num_rows > 0) {
                        $downtime_start_ts = $res_first->fetch_assoc()['timestamp'];
                    } else {
                        // Langkah 3: Gunakan waktu mulai shift
                        $downtime_start_ts = $waktu_mulai;
                    }
                }
                
                if ($downtime_start_ts) {
                    $sql_ongoing = "SELECT TIMESTAMPDIFF(SECOND, '$downtime_start_ts', $end_time_expr) as active_sec";
                    $res_ongoing = $conn->query($sql_ongoing);
                    if ($res_ongoing && $res_ongoing->num_rows > 0) {
                        $ongoing_sec = max(0, (int)$res_ongoing->fetch_assoc()['active_sec']);
                        $total_loss_detik += $ongoing_sec;
                    }
                }
            }
        }

        // 3. Perbaiki Rumus Operating Time (Semua losstime mengurangi availability)
        $operating_time_seconds = $ppt_seconds - $total_loss_detik;
        if ($operating_time_seconds < 0) $operating_time_seconds = 0;
        
        $ideal_ct = floatval($row['ct_pcs'] ?? 0);
        
        $availability = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
        $performance = ($operating_time_seconds > 0) ? (($ideal_ct * $prodCount) / $operating_time_seconds) * 100 : 0;
        $quality = ($prodCount > 0) ? (($prodCount - $NGCount) / $prodCount) * 100 : 0;
        
        // if ($availability > 100) $availability = 100; // Removed cap
        // if ($performance > 100) $performance = 100; // Removed cap
        if ($quality > 100) $quality = 100;
        if ($quality < 0) $quality = 0;

        $oee = ($availability * $performance * $quality) / 10000;

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
