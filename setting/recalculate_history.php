<?php
/**
 * RECALCULATE & REBUILD HISTORY SUMMARY (SSOT & ISO 22400-2)
 * Khusus Tim IT / Setting
 * Tool untuk merapikan dan menghitung ulang data produksi masa lalu
 * berdasarkan raw logs: history_quality/log_quality, history_downtime/log_downtime, history_ng/log_ng.
 */
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../helper_jadwal.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Jakarta');

$filterTgl = isset($_GET['tanggal']) ? trim($_GET['tanggal']) : '';
$filterMc  = isset($_GET['mcID']) ? trim($_GET['mcID']) : '';
$action    = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Ambil rentang tanggal dari gabungan history_quality, log_quality, dan history_summary
$q_dates = $conn->query("
    SELECT DISTINCT tgl FROM (
        SELECT DATE(timestamp) as tgl FROM history_quality
        UNION
        SELECT DATE(timestamp) as tgl FROM log_quality
        UNION
        SELECT DATE(tanggal) as tgl FROM history_summary
    ) as u WHERE tgl IS NOT NULL AND tgl != '0000-00-00' ORDER BY tgl DESC
");
$available_dates = [];
if ($q_dates) {
    while ($d = $q_dates->fetch_assoc()) {
        $available_dates[] = $d['tgl'];
    }
}

// Ambil daftar mesin
$q_mc = $conn->query("
    SELECT DISTINCT mcID FROM (
        SELECT mcID FROM history_quality
        UNION
        SELECT mcID FROM log_quality
        UNION
        SELECT mcID FROM history_summary
    ) as u WHERE mcID IS NOT NULL AND mcID != '' ORDER BY mcID ASC
");
$available_mcs = [];
if ($q_mc) {
    while ($m = $q_mc->fetch_assoc()) {
        $available_mcs[] = $m['mcID'];
    }
}

// Function helper untuk deduplikasi & clipping interval downtime
function getCleanDowntimeSeconds($conn, $mcID, $s_start, $s_end, $tbl_dt = 'history_downtime') {
    $dt_res = $conn->query("SELECT kode_dt, durasi_detik, timestamp FROM $tbl_dt WHERE mcID = '$mcID' AND timestamp >= '$s_start' AND timestamp <= '$s_end' ORDER BY timestamp ASC");
    if (!$dt_res || $dt_res->num_rows == 0) return 0;
    
    $shift_s = strtotime($s_start);
    $shift_e = strtotime($s_end);
    $intervals = [];
    
    while ($dr = $dt_res->fetch_assoc()) {
        $dur = (int)$dr['durasi_detik'];
        if ($dur <= 0) continue;
        $t_end = strtotime($dr['timestamp']);
        $t_start = $t_end - $dur;
        
        $c_start = max($t_start, $shift_s);
        $c_end   = min($t_end, $shift_e);
        if ($c_end > $c_start) {
            $intervals[] = [$c_start, $c_end];
        }
    }
    if (empty($intervals)) return 0;
    
    usort($intervals, function($a, $b) { return $a[0] - $b[0]; });
    $merged = [];
    foreach ($intervals as $iv) {
        if (empty($merged)) {
            $merged[] = $iv;
        } else {
            $last = &$merged[count($merged) - 1];
            if ($iv[0] <= $last[1]) {
                $last[1] = max($last[1], $iv[1]);
            } else {
                $merged[] = $iv;
            }
        }
    }
    $total_sec = 0;
    foreach ($merged as $m_iv) {
        $total_sec += ($m_iv[1] - $m_iv[0]);
    }
    return $total_sec;
}

// Function helper untuk rekonstruksi pulse output OK
function getCleanTotalOk($conn, $mcID, $s_start, $s_end, $tbl_q = 'history_quality') {
    $res = $conn->query("SELECT prodCount FROM $tbl_q WHERE mcID = '$mcID' AND timestamp >= '$s_start' AND timestamp <= '$s_end' ORDER BY id ASC");
    if (!$res || $res->num_rows == 0) return 0;
    
    $total_ok = 0;
    $prev_p = null;
    while ($r = $res->fetch_assoc()) {
        $p = (int)$r['prodCount'];
        if ($prev_p !== null) {
            $delta = $p - $prev_p;
            if ($delta > 0 && $delta <= 100) {
                $total_ok += $delta;
            } else if ($delta < 0 && $p <= 20) {
                // Reset ESP32
                $total_ok += $p;
            }
        }
        $prev_p = $p;
    }
    return $total_ok;
}

// Function helper get PPT dari master jadwal
function getPptSeconds($conn, $line, $shift, $time_str) {
    $template_aktif = 'DEFAULT';
    if (!empty($line)) {
        $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line' LIMIT 1");
        if ($res_tpl && $res_tpl->num_rows > 0) $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
    }
    $day_num = date('N', strtotime($time_str));
    $hari = ($day_num >= 1 && $day_num <= 4) ? 'SENIN-KAMIS' : (($day_num == 5) ? 'JUMAT' : 'SABTU-MINGGU');
    
    $tpl_esc = $conn->real_escape_string($template_aktif);
    $sql = "SELECT menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift' AND (hari = '$hari' OR hari = 'SETIAP HARI') ORDER BY urutan ASC";
    $res = $conn->query($sql);
    $ppt = 0;
    if ($res && $res->num_rows > 0) {
        while ($r = $res->fetch_assoc()) $ppt += ((int)$r['menit_efektif'] * 60);
    }
    return ($ppt > 0) ? $ppt : (480 * 60);
}

// Jalankan kalkulasi jika tombol/filter ditekan
$results = [];
$stats = ['total' => 0, 'updated' => 0, 'unchanged' => 0];

if (!empty($filterTgl) || $action === 'execute_all') {
    $target_dates = !empty($filterTgl) ? [$filterTgl] : $available_dates;
    
    // Baca jam reset dari setting_pabrik (BUKAN hardcode!)
    $sql_sp_rc = $conn->query("SELECT jam_reset_shift1, jam_reset_shift2 FROM setting_pabrik LIMIT 1");
    $sp_rc = ($sql_sp_rc && $sql_sp_rc->num_rows > 0) ? $sql_sp_rc->fetch_assoc() : [];
    $rc_jam_s1 = $sp_rc['jam_reset_shift1'] ?? '16:00:00';
    $rc_jam_s2 = $sp_rc['jam_reset_shift2'] ?? '06:00:00';

    foreach ($target_dates as $tgl) {
        $shifts = [
            ['label' => 'SHIFT 1', 'start' => "$tgl $rc_jam_s2", 'end' => "$tgl $rc_jam_s1"],
            ['label' => 'SHIFT 2', 'start' => "$tgl $rc_jam_s1", 'end' => date("Y-m-d $rc_jam_s2", strtotime("$tgl +1 day"))]
        ];
        
        foreach ($shifts as $s) {
            $s_label = $s['label'];
            $s_start = $s['start'];
            $s_end   = $s['end'];
            
            // Cek apakah data shift ini ada di history_quality atau log_quality
            $tbl_q = 'history_quality';
            $chk_hq = $conn->query("SELECT id FROM history_quality WHERE timestamp >= '$s_start' AND timestamp <= '$s_end' LIMIT 1");
            if (!$chk_hq || $chk_hq->num_rows == 0) {
                $chk_lq = $conn->query("SELECT id FROM log_quality WHERE timestamp >= '$s_start' AND timestamp <= '$s_end' LIMIT 1");
                if ($chk_lq && $chk_lq->num_rows > 0) {
                    $tbl_q = 'log_quality';
                }
            }
            
            $tbl_dt = ($tbl_q === 'log_quality') ? 'log_downtime' : 'history_downtime';
            $tbl_ng = ($tbl_q === 'log_quality') ? 'log_ng' : 'history_ng';
            
            $sql_mc = "SELECT DISTINCT mcID FROM $tbl_q WHERE timestamp >= '$s_start' AND timestamp <= '$s_end'";
            if (!empty($filterMc)) {
                $sql_mc .= " AND mcID = '" . $conn->real_escape_string($filterMc) . "'";
            }
            $res_mc = $conn->query($sql_mc);
            if (!$res_mc || $res_mc->num_rows == 0) continue;
            
            while ($rm = $res_mc->fetch_assoc()) {
                $mcID = $rm['mcID'];
                
                // Ambil info part, line, ct
                $info = $conn->query("SELECT m.nama_mesin, m.id_mesin, c.part_name, c.ct_pcs, c.line 
                                      FROM $tbl_q q 
                                      LEFT JOIN master_mesin m ON (q.mcID = m.id_mesin OR q.mcID = m.mcID) 
                                      LEFT JOIN master_ct c ON q.kode_proses = c.kode 
                                      WHERE q.mcID = '$mcID' AND q.timestamp >= '$s_start' AND q.timestamp <= '$s_end' 
                                      ORDER BY q.id DESC LIMIT 1")->fetch_assoc();
                
                $nama_mesin = $info['nama_mesin'] ?? $mcID;
                $id_mesin   = $info['id_mesin'] ?? $mcID;
                $part_name  = $info['part_name'] ?? 'Tidak Diketahui';
                $ct_pcs     = (float)($info['ct_pcs'] ?? 0);
                $line       = $info['line'] ?? '';
                
                // Waktu mulai & selesai
                $w_res = $conn->query("SELECT MIN(timestamp) as wm, MAX(timestamp) as ws FROM $tbl_q WHERE mcID = '$mcID' AND timestamp >= '$s_start' AND timestamp <= '$s_end'")->fetch_assoc();
                $waktu_mulai   = $w_res['wm'] ?? $s_start;
                $waktu_selesai = $w_res['ws'] ?? $s_end;
                
                // Hitung SSOT
                $new_ok = getCleanTotalOk($conn, $mcID, $s_start, $s_end, $tbl_q);
                
                $ng_res = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as ng FROM $tbl_ng WHERE mcID = '$mcID' AND timestamp >= '$s_start' AND timestamp <= '$s_end'")->fetch_assoc();
                $new_ng = (int)($ng_res['ng'] ?? 0);
                
                $new_dt = getCleanDowntimeSeconds($conn, $mcID, $s_start, $s_end, $tbl_dt);
                $ppt_sec = getPptSeconds($conn, $line, $s_label, $waktu_mulai);
                
                $std = calculateStandardOEE($ppt_sec, $new_dt, $new_ok, $new_ng, $ct_pcs);
                
                // Ambil data lama di history_summary jika ada
                $old_q = $conn->query("SELECT id, total_ok, total_ng, oee, availability, performance, quality FROM history_summary WHERE mcID = '$mcID' AND tanggal = '$tgl' AND shift = '$s_label' LIMIT 1");
                $old = ($old_q && $old_q->num_rows > 0) ? $old_q->fetch_assoc() : null;
                
                $item = [
                    'tanggal'      => $tgl,
                    'shift'        => $s_label,
                    'mcID'         => $mcID,
                    'id_mesin'     => $id_mesin,
                    'nama_mesin'   => $nama_mesin,
                    'part_name'    => $part_name,
                    'ct_pcs'       => $ct_pcs,
                    'old_ok'       => $old ? $old['total_ok'] : '-',
                    'old_ng'       => $old ? $old['total_ng'] : '-',
                    'old_oee'      => $old ? number_format($old['oee'], 1).'%' : '-',
                    'new_ok'       => $new_ok,
                    'new_ng'       => $new_ng,
                    'new_dt_min'   => round($new_dt / 60, 1),
                    'new_avail'    => $std['availability'],
                    'new_perf'     => $std['performance'],
                    'new_qual'     => $std['quality'],
                    'new_oee'      => $std['oee'],
                    'source_tbl'   => $tbl_q,
                    'has_old'      => ($old !== null)
                ];
                
                // Eksekusi jika action = execute
                if ($action === 'execute') {
                    $part_esc = $conn->real_escape_string($part_name);
                    $nama_esc = $conn->real_escape_string($nama_mesin);
                    
                    if ($old) {
                        $upd = "UPDATE history_summary SET 
                                    nama_mesin = '$nama_esc',
                                    part_name = '$part_esc',
                                    total_ok = '{$new_ok}',
                                    total_ng = '{$new_ng}',
                                    availability = '{$std['availability']}',
                                    performance = '{$std['performance']}',
                                    quality = '{$std['quality']}',
                                    oee = '{$std['oee']}',
                                    waktu_mulai = '$waktu_mulai',
                                    waktu_selesai = '$waktu_selesai'
                                WHERE id = '{$old['id']}'";
                        $conn->query($upd);
                        $stats['updated']++;
                    } else {
                        $ins = "INSERT INTO history_summary (tanggal, shift, mcID, nama_mesin, part_name, total_ok, total_ng, oee, availability, performance, quality, waktu_mulai, waktu_selesai, waktu_reset)
                                VALUES ('$tgl', '$s_label', '$mcID', '$nama_esc', '$part_esc', '{$new_ok}', '{$new_ng}', '{$std['oee']}', '{$std['availability']}', '{$std['performance']}', '{$std['quality']}', '$waktu_mulai', '$waktu_selesai', '$s_end')";
                        $conn->query($ins);
                        $stats['updated']++;
                    }
                }
                
                $stats['total']++;
                $results[] = $item;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Rekalkulasi History (IT Only) - PT CNC</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #131b2e;
            --card-border: #1e293b;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; }
        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
        }
        /* Sidebar Styling */
        .sidebar {
            position: fixed; top: 0; left: 0;
            transform: translateX(-100%);
            width: 280px; height: 100%;
            background: #1e1e1e; border-right: 1px solid #333;
            z-index: 1000; transition: transform 0.3s;
            box-shadow: 4px 0 15px rgba(0,0,0,0.5);
            display: flex; flex-direction: column;
        }
        .sidebar.open { transform: translateX(0); }
        .sidebar-header {
            padding: 20px; border-bottom: 1px solid #333;
            display: flex; justify-content: space-between; align-items: center;
        }
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #00bfa5; }
        .close-btn {
            background: none; border: none; color: #fff;
            font-size: 24px; cursor: pointer;
        }
        .sidebar-menu { padding: 20px 0; display: flex; flex-direction: column; }
        .sidebar-menu a {
            padding: 12px 20px; color: #ccc; text-decoration: none;
            display: flex; align-items: center; gap: 10px; font-size: 14px;
            transition: 0.2s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: #2a2a2a; color: #00bfa5; border-left: 4px solid #00bfa5;
        }
        #overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 999; display: none;
        }
        #overlay.show { display: block; }
        .menu-btn {
            background: #1f2937; border: 1px solid #374151; color: #fff;
            font-size: 18px; padding: 6px 12px; border-radius: 6px; cursor: pointer;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .btn-back {
            color: #94a3b8; text-decoration: none; font-size: 13px; font-weight: 600;
            padding: 8px 14px; border-radius: 6px; background: #1e293b; border: 1px solid var(--card-border);
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-back:hover { background: #334155; color: #fff; border-color: var(--primary); transform: translateX(-2px); }
        .filter-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }
        select, input, button {
            background: #0f172a;
            border: 1px solid var(--card-border);
            color: #fff;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            font-family: inherit;
        }
        select:focus, input:focus { border-color: var(--primary); }
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success {
            background: var(--success);
            border-color: var(--success);
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-success:hover { background: #059669; }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: #34d399; }
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        th, td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--card-border);
        }
        th {
            background: #0f172a;
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tr:hover { background: rgba(255, 255, 255, 0.02); }
        .badge {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }
        .badge-shift { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-ok { color: var(--success); font-weight: 700; }
        .badge-oee-ok { background: rgba(16, 185, 129, 0.15); color: #34d399; }
        .badge-oee-warn { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
        .badge-oee-ng { background: rgba(239, 68, 68, 0.15); color: #f87171; }
    </style>
</head>
<body>

    <!-- SIDEBAR NAVIGATION -->
    <div id="overlay" onclick="toggleSidebar()"></div>
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h2>PT CNC Apps</h2>
            <button class="close-btn" onclick="toggleSidebar()">×</button>
        </div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php">📁 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php">📈 Rangkuman OEE</a>
            <?php if(isset($user_role) && $user_role === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/pengaturan_jam.php">⏱️ Master Jam (Template)</a>
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line</a>
                <a href="<?= BASE_URL ?>setting/recalculate_history.php" class="active">🔄 Rekalkulasi History</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/settings_auth.php">🔒 Pengaturan Keamanan</a>
            <?php endif; ?>
            <?php if(isset($user_role) && in_array($user_role, ['admin', 'it'])): ?>
                <a href="<?= BASE_URL ?>admin/skill_matrix.php">🎯 Skill Matrix Mesin</a>
                <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
                <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>logout.php" style="color: #ef4444; margin-top: 20px;">🚪 Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <div>
                <h1 style="margin:0; font-size:20px; font-weight:800; background:linear-gradient(90deg, #60a5fa, #a78bfa); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">
                    ⚙️ TOOL REKALKULASI & SINKRONISASI HISTORY (IT ONLY)
                </h1>
                <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">
                    Membangun ulang data agregat pada <code>history_summary</code> dari raw logs (<code>history_quality</code> & <code>log_quality</code>).
                </p>
            </div>
        </div>
        <a href="<?= BASE_URL ?>setting/pengaturan_line.php" onclick="if(history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){ history.back(); return false; }" class="btn-back">← Pengaturan Line</a>
    </div>

    <?php if ($action === 'execute'): ?>
        <div class="alert alert-success">
            🎉 <strong>Berhasil!</strong> Sebanyak <strong><?= $stats['updated'] ?> data</strong> pada <code>history_summary</code> telah berhasil diperbarui dan disinkronisasi dengan raw logs produksi.
        </div>
    <?php endif; ?>

    <div class="filter-box">
        <form method="GET" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group">
                <label>Pilih Tanggal Masa Lalu:</label>
                <select name="tanggal">
                    <option value="">-- Pilih Tanggal --</option>
                    <?php foreach ($available_dates as $d): ?>
                        <option value="<?= $d ?>" <?= ($filterTgl === $d) ? 'selected' : '' ?>><?= date('d-M-Y', strtotime($d)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Filter Mesin (Opsional):</label>
                <select name="mcID">
                    <option value="">Semua Mesin</option>
                    <?php foreach ($available_mcs as $m): ?>
                        <option value="<?= $m ?>" <?= ($filterMc === $m) ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-primary">🔍 Pratinjau Rekalkulasi</button>
        </form>

        <?php if (!empty($results)): ?>
            <form method="POST" action="recalculate_history.php?tanggal=<?= urlencode($filterTgl) ?>&mcID=<?= urlencode($filterMc) ?>" style="margin-left: auto;">
                <input type="hidden" name="action" value="execute">
                <button type="submit" class="btn-success" onclick="return confirm('Apakah Anda yakin ingin memperbarui data history_summary dengan hasil rekalkulasi SSOT ini?')">
                    💾 Eksekusi & Simpan ke History Summary
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($results)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Tgl & Shift</th>
                        <th>Mesin</th>
                        <th>Part Name</th>
                        <th>Cycle Time</th>
                        <th>Sumber Log</th>
                        <th>Total OK (Lama ➔ Baru)</th>
                        <th>NG</th>
                        <th>Downtime</th>
                        <th>Avail</th>
                        <th>Perf</th>
                        <th>Qual</th>
                        <th>OEE Baru (Lama)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td>
                                <strong><?= $r['tanggal'] ?></strong><br>
                                <span class="badge badge-shift"><?= $r['shift'] ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['nama_mesin']) ?></strong><br>
                                <small style="color: var(--text-muted);"><?= $r['mcID'] ?></small>
                            </td>
                            <td><?= htmlspecialchars($r['part_name']) ?></td>
                            <td><?= $r['ct_pcs'] ?>s</td>
                            <td><code style="color: #93c5fd;"><?= $r['source_tbl'] ?></code></td>
                            <td>
                                <span style="color: var(--text-muted);"><?= $r['old_ok'] ?></span> ➔ 
                                <span class="badge-ok"><?= $r['new_ok'] ?></span>
                            </td>
                            <td><?= $r['new_ng'] ?></td>
                            <td><?= $r['new_dt_min'] ?>m</td>
                            <td><?= $r['new_avail'] ?>%</td>
                            <td><?= $r['new_perf'] ?>%</td>
                            <td><?= $r['new_qual'] ?>%</td>
                            <td>
                                <?php
                                    $badgeCls = ($r['new_oee'] >= 85) ? 'badge-oee-ok' : (($r['new_oee'] >= 60) ? 'badge-oee-warn' : 'badge-oee-ng');
                                ?>
                                <span class="badge <?= $badgeCls ?>"><?= $r['new_oee'] ?>%</span>
                                <small style="color: var(--text-muted);">(<?= $r['old_oee'] ?>)</small>
                            </td>
                            <td>
                                <?= $r['has_old'] ? '<span style="color: #60a5fa;">Update</span>' : '<span style="color: #34d399;">Baru (Insert)</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (!empty($filterTgl)): ?>
        <div style="background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 40px; text-align: center; color: var(--text-muted);">
            Tidak ada data mentah (raw log) ditemukan untuk tanggal <strong><?= $filterTgl ?></strong>.
        </div>
    <?php else: ?>
        <div style="background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 40px; text-align: center; color: var(--text-muted);">
            Silakan pilih tanggal masa lalu di atas untuk mulai melihat pratinjau kalkulasi ulang SSOT.
        </div>
    <?php endif; ?>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("open");
            document.getElementById("overlay").classList.toggle("show");
        }
    </script>
</body>
</html>
