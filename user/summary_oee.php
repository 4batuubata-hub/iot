<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// Ambil list filter dinamis
$res_mesin = $conn->query("SELECT DISTINCT mcID, nama_mesin FROM history_summary ORDER BY mcID ASC");
$res_shift = $conn->query("SELECT DISTINCT shift FROM history_summary ORDER BY shift ASC");
$res_part  = $conn->query("SELECT DISTINCT part_name FROM history_summary WHERE part_name IS NOT NULL AND part_name != '' ORDER BY part_name ASC");

// Ambil tanggal max untuk default
$res_range = $conn->query("SELECT MIN(tanggal) as min_tgl, MAX(tanggal) as max_tgl FROM history_summary");
$range = ($res_range && $res_range->num_rows > 0) ? $res_range->fetch_assoc() : [];
$min_tanggal = $range['min_tgl'] ?? date('Y-m-d');
$max_tanggal = $range['max_tgl'] ?? date('Y-m-d');

// GET FILTER
$f_start = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : $max_tanggal;
$f_end   = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : $max_tanggal;
$f_shift = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : 'ALL';
$f_mesin = isset($_GET['mesin']) ? $conn->real_escape_string($_GET['mesin']) : 'ALL';
$f_part  = isset($_GET['part']) ? $conn->real_escape_string($_GET['part']) : 'ALL';

// BUILD QUERY
$where = ["hs.tanggal BETWEEN '$f_start' AND '$f_end'"];
if ($f_shift !== 'ALL') $where[] = "hs.shift = '$f_shift'";
if ($f_mesin !== 'ALL') $where[] = "hs.mcID = '$f_mesin'";
if ($f_part !== 'ALL')  $where[] = "hs.part_name = '$f_part'";

$f_proses = isset($_GET['proses']) ? $conn->real_escape_string($_GET['proses']) : 'ALL';
if ($f_proses !== 'ALL') $where[] = "mc.proses_description = '$f_proses'";

$where_clause = implode(" AND ", $where);

// Ambil list proses description untuk filter
$res_proses = $conn->query("SELECT DISTINCT proses_description FROM master_ct WHERE proses_description IS NOT NULL AND proses_description != '' ORDER BY proses_description ASC");

// 1. QUERY SUMMARY PROSES
$sql_data = "
    SELECT hs.*, mc.kode, mc.part_number, mc.proses_name, mc.proses_description 
    FROM history_summary hs 
    LEFT JOIN master_ct mc ON hs.part_name = mc.part_name 
    WHERE $where_clause 
    GROUP BY hs.id 
    ORDER BY hs.tanggal DESC, hs.shift ASC, hs.mcID ASC
";
$result = $conn->query($sql_data);

$sum_ok = 0; $sum_ng = 0; $sum_oee = 0; $sum_a = 0; $sum_p = 0; $sum_q = 0; $count = 0;
$data_rows = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $sum_ok += (int)$row['total_ok'];
        $sum_ng += (int)$row['total_ng'];
        $sum_oee += (float)$row['oee'];
        $sum_a += (float)$row['availability'];
        $sum_p += (float)$row['performance'];
        $sum_q += (float)$row['quality'];
        $count++;
        $data_rows[] = $row;
    }
}
$avg_oee = $count > 0 ? ($sum_oee / $count) : 0;
$avg_a   = $count > 0 ? ($sum_a / $count) : 0;
$avg_p   = $count > 0 ? ($sum_p / $count) : 0;
$avg_q   = $count > 0 ? ($sum_q / $count) : 0;

// 2. QUERY SUMMARY PART (GROUP BY PART NAME)
$sql_part = "
    SELECT 
        COALESCE(hs.part_name, 'Unknown') as p_name, 
        MAX(mc.part_number) as p_number,
        SUM(hs.total_ok) as t_ok, 
        SUM(hs.total_ng) as t_ng, 
        AVG(hs.availability) as a_a, 
        AVG(hs.performance) as a_p, 
        AVG(hs.quality) as a_q, 
        AVG(hs.oee) as a_oee 
    FROM history_summary hs
    LEFT JOIN master_ct mc ON hs.part_name = mc.part_name
    WHERE $where_clause 
    GROUP BY p_name 
    ORDER BY a_oee DESC
";
$res_part_data = $conn->query($sql_part);
$data_parts = [];
$chart_part_labels = [];
$chart_part_data = [];
if ($res_part_data && $res_part_data->num_rows > 0) {
    while($row = $res_part_data->fetch_assoc()) {
        $data_parts[] = $row;
        // Limit to top 15 for chart
        if (count($chart_part_labels) < 15) {
            $chart_part_labels[] = $row['p_name'] ?: 'Unknown';
            $chart_part_data[] = round((float)$row['a_oee'], 2);
        }
    }
}

// 3. QUERY CHART TREND (GROUP BY TANGGAL)
$sql_trend = "SELECT hs.tanggal, AVG(hs.oee) as a_oee FROM history_summary hs LEFT JOIN master_ct mc ON hs.part_name = mc.part_name WHERE $where_clause GROUP BY hs.tanggal ORDER BY hs.tanggal ASC";
$res_trend = $conn->query($sql_trend);
$chart_trend_labels = [];
$chart_trend_data = [];
if ($res_trend && $res_trend->num_rows > 0) {
    while($row = $res_trend->fetch_assoc()) {
        $chart_trend_labels[] = $row['tanggal'];
        $chart_trend_data[] = round((float)$row['a_oee'], 2);
    }
}

// Helper function for color coding
if (!function_exists('getColorClass')) {
    function getColorClass($val) {
        if ($val >= 90) return 'bg-green';
        if ($val >= 75) return 'bg-yellow';
        return 'bg-red';
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rangkuman OEE - PT CNC</title>
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
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
        
        /* SIDEBAR */
        .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); width: 280px; height: 100%; background: #1e293b; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); box-shadow: 4px 0 15px rgba(0,0,0,0.5); display: flex; flex-direction: column; }
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
        
        /* HEADER */
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .menu-btn { background: var(--card-bg); border: 1px solid var(--border-color); color: white; border-radius: 8px; width: 40px; height: 40px; font-size: 20px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .menu-btn:hover { background: #334155; }
        
        /* FILTER & CARDS */
        .filter-form { background: var(--card-bg); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 12px; color: var(--text-muted); font-weight: 600; }
        .filter-group input, .filter-group select { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 10px; border-radius: 6px; outline: none; }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--primary); }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.3s; height: 38px;}
        .btn-submit:hover { background: #2563eb; }

        .kpi-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .kpi-card { background: var(--card-bg); border: 1px solid var(--border-color); padding: 15px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .kpi-title { font-size: 12px; color: var(--text-muted); font-weight: bold; margin-bottom: 8px; text-transform: uppercase;}
        .kpi-value { font-size: 24px; font-weight: 900; color: #fff; }
        .text-ok { color: var(--color-ok); }
        .text-ng { color: var(--color-ng); }
        .text-primary { color: var(--primary); }

        /* DATATABLE OVERRIDES */
        .table-container { background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); overflow-x: auto; margin-top: 15px; }
        table.dataTable { border-collapse: collapse; width: 100%; color: var(--text-main); }
        table.dataTable thead th { background: #1e293b; border-bottom: 2px solid var(--primary); padding: 12px 10px; font-size: 13px; text-align: center !important; vertical-align: middle; border: 1px solid var(--border-color) !important; color: white; font-weight: bold; }
        table.dataTable tbody td { text-align: center; border-bottom: 1px solid var(--border-color); padding: 10px; font-size: 13px; border: 1px solid var(--border-color) !important; vertical-align: middle; }
        table.dataTable tbody tr { background: transparent !important; }
        table.dataTable tbody tr:hover { background: rgba(59, 130, 246, 0.1) !important; }
        .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_processing, .dataTables_wrapper .dataTables_paginate { color: var(--text-muted) !important; margin-bottom: 15px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { color: var(--text-main) !important; border-radius: 4px; padding: 5px 10px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: var(--primary) !important; border: 1px solid var(--primary) !important; color: white !important; }
        .dataTables_wrapper .dataTables_filter input { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 6px; border-radius: 4px; margin-left: 8px;}
        .dataTables_wrapper .dataTables_length select { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 4px; border-radius: 4px; }
        
        .dt-buttons .dt-button { background: #10b981 !important; color: white !important; border: none !important; border-radius: 6px !important; font-weight: bold; padding: 6px 15px !important; margin-bottom: 15px; transition: 0.3s; }
        .dt-buttons .dt-button:hover { background: #059669 !important; }

        /* Conditional Formatting */
        .bg-green { background-color: #059669 !important; color: white !important; font-weight: bold; }
        .bg-yellow { background-color: #d97706 !important; color: white !important; font-weight: bold; }
        .bg-red { background-color: #dc2626 !important; color: white !important; font-weight: bold; }

        /* Excel-like Header */
        .excel-header-container { display: flex; align-items: center; justify-content: center; border: 2px solid var(--border-color); background: var(--card-bg); margin-bottom: 20px; padding: 15px; }
        .excel-title { text-align: center; font-size: 24px; font-weight: 900; letter-spacing: 1px; color: white; text-transform: uppercase; margin: 0; }

        /* Select2 */
        .select2-container--default .select2-selection--single { background: #1e293b; border: 1px solid var(--border-color); border-radius: 6px; height: 38px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { color: white; line-height: normal; padding-left: 10px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; right: 5px; }
        .select2-dropdown { background-color: #1e293b; border: 1px solid var(--border-color); }
        .select2-container--default .select2-results__option { color: white; }
        .select2-container--default .select2-results__option--selected { background-color: var(--primary); }
        .select2-container--default .select2-search--dropdown .select2-search__field { background-color: #0f172a; color: white; border: 1px solid var(--border-color); }

        /* Tabs UI */
        .tabs { display: flex; gap: 10px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px; margin-bottom: 10px; margin-top: 30px;}
        .tab-btn { background: var(--card-bg); color: var(--text-muted); border: 1px solid var(--border-color); padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .tab-btn:hover { background: #1e293b; color: white; }
        .tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4); }
        .tab-content { display: none; animation: fadeIn 0.3s ease-in-out; }
        .tab-content.active { display: block; }

        /* Charts */
        .charts-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-box { background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); }
        @media(max-width: 900px) { .charts-container { grid-template-columns: 1fr; } }
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
            <a href="<?= BASE_URL ?>user/history/index.php">📁 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php" class="active">📈 Rangkuman OEE</a>
            
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

    <div class="header" style="border:none; margin-bottom:5px; padding-bottom:5px;">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
        </div>
    </div>

    <!-- EXCEL-LIKE HEADER -->
    <div class="excel-header-container">
        <h1 class="excel-title">SUMMARY PROSES OEE & FILTERING</h1>
    </div>

    <!-- FILTER FORM -->
    <form method="GET" action="summary_oee.php" class="filter-form">
        <div class="filter-group">
            <label>Tgl Awal</label>
            <input type="date" name="start_date" value="<?= $f_start ?>">
        </div>
        <div class="filter-group">
            <label>Tgl Akhir</label>
            <input type="date" name="end_date" value="<?= $f_end ?>">
        </div>
        <div class="filter-group">
            <label>Shift</label>
            <select name="shift" class="select2-filter" style="width: 120px;">
                <option value="ALL" <?= $f_shift == 'ALL' ? 'selected' : '' ?>>Semua Shift</option>
                <?php mysqli_data_seek($res_shift, 0); while($s = $res_shift->fetch_assoc()): ?>
                    <option value="<?= $s['shift'] ?>" <?= $f_shift == $s['shift'] ? 'selected' : '' ?>>Shift <?= $s['shift'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Mesin / Line</label>
            <select name="mesin" class="select2-filter" style="width: 250px;">
                <option value="ALL" <?= $f_mesin == 'ALL' ? 'selected' : '' ?>>Semua Mesin</option>
                <?php mysqli_data_seek($res_mesin, 0); while($m = $res_mesin->fetch_assoc()): ?>
                    <option value="<?= $m['mcID'] ?>" <?= $f_mesin == $m['mcID'] ? 'selected' : '' ?>><?= $m['nama_mesin'] ?> (<?= $m['mcID'] ?>)</option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Part Name</label>
            <select name="part" class="select2-filter" style="width: 200px;">
                <option value="ALL" <?= $f_part == 'ALL' ? 'selected' : '' ?>>Semua Part</option>
                <?php mysqli_data_seek($res_part, 0); while($p = $res_part->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($p['part_name']) ?>" <?= $f_part == $p['part_name'] ? 'selected' : '' ?>><?= htmlspecialchars($p['part_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Deskripsi Proses</label>
            <select name="proses" class="select2-filter" style="width: 250px;">
                <option value="ALL" <?= $f_proses == 'ALL' ? 'selected' : '' ?>>Semua Proses</option>
                <?php if($res_proses): mysqli_data_seek($res_proses, 0); while($pr = $res_proses->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($pr['proses_description']) ?>" <?= $f_proses == $pr['proses_description'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['proses_description']) ?></option>
                <?php endwhile; endif; ?>
            </select>
        </div>
        <button type="submit" class="btn-submit">🔍 Terapkan Filter</button>
    </form>

    <!-- KPI CARDS -->
    <div class="kpi-container">
        <div class="kpi-card">
            <div class="kpi-title">Avg OEE</div>
            <div class="kpi-value text-primary"><?= number_format($avg_oee, 2) ?>%</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Avg Availability</div>
            <div class="kpi-value"><?= number_format($avg_a, 2) ?>%</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Avg Performance</div>
            <div class="kpi-value"><?= number_format($avg_p, 2) ?>%</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Avg Quality</div>
            <div class="kpi-value"><?= number_format($avg_q, 2) ?>%</div>
        </div>
        <div class="kpi-card" style="border-bottom: 4px solid var(--color-ok);">
            <div class="kpi-title">Total Produk OK</div>
            <div class="kpi-value text-ok"><?= number_format($sum_ok) ?></div>
        </div>
        <div class="kpi-card" style="border-bottom: 4px solid var(--color-ng);">
            <div class="kpi-title">Total Produk NG</div>
            <div class="kpi-value text-ng"><?= number_format($sum_ng) ?></div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="charts-container">
        <div class="chart-box">
            <canvas id="trendChart"></canvas>
        </div>
        <div class="chart-box">
            <canvas id="partChart"></canvas>
        </div>
    </div>

    <!-- TABS -->
    <div class="tabs">
        <button class="tab-btn active" onclick="openTab(event, 'tabProses')">📝 SUMMARY PROSES</button>
        <button class="tab-btn" onclick="openTab(event, 'tabPart')">📦 SUMMARY PART</button>
    </div>

    <!-- TAB 1: SUMMARY PROSES -->
    <div id="tabProses" class="tab-content active">
        <div class="table-container">
            <table id="oeeProsesTable" class="display nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>RANK</th>
                        <th>TANGGAL</th>
                        <th>KODE PROS</th>
                        <th>NAMA PART</th>
                        <th>NO. PART</th>
                        <th>PROSES</th>
                        <th>DESKRIPSI PROSES</th>
                        <th>TOTAL OK</th>
                        <th>TOTAL NG</th>
                        <th>OTR (%)</th>
                        <th>PER (%)</th>
                        <th>QR (%)</th>
                        <th>OEE (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach($data_rows as $row): 
                        $otr = (float)$row['availability'];
                        $per = (float)$row['performance'];
                        $qr  = (float)$row['quality'];
                        $oee = (float)$row['oee'];
                    ?>
                    <tr>
                        <td style="font-weight:bold;"><?= $rank++ ?></td>
                        <td><?= $row['tanggal'] ?></td>
                        <td><?= htmlspecialchars($row['kode'] ?? '-') ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($row['part_name'] ?? '-') ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($row['part_number'] ?? '-') ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($row['proses_name'] ?? '-') ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($row['proses_description'] ?? '-') ?></td>
                        <td style="color:var(--color-ok); font-weight:bold;"><?= $row['total_ok'] ?></td>
                        <td style="color:var(--color-ng); font-weight:bold;"><?= $row['total_ng'] ?></td>
                        <td class="<?= getColorClass($otr) ?>"><?= number_format($otr, 2) ?>%</td>
                        <td class="<?= getColorClass($per) ?>"><?= number_format($per, 2) ?>%</td>
                        <td class="<?= getColorClass($qr) ?>"><?= number_format($qr, 2) ?>%</td>
                        <td class="<?= getColorClass($oee) ?>"><?= number_format($oee, 2) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 2: SUMMARY PART -->
    <div id="tabPart" class="tab-content">
        <div class="table-container">
            <table id="oeePartTable" class="display nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>RANK</th>
                        <th>NAMA PART</th>
                        <th>NO. PART</th>
                        <th>TOTAL OK</th>
                        <th>TOTAL NG</th>
                        <th>AVG OTR (%)</th>
                        <th>AVG PER (%)</th>
                        <th>AVG QR (%)</th>
                        <th>AVG OEE (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank2 = 1;
                    foreach($data_parts as $p): 
                        $a_a = (float)$p['a_a'];
                        $a_p = (float)$p['a_p'];
                        $a_q  = (float)$p['a_q'];
                        $a_oee = (float)$p['a_oee'];
                    ?>
                    <tr>
                        <td style="font-weight:bold;"><?= $rank2++ ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($p['p_name']) ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($p['p_number'] ?? '-') ?></td>
                        <td style="color:var(--color-ok); font-weight:bold;"><?= $p['t_ok'] ?></td>
                        <td style="color:var(--color-ng); font-weight:bold;"><?= $p['t_ng'] ?></td>
                        <td class="<?= getColorClass($a_a) ?>"><?= number_format($a_a, 2) ?>%</td>
                        <td class="<?= getColorClass($a_p) ?>"><?= number_format($a_p, 2) ?>%</td>
                        <td class="<?= getColorClass($a_q) ?>"><?= number_format($a_q, 2) ?>%</td>
                        <td class="<?= getColorClass($a_oee) ?>"><?= number_format($a_oee, 2) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        function toggleSidebar() { 
            document.getElementById("sidebar").classList.toggle("open"); 
            document.getElementById("overlay").classList.toggle("show"); 
        }

        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
                tabcontent[i].classList.remove("active");
            }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            document.getElementById(tabName).style.display = "block";
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
            
            // Re-adjust columns for datatables when tab becomes visible
            $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        }

        $(document).ready(function() {
            $('.select2-filter').select2({ placeholder: 'Pilih filter...', allowClear: false });

            let dtOptions = {
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'excelHtml5', text: '📥 Export ke Excel', className: 'btn-export' },
                    { extend: 'csvHtml5', text: '📄 Export ke CSV' }
                ],
                pageLength: 25,
                scrollX: true
            };

            $('#oeeProsesTable').DataTable({...dtOptions, order: [[0, 'asc']]});
            $('#oeePartTable').DataTable({...dtOptions, order: [[0, 'asc']]});

            // CHART JS INIT
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_trend_labels) ?>,
                    datasets: [{
                        label: 'Trend OEE (%)',
                        data: <?= json_encode($chart_trend_data) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: { display: true, text: 'Trend OEE Harian', color: '#fff', font: {size: 16} },
                        legend: { labels: { color: '#fff' } }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: {color: '#334155'} },
                        x: { ticks: { color: '#94a3b8' }, grid: {color: '#334155'} }
                    }
                }
            });

            const partCtx = document.getElementById('partChart').getContext('2d');
            new Chart(partCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_part_labels) ?>,
                    datasets: [{
                        label: 'OEE per Part (%)',
                        data: <?= json_encode($chart_part_data) ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: { display: true, text: 'Top 15 Part Berdasarkan OEE', color: '#fff', font: {size: 16} },
                        legend: { labels: { color: '#fff' } }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: {color: '#334155'} },
                        x: { ticks: { color: '#94a3b8', maxRotation: 45, minRotation: 45 }, grid: {display: false} }
                    }
                }
            });
        });
    </script>
</body>
</html>
