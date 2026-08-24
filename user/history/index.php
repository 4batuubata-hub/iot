<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// Ambil rentang tanggal yang tersedia di history
$sql_range = "SELECT MIN(tanggal) as min_tgl, MAX(tanggal) as max_tgl FROM history_summary";
$res_range = $conn->query($sql_range);
$range = ($res_range && $res_range->num_rows > 0) ? $res_range->fetch_assoc() : [];
$min_tanggal = $range['min_tgl'] ?? date('Y-m-d');
$max_tanggal = $range['max_tgl'] ?? date('Y-m-d');

// Ambil daftar mesin
$sql_mesin_list = "SELECT DISTINCT mcID, nama_mesin FROM history_summary ORDER BY mcID ASC";
$res_mesin_list = $conn->query($sql_mesin_list);

// Ambil daftar shift yang ada di history
$sql_shift_list = "SELECT DISTINCT shift FROM history_summary ORDER BY shift ASC";
$res_shift_list = $conn->query($sql_shift_list);

// Filter values (dengan SQL escaping untuk keamanan)
$filterTanggal = isset($_GET['tanggal']) ? $conn->real_escape_string($_GET['tanggal']) : $max_tanggal;
$filterMesin = isset($_GET['mesin']) ? $conn->real_escape_string($_GET['mesin']) : 'ALL';
$filterShift = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : 'ALL';

// Validasi format tanggal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTanggal)) {
    $filterTanggal = $max_tanggal;
}

// Query Data History Summary dengan Filter Gabungan
$query_history = "SELECT hs.*, mm.nama_mesin as master_nama, mm.id_mesin as master_id FROM history_summary hs LEFT JOIN master_mesin mm ON hs.mcID = mm.mcID WHERE hs.tanggal = '$filterTanggal'";
if ($filterMesin !== 'ALL') { $query_history .= " AND hs.mcID = '$filterMesin'"; }
if ($filterShift !== 'ALL') { $query_history .= " AND hs.shift = '$filterShift'"; }
$query_history .= " ORDER BY oee DESC";

$result = $conn->query($query_history);

// Hitung jumlah data untuk badge
$countResult = ($result) ? $result->num_rows : 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HISTORY OEE - PT CNC</title>
    <style>
        :root { 
            --bg-color: #000000; 
            --card-bg: #111111; 
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --color-ok: #00ff00; 
            --color-warning: #ffff00; 
            --color-ng: #ff0000; 
            --border-color: #334155;
            --primary: #3b82f6;
        }
        body { background-color: var(--bg-color); color: var(--text-main); font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 20px; overflow-x: hidden; }
        
        .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); width: 280px; height: 100%; background: #1e293b; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); will-change: transform; box-shadow: 4px 0 15px rgba(0,0,0,0.5); display: flex; flex-direction: column; }
        .sidebar.open { transform: translateX(0); }
        #overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999; opacity: 0; visibility: hidden; transition: opacity 0.3s; backdrop-filter: blur(2px); }
        #overlay.show { opacity: 1; visibility: visible; }
        
        .sidebar-header { padding: 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); background: #0f172a;}
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #fff; }
        .close-btn { background: none; border: none; color: var(--text-muted); font-size: 28px; cursor: pointer; transition: color 0.2s; }
        .close-btn:hover { color: #fff; }
        
        .sidebar-menu { display: flex; flex-direction: column; padding-top: 10px; }
        .sidebar-menu a { padding: 15px 25px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: bold; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; transition: all 0.2s;}
        .sidebar-menu a:hover, .sidebar-menu a.active { background: #334155; color: var(--primary); border-left: 4px solid var(--primary); padding-left: 30px;}
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: var(--card-bg); border: 1px solid var(--border-color); color: white; border-radius: 8px; width: 40px; height: 40px; font-size: 20px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .menu-btn:hover { background: #334155; }
        .header h1 { margin: 0; font-size: 22px; letter-spacing: 1px; font-weight: 700; background: linear-gradient(90deg, #60a5fa, #a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .filter-container { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: flex-end; flex: 1;}
        .filter-select { background: var(--card-bg); color: white; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; cursor: pointer; outline: none; transition: border-color 0.2s; }
        .filter-select:focus { border-color: var(--primary); }
        
        .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; width: 100%; animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .card { background-color: var(--card-bg); border-radius: 12px; padding: 18px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid var(--border-color); position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .card:hover { transform: translateY(-6px); border-color: var(--primary); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3), 0 0 15px rgba(59, 130, 246, 0.2); }
        .card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; }
        .card.oee-ok::before { background: var(--color-ok); }
        .card.oee-warn::before { background: var(--color-warning); }
        .card.oee-ng::before { background: var(--color-ng); }
        
        .card-header-title { font-size: 12px; color: #cbd5e1; font-weight: bold; margin-bottom: 6px; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 18px; font-weight: 800; margin-bottom: 4px; color: #ffffff; letter-spacing: -0.5px; }
        .card-id { font-size: 11px; color: var(--primary); font-weight: 600; margin-bottom: 8px; background: rgba(59,130,246,0.1); padding: 2px 8px; border-radius: 10px; display: inline-block; }
        .card-subtitle { font-size: 12px; color: #94a3b8; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .card-shift-badge { background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; color: #93c5fd; letter-spacing: 0.5px; }
        .card-date-badge { font-size: 10px; color: #64748b; }
        
        .card-output-row { font-size: 12px; margin-bottom: 15px; color: var(--text-muted); display: flex; gap: 10px; }
        .card-output-row .ok-val { color: var(--color-ok); font-weight: bold; }
        .card-output-row .ng-val { color: var(--color-ng); font-weight: bold; }
        
        .donut-container { position: relative; width: 100px; height: 100px; margin: 0 auto 20px auto; }
        .donut { width: 100%; height: 100%; border-radius: 50%; filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); transition: background 1s ease-out; }
        .donut-hole { position: absolute; top: 12%; left: 12%; width: 76%; height: 76%; background-color: var(--card-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column; box-shadow: inset 0 2px 5px rgba(0,0,0,0.5); }
        .donut-val { font-size: 20px; font-weight: 800; color: #fff; }
        .donut-label { font-size: 10px; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; }
        
        .bar-group { text-align: left; margin-bottom: 10px; }
        .bar-label { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 4px; font-weight: 600; color: #cbd5e1; }
        .bar-bg { width: 100%; background-color: #0f172a; border-radius: 6px; height: 8px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.5); }
        .bar-fill { height: 100%; border-radius: 6px; transition: width 1s cubic-bezier(0.4, 0, 0.2, 1); }
        .fill-a { background: linear-gradient(90deg, #2563eb, #60a5fa); box-shadow: 0 0 5px #2563eb; } 
        .fill-p { background: linear-gradient(90deg, #d97706, #fbbf24); box-shadow: 0 0 5px #d97706; } 
        .fill-q { background: linear-gradient(90deg, #7c3aed, #a78bfa); box-shadow: 0 0 5px #7c3aed; }
        
        .empty-state { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state h3 { font-size: 18px; margin-bottom: 8px; color: #475569; }

        @media (max-width: 768px) { .grid-container { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div id="overlay" onclick="toggleSidebar()"></div>
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h2>PT CNC Apps</h2>
            <button class="close-btn" onclick="toggleSidebar()">×</button>
        </div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php" class="active">📁 History Produksi</a>
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
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>logout.php" style="color: #ef4444; margin-top: 20px;">🚪 Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <h1>📁 HISTORY OEE DATA</h1>
        </div>
        <form method="GET" id="filterForm" class="filter-container">
            <!-- FILTER TANGGAL (Date Picker) -->
            <input type="date" name="tanggal" class="filter-select" 
                   value="<?= htmlspecialchars($filterTanggal) ?>" 
                   min="<?= $min_tanggal ?>" max="<?= $max_tanggal ?>"
                   onchange="document.getElementById('filterForm').submit()">
            
            <!-- FILTER SHIFT -->
            <select name="shift" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                <option value="ALL">Semua Shift</option>
                <?php 
                if($res_shift_list && $res_shift_list->num_rows > 0) {
                    while($rs = $res_shift_list->fetch_assoc()) {
                        $sel = ($filterShift == $rs['shift']) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($rs['shift'])."' $sel>".htmlspecialchars($rs['shift'])."</option>";
                    }
                }
                ?>
            </select>

            <!-- FILTER MESIN -->
            <select name="mesin" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                <option value="ALL">Semua Mesin</option>
                <?php 
                if($res_mesin_list && $res_mesin_list->num_rows > 0) {
                    while($rm = $res_mesin_list->fetch_assoc()) {
                        $sel = ($filterMesin == $rm['mcID']) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($rm['mcID'])."' $sel>".htmlspecialchars($rm['nama_mesin'])." (".$rm['mcID'].")</option>";
                    }
                }
                ?>
            </select>

            <!-- BADGE JUMLAH DATA -->
            <span style="background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; color: #93c5fd; white-space: nowrap;">
                📊 <?= $countResult ?> Data
            </span>
        </form>
    </div>

    <div class="grid-container">
        <?php
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $oee = $row['oee'];
                $donutColor = ($oee >= 85) ? 'var(--color-ok)' : (($oee >= 75) ? 'var(--color-warning)' : 'var(--color-ng)');
                $oeeClass = ($oee >= 85) ? 'oee-ok' : (($oee >= 75) ? 'oee-warn' : 'oee-ng');
                
                $mcID_card = $row['mcID'];
                $wm = $row['waktu_mulai'];
                $ws = $row['waktu_selesai'];
                $last_part = $row['part_name'];
                
                if ($wm && $ws) {
                    $sql_lp = "SELECT mc.part_name FROM log_quality lq LEFT JOIN master_ct mc ON lq.kode_proses = mc.kode WHERE lq.mcID = '$mcID_card' AND lq.timestamp >= '$wm' AND lq.timestamp <= '$ws' ORDER BY lq.timestamp DESC LIMIT 1";
                    $res_lp = $conn->query($sql_lp);
                    if ($res_lp && $res_lp->num_rows > 0) {
                        $last_part = $res_lp->fetch_assoc()['part_name'];
                    }
                }
                
                $display_nama = $row['master_nama'] ?: $row['nama_mesin'];
                $display_id = $row['master_id'] ?: $row['mcID'];
                
                ?>
                <div class="card <?= $oeeClass ?>" onclick="window.location.href='detail.php?mcID=<?= urlencode($row['mcID']) ?>&tanggal=<?= $row['tanggal'] ?>&shift=<?= urlencode($row['shift']) ?>'">
                    <div class="card-header-title">
                        <span class="card-date-badge">📅 <?= date('d/m/Y', strtotime($row['tanggal'])) ?></span>
                        <span class="card-shift-badge"><?= htmlspecialchars($row['shift']) ?></span>
                    </div>
                    <div class="card-title"><?= htmlspecialchars($display_nama) ?></div>
                    <div class="card-id">ID: <?= htmlspecialchars($display_id) ?></div>
                    <div class="card-subtitle">Last Part: <?= htmlspecialchars($last_part) ?></div>
                    
                    <div class="card-output-row">
                        <span>Output: <span class="ok-val"><?= $row['total_ok'] ?> Pcs</span></span>
                        <span>NG: <span class="ng-val"><?= $row['total_ng'] ?> Pcs</span></span>
                    </div>
                    
                    <div class="donut-container">
                        <div class="donut" style="background: conic-gradient(<?= $donutColor ?> 0% <?= $oee ?>%, var(--border-color) <?= $oee ?>% 100%);"></div>
                        <div class="donut-hole"><span class="donut-val"><?= number_format($oee, 1) ?>%</span><span class="donut-label">OEE</span></div>
                    </div>
                    <div class="bar-group"><div class="bar-label"><span>Availability</span> <span><?= number_format($row['availability'], 1) ?>%</span></div><div class="bar-bg"><div class="bar-fill fill-a" style="width: <?= $row['availability'] ?>%;"></div></div></div>
                    <div class="bar-group"><div class="bar-label"><span>Performance</span> <span><?= number_format($row['performance'], 1) ?>%</span></div><div class="bar-bg"><div class="bar-fill fill-p" style="width: <?= $row['performance'] ?>%;"></div></div></div>
                    <div class="bar-group"><div class="bar-label"><span>Quality</span> <span><?= number_format($row['quality'], 1) ?>%</span></div><div class="bar-bg"><div class="bar-fill fill-q" style="width: <?= $row['quality'] ?>%;"></div></div></div>
                </div>
                <?php
            }
        } else {
            echo "<div class='empty-state'><h3>📭 Tidak ada data history.</h3><p>Pilih tanggal lain atau cek kembali filter.</p></div>";
        }
        ?>
    </div>

    <script>
        function toggleSidebar() { 
            document.getElementById("sidebar").classList.toggle("open"); 
            document.getElementById("overlay").classList.toggle("show"); 
        }
    </script>
</body>
</html>