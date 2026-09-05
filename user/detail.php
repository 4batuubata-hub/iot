<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

// FIX FATAL: Sinkronisasi Waktu Database ke WIB (Jakarta)
$conn->query("SET time_zone = '+07:00'");

$mcID = isset($_GET['mcID']) ? $conn->real_escape_string($_GET['mcID']) : '';
if(empty($mcID)) die("<h2 style='color:white; text-align:center;'>Pilih mesin dari dashboard terlebih dahulu!</h2>");

// AJAX ENDPOINT UNTUK AUTO UPDATE OPERATOR PROFILE & SKILL MATRIX
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? '';
    if ($action === 'get_operator_info') {
        $sql_info = "SELECT lq.op_NIK, mo.nama as nama_operator, mo.nik, mo.foto, mc.line, mm.mcID as string_mcID
                     FROM master_mesin mm 
                     LEFT JOIN (SELECT l1.* FROM log_quality l1 INNER JOIN (SELECT mcID, MAX(id) as max_id FROM log_quality GROUP BY mcID) l2 ON l1.mcID = l2.mcID AND l1.id = l2.max_id) lq ON mm.id_mesin = lq.mcID 
                     LEFT JOIN master_ct mc ON lq.kode_proses = mc.kode 
                     LEFT JOIN master_operator mo ON lq.op_NIK = mo.nik 
                     WHERE mm.id_mesin = '$mcID' OR mm.mcID = '$mcID' LIMIT 1";
        $res_info = $conn->query($sql_info);
        $info = ($res_info && $res_info->num_rows > 0) ? $res_info->fetch_assoc() : [];

        $operatorName = $info['nama_operator'] ?? ($info['op_NIK'] ?? 'Belum Login');
        $nikOP = $info['nik'] ?? ($info['op_NIK'] ?? 'default');
        $target_mcID = $info['string_mcID'] ?? $mcID;

        $opSkillLevel = 1;
        $opFoto = '';
        if (!empty($nikOP) && $nikOP !== 'default') {
            $sql_sm = "SELECT skill_level FROM skill_matrix WHERE mcID = '$target_mcID' AND nik_operator = '$nikOP' LIMIT 1";
            $res_sm = $conn->query($sql_sm);
            if (!$res_sm || $res_sm->num_rows == 0) {
                $sql_sm = "SELECT skill_level FROM skill_matrix WHERE nik_operator = '$nikOP' ORDER BY skill_level DESC LIMIT 1";
                $res_sm = $conn->query($sql_sm);
            }
            if ($res_sm && $res_sm->num_rows > 0) {
                $opSkillLevel = (int)$res_sm->fetch_assoc()['skill_level'];
            }
            $sql_op_foto = "SELECT foto FROM master_operator WHERE nik = '$nikOP' LIMIT 1";
            $res_op_foto = $conn->query($sql_op_foto);
            if ($res_op_foto && $res_op_foto->num_rows > 0) {
                $opFoto = $res_op_foto->fetch_assoc()['foto'];
            }
        }

        $skillMap = [
            1 => ['label' => 'Level 1 (Beginner)', 'color' => '#64748b'],
            2 => ['label' => 'Level 2 (Basic)', 'color' => '#0284c7'],
            3 => ['label' => 'Level 3 (Competent)', 'color' => '#10b981'],
            4 => ['label' => 'Level 4 (Expert)', 'color' => '#a855f7']
        ];
        $opSkillInfo = $skillMap[$opSkillLevel] ?? $skillMap[1];

        $line = $info['line'] ?? 'ALL';
        $sql_tpl = "SELECT nama_template, nama_template_shift1, nama_template_shift2 FROM master_line WHERE nama_line = '$line' LIMIT 1";
        $res_tpl = $conn->query($sql_tpl);
        $row_tpl = ($res_tpl && $res_tpl->num_rows > 0) ? $res_tpl->fetch_assoc() : [];
        $tpl_s1 = $row_tpl['nama_template_shift1'] ?? ($row_tpl['nama_template'] ?? 'DEFAULT');
        $tpl_s2 = $row_tpl['nama_template_shift2'] ?? ($row_tpl['nama_template'] ?? 'DEFAULT');
        $now = date('H:i:s'); $today = date('Y-m-d');
        $waktu_mulai = "$today 00:00:00"; $waktu_selesai = "$today 23:59:59";
        
        $templates_to_check = array_unique([$tpl_s1, $tpl_s2, 'DEFAULT']);
        foreach ($templates_to_check as $template) {
            if (empty($template)) continue;
            $tpl_esc = $conn->real_escape_string($template);
            $res_jam = $conn->query("SELECT shift, rentang_jam FROM master_jam_statis WHERE nama_template = '$tpl_esc' ORDER BY urutan ASC");
            if (!$res_jam || $res_jam->num_rows == 0) continue;
            $shift_data = [];
            while($r = $res_jam->fetch_assoc()) {
                $p = explode('-', $r['rentang_jam']);
                if(count($p) == 2) {
                    $start = trim($p[0]).":00"; $end = trim($p[1]).":00"; $s_name = $r['shift'];
                    if(!isset($shift_data[$s_name])) { $shift_data[$s_name] = ['start' => $start, 'end' => $end]; } 
                    else { $shift_data[$s_name]['end'] = $end; }
                }
            }
            foreach($shift_data as $s_name => $times) {
                $s = $times['start']; $e = $times['end'];
                if ($s <= $e) { 
                    if ($now >= $s && $now <= $e) { $waktu_mulai = "$today $s"; $waktu_selesai = "$today $e"; break 2; }
                } else { 
                    if ($now >= $s) { $waktu_mulai = "$today $s"; $waktu_selesai = date('Y-m-d', strtotime('+1 day'))." $e"; break 2; }
                    elseif ($now <= $e) { $waktu_mulai = date('Y-m-d', strtotime('-1 day'))." $s"; $waktu_selesai = "$today $e"; break 2; }
                }
            }
        }

        $res_op_history = $conn->query("SELECT lq.op_NIK, mo.nama, MIN(TIME(lq.timestamp)) as jam_mulai, MAX(TIME(lq.timestamp)) as jam_selesai, (MAX(lq.prodCount) - MIN(lq.prodCount)) as total_qty FROM log_quality lq LEFT JOIN master_operator mo ON lq.op_NIK = mo.nik WHERE lq.mcID = '$mcID' AND lq.timestamp >= '$waktu_mulai' AND lq.timestamp <= '$waktu_selesai' AND lq.op_NIK != '' GROUP BY lq.op_NIK, mo.nama ORDER BY jam_mulai ASC");
        $opHistoryData = [];
        if ($res_op_history && $res_op_history->num_rows > 0) {
            while($rowOp = $res_op_history->fetch_assoc()) { $opHistoryData[] = $rowOp; }
        }

        echo json_encode([
            'status' => 'success',
            'nama' => $operatorName,
            'nik' => $nikOP,
            'foto' => $info['foto'] ?? '',
            'skill_level' => $opSkillLevel,
            'skill_label' => $opSkillInfo['label'],
            'skill_color' => $opSkillInfo['color'],
            'history' => $opHistoryData
        ]);
        exit;
    }
}

$today_db = date('Y-m-d');

// PROSES TOMBOL QUICK ACTION (LEMBUR AWAL / AKHIR)
if(isset($_POST['set_lembur'])) {
    $j_mulai = $conn->real_escape_string($_POST['jam_mulai']);
    $j_selesai = $conn->real_escape_string($_POST['jam_selesai']);
    $posisi = $conn->real_escape_string($_POST['posisi_lembur']); 
    
    $conn->query("INSERT INTO mesin_override (mcID, tanggal, jenis, jam_mulai, jam_selesai) VALUES ('$mcID', '$today_db', '$posisi', '$j_mulai', '$j_selesai') ON DUPLICATE KEY UPDATE jenis='$posisi', jam_mulai='$j_mulai', jam_selesai='$j_selesai'");
    header("Location: detail.php?mcID=".urlencode($mcID)); exit;
}
if(isset($_POST['reset_override'])) {
    $conn->query("DELETE FROM mesin_override WHERE mcID = '$mcID' AND tanggal = '$today_db'");
    header("Location: detail.php?mcID=".urlencode($mcID)); exit;
}

// CEK STATUS QUICK ACTION AKTIF
$sql_over = "SELECT jenis, jam_mulai, jam_selesai FROM mesin_override WHERE mcID = '$mcID' AND tanggal = '$today_db'";
$res_over = $conn->query($sql_over);
$override = ($res_over && $res_over->num_rows > 0) ? $res_over->fetch_assoc() : null;
$is_lembur = false; $jenis_lembur = "";
if($override) {
    if($override['jenis'] == 'LEMBUR_AWAL' || $override['jenis'] == 'LEMBUR_AKHIR' || $override['jenis'] == 'LEMBUR') {
        $is_lembur = true;
        $jenis_lembur = $override['jenis'];
    }
}

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
        $sql = "SELECT shift, rentang_jam FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND (hari = '$hari' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
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
                $shift_data[$s_name]['slots'][] = ['start' => $start, 'end' => $end];
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
    return ['shift' => 'OFF SHIFT', 'mulai' => "$today 00:00:00", 'selesai' => "$today 23:59:59", 'template' => $template, 'hari' => getLogicalDay(), 'slots' => []];
}

$sql_info = "SELECT lq.*, mm.nama_mesin, mm.offset_produksi, mm.mcID as string_mcID, mc.ct_jam, mc.part_name, mc.part_number, mc.proses_name, mc.proses_description, mc.ct_pcs, mc.line, mo.nama as nama_operator, mo.nik, TIMESTAMPDIFF(SECOND, lq.timestamp, NOW()) as last_update_sec, lq.timestamp as last_ts
             FROM master_mesin mm LEFT JOIN (SELECT l1.* FROM log_quality l1 INNER JOIN (SELECT mcID, MAX(id) as max_id FROM log_quality GROUP BY mcID) l2 ON l1.mcID = l2.mcID AND l1.id = l2.max_id) lq ON mm.id_mesin = lq.mcID
             LEFT JOIN (SELECT mcID, kode_proses FROM log_quality WHERE id IN (SELECT MAX(id) FROM log_quality WHERE kode_proses IS NOT NULL AND kode_proses != '' GROUP BY mcID)) lq_kp ON mm.id_mesin = lq_kp.mcID
             LEFT JOIN (SELECT mcID, op_NIK FROM log_quality WHERE id IN (SELECT MAX(id) FROM log_quality WHERE op_NIK IS NOT NULL AND op_NIK != '' GROUP BY mcID)) lq_op ON mm.id_mesin = lq_op.mcID
             LEFT JOIN master_ct mc ON lq_kp.kode_proses = mc.kode LEFT JOIN master_operator mo ON lq_op.op_NIK = mo.nik 
             WHERE mm.id_mesin = '$mcID' OR mm.mcID = '$mcID' LIMIT 1";
$res_info = $conn->query($sql_info);
$info = ($res_info && $res_info->num_rows > 0) ? $res_info->fetch_assoc() : [];

$line = $info['line'] ?? 'ALL';
$shift_info = getActiveShift($conn, $line);

$waktu_mulai = $shift_info['mulai'];
$waktu_selesai = $shift_info['selesai'];
$shift_aktif = $shift_info['shift'];
$hari_aktif = $shift_info['hari'];

$lembur_start_dt = ""; $lembur_end_dt = "";
if($is_lembur) {
    $j_mulai = $override['jam_mulai'];
    $j_selesai = $override['jam_selesai'];
    
    $lembur_start_dt = date('Y-m-d H:i:s', strtotime($today_db . ' ' . $j_mulai));
    $lembur_end_dt = date('Y-m-d H:i:s', strtotime($today_db . ' ' . $j_selesai));
    
    if ($j_mulai > $j_selesai) { // Lintas Malam
        $lembur_end_dt = date('Y-m-d H:i:s', strtotime('+1 day', strtotime($today_db . ' ' . $j_selesai)));
    }
    
    if ($lembur_start_dt < $waktu_mulai) $waktu_mulai = $lembur_start_dt;
    if ($lembur_end_dt > $waktu_selesai) $waktu_selesai = $lembur_end_dt;

    if($shift_aktif == 'OFF SHIFT') { $shift_aktif = 'LEMBUR AKTIF'; }
}

$template_aktif = $shift_info['template'];
$namaMesin = $info['nama_mesin'] ?? $mcID; $partName = $info['part_name'] ?? 'Belum Ada Part'; $partNumber = $info['part_number'] ?? '-';
$prosesName = $info['proses_name'] ?? 'PROSES 1'; $prosesDesc = $info['proses_description'] ?? '-'; $ctPcs = $info['ct_pcs'] ?? 0; $targetPerJam = isset($info['ct_jam']) && $info['ct_jam'] > 0 ? round($info['ct_jam']) : 0;
$operatorName = $info['nama_operator'] ?? ($info['op_NIK'] ?? 'Belum Login'); $nikOP = $info['nik'] ?? ($info['op_NIK'] ?? 'default');

// AMBIL SKILL MATRIX OPERATOR DARI DATABASE
$opSkillLevel = 1;
$target_mcID = $info['string_mcID'] ?? $mcID;
if (!empty($nikOP) && $nikOP !== 'default') {
    $sql_sm = "SELECT skill_level FROM skill_matrix WHERE mcID = '$target_mcID' AND nik_operator = '$nikOP' LIMIT 1";
    $res_sm = $conn->query($sql_sm);
    if (!$res_sm || $res_sm->num_rows == 0) {
        $sql_sm = "SELECT skill_level FROM skill_matrix WHERE nik_operator = '$nikOP' ORDER BY skill_level DESC LIMIT 1";
        $res_sm = $conn->query($sql_sm);
    }
    if ($res_sm && $res_sm->num_rows > 0) {
        $opSkillLevel = (int)$res_sm->fetch_assoc()['skill_level'];
    }
}
$skillMap = [
    1 => ['label' => 'Level 1 (Beginner)', 'color' => '#64748b'],
    2 => ['label' => 'Level 2 (Basic)', 'color' => '#0284c7'],
    3 => ['label' => 'Level 3 (Competent)', 'color' => '#10b981'],
    4 => ['label' => 'Level 4 (Expert)', 'color' => '#a855f7']
];
$opSkillInfo = $skillMap[$opSkillLevel] ?? $skillMap[1];
$opSkillLabel = $opSkillInfo['label'];
$opSkillColor = $opSkillInfo['color'];

$mcStatus = strtolower($info['mcStatus'] ?? 'off'); $infoAsli = trim($info['mcInfo'] ?? 'Off');
$isTimeout = ($info['last_update_sec'] === null || $info['last_update_sec'] > 180);

        $kuning = ['Toilet', 'Minum', 'Sholat', 'Operator Izin']; 
        $oren = ['Dandory', 'Refill Material', '5P/5S', 'PSM', 'Teaching', 'OJT', 'Tambahan Proses', 'Wire Las Macet', 'Nozzle / Contac Tip']; 
        $merah = ['Problem Mesin', 'Problem Qualitas', 'Problem Insp Jig', 'Problem Jig Proses', 'Perawatan Maintanance', 'QC Trial', 'ENG Trial', 'Material Habis', 'No Planning', 'TPM', 'Sarana', 'Tidak Ada Planning', 'Tunggu Material', 'Persiapan Sarana Proses'];

        if ($isTimeout) { 
            $statusTeks = "OFF / DISCONNECTED"; $statusWarna = "#757575"; 
        } elseif ($mcStatus == 'run' || $mcStatus == 'running' || strcasecmp($infoAsli, 'Running') == 0) { 
            $statusTeks = "RUNNING"; $statusWarna = "#00e676"; 
        } elseif (in_array(strtoupper($infoAsli), array_map('strtoupper', $kuning)) || $mcStatus == 'standby') { 
            $statusTeks = strtoupper($infoAsli); $statusWarna = "#ffea00"; 
        } elseif ($mcStatus == 'alarm' || in_array(strtoupper($infoAsli), array_map('strtoupper', $oren)) || in_array(strtoupper($infoAsli), array_map('strtoupper', $merah))) { 
            $statusTeks = strtoupper($infoAsli); 
            $statusWarna = in_array(strtoupper($infoAsli), array_map('strtoupper', $oren)) ? "#ff9100" : "#ff1744"; 
        } else { 
            $statusTeks = "OFF"; $statusWarna = "#757575"; 
        }

$offset_produksi = (int)($info['offset_produksi'] ?? 0);
$sql_prod = "SELECT COALESCE(MAX(prodCount), 0) as max_prod FROM log_quality WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
$max_prod = ($conn->query($sql_prod)->fetch_assoc()['max_prod']) ?? 0;

$totalProd = $max_prod - $offset_produksi;
if ($totalProd < 0) {
    // Failsafe jika ESP32 restart
    $sql_fallback = "SELECT COALESCE(MAX(prodCount) - MIN(prodCount), 0) as fallback_prod FROM log_quality WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'";
    $totalProd = ($conn->query($sql_fallback)->fetch_assoc()['fallback_prod']) ?? 0;
}

$res_ng = $conn->query("SELECT COALESCE(SUM(CASE WHEN qty_ng > 0 THEN qty_ng ELSE 0 END), 0) as total_ng_plus, COALESCE(SUM(CASE WHEN qty_ng < 0 THEN ABS(qty_ng) ELSE 0 END), 0) as total_repair FROM log_ng WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'");
if ($res_ng && $res_ng->num_rows > 0) {
    $row_ng = $res_ng->fetch_assoc();
    $totalNG = $row_ng['total_ng_plus'];
    $totalRepair = $row_ng['total_repair'];
} else {
    $totalNG = 0; $totalRepair = 0;
}
$totalScrap = max(0, $totalNG - $totalRepair);
$totalOK = max(0, $totalProd - $totalScrap);

$hourlyCalculatedTarget = [];
$hourlyTargetSum = [];
$shift_target = ($shift_aktif === 'OFF SHIFT' || $shift_aktif === 'LEMBUR AKTIF') ? 'SHIFT 1' : $shift_aktif;
$template_esc = $conn->real_escape_string($template_aktif);
$shift_esc = $conn->real_escape_string($shift_target);
$hari_esc = $conn->real_escape_string($hari_aktif);

$sql_jam_statis = "SELECT rentang_jam, menit_efektif FROM master_jam_statis WHERE nama_template = '$template_esc' AND shift = '$shift_esc' AND (hari = '$hari_esc' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
$res_jam_statis = $conn->query($sql_jam_statis);
$jamAktif = []; $hourlyActualSum = []; $hourlyTargetSum = []; $jamEfektif = [];

// Helper function to check if a time falls within a range (handles cross-midnight)
function isTimeInRange($time, $range) {
    $p = explode('-', $range);
    if(count($p) !== 2) return false;
    $start = trim($p[0]) . ":00";
    $end = trim($p[1]) . ":00";
    if ($start <= $end) {
        return ($time >= $start && $time <= $end);
    } else {
        return ($time >= $start || $time <= $end);
    }
}

// --- SMART STANDBY LOGIC ---
function isTargetMetAtTime($time_to_check, $jamAktif, $lembur_start_dt, $lembur_end_dt, $jam_lembur_str, $is_lembur, $hourlyActualSum, $hourlyCalculatedTarget) {
    $matched_jam = null;
    if ($is_lembur && $time_to_check >= $lembur_start_dt && $time_to_check <= $lembur_end_dt) {
        $matched_jam = $jam_lembur_str;
    } else {
        $logTime = date('H:i:s', strtotime($time_to_check));
        foreach($jamAktif as $jam) {
            if ($jam == $jam_lembur_str) continue;
            if (isTimeInRange($logTime, $jam)) {
                $matched_jam = $jam;
                break;
            }
        }
    }
    if ($matched_jam && isset($hourlyActualSum[$matched_jam]) && isset($hourlyCalculatedTarget[$matched_jam])) {
        // Jika target > 0 dan aktual >= target, maka target tercapai
        if ($hourlyCalculatedTarget[$matched_jam] > 0 && $hourlyActualSum[$matched_jam] >= $hourlyCalculatedTarget[$matched_jam]) {
            return true;
        }
    }
    return false;
}


// 1. Hitung Downtime Historis (Sudah Selesai) - SMART STANDBY
$completed_downtime_sec = 0;
$pareto_map = [];

// Ambil semua row downtime
$res_dt_all = $conn->query("SELECT ld.timestamp, ld.kode_dt, ld.durasi_detik, md.label_dt FROM log_downtime ld LEFT JOIN master_downtime md ON ld.kode_dt = md.kode_dt WHERE ld.mcID = '$mcID' AND ld.timestamp >= '$waktu_mulai' AND ld.timestamp <= '$waktu_selesai'");
if ($res_dt_all && $res_dt_all->num_rows > 0) {
    while ($dt_row = $res_dt_all->fetch_assoc()) {
        $dt_time = $dt_row['timestamp'];
        $dt_kode = strtoupper($dt_row['kode_dt']);
        $dt_label_master = $dt_row['label_dt'];
        $dt_dur = (int)$dt_row['durasi_detik'];
        
        $label = ($dt_kode == 'SB' || $dt_kode == 'STAND BY') ? 'Stand By' : ($dt_kode == 'MESIN OFF' ? 'Mesin Off' : ($dt_label_master ? $dt_label_master : $dt_kode));
        
        // Cek apakah target tercapai di jam saat downtime ini terjadi
        $is_target_met = isTargetMetAtTime($dt_time, $jamAktif, $lembur_start_dt, $lembur_end_dt, $jam_lembur_str, $is_lembur, $hourlyActualSum, $hourlyCalculatedTarget);
        
        $forgiven_labels = ['Stand By', 'Mesin Off', 'Toilet', 'Minum', 'Sholat'];
        if ($is_target_met && in_array($label, $forgiven_labels)) {
            // TARGET TERCAPAI: Abaikan downtime personal/istirahat dari Loss Time
            continue; 
        }
        
        // Tambahkan ke total loss time historis
        $completed_downtime_sec += $dt_dur;
        
        // Tambahkan ke Pareto
        if (isset($pareto_map[$label])) {
            $pareto_map[$label] += $dt_dur;
        } else {
            $pareto_map[$label] = $dt_dur;
        }
    }
}

// 2. Hitung Downtime Aktif (Sedang Berjalan) - SMART STANDBY
$ongoing_downtime_sec = 0;
$ongoing_dt_label = null;

if ($statusTeks != 'RUNNING') {
    if (strcasecmp($infoAsli, 'Mesin Running') == 0 || strcasecmp($infoAsli, 'Running') == 0) {
        $ongoing_downtime_sec = 0;
        $ongoing_dt_label = null;
    } else {
        $infoEsc = $conn->real_escape_string($infoAsli);
        $end_time_expr = ($isTimeout && isset($info['last_ts'])) ? "'{$info['last_ts']}'" : "NOW()";
        
        $sql_start = "SELECT timestamp FROM log_quality WHERE mcID = '$mcID' AND mcInfo != '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp DESC LIMIT 1";
        $res_start = $conn->query($sql_start);
        $downtime_start_ts = null;
        
        if ($res_start && $res_start->num_rows > 0) {
            $downtime_start_ts = $res_start->fetch_assoc()['timestamp'];
        } else {
            $sql_first = "SELECT timestamp FROM log_quality WHERE mcID = '$mcID' AND mcInfo = '$infoEsc' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp ASC LIMIT 1";
            $res_first = $conn->query($sql_first);
            if ($res_first && $res_first->num_rows > 0) {
                $downtime_start_ts = $res_first->fetch_assoc()['timestamp'];
            } else {
                $downtime_start_ts = $waktu_mulai;
            }
        }
        
        if ($downtime_start_ts) {
            $sql_ongoing = "SELECT TIMESTAMPDIFF(SECOND, '$downtime_start_ts', $end_time_expr) as active_sec";
            $res_ongoing = $conn->query($sql_ongoing);
            if ($res_ongoing && $res_ongoing->num_rows > 0) {
                $raw_ongoing = max(0, (int)$res_ongoing->fetch_assoc()['active_sec']);
                
                $ongoing_dt_label = $infoAsli;
                $sql_dt_label = "SELECT kode_dt, label_dt FROM master_downtime WHERE kode_dt = '$infoEsc' OR label_dt = '$infoEsc' LIMIT 1";
                $res_dt_label = $conn->query($sql_dt_label);
                if ($res_dt_label && $res_dt_label->num_rows > 0) {
                    $dt_row = $res_dt_label->fetch_assoc();
                    $ongoing_dt_label = $dt_row['label_dt'];
                } else {
                    if (strtoupper($infoAsli) == 'STAND BY' || strtoupper($infoAsli) == 'SB') {
                        $ongoing_dt_label = 'Stand By';
                    }
                }
                
                // Cek apakah target tercapai saat ini
                $current_is_target_met = isTargetMetAtTime(date('Y-m-d H:i:s'), $jamAktif, $lembur_start_dt, $lembur_end_dt, $jam_lembur_str, $is_lembur, $hourlyActualSum, $hourlyCalculatedTarget);
                
                $forgiven_labels = ['Stand By', 'Mesin Off', 'Toilet', 'Minum', 'Sholat'];
                if ($current_is_target_met && in_array($ongoing_dt_label, $forgiven_labels)) {
                    // TARGET TERCAPAI: Abaikan downtime personal/istirahat Berjalan
                    $ongoing_downtime_sec = 0;
                } else {
                    $ongoing_downtime_sec = $raw_ongoing;
                }
            }
        }
    }
}

// 3. Gabungkan untuk UI Total Losstime
$totalLosstimeMenit = round(($completed_downtime_sec + $ongoing_downtime_sec) / 60);
$res_ng_summary = $conn->query("SELECT md.keterangan as nama_defect, mc.part_name as log_part_name, mc.part_number as log_part_number, mc.proses_description as log_proses_desc, COALESCE(SUM(CASE WHEN ln.qty_ng > 0 THEN ln.qty_ng ELSE 0 END), 0) as qty_ng_plus, COALESCE(SUM(CASE WHEN ln.qty_ng < 0 THEN ABS(ln.qty_ng) ELSE 0 END), 0) as qty_repair, COALESCE(SUM(ln.qty_ng), 0) as net_scrap FROM log_ng ln LEFT JOIN master_defect md ON ln.kode_ng = md.kode_defect LEFT JOIN master_ct mc ON ln.kode_proses = mc.kode WHERE ln.mcID = '$mcID' AND ln.timestamp >= '$waktu_mulai' AND ln.timestamp <= '$waktu_selesai' GROUP BY ln.kode_proses, ln.kode_ng, md.keterangan, mc.part_name, mc.part_number, mc.proses_description");
$res_logs_op = $conn->query("SELECT timestamp, op_NIK, prodCount FROM log_quality WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' ORDER BY timestamp ASC, id ASC");
$opSessions = [];
$currentSession = null;
if ($res_logs_op && $res_logs_op->num_rows > 0) {
    while ($row = $res_logs_op->fetch_assoc()) {
        $nik = trim($row['op_NIK']);
        if (empty($nik)) continue;
        $qty = (int)$row['prodCount'];
        
        if ($currentSession === null || $currentSession['nik'] !== $nik) {
            if ($currentSession !== null) $opSessions[] = $currentSession;
            $currentSession = [
                'nik' => $nik,
                'jam_mulai' => date('H:i', strtotime($row['timestamp'])),
                'jam_selesai' => date('H:i', strtotime($row['timestamp'])),
                'prev_qty' => $qty,
                'total_qty' => 0
            ];
        } else {
            $delta = $qty - $currentSession['prev_qty'];
            if ($delta > 0) $currentSession['total_qty'] += $delta;
            else if ($delta < 0) $currentSession['total_qty'] += $qty;
            $currentSession['prev_qty'] = $qty;
            $currentSession['jam_selesai'] = date('H:i', strtotime($row['timestamp']));
        }
    }
    if ($currentSession !== null) $opSessions[] = $currentSession;
}
$opHistoryData = [];
$op_names_cache = [];
$res_op = $conn->query("SELECT nik, nama FROM master_operator");
if ($res_op) { while($o = $res_op->fetch_assoc()) { $op_names_cache[$o['nik']] = $o['nama']; } }
foreach ($opSessions as $ses) {
    $opHistoryData[] = [
        'op_NIK' => $ses['nik'],
        'nama' => $op_names_cache[$ses['nik']] ?? 'Tidak Terdaftar',
        'jam_mulai' => $ses['jam_mulai'],
        'jam_selesai' => $ses['jam_selesai'],
        'total_qty' => $ses['total_qty']
    ];
}


function getDurationHours($rangeStr) {
    $p = explode('-', $rangeStr);
    if(count($p) !== 2) return 1;
    $start = strtotime(trim($p[0]) . ":00");
    $end = strtotime(trim($p[1]) . ":00");
    if ($start === false || $end === false) return 1;
    if ($end < $start) $end += 86400; // Cross midnight
    return ($end - $start) / 3600;
}

function isBucketStarted($rangeStr, $waktu_mulai) {
    $p = explode('-', $rangeStr);
    if(count($p) !== 2) return true;
    
    $start_time_str = trim($p[0]) . ":00";
    $shift_start_time = date('H:i:s', strtotime($waktu_mulai));
    $bucket_date = date('Y-m-d', strtotime($waktu_mulai));
    
    if ($start_time_str < $shift_start_time) {
        $bucket_date = date('Y-m-d', strtotime($waktu_mulai . ' +1 day'));
    }
    
    $bucket_start_dt = strtotime($bucket_date . ' ' . $start_time_str);
    return time() >= $bucket_start_dt;
}

if ($res_jam_statis && $res_jam_statis->num_rows > 0) {
    while($rowJ = $res_jam_statis->fetch_assoc()) { 
        $jamAktif[] = $rowJ['rentang_jam']; 
        $jamEfektif[$rowJ['rentang_jam']] = (int)$rowJ['menit_efektif'];
        $hourlyActualSum[$rowJ['rentang_jam']] = 0; 
        $hourlyTargetSum[$rowJ['rentang_jam']] = 0; 
    }
}

$jam_lembur_str = "";
if ($is_lembur) {
    $jam_lembur_str = $j_mulai . ' - ' . $j_selesai;
    if ($jenis_lembur == 'LEMBUR_AWAL') {
        array_unshift($jamAktif, $jam_lembur_str);
    } else {
        array_push($jamAktif, $jam_lembur_str);
    }
    $hourlyActualSum[$jam_lembur_str] = 0; $hourlyTargetSum[$jam_lembur_str] = 0;
    $jamEfektif[$jam_lembur_str] = getDurationHours($jam_lembur_str) * 60; // fallback untuk lembur
}

// Fetch all logs for the current shift period
$sql_logs = "SELECT lq.timestamp, lq.prodCount, m.part_number, m.part_name, m.proses_name, m.proses_description, m.ct_pcs, m.ct_jam
             FROM log_quality lq
             LEFT JOIN master_ct m ON lq.kode_proses = m.kode
             WHERE lq.mcID = '$mcID' AND lq.timestamp >= '$waktu_mulai' AND lq.timestamp <= '$waktu_selesai'
             ORDER BY lq.timestamp ASC, lq.id ASC";
$res_logs = $conn->query($sql_logs);

$buckets = [];
$tableData = [];

// Initialize structure
foreach($jamAktif as $jam) {
    $hourlyActualSum[$jam] = 0;
    $hourlyTargetSum[$jam] = 0;
}

$true_total_prod = 0;
$prev_prodCount = $offset_produksi;

if ($res_logs && $res_logs->num_rows > 0) {
    while($row = $res_logs->fetch_assoc()) {
        $logTime = date('H:i:s', strtotime($row['timestamp']));
        
        $curr_prodCount = (int)$row['prodCount'];
        $delta = $curr_prodCount - $prev_prodCount;
        $qty_added = 0;
        
        if ($delta > 0) {
            $qty_added = $delta;
        } else if ($delta < 0) {
            // ESP32 Ter-Reset!
            $qty_added = $curr_prodCount; 
        }
        $true_total_prod += $qty_added;
        $prev_prodCount = $curr_prodCount;

        $matched_jam = null;
        if ($is_lembur && $row['timestamp'] >= $lembur_start_dt && $row['timestamp'] <= $lembur_end_dt) {
            $matched_jam = $jam_lembur_str;
        } else {
            foreach($jamAktif as $jam) {
                if ($jam == $jam_lembur_str) continue; // Skip lembur string if not matched above
                if (isTimeInRange($logTime, $jam)) {
                    $matched_jam = $jam;
                    break;
                }
            }
        }
        
        // JIKA DILUAR TEMPLATE DAN BUKAN QUICK ACTION, AUTO-GENERATE JAM LEMBUR
        if (!$matched_jam) {
            $last_end = null;
            if (!empty($jamAktif)) {
                $last_bucket = end($jamAktif);
                if ($last_bucket == $jam_lembur_str && count($jamAktif) > 1) {
                    $last_bucket = $jamAktif[count($jamAktif)-2]; // Ambil sebelum lembur quick action
                }
                $p = explode('-', $last_bucket);
                if (count($p) == 2) $last_end = trim($p[1]);
            }
            
            if ($last_end) {
                // Generate sequential buckets until we find the one containing logTime
                $s_time = strtotime($last_end . ":00");
                $log_ts_check = strtotime($logTime);
                if ($log_ts_check < $s_time) {
                    $log_ts_check += 86400; // cross midnight fix untuk perbandingan
                }
                
                $loops = 0;
                while($loops < 10) {
                    $e_time = strtotime("+1 hour", $s_time);
                    $start = date('H:i', $s_time);
                    $end = date('H:i', $e_time);
                    $new_bucket = "$start - $end";
                    
                    if (!in_array($new_bucket, $jamAktif)) {
                        $jamAktif[] = $new_bucket;
                        $hourlyActualSum[$new_bucket] = 0;
                        $hourlyTargetSum[$new_bucket] = 0;
                        if (isset($jamEfektif)) $jamEfektif[$new_bucket] = 60; // 60 menit efektif
                    }
                    
                    if (isTimeInRange($logTime, $new_bucket)) {
                        $matched_jam = $new_bucket;
                        break;
                    }
                    $s_time = $e_time;
                    $loops++;
                }
            }
            
            if (!$matched_jam) {
                $hour = (int)date('H', strtotime($logTime));
                $start = str_pad($hour, 2, '0', STR_PAD_LEFT).":00";
                $end = str_pad($hour+1, 2, '0', STR_PAD_LEFT).":00";
                if($hour == 23) $end = "23:59";
                $matched_jam = "$start - $end";
                if (!in_array($matched_jam, $jamAktif)) {
                    $jamAktif[] = $matched_jam;
                    $hourlyActualSum[$matched_jam] = 0;
                    $hourlyTargetSum[$matched_jam] = 0;
                    if (isset($jamEfektif)) $jamEfektif[$matched_jam] = 60;
                }
            }
        }
        
        $pn = !empty($row['part_number']) ? $row['part_number'] : $partNumber; 
        $pName = !empty($row['part_name']) ? $row['part_name'] : $partName; 
        $proses = !empty($row['proses_description']) ? $row['proses_description'] : (!empty($row['proses_name']) ? $row['proses_name'] : $prosesName);
        $ct = !empty($row['ct_pcs']) ? $row['ct_pcs'] : $ctPcs; 
        $target = !empty($row['ct_jam']) ? round($row['ct_jam']) : $targetPerJam;
        $key = $pn . '|' . $proses;

        if ($matched_jam) {
            if (!isset($buckets[$matched_jam][$key])) {
                $buckets[$matched_jam][$key] = [
                    'qty' => 0,
                    'part_name' => $pName, 'part_number' => $pn, 'proses' => $proses, 'ct' => $ct, 'ct_jam' => $target
                ];
            }
            $buckets[$matched_jam][$key]['qty'] += $qty_added;
        }
    }
}

// Tumpuk totalProd dengan perhitungan delta sesungguhnya (agar tahan reset ESP32)
$totalProd = $true_total_prod;
$totalOK = max(0, $totalProd - $totalScrap);

// 2. COMPILE BUCKETS KE DALAM TABLE DATA
foreach ($jamAktif as $jamStr) {
    if (isset($buckets[$jamStr])) {
        foreach ($buckets[$jamStr] as $key => &$data) {
            $pn = $data['part_number'];
            $proses = $data['proses'];
            if (!isset($tableData[$pn])) $tableData[$pn] = ['name' => $data['part_name'], 'proses' => []];
            if (!isset($tableData[$pn]['proses'][$proses])) $tableData[$pn]['proses'][$proses] = ['ct' => $data['ct'], 'data_jam' => []];
        }
    }
}

// Default current part if table is totally empty to ensure targets render
if (empty($tableData)) {
    $tableData[$partNumber] = ['name' => $partName, 'proses' => []];
    $tableData[$partNumber]['proses'][$prosesName] = ['ct' => $ctPcs, 'data_jam' => []];
    
    foreach ($jamAktif as $jam) {
        $hourlyTargetSum[$jam] = 0; // Jika tidak ada data, target tidak dimunculkan sesuai request
    }
} else {
    foreach ($jamAktif as $jam) {
        $durMinutes = isset($jamEfektif[$jam]) ? $jamEfektif[$jam] : (getDurationHours($jam) * 60);
        $durSeconds = $durMinutes * 60;
        
        $total_qty = 0;
        $total_ideal_seconds = 0;
        
        // Fill data for all known parts
        foreach ($tableData as $pn => &$partData) {
            foreach ($partData['proses'] as $proses => &$pData) {
                $key = $pn . '|' . $proses;
                if (isset($buckets[$jam][$key])) {
                    $qty = $buckets[$jam][$key]['qty'];
                    $ct_pcs = $buckets[$jam][$key]['ct'];
                    $pData['data_jam'][$jam] = $qty;
                    $hourlyActualSum[$jam] += $qty;
                    
                    $total_qty += $qty;
                    $total_ideal_seconds += ($qty * $ct_pcs);
                } else {
                    $pData['data_jam'][$jam] = 'NO DATA';
                }
            }
        }
        unset($partData);
        unset($pData);
        
        // Kalkulasi Target Blended berdasarkan Rasio Produksi
        if ($total_ideal_seconds > 0) {
            $calculatedTarget = round($durSeconds * $total_qty / $total_ideal_seconds);
        } else {
            $calculatedTarget = $targetPerJam * ($durMinutes / 60); // Fallback jika tidak ada CT
        }
        
        // Pengecekan apakah jam sudah lewat (dimunculkan setelah pindah jam saja)
        $startStr = trim(explode('-', $jam)[0]);
        $endStr = trim(explode('-', $jam)[1]);
        $today = date('Y-m-d');
        
        $currentTimeSec = time();
        $endDtSec = strtotime($today . ' ' . $endStr);
        $startDtSec = strtotime($today . ' ' . $startStr);
        
        // Handle cross midnight
        if ($endDtSec < $startDtSec) { 
            if (date('H') < 12) {
                $endDtSec = strtotime($today . ' ' . $endStr);
            } else {
                $endDtSec = strtotime('+1 day', strtotime($today . ' ' . $endStr));
            }
        }
        
        if (isBucketStarted($jam, $waktu_mulai)) {
            if ($currentTimeSec >= $endDtSec) {
                // Jam sudah lewat sepenuhnya -> Tampilkan target aslinya
                $hourlyTargetSum[$jam] = $calculatedTarget;
            } else {
                // Jam masih berjalan atau di masa depan -> Sembunyikan target (0)
                $hourlyTargetSum[$jam] = 0;
            }
        } else {
            $hourlyTargetSum[$jam] = 0;
        }
    }
}




// Inject ongoing downtime to Pareto array
if ($ongoing_downtime_sec > 0 && $ongoing_dt_label) {
    if (isset($pareto_map[$ongoing_dt_label])) {
        $pareto_map[$ongoing_dt_label] += $ongoing_downtime_sec;
    } else {
        $pareto_map[$ongoing_dt_label] = $ongoing_downtime_sec;
    }
}

// Sort descending
arsort($pareto_map);
$paretoLabels = array_keys($pareto_map); 
$paretoValues = array_values($pareto_map);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail OEE - <?= htmlspecialchars($mcID) ?></title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <style>
        :root { --bg-color: #121212; --card-bg: #1e1e1e; --text-main: #ffffff; --text-muted: #a0a0a0; --border-color: #333333; --target-color: #ff1744; --actual-color: #00bfa5; }
        body { background-color: var(--bg-color); color: var(--text-main); font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 20px; overflow-x: hidden;}
        .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); width: 280px; height: 100%; background: #1e1e1e; border-right: 1px solid #333; z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); will-change: transform; box-shadow: 4px 0 15px rgba(0,0,0,0.5); display: flex; flex-direction: column; }
        .sidebar.open { transform: translateX(0); }
        #overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999; opacity: 0; visibility: hidden; transition: opacity 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); will-change: opacity;}
        #overlay.show { opacity: 1; visibility: visible; }
        .sidebar-header { padding: 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; background: #252525;}
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #fff; }
        .close-btn { background: none; border: none; color: #a0a0a0; font-size: 28px; cursor: pointer;}
        .sidebar-menu { display: flex; flex-direction: column; padding-top: 10px; }
        .sidebar-menu a { padding: 15px 25px; color: #a0a0a0; text-decoration: none; font-size: 14px; font-weight: bold; border-bottom: 1px solid #2a2a2a; display: flex; align-items: center; gap: 10px; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: #2a2a2a; color: #00bfa5; border-left: 4px solid #00bfa5; padding-left: 30px;}
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap;}
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .btn-back { background: #475569; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 13px; }
        
        .btn-action { padding: 10px 15px; border: none; border-radius: 5px; font-weight: bold; font-size: 13px; cursor: pointer; transition: 0.2s; color: white; width: 100%;}
        .btn-lembur { background: #1e3a8a; border: 1px solid #3b82f6; } .btn-lembur:hover { background: #2563eb; }
        .btn-reset { background: #374151; border: 1px solid #6b7280; } .btn-reset:hover { background: #4b5563; }

        .card { background: var(--card-bg); border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 20px; overflow: hidden; width: 100%;}
        .card-header { background: #252525; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px solid var(--border-color); }
        .card-title { margin: 0; font-size: 15px; font-weight: bold; color: #ffffff; }
        .card-toggle { font-size: 18px; color: var(--text-muted); }
        .card-body { padding: 15px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; align-items: start; }
        .pareto-dekidaka-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; align-items: start; }
        .info-bar { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
        .info-item { text-align: left; flex: 1; border-right: 1px solid var(--border-color); padding-right:10px;}
        .info-item:last-child { border-right: none; }
        .info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: bold; margin-bottom: 5px;}
        .info-value { font-size: 18px; font-weight: bold; color: #ffffff;}
        .op-card { display: flex; align-items: flex-start; gap: 15px; }
        .op-photo { width: 85px; height: 85px; background: #333333; border-radius: 8px; object-fit: cover; border: 2px solid #555; }
        .op-details h4 { margin: 0 0 5px 0; font-size: 18px; color: #ffffff; text-transform: uppercase; }
        .op-badge { color: white; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .summary-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 15px; }
        .summary-box { background: #2a2a2a; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #444;}
        .summary-val { font-size: 26px; font-weight: bold; margin-top: 5px; }
        .chart-container { position: relative; width: 100%; min-height: 250px;}
        .donut-center-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none; }
        .table-defect { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .table-defect th { padding: 10px; border-bottom: 2px solid #444; color: var(--text-muted); }
        .table-defect td { padding: 10px; border-bottom: 1px solid #333; }
        .table-container { overflow-x: auto; width: 100%; padding-bottom: 10px;}
        
        table.dataTable { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; color: #e0e0e0; }
        table.dataTable thead th { background-color: #252525; color: #ffffff; border-bottom: 2px solid var(--actual-color); padding: 12px; text-align: center; }
        table.dataTable thead th:nth-child(1), table.dataTable thead th:nth-child(2), table.dataTable thead th:nth-child(3) { text-align: left; }
        table.dataTable tbody td { padding: 12px 10px; border: 1px solid var(--border-color); vertical-align: middle; }
        table.dataTable tbody tr:hover { background-color: #333333; }
        .td-merge { background-color: #1a1a1a; font-weight: bold; color: #00bfa5;}
        .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { color: #a0a0a0 !important; margin-bottom: 10px;}
        .dataTables_wrapper .dataTables_filter input, .dataTables_wrapper .dataTables_length select { background-color: #222; color: white; border: 1px solid #555; border-radius: 4px; padding: 4px; }
        @media (max-width: 992px) { .info-grid, .summary-metrics, .pareto-dekidaka-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div id="overlay" onclick="toggleSidebar()"></div>
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header"><h2>PT CNC Apps</h2><button class="close-btn" onclick="toggleSidebar()">×</button></div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php">📁 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php">📈 Rangkuman OEE</a>
            <?php if(isset($user_role) && $user_role === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/pengaturan_jam.php">⏱️ Master Jam (Template)</a>
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/settings_auth.php">🔒 Pengaturan Keamanan</a>
            <?php endif; ?>
            <?php if(isset($user_role) && in_array($user_role, ['admin', 'it'])): ?>
                <a href="<?= BASE_URL ?>admin/skill_matrix.php">🎯 Skill Matrix Mesin</a>
                <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
                <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <a href="javascript:history.back()" class="btn-back">← Kembali</a>
        </div>
        <div style="text-align:center; flex-grow:1;">
            <h2 style="margin:0;">📊 Detail: <?= htmlspecialchars($mcID) ?> 
                <?php if ($shift_aktif !== 'OFF SHIFT'): ?><span style="color:#00bfa5;">(<?= $shift_aktif ?>)</span><?php endif; ?>
            </h2>
        </div>
        <div style="text-align:center;">
            <div style="background: <?= $statusWarna ?>; color: <?= ($statusWarna == '#ffea00') ? '#000' : '#fff' ?>; padding: 8px 15px; border-radius: 20px; font-weight: bold; display: inline-block;">
                STATUS: <?= $statusTeks ?>
            </div>
            <div id="clockWIB" style="margin-top: 8px; font-size: 14px; font-weight: bold; color: #a0a0a0; letter-spacing: 1.5px;">--:--:-- WIB</div>
        </div>
    </div>

    <div class="info-grid">
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">👤 Profil Operator & Skill</h3><span class="card-toggle">−</span></div>
            <div class="card-body">
                <div class="op-card" style="margin-bottom: 15px;">
                    <img id="opPhoto" src="<?= BASE_URL ?>assets/foto_operator/<?= !empty($opFoto) ? htmlspecialchars($opFoto) : 'default' ?>" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'" class="op-photo">
                    <div class="op-details">
                        <h4 id="opName"><?= htmlspecialchars($operatorName) ?></h4>
                        <span id="opBadge" class="op-badge" style="background-color: <?= $opSkillColor ?>;"><?= htmlspecialchars($opSkillLabel) ?></span>
                        <div id="opNikInfo" style="font-size: 12px; color: #a0a0a0; margin-top: 8px;">Lisensi: Aktif | NIK: <?= htmlspecialchars($nikOP) ?></div>
                    </div>
                </div>
                <hr style="border: 0; border-top: 1px dashed var(--border-color); margin: 10px 0;">
                <div style="font-size: 12px; color: var(--text-muted); font-weight: bold; margin-bottom: 8px;">RIWAYAT OPERATOR:</div>
                <div id="opHistoryList" style="max-height: 80px; overflow-y: auto; font-size: 13px; color: #e0e0e0;">
                    <?php
                    if (!empty($opHistoryData)) {
                        foreach ($opHistoryData as $history) {
                            $jamMulai = substr($history['jam_mulai'], 0, 5); $jamSelesai = substr($history['jam_selesai'], 0, 5);
                            $qty = $history['total_qty'] > 0 ? $history['total_qty'] : 0;
                            echo "<div style='display: flex; justify-content: space-between; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #333;'>";
                            echo "<span>👤 <strong>".htmlspecialchars($history['nama'] ?: $history['op_NIK'])."</strong><br><span style='color:#a0a0a0; font-size:11px;'>$jamMulai - $jamSelesai</span></span>";
                            echo "<span style='color:#00bfa5; font-weight:bold;'>{$qty} Pcs</span></div>";
                        }
                    } else { echo "<span style='color:#777;'>Belum ada data operator.</span>"; }
                    ?>
                </div>
            </div>
        </div>
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">⚙️ Proses Saat Ini</h3><span class="card-toggle">−</span></div>
            <div class="card-body info-bar" style="align-items: center;">
                <div class="info-item"><div class="info-label">Part Name / Number</div><div class="info-value" style="font-size:15px;"><?= htmlspecialchars($partName) ?><br><span style="color:#00bfa5;"><?= htmlspecialchars($partNumber) ?></span></div></div>
                <div class="info-item"><div class="info-label">Proses Aktif</div><div class="info-value"><?= htmlspecialchars($prosesName) ?><br><span style="font-size:12px; color:#a0a0a0;">CT: <?= $ctPcs ?>s</span></div></div>
                <div class="info-item"><div class="info-label">Target / Jam</div><div class="info-value text-red" style="font-size:24px; color:#ff1744;"><?= $targetPerJam ?></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px;">
        <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📋 Summary Defect & Output</h3><span class="card-toggle">−</span></div>
        <div class="card-body">
            <div style="overflow-x: auto; margin-bottom: 20px; background: #252525; padding: 10px; border-radius: 8px;">
                <table class="table-defect">
                    <thead><tr><th>Part Name</th><th>Part Number</th><th>Kategori Defect</th><th>Qty NG</th><th>Qty Repair</th><th style="color:#ff1744;">Total Scrap</th></tr></thead>
                    <tbody>
                        <?php
                        if ($res_ng_summary && $res_ng_summary->num_rows > 0) {
                            while($ngRow = $res_ng_summary->fetch_assoc()) {
                                $ng_plus = (int)$ngRow['qty_ng_plus'];
                                $repair = (int)$ngRow['qty_repair'];
                                $net_scrap = (int)$ngRow['net_scrap'];
                                $dispPartName = $ngRow['log_part_name'] ?: $partName;
                                $dispPartNumber = $ngRow['log_part_number'] ?: $partNumber;
                                $dispProsesDesc = $ngRow['log_proses_desc'] ?: $prosesDesc;
                                echo "<tr><td style='color:#00bfa5; font-weight:bold;'>".htmlspecialchars($dispPartName)."</td><td>".htmlspecialchars($dispPartNumber)."<br><span style='color:#a0a0a0; font-size:11px;'>".htmlspecialchars($dispProsesDesc)."</span></td><td>".htmlspecialchars($ngRow['nama_defect'] ?: 'Lain-lain')."</td><td>{$ng_plus} Pcs</td><td style='color:#ffeb3b;'>{$repair} Pcs</td><td style='color:#ff1744; font-weight:bold;'>{$net_scrap} Pcs</td></tr>";
                            }
                        } else { echo "<tr><td colspan='6' style='padding:15px; color:#777; text-align:center;'>Belum ada laporan NG.</td></tr>"; }
                        ?>
                    </tbody>
                </table>
            </div>
            <div class="summary-metrics">
                <div class="summary-box"><div style="font-size:12px; color:var(--text-muted); font-weight:bold;">TOTAL OUTPUT OK</div><div class="summary-val" style="color:#00e676;"><?= $totalOK ?> <span style="font-size:14px;">Pcs</span></div></div>
                <div class="summary-box"><div style="font-size:12px; color:var(--text-muted); font-weight:bold;">TOTAL NG</div><div class="summary-val" style="color:#ff1744;"><?= $totalNG ?> <span style="font-size:14px;">Pcs</span></div></div>
                <div class="summary-box"><div style="font-size:12px; color:var(--text-muted); font-weight:bold;">TOTAL REPAIR</div><div class="summary-val" style="color:#ffeb3b;"><?= $totalRepair ?> <span style="font-size:14px;">Pcs</span></div></div>
                <div class="summary-box"><div style="font-size:12px; color:var(--text-muted); font-weight:bold;">TOTAL AKUMULASI SCRAP</div><div class="summary-val" style="color:#ff1744;"><?= $totalScrap ?> <span style="font-size:14px;">Pcs</span></div></div>
            </div>
        </div>
    </div>

    <div class="pareto-dekidaka-grid">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📉 Pareto Downtime</h3><span class="card-toggle">−</span></div>
            <div class="card-body">
                <div class="chart-container"><canvas id="paretoChart"></canvas><div class="donut-center-text"><div style="font-size: 11px; color: #a0a0a0; text-transform: uppercase;">Total Losstime</div><div style="font-size: 28px; font-weight: bold; color: #f59e0b; line-height: 1.1;"><?= $totalLosstimeMenit ?></div><div style="font-size: 12px; color: #a0a0a0;">Menit</div></div></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📊 Actual vs Target (Template: <?= $template_aktif ?>)</h3><span class="card-toggle">−</span></div>
            <div class="card-body">
                <div style="display:flex; justify-content:center; gap: 40px; margin-bottom: 20px;">
                    <div style="text-align:center;">
                        <div style="font-size:12px; color:var(--text-muted); font-weight:bold; text-transform:uppercase;">Total Actual</div>
                        <div style="font-size:28px; font-weight:bold; color:#00bfa5;"><?= number_format(array_sum($hourlyActualSum ?? [])) ?> <span style="font-size:14px; color:#a0a0a0;">Pcs</span></div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:12px; color:var(--text-muted); font-weight:bold; text-transform:uppercase;">Total Target</div>
                        <div style="font-size:28px; font-weight:bold; color:#ff1744;"><?= number_format(round(array_sum($hourlyTargetSum ?? []))) ?> <span style="font-size:14px; color:#a0a0a0;">Pcs</span></div>
                    </div>
                </div>
                <div class="chart-container"><canvas id="dekidakaChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📑 Rincian Produksi Per Jam</h3><span class="card-toggle">−</span></div>
        <div class="card-body"> 
            <div class="table-container">
                <table id="rincianTable" class="display nowrap">
                    <thead><tr><th>Part Name</th><th>Part Number</th><th>Proses (CT)</th><?php foreach ($jamAktif as $jamStr) { echo "<th style='text-align:center;'>{$jamStr}</th>"; } ?><th style="color:#00bfa5; text-align:center;">Total</th></tr></thead>
                    <tbody>
                        <?php
                        if (!empty($tableData)) {
                            foreach ($tableData as $pn => $partData) {
                                foreach ($partData['proses'] as $prosesName => $pData) {
                                    echo "<tr><td class='td-merge'>" . htmlspecialchars($partData['name']) . "</td><td class='td-merge'>" . htmlspecialchars($pn) . "</td><td>" . htmlspecialchars($prosesName) . " <br><span style='color:#a0a0a0; font-size:11px;'>(CT: {$pData['ct']}s)</span></td>";
                                    $rowTotal = 0;
                                    foreach ($jamAktif as $jamStr) { 
                                        $qty = isset($pData['data_jam'][$jamStr]) ? $pData['data_jam'][$jamStr] : 'NO DATA'; 
                                        
                                        // VISUALISASI NO DATA
                                        if($qty === 'NO DATA') {
                                            echo "<td style='color:#555555; font-size:14px; font-weight:bold; text-align:center;'>0</td>";
                                        } else {
                                            echo "<td style='text-align:center; font-weight:bold; font-size:14px;'>{$qty}</td>"; 
                                            $rowTotal += $qty; 
                                        }
                                    }
                                    echo "<td style='font-weight:bold; color:#00bfa5; text-align:center; font-size:15px;'>{$rowTotal}</td></tr>";
                                }
                            }
                        }
                        ?>
                    </tbody>
                    <?php
                        if (!empty($tableData)) {
                            // BARIS TOTAL AKUMULASI DI BAWAH TABEL
                            echo "<tfoot style='background-color:#1a1a1a;'><tr><td colspan='3' style='text-align:right; font-weight:bold; color:#00bfa5; padding:12px 20px 12px 12px; letter-spacing:1px;'>TOTAL</td>";
                            $grandTotal = 0;
                            foreach ($jamAktif as $jamStr) {
                                $colTotal = $hourlyActualSum[$jamStr] ?? 0;
                                $grandTotal += $colTotal;
                                echo "<td style='text-align:center; font-weight:bold; font-size:15px; color:#fff;'>{$colTotal}</td>";
                            }
                            echo "<td style='text-align:center; font-weight:bold; font-size:16px; color:#00e676;'>{$grandTotal}</td></tr></tfoot>";
                        }
                    ?>
                </table>
            </div>
        </div>
    </div>

    <!-- SETTING QUICK ACTION DIBAWAH -->
    <div class="card" style="border-color:#3b82f6;">
        <div class="card-header" onclick="toggleCard(this)" style="color:#3b82f6; border-bottom-color:#3b82f6;"><h3 class="card-title" style="color:#3b82f6;">🛠️ Pengaturan Tambah Jam Kerja</h3><span class="card-toggle">−</span></div>
        <div class="card-body">
            <div style="background:#252525; padding:20px; border-radius:8px; border:1px solid #333; max-width:800px; margin:0 auto;">
                <h4 style="margin-top:0; color:#fff; font-size:15px;">🔵 Atur Tambahan Jam Kerja Mesin</h4>
                <p style="font-size:12px; color:#a0a0a0; margin-bottom:15px;">Tentukan posisi kolom jam tambahan. Jika memilih <b>Awal</b>, jam akan muncul di depan (kiri) tabel. Jika <b>Akhir</b>, jam muncul di belakang (kanan) tabel.</p>
                <form method="POST" style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                    <div style="flex: 1; min-width:150px;">
                        <label style="font-size:11px; color:#a0a0a0; font-weight:bold;">Posisi Jam Kerja</label>
                        <select name="posisi_lembur" required style="width:100%; padding:10px; background:#121212; color:#fff; border:1px solid #444; border-radius:4px; box-sizing:border-box;">
                            <option value="LEMBUR_AKHIR" <?= ($jenis_lembur == 'LEMBUR_AKHIR') ? 'selected' : '' ?>>Jam Akhir</option>
                            <option value="LEMBUR_AWAL" <?= ($jenis_lembur == 'LEMBUR_AWAL') ? 'selected' : '' ?>>Jam Awal</option>
                        </select>
                    </div>
                    <div style="flex: 1; min-width:120px;">
                        <label style="font-size:11px; color:#a0a0a0; font-weight:bold;">Jam Mulai (HH:mm)</label>
                        <input type="text" name="jam_mulai" placeholder="19:00" required style="width:100%; padding:10px; background:#121212; color:#fff; border:1px solid #444; border-radius:4px; box-sizing:border-box;" value="<?= $is_lembur ? htmlspecialchars($override['jam_mulai']) : '' ?>">
                    </div>
                    <div style="flex: 1; min-width:120px;">
                        <label style="font-size:11px; color:#a0a0a0; font-weight:bold;">Jam Selesai (HH:mm)</label>
                        <input type="text" name="jam_selesai" placeholder="21:00" required style="width:100%; padding:10px; background:#121212; color:#fff; border:1px solid #444; border-radius:4px; box-sizing:border-box;" value="<?= $is_lembur ? htmlspecialchars($override['jam_selesai']) : '' ?>">
                    </div>
                    <div style="display:flex; gap:10px; flex: 2; min-width:250px;">
                        <button type="submit" name="set_lembur" class="btn-action btn-lembur" style="flex:1;">SIMPAN JAM KERJA</button>
                        <button type="submit" name="reset_override" class="btn-action btn-reset" style="flex:1;" onclick="return confirm('Kembalikan mesin ke jadwal normal?')" <?= !$is_lembur ? 'disabled style="opacity:0.5;"' : '' ?>>RESET NORMAL</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateOperatorProfile() {
            $.getJSON('detail.php', { ajax: '1', action: 'get_operator_info', mcID: <?= json_encode($mcID) ?> }, function(data) {
                if (data && data.status === 'success') {
                    $('#opName').text(data.nama);
                    $('#opBadge').css('background-color', data.skill_color).text(data.skill_label);
                    $('#opNikInfo').text('Lisensi: Aktif | NIK: ' + data.nik);
                    
                    let photoUrl = data.foto ? '<?= BASE_URL ?>assets/foto_operator/' + data.foto : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                    let img = new Image();
                    img.onload = function() { $('#opPhoto').attr('src', photoUrl); };
                    img.onerror = function() { $('#opPhoto').attr('src', 'https://cdn-icons-png.flaticon.com/512/149/149071.png'); };
                    img.src = photoUrl;

                    let historyHtml = '';
                    if (data.history && data.history.length > 0) {
                        data.history.forEach(function(h) {
                            let jMulai = h.jam_mulai ? h.jam_mulai.substring(0, 5) : '--:--';
                            let jSelesai = h.jam_selesai ? h.jam_selesai.substring(0, 5) : '--:--';
                            let qty = h.total_qty > 0 ? h.total_qty : 0;
                            let namaOp = h.nama ? h.nama : h.op_NIK;
                            historyHtml += `<div style='display: flex; justify-content: space-between; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #333;'>
                                <span>👤 <strong>${namaOp}</strong><br><span style='color:#a0a0a0; font-size:11px;'>${jMulai} - ${jSelesai}</span></span>
                                <span style='color:#00bfa5; font-weight:bold;'>${qty} Pcs</span></div>`;
                        });
                    } else {
                        historyHtml = "<span style='color:#777;'>Belum ada data operator.</span>";
                    }
                    $('#opHistoryList').html(historyHtml);
                }
            });
        }
        setInterval(updateOperatorProfile, 3000);
        setInterval(function() { window.location.reload(); }, 15000);

        function toggleSidebar() { document.getElementById("sidebar").classList.toggle("open"); document.getElementById("overlay").classList.toggle("show"); }
        // FIX: Suppress DataTables alert popup
        $.fn.dataTable.ext.errMode = 'none';
        $(document).ready(function() {
            var headerCount = $('#rincianTable thead th').length;
            var firstRowCols = $('#rincianTable tbody tr:first td').length;
            if (headerCount > 0 && (firstRowCols === headerCount || firstRowCols === 0)) {
                $('#rincianTable').DataTable({ "ordering": false, "pageLength": 10, "language": { "search": "Cari Data:", "emptyTable": "NO DATA" } });
            }
        });
        function toggleCard(el) { const body = el.nextElementSibling; const icon = el.querySelector('.card-toggle'); if (body.style.display === "none") { body.style.display = ""; icon.innerHTML = "−"; } else { body.style.display = "none"; icon.innerHTML = "+"; } }
        function stringToColor(str) { let hash = 0; for (let i = 0; i < str.length; i++) { hash = str.charCodeAt(i) + ((hash << 5) - hash); } let color = '#'; for (let i = 0; i < 3; i++) { let value = (hash >> (i * 8)) & 0xFF; color += ('00' + value.toString(16)).substr(-2); } return color; }
        Chart.register(ChartDataLabels);

        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clockWIB').innerText = `${h}:${m}:${s} WIB`;
        }
        setInterval(updateClock, 1000); updateClock();

        const paretoCtx = document.getElementById('paretoChart').getContext('2d');
        const dtLabels = <?= json_encode(!empty($paretoLabels) ? $paretoLabels : ['No Downtime']) ?>; const dtData = <?= json_encode(!empty($paretoValues) ? $paretoValues : [0]) ?>;
        new Chart(paretoCtx, { type: 'doughnut', data: { labels: dtLabels, datasets: [{ data: dtData, backgroundColor: dtLabels.map(l => stringToColor(l)), borderWidth: 0, cutout: '70%' }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#ffffff' } }, datalabels: { display: false } } } });

        const dekidakaCtx = document.getElementById('dekidakaChart').getContext('2d');
        new Chart(dekidakaCtx, { type: 'bar', data: { labels: <?= json_encode(!empty($jamAktif) ? $jamAktif : ['Belum Ada Setting Jam']) ?>, datasets: [ { label: 'Actual', data: <?= json_encode(!empty($hourlyActualSum) ? array_values($hourlyActualSum) : [0]) ?>, backgroundColor: '#00bfa5', borderRadius: 2 }, { label: 'Target', data: <?= json_encode(!empty($hourlyTargetSum) ? array_values($hourlyTargetSum) : [0]) ?>, backgroundColor: '#ff1744', borderRadius: 2 } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#ffffff' } }, datalabels: { display: false } }, scales: { y: { grid: { color: '#333' }, ticks: { color: '#a0a0a0' } }, x: { grid: { display: false }, ticks: { color: '#fff' } } } } });
    </script>
</body>
</html>