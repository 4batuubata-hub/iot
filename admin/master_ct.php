<?php
session_start();
require_once __DIR__ . '/../auth_check.php';

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

// AJAX Handler untuk Update Cavity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_cavity') {
    if (!isset($user_role) || $user_role !== 'it') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $id = (int)$_POST['id'];
    $cav = (int)$_POST['cavity'];
    $stmt = $conn->prepare("UPDATE master_ct SET cavity = ? WHERE id = ?");
    $stmt->bind_param("ii", $cav, $id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

// AJAX Handler untuk Update Kode Finish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_kode_finish') {
    if (!isset($user_role) || $user_role !== 'it') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $id = (int)$_POST['id'];
    $kode_finish = $_POST['kode_finish'];
    $stmt = $conn->prepare("UPDATE master_ct SET kode_finish = ? WHERE id = ?");
    $stmt->bind_param("si", $kode_finish, $id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

// AJAX Handler untuk Import CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (!isset($user_role) || $user_role !== 'it') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengupload file.']);
        exit;
    }
    
    $tmpName = $_FILES['csv_file']['tmp_name'];
    $file = fopen($tmpName, "r");
    if (!$file) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak bisa membaca file.']);
        exit;
    }

    // Deteksi Delimiter (Tab, Koma, atau Titik Koma)
    $firstLine = fgets($file);
    $delimiter = ',';
    if (strpos($firstLine, "\t") !== false) $delimiter = "\t";
    elseif (strpos($firstLine, ";") !== false) $delimiter = ";";
    rewind($file);

    // Truncate tabel
    $conn->query("TRUNCATE TABLE master_ct");

    // Skip header
    fgetcsv($file, 0, $delimiter);

    $stmt = $conn->prepare("INSERT INTO master_ct (kode, kode_finish, part_name, part_number, proses_name, proses_description, customer, line, ct_pcs, ct_jam, cavity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $success = 0; $failed = 0;

    while (($row = fgetcsv($file, 0, $delimiter)) !== FALSE) {
        if (count($row) < 12) continue; // Skip incomplete
        $kode = trim($row[1]);
        if (empty($kode)) continue;
        
        $kode_finish = trim($row[2]);
        $part_name = trim($row[3]);
        $part_number = trim($row[4]);
        $proses_name = trim($row[5]);
        $proses_desc = trim($row[6]);
        $customer = trim($row[7]);
        $line = trim($row[8]);
        $ct_pcs = (float)$row[9];
        $ct_jam = (float)$row[10];
        $cavity = (int)$row[11];

        $stmt->bind_param("ssssssssddi", $kode, $kode_finish, $part_name, $part_number, $proses_name, $proses_desc, $customer, $line, $ct_pcs, $ct_jam, $cavity);
        if ($stmt->execute()) {
            $success++;
        } else {
            $failed++;
        }
    }
    fclose($file);
    echo json_encode(['status' => 'success', 'message' => "Import selesai! Sukses: $success baris. Gagal: $failed baris."]);
    exit;
}

// AJAX Handler untuk Add Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    if (!isset($user_role) || $user_role !== 'it') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $kode = $_POST['kode'];
    
    // Check duplikat
    $cek = $conn->prepare("SELECT id FROM master_ct WHERE kode = ?");
    $cek->bind_param("s", $kode);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Kode Proses sudah ada di database!']);
        exit;
    }

    $kode_finish = $_POST['kode_finish'];
    $part_name = $_POST['part_name'];
    $part_number = $_POST['part_number'];
    $proses_name = $_POST['proses_name'];
    $proses_description = $_POST['proses_description'];
    $customer = $_POST['customer'];
    $line = $_POST['line'];
    $ct_pcs = (float)$_POST['ct_pcs'];
    $ct_jam = (float)$_POST['ct_jam'];
    $cavity = (int)$_POST['cavity'];

    $stmt = $conn->prepare("INSERT INTO master_ct (kode, kode_finish, part_name, part_number, proses_name, proses_description, customer, line, ct_pcs, ct_jam, cavity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssssddi", $kode, $kode_finish, $part_name, $part_number, $proses_name, $proses_description, $customer, $line, $ct_pcs, $ct_jam, $cavity);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

// AJAX Handler untuk DataTables (Server-Side)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['action']) && $_GET['action'] === 'get_master_ct') {
    header('Content-Type: application/json; charset=utf-8');
    
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : (isset($_GET['draw']) ? intval($_GET['draw']) : 1);
    $start = isset($_POST['start']) ? intval($_POST['start']) : (isset($_GET['start']) ? intval($_GET['start']) : 0);
    $length = isset($_POST['length']) ? intval($_POST['length']) : (isset($_GET['length']) ? intval($_GET['length']) : 50);
    $search = isset($_POST['search']['value']) ? $_POST['search']['value'] : (isset($_GET['search']['value']) ? $_GET['search']['value'] : '');
    
    $order_col_idx = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : (isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 1);
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : (isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'asc');

    $columns = [
        0 => 'id', 
        1 => 'kode',
        2 => 'kode_finish',
        3 => 'part_name',
        4 => 'part_number',
        5 => 'proses_name',
        6 => 'proses_description',
        7 => 'customer',
        8 => 'line',
        9 => 'ct_pcs',
        10 => 'ct_jam',
        11 => 'cavity'
    ];
    
    $order_col = $columns[$order_col_idx] ?? 'kode';
    if (!in_array(strtolower($order_dir), ['asc', 'desc'])) {
        $order_dir = 'asc';
    }

    $filter_customer = isset($_POST['filter_customer']) ? $_POST['filter_customer'] : '';
    $filter_line = isset($_POST['filter_line']) ? $_POST['filter_line'] : '';
    $filter_part = isset($_POST['filter_part']) ? $_POST['filter_part'] : '';

    $where = "1=1";
    if (!empty($search)) {
        $search = $conn->real_escape_string($search);
        $where .= " AND (kode LIKE '%$search%' OR kode_finish LIKE '%$search%' OR part_name LIKE '%$search%' OR part_number LIKE '%$search%' OR proses_name LIKE '%$search%' OR customer LIKE '%$search%' OR line LIKE '%$search%')";
    }
    
    if (!empty($filter_customer)) {
        $where .= " AND customer = '" . $conn->real_escape_string($filter_customer) . "'";
    }
    if (!empty($filter_line)) {
        $where .= " AND line = '" . $conn->real_escape_string($filter_line) . "'";
    }
    if (!empty($filter_part)) {
        $val = $conn->real_escape_string($filter_part);
        $where .= " AND (part_name LIKE '%$val%' OR part_number LIKE '%$val%')";
    }

    $resTotal = $conn->query("SELECT COUNT(id) as total FROM master_ct");
    $recordsTotal = $resTotal ? $resTotal->fetch_assoc()['total'] : 0;

    $resFiltered = $conn->query("SELECT COUNT(id) as total FROM master_ct WHERE $where");
    $recordsFiltered = $resFiltered ? $resFiltered->fetch_assoc()['total'] : 0;

    $sql = "SELECT * FROM master_ct WHERE $where ORDER BY $order_col $order_dir";
    if ($length > 0) {
        $sql .= " LIMIT $start, $length";
    }
    
    $res = $conn->query($sql);
    $data = [];
    if ($res) {
        $no = $start + 1;
        while ($r = $res->fetch_assoc()) {
            $r['no'] = $no++;
            $r['is_it_role'] = (isset($user_role) && $user_role === 'it') ? true : false;
            $data[] = $r;
        }
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $data
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Master CT - PT CNC</title>
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    
    <style>
        :root { 
            --bg-color: #000000; 
            --card-bg: #111111; 
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
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
        .header { display: flex; align-items: center; gap: 15px; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; }
        .menu-btn { background: var(--card-bg); border: 1px solid var(--border-color); color: white; border-radius: 8px; width: 40px; height: 40px; font-size: 20px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .menu-btn:hover { background: #334155; }
        .header h1 { margin: 0; font-size: 22px; letter-spacing: 1px; font-weight: 700; background: linear-gradient(90deg, #60a5fa, #a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        /* DATATABLE OVERRIDES */
        .table-container { background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); overflow-x: auto; margin-top: 15px; }
        table.dataTable { border-collapse: collapse; width: 100%; color: var(--text-main); }
        table.dataTable thead th { background: #1e293b; border-bottom: 2px solid var(--primary); padding: 12px 10px; font-size: 13px; text-align: left; vertical-align: middle; border: 1px solid var(--border-color) !important; color: white; font-weight: bold; }
        table.dataTable tbody td { text-align: left; border-bottom: 1px solid var(--border-color); padding: 10px; font-size: 13px; border: 1px solid var(--border-color) !important; vertical-align: middle; }
        table.dataTable tbody tr { background: transparent !important; }
        table.dataTable tbody tr:hover { background: rgba(59, 130, 246, 0.1) !important; }
        .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_processing, .dataTables_wrapper .dataTables_paginate { color: var(--text-muted) !important; margin-bottom: 15px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { color: var(--text-main) !important; border-radius: 4px; padding: 5px 10px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: var(--primary) !important; border: 1px solid var(--primary) !important; color: white !important; }
        .dataTables_wrapper .dataTables_filter input { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 6px; border-radius: 4px; margin-left: 8px;}
        .dataTables_wrapper .dataTables_length select { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 4px; border-radius: 4px; }
        
        .dt-buttons .dt-button { background: #10b981 !important; color: white !important; border: none !important; border-radius: 6px !important; font-weight: bold; padding: 6px 15px !important; margin-bottom: 15px; transition: 0.3s; }
        .dt-buttons .dt-button:hover { background: #059669 !important; }
        
        .badge { background: #334155; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: #60a5fa;}
        
        .cavity-input { width: 60px; background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 5px; border-radius: 6px; text-align: center; transition: border-color 0.3s; }
        .cavity-input:focus { outline: none; border-color: var(--primary); }
        .kode-finish-input { width: 100px; background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 5px; border-radius: 6px; text-align: center; transition: border-color 0.3s; }
        .kode-finish-input:focus { outline: none; border-color: var(--primary); }

        /* MODAL */
        .modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px); }
        .modal-content { background-color: var(--card-bg); margin: 5% auto; padding: 25px; border: 1px solid var(--border-color); width: 600px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: white; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: white; }
        .close-modal { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .close-modal:hover { color: white; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px; color: var(--text-muted); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; background: #1e293b; border: 1px solid var(--border-color); color: white; border-radius: 6px; box-sizing: border-box; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        .btn-submit { width: 100%; padding: 12px; background: var(--primary); color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-submit:hover { background: #2563eb; }
        
        .btn-add { background: var(--primary) !important; color: white !important; margin-left: 10px; }
        .btn-add:hover { background: #2563eb !important; }

        /* FILTER BAR */
        .filter-bar { background: var(--card-bg); padding: 15px 20px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 15px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-item { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 200px; }
        .filter-item label { font-size: 12px; color: var(--text-muted); font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-item select, .filter-item input { background: #1e293b; color: white; border: 1px solid var(--border-color); padding: 10px; border-radius: 6px; outline: none; transition: 0.3s; font-family: inherit; font-size: 14px;}
        .filter-item select:focus, .filter-item input:focus { border-color: var(--primary); }
        .filter-actions { display: flex; gap: 10px; flex: 1; min-width: 200px; align-items: flex-end; }
        .filter-actions button { padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; transition: 0.3s; flex: 1; font-size: 14px;}
        .btn-apply { background: var(--primary); color: white; }
        .btn-apply:hover { background: #2563eb; }
        .btn-clear { background: #334155; color: white; }
        .btn-clear:hover { background: #475569; }
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
                <a href="<?= BASE_URL ?>admin/master_ct.php" class="active">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>logout.php" style="color: #ef4444; margin-top: 20px;">🚪 Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="header">
        <button class="menu-btn" onclick="toggleSidebar()">☰</button>
        <h1>DAFTAR MASTER CYCLE TIME (CT)</h1>
    </div>

    <?php
    $qCust = $conn->query("SELECT DISTINCT customer FROM master_ct WHERE customer != '' ORDER BY customer ASC");
    $qLine = $conn->query("SELECT DISTINCT line FROM master_ct WHERE line != '' ORDER BY line ASC");
    ?>
    <div class="filter-bar">
        <div class="filter-item">
            <label>Customer</label>
            <select id="filter_customer">
                <option value="">-- Semua Customer --</option>
                <?php while($c = $qCust->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($c['customer']) ?>"><?= htmlspecialchars($c['customer']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-item">
            <label>Line</label>
            <select id="filter_line">
                <option value="">-- Semua Line --</option>
                <?php while($l = $qLine->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($l['line']) ?>"><?= htmlspecialchars($l['line']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-item">
            <label>Nama / No. Part</label>
            <input type="text" id="filter_part" placeholder="Ketik kata kunci...">
        </div>
        <div class="filter-actions">
            <button class="btn-apply" onclick="applyFilters()">🔍 Terapkan</button>
            <button class="btn-clear" onclick="resetFilters()">✖ Reset</button>
        </div>
    </div>

    <div class="table-container">
        <table id="ctTable" class="display nowrap" style="width:100%">
            <thead>
                <tr>
                    <th>NO</th>
                    <th>KODE PROSES</th>
                    <th>KODE FINISH</th>
                    <th>NAMA PART</th>
                    <th>NO. PART</th>
                    <th>PROSES NAME</th>
                    <th>DESKRIPSI PROSES</th>
                    <th>CUSTOMER</th>
                    <th>LINE</th>
                    <th>CT (Pcs)</th>
                    <th>CT (Jam)</th>
                    <th>CAVITY</th>
                </tr>
            </thead>
            <tbody>
            <tbody>
            </tbody>
        </table>
    </div>

    <!-- Import CSV Modal -->
    <div id="importCsvModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3>📥 Import Data Master CT</h3>
                <span class="close-modal" onclick="closeImportModal()">&times;</span>
            </div>
            <form id="importCsvForm" enctype="multipart/form-data">
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="color: #fca5a5; margin: 0; font-size: 13px; font-weight: bold;">⚠️ PERINGATAN!</p>
                    <p style="color: #f87171; margin: 5px 0 0 0; font-size: 12px; line-height: 1.4;">Melakukan import akan MENGHAPUS seluruh data Master CT yang ada saat ini dan menggantinya dengan data dari file Anda.</p>
                </div>
                <div class="form-group">
                    <label>Pilih File (.csv / .txt)</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv, .txt" required style="background: #0f172a; padding: 15px; cursor: pointer;">
                </div>
                <button type="submit" class="btn-submit" id="btnImportSubmit">🚀 Eksekusi Import</button>
            </form>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>➕ Tambah Produk Master CT</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <form id="addProductForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Kode Proses *</label>
                        <input type="text" id="add_kode" required placeholder="Contoh: 7011">
                    </div>
                    <div class="form-group">
                        <label>Kode Finish</label>
                        <input type="text" id="add_kode_finish" placeholder="Opsional">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Part Name *</label>
                        <input type="text" id="add_part_name" required>
                    </div>
                    <div class="form-group">
                        <label>Part Number *</label>
                        <input type="text" id="add_part_number" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Proses Name *</label>
                        <input type="text" id="add_proses_name" required>
                    </div>
                    <div class="form-group">
                        <label>Line</label>
                        <input type="text" id="add_line" value="-">
                    </div>
                </div>
                <div class="form-group">
                    <label>Deskripsi Proses</label>
                    <textarea id="add_proses_description" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Customer</label>
                    <input type="text" id="add_customer" value="INTERNAL">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>CT Pcs (Detik) *</label>
                        <input type="number" id="add_ct_pcs" step="0.1" required>
                    </div>
                    <div class="form-group">
                        <label>CT Jam (Pcs/Jam) *</label>
                        <input type="number" id="add_ct_jam" step="0.1" required>
                    </div>
                    <div class="form-group">
                        <label>Cavity *</label>
                        <input type="number" id="add_cavity" value="1" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">💾 Simpan Data</button>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

    <script>
        function toggleSidebar() { 
            document.getElementById("sidebar").classList.toggle("open"); 
            document.getElementById("overlay").classList.toggle("show"); 
        }

        $(document).ready(function() {
            var buttonsConfig = [
                { extend: 'excelHtml5', text: '📥 Export ke Excel', className: 'btn-export' },
                { extend: 'csvHtml5', text: '📄 Export ke CSV' }
            ];
            <?php if (isset($user_role) && $user_role === 'it'): ?>
            buttonsConfig.push({
                text: '➕ Tambah Produk',
                className: 'btn-add',
                action: function ( e, dt, node, config ) {
                    $('#addProductModal').css('display', 'block');
                }
            });
            buttonsConfig.push({
                text: '📥 Import CSV',
                className: 'btn-add',
                action: function ( e, dt, node, config ) {
                    $('#importCsvModal').css('display', 'block');
                }
            });
            <?php endif; ?>

            $('#ctTable').DataTable({
                dom: 'Bfrtip',
                buttons: buttonsConfig,
                pageLength: 50,
                scrollX: true,
                processing: true,
                serverSide: true,
                ajax: {
                    url: 'master_ct.php?ajax=1&action=get_master_ct',
                    type: 'POST',
                    data: function(d) {
                        d.filter_customer = $('#filter_customer').val();
                        d.filter_line = $('#filter_line').val();
                        d.filter_part = $('#filter_part').val();
                    }
                },
                columns: [
                    { data: 'no', orderable: false },
                    { 
                        data: 'kode',
                        render: function(data) {
                            return '<span class="badge">' + (data ? data.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;") : '') + '</span>';
                        }
                    },
                    {
                        data: 'kode_finish',
                        render: function(data, type, row) {
                            var safeData = data ? data.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;") : '';
                            if (row.is_it_role) {
                                return '<div style="text-align:center;"><input type="text" class="kode-finish-input" data-id="'+row.id+'" value="'+safeData+'" placeholder="Kosong"></div>';
                            } else {
                                return '<div style="text-align:center;"><span class="badge" style="background:#0f172a; border: 1px solid #334155; color: #cbd5e1;">'+(safeData||'-')+'</span></div>';
                            }
                        }
                    },
                    { 
                        data: 'part_name',
                        render: function(data) {
                            return '<span style="font-weight:bold; color: #fff;">' + (data ? data.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;") : '') + '</span>';
                        }
                    },
                    { data: 'part_number' },
                    { data: 'proses_name' },
                    { data: 'proses_description' },
                    { data: 'customer' },
                    { data: 'line' },
                    { 
                        data: 'ct_pcs',
                        render: function(data) {
                            return '<div style="color: var(--primary); font-weight:bold; text-align:center;">' + data + 's</div>';
                        }
                    },
                    { 
                        data: 'ct_jam',
                        render: function(data) {
                            return '<div style="text-align:center;">' + data + '</div>';
                        }
                    },
                    {
                        data: 'cavity',
                        render: function(data, type, row) {
                            if (row.is_it_role) {
                                return '<div style="text-align:center;"><input type="number" class="cavity-input" data-id="'+row.id+'" value="'+data+'" min="1"></div>';
                            } else {
                                return '<div style="text-align:center;">' + data + '</div>';
                            }
                        }
                    }
                ]
            });

            // Update Cavity
            $(document).on('change', '.cavity-input', function() {
                var input = $(this);
                var id = input.data('id');
                var val = input.val();
                
                $.ajax({
                    url: 'master_ct.php',
                    type: 'POST',
                    data: { action: 'update_cavity', id: id, cavity: val },
                    success: function(res) {
                        try {
                            var data = JSON.parse(res);
                            if(data.status === 'success') {
                                input.css('border-color', '#10b981'); // Green success flash
                                setTimeout(function(){ input.css('border-color', '#334155'); }, 1500);
                            } else {
                                alert('Gagal update: ' + data.message);
                            }
                        } catch (e) {
                            alert('Respons server tidak valid.');
                        }
                    },
                    error: function() {
                        alert('Terjadi kesalahan jaringan.');
                    }
                });
            });

            // Update Kode Finish
            $(document).on('change', '.kode-finish-input', function() {
                var input = $(this);
                var id = input.data('id');
                var val = input.val();
                
                $.ajax({
                    url: 'master_ct.php',
                    type: 'POST',
                    data: { action: 'update_kode_finish', id: id, kode_finish: val },
                    success: function(res) {
                        try {
                            var data = JSON.parse(res);
                            if(data.status === 'success') {
                                input.css('border-color', '#10b981'); 
                                setTimeout(function(){ input.css('border-color', '#334155'); }, 1500);
                            } else {
                                alert('Gagal update: ' + data.message);
                            }
                        } catch (e) {
                            alert('Respons server tidak valid.');
                        }
                    },
                    error: function() {
                        alert('Terjadi kesalahan jaringan.');
                    }
                });
            });

            // Modal Logic
            window.closeModal = function() {
                $('#addProductModal').css('display', 'none');
                $('#addProductForm')[0].reset();
            }
            window.closeImportModal = function() {
                $('#importCsvModal').css('display', 'none');
                $('#importCsvForm')[0].reset();
            }

            // Close when clicking outside modal
            $(window).click(function(e) {
                if ($(e.target).is('#addProductModal')) {
                    closeModal();
                }
                if ($(e.target).is('#importCsvModal')) {
                    closeImportModal();
                }
            });

            // Submit Form Import CSV
            $('#importCsvForm').submit(function(e) {
                e.preventDefault();
                var formData = new FormData();
                formData.append('action', 'import_csv');
                formData.append('csv_file', $('#csv_file')[0].files[0]);

                $('#btnImportSubmit').text('⏳ Mengimport...').prop('disabled', true).css('background', '#64748b');

                $.ajax({
                    url: 'master_ct.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        try {
                            var json = JSON.parse(res);
                            if(json.status === 'success') {
                                alert(json.message);
                                location.reload();
                            } else {
                                alert('Gagal: ' + json.message);
                                $('#btnImportSubmit').text('🚀 Eksekusi Import').prop('disabled', false).css('background', 'var(--primary)');
                            }
                        } catch (e) {
                            alert('Respons server tidak valid.');
                            $('#btnImportSubmit').text('🚀 Eksekusi Import').prop('disabled', false).css('background', 'var(--primary)');
                        }
                    },
                    error: function() {
                        alert('Terjadi kesalahan jaringan.');
                        $('#btnImportSubmit').text('🚀 Eksekusi Import').prop('disabled', false).css('background', 'var(--primary)');
                    }
                });
            });

            // Submit Form Add Product
            $('#addProductForm').submit(function(e) {
                e.preventDefault();
                var data = {
                    action: 'add_product',
                    kode: $('#add_kode').val(),
                    kode_finish: $('#add_kode_finish').val(),
                    part_name: $('#add_part_name').val(),
                    part_number: $('#add_part_number').val(),
                    proses_name: $('#add_proses_name').val(),
                    line: $('#add_line').val(),
                    proses_description: $('#add_proses_description').val(),
                    customer: $('#add_customer').val(),
                    ct_pcs: $('#add_ct_pcs').val(),
                    ct_jam: $('#add_ct_jam').val(),
                    cavity: $('#add_cavity').val()
                };

                $.ajax({
                    url: 'master_ct.php',
                    type: 'POST',
                    data: data,
                    success: function(res) {
                        try {
                            var json = JSON.parse(res);
                            if(json.status === 'success') {
                                alert('Berhasil menambahkan produk!');
                                location.reload();
                            } else {
                                alert('Gagal: ' + json.message);
                            }
                        } catch (e) {
                            alert('Respons server tidak valid.');
                        }
                    },
                    error: function() {
                        alert('Terjadi kesalahan jaringan.');
                    }
                });
            });

            window.applyFilters = function() {
                $('#ctTable').DataTable().ajax.reload();
            };
            
            window.resetFilters = function() {
                $('#filter_customer').val('');
                $('#filter_line').val('');
                $('#filter_part').val('');
                $('#ctTable').DataTable().search('').ajax.reload();
            };
        });
    </script>
</body>
</html>
