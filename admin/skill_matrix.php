<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

// Ensure skill_matrix table exists
$conn->query("CREATE TABLE IF NOT EXISTS skill_matrix (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mcID VARCHAR(50) NOT NULL,
    nik_operator VARCHAR(50) NOT NULL,
    skill_level INT NOT NULL COMMENT '1: Beginner, 2: Basic, 3: Competent, 4: Expert',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mc_op (mcID, nik_operator)
)");

// =========================================================================
// AJAX HANDLERS
// =========================================================================
if (isset($_GET['ajax']) || isset($_POST['ajax']) || isset($_REQUEST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'get_machine_details') {
        $mcID = $conn->real_escape_string($_GET['mcID'] ?? '');
        
        // Machine Info
        $res_m = $conn->query("SELECT * FROM master_mesin WHERE mcID = '$mcID' OR id_mesin = '$mcID' LIMIT 1");
        $mesin = $res_m ? $res_m->fetch_assoc() : null;

        if (!$mesin) {
            echo json_encode(['status' => 'error', 'message' => 'Mesin tidak ditemukan.']);
            exit;
        }

        $target_mcID = $mesin['mcID'];

        // Assigned Operators
        $sql_ops = "SELECT sm.id, sm.mcID, sm.nik_operator, sm.skill_level, sm.updated_at, mo.nama, mo.bagian 
                    FROM skill_matrix sm 
                    JOIN master_operator mo ON sm.nik_operator = mo.nik 
                    WHERE sm.mcID = '$target_mcID' 
                    ORDER BY sm.skill_level DESC, mo.nama ASC";
        $res_ops = $conn->query($sql_ops);
        $assigned = [];
        $assigned_niks = [];
        if ($res_ops) {
            while ($r = $res_ops->fetch_assoc()) {
                $assigned[] = $r;
                $assigned_niks[] = $r['nik_operator'];
            }
        }

        // Available Operators (not assigned yet)
        $sql_all_ops = "SELECT nik, nama, bagian FROM master_operator ORDER BY nama ASC";
        $res_all = $conn->query($sql_all_ops);
        $available = [];
        if ($res_all) {
            while ($r = $res_all->fetch_assoc()) {
                if (!in_array($r['nik'], $assigned_niks)) {
                    $available[] = $r;
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'mesin' => $mesin,
            'assigned' => $assigned,
            'available' => $available
        ]);
        exit;
    }

    if ($action === 'save_operator') {
        $mcID = $conn->real_escape_string($_POST['mcID'] ?? '');
        $nik = $conn->real_escape_string($_POST['nik_operator'] ?? '');
        $level = intval($_POST['skill_level'] ?? 1);

        if (empty($mcID) || empty($nik)) {
            echo json_encode(['status' => 'error', 'message' => 'Mesin dan Operator wajib dipilih.']);
            exit;
        }
        if ($level < 1 || $level > 4) {
            echo json_encode(['status' => 'error', 'message' => 'Level skill harus bernilai 1 - 4.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO skill_matrix (mcID, nik_operator, skill_level) VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE skill_level = VALUES(skill_level)");
        $stmt->bind_param("ssi", $mcID, $nik, $level);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Skill operator berhasil disimpan!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data: ' . $stmt->error]);
        }
        exit;
    }

    if ($action === 'delete_operator') {
        $mcID = $conn->real_escape_string($_POST['mcID'] ?? '');
        $nik = $conn->real_escape_string($_POST['nik_operator'] ?? '');

        if (empty($mcID) || empty($nik)) {
            echo json_encode(['status' => 'error', 'message' => 'Parameter tidak lengkap.']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM skill_matrix WHERE mcID = ? AND nik_operator = ?");
        $stmt->bind_param("ss", $mcID, $nik);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Operator berhasil dihapus dari mesin ini.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus operator: ' . $stmt->error]);
        }
        exit;
    }

    if ($action === 'bulk_save_operator') {
        if (!isset($user_role) || $user_role !== 'it') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $nik = $conn->real_escape_string($_POST['nik_operator'] ?? '');
        $level = intval($_POST['skill_level'] ?? 1);

        if (empty($nik)) {
            echo json_encode(['status' => 'error', 'message' => 'Operator wajib dipilih.']);
            exit;
        }
        if ($level < 1 || $level > 4) {
            echo json_encode(['status' => 'error', 'message' => 'Level skill harus bernilai 1 - 4.']);
            exit;
        }

        $res = $conn->query("SELECT mcID FROM master_mesin");
        $successCount = 0;
        
        $stmt = $conn->prepare("INSERT INTO skill_matrix (mcID, nik_operator, skill_level) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE skill_level = VALUES(skill_level)");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $stmt->bind_param("ssi", $row['mcID'], $nik, $level);
                if ($stmt->execute()) {
                    $successCount++;
                }
            }
        }
        echo json_encode(['status' => 'success', 'message' => "Berhasil menugaskan operator ke $successCount mesin!"]);
        exit;
    }

    if ($action === 'get_all_operators') {
        $sql = "SELECT nik, nama, bagian FROM master_operator ORDER BY nama ASC";
        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Action tidak valid.']);
    exit;
}

// =========================================================================
// PAGE DATA FETCHING
// =========================================================================
$searchQuery = trim($_GET['q'] ?? '');
$levelFilter = trim($_GET['level'] ?? 'ALL');

// Fetch master_mesin list
$sql_mesin = "SELECT mm.mcID, mm.id_mesin, mm.nama_mesin 
              FROM master_mesin mm 
              ORDER BY mm.nama_mesin ASC, mm.mcID ASC";
$res_mesin = $conn->query($sql_mesin);
$all_mesin = [];
if ($res_mesin) {
    while ($r = $res_mesin->fetch_assoc()) {
        $all_mesin[] = $r;
    }
}

// Pre-fetch skill matrix mappings grouped by mcID
$sql_matrix = "SELECT sm.mcID, sm.nik_operator, sm.skill_level, mo.nama, mo.bagian 
               FROM skill_matrix sm 
               JOIN master_operator mo ON sm.nik_operator = mo.nik 
               ORDER BY sm.skill_level DESC, mo.nama ASC";
$res_matrix = $conn->query($sql_matrix);
$matrix_data = [];
$total_assigned_records = 0;
if ($res_matrix) {
    while ($r = $res_matrix->fetch_assoc()) {
        $matrix_data[$r['mcID']][] = $r;
        $total_assigned_records++;
    }
}

// Get operator stats
$total_operator_count = 0;
$res_op_cnt = $conn->query("SELECT COUNT(*) as cnt FROM master_operator");
if ($res_op_cnt) {
    $total_operator_count = $res_op_cnt->fetch_assoc()['cnt'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skill Matrix Mesin - PT CNC</title>
    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --card-border: #333;
            --text-main: #ffffff;
            --text-muted: #a0a0a0;
            --primary: #00bfa5;
            --primary-hover: #00897b;
            --secondary: #3b82f6;
            --lvl-1-color: #64748b;
            --lvl-2-color: #0284c7;
            --lvl-3-color: #10b981;
            --lvl-4-color: #a855f7;
        }

        * { box-sizing: border-box; }
        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        .sidebar {
            position: fixed; top: 0; left: 0;
            transform: translateX(-100%);
            width: 280px; height: 100%;
            background: #1e1e1e; border-right: 1px solid #333;
            z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 15px rgba(0,0,0,0.5);
            display: flex; flex-direction: column;
        }
        .sidebar.open { transform: translateX(0); }
        #overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 999;
            opacity: 0; visibility: hidden; transition: opacity 0.3s;
        }
        #overlay.show { opacity: 1; visibility: visible; }
        .sidebar-header {
            padding: 20px; display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #333; background: #252525;
        }
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #fff; }
        .close-btn { background: none; border: none; color: #a0a0a0; font-size: 28px; cursor: pointer; }
        .sidebar-menu { display: flex; flex-direction: column; padding-top: 10px; }
        .sidebar-menu a {
            padding: 15px 25px; color: #a0a0a0; text-decoration: none; font-size: 14px;
            font-weight: bold; border-bottom: 1px solid #2a2a2a; display: flex; align-items: center; gap: 10px;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: #2a2a2a; color: var(--primary); border-left: 4px solid var(--primary); padding-left: 30px;
        }

        /* Top Header */
        .header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; border-bottom: 2px solid #333; padding-bottom: 15px;
            flex-wrap: wrap; gap: 15px;
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .header h1 { margin: 0; font-size: 24px; letter-spacing: 1.5px; color: #fff; }
        .btn-back { background: #334155; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; transition: 0.2s; }
        .btn-back:hover { background: #475569; }

        /* Stats Cards */
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px; margin-bottom: 25px;
        }
        .stat-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 10px; padding: 18px; display: flex; align-items: center; gap: 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 10px; background: rgba(0, 191, 165, 0.1);
            display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary);
        }
        .stat-info h4 { margin: 0 0 5px 0; font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info .value { font-size: 22px; font-weight: bold; color: #fff; }

        /* Controls Section */
        .controls-bar {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 10px; padding: 15px 20px; margin-bottom: 25px;
            display: flex; gap: 15px; flex-wrap: wrap; align-items: center; justify-content: space-between;
        }
        .search-box {
            display: flex; align-items: center; background: #121212; border: 1px solid #444;
            border-radius: 6px; padding: 0 12px; flex: 1; min-width: 250px;
        }
        .search-box input {
            background: transparent; border: none; color: #fff; padding: 10px;
            width: 100%; outline: none; font-size: 14px;
        }
        .filter-select {
            background: #121212; color: #fff; border: 1px solid #444;
            padding: 10px 14px; border-radius: 6px; font-size: 13px; outline: none; cursor: pointer;
        }
        .filter-select:focus, .search-box:focus-within { border-color: var(--primary); }
        .btn-primary { background: var(--primary); color: #000; }
        .btn-primary:hover { background: var(--primary-hover); }

        /* Level Legend */
        .legend-bar {
            display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: center;
        }
        .legend-title { font-size: 13px; font-weight: bold; color: var(--text-muted); }
        .lvl-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
            border-radius: 12px; font-size: 12px; font-weight: 600; color: #fff;
        }
        .lvl-1 { background: var(--lvl-1-color); }
        .lvl-2 { background: var(--lvl-2-color); }
        .lvl-3 { background: var(--lvl-3-color); }
        .lvl-4 { background: var(--lvl-4-color); box-shadow: 0 0 10px rgba(168, 85, 247, 0.4); }

        /* Machine Matrix Cards Grid */
        .matrix-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .machine-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 12px; padding: 20px; transition: transform 0.2s, border-color 0.2s;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden; cursor: pointer;
        }
        .machine-card:hover {
            transform: translateY(-4px); border-color: var(--primary);
            box-shadow: 0 8px 20px rgba(0, 191, 165, 0.15);
        }
        .machine-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 12px; border-bottom: 1px solid #2a2a2a; padding-bottom: 10px;
        }
        .machine-title { font-size: 17px; font-weight: bold; color: #fff; margin: 0 0 4px 0; }
        .machine-id { font-size: 12px; color: #90caf9; font-weight: bold; }
        .op-count-badge {
            background: #2a2a2a; border: 1px solid #444; color: #e2e8f0;
            padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }

        /* Operators List inside Card */
        .ops-chip-list {
            display: flex; flex-direction: column; gap: 8px; margin: 12px 0 18px 0;
            min-height: 70px;
        }
        .op-chip {
            background: #252525; border: 1px solid #333; border-radius: 8px;
            padding: 8px 12px; display: flex; justify-content: space-between; align-items: center;
        }
        .op-info { display: flex; flex-direction: column; }
        .op-name { font-size: 13px; font-weight: 600; color: #e2e8f0; }
        .op-dept { font-size: 11px; color: var(--text-muted); }
        .empty-op {
            text-align: center; color: #666; font-size: 12px; padding: 15px 0;
            font-style: italic; background: rgba(255,255,255,0.02); border-radius: 6px;
        }

        .card-actions { margin-top: auto; }
        .btn-manage {
            width: 100%; background: #1e3a8a; border: 1px solid #3b82f6; color: #fff;
            padding: 10px; border-radius: 8px; font-weight: bold; font-size: 13px;
            cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-manage:hover { background: #2563eb; }

        /* Modal Styles */
        .modal {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); z-index: 2000; display: none;
            align-items: center; justify-content: center; padding: 20px;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: #1e1e1e; border: 1px solid #444; border-radius: 12px;
            width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5); animation: modalIn 0.2s ease-out;
        }
        @keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .modal-header {
            padding: 18px 24px; border-bottom: 1px solid #333; background: #252525;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 18px; color: var(--primary); }
        .modal-close { background: none; border: none; color: #a0a0a0; font-size: 24px; cursor: pointer; }
        .modal-body { padding: 24px; }

        .section-title {
            font-size: 14px; font-weight: bold; color: #90caf9; margin: 0 0 12px 0;
            text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px;
        }

        /* Modal Operators Table */
        .modal-op-table {
            width: 100%; border-collapse: collapse; margin-bottom: 25px;
        }
        .modal-op-table th, .modal-op-table td {
            padding: 10px 12px; text-align: left; border-bottom: 1px solid #333; font-size: 13px;
        }
        .modal-op-table th { background: #252525; color: var(--text-muted); font-size: 11px; text-transform: uppercase; }

        /* Add Form */
        .form-box {
            background: #252525; border: 1px solid #333; border-radius: 10px; padding: 18px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; color: var(--text-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; background: #121212; border: 1px solid #444; color: #fff;
            padding: 10px 12px; border-radius: 6px; font-size: 13px; outline: none;
        }
        .form-control:focus { border-color: var(--primary); }

        /* Radio Skill Level Options */
        .skill-selector {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
        }
        .skill-option {
            background: #121212; border: 1px solid #333; border-radius: 8px; padding: 10px;
            cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 10px;
        }
        .skill-option:hover { border-color: #555; }
        .skill-option.selected { border-color: var(--primary); background: rgba(0, 191, 165, 0.1); }
        .skill-option input { display: none; }
        .skill-opt-title { font-size: 13px; font-weight: bold; color: #fff; display: flex; align-items: center; gap: 6px; }
        .skill-opt-desc { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        .btn-submit-op {
            width: 100%; background: var(--primary); color: #000; border: none;
            padding: 12px; border-radius: 8px; font-weight: bold; font-size: 14px;
            cursor: pointer; transition: 0.2s; margin-top: 10px;
        }
        .btn-submit-op:hover { background: var(--primary-hover); }

        .btn-delete-op {
            background: #ef4444; color: white; border: none; padding: 5px 10px;
            border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: bold;
        }
        .btn-delete-op:hover { background: #dc2626; }

        /* Toast Notifications */
        #toast-container {
            position: fixed; bottom: 20px; right: 20px; z-index: 3000; display: flex; flex-direction: column; gap: 10px;
        }
        .toast {
            background: #1e293b; color: white; padding: 12px 20px; border-radius: 8px;
            border-left: 4px solid var(--primary); font-size: 13px; font-weight: 500;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4); animation: toastIn 0.3s ease-out;
        }
        .toast.error { border-left-color: #ef4444; }
        @keyframes toastIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* Searchable Select Operator Styles */
        .searchable-select-container { position: relative; width: 100%; }
        .op-search-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; max-height: 220px;
            overflow-y: auto; background: #1a1a1a; border: 1px solid var(--primary);
            border-radius: 8px; z-index: 2500; display: none; box-shadow: 0 8px 25px rgba(0,0,0,0.7);
            margin-top: 4px;
        }
        .op-search-item {
            padding: 10px 14px; border-bottom: 1px solid #2a2a2a; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center; transition: background 0.15s;
        }
        .op-search-item:hover { background: #2a2a2a; color: var(--primary); }
        .op-search-item-info { display: flex; flex-direction: column; }
        .op-search-item-name { font-size: 13px; font-weight: bold; color: #fff; }
        .op-search-item-meta { font-size: 11px; color: var(--text-muted); }
        .op-selected-card {
            background: rgba(0, 191, 165, 0.12); border: 1px solid var(--primary);
            border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between;
            align-items: center; margin-top: 10px;
        }

        @media (max-width: 768px) {
            .skill-selector { grid-template-columns: 1fr; }
            .matrix-grid { grid-template-columns: 1fr; }
        }
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
            <?php endif; ?>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/settings_auth.php">🔒 Pengaturan Keamanan</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>admin/skill_matrix.php" class="active">🎯 Skill Matrix Mesin</a>
            <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
            <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <h1>🎯 SKILL MATRIX MESIN</h1>
        </div>
        <div>
            <a href="<?= BASE_URL ?>user/index.php" class="btn-back">← Dashboard</a>
        </div>
    </div>

    <!-- SUMMARY STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📟</div>
            <div class="stat-info">
                <h4>Total Mesin</h4>
                <div class="value"><?= count($all_mesin) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👨‍🔧</div>
            <div class="stat-info">
                <h4>Total Operator Master</h4>
                <div class="value"><?= $total_operator_count ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-info">
                <h4>Total Penugasan Skill</h4>
                <div class="value"><?= $total_assigned_records ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-info">
                <h4>Mesin Tercover Skill</h4>
                <div class="value"><?= count($matrix_data) ?> / <?= count($all_mesin) ?></div>
            </div>
        </div>
    </div>

    <!-- CONTROLS & SEARCH BAR -->
    <div class="controls-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; gap:10px; flex-grow:1; flex-wrap:wrap;">
            <div class="search-box" style="flex-grow:1; min-width:200px;">
                <span style="color:#777; margin-right:5px;">🔍</span>
                <input type="text" id="searchInput" placeholder="Cari berdasarkan nama mesin, ID mesin, atau nama operator..." onkeyup="filterCards()" style="width:calc(100% - 30px); background:transparent; border:none; color:#fff; outline:none;">
            </div>
            <select id="levelFilter" class="filter-select" onchange="filterCards()">
                <option value="ALL">Semua Mesin</option>
                <option value="HAS_OP">Memiliki Operator</option>
                <option value="NO_OP">Belum Ada Operator</option>
                <option value="EXPERT">Memiliki Expert (Lvl 4)</option>
            </select>
        </div>
        <?php if(isset($user_role) && $user_role === 'it'): ?>
        <button class="btn-primary" style="padding:10px 20px; font-weight:bold; border-radius:8px; border:none; cursor:pointer;" onclick="openBulkModal()">🚀 Tambah Masal (Semua Mesin)</button>
        <?php endif; ?>
    </div>

    <!-- LEVEL LEGEND -->
    <div class="legend-bar">
        <span class="legend-title">Tingkat Skill:</span>
        <span class="lvl-badge lvl-1">Level 1: Beginner ★☆☆☆</span>
        <span class="lvl-badge lvl-2">Level 2: Basic ★★☆☆</span>
        <span class="lvl-badge lvl-3">Level 3: Competent ★★★☆</span>
        <span class="lvl-badge lvl-4">Level 4: Expert ★★★★</span>
    </div>

    <!-- MACHINE MATRIX GRID -->
    <div class="matrix-grid" id="matrixGrid">
        <?php foreach ($all_mesin as $m): 
            $mcID = $m['mcID'];
            $id_mesin = $m['id_mesin'];
            $nama_mesin = $m['nama_mesin'];
            $assigned_ops = $matrix_data[$mcID] ?? [];
            $op_count = count($assigned_ops);

            $has_expert = false;
            foreach($assigned_ops as $op_check) {
                if($op_check['skill_level'] == 4) { $has_expert = true; break; }
            }
        ?>
            <div class="machine-card" 
                 data-id="<?= htmlspecialchars(strtolower($id_mesin . ' ' . $mcID)) ?>" 
                 data-nama="<?= htmlspecialchars(strtolower($nama_mesin)) ?>"
                 data-ops="<?= htmlspecialchars(strtolower(implode(' ', array_column($assigned_ops, 'nama')))) ?>"
                 data-count="<?= $op_count ?>"
                 data-expert="<?= $has_expert ? '1' : '0' ?>"
                 onclick="openManageModal('<?= htmlspecialchars($mcID, ENT_QUOTES) ?>')">
                 
                <div>
                    <div class="machine-header">
                        <div>
                            <h3 class="machine-title"><?= htmlspecialchars($nama_mesin) ?></h3>
                            <span class="machine-id">ID: <?= htmlspecialchars($id_mesin) ?> | Kode: <?= htmlspecialchars($mcID) ?></span>
                        </div>
                        <span class="op-count-badge">👥 <?= $op_count ?> Op</span>
                    </div>

                    <div class="ops-chip-list">
                        <?php if ($op_count > 0): ?>
                            <?php foreach (array_slice($assigned_ops, 0, 4) as $op): ?>
                                <div class="op-chip">
                                    <div class="op-info">
                                        <span class="op-name"><?= htmlspecialchars($op['nama']) ?></span>
                                        <span class="op-dept"><?= htmlspecialchars($op['bagian'] ?: 'Operator') ?></span>
                                    </div>
                                    <?php
                                        $lvl = $op['skill_level'];
                                        $lvlText = $lvl == 4 ? 'Expert ★★★★' : ($lvl == 3 ? 'Competent ★★★☆' : ($lvl == 2 ? 'Basic ★★☆☆' : 'Beginner ★☆☆☆'));
                                        $lvlClass = "lvl-" . $lvl;
                                    ?>
                                    <span class="lvl-badge <?= $lvlClass ?>"><?= $lvlText ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($op_count > 4): ?>
                                <div style="font-size: 11px; color: var(--text-muted); text-align: center;">+ <?= $op_count - 4 ?> operator lainnya...</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-op">Belum ada operator ditugaskan. Klik untuk menambahkan.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-actions">
                    <button class="btn-manage" onclick="event.stopPropagation(); openManageModal('<?= htmlspecialchars($mcID, ENT_QUOTES) ?>')">
                        <span>⚙️ Kelola Skill Operator</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- MODAL POPUP FOR OPERATOR MANAGEMENT -->
    <div id="manageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalMachineTitle">Kelola Operator</h3>
                    <span id="modalMachineId" style="font-size: 12px; color: #90caf9;"></span>
                </div>
                <button class="modal-close" onclick="closeManageModal()">×</button>
            </div>
            <div class="modal-body">
                <!-- ASSIGNED OPERATORS LIST -->
                <div class="section-title">📋 Operator Ditugaskan</div>
                <div id="assignedOpsContainer">
                    <table class="modal-op-table">
                        <thead>
                            <tr>
                                <th>NIK</th>
                                <th>Nama</th>
                                <th>Bagian</th>
                                <th>Level Skill</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="assignedOpsTable">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- ADD OPERATOR FORM -->
                <div class="section-title" style="margin-top: 20px;">➕ Tambah Operator ke Mesin Ini</div>
                <div class="form-box">
                    <input type="hidden" id="currentMcID">
                    <div class="form-group">
                        <label>Cari & Pilih Operator (Ketik NIK / Nama / Bagian)</label>
                        <div class="searchable-select-container">
                            <input type="hidden" id="selectedOperatorNik" value="">
                            <input type="text" id="opSearchInput" class="form-control" 
                                   placeholder="🔍 Ketik nama atau NIK operator..." 
                                   oninput="onOpSearchInput()" onfocus="onOpSearchInput()" autocomplete="off">
                            <div id="opSearchDropdown" class="op-search-dropdown"></div>
                        </div>
                        <div id="opSelectedBadge" style="display:none;" class="op-selected-card">
                            <div>
                                <div style="font-weight:bold; font-size:13px; color:#fff;" id="selectedOpName"></div>
                                <div style="font-size:11px; color:#90caf9;" id="selectedOpMeta"></div>
                            </div>
                            <button type="button" class="btn-delete-op" style="background:#475569;" onclick="clearSelectedOp()">❌ Ganti</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Pilih Level Skill</label>
                        <div class="skill-selector">
                            <label class="skill-option selected" onclick="selectSkillLevel(1)">
                                <input type="radio" name="skill_level_radio" value="1" checked>
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-1">Lvl 1</span> Beginner ★☆☆☆</div>
                                    <div class="skill-opt-desc">Baru belajar / dalam pengawasan</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectSkillLevel(2)">
                                <input type="radio" name="skill_level_radio" value="2">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-2">Lvl 2</span> Basic ★★☆☆</div>
                                    <div class="skill-opt-desc">Operasi dasar secara mandiri</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectSkillLevel(3)">
                                <input type="radio" name="skill_level_radio" value="3">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-3">Lvl 3</span> Competent ★★★☆</div>
                                    <div class="skill-opt-desc">Mandiri penuh & trouble solver</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectSkillLevel(4)">
                                <input type="radio" name="skill_level_radio" value="4">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-4">Lvl 4</span> Expert ★★★★</div>
                                    <div class="skill-opt-desc">Spesialis & instruktur mesin</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <button type="button" class="btn-submit-op" onclick="submitAddOperator()">💾 SIMPAN OPERATOR</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL POPUP FOR BULK ADD -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="bulkModalTitle">🚀 Tambah Operator ke Semua Mesin</h3>
                </div>
                <button class="modal-close" onclick="closeBulkModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="color: #fca5a5; margin: 0; font-size: 13px; font-weight: bold;">⚠️ PERHATIAN!</p>
                    <p style="color: #f87171; margin: 5px 0 0 0; font-size: 12px; line-height: 1.4;">Aksi ini akan menugaskan operator yang dipilih ke <b>SELURUH MESIN</b> yang ada di pabrik dengan level skill yang sama.</p>
                </div>
                
                <div class="form-box">
                    <div class="form-group">
                        <label>Cari & Pilih Operator (Ketik NIK / Nama / Bagian)</label>
                        <div class="searchable-select-container">
                            <input type="hidden" id="bulkSelectedOperatorNik" value="">
                            <input type="text" id="bulkOpSearchInput" class="form-control" 
                                   placeholder="🔍 Ketik nama atau NIK operator..." 
                                   oninput="onBulkOpSearchInput()" onfocus="onBulkOpSearchInput()" autocomplete="off">
                            <div id="bulkOpSearchDropdown" class="op-search-dropdown"></div>
                        </div>
                        <div id="bulkOpSelectedBadge" style="display:none;" class="op-selected-card">
                            <div>
                                <div style="font-weight:bold; font-size:13px; color:#fff;" id="bulkSelectedOpName"></div>
                                <div style="font-size:11px; color:#90caf9;" id="bulkSelectedOpMeta"></div>
                            </div>
                            <button type="button" class="btn-delete-op" style="background:#475569;" onclick="clearBulkSelectedOp()">❌ Ganti</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Pilih Level Skill</label>
                        <div class="skill-selector">
                            <label class="skill-option selected" onclick="selectBulkSkillLevel(1)">
                                <input type="radio" name="bulk_skill_level_radio" value="1" checked>
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-1">Lvl 1</span> Beginner ★☆☆☆</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectBulkSkillLevel(2)">
                                <input type="radio" name="bulk_skill_level_radio" value="2">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-2">Lvl 2</span> Basic ★★☆☆</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectBulkSkillLevel(3)">
                                <input type="radio" name="bulk_skill_level_radio" value="3">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-3">Lvl 3</span> Competent ★★★☆</div>
                                </div>
                            </label>
                            <label class="skill-option" onclick="selectBulkSkillLevel(4)">
                                <input type="radio" name="bulk_skill_level_radio" value="4">
                                <div>
                                    <div class="skill-opt-title"><span class="lvl-badge lvl-4">Lvl 4</span> Expert ★★★★</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <button type="button" class="btn-submit-op" id="btnBulkSubmit" onclick="submitBulkAddOperator()">💾 SIMPAN KE SEMUA MESIN</button>
                </div>
            </div>
        </div>
    </div>

    <!-- TOAST CONTAINER -->
    <div id="toast-container"></div>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("open");
            document.getElementById("overlay").classList.toggle("show");
        }

        // Live Filtering for Cards
        function filterCards() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const level = document.getElementById('levelFilter').value;
            const cards = document.querySelectorAll('.machine-card');

            cards.forEach(card => {
                const id = card.getAttribute('data-id');
                const nama = card.getAttribute('data-nama');
                const ops = card.getAttribute('data-ops');
                const count = parseInt(card.getAttribute('data-count'));
                const hasExpert = card.getAttribute('data-expert') === '1';

                const matchesSearch = !query || id.includes(query) || nama.includes(query) || ops.includes(query);
                
                let matchesLevel = true;
                if (level === 'HAS_OP') matchesLevel = count > 0;
                else if (level === 'NO_OP') matchesLevel = count === 0;
                else if (level === 'EXPERT') matchesLevel = hasExpert;

                if (matchesSearch && matchesLevel) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Modal Management
        function openManageModal(mcID) {
            document.getElementById('currentMcID').value = mcID;
            document.getElementById('manageModal').classList.add('active');
            fetchMachineDetails(mcID);
        }

        function closeManageModal() {
            document.getElementById('manageModal').classList.remove('active');
        }

        function selectSkillLevel(level) {
            document.querySelectorAll('#manageModal .skill-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`input[name="skill_level_radio"][value="${level}"]`).parentElement.classList.add('selected');
        }

        function fetchMachineDetails(mcID) {
            fetch(`skill_matrix.php?ajax=1&action=get_machine_details&mcID=${mcID}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('modalMachineTitle').innerText = data.mesin.nama_mesin;
                        document.getElementById('modalMachineId').innerText = `ID: ${data.mesin.id_mesin} | Kode: ${data.mesin.mcID}`;
                        
                        const tbody = document.getElementById('assignedOpsTable');
                        tbody.innerHTML = '';
                        if (data.assigned.length === 0) {
                            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:#666; font-style:italic;">Belum ada operator ditugaskan.</td></tr>`;
                        } else {
                            data.assigned.forEach(op => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${op.nik_operator}</td>
                                    <td style="font-weight:bold; color:#e2e8f0;">${op.nama}</td>
                                    <td>${op.bagian || '-'}</td>
                                    <td>
                                        <select class="form-control" style="width:auto; padding:5px; font-size:12px;" onchange="updateSkillLevel('${op.mcID}', '${op.nik_operator}', this.value)">
                                            <option value="1" ${op.skill_level == 1 ? 'selected' : ''}>1: Beginner</option>
                                            <option value="2" ${op.skill_level == 2 ? 'selected' : ''}>2: Basic</option>
                                            <option value="3" ${op.skill_level == 3 ? 'selected' : ''}>3: Competent</option>
                                            <option value="4" ${op.skill_level == 4 ? 'selected' : ''}>4: Expert</option>
                                        </select>
                                    </td>
                                    <td style="text-align:center;">
                                        <button class="btn-delete-op" onclick="deleteOperator('${op.mcID}', '${op.nik_operator}')">Hapus</button>
                                    </td>
                                `;
                                tbody.appendChild(tr);
                            });
                        }

                        // Store available operators globally for searchable autocomplete
                        currentAvailableOps = data.available || [];
                        clearSelectedOp();
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Gagal memuat data mesin.', 'error');
                });
        }

        let currentAvailableOps = [];

        function onOpSearchInput() {
            const query = document.getElementById('opSearchInput').value.toLowerCase().trim();
            const dropdown = document.getElementById('opSearchDropdown');
            dropdown.innerHTML = '';

            if (currentAvailableOps.length === 0) {
                dropdown.style.display = 'block';
                dropdown.innerHTML = '<div style="padding:12px; color:#777; font-size:12px; text-align:center;">Semua operator sudah ditugaskan pada mesin ini.</div>';
                return;
            }

            const filtered = currentAvailableOps.filter(op => 
                op.nik.toLowerCase().includes(query) || 
                op.nama.toLowerCase().includes(query) || 
                (op.bagian && op.bagian.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                dropdown.style.display = 'block';
                dropdown.innerHTML = '<div style="padding:12px; color:#777; font-size:12px; text-align:center;">Tidak ada operator yang cocok.</div>';
                return;
            }

            dropdown.style.display = 'block';
            filtered.slice(0, 40).forEach(op => {
                const item = document.createElement('div');
                item.className = 'op-search-item';
                item.innerHTML = `
                    <div class="op-search-item-info">
                        <span class="op-search-item-name">${op.nama}</span>
                        <span class="op-search-item-meta">NIK: ${op.nik} • ${op.bagian || 'Produksi'}</span>
                    </div>
                    <span style="font-size:11px; background:#2a2a2a; padding:3px 8px; border-radius:4px; color:#90caf9;">Pilih +</span>
                `;
                item.onclick = function() {
                    selectOperator(op);
                };
                dropdown.appendChild(item);
            });
        }

        function selectOperator(op) {
            document.getElementById('selectedOperatorNik').value = op.nik;
            document.getElementById('selectedOpName').innerText = `${op.nama} (${op.nik})`;
            document.getElementById('selectedOpMeta').innerText = `Bagian: ${op.bagian || 'Produksi'}`;
            document.getElementById('opSelectedBadge').style.display = 'flex';
            document.getElementById('opSearchInput').style.display = 'none';
            document.getElementById('opSearchDropdown').style.display = 'none';
        }

        function clearSelectedOp() {
            document.getElementById('selectedOperatorNik').value = '';
            document.getElementById('opSearchInput').value = '';
            document.getElementById('opSearchInput').style.display = 'block';
            document.getElementById('opSelectedBadge').style.display = 'none';
            document.getElementById('opSearchDropdown').style.display = 'none';
        }

        function submitAddOperator() {
            const mcID = document.getElementById('currentMcID').value;
            const nik = document.getElementById('selectedOperatorNik').value;
            const level = document.querySelector('input[name="skill_level_radio"]:checked').value;

            if (!nik) {
                showToast('Silakan cari dan pilih operator terlebih dahulu.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'save_operator');
            formData.append('mcID', mcID);
            formData.append('nik_operator', nik);
            formData.append('skill_level', level);

            fetch('skill_matrix.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        // Reload data untuk modal
                        openManageModal(mcID);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Terjadi kesalahan jaringan.', 'error');
                });
        }

        // ==========================================
        // BULK ADD LOGIC
        // ==========================================
        let allOperatorsGlobal = [];

        function openBulkModal() {
            document.getElementById('bulkModal').style.display = 'block';
            clearBulkSelectedOp();
            
            // Fetch all operators
            fetch('skill_matrix.php?ajax=1&action=get_all_operators')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        allOperatorsGlobal = data.data || [];
                    } else {
                        showToast('Gagal memuat daftar operator.', 'error');
                    }
                })
                .catch(err => console.error(err));
        }

        function closeBulkModal() {
            document.getElementById('bulkModal').style.display = 'none';
        }

        function onBulkOpSearchInput() {
            const query = document.getElementById('bulkOpSearchInput').value.toLowerCase().trim();
            const dropdown = document.getElementById('bulkOpSearchDropdown');
            dropdown.innerHTML = '';

            if (allOperatorsGlobal.length === 0) return;

            const filtered = allOperatorsGlobal.filter(op => 
                op.nik.toLowerCase().includes(query) || 
                op.nama.toLowerCase().includes(query) || 
                (op.bagian && op.bagian.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                dropdown.style.display = 'block';
                dropdown.innerHTML = '<div style="padding:12px; color:#777; font-size:12px; text-align:center;">Tidak ada operator yang cocok.</div>';
                return;
            }

            dropdown.style.display = 'block';
            filtered.slice(0, 40).forEach(op => {
                const item = document.createElement('div');
                item.className = 'op-search-item';
                item.innerHTML = `
                    <div class="op-search-item-info">
                        <span class="op-search-item-name">${op.nama}</span>
                        <span class="op-search-item-meta">NIK: ${op.nik} • ${op.bagian || 'Produksi'}</span>
                    </div>
                    <span style="font-size:11px; background:#2a2a2a; padding:3px 8px; border-radius:4px; color:#90caf9;">Pilih +</span>
                `;
                item.onclick = function() {
                    selectBulkOperator(op);
                };
                dropdown.appendChild(item);
            });
        }

        function selectBulkOperator(op) {
            document.getElementById('bulkSelectedOperatorNik').value = op.nik;
            document.getElementById('bulkSelectedOpName').innerText = `${op.nama} (${op.nik})`;
            document.getElementById('bulkSelectedOpMeta').innerText = `Bagian: ${op.bagian || 'Produksi'}`;
            document.getElementById('bulkOpSelectedBadge').style.display = 'flex';
            document.getElementById('bulkOpSearchInput').style.display = 'none';
            document.getElementById('bulkOpSearchDropdown').style.display = 'none';
        }

        function clearBulkSelectedOp() {
            document.getElementById('bulkSelectedOperatorNik').value = '';
            document.getElementById('bulkOpSearchInput').value = '';
            document.getElementById('bulkOpSearchInput').style.display = 'block';
            document.getElementById('bulkOpSelectedBadge').style.display = 'none';
            document.getElementById('bulkOpSearchDropdown').style.display = 'none';
        }

        function selectBulkSkillLevel(level) {
            document.querySelectorAll('#bulkModal .skill-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`input[name="bulk_skill_level_radio"][value="${level}"]`).parentElement.classList.add('selected');
        }

        function submitBulkAddOperator() {
            const nik = document.getElementById('bulkSelectedOperatorNik').value;
            const level = document.querySelector('input[name="bulk_skill_level_radio"]:checked').value;

            if (!nik) {
                showToast('Silakan cari dan pilih operator terlebih dahulu.', 'error');
                return;
            }

            const btn = document.getElementById('btnBulkSubmit');
            btn.innerText = '⏳ MENYIMPAN KE SEMUA MESIN...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'bulk_save_operator');
            formData.append('nik_operator', nik);
            formData.append('skill_level', level);

            fetch('skill_matrix.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast(data.message, 'error');
                        btn.innerText = '💾 SIMPAN KE SEMUA MESIN';
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Terjadi kesalahan jaringan.', 'error');
                    btn.innerText = '💾 SIMPAN KE SEMUA MESIN';
                    btn.disabled = false;
                });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.searchable-select-container')) {
                const drop = document.getElementById('opSearchDropdown');
                if (drop) drop.style.display = 'none';
                const drop2 = document.getElementById('bulkOpSearchDropdown');
                if (drop2) drop2.style.display = 'none';
            }
        });

        function updateSkillLevel(mcID, nik, newLevel) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'save_operator');
            formData.append('mcID', mcID);
            formData.append('nik_operator', nik);
            formData.append('skill_level', newLevel);

            fetch('skill_matrix.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast('Level skill berhasil diperbarui!');
                        fetchMachineDetails(mcID);
                    } else {
                        showToast(data.message, 'error');
                    }
                });
        }

        function deleteOperator(mcID, nik) {
            if (!confirm('Apakah Anda yakin ingin menghapus operator dari mesin ini?')) return;

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete_operator');
            formData.append('mcID', mcID);
            formData.append('nik_operator', nik);

            fetch('skill_matrix.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message);
                        fetchMachineDetails(mcID);
                    } else {
                        showToast(data.message, 'error');
                    }
                });
        }

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'error' ? 'error' : ''}`;
            toast.innerText = msg;
            container.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }

        // Close modal when clicking outside content
        window.onclick = function(event) {
            const modal = document.getElementById('manageModal');
            if (event.target === modal) {
                closeManageModal();
            }
        }
    </script>
</body>
</html>
