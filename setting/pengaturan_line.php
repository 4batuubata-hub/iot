<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// 1. Ensure table schema updates for 2 shifts and 2 reset times
$conn->query("CREATE TABLE IF NOT EXISTS master_line (id INT AUTO_INCREMENT PRIMARY KEY, nama_line VARCHAR(100) UNIQUE, nama_template VARCHAR(100))");
$conn->query("ALTER TABLE master_line ADD COLUMN IF NOT EXISTS nama_template_shift1 VARCHAR(100)");
$conn->query("ALTER TABLE master_line ADD COLUMN IF NOT EXISTS nama_template_shift2 VARCHAR(100)");

$conn->query("CREATE TABLE IF NOT EXISTS setting_pabrik (id INT AUTO_INCREMENT PRIMARY KEY, jam_reset TIME DEFAULT '06:00:00')");
$conn->query("ALTER TABLE setting_pabrik ADD COLUMN IF NOT EXISTS jam_reset_shift1 TIME DEFAULT '16:00:00'");
$conn->query("ALTER TABLE setting_pabrik ADD COLUMN IF NOT EXISTS jam_reset_shift2 TIME DEFAULT '06:00:00'");

// Sync line names from master_ct if missing
$conn->query("INSERT IGNORE INTO master_line (nama_line) SELECT DISTINCT line FROM master_ct WHERE line != '' AND line IS NOT NULL");

// 2. Fetch available shift templates (from master_template_jam & master_jam_statis)
$sql_tpl = "SELECT DISTINCT nama_template FROM master_template_jam 
            UNION 
            SELECT DISTINCT nama_template FROM master_jam_statis 
            ORDER BY nama_template ASC";
$res_tpl = $conn->query($sql_tpl);
$templates = [];
if ($res_tpl && $res_tpl->num_rows > 0) {
    while ($r = $res_tpl->fetch_assoc()) {
        if (!empty($r['nama_template'])) {
            $templates[] = $r['nama_template'];
        }
    }
}

// 3. Handle Auto-Reset Form Submit
if (isset($_POST['simpan_auto_reset'])) {
    $jam_s1 = $conn->real_escape_string($_POST['jam_reset_shift1']);
    $jam_s2 = $conn->real_escape_string($_POST['jam_reset_shift2']);

    $check = $conn->query("SELECT id FROM setting_pabrik LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $conn->query("UPDATE setting_pabrik SET jam_reset_shift1 = '$jam_s1', jam_reset_shift2 = '$jam_s2', jam_reset = '$jam_s2' WHERE id = 1");
    } else {
        $conn->query("INSERT INTO setting_pabrik (jam_reset_shift1, jam_reset_shift2, jam_reset) VALUES ('$jam_s1', '$jam_s2', '$jam_s2')");
    }
    echo "<script>alert('Pengaturan Jam Auto-Reset 2 Shift berhasil disimpan!'); window.location.href='pengaturan_line.php';</script>";
    exit;
}

// 4. Handle Line Templates Form Submit (1 Global Template per Line)
if (isset($_POST['simpan_line'])) {
    if (isset($_POST['template']) && is_array($_POST['template'])) {
        foreach ($_POST['template'] as $lineName => $tpl) {
            $ln = $conn->real_escape_string($lineName);
            $tp = $conn->real_escape_string($tpl);
            
            $conn->query("UPDATE master_line SET nama_template = '$tp' WHERE nama_line = '$ln'");
        }
        echo "<script>alert('Pengaturan Template Line berhasil disimpan!'); window.location.href='pengaturan_line.php';</script>";
        exit;
    }
}

// 5. Fetch Auto-Reset Data
$sql_reset = "SELECT jam_reset_shift1, jam_reset_shift2, jam_reset FROM setting_pabrik LIMIT 1";
$res_reset = $conn->query($sql_reset);
$row_reset = ($res_reset && $res_reset->num_rows > 0) ? $res_reset->fetch_assoc() : [];
$jam_reset_s1 = $row_reset['jam_reset_shift1'] ?? '16:00:00';
$jam_reset_s2 = $row_reset['jam_reset_shift2'] ?? ($row_reset['jam_reset'] ?? '06:00:00');

// 6. Fetch Lines Data
$sql_line = "SELECT nama_line, nama_template FROM master_line ORDER BY nama_line ASC";
$res_line = $conn->query($sql_line);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Line & Auto-Reset - PT CNC</title>
    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --primary: #00bfa5;
            --secondary: #3b82f6;
            --text-muted: #a0a0a0;
        }

        body {
            background-color: var(--bg-color);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            margin: 0;
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

        .header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 25px; border-bottom: 2px solid #333; padding-bottom: 15px;
            max-width: 950px; margin: 0 auto 30px auto;
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .btn-back { background: #475569; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 13px; }
        .btn-back:hover { background: #64748b; }

        .layout-container {
            max-width: 950px; margin: 0 auto; display: flex; flex-direction: column; gap: 25px;
        }

        .card {
            background: var(--card-bg); border: 1px solid #333; border-radius: 12px;
            padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .card-header {
            font-size: 17px; font-weight: bold; color: var(--primary);
            border-bottom: 1px solid #333; padding-bottom: 12px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }

        /* Auto Reset Form Grid */
        .reset-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 15px;
        }
        .reset-box {
            background: #252525; border: 1px solid #333; border-radius: 10px; padding: 18px;
            text-align: center;
        }
        .reset-box h4 { margin: 0 0 8px 0; color: #90caf9; font-size: 14px; }
        .reset-box p { margin: 0 0 15px 0; font-size: 11px; color: var(--text-muted); }
        
        input[type="time"] {
            background: #121212; color: var(--primary); border: 1px solid #444;
            padding: 12px 20px; font-size: 22px; font-weight: bold; border-radius: 8px;
            text-align: center; outline: none; width: 100%; box-sizing: border-box;
        }
        input[type="time"]:focus { border-color: var(--primary); }

        /* Line Mapping Table/Rows */
        .line-table {
            width: 100%; border-collapse: separate; border-spacing: 0 10px;
        }
        .line-table th {
            text-align: left; padding: 10px 15px; font-size: 12px; color: var(--text-muted);
            text-transform: uppercase; border-bottom: 1px solid #333;
        }
        .line-row {
            background: #252525; border-radius: 8px; transition: border-color 0.2s;
        }
        .line-row td {
            padding: 14px 15px; vertical-align: middle; border-top: 1px solid #333; border-bottom: 1px solid #333;
        }
        .line-row td:first-child { border-left: 1px solid #333; border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
        .line-row td:last-child { border-right: 1px solid #333; border-top-right-radius: 8px; border-bottom-right-radius: 8px; }
        .line-row:hover td { border-color: var(--primary); }
        
        .line-name { font-weight: bold; font-size: 14px; color: #90caf9; display: flex; align-items: center; gap: 8px; }

        select {
            background: #121212; color: #fff; border: 1px solid #444;
            padding: 8px 12px; border-radius: 6px; font-weight: 600; font-size: 13px;
            outline: none; width: 100%; box-sizing: border-box;
        }
        select:focus { border-color: var(--primary); }

        .btn-submit {
            background: #1e3a8a; color: white; border: 1px solid #3b82f6;
            padding: 14px 20px; border-radius: 8px; font-weight: bold; cursor: pointer;
            width: 100%; font-size: 14px; margin-top: 15px; transition: 0.2s;
        }
        .btn-submit:hover { background: #2563eb; }

        .info-note {
            background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--secondary);
            padding: 12px 15px; border-radius: 6px; font-size: 12px; color: #cbd5e1;
            margin-bottom: 20px; line-height: 1.5;
        }

        @media (max-width: 768px) {
            .reset-grid { grid-template-columns: 1fr; }
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
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php" class="active">⚙️ Pengaturan Line</a>
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

    <!-- MAIN HEADER -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <h1 style="margin:0; font-size:22px;">⚙️ PENGATURAN LINE & AUTO-RESET (2 SHIFT)</h1>
        </div>
        <a href="<?= BASE_URL ?>user/index.php" class="btn-back">← Dashboard</a>
    </div>

    <div class="layout-container">

        <!-- SECTION 1: AUTO RESET SETTINGS FOR 2 SHIFTS -->
        <div class="card">
            <div class="card-header">
                <span>⏰ Pengaturan Jam Auto-Reset Produksi (2 Shift)</span>
            </div>

            <div class="info-note">
                💡 <b>Fungsi Auto-Reset:</b> Pada jam reset yang ditentukan, sistem secara otomatis mengosongkan log sementara produksi dan memindahkan rekap data OEE ke <b>History Produksi</b> sesuai shift-nya.
            </div>

            <form method="POST">
                <div class="reset-grid">
                    <div class="reset-box">
                        <h4>🌅 Jam Reset Shift 1 (Tutup Buku Shift 1)</h4>
                        <p>Waktu pengarsipan data & perantian shift 1</p>
                        <input type="time" name="jam_reset_shift1" value="<?= substr($jam_reset_s1, 0, 5) ?>" required>
                    </div>

                    <div class="reset-box">
                        <h4>🌙 Jam Reset Shift 2 / Rekap Harian</h4>
                        <p>Waktu pengarsipan data & perantian shift 2</p>
                        <input type="time" name="jam_reset_shift2" value="<?= substr($jam_reset_s2, 0, 5) ?>" required>
                    </div>
                </div>

                <button type="submit" name="simpan_auto_reset" class="btn-submit" style="background:#059669; border-color:#10b981;">
                    💾 SIMPAN WAKTU AUTO-RESET 2 SHIFT
                </button>
            </form>
        </div>

        <!-- SECTION 2: LINE TEMPLATES MAPPING FOR 2 SHIFTS PER LINE -->
        <div class="card">
            <div class="card-header">
                <span>📍 Mapping Shift Template per Line (2 Shift)</span>
            </div>

            <p style="font-size:12px; color:var(--text-muted); margin-bottom:15px;">
                Atur template jam kerja per line untuk <b>Shift 1</b> dan <b>Shift 2</b>.
            </p>

            <form method="POST">
                <?php if ($res_line && $res_line->num_rows > 0): ?>
                    <table class="line-table">
                        <thead>
                            <tr>
                                <th style="width: 50%;">Line Produksi</th>
                                <th style="width: 50%;">Template Global (Senin-Minggu, Semua Shift)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $res_line->fetch_assoc()): 
                                $line_name = $row['nama_line'];
                                $tpl_aktif = $row['nama_template'] ?: 'DEFAULT';
                            ?>
                                <tr class="line-row">
                                    <td>
                                        <div class="line-name">
                                            <span>📍</span>
                                            <span><?= htmlspecialchars($line_name) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <select name="template[<?= htmlspecialchars($line_name) ?>]">
                                            <option value="DEFAULT">-- Default Template --</option>
                                            <?php foreach ($templates as $tpl): ?>
                                                <option value="<?= htmlspecialchars($tpl) ?>" <?= ($tpl_aktif == $tpl) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($tpl) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align:center; color:#777; padding:20px;">Belum ada data Line Produksi.</div>
                <?php endif; ?>

                <button type="submit" name="simpan_line" class="btn-submit">
                    💾 SIMPAN MAPPING TEMPLATE LINE
                </button>
            </form>
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