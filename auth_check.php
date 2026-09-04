<?php
// Script ini harus di-include di baris pertama setelah session_start() di setiap halaman
require_once __DIR__ . '/koneksi.php';

// Cek apakah auth_enabled diaktifkan
$auth_enabled = 0;
$sql = "SELECT auth_enabled FROM setting_pabrik LIMIT 1";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $auth_enabled = (int)$row['auth_enabled'];
}

$current_script = basename($_SERVER['PHP_SELF']);

// Jika auth diaktifkan dan belum login, arahkan ke login.php
if ($auth_enabled == 1 && !isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// Daftar halaman khusus  Setting
$it_only_pages = ['pengaturan_line.php', 'pengaturan_jam.php', 'settings_auth.php'];

// === PENGECUALIAN UNTUK HALAMAN IT ===
// Meskipun auth_enabled = 0, khusus halaman IT WAJIB login.
if (in_array($current_script, $it_only_pages) && !isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "login.php?force=1");
    exit;
}

// === ROLE BASED ACCESS CONTROL (RBAC) ===
// Role yang ada: 'it' (setting), 'admin', 'user'
// Jika sudah login, gunakan role asli. Jika belum (karena auth mati), asumsikan 'admin' (bukan 'it').
// Ini mencegah pengunjung anonim bisa mengedit Master CT / Setting Line.
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'admin';

// Daftar halaman Admin & Setting
$admin_it_pages = ['skill_matrix.php', 'data_operator.php', 'proses_reset.php', 'master_ct.php'];

// 1. Cek Akses Halaman Setting (Hanya boleh dibuka oleh Tim Setting / IT)
if (in_array($current_script, $it_only_pages) && $user_role !== 'it') {
    die("<div style='text-align:center; padding: 50px; font-family:sans-serif; color:white; background:#111; height:100vh;'>
            <h2 style='color:#ef4444;'>⛔ AKSES DITOLAK</h2>
            <p>Hanya Tim Setting / IT yang dapat mengakses halaman ini.</p>
            <a href='index.php' style='color:#00bfa5;'>Kembali ke Dashboard</a>
         </div>");
}

// 2. Cek Akses Halaman Master Data (Boleh dibuka Admin & Setting)
if (in_array($current_script, $admin_it_pages) && !in_array($user_role, ['admin', 'it'])) {
    die("<div style='text-align:center; padding: 50px; font-family:sans-serif; color:white; background:#111; height:100vh;'>
            <h2 style='color:#ef4444;'>⛔ AKSES DITOLAK</h2>
            <p>Role USER tidak memiliki izin untuk mengedit Master Data.</p>
            <a href='index.php' style='color:#00bfa5;'>Kembali ke Dashboard</a>
         </div>");
}
?>
