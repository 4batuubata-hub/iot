<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// 1. Get Target Template Name
$template_name = trim($_GET['template'] ?? '');
if (empty($template_name)) {
    header("Location: pengaturan_jam.php");
    exit;
}

$template_clean = $conn->real_escape_string($template_name);

// Get template info from master_template_jam
$res_info = $conn->query("SELECT * FROM master_template_jam WHERE nama_template = '$template_clean' LIMIT 1");
$template_info = ($res_info && $res_info->num_rows > 0) ? $res_info->fetch_assoc() : null;

$pesan = "";

// 2. Handle Insert / Update Time Slot
if (isset($_POST['simpan_slot'])) {
    $id_slot = intval($_POST['id_slot'] ?? 0);
    $shift = strtoupper(trim($_POST['shift'] ?? 'SHIFT 1'));
    
    // Construct rentang_jam from time pickers or manual text
    $rentang_manual = trim($_POST['rentang_jam'] ?? '');
    $jam_mulai = trim($_POST['jam_mulai'] ?? '');
    $jam_selesai = trim($_POST['jam_selesai'] ?? '');
    
    if (!empty($jam_mulai) && !empty($jam_selesai)) {
        $rentang_jam = $jam_mulai . " - " . $jam_selesai;
    } elseif (!empty($rentang_manual)) {
        $rentang_jam = $rentang_manual;
    } else {
        $rentang_jam = "";
    }

    $menit_efektif = intval($_POST['menit_efektif'] ?? 60);
    $hari = trim($_POST['hari'] ?? 'SETIAP HARI');
    $urutan = intval($_POST['urutan'] ?? 1);

    if (empty($rentang_jam)) {
        $pesan = "<div class='alert alert-danger'>Rentang jam kerja wajib diisi!</div>";
    } else {
        if ($id_slot > 0) {
            // Update existing slot by ID
            $stmt = $conn->prepare("UPDATE master_jam_statis SET shift = ?, rentang_jam = ?, menit_efektif = ?, hari = ?, urutan = ? WHERE id = ?");
            $stmt->bind_param("ssisii", $shift, $rentang_jam, $menit_efektif, $hari, $urutan, $id_slot);
            if ($stmt->execute()) {
                header("Location: pengaturan_jam_detail.php?template=" . urlencode($template_name) . "&msg=updated");
                exit;
            } else {
                $pesan = "<div class='alert alert-danger'>Gagal memperbarui: " . $stmt->error . "</div>";
            }
        } else {
            // Insert new slot (Pass raw $template_name into bind_param, not $template_clean!)
            $stmt = $conn->prepare("INSERT INTO master_jam_statis (nama_template, shift, rentang_jam, menit_efektif, hari, urutan) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssisi", $template_name, $shift, $rentang_jam, $menit_efektif, $hari, $urutan);
            if ($stmt->execute()) {
                header("Location: pengaturan_jam_detail.php?template=" . urlencode($template_name) . "&msg=created");
                exit;
            } else {
                $pesan = "<div class='alert alert-danger'>Gagal menambahkan slot: " . $stmt->error . "</div>";
            }
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'updated') {
        $pesan = "<div class='alert alert-success'>Slot jam kerja berhasil diperbarui!</div>";
    } elseif ($_GET['msg'] === 'created') {
        $pesan = "<div class='alert alert-success'>Slot jam kerja baru berhasil ditambahkan!</div>";
    }
}

// 3. Handle Delete Time Slot
if (isset($_GET['hapus_slot'])) {
    $id_hapus = intval($_GET['hapus_slot']);
    $conn->query("DELETE FROM master_jam_statis WHERE id = $id_hapus AND nama_template = '$template_clean'");
    echo "<script>alert('Slot jam kerja berhasil dihapus!'); window.location.href='pengaturan_jam_detail.php?template=" . urlencode($template_name) . "';</script>";
    exit;
}

// 4. Fetch Slots for this Template
$sql_slots = "SELECT * FROM master_jam_statis WHERE nama_template = '$template_clean' ORDER BY shift ASC, urutan ASC, id ASC";
$res_slots = $conn->query($sql_slots);
$all_slots = [];
if ($res_slots) {
    while ($r = $res_slots->fetch_assoc()) {
        $all_slots[] = $r;
    }
}

// Check slot to edit if edit_slot parameter is passed
$edit_data = null;
if (isset($_GET['edit_slot'])) {
    $id_edit = intval($_GET['edit_slot']);
    $res_ed = $conn->query("SELECT * FROM master_jam_statis WHERE id = $id_edit AND nama_template = '$template_clean' LIMIT 1");
    if ($res_ed && $res_ed->num_rows > 0) {
        $edit_data = $res_ed->fetch_assoc();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Jam Template <?= htmlspecialchars($template_name) ?> - PT CNC</title>
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

        /* Header */
        .header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; border-bottom: 2px solid #333; padding-bottom: 15px;
            flex-wrap: wrap; gap: 15px;
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .header h1 { margin: 0; font-size: 22px; color: #fff; }
        .btn-back { background: #334155; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; transition: 0.2s; }
        .btn-back:hover { background: #475569; }

        .layout-grid {
            display: grid; grid-template-columns: 340px 1fr; gap: 25px; align-items: start;
        }

        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 12px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .card-header-title {
            font-size: 16px; font-weight: bold; color: var(--primary);
            border-bottom: 1px solid #333; padding-bottom: 12px; margin-bottom: 18px;
            display: flex; align-items: center; justify-content: space-between;
        }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; color: var(--text-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; background: #121212; border: 1px solid #444; color: #fff;
            padding: 10px 12px; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;
        }
        .form-control:focus { border-color: var(--primary); }

        .time-picker-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        .btn-submit {
            width: 100%; background: var(--primary); color: #000; border: none;
            padding: 12px; border-radius: 8px; font-weight: bold; font-size: 14px;
            cursor: pointer; transition: 0.2s; margin-top: 10px;
        }
        .btn-submit:hover { background: var(--primary-hover); }

        .alert {
            padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: bold;
        }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #a7f3d0; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; }

        /* Table */
        .slot-table {
            width: 100%; border-collapse: separate; border-spacing: 0 8px; font-size: 13px;
        }
        .slot-table th {
            background: #252525; padding: 12px; text-align: left; color: var(--text-muted);
            font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #333;
        }
        .slot-row { background: #252525; border-radius: 8px; }
        .slot-row td {
            padding: 12px; vertical-align: middle; border-top: 1px solid #333; border-bottom: 1px solid #333;
        }
        .slot-row td:first-child { border-left: 1px solid #333; border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
        .slot-row td:last-child { border-right: 1px solid #333; border-top-right-radius: 8px; border-bottom-right-radius: 8px; }
        
        .badge-shift {
            background: #1e3a8a; color: #90caf9; padding: 4px 10px; border-radius: 6px;
            font-size: 11px; font-weight: bold; display: inline-block;
        }

        .btn-edit-slot {
            background: #3b82f6; color: white; border: none; padding: 5px 10px;
            border-radius: 4px; font-size: 11px; text-decoration: none; font-weight: bold; margin-right: 5px;
        }
        .btn-delete-slot {
            background: var(--danger); color: white; border: none; padding: 5px 10px;
            border-radius: 4px; font-size: 11px; text-decoration: none; font-weight: bold;
        }

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
            <button class="close-btn" onclick="toggleSidebar()">&times;</button>
        </div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php">📁 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php">📈 Rangkuman OEE</a>
            <?php if(isset($user_role) && $user_role === 'it'): ?><a href="<?= BASE_URL ?>setting/pengaturan_jam.php" class="active">⏱️ Master Jam (Template)</a>
            <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line & Reset</a>
            <a href="<?= BASE_URL ?>admin/skill_matrix.php">🎯 Skill Matrix Mesin</a>
            <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
                <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">&#9776;</button>
            <h1>⏱️ DETAIL TEMPLATE: <span style="color:var(--primary);"><?= htmlspecialchars($template_name) ?></span></h1>
        </div>
        <a href="<?= BASE_URL ?>setting/pengaturan_jam.php" class="btn-back">&larr; Kembali ke Daftar Template</a>
    </div>

    <?= $pesan ?>

    <div class="layout-grid">

        <!-- CARD 1: FORM TAMBAH / EDIT SLOT JAM -->
        <div class="card" id="slotFormCard">
            <div class="card-header-title">
                <span id="formTitle"><?= $edit_data ? '✏️ Edit Slot Jam' : '➕ Tambah Slot Jam Kerja' ?></span>
                <a href="<?= BASE_URL ?>setting/pengaturan_jam_detail.php?template=<?= urlencode($template_name) ?>" 
                   id="btnCancelEdit" 
                   style="font-size:11px; color:#90caf9; text-decoration:none; display:<?= $edit_data ? 'inline' : 'none' ?>;">
                   + Batal Edit
                </a>
            </div>

            <form method="POST" action="" id="slotForm">
                <input type="hidden" name="id_slot" id="id_slot" value="<?= $edit_data ? $edit_data['id'] : '0' ?>">

                <div class="form-group">
                    <label>Pilih Shift</label>
                    <select name="shift" id="field_shift" class="form-control" required>
                        <option value="SHIFT 1" <?= ($edit_data && $edit_data['shift'] == 'SHIFT 1') ? 'selected' : '' ?>>SHIFT 1</option>
                        <option value="SHIFT 2" <?= ($edit_data && $edit_data['shift'] == 'SHIFT 2') ? 'selected' : '' ?>>SHIFT 2</option>
                        <option value="SHIFT 3" <?= ($edit_data && $edit_data['shift'] == 'SHIFT 3') ? 'selected' : '' ?>>SHIFT 3</option>
                    </select>
                </div>

                <?php 
                    $p_mulai = ''; $p_selesai = ''; $raw_rentang = '';
                    if ($edit_data && !empty($edit_data['rentang_jam'])) {
                        $raw_rentang = $edit_data['rentang_jam'];
                        $parts = explode('-', $edit_data['rentang_jam']);
                        if (count($parts) == 2) {
                            $t_start = strtotime(trim($parts[0]));
                            $t_end = strtotime(trim($parts[1]));
                            if ($t_start !== false) $p_mulai = date('H:i', $t_start);
                            if ($t_end !== false) $p_selesai = date('H:i', $t_end);
                        }
                    }
                ?>

                <div class="form-group">
                    <label>Rentang Jam Kerja (Jam Mulai & Jam Selesai)</label>
                    <div class="time-picker-grid">
                        <input type="time" name="jam_mulai" id="field_jam_mulai" class="form-control" value="<?= htmlspecialchars($p_mulai) ?>">
                        <input type="time" name="jam_selesai" id="field_jam_selesai" class="form-control" value="<?= htmlspecialchars($p_selesai) ?>">
                    </div>
                    <div style="margin-top:6px;">
                        <input type="text" name="rentang_jam" id="field_rentang_jam" class="form-control" 
                               placeholder="Atau ketik rentang jam manual (contoh: 07:00 - 16:00)" 
                               value="<?= htmlspecialchars($raw_rentang) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Waktu Kerja / Menit Efektif (Menit)</label>
                    <input type="number" name="menit_efektif" id="field_menit_efektif" class="form-control" value="<?= $edit_data ? $edit_data['menit_efektif'] : '60' ?>" required style="background-color: #2a2a2a;" readonly>
                    <small style="color:var(--text-muted); font-size:11px;">Otomatis dihitung dari selisih Jam Mulai dan Jam Selesai.</small>
                </div>

                <div class="form-group">
                    <label>Hari Berlaku</label>
                    <select name="hari" id="field_hari" class="form-control" required>
                        <option value="SETIAP HARI" <?= ($edit_data && $edit_data['hari'] == 'SETIAP HARI') ? 'selected' : '' ?>>SETIAP HARI</option>
                        <option value="SENIN-KAMIS" <?= ($edit_data && $edit_data['hari'] == 'SENIN-KAMIS') ? 'selected' : '' ?>>SENIN - KAMIS</option>
                        <option value="JUMAT" <?= ($edit_data && $edit_data['hari'] == 'JUMAT') ? 'selected' : '' ?>>JUMAT</option>
                        <option value="SABTU-MINGGU" <?= ($edit_data && $edit_data['hari'] == 'SABTU-MINGGU') ? 'selected' : '' ?>>SABTU - MINGGU</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Urutan Tampil (Kolom)</label>
                    <input type="number" name="urutan" id="field_urutan" class="form-control" value="<?= $edit_data ? $edit_data['urutan'] : (count($all_slots) + 1) ?>" required>
                </div>

                <button type="submit" name="simpan_slot" id="btnSubmitSlot" class="btn-submit">
                    <?= $edit_data ? '💾 SIMPAN PERUBAHAN SLOT' : '💾 TAMBAH SLOT JAM KERJA' ?>
                </button>
            </form>
        </div>

        <!-- CARD 2: DAFTAR SLOT JAM KERJA TABLE -->
        <div class="card">
            <div class="card-header-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span>📋 Daftar Rentang Jam Kerja (<?= count($all_slots) ?> Slot)</span>
                <div style="display: flex; gap: 10px;">
                    <select id="filterShift" onchange="filterTable()" style="padding: 5px 10px; border-radius: 4px; background: #121212; color: #fff; border: 1px solid #444; outline: none; min-width: 120px;">
                        <option value="ALL">-- Semua Shift --</option>
                        <option value="SHIFT 1">SHIFT 1</option>
                        <option value="SHIFT 2">SHIFT 2</option>
                    </select>
                    <select id="filterHari" onchange="filterTable()" style="padding: 5px 10px; border-radius: 4px; background: #121212; color: #fff; border: 1px solid #444; outline: none; min-width: 150px;">
                        <option value="ALL">-- Semua Hari --</option>
                        <option value="SENIN-KAMIS">SENIN - KAMIS</option>
                        <option value="JUMAT">JUMAT</option>
                        <option value="SABTU-MINGGU">SABTU - MINGGU</option>
                        <option value="SETIAP HARI">SETIAP HARI</option>
                    </select>
                </div>
            </div>

            <?php if (count($all_slots) > 0): ?>
                <table class="slot-table">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th>Urutan</th>
                            <th>Rentang Jam</th>
                            <th>Menit Efektif</th>
                            <th>Hari</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_slots as $slot): ?>
                            <tr class="slot-row">
                                <td><span class="badge-shift"><?= htmlspecialchars($slot['shift']) ?></span></td>
                                <td><b><?= htmlspecialchars($slot['urutan']) ?></b></td>
                                <td><strong style="color:var(--primary); font-size:14px;"><?= htmlspecialchars($slot['rentang_jam']) ?></strong></td>
                                <td><?= htmlspecialchars($slot['menit_efektif']) ?> Menit</td>
                                <td><?= htmlspecialchars($slot['hari']) ?></td>
                                <td style="text-align: center;">
                                    <a href="<?= BASE_URL ?>setting/pengaturan_jam_detail.php?template=<?= urlencode($template_name) ?>&edit_slot=<?= $slot['id'] ?>" 
                                       class="btn-edit-slot"
                                       data-id="<?= $slot['id'] ?>"
                                       data-shift="<?= htmlspecialchars($slot['shift']) ?>"
                                       data-rentang="<?= htmlspecialchars($slot['rentang_jam']) ?>"
                                       data-menit="<?= htmlspecialchars($slot['menit_efektif']) ?>"
                                       data-hari="<?= htmlspecialchars($slot['hari']) ?>"
                                       data-urutan="<?= htmlspecialchars($slot['urutan']) ?>"
                                       onclick="editSlotBtn(this, event)">
                                       ✏️ Edit
                                    </a>
                                    <a href="<?= BASE_URL ?>setting/pengaturan_jam_detail.php?template=<?= urlencode($template_name) ?>&hapus_slot=<?= $slot['id'] ?>" 
                                       class="btn-delete-slot" 
                                       onclick="return confirm('Hapus rentang jam ini?')">🗑️ Hapus</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align:center; color:#777; padding:40px;">
                    Belum ada rentang jam yang diatur pada template ini.<br>
                    Silakan isi form di sebelah kiri untuk menambahkan jam kerja.
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function filterTable() {
            let filterS = document.getElementById("filterShift").value;
            let filterH = document.getElementById("filterHari").value;
            
            sessionStorage.setItem("savedFilterShift", filterS);
            sessionStorage.setItem("savedFilterHari", filterH);
            
            let fs = filterS.toUpperCase();
            let fh = filterH.toUpperCase();
            
            let rows = document.querySelectorAll(".slot-row");
            rows.forEach(row => {
                let shiftText = row.querySelector("td:nth-child(1)").innerText.toUpperCase().trim();
                let hariText = row.querySelector("td:nth-child(5)").innerText.toUpperCase().trim();
                
                let matchShift = (fs === "ALL" || shiftText === fs);
                let matchHari = (fh === "ALL" || hariText === fh);
                
                if (matchShift && matchHari) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }

        window.addEventListener('DOMContentLoaded', (event) => {
            let savedS = sessionStorage.getItem("savedFilterShift");
            let savedH = sessionStorage.getItem("savedFilterHari");
            if(savedS) document.getElementById("filterShift").value = savedS;
            if(savedH) document.getElementById("filterHari").value = savedH;
            filterTable();
        });

        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("open");
            document.getElementById("overlay").classList.toggle("show");
        }

        function editSlotBtn(btn, e) {
            if (e) e.preventDefault();

            const id = btn.getAttribute('data-id');
            const shift = btn.getAttribute('data-shift');
            const rentang = btn.getAttribute('data-rentang');
            const menit = btn.getAttribute('data-menit');
            const hari = btn.getAttribute('data-hari');
            const urutan = btn.getAttribute('data-urutan');

            document.getElementById('id_slot').value = id;
            
            const selectShift = document.getElementById('field_shift');
            if (selectShift) {
                selectShift.value = shift;
                if (selectShift.selectedIndex === -1) {
                    for (let i = 0; i < selectShift.options.length; i++) {
                        if (selectShift.options[i].value.trim().toUpperCase() === (shift || '').trim().toUpperCase()) {
                            selectShift.selectedIndex = i;
                            break;
                        }
                    }
                }
            }

            document.getElementById('field_rentang_jam').value = rentang;
            
            const parts = rentang.split('-');
            if (parts.length === 2) {
                document.getElementById('field_jam_mulai').value = parts[0].trim();
                document.getElementById('field_jam_selesai').value = parts[1].trim();
            } else {
                document.getElementById('field_jam_mulai').value = '';
                document.getElementById('field_jam_selesai').value = '';
            }

            document.getElementById('field_menit_efektif').value = menit || 60;
            document.getElementById('field_hari').value = hari;
            document.getElementById('field_urutan').value = urutan;

            document.getElementById('formTitle').innerText = '✏️ Edit Slot Jam Kerja';
            document.getElementById('btnSubmitSlot').innerText = '💾 SIMPAN PERUBAHAN SLOT';
            document.getElementById('btnCancelEdit').style.display = 'inline';

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Auto calculate menit efektif
        function calculateDiff() {
            let mulai = document.getElementById('field_jam_mulai').value;
            let selesai = document.getElementById('field_jam_selesai').value;
            if (mulai && selesai) {
                let start = new Date("1970-01-01T" + mulai + ":00");
                let end = new Date("1970-01-01T" + selesai + ":00");
                if (end < start) {
                    end.setDate(end.getDate() + 1); // handle overnight shift
                }
                let diffMs = end - start;
                let diffMins = Math.round(diffMs / 60000);
                document.getElementById('field_menit_efektif').value = diffMins;
                
                // Update text field as well
                document.getElementById('field_rentang_jam').value = mulai + " - " + selesai;
            }
        }
        document.getElementById('field_jam_mulai').addEventListener('change', calculateDiff);
        document.getElementById('field_jam_selesai').addEventListener('change', calculateDiff);

    </script>
</body>
</html>
