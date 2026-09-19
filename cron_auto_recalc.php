<?php
/**
 * cron_auto_recalc.php
 * 
 * Otomatis mendeteksi dan memperbaiki history_summary yang OEE-nya 0 atau NULL
 * padahal ada data produksi di history_quality.
 * 
 * Dijalankan oleh Node-RED atau Task Scheduler setiap 30 menit.
 * 
 * Logika:
 * 1. Cari history_summary WHERE (oee = 0 OR oee IS NULL) AND total_ok > 0
 * 2. Untuk setiap record, recalculate OEE dari data mentah
 * 3. Update history_summary dengan nilai yang benar
 */

header("Content-Type: text/plain; charset=UTF-8");
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$conn->query("SET time_zone = '+07:00'");

require_once __DIR__ . '/helper_jadwal.php';

// Baca jam reset dari setting_pabrik
$sql_sp = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
$sp = ($sql_sp && $sql_sp->num_rows > 0) ? $sql_sp->fetch_assoc() : [];
$jam_reset_s1 = $sp['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $sp['jam_reset_shift2'] ?? '06:00:00';

echo "=== CRON AUTO RECALC ===\n";
echo "Waktu: " . date('Y-m-d H:i:s') . "\n";
echo "Jam Reset S1: $jam_reset_s1, S2: $jam_reset_s2\n\n";

// Cari history_summary yang perlu diperbaiki
$sql_broken = "SELECT hs.id, hs.mcID, hs.tanggal, hs.shift, hs.total_ok, hs.total_ng, 
                      hs.waktu_mulai, hs.waktu_selesai, hs.downtime, hs.oee,
                      mm.nama_mesin
               FROM history_summary hs
               LEFT JOIN master_mesin mm ON (hs.mcID = mm.id_mesin OR hs.mcID = mm.mcID)
               WHERE (hs.oee = 0 OR hs.oee IS NULL) 
                     AND (hs.total_ok > 0 OR hs.total_ng > 0)
               ORDER BY hs.tanggal DESC, hs.id DESC
               LIMIT 50";

$res_broken = $conn->query($sql_broken);
if (!$res_broken || $res_broken->num_rows == 0) {
    echo "Tidak ada history yang perlu diperbaiki.\n";
    exit;
}

$fixed = 0;
$skipped = 0;

while ($row = $res_broken->fetch_assoc()) {
    $hs_id = $row['id'];
    $mcID = $row['mcID'];
    $tanggal = $row['tanggal'];
    $shift_label = $row['shift'];
    $total_ok = (int)$row['total_ok'];
    $total_ng = (int)$row['total_ng'];
    $waktu_mulai = $row['waktu_mulai'];
    $waktu_selesai = $row['waktu_selesai'];
    
    echo "Fixing: ID=$hs_id, mcID=$mcID, tgl=$tanggal, shift=$shift_label\n";
    
    // Fallback waktu mulai/selesai dari setting
    if (empty($waktu_mulai) || empty($waktu_selesai)) {
        if ($shift_label == 'SHIFT 1') {
            $waktu_mulai = "$tanggal $jam_reset_s2";
            $waktu_selesai = "$tanggal $jam_reset_s1";
        } else {
            $tgl_next = date('Y-m-d', strtotime("$tanggal +1 day"));
            $waktu_mulai = "$tanggal $jam_reset_s1";
            $waktu_selesai = "$tgl_next $jam_reset_s2";
        }
    }
    
    // Resolve dual ID
    $mcID_esc = $conn->real_escape_string($mcID);
    $res_mm = $conn->query("SELECT mcID, id_mesin FROM master_mesin WHERE mcID = '$mcID_esc' OR id_mesin = '$mcID_esc' LIMIT 1");
    $ids = [$mcID];
    if ($res_mm && $res_mm->num_rows > 0) {
        $mm = $res_mm->fetch_assoc();
        if (!empty($mm['mcID'])) $ids[] = $mm['mcID'];
        if (!empty($mm['id_mesin'])) $ids[] = $mm['id_mesin'];
    }
    $ids = array_unique(array_filter($ids));
    $in_ids = "'" . implode("','", array_map([$conn, 'real_escape_string'], $ids)) . "'";
    
    // Resolve template per-shift
    $line_q = $conn->query("SELECT hq.kode_proses, c.line FROM history_quality hq LEFT JOIN master_ct c ON hq.kode_proses = c.kode WHERE hq.mcID IN ($in_ids) AND hq.timestamp >= '$waktu_mulai' AND hq.timestamp <= '$waktu_selesai' AND c.line IS NOT NULL LIMIT 1");
    $line_m = ($line_q && $line_q->num_rows > 0) ? $line_q->fetch_assoc()['line'] : '';
    
    $template_aktif = 'DEFAULT';
    if (!empty($line_m)) {
        $line_m_esc = $conn->real_escape_string($line_m);
        $res_tpl = $conn->query("SELECT nama_template, nama_template_shift1, nama_template_shift2 FROM master_line WHERE nama_line = '$line_m_esc' LIMIT 1");
        if ($res_tpl && $res_tpl->num_rows > 0) {
            $tpl_row = $res_tpl->fetch_assoc();
            if ($shift_label == 'SHIFT 1' && !empty($tpl_row['nama_template_shift1'])) {
                $template_aktif = $tpl_row['nama_template_shift1'];
            } elseif ($shift_label == 'SHIFT 2' && !empty($tpl_row['nama_template_shift2'])) {
                $template_aktif = $tpl_row['nama_template_shift2'];
            } else {
                $template_aktif = $tpl_row['nama_template'] ?? 'DEFAULT';
            }
        }
    }
    
    // Resolve ideal CT
    $ct_q = $conn->query("SELECT c.ct_pcs FROM history_quality hq LEFT JOIN master_ct c ON hq.kode_proses = c.kode WHERE hq.mcID IN ($in_ids) AND hq.timestamp >= '$waktu_mulai' AND hq.timestamp <= '$waktu_selesai' AND c.ct_pcs > 0 ORDER BY hq.id DESC LIMIT 1");
    $ideal_ct = ($ct_q && $ct_q->num_rows > 0) ? (float)$ct_q->fetch_assoc()['ct_pcs'] : 0;
    
    if ($ideal_ct <= 0) {
        echo "  SKIP: ideal_ct=0, tidak bisa hitung OEE\n";
        $skipped++;
        continue;
    }
    
    // Resolve hari aktif
    $hari_aktif = getLogicalDay(strtotime($waktu_mulai));
    
    // Hitung PPT
    $shift_end_ts = strtotime($waktu_selesai);
    $ppt_seconds = calculateShiftPPTSeconds($conn, $template_aktif, $shift_label, $hari_aktif, $waktu_mulai, $shift_end_ts);
    if ($ppt_seconds <= 0) $ppt_seconds = 8 * 3600;
    
    // Hitung Downtime Historis
    $historical_real_dt = 0;
    $tbl_dt = 'history_downtime';
    $chk = $conn->query("SELECT id FROM history_downtime WHERE mcID IN ($in_ids) AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai' LIMIT 1");
    if (!$chk || $chk->num_rows == 0) $tbl_dt = 'log_downtime';
    
    $res_dt = $conn->query("SELECT durasi_detik, timestamp FROM $tbl_dt WHERE mcID IN ($in_ids) AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'");
    if ($res_dt && $res_dt->num_rows > 0) {
        $shift_start_ts = strtotime($waktu_mulai);
        while ($dt = $res_dt->fetch_assoc()) {
            $end_ts = strtotime($dt['timestamp']);
            $start_ts = $end_ts - (int)$dt['durasi_detik'];
            $clipped = clipIntervalToShift($start_ts, $end_ts, $shift_start_ts, $shift_end_ts);
            if ($clipped > 0) $historical_real_dt += $clipped;
        }
    }
    
    // Pre-production losstime
    $pre_prod_loss_sec = 0;
    $tbl_q = 'history_quality';
    $chk_hq = $conn->query("SELECT id FROM history_quality WHERE mcID IN ($in_ids) AND timestamp >= '$waktu_mulai' LIMIT 1");
    if (!$chk_hq || $chk_hq->num_rows == 0) $tbl_q = 'log_quality';
    
    $res_first = $conn->query("SELECT MIN(timestamp) as first_ts FROM $tbl_q WHERE mcID IN ($in_ids) AND timestamp >= '$waktu_mulai' AND timestamp <= '$waktu_selesai'");
    if ($res_first && $res_first->num_rows > 0) {
        $fd = $res_first->fetch_assoc();
        if (!empty($fd['first_ts'])) {
            $first_data_ts = strtotime($fd['first_ts']);
            $work_start_ts = strtotime($waktu_mulai);
            if ($first_data_ts > $work_start_ts) {
                $pre_prod_loss_sec = $first_data_ts - $work_start_ts;
                if ($pre_prod_loss_sec > 7200) $pre_prod_loss_sec = 0;
            }
        }
    }
    
    $total_loss = $historical_real_dt + $pre_prod_loss_sec;
    $prodCount = $total_ok + $total_ng;
    
    // Kalkulasi OEE Standar
    $stdOEE = calculateStandardOEE($ppt_seconds, $total_loss, $prodCount, $total_ng, $ideal_ct);
    
    $new_availability = $stdOEE['availability'];
    $new_performance = $stdOEE['performance'];
    $new_quality = $stdOEE['quality'];
    $new_oee = $stdOEE['oee'];
    
    // Update history_summary
    $sql_update = "UPDATE history_summary SET 
                    availability = $new_availability, 
                    performance = $new_performance, 
                    quality = $new_quality, 
                    oee = $new_oee, 
                    downtime = $total_loss
                   WHERE id = $hs_id";
    
    if ($conn->query($sql_update)) {
        echo "  OK: OEE={$new_oee}% (A={$new_availability}%, P={$new_performance}%, Q={$new_quality}%), DT={$total_loss}s\n";
        $fixed++;
    } else {
        echo "  ERROR: " . $conn->error . "\n";
    }
}

echo "\n=== SELESAI ===\n";
echo "Fixed: $fixed | Skipped: $skipped | Total: " . ($fixed + $skipped) . "\n";
$conn->close();
?>
