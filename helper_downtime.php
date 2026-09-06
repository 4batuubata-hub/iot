<?php

// helper_downtime.php

function getLogicalDayForDT($time) {
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

function getShiftForDT($conn, $time) {
    $sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
    $row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
    $jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
    $jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:00:00';
    
    $now = date('H:i:s', $time);
    
    if ($now >= $jam_reset_s2 && $now < $jam_reset_s1) {
        return 'SHIFT 1';
    } else {
        return 'SHIFT 2';
    }
}

function calculate_effective_downtime($conn, $start_time, $end_time, $template, $shift, $hari) {
    $tpl_esc = $conn->real_escape_string($template);
    $sql_slots = "SELECT rentang_jam FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift' AND (hari = '$hari' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
    $res_slots = $conn->query($sql_slots);
    
    if (!$res_slots || $res_slots->num_rows == 0) return 0;

    $total_effective = 0;
    
    $anchor_date_start = date('Y-m-d', $start_time);
    if (date('H:i:s', $start_time) < '07:00:00') {
        $anchor_date_start = date('Y-m-d', strtotime('-1 day', $start_time));
    }
    
    $anchor_date_end = date('Y-m-d', $end_time);
    if (date('H:i:s', $end_time) < '07:00:00') {
        $anchor_date_end = date('Y-m-d', strtotime('-1 day', $end_time));
    }
    
    $curr_date = $anchor_date_start;
    
    while (strtotime($curr_date) <= strtotime($anchor_date_end)) {
        $res_slots->data_seek(0);
        while ($r_slot = $res_slots->fetch_assoc()) {
            $rentang = explode('-', $r_slot['rentang_jam']);
            if (count($rentang) != 2) continue;
            
            $str_start = trim($rentang[0]);
            $str_end = trim($rentang[1]);
            
            $slot_start_time = strtotime($curr_date . ' ' . $str_start);
            $slot_end_time = strtotime($curr_date . ' ' . $str_end);
            
            if ($str_start >= '00:00:00' && $str_start < '07:00:00') {
                $slot_start_time = strtotime('+1 day', $slot_start_time);
            }
            if ($str_end >= '00:00:00' && $str_end <= '07:00:00') {
                $slot_end_time = strtotime('+1 day', $slot_end_time);
            }
            
            $overlap_start = max($start_time, $slot_start_time);
            $overlap_end = min($end_time, $slot_end_time);
            
            if ($overlap_end > $overlap_start) {
                $total_effective += ($overlap_end - $overlap_start);
            }
        }
        $curr_date = date('Y-m-d', strtotime('+1 day', strtotime($curr_date)));
    }
    
    return $total_effective;
}
?>
