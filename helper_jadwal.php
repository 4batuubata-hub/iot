<?php
/**
 * Helper Resolusi Jadwal Kerja & Lini Pabrik
 * Arsitektur Bersih (Clean MES Architecture) untuk Multi-Jadwal
 * Mengadaptasi JADWAL KERJA A, JADWAL KERJA B, dan template masa depan.
 */

if (!defined('HELPER_JADWAL_LOADED')) {
    define('HELPER_JADWAL_LOADED', true);

    /**
     * Dapatkan nama template jadwal untuk suatu nama line.
     * Fallback aman ke 'JADWAL KERJA A' jika tidak ditemukan.
     */
    function getLineTemplate($conn, $line) {
        if (empty($line) || $line === 'ALL') return 'JADWAL KERJA A';
        $lineEsc = $conn->real_escape_string(trim($line));
        $sql = "SELECT nama_template FROM master_line WHERE nama_line = '$lineEsc' LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!empty($row['nama_template'])) return $row['nama_template'];
        }
        return 'JADWAL KERJA A';
    }

    if (!function_exists('getLogicalDay')) {
        function getLogicalDay($time = null) {
            if ($time === null) $time = time();
            $now = date('H:i:s', $time);
            $day_num = date('N', $time); // 1 (Monday) to 7 (Sunday)
            
            if ($now < '06:00:00') {
                $day_num = $day_num - 1;
                if ($day_num == 0) $day_num = 7; // Sunday wrap
            }
            
            if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
            if ($day_num == 5) return 'JUMAT';
            if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
            
            return 'SENIN-KAMIS';
        }
    }

    if (!function_exists('getActiveShift')) {
        function getActiveShift($conn, $line, $time = null) {
            $sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
            $row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
            $jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
            $jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:00:00';

            if ($time === null) $time = time();
            $now = date('H:i:s', $time); 
            $today = date('Y-m-d', $time);
            
            $is_shift_1 = false;
            if ($jam_reset_s2 <= $jam_reset_s1) {
                if ($now >= $jam_reset_s2 && $now < $jam_reset_s1) {
                    $is_shift_1 = true;
                }
            } else {
                if ($now >= $jam_reset_s2 || $now < $jam_reset_s1) {
                    $is_shift_1 = true;
                }
            }

            $shift_aktif = $is_shift_1 ? 'SHIFT 1' : 'SHIFT 2';
            if ($is_shift_1) {
                $mulai = "$today $jam_reset_s2";
                $selesai = "$today $jam_reset_s1";
            } else {
                if ($now >= $jam_reset_s1) {
                    $mulai = "$today $jam_reset_s1";
                    $selesai = date('Y-m-d', strtotime('+1 day', $time)) . " $jam_reset_s2";
                } else {
                    $mulai = date('Y-m-d', strtotime('-1 day', $time)) . " $jam_reset_s1";
                    $selesai = "$today $jam_reset_s2";
                }
            }

            $hari = getLogicalDay(strtotime($mulai));

            $template = getLineTemplate($conn, $line);
            $templates_to_check = array_unique([$template, 'JADWAL KERJA A']);
            $slots = [];
            $found_template = 'JADWAL KERJA A';

            foreach ($templates_to_check as $tpl) {
                if (empty($tpl)) continue;
                $tpl_esc = $conn->real_escape_string($tpl);
                $sql = "SELECT shift, rentang_jam FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift_aktif' AND (hari = '$hari' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
                $res = $conn->query($sql);
                
                if ($res && $res->num_rows > 0) {
                    $found_template = $tpl;
                    while($r = $res->fetch_assoc()) {
                        $p = explode('-', $r['rentang_jam']);
                        if(count($p) == 2) {
                            $slots[] = ['start' => trim($p[0]).":00", 'end' => trim($p[1]).":00"];
                        }
                    }
                    break;
                }
            }

            // Hitung jam kerja nyata shift (Real Working Window) dari slot pertama dan terakhir
            $work_start = $mulai;
            $work_end = $selesai;
            if (!empty($slots)) {
                $first_s = $slots[0]['start'];
                $last_e = end($slots)['end'];
                $m_date = date('Y-m-d', strtotime($mulai));
                $work_start = "$m_date $first_s";
                
                $e_date = $m_date;
                if ($last_e < $first_s) {
                    $e_date = date('Y-m-d', strtotime($m_date . ' +1 day'));
                }
                $work_end = "$e_date $last_e";
            }

            return [
                'shift' => $shift_aktif,
                'mulai' => $mulai,
                'selesai' => $selesai,
                'waktu_mulai' => $mulai,
                'waktu_selesai' => $selesai,
                'work_start' => $work_start,
                'work_end' => $work_end,
                'template' => $found_template,
                'hari' => $hari,
                'slots' => $slots
            ];
        }
    }

    /**
     * Dapatkan informasi Line & Template mesin secara dinamis (3-tier fallback).
     * Tidak mewajibkan mapping manual di awal.
     */
    function getMesinLineInfo($conn, $mcID, $currentKodeProses = '') {
        $mcEsc = $conn->real_escape_string($mcID);

        // Prioritas 1: Part/proses yang sedang aktif berjalan di mesin
        if (!empty($currentKodeProses)) {
            $kpEsc = $conn->real_escape_string($currentKodeProses);
            $res = $conn->query("SELECT line FROM master_ct WHERE kode = '$kpEsc' LIMIT 1");
            if ($res && $res->num_rows > 0) {
                $line = $res->fetch_assoc()['line'];
                if (!empty($line)) {
                    return [
                        'line' => $line,
                        'template' => getLineTemplate($conn, $line),
                        'source' => 'CURRENT_PROCESS'
                    ];
                }
            }
        }

        // Prioritas 2: Transaksi terakhir di log_quality (jika ada)
        $sqlLog = "SELECT mc.line FROM log_quality lq 
                   JOIN master_ct mc ON lq.kode_proses = mc.kode 
                   WHERE (lq.mcID = '$mcEsc' OR lq.mcID IN (SELECT id_mesin FROM master_mesin WHERE mcID = '$mcEsc')) 
                     AND lq.kode_proses IS NOT NULL AND lq.kode_proses != '' 
                   ORDER BY lq.id DESC LIMIT 1";
        $resLog = $conn->query($sqlLog);
        if ($resLog && $resLog->num_rows > 0) {
            $line = $resLog->fetch_assoc()['line'];
            if (!empty($line)) {
                return [
                    'line' => $line,
                    'template' => getLineTemplate($conn, $line),
                    'source' => 'RECENT_LOG'
                ];
            }
        }

        // Prioritas 3: Memori part terakhir dari history_summary
        $sqlHist = "SELECT mc.line FROM history_summary hs 
                    JOIN master_ct mc ON hs.part_name = mc.part_name 
                    WHERE (hs.mcID = '$mcEsc' OR hs.nama_mesin = '$mcEsc') AND mc.line != '' 
                    ORDER BY hs.id DESC LIMIT 1";
        $resHist = $conn->query($sqlHist);
        if ($resHist && $resHist->num_rows > 0) {
            $line = $resHist->fetch_assoc()['line'];
            if (!empty($line)) {
                return [
                    'line' => $line,
                    'template' => getLineTemplate($conn, $line),
                    'source' => 'HISTORY_MEMORY'
                ];
            }
        }

        // Prioritas 4: Default Pabrik (Standar Line Utama)
        return [
            'line' => 'PRESSING 1',
            'template' => 'JADWAL KERJA A',
            'source' => 'FACTORY_DEFAULT'
        ];
    }

    /**
     * Ambil slot jam kerja statis resmi dari master_jam_statis.
     * Menghasilkan array: ['07:00 - 08:00' => 60, ...]
     * Dijamin tidak pernah mengembalikan array kosong.
     */
    function getJadwalStatisSlots($conn, $template, $shift, $hari) {
        $tplEsc = $conn->real_escape_string($template);
        $shiftEsc = $conn->real_escape_string($shift);
        $hariEsc = $conn->real_escape_string($hari);

        $sql = "SELECT rentang_jam, menit_efektif FROM master_jam_statis 
                WHERE nama_template = '$tplEsc' AND shift = '$shiftEsc' AND (hari = '$hariEsc' OR hari = 'SETIAP HARI') 
                ORDER BY urutan ASC";
        $res = $conn->query($sql);

        $slots = [];
        if ($res && $res->num_rows > 0) {
            while ($r = $res->fetch_assoc()) {
                $slots[$r['rentang_jam']] = (int)$r['menit_efektif'];
            }
        } else {
            // Fallback aman ke JADWAL KERJA A untuk shift & hari tersebut
            $sqlFb = "SELECT rentang_jam, menit_efektif FROM master_jam_statis 
                      WHERE nama_template = 'JADWAL KERJA A' AND shift = '$shiftEsc' AND (hari = '$hariEsc' OR hari = 'SETIAP HARI') 
                      ORDER BY urutan ASC";
            $resFb = $conn->query($sqlFb);
            if ($resFb && $resFb->num_rows > 0) {
                while ($r = $resFb->fetch_assoc()) {
                    $slots[$r['rentang_jam']] = (int)$r['menit_efektif'];
                }
            }
        }
        return $slots;
    }

    /**
     * Hitung kapasitas fisik teoritis maksimum per jam berdasarkan Cycle Time.
     * Rumus: 3600 detik / CT.
     */
    function getPhysicalHourlyCapacity($ct_pcs) {
        $ct = (float)$ct_pcs;
        if ($ct <= 0) return 3600; // Fallback 1 detik jika data CT hilang
        return (int)round(3600 / $ct);
    }

    /**
     * Cek apakah waktu (H:i:s) berada di dalam suatu rentang jam ('07:00 - 08:00').
     * Menangani rentang lintas tengah malam secara presisi.
     */
    function isTimeInSlotRange($time, $rangeStr) {
        $p = explode('-', $rangeStr);
        if (count($p) !== 2) return false;
        $start = trim($p[0]) . ":00";
        $end = trim($p[1]) . ":00";
        if ($start <= $end) {
            return ($time >= $start && $time < $end);
        } else {
            // Cross midnight (misal 23:30 - 01:30)
            return ($time >= $start || $time < $end);
        }
    }

    /**
     * Klasifikasikan label downtime ke 6 kategori standar TPM / JIPM (Six Big Losses).
     */
    function classifyDowntimeCategory($label) {
        $clean = trim(strtoupper($label));
        
        // 1. Breakdown Loss (Equipment Failure)
        $breakdown_keywords = ['PROBLEM MESIN', 'PROBLEM INSP JIG', 'PROBLEM JIG PROSES', 'PROBLEM QUALITAS', 'WIRE LAS MACET', 'NOZZLE/CONTACT TIP', 'ALARM', 'ERROR'];
        foreach ($breakdown_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'BREAKDOWN',
                    'category_label' => 'Breakdown / Kerusakan',
                    'color' => '#ef4444',
                    'is_loss' => true
                ];
            }
        }

        // 2. Setup & Adjustment Loss
        $setup_keywords = ['DANDORY', 'ENG TRIAL', 'QC TRIAL', 'TEACHING', 'GANTI DIES', 'SETTING'];
        foreach ($setup_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'SETUP_DANDORY',
                    'category_label' => 'Setup & Dandory',
                    'color' => '#f59e0b',
                    'is_loss' => true
                ];
            }
        }

        // 3. Operational / Logistics Stoppage
        $operational_keywords = ['MATERIAL HABIS', 'TUNGGU MATERIAL', 'REFILL MATERIAL', 'PERSIAPAN SARANA', 'TAMBAHAN PROSES', 'TUNGGU CRANE', 'TUNGGU FORKLIFT'];
        foreach ($operational_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'OPERATIONAL',
                    'category_label' => 'Operasional & Material',
                    'color' => '#3b82f6',
                    'is_loss' => true
                ];
            }
        }

        // 4. Planned Maintenance & 5S
        $maint_keywords = ['TPM', 'PERAWATAN', '5P/5R', 'P5M', 'OJT'];
        foreach ($maint_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'MAINTENANCE',
                    'category_label' => 'Pemeliharaan / 5S',
                    'color' => '#8b5cf6',
                    'is_loss' => true
                ];
            }
        }

        // 5. Personal Operator
        $personal_keywords = ['TOILET', 'MINUM', 'SHOLAT', 'OPERATOR IZIN'];
        foreach ($personal_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'PERSONAL',
                    'category_label' => 'Personal Operator',
                    'color' => '#ec4899',
                    'is_loss' => true
                ];
            }
        }

        // 6. In-Shift Off / Standby
        $off_keywords = ['MESIN OFF', 'STAND BY', 'STANDBY', 'SB', 'OFF', 'TIDAK ADA PLANNING'];
        foreach ($off_keywords as $k) {
            if (strpos($clean, $k) !== false) {
                return [
                    'category' => 'IN_SHIFT_OFF',
                    'category_label' => 'Mesin Mati / Standby',
                    'color' => '#64748b',
                    'is_loss' => true
                ];
            }
        }

        // Fallback Other Loss
        return [
            'category' => 'OTHER',
            'category_label' => 'Lain-lain',
            'color' => '#94a3b8',
            'is_loss' => true
        ];
    }

    /**
     * Potong durasi interval waktu agar hanya yang berada di dalam jam shift aktif yang dihitung.
     * Mengeliminasi Non-Scheduled Time (Off-Shift) dari perhitungan Downtime.
     */
    function clipIntervalToShift($start_ts, $end_ts, $shift_start_ts, $shift_end_ts) {
        if ($end_ts <= $shift_start_ts || $start_ts >= $shift_end_ts) {
            return 0;
        }
        $effective_start = max($start_ts, $shift_start_ts);
        $effective_end = min($end_ts, $shift_end_ts);
        return max(0, $effective_end - $effective_start);
    }

    /**
     * Hitung Planned Production Time (PPT) kumulatif hingga waktu tertentu ($currentTime)
     * secara proporsional sesuai standar ISO 22400-2.
     */
    function calculateShiftPPTSeconds($conn, $template, $shift, $hari, $waktu_mulai, $currentTime = null) {
        if ($currentTime === null) $currentTime = time();
        $slots = getJadwalStatisSlots($conn, $template, $shift, $hari);
        $ppt_sec = 0;
        $mulai_date = date('Y-m-d', strtotime($waktu_mulai));
        $shift_start_hour = date('H:i:s', strtotime($waktu_mulai));

        foreach ($slots as $range => $menit_efektif) {
            $p = explode('-', $range);
            if (count($p) !== 2) continue;
            
            $start_h = trim($p[0]) . ":00";
            $end_h = trim($p[1]) . ":00";
            
            $b_date = $mulai_date;
            if ($start_h < $shift_start_hour) {
                $b_date = date('Y-m-d', strtotime($mulai_date . ' +1 day'));
            }
            $slot_start_ts = strtotime($b_date . ' ' . $start_h);
            
            $e_date = $b_date;
            if ($end_h <= $start_h) {
                $e_date = date('Y-m-d', strtotime($b_date . ' +1 day'));
            }
            $slot_end_ts = strtotime($e_date . ' ' . $end_h);
            
            $slot_phys_dur = $slot_end_ts - $slot_start_ts;
            if ($slot_phys_dur <= 0) continue;
            
            if ($currentTime > $slot_start_ts) {
                $elapsed = min($currentTime, $slot_end_ts) - $slot_start_ts;
                $eff_sec = $menit_efektif * 60;
                $ppt_sec += ($elapsed / $slot_phys_dur) * $eff_sec;
            }
        }
        return round($ppt_sec);
    }

    /**
     * Rumus Baku KPI Standar Internasional ISO 22400-2 untuk OEE, Availability, Performance, Quality.
     */
    function calculateStandardOEE($ppt_sec, $loss_sec, $total_prod, $total_scrap, $ct_sec) {
        $operating_sec = max(0, $ppt_sec - $loss_sec);
        $ideal_sec = $total_prod * (float)$ct_sec;
        
        $availability = ($ppt_sec > 0) ? ($operating_sec / $ppt_sec) * 100 : 0;
        $performance = ($operating_sec > 0) ? ($ideal_sec / $operating_sec) * 100 : 0;
        $quality = ($total_prod > 0) ? max(0, ($total_prod - $total_scrap) / $total_prod) * 100 : 0;
        
        if ($quality > 100) $quality = 100;
        $oee = ($availability * $performance * $quality) / 10000;
        
        return [
            'ppt_seconds' => round($ppt_sec),
            'loss_seconds' => round($loss_sec),
            'operating_time_seconds' => round($operating_sec),
            'ideal_operating_seconds' => round($ideal_sec),
            'availability' => round($availability, 1),
            'performance' => round($performance, 1),
            'quality' => round($quality, 1),
            'oee' => round($oee, 1)
        ];
    }
}

if (!function_exists('normalizeDowntimeLabel')) {
    /**
     * Normalisasi kode atau label downtime ke bentuk standar display
     */
    function normalizeDowntimeLabel($kode_or_label) {
        $u = strtoupper(trim($kode_or_label));
        if ($u === 'SB' || $u === 'STAND BY' || $u === 'STANDBY') return 'Stand By';
        if ($u === 'MESIN OFF' || $u === 'OFF') return 'Mesin Off';
        if ($u === '5P' || $u === '5S' || $u === '5P/5S') return '5P/5S';
        if ($u === 'RUNNING' || $u === 'MESIN RUNNING') return 'Running';
        return trim($kode_or_label);
    }
}



if (!function_exists('calculateShiftProductionCount')) {
    /**
     * Single Source of Truth (SSOT) Akumulasi Produksi Shift
     * Menghitung akumulasi delta prodCount secara berurutan.
     * Menangani pergantian part / reset counter hardware (delta < 0)
     * dan proteksi terhadap reconnect dump / lonjakan data offline.
     */
    function calculateShiftProductionCount($conn, $mc_where, $waktu_mulai, $waktu_selesai, $default_ct = 5) {
        $sql_base = "SELECT prodCount FROM log_quality WHERE $mc_where AND timestamp < '$waktu_mulai' ORDER BY timestamp DESC, id DESC LIMIT 1";
        $res_base = $conn->query($sql_base);
        $prev_prodCount = ($res_base && $res_base->num_rows > 0) ? (int)$res_base->fetch_assoc()['prodCount'] : 0;
        
        $sql_logs = "SELECT lq.timestamp, lq.prodCount, mc.ct_pcs 
                     FROM log_quality lq 
                     LEFT JOIN master_ct mc ON lq.kode_proses = mc.kode 
                     WHERE $mc_where AND lq.timestamp >= '$waktu_mulai' AND lq.timestamp <= '$waktu_selesai' 
                     ORDER BY lq.timestamp ASC, lq.id ASC";
        $res_logs = $conn->query($sql_logs);
        
        if (!$res_logs || $res_logs->num_rows == 0) {
            return 0;
        }
        
        $total_prod = 0;
        $prev_log_ts = null;
        
        while ($row = $res_logs->fetch_assoc()) {
            $curr_prodCount = (int)$row['prodCount'];
            $delta = $curr_prodCount - $prev_prodCount;
            $qty_added = 0;
            
            if ($delta > 0) {
                $qty_added = $delta;
            } else if ($delta < 0) {
                // Reset ESP32 atau pergantian model (dandory)
                $qty_added = max(0, $curr_prodCount);
            }
            
            $curr_log_ts = strtotime($row['timestamp']);
            $ct = !empty($row['ct_pcs']) ? (float)$row['ct_pcs'] : (float)$default_ct;
            $phys_cap_hourly = getPhysicalHourlyCapacity($ct);
            
            // Proteksi reconnect dump
            if ($qty_added > 0 && $prev_log_ts !== null) {
                $gap = $curr_log_ts - $prev_log_ts;
                if ($gap > 0) {
                    $proportional_cap = (int)ceil($phys_cap_hourly * $gap / 3600 * 1.20);
                    if ($qty_added > $proportional_cap && $gap > 300) {
                        $qty_added = 0;
                        $prev_prodCount = $curr_prodCount;
                        $prev_log_ts = $curr_log_ts;
                        continue;
                    }
                }
            }
            
            $prev_log_ts = $curr_log_ts;
            $prev_prodCount = $curr_prodCount;
            $total_prod += $qty_added;
        }
        
        return $total_prod;
    }
}

