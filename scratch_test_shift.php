<?php
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
$conn->query("SET time_zone = '+07:00'");

function getLogicalDay() {
    $now = date('H:i:s');
    $day_num = date('N'); 
    
    if ($now < '07:00:00') {
        $day_num = $day_num - 1;
        if ($day_num == 0) $day_num = 7; 
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

$line = 'ALL';
$shift_info = getActiveShift($conn, $line);
print_r($shift_info);

$shift_aktif = $shift_info['shift'];
$shift_target = ($shift_aktif === 'OFF SHIFT' || $shift_aktif === 'LEMBUR AKTIF') ? 'SHIFT 1' : $shift_aktif;

echo "\nShift aktif: $shift_aktif\n";
echo "Shift target: $shift_target\n";
