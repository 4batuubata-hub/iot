<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

$mcID = isset($_GET['mcID']) ? $conn->real_escape_string($_GET['mcID']) : '';
$tanggal = isset($_GET['tanggal']) ? $conn->real_escape_string($_GET['tanggal']) : '';
$filterShift = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : '';
if(empty($mcID) || empty($tanggal)) die("<h2 style='color:white; text-align:center;'>Data History tidak valid!</h2>");

// 1. Ambil info dari summary history (dengan filter shift jika ada)
$sql_summary = "SELECT hs.*, mm.nama_mesin, mm.mcID as string_mcID FROM history_summary hs LEFT JOIN master_mesin mm ON (hs.mcID = mm.id_mesin OR hs.mcID = mm.mcID) WHERE hs.mcID = '$mcID' AND hs.tanggal = '$tanggal'";
if (!empty($filterShift)) { $sql_summary .= " AND hs.shift = '$filterShift'"; }
$sql_summary .= " LIMIT 1";
$res_summary = $conn->query($sql_summary);
$summary = ($res_summary && $res_summary->num_rows > 0) ? $res_summary->fetch_assoc() : [];

if (empty($summary)) die("<h2 style='color:white; text-align:center;'>Data History tidak ditemukan untuk mesin ini.</h2>");

// 2. Ambil detail operasional terakhir dari history (Part, CT, Proses, Operator)
$filter_waktu_hq = "";
$filter_waktu_hn = "";
$filter_waktu_hd = "";

if (empty($filterShift) || $filterShift == 'REKAP HARIAN' || $filterShift == 'ALL') {
    $filter_waktu_hq = "DATE(hq.timestamp) = '$tanggal'";
    $filter_waktu_hn = "DATE(hn.timestamp) = '$tanggal'";
    $filter_waktu_hd = "DATE(hd.timestamp) = '$tanggal'";
} else {
    $waktu_mulai = $summary['waktu_mulai'] ?? ($tanggal . ' 00:00:00');
    $waktu_selesai = $summary['waktu_selesai'] ?? ($tanggal . ' 23:59:59');
    $filter_waktu_hq = "hq.timestamp >= '$waktu_mulai' AND hq.timestamp <= '$waktu_selesai'";
    $filter_waktu_hn = "hn.timestamp >= '$waktu_mulai' AND hn.timestamp <= '$waktu_selesai'";
    $filter_waktu_hd = "hd.timestamp >= '$waktu_mulai' AND hd.timestamp <= '$waktu_selesai'";
}

$sql_last_log = "SELECT hq.*, mc.part_name, mc.part_number, mc.proses_name, mc.ct_pcs, mc.ct_jam, mc.line, mo.nama as nama_operator, mo.nik
                 FROM history_quality hq
                 LEFT JOIN master_ct mc ON hq.kode_proses = mc.kode
                 LEFT JOIN master_operator mo ON hq.op_NIK = mo.nik
                 WHERE hq.mcID = '$mcID' AND $filter_waktu_hq
                 ORDER BY hq.id DESC LIMIT 1";

$res_last_log = $conn->query($sql_last_log);
$last_log = ($res_last_log && $res_last_log->num_rows > 0) ? $res_last_log->fetch_assoc() : [];

$namaMesin = $summary['nama_mesin'] ?? $mcID; 
$partName = $last_log['part_name'] ?? ($summary['part_name'] ?? 'Data Part Terhapus');
$partNumber = $last_log['part_number'] ?? '-';
$prosesName = $last_log['proses_name'] ?? 'PROSES 1'; 
$ctPcs = $last_log['ct_pcs'] ?? 0; 
$targetPerJam = isset($last_log['ct_jam']) && $last_log['ct_jam'] > 0 ? round($last_log['ct_jam']) : 0;
$operatorName = $last_log['nama_operator'] ?? ($last_log['op_NIK'] ?? 'Belum Login'); 
$nikOP = $last_log['nik'] ?? ($last_log['op_NIK'] ?? 'default');
$shift_aktif = $summary['shift'] ?? 'REKAP HARIAN';
$lineMesin = $last_log['line'] ?? 'N/A';
$stringMcID = $summary['string_mcID'] ?? $mcID;

// AMBIL SKILL MATRIX OPERATOR DARI DATABASE
$opSkillLevel = 1;
$opFoto = '';
$target_mcID = $summary['string_mcID'] ?? $mcID;
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
$opSkillLabel = $opSkillInfo['label'];
$opSkillColor = $opSkillInfo['color'];

$statusTeks = "ARSIP " . date('d M Y', strtotime($tanggal)); 
$statusWarna = "#1e3a8a"; // Warna Biru Penanda History

$totalOK = $summary['total_ok'] ?? 0;
$totalNG = $summary['total_ng'] ?? 0;
$totalRepair = 0; 
$totalScrap = $totalNG - $totalRepair;

// Safe query for losstime
$res_losstime = $conn->query("SELECT COALESCE(SUM(hd.durasi_detik), 0) as total_sec FROM history_downtime hd WHERE hd.mcID = '$mcID' AND $filter_waktu_hd");
$totalLosstimeMenit = ($res_losstime && $res_losstime->num_rows > 0) ? round(($res_losstime->fetch_assoc()['total_sec'] ?? 0) / 60) : 0;

$res_ng_summary = $conn->query("SELECT md.keterangan as nama_defect, COALESCE(SUM(hn.qty_ng), 0) as qty FROM history_ng hn LEFT JOIN master_defect md ON hn.kode_ng = md.kode_defect WHERE hn.mcID = '$mcID' AND $filter_waktu_hn GROUP BY hn.kode_ng, md.keterangan");
// Operator Names mapping for dynamic history
$op_names = [];
$res_op = $conn->query("SELECT nik, nama FROM master_operator");
if($res_op) while($op = $res_op->fetch_assoc()) $op_names[$op['nik']] = $op['nama'];

// Operator history data will be built during log processing below (baris 221+)
$opHistoryData = [];

// Tentukan Template Jam
$template_aktif = 'DEFAULT';
$hari_aktif = 'SENIN-KAMIS';
if ($shift_aktif != 'REKAP HARIAN' && $shift_aktif != 'ALL') {
    $lineMesinEsc = $conn->real_escape_string($lineMesin);
    $res_tpl = $conn->query("SELECT nama_template_shift1, nama_template_shift2, nama_template FROM master_line WHERE nama_line = '$lineMesinEsc' LIMIT 1");
    if ($res_tpl && $res_tpl->num_rows > 0) {
        $row_tpl = $res_tpl->fetch_assoc();
        if ($shift_aktif == 'SHIFT 1') $template_aktif = $row_tpl['nama_template_shift1'] ?? $row_tpl['nama_template'] ?? 'DEFAULT';
        elseif ($shift_aktif == 'SHIFT 2') $template_aktif = $row_tpl['nama_template_shift2'] ?? $row_tpl['nama_template'] ?? 'DEFAULT';
        else $template_aktif = $row_tpl['nama_template'] ?? 'DEFAULT';
    }
    $dayOfWeek = date('w', strtotime($tanggal));
    $hari_aktif = ($dayOfWeek == 5) ? 'JUMAT' : (($dayOfWeek == 6) ? 'SABTU' : (($dayOfWeek == 0) ? 'MINGGU' : 'SENIN-KAMIS'));
}

$jamAktif = []; $hourlyActualSum = []; $hourlyTargetSum = [];

if ($shift_aktif == 'REKAP HARIAN' || $shift_aktif == 'ALL') {
    // Buat slot 24 Jam 
    for($i = 0; $i < 24; $i++) {
        $start = str_pad($i, 2, '0', STR_PAD_LEFT).":00";
        $end = str_pad($i+1, 2, '0', STR_PAD_LEFT).":00";
        if($i == 23) $end = "23:59";
        $jamAktif[] = "$start - $end"; 
    }
} else {
    // Ambil dari master_jam_statis
    $template_esc = $conn->real_escape_string($template_aktif);
    $shift_esc = $conn->real_escape_string($shift_aktif);
    $hari_esc = $conn->real_escape_string($hari_aktif);
    $sql_jam_statis = "SELECT rentang_jam FROM master_jam_statis WHERE nama_template = '$template_esc' AND shift = '$shift_esc' AND (hari = '$hari_esc' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
    $res_jam_statis = $conn->query($sql_jam_statis);
    if ($res_jam_statis && $res_jam_statis->num_rows > 0) {
        while($rowJ = $res_jam_statis->fetch_assoc()) { 
            $jamAktif[] = $rowJ['rentang_jam']; 
        }
    } else {
        // Fallback jika tidak ada template
        for($i = 0; $i < 24; $i++) {
            $start = str_pad($i, 2, '0', STR_PAD_LEFT).":00";
            $end = str_pad($i+1, 2, '0', STR_PAD_LEFT).":00";
            if($i == 23) $end = "23:59";
            $jamAktif[] = "$start - $end"; 
        }
    }
}

foreach($jamAktif as $jam) {
    $hourlyActualSum[$jam] = 0; 
    $hourlyTargetSum[$jam] = 0;
}

// Helper function to check if a time falls within a range
if (!function_exists('isTimeInRange')) {
    function isTimeInRange($time, $range) {
        $p = explode('-', $range);
        if(count($p) !== 2) return false;
        $start = trim($p[0]) . ":00";
        $end = trim($p[1]) . ":00";
        if ($start <= $end) { return ($time >= $start && $time <= $end); } 
        else { return ($time >= $start || $time <= $end); }
    }
}

// 1. QUERY RAW DATA DARI HISTORY_QUALITY (TANPA GROUP BY HOUR)
$sql_logs = "SELECT hq.timestamp, hq.prodCount, hq.op_NIK, c.part_name, c.part_number, c.proses_name, c.ct_pcs, c.ct_jam 
             FROM history_quality hq 
             LEFT JOIN master_ct c ON hq.kode_proses = c.kode 
             WHERE hq.mcID = '$mcID' AND $filter_waktu_hq 
             ORDER BY hq.timestamp ASC, hq.id ASC";
// echo "<div style='background:#111; padding:10px; margin:10px; border:1px solid #0f0; color:cyan;'><b>DEBUG sql_logs (Dekidaka RAW DATA):</b><br>" . $sql_logs . "</div>";
$res_logs = $conn->query($sql_logs);

$tableData = []; 
$buckets = [];
$opHistoryData = [];

$true_total_prod = 0;
$prev_prodCount = 0; // Asumsi awal offset 0 untuk menemukan akumulasi total murni

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

        // Operator History Tracking
        $op_nik = trim($row['op_NIK']);
        if (!empty($op_nik)) {
            if (!isset($opHistoryData[$op_nik])) {
                $opHistoryData[$op_nik] = [
                    'op_NIK' => $op_nik,
                    'nama' => $op_names[$op_nik] ?? $op_nik,
                    'jam_mulai' => $logTime,
                    'jam_selesai' => $logTime,
                    'total_qty' => 0
                ];
            }
            $opHistoryData[$op_nik]['jam_selesai'] = $logTime;
            $opHistoryData[$op_nik]['total_qty'] += $qty_added;
        }

        // Bucketing
        $matched_jam = null;
        foreach($jamAktif as $jam) {
            if (isTimeInRange($logTime, $jam)) {
                $matched_jam = $jam;
                break;
            }
        }
        
        // JIKA JAM LEMBUR / DILUAR TEMPLATE, TAMBAHKAN SLOT BARU SECARA BERURUTAN
        if (!$matched_jam) {
            $last_end = null;
            if (!empty($jamAktif)) {
                $last_bucket = end($jamAktif);
                $p = explode('-', $last_bucket);
                if (count($p) == 2) $last_end = trim($p[1]);
            }
            
            if ($last_end) {
                $s_time = strtotime($last_end . ":00");
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
                }
            }
        }
        
        $pn = !empty($row['part_number']) ? $row['part_number'] : '-'; 
        $pName = !empty($row['part_name']) ? $row['part_name'] : 'Unknown'; 
        $proses = !empty($row['proses_name']) ? $row['proses_name'] : 'Proses 1';
        $ct = !empty($row['ct_pcs']) ? $row['ct_pcs'] : 0; 
        $target = !empty($row['ct_jam']) ? round($row['ct_jam']) : 0; 
        
        $key = $pn . '|' . $proses;

        if ($matched_jam) {
            if (!isset($buckets[$matched_jam][$key])) {
                $buckets[$matched_jam][$key] = [
                    'qty' => 0,
                    'part_name' => $pName, 'part_number' => $pn, 'proses' => $proses, 'ct' => $ct, 'target' => $target
                ];
            }
            $buckets[$matched_jam][$key]['qty'] += $qty_added;
        }
    }
}

// 2. COMPILE BUCKETS KE DALAM TABLE DATA DAN ADJUST OFFSET
// Calculate historical offset so dekidaka matches total summary
$historical_total_prod = ($summary['total_ok'] ?? 0) + ($summary['total_ng'] ?? 0);
$historical_offset = $true_total_prod - $historical_total_prod;
if ($historical_offset < 0) $historical_offset = 0;

// Subtract the offset from the first available bucket
// Subtract the offset from the first available bucket
if ($historical_offset > 0) {
    $remaining_offset = $historical_offset; // Simpan nilai original sebelum dikurangi
    foreach ($jamAktif as $jamStr) {
        if (isset($buckets[$jamStr])) {
            foreach ($buckets[$jamStr] as $key => &$data) {
                if ($data['qty'] >= $remaining_offset) {
                    $data['qty'] -= $remaining_offset;
                    $remaining_offset = 0;
                    break 2;
                } else {
                    $remaining_offset -= $data['qty'];
                    $data['qty'] = 0;
                }
            }
        }
    }
    
    // Kurangi offset dari operator history data (pertama kali)
    if ($historical_offset > 0 && !empty($opHistoryData)) {
        $first_key = array_key_first($opHistoryData);
        if ($first_key !== null) {
            $opHistoryData[$first_key]['total_qty'] -= $historical_offset;
            if ($opHistoryData[$first_key]['total_qty'] < 0) $opHistoryData[$first_key]['total_qty'] = 0;
        }
    }
}

// Build tableData
foreach ($jamAktif as $jamStr) {
    if (isset($buckets[$jamStr])) {
        foreach ($buckets[$jamStr] as $key => &$data) {
            $qty = $data['qty'];
            
            $pn = $data['part_number'];
            $pName = $data['part_name'];
            $proses = $data['proses'];
            
            if (!isset($tableData[$pn])) $tableData[$pn] = ['name' => $pName, 'proses' => []];
            if (!isset($tableData[$pn]['proses'][$proses])) $tableData[$pn]['proses'][$proses] = ['ct' => $data['ct'], 'data_jam' => []];
            
            if (!isset($tableData[$pn]['proses'][$proses]['data_jam'][$jamStr])) {
                $tableData[$pn]['proses'][$proses]['data_jam'][$jamStr] = 0;
            }
            $tableData[$pn]['proses'][$proses]['data_jam'][$jamStr] += $qty;
            
            $hourlyActualSum[$jamStr] += $qty;
            if ($data['target'] > $hourlyTargetSum[$jamStr]) $hourlyTargetSum[$jamStr] = $data['target'];
        }
    }
}

// Set default 'NO DATA' for empty slots
foreach($tableData as $pn => &$partData) {
    foreach($partData['proses'] as $pName => &$pData) {
        foreach($jamAktif as $jamStr) {
            if(!isset($pData['data_jam'][$jamStr])) {
                $pData['data_jam'][$jamStr] = 'NO DATA';
            }
        }
    }
}

// Bersihkan kolom jam yang seharian kosong
foreach($hourlyActualSum as $k => $v) { 
    $hasData = false;
    if (!empty($tableData)) {
        foreach($tableData as $pn => $partData) {
            foreach($partData['proses'] as $pName => $pData) {
                if(isset($pData['data_jam'][$k]) && $pData['data_jam'][$k] !== 'NO DATA') {
                    $hasData = true; break 2;
                }
            }
        }
    }
    if($v == 0 && !$hasData) { 
        unset($hourlyActualSum[$k]); 
        unset($hourlyTargetSum[$k]); 
    } 
}
$jamAktifFinal = array_keys($hourlyActualSum);

// Hitung jumlah kolom untuk DataTables (KRITIS: harus konsisten antara thead dan tbody)
$totalColumns = count($jamAktifFinal) + 4; // Part Name + Part Number + Proses (CT) + [jam columns] + Total

$res_pareto = $conn->query("SELECT CASE WHEN hd.kode_dt = 'SB' THEN 'Stand By' WHEN hd.kode_dt = 'Mesin Off' THEN 'Mesin Off' ELSE COALESCE(md.label_dt, hd.kode_dt) END as label_downtime, SUM(hd.durasi_detik) as total_detik FROM history_downtime hd LEFT JOIN master_downtime md ON hd.kode_dt = md.kode_dt WHERE hd.mcID = '$mcID' AND $filter_waktu_hd GROUP BY label_downtime ORDER BY total_detik DESC");
$paretoLabels = []; $paretoValues = [];
if ($res_pareto && $res_pareto->num_rows > 0) { while($pRow = $res_pareto->fetch_assoc()) { $paretoLabels[] = $pRow['label_downtime']; $paretoValues[] = (int)$pRow['total_detik']; } }

// OEE data from summary
$oeeVal = number_format($summary['oee'] ?? 0, 1);
$availVal = number_format($summary['availability'] ?? 0, 1);
$perfVal = number_format($summary['performance'] ?? 0, 1);
$qualVal = number_format($summary['quality'] ?? 0, 1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arsip OEE - <?= htmlspecialchars($mcID) ?></title>
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
        
        .card { background: var(--card-bg); border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 20px; overflow: hidden; width: 100%;}
        .card-header { background: #252525; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px solid var(--border-color); }
        .card-title { margin: 0; font-size: 15px; font-weight: bold; color: #ffffff; }
        .card-toggle { font-size: 18px; color: var(--text-muted); }
        .card-body { padding: 15px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; align-items: start; }
        .info-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; align-items: start; }
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

        .mesin-info-item { text-align: center; padding: 12px; }
        .mesin-info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: bold; margin-bottom: 6px; }
        .mesin-info-value { font-size: 16px; font-weight: bold; color: #ffffff; }

        @media (max-width: 992px) { .info-grid, .info-grid-3, .summary-metrics, .pareto-dekidaka-grid { grid-template-columns: 1fr; } }
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
            <a href="<?= BASE_URL ?>user/index.php" class="btn-back">← Kembali ke History</a>
        </div>
        <div style="text-align:center; flex-grow:1;">
            <h2 style="margin:0;">📜 ARSIP: <?= htmlspecialchars($mcID) ?> 
                <span style="color:#00bfa5;">(<?= htmlspecialchars($shift_aktif) ?>)</span>
            </h2>
        </div>
        <div style="text-align:center;">
            <div style="background: <?= $statusWarna ?>; color: #fff; padding: 8px 15px; border-radius: 20px; font-weight: bold; display: inline-block;">
                STATUS: <?= $statusTeks ?>
            </div>
            <div id="clockWIB" style="margin-top: 8px; font-size: 14px; font-weight: bold; color: #a0a0a0; letter-spacing: 1.5px;">--:--:-- WIB</div>
        </div>
    </div>

    <!-- DETAIL MESIN & SHIFT INFO (NEW) -->
    <div class="card">
        <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">🖥️ Detail Mesin & Shift</h3><span class="card-toggle">−</span></div>
        <div class="card-body">
            <div style="display: flex; flex-wrap: wrap; gap: 0; justify-content: space-around; background: #252525; border-radius: 8px; padding: 10px 0; border: 1px solid #444;">
                <div class="mesin-info-item">
                    <div class="mesin-info-label">Nama Mesin</div>
                    <div class="mesin-info-value"><?= htmlspecialchars($namaMesin) ?></div>
                </div>
                <div class="mesin-info-item" style="border-left: 1px solid #444; border-right: 1px solid #444;">
                    <div class="mesin-info-label">Machine ID</div>
                    <div class="mesin-info-value" style="color: #00bfa5;"><?= htmlspecialchars($stringMcID) ?></div>
                </div>
                <div class="mesin-info-item">
                    <div class="mesin-info-label">Line</div>
                    <div class="mesin-info-value"><?= htmlspecialchars($lineMesin) ?></div>
                </div>
                <div class="mesin-info-item" style="border-left: 1px solid #444; border-right: 1px solid #444;">
                    <div class="mesin-info-label">Shift</div>
                    <div class="mesin-info-value" style="color: #60a5fa;"><?= htmlspecialchars($shift_aktif) ?></div>
                </div>
                <div class="mesin-info-item">
                    <div class="mesin-info-label">Tanggal</div>
                    <div class="mesin-info-value"><?= date('d M Y', strtotime($tanggal)) ?></div>
                </div>
                <div class="mesin-info-item" style="border-left: 1px solid #444;">
                    <div class="mesin-info-label">OEE</div>
                    <div class="mesin-info-value" style="color: <?= ($summary['oee'] ?? 0) >= 85 ? '#00e676' : (($summary['oee'] ?? 0) >= 75 ? '#ffea00' : '#ff1744') ?>;"><?= $oeeVal ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">👤 Profil Operator & Skill</h3><span class="card-toggle">−</span></div>
            <div class="card-body">
                <div class="op-card" style="margin-bottom: 15px;">
                    <img src="<?= BASE_URL ?>assets/foto_operator/<?= !empty($opFoto) ? htmlspecialchars($opFoto) : 'default' ?>" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'" class="op-photo">
                    <div class="op-details">
                        <h4><?= htmlspecialchars($operatorName) ?></h4>
                        <span class="op-badge" style="background-color: <?= $opSkillColor ?>;"><?= htmlspecialchars($opSkillLabel) ?></span>
                        <div style="font-size: 12px; color: #a0a0a0; margin-top: 8px;">Lisensi: Aktif | NIK: <?= htmlspecialchars($nikOP) ?></div>
                    </div>
                </div>
                <hr style="border: 0; border-top: 1px dashed var(--border-color); margin: 10px 0;">
                <div style="font-size: 12px; color: var(--text-muted); font-weight: bold; margin-bottom: 8px;">RIWAYAT OPERATOR:</div>
                <div style="max-height: 80px; overflow-y: auto; font-size: 13px; color: #e0e0e0;">
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
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">⚙️ Proses Terakhir</h3><span class="card-toggle">−</span></div>
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
                                $scrap_row = (int)$ngRow['qty'];
                                echo "<tr><td style='color:#00bfa5; font-weight:bold;'>".htmlspecialchars($partName)."</td><td>".htmlspecialchars($partNumber)."</td><td>".htmlspecialchars($ngRow['nama_defect'] ?: 'Lain-lain')."</td><td>{$scrap_row} Pcs</td><td style='color:#ffeb3b;'>0 Pcs</td><td style='color:#ff1744; font-weight:bold;'>{$scrap_row} Pcs</td></tr>";
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
            <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📊 Dekidaka (<?= htmlspecialchars($shift_aktif) ?>)</h3><span class="card-toggle">−</span></div>
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
        <div class="card-header" onclick="toggleCard(this)"><h3 class="card-title">📑 Rincian Arsip Produksi Per Jam</h3><span class="card-toggle">−</span></div>
        <div class="card-body"> 
            <div class="table-container">
                <table id="rincianTable" class="display nowrap">
                    <thead>
                        <tr>
                            <th>Part Name</th>
                            <th>Part Number</th>
                            <th>Proses (CT)</th>
                            <?php 
                            if (!empty($jamAktifFinal)) {
                                foreach ($jamAktifFinal as $jamStr) { echo "<th style='text-align:center;'>{$jamStr}</th>"; }
                            }
                            ?>
                            <th style="color:#00bfa5; text-align:center;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($tableData) && !empty($jamAktifFinal)) {
                            foreach ($tableData as $pn => $partData) {
                                foreach ($partData['proses'] as $prosesName => $pData) {
                                    echo "<tr><td class='td-merge'>" . htmlspecialchars($partData['name']) . "</td><td class='td-merge'>" . htmlspecialchars($pn) . "</td><td>" . htmlspecialchars($prosesName) . " <br><span style='color:#a0a0a0; font-size:11px;'>(CT: {$pData['ct']}s)</span></td>";
                                    $rowTotal = 0;
                                    foreach ($jamAktifFinal as $jamStr) { 
                                        $qty = isset($pData['data_jam'][$jamStr]) ? $pData['data_jam'][$jamStr] : 'NO DATA'; 
                                        
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
                        } else {
                            // FIX: Pastikan colspan sesuai jumlah kolom di thead
                            $totalColumns = !empty($jamAktifFinal) ? count($jamAktifFinal) + 4 : 4;
                            echo "<tr><td colspan='{$totalColumns}' style='text-align:center; color:#777; padding:15px; font-weight:bold; letter-spacing:2px;'>NO DATA</td></tr>";
                        }
                        ?>
                    </tbody>
                    <?php
                        if (!empty($tableData) && !empty($jamAktifFinal)) {
                            // BARIS TOTAL AKUMULASI DI BAWAH TABEL
                            echo "<tfoot style='background-color:#1a1a1a;'><tr><td colspan='3' style='text-align:right; font-weight:bold; color:#00bfa5; padding:12px 20px 12px 12px; letter-spacing:1px;'>TOTAL</td>";
                            $grandTotal = 0;
                            foreach ($jamAktifFinal as $jamStr) {
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

    <script>
        // FIX: Suppress DataTables alert popup - use console instead
        $.fn.dataTable.ext.errMode = 'none';

        function toggleSidebar() { document.getElementById("sidebar").classList.toggle("open"); document.getElementById("overlay").classList.toggle("show"); }
        
        $(document).ready(function() { 
            // Only initialize DataTables if table has proper structure
            var headerCount = $('#rincianTable thead th').length;
            var firstRowCols = $('#rincianTable tbody tr:first td').length;
            
            if (headerCount > 0 && (firstRowCols === headerCount || firstRowCols === 0)) {
                $('#rincianTable').DataTable({ 
                    "ordering": false, 
                    "pageLength": 10, 
                    "language": { 
                        "search": "Cari Data:", 
                        "emptyTable": "No data available in table",
                        "zeroRecords": "Tidak ada data yang cocok"
                    } 
                });
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
        new Chart(dekidakaCtx, { type: 'bar', data: { labels: <?= json_encode(!empty($jamAktifFinal) ? $jamAktifFinal : ['No Data']) ?>, datasets: [ { label: 'Actual', data: <?= json_encode(!empty($hourlyActualSum) ? array_values($hourlyActualSum) : [0]) ?>, backgroundColor: '#00bfa5', borderRadius: 2 }, { label: 'Target', data: <?= json_encode(!empty($hourlyTargetSum) ? array_values($hourlyTargetSum) : [0]) ?>, backgroundColor: '#ff1744', borderRadius: 2 } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#ffffff' } }, datalabels: { display: false } }, scales: { y: { grid: { color: '#333' }, ticks: { color: '#a0a0a0' } }, x: { grid: { display: false }, ticks: { color: '#fff' } } } } });
    </script>
</body>
</html>