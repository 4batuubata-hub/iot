<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// 1. Ensure DB Tables exist and sync
$conn->query("CREATE TABLE IF NOT EXISTS master_template_jam (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_template VARCHAR(100) UNIQUE NOT NULL,
    deskripsi VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS master_jam_statis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_template VARCHAR(100) NOT NULL,
    shift VARCHAR(50) NOT NULL,
    rentang_jam VARCHAR(50) NOT NULL,
    menit_efektif INT NOT NULL DEFAULT 60,
    hari VARCHAR(50) NOT NULL DEFAULT 'SETIAP HARI',
    urutan INT NOT NULL DEFAULT 1
)");

// Initial table seeding: only seed if master_template_jam is completely empty
$check_empty = $conn->query("SELECT COUNT(*) as cnt FROM master_template_jam");
if ($check_empty && $check_empty->fetch_assoc()['cnt'] == 0) {
    $conn->query("INSERT IGNORE INTO master_template_jam (nama_template) 
                  SELECT DISTINCT nama_template FROM master_jam_statis 
                  WHERE nama_template != '' AND nama_template IS NOT NULL");
                  
    $check_empty2 = $conn->query("SELECT COUNT(*) as cnt FROM master_template_jam");
    if ($check_empty2 && $check_empty2->fetch_assoc()['cnt'] == 0) {
        $conn->query("INSERT INTO master_template_jam (nama_template, deskripsi) VALUES ('DEFAULT', 'Template Shift Standard (07:00 - 16:00)')");
    }
}

$pesan = "";

// 2. Process Add New Template
if (isset($_POST['tambah_template'])) {
    $nama_raw = trim($_POST['nama_template'] ?? '');
    $nama_template = strtoupper($conn->real_escape_string($nama_raw));
    $deskripsi = $conn->real_escape_string($_POST['deskripsi'] ?? '');

    if (empty($nama_template)) {
        $pesan = "<div class='alert alert-danger'>Nama template tidak boleh kosong!</div>";
    } else {
        $check = $conn->query("SELECT id FROM master_template_jam WHERE nama_template = '$nama_template' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $pesan = "<div class='alert alert-danger'>Template dengan nama '$nama_template' sudah ada!</div>";
        } else {
            $sql_ins = "INSERT INTO master_template_jam (nama_template, deskripsi) VALUES ('$nama_template', '$deskripsi')";
            if ($conn->query($sql_ins)) {
                // Redirect straight to detail view for this newly added template!
                $encoded_name = urlencode($nama_template);
                echo "<script>alert('Template $nama_template berhasil dibuat! Silakan atur jam kerjanya.'); window.location.href='pengaturan_jam_detail.php?template=$encoded_name';</script>";
                exit;
            } else {
                $pesan = "<div class='alert alert-danger'>Gagal menambahkan template: " . $conn->error . "</div>";
            }
        }
    }
}

// 3. Process Edit Template Name / Description
if (isset($_POST['edit_template'])) {
    $old_raw = trim($_POST['old_nama_template'] ?? '');
    if (strtoupper($old_raw) === 'SHIFT 1 A') {
        echo "<script>alert('Nama template SHIFT 1 A tidak dapat diubah!'); window.location.href='pengaturan_jam.php';</script>";
        exit;
    }
    $old_name = $conn->real_escape_string($old_raw);
    $new_name = strtoupper($conn->real_escape_string(trim($_POST['nama_template'] ?? '')));
    $deskripsi = $conn->real_escape_string($_POST['deskripsi'] ?? '');

    if (!empty($new_name) && !empty($old_name)) {
        $conn->query("UPDATE master_template_jam SET nama_template = '$new_name', deskripsi = '$deskripsi' WHERE nama_template = '$old_name'");
        $conn->query("UPDATE master_jam_statis SET nama_template = '$new_name' WHERE nama_template = '$old_name'");
        $conn->query("UPDATE master_line SET nama_template = '$new_name' WHERE nama_template = '$old_name'");
        echo "<script>alert('Template $old_name berhasil diperbarui!'); window.location.href='pengaturan_jam.php';</script>";
        exit;
    }
}

// 4. Process Delete Template
if (isset($_GET['hapus_template'])) {
    $raw_hapus = trim($_GET['hapus_template']);
    if (strtoupper($raw_hapus) === 'SHIFT 1 A') {
        echo "<script>alert('Template SHIFT 1 A adalah template standar sistem dan tidak dapat dihapus!'); window.location.href='pengaturan_jam.php';</script>";
        exit;
    }
    $tpl_hapus = $conn->real_escape_string($raw_hapus);
    $conn->query("DELETE FROM master_template_jam WHERE nama_template = '$tpl_hapus'");
    $conn->query("DELETE FROM master_jam_statis WHERE nama_template = '$tpl_hapus'");
    echo "<script>alert('Template $tpl_hapus dan seluruh jadwalnya berhasil dihapus!'); window.location.href='pengaturan_jam.php';</script>";
    exit;
}

// 4. Fetch All Templates & Details Summary
$sql_templates = "SELECT t.*, 
                         COUNT(js.id) as total_slot,
                         GROUP_CONCAT(DISTINCT js.shift ORDER BY js.shift ASC SEPARATOR ', ') as shifts_summary
                  FROM master_template_jam t
                  LEFT JOIN master_jam_statis js ON t.nama_template = js.nama_template
                  GROUP BY t.id
                  ORDER BY t.nama_template ASC";
$res_templates = $conn->query($sql_templates);

// Also check lines using each template
$lines_usage = [];
$res_l = $conn->query("SELECT nama_line, nama_template_shift1, nama_template_shift2 FROM master_line");
if ($res_l) {
    while ($rl = $res_l->fetch_assoc()) {
        if (!empty($rl['nama_template_shift1'])) $lines_usage[$rl['nama_template_shift1']][] = $rl['nama_line'] . " (S1)";
        if (!empty($rl['nama_template_shift2'])) $lines_usage[$rl['nama_template_shift2']][] = $rl['nama_line'] . " (S2)";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Jam (Template Shift) - PT CNC</title>
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
            --danger: #ef4444;
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
            z-index: 1000; transition: transform 0.3s;
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

        .layout-grid {
            display: grid; grid-template-columns: 320px 1fr; gap: 25px; align-items: start;
        }

        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 12px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .card-header-title {
            font-size: 16px; font-weight: bold; color: var(--primary);
            border-bottom: 1px solid #333; padding-bottom: 12px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; color: var(--text-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; background: #121212; border: 1px solid #444; color: #fff;
            padding: 11px; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;
        }
        .form-control:focus { border-color: var(--primary); }

        .btn-submit {
            width: 100%; background: var(--primary); color: #000; border: none;
            padding: 12px; border-radius: 8px; font-weight: bold; font-size: 14px;
            cursor: pointer; transition: 0.2s; margin-top: 5px;
        }
        .btn-submit:hover { background: var(--primary-hover); }

        .alert {
            padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: bold;
        }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; }

        /* Template Cards Grid */
        .template-cards-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;
        }
        .template-card {
            background: #252525; border: 1px solid #333; border-radius: 10px; padding: 20px;
            display: flex; flex-direction: column; justify-content: space-between;
            transition: transform 0.2s, border-color 0.2s;
        }
        .template-card:hover { transform: translateY(-4px); border-color: var(--primary); }
        
        .tpl-title { font-size: 16px; font-weight: bold; color: #fff; margin: 0 0 6px 0; }
        .tpl-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 15px; }

        .tpl-meta {
            display: flex; flex-direction: column; gap: 6px; font-size: 12px;
            background: #1a1a1a; padding: 10px 12px; border-radius: 6px; margin-bottom: 18px; border: 1px solid #333;
        }
        .tpl-meta-item { display: flex; justify-content: space-between; }
        .tpl-meta-label { color: var(--text-muted); }
        .tpl-meta-val { font-weight: bold; color: #90caf9; }

        .tpl-actions { display: flex; gap: 8px; }
        .btn-detail {
            flex: 1; background: #1e3a8a; border: 1px solid #3b82f6; color: #fff;
            padding: 9px; border-radius: 6px; font-weight: bold; font-size: 12px;
            text-decoration: none; text-align: center; transition: 0.2s;
        }
        .btn-detail:hover { background: #2563eb; }

        .btn-delete {
            background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5;
            padding: 9px 12px; border-radius: 6px; font-weight: bold; font-size: 12px;
            text-decoration: none; text-align: center; transition: 0.2s;
        }
        .btn-delete:hover { background: var(--danger); color: #fff; }

        @media (max-width: 900px) {
            .layout-grid { grid-template-columns: 1fr; }
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
                <a href="<?= BASE_URL ?>setting/pengaturan_jam.php" class="active">⏱️ Master Jam (Template)</a>
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line</a>
            <?php endif; ?>
            <?php if(isset($user_role) && in_array($user_role, ['admin', 'it'])): ?>
                <a href="<?= BASE_URL ?>admin/skill_matrix.php">🎯 Skill Matrix Mesin</a>
                <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
                <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <h1>⏱️ MASTER TEMPLATE JAM KERJA</h1>
        </div>
        <a href="<?= BASE_URL ?>user/index.php" class="btn-back">← Dashboard</a>
    </div>

    <?= $pesan ?>

    <div class="layout-grid">

        <!-- CARD 1: FORM TAMBAH TEMPLATE BARU -->
        <div class="card">
            <div class="card-header-title">➕ Tambah Template Jam Baru</div>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Nama Template</label>
                    <input type="text" name="nama_template" class="form-control" placeholder="Contoh: SHIFT NORMAL 2 SHIFT" required>
                </div>
                <div class="form-group">
                    <label>Deskripsi / Catatan (Opsional)</label>
                    <input type="text" name="deskripsi" class="form-control" placeholder="Contoh: Jadwal operasional 2 shift normal">
                </div>
                <button type="submit" name="tambah_template" class="btn-submit">
                    💾 BUAT TEMPLATE & ATUR JAM →
                </button>
            </form>
        </div>

        <!-- CARD 2: DAFTAR TEMPLATE CARDS -->
        <div class="card">
            <div class="card-header-title">📋 Daftar Template Shift Terdaftar</div>
            <div class="template-cards-grid">
                <?php if ($res_templates && $res_templates->num_rows > 0): ?>
                    <?php while ($t = $res_templates->fetch_assoc()): 
                        $tName = $t['nama_template'];
                        $slotCount = $t['total_slot'];
                        $shiftsStr = $t['shifts_summary'] ?: 'Belum diatur';
                        $used_lines = $lines_usage[$tName] ?? [];
                    ?>
                        <div class="template-card">
                            <div>
                                <h3 class="tpl-title">⏱️ <?= htmlspecialchars($tName) ?></h3>
                                <div class="tpl-desc"><?= htmlspecialchars($t['deskripsi'] ?: 'Tanpa deskripsi') ?></div>

                                <div class="tpl-meta">
                                    <div class="tpl-meta-item">
                                        <span class="tpl-meta-label">Total Slot Jam:</span>
                                        <span class="tpl-meta-val"><?= $slotCount ?> Slot</span>
                                    </div>
                                    <div class="tpl-meta-item">
                                        <span class="tpl-meta-label">Shift Tercover:</span>
                                        <span class="tpl-meta-val"><?= htmlspecialchars($shiftsStr) ?></span>
                                    </div>
                                    <?php if (!empty($used_lines)): ?>
                                        <div class="tpl-meta-item" style="margin-top: 4px; border-top:1px dashed #333; padding-top:4px;">
                                            <span class="tpl-meta-label">Dipakai Line:</span>
                                            <span style="font-size:11px; color:#a7f3d0; text-align:right;">
                                                <?= htmlspecialchars(implode(', ', array_slice($used_lines, 0, 3))) ?>
                                                <?= count($used_lines) > 3 ? '...' : '' ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="tpl-actions">
                                <a href="<?= BASE_URL ?>setting/pengaturan_jam_detail.php?template=<?= urlencode($tName) ?>" class="btn-detail">
                                    ⚙️ Atur Jam & Shift
                                </a>
                                <?php if (strtoupper($tName) === 'SHIFT 1 A'): ?>
                                    <button class="btn-delete" disabled style="opacity:0.5; cursor:not-allowed;" title="Template SHIFT 1 A sistem tidak dapat dihapus">
                                        🔒
                                    </button>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?>setting/pengaturan_jam.php?hapus_template=<?= urlencode($tName) ?>" 
                                       class="btn-delete" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus template <?= htmlspecialchars($tName) ?> dan seluruh jadwalnya?')">
                                        🗑️
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align:center; color:#777; padding:30px; grid-column: 1/-1;">
                        Belum ada template. Silakan buat template baru di sebelah kiri.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("open");
            document.getElementById("overlay").classList.toggle("show");
        }
    </script>
</body>
</html>