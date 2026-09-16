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
require_once __DIR__ . '/helper_jadwal.php';

echo "Starting Smart Auto-Reset Process...\n";

// Load global reset settings
$sql_setting = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
$row_setting = ($sql_setting && $sql_setting->num_rows > 0) ? $sql_setting->fetch_assoc() : [];
$jam_reset_s1 = $row_setting['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $row_setting['jam_reset_shift2'] ?? '06:00:00';
$now_time = date('H:i:s');
$today_date = date('Y-m-d');
$current_timestamp = time();

if (!function_exists('getLogicalDay')) {
    function getLogicalDay($time) {
        $now = date('H:i:s', $time);
        $day_num = date('N', $time); 
        if ($now < '06:00:00') {
            $day_num = $day_num - 1;
            if ($day_num == 0) $day_num = 7; 
        }
        if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
        if ($day_num == 5) return 'JUMAT';
        if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
        return 'SENIN-KAMIS';
    }
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
        
        $sql_prod_logs = "
            SELECT 
                SUM(lq.delta_prodCount) as total_prod, 
                MAX(lq.timestamp) as max_ts,
                SUM(lq.delta_prodCount * COALESCE(c.ct_pcs, 0)) as total_ideal_sec
            FROM log_quality lq 
            LEFT JOIN master_ct c ON lq.kode_proses = c.kode
            WHERE lq.mcID = '$mcID' AND lq.id <= $max_q AND lq.timestamp <= '$batas_waktu_str'
        ";
        $res_prod_logs = $conn->query($sql_prod_logs)->fetch_assoc();
        $prod_r = $offset_produksi + (int)($res_prod_logs['total_prod'] ?? 0);
        $total_ideal_sec_r = ($offset_produksi * $ideal_ct) + (float)($res_prod_logs['total_ideal_sec'] ?? 0);
        $waktu_selesai = $res_prod_logs['max_ts'] ?? $batas_waktu_str;
        
        $ng_r = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as qty FROM log_ng WHERE mcID = '$mcID' AND id <= $max_ng AND timestamp <= '$batas_waktu_str'")->fetch_assoc()['qty'] ?? 0;
        
        // Hitung total loss downtime dengan time clipping terhadap jendela shift
        $res_dt_logs = $conn->query("SELECT kode_dt, durasi_detik, timestamp FROM history_downtime WHERE mcID = '$mcID' AND timestamp >= '$waktu_mulai' AND timestamp <= '$batas_waktu_str'");
        $total_loss_sec = 0;
        if ($res_dt_logs && $res_dt_logs->num_rows > 0) {
            $shift_start_ts = strtotime($waktu_mulai);
            $shift_end_ts = $batas_waktu_cap_ts;
            while ($dt_row = $res_dt_logs->fetch_assoc()) {
                $end_ts = strtotime($dt_row['timestamp']);
                $start_ts = $end_ts - (int)$dt_row['durasi_detik'];
                $clipped = clipIntervalToShift($start_ts, $end_ts, $shift_start_ts, $shift_end_ts);
                $total_loss_sec += $clipped;
            }
        }
        
        // Formula OEE Standar Internasional ISO 22400-2
        $std_oee = calculateStandardOEE($ppt_seconds, $total_loss_sec, $prod_r, $ng_r, $ideal_ct);
        $a_r = $std_oee['availability'];
        $p_r = $std_oee['performance'];
        $q_r = $std_oee['quality'];
        $oee_r = $std_oee['oee'];
        
        $conn->query("INSERT INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset) 
                      VALUES ('$tanggal_history', '$shift_label', '$mcID', '$nama_m', '$part_m', '$prod_r', '$ng_r', '$oee_r', '$a_r', '$p_r', '$q_r', '$waktu_mulai', '$waktu_selesai', NOW())
                      ON DUPLICATE KEY UPDATE 
                      nama_mesin = VALUES(nama_mesin), part_name = VALUES(part_name), total_ok = VALUES(total_ok), total_ng = VALUES(total_ng),
                      oee = VALUES(oee), availability = VALUES(availability), performance = VALUES(performance), quality = VALUES(quality),
                      waktu_mulai = VALUES(waktu_mulai), waktu_selesai = VALUES(waktu_selesai), waktu_reset = NOW()");

        // 1. Simpan offset produksi terakhir SEBELUM data dihapus (PENTING: Mencegah Counter Freeze!)
        $last_row = $conn->query("SELECT prodCount, raw_prodCount FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $last_raw = $last_row['raw_prodCount'] ?? 0;
        $conn->query("UPDATE master_mesin SET catStatus = 'OFF', offset_produksi = '0', offset_raw_produksi = '$last_raw' WHERE mcID = '$mcID' OR id_mesin = '$mcID'");

        // 2. Hapus HANYA data mesin ini dari tabel berjalan
        if ($max_q > 0) $conn->query("DELETE FROM log_quality WHERE mcID='$mcID' AND id <= $max_q");
        if ($max_ng > 0) $conn->query("DELETE FROM log_ng WHERE mcID='$mcID' AND id <= $max_ng");
        if ($max_dt > 0) $conn->query("DELETE FROM log_downtime WHERE mcID='$mcID' AND id <= $max_dt");
        
        // Hapus Override
        $conn->query("DELETE FROM mesin_override WHERE mcID='$mcID'");

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
        $sql_slots = "SELECT rentang_jam, menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift_label' AND (hari = '$hari_history' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
        $res_slots = $conn->query($sql_slots);
        
        $scheduled_end_jam = '';
        $slots_data = [];
        if ($res_slots && $res_slots->num_rows > 0) {
            while($r_slot = $res_slots->fetch_assoc()) {
                $slots_data[] = $r_slot;
                $parts = explode('-', $r_slot['rentang_jam']);
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
        $override_start_ts = 0;
        $res_ov = $conn->query("SELECT jam_mulai, jam_selesai FROM mesin_override WHERE mcID = '$mcID' AND (tanggal = '$tanggal_history' OR tanggal = '$tanggal_mulai') ORDER BY id DESC LIMIT 1");
        if ($res_ov && $res_ov->num_rows > 0) {
            $ov = $res_ov->fetch_assoc();
            $override_end_ts = strtotime($tanggal_history . ' ' . $ov['jam_selesai']);
            $override_start_ts = strtotime($tanggal_history . ' ' . $ov['jam_mulai']);
            if ($shift_label == 'SHIFT 2') {
                if ($ov['jam_mulai'] < '12:00:00') {
                    $override_start_ts = strtotime('+1 day', $override_start_ts);
                }
                if ($ov['jam_selesai'] < '12:00:00' || $ov['jam_mulai'] > $ov['jam_selesai']) {
                    $override_end_ts = strtotime('+1 day', $override_end_ts);
                }
            } else {
                if ($ov['jam_mulai'] > $ov['jam_selesai']) {
                    $override_end_ts = strtotime('+1 day', $override_end_ts);
                }
            }
            echo "  [INFO] Override Lembur Ditemukan: " . date('Y-m-d H:i', $override_start_ts) . " s/d " . date('Y-m-d H:i', $override_end_ts) . "\n";
        }

        // Cek Aktivitas Terakhir (Untuk Lembur Siluman / Auto-Detect)
        $res_active = $conn->query("SELECT MAX(timestamp) as last_active FROM log_quality WHERE mcID = '$mcID' AND id <= $max_q AND (mcStatus = 'Mesin Running' OR delta_prodCount > 0)");
        $last_active = $res_active->fetch_assoc()['last_active'];
        $last_active_ts = $last_active ? strtotime($last_active) : strtotime($waktu_mulai);
        
        // Kalkulasi Waktu End Dinamis (Yang Terbesar Diantara Jadwal / Override / Aktivitas)
        $dynamic_end_ts = max($scheduled_end_ts, $override_end_ts, $last_active_ts);

        // TASK 3: HARD CAP dynamic_end_ts to next shift reset time
        $hard_cap_ts = 0;
        if ($shift_label == 'SHIFT 2') {
            $hard_cap_ts = strtotime(date('Y-m-d', strtotime('+1 day', strtotime($tanggal_history))) . ' ' . $jam_reset_s2);
        } else {
            $hard_cap_ts = strtotime($tanggal_mulai . ' ' . $jam_reset_s1);
            if ($hard_cap_ts < strtotime($waktu_mulai)) {
                // Failsafe if jam_reset_s1 < jam_mulai but still considered SHIFT 1
                $hard_cap_ts = strtotime('+1 day', $hard_cap_ts);
            }
        }
        if ($dynamic_end_ts > $hard_cap_ts) {
            $dynamic_end_ts = $hard_cap_ts;
            echo "  [INFO] HARD CAP Applied. End dinamis dibatasi sampai " . date('Y-m-d H:i:s', $hard_cap_ts) . "\n";
        }

        // TASK 2: Hitung PPT berdasarkan Irisan dengan dynamic_end_ts
        $ppt_seconds = 0;
        $machine_start_ts = strtotime($waktu_mulai);
        
        foreach ($slots_data as $r_slot) {
            $parts = explode('-', $r_slot['rentang_jam']);
            if (count($parts) == 2) {
                $s_jam = trim($parts[0]);
                $e_jam = trim($parts[1]);
                $slot_s_ts = strtotime($tanggal_mulai . ' ' . $s_jam);
                $slot_e_ts = strtotime($tanggal_mulai . ' ' . $e_jam);
                
                // Koreksi Lintas Malam Shift 2
                if ($shift_label == 'SHIFT 2') {
                    if ($s_jam < '12:00:00' && $jam_mulai >= '12:00:00') $slot_s_ts = strtotime('+1 day', $slot_s_ts);
                    if ($e_jam < '12:00:00' && $jam_mulai >= '12:00:00') $slot_e_ts = strtotime('+1 day', $slot_e_ts);
                }
                if ($slot_e_ts < $slot_s_ts) {
                    $slot_e_ts = strtotime('+1 day', $slot_e_ts);
                }

                $effective_start = max($slot_s_ts, $machine_start_ts);
                $effective_end = min($slot_e_ts, $dynamic_end_ts);
                
                if ($effective_start < $effective_end) {
                    $slot_duration = $slot_e_ts - $slot_s_ts;
                    $actual_duration = $effective_end - $effective_start;
                    if ($slot_duration > 0) {
                        $rasio = $actual_duration / $slot_duration;
                        $ppt_seconds += ($r_slot['menit_efektif'] * 60) * $rasio;
                    }
                }
            }
        }

        // Tambahkan lembur jika ada irisan
        if ($override_end_ts > 0 && $override_end_ts > $override_start_ts) {
            $effective_l_start = max($override_start_ts, $machine_start_ts);
            $effective_l_end = min($override_end_ts, $dynamic_end_ts);
            if ($effective_l_start < $effective_l_end) {
                $ppt_seconds += ($effective_l_end - $effective_l_start);
            }
        }

        // Lembur Siluman Otomatis
        if ($override_end_ts == 0 && $last_active_ts > $scheduled_end_ts) {
            $effective_s_start = max($scheduled_end_ts, $machine_start_ts);
            $effective_s_end = min($last_active_ts, $dynamic_end_ts);
            if ($effective_s_start < $effective_s_end) {
                $extra_seconds = $effective_s_end - $effective_s_start;
                $ppt_seconds += $extra_seconds;
                echo "  [INFO] Lembur Siluman Otomatis Terdeteksi: +" . round($extra_seconds/60) . " Menit\n";
            }
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
