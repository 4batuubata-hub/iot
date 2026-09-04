<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

// Ensure assets/img/operator directory exists
$upload_dir = __DIR__ . '/../assets/foto_operator/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// =========================================================================
// AJAX HANDLERS
// =========================================================================
if (isset($_GET['ajax']) || isset($_POST['ajax']) || isset($_REQUEST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['action'] ?? '';

    if ($action === 'get_operators') {
        $draw = isset($_POST['draw']) ? intval($_POST['draw']) : (isset($_GET['draw']) ? intval($_GET['draw']) : 1);
        $start = isset($_POST['start']) ? intval($_POST['start']) : (isset($_GET['start']) ? intval($_GET['start']) : 0);
        $length = isset($_POST['length']) ? intval($_POST['length']) : (isset($_GET['length']) ? intval($_GET['length']) : 15);
        $search = isset($_POST['search']['value']) ? $_POST['search']['value'] : (isset($_GET['search']['value']) ? $_GET['search']['value'] : '');
        
        $order_col_idx = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : (isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 3);
        $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : (isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'asc');

        $columns = [
            0 => 'foto',
            1 => 'nik',
            2 => 'uid_kartu',
            3 => 'nama',
            4 => 'bagian'
        ];
        $order_col = $columns[$order_col_idx] ?? 'nama';
        if (!in_array(strtolower($order_dir), ['asc', 'desc'])) {
            $order_dir = 'asc';
        }

        $where = "1=1";
        if (!empty($search)) {
            $search = $conn->real_escape_string($search);
            $where .= " AND (nik LIKE '%$search%' OR nama LIKE '%$search%' OR bagian LIKE '%$search%' OR uid_kartu LIKE '%$search%')";
        }

        $resTotal = $conn->query("SELECT COUNT(nik) as total FROM master_operator");
        $recordsTotal = $resTotal ? $resTotal->fetch_assoc()['total'] : 0;

        $resFiltered = $conn->query("SELECT COUNT(nik) as total FROM master_operator WHERE $where");
        $recordsFiltered = $resFiltered ? $resFiltered->fetch_assoc()['total'] : 0;

        $sql = "SELECT * FROM master_operator WHERE $where ORDER BY $order_col $order_dir";
        if ($length > 0) {
            $sql .= " LIMIT $start, $length";
        }
        
        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
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

    if ($action === 'get_operator') {
        $nik = $conn->real_escape_string($_GET['nik'] ?? '');
        $res = $conn->query("SELECT * FROM master_operator WHERE nik = '$nik' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            echo json_encode(['status' => 'success', 'data' => $res->fetch_assoc()]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Operator tidak ditemukan']);
        }
        exit;
    }

    if ($action === 'save_operator') {
        $nik = $conn->real_escape_string($_POST['nik'] ?? '');
        $nama = $conn->real_escape_string($_POST['nama'] ?? '');
        $bagian = $conn->real_escape_string($_POST['bagian'] ?? '');
        $uid_kartu = $conn->real_escape_string($_POST['uid_kartu'] ?? '');
        $is_edit = isset($_POST['is_edit']) && $_POST['is_edit'] == '1';
        $old_nik = $conn->real_escape_string($_POST['old_nik'] ?? '');

        if (empty($nik) || empty($nama)) {
            echo json_encode(['status' => 'error', 'message' => 'NIK dan Nama wajib diisi.']);
            exit;
        }

        // Handle File Upload
        $foto_filename = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $file_parts = explode('.', $_FILES['foto']['name']);
            $ext = strtolower(end($file_parts));
            
            if (!in_array($ext, $allowed_ext)) {
                echo json_encode(['status' => 'error', 'message' => 'Format file foto tidak didukung.']);
                exit;
            }
            
            // Limit to 5MB
            if ($_FILES['foto']['size'] > 5242880) {
                echo json_encode(['status' => 'error', 'message' => 'Ukuran file foto maksimal 5MB.']);
                exit;
            }

            // Generate unique filename
            $foto_filename = $nik . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $foto_filename)) {
                echo json_encode(['status' => 'error', 'message' => 'Gagal mengunggah foto.']);
                exit;
            }
        }

        if ($is_edit) {
            // Cek apakah NIK diubah dan bentrok
            if ($nik !== $old_nik) {
                $cek = $conn->query("SELECT nik FROM master_operator WHERE nik = '$nik'");
                if ($cek->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'NIK baru sudah terdaftar untuk operator lain.']);
                    exit;
                }
            }

            // Dapatkan foto lama untuk dihapus jika diganti
            $old_foto = null;
            $res = $conn->query("SELECT foto FROM master_operator WHERE nik = '$old_nik'");
            if ($res && $res->num_rows > 0) {
                $old_foto = $res->fetch_assoc()['foto'];
            }

            if ($foto_filename) {
                // Hapus foto lama
                if ($old_foto && file_exists($upload_dir . $old_foto)) {
                    unlink($upload_dir . $old_foto);
                }
                $stmt = $conn->prepare("UPDATE master_operator SET nik=?, nama=?, bagian=?, foto=?, uid_kartu=? WHERE nik=?");
                $stmt->bind_param("ssssss", $nik, $nama, $bagian, $foto_filename, $uid_kartu, $old_nik);
            } else {
                $stmt = $conn->prepare("UPDATE master_operator SET nik=?, nama=?, bagian=?, uid_kartu=? WHERE nik=?");
                $stmt->bind_param("sssss", $nik, $nama, $bagian, $uid_kartu, $old_nik);
            }

            if ($stmt->execute()) {
                // Jika NIK berubah, perbarui referensi di skill_matrix (opsional, karena belum pakai ON UPDATE CASCADE)
                if ($nik !== $old_nik) {
                    $conn->query("UPDATE skill_matrix SET nik_operator = '$nik' WHERE nik_operator = '$old_nik'");
                }
                echo json_encode(['status' => 'success', 'message' => 'Data operator berhasil diperbarui.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui data: ' . $stmt->error]);
            }
        } else {
            // Cek NIK
            $cek = $conn->query("SELECT nik FROM master_operator WHERE nik = '$nik'");
            if ($cek->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'NIK sudah terdaftar.']);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO master_operator (nik, nama, bagian, foto, uid_kartu) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $nik, $nama, $bagian, $foto_filename, $uid_kartu);
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Operator baru berhasil ditambahkan.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data: ' . $stmt->error]);
            }
        }
        exit;
    }

    if ($action === 'delete_operator') {
        $nik = $conn->real_escape_string($_POST['nik'] ?? '');
        
        if (empty($nik)) {
            echo json_encode(['status' => 'error', 'message' => 'Parameter NIK tidak ditemukan.']);
            exit;
        }

        // Hapus foto
        $res = $conn->query("SELECT foto FROM master_operator WHERE nik = '$nik'");
        if ($res && $res->num_rows > 0) {
            $old_foto = $res->fetch_assoc()['foto'];
            if ($old_foto && file_exists($upload_dir . $old_foto)) {
                unlink($upload_dir . $old_foto);
            }
        }

        // Hapus dari skill matrix
        $conn->query("DELETE FROM skill_matrix WHERE nik_operator = '$nik'");

        // Hapus dari master_operator
        $stmt = $conn->prepare("DELETE FROM master_operator WHERE nik = ?");
        $stmt->bind_param("s", $nik);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Operator berhasil dihapus.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus operator: ' . $stmt->error]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Action tidak valid.']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Operator - PT CNC</title>
    
    <!-- DataTables & jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.css">
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.js"></script>

    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --card-border: #333;
            --text-main: #ffffff;
            --text-muted: #a0a0a0;
            --primary: #00bfa5;
            --primary-hover: #00897b;
            --danger: #ff1744;
            --danger-hover: #d50000;
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
            height: 100%;
            width: 250px;
            position: fixed;
            top: 0;
            left: -250px;
            background-color: #1a1a1a;
            overflow-x: hidden;
            transition: 0.3s;
            padding-top: 0;
            z-index: 1000;
            border-right: 1px solid var(--card-border);
            box-shadow: 5px 0 15px rgba(0,0,0,0.5);
        }
        .sidebar.open { transform: translateX(250px); }
        .sidebar-header {
            padding: 20px;
            background: #252525;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #fff; }
        .close-btn { background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; }
        .close-btn:hover { color: #fff; }
        .sidebar-menu { display: flex; flex-direction: column; padding-top: 10px; }
        .sidebar-menu a {
            padding: 15px 25px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: #333333;
            color: var(--primary);
            border-left: 4px solid var(--primary);
            padding-left: 30px;
        }
        #overlay {
            position: fixed;
            display: none;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.7);
            z-index: 999;
            cursor: pointer;
        }
        #overlay.show { display: block; }
        .menu-btn {
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 24px;
            cursor: pointer;
            padding: 0 15px 0 0;
        }

        /* Top Header */
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; border-bottom: 1px solid var(--card-border); padding-bottom: 15px; }
        .header-left { display: flex; align-items: center; }
        .header h1 { margin: 0; font-size: 22px; color: #fff; }

        /* Card Container */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        /* Buttons */
        .btn {
            padding: 8px 16px;
            background: #333;
            color: #fff;
            border: 1px solid #555;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        .btn:hover { background: #444; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #000; }
        .btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        .btn-danger { background: var(--danger); border-color: var(--danger); }
        .btn-danger:hover { background: var(--danger-hover); border-color: var(--danger-hover); }

        /* DataTables Custom Styling */
        table.dataTable { width: 100%; border-collapse: collapse; margin-top: 20px !important; color: #fff;}
        table.dataTable thead th { background-color: #252525; color: #ffffff; border-bottom: 2px solid var(--primary); padding: 12px; text-align: left; }
        table.dataTable tbody td { padding: 12px 10px; border-bottom: 1px solid #333; vertical-align: middle; }
        table.dataTable tbody tr:hover { background-color: #2c2c2c; }
        .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_processing, .dataTables_wrapper .dataTables_paginate {
            color: var(--text-muted); margin-bottom: 10px;
        }
        .dataTables_wrapper .dataTables_filter input, .dataTables_wrapper .dataTables_length select {
            background-color: #252525; color: #fff; border: 1px solid #444; border-radius: 4px; padding: 5px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button { color: var(--text-muted) !important; border-radius: 4px; border: 1px solid transparent; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #333 !important; color: #fff !important; border: 1px solid #555; }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: var(--primary) !important; color: #000 !important; border: 1px solid var(--primary); }

        /* OP Photo thumbnail */
        .op-photo-thumb {
            width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #444;
        }

        /* Modal Styling */
        .modal {
            display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto;
            background-color: rgba(0,0,0,0.8); backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: var(--card-bg); margin: 5% auto; padding: 25px; border: 1px solid var(--card-border);
            width: 100%; max-width: 500px; border-radius: 8px; position: relative;
        }
        .close-modal { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1; }
        .close-modal:hover { color: #fff; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 13px; font-weight: bold; }
        .form-control {
            width: 100%; padding: 10px; background: #252525; border: 1px solid #444; border-radius: 4px;
            color: #fff; font-family: inherit; font-size: 14px;
        }
        .form-control:focus { outline: none; border-color: var(--primary); }
        .modal-footer { margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #333; padding-top: 15px; }
        
        .photo-preview {
            width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 2px dashed #555;
            margin-top: 10px; display: none; background: #111;
        }
    </style>
</head>
<body>
    <!-- SIDEBAR NAVIGATION -->
    <div id="overlay" onclick="toggleSidebar()"></div>
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h2>PT CNC Menu</h2>
            <button class="close-btn" onclick="toggleSidebar()">&times;</button>
        </div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php">📜 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php">📈 Rangkuman OEE</a>
            <?php if(isset($user_role) && $user_role === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line</a>
                <a href="<?= BASE_URL ?>setting/pengaturan_jam.php">⏱️ Pengaturan Jam Kerja</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/settings_auth.php">🔒 Pengaturan Keamanan</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>admin/skill_matrix.php">🧠 Skill Matrix</a>
            <a href="<?= BASE_URL ?>admin/data_operator.php" class="active">🧑‍🔧 Data Operator</a>
            <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">&#9776;</button>
            <h1>Data Operator</h1>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openModal('add')">+ Tambah Operator</button>
        </div>
    </div>

    <div class="card">
        <table id="opTable" class="display nowrap">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">Foto</th>
                    <th>NIK</th>
                    <th>UID RFID</th>
                    <th>Nama Operator</th>
                    <th>Bagian</th>
                    <th style="text-align: center; width: 150px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <!-- Data will be loaded via AJAX -->
            </tbody>
        </table>
    </div>

    <!-- Modal Form -->
    <div id="opModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-top:0; border-bottom:1px solid #333; padding-bottom:10px;">Tambah Operator</h2>
            
            <form id="opForm" onsubmit="saveOperator(event)" enctype="multipart/form-data">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" value="save_operator">
                <input type="hidden" name="is_edit" id="is_edit" value="0">
                <input type="hidden" name="old_nik" id="old_nik" value="">

                <div class="form-group">
                    <label>NIK Operator</label>
                    <input type="text" class="form-control" name="nik" id="nik" required placeholder="Masukkan NIK">
                </div>
                <div class="form-group">
                    <label>UID RFID (Khusus Arduino Mega)</label>
                    <input type="text" class="form-control" name="uid_kartu" id="uid_kartu" placeholder="Contoh: A1B2C3D4">
                </div>
                <div class="form-group">
                    <label>Nama Lengkap</label>
                    <input type="text" class="form-control" name="nama" id="nama" required placeholder="Masukkan Nama Operator">
                </div>
                <div class="form-group">
                    <label>Bagian / Departemen</label>
                    <input type="text" class="form-control" name="bagian" id="bagian" placeholder="Contoh: Produksi, QA, dll">
                </div>
                <div class="form-group">
                    <label>Foto Profil (Opsional)</label>
                    <input type="file" class="form-control" name="foto" id="foto" accept="image/png, image/jpeg, image/jpg, image/webp" onchange="previewImage(this)">
                    <img id="preview" class="photo-preview" src="">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal()">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSave">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("open");
            document.getElementById("overlay").classList.toggle("show");
        }

        let table;

        $(document).ready(function() {
            loadTable();
        });

        function loadTable() {
            if (table) { table.destroy(); }
            table = $('#opTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "data_operator.php?ajax=1&action=get_operators",
                    "type": "POST"
                },
                "columns": [
                    { 
                        "data": "foto", 
                        "orderable": false,
                        "render": function(data, type, row) {
                            if (data) {
                                return `<div style="text-align:center"><img src="<?= BASE_URL ?>assets/foto_operator/${data}" class="op-photo-thumb" alt="Foto"></div>`;
                            }
                            return `<div style="text-align:center"><div class="op-photo-thumb" style="background:#333; display:inline-block; line-height:36px; text-align:center; color:#777;">?</div></div>`;
                        }
                    },
                    { "data": "nik" },
                    { 
                        "data": "uid_kartu",
                        "render": function(data) { return data ? data : '-'; }
                    },
                    { "data": "nama" },
                    { "data": "bagian" },
                    { 
                        "data": null,
                        "orderable": false,
                        "render": function(data, type, row) {
                            return `
                                <div style="text-align:center">
                                    <button class="btn" style="background:#252525; padding:5px 10px; font-size:12px;" onclick="openModal('edit', '${row.nik}')">Edit</button>
                                </div>
                            `;
                        }
                    }
                ],
                "language": { "search": "Cari Operator (NIK/Nama/Bagian):", "emptyTable": "Tidak ada data operator." },
                "pageLength": 15
            });
        }

        function openModal(mode, nik = '') {
            document.getElementById('opForm').reset();
            document.getElementById('preview').style.display = 'none';
            document.getElementById('preview').src = '';
            
            if (mode === 'add') {
                document.getElementById('modalTitle').innerText = 'Tambah Operator Baru';
                document.getElementById('is_edit').value = '0';
                document.getElementById('old_nik').value = '';
                document.getElementById('opModal').style.display = 'block';
            } else if (mode === 'edit') {
                document.getElementById('modalTitle').innerText = 'Edit Data Operator';
                document.getElementById('is_edit').value = '1';
                document.getElementById('old_nik').value = nik;
                
                // Fetch data
                $.ajax({
                    url: 'data_operator.php',
                    type: 'GET',
                    data: { ajax: 1, action: 'get_operator', nik: nik },
                    success: function(res) {
                        if (res.status === 'success') {
                            document.getElementById('nik').value = res.data.nik;
                            document.getElementById('uid_kartu').value = res.data.uid_kartu || '';
                            document.getElementById('nama').value = res.data.nama;
                            document.getElementById('bagian').value = res.data.bagian;
                            
                            if (res.data.foto) {
                                document.getElementById('preview').src = '<?= BASE_URL ?>assets/foto_operator/' + res.data.foto;
                                document.getElementById('preview').style.display = 'block';
                            }
                            
                            document.getElementById('opModal').style.display = 'block';
                        } else {
                            alert(res.message);
                        }
                    }
                });
            }
        }

        function closeModal() {
            document.getElementById('opModal').style.display = 'none';
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview').src = e.target.result;
                    document.getElementById('preview').style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function saveOperator(e) {
            e.preventDefault();
            let form = document.getElementById('opForm');
            let formData = new FormData(form);
            let btn = document.getElementById('btnSave');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';

            $.ajax({
                url: 'data_operator.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(res) {
                    if (res.status === 'success') {
                        closeModal();
                        table.ajax.reload(null, false);
                    } else {
                        alert(res.message || 'Terjadi kesalahan.');
                    }
                },
                error: function() {
                    alert('Gagal menghubungi server.');
                },
                complete: function() {
                    btn.disabled = false;
                    btn.innerText = 'Simpan Data';
                }
            });
        }

        function deleteOperator(nik, nama) {
            if (confirm(`Apakah Anda yakin ingin menghapus operator: ${nama} (${nik})?\nData skill matrix yang terkait dengan operator ini juga akan dihapus.`)) {
                $.ajax({
                    url: 'data_operator.php',
                    type: 'POST',
                    data: { ajax: 1, action: 'delete_operator', nik: nik },
                    success: function(res) {
                        if (res.status === 'success') {
                            table.ajax.reload(null, false);
                        } else {
                            alert(res.message);
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>
