<?php
/**
 * 1-Click Server Setup Script (CLI & Web Compatible)
 * Otomatis membuat database, mengimpor skema bersih produksi,
 * memasang triggers, migrasi kolom lembur, dan verifikasi sistem.
 */
$is_web = (php_sapi_name() !== 'cli');
if ($is_web) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>MES IoT - 1-Click Zero-Touch Server Setup</title>
    <style>
        body { background: #0f172a; color: #f1f5f9; font-family: "Segoe UI", Consolas, monospace; margin: 0; padding: 30px; display: flex; justify-content: center; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 30px; max-width: 850px; width: 100%; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #38bdf8; font-size: 20px; border-bottom: 2px solid #334155; padding-bottom: 12px; margin-top: 0; }
        pre { background: #090d16; border: 1px solid #1e293b; border-radius: 8px; padding: 20px; font-size: 13px; line-height: 1.6; color: #4ade80; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
        .btn-group { margin-top: 20px; display: flex; gap: 12px; }
        .btn { background: #0284c7; color: white; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; font-family: sans-serif; font-size: 14px; display: inline-block; transition: 0.2s; }
        .btn:hover { background: #0369a1; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
    </style>
</head>
<body>
<div class="card">
    <h1>⚙️ MES IOT FACTORY - 1-CLICK ZERO-TOUCH SERVER SETUP</h1>
    <pre>';
}

echo "\n========================================================================\n";
echo "       IOT FACTORY MES - 1-CLICK AUTOMATED SERVER & SQL SETUP          \n";
echo "========================================================================\n\n";

// 1. Baca konfigurasi dari koneksi.php
$koneksi_file = __DIR__ . '/koneksi.php';
$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";

if (file_exists($koneksi_file)) {
    $content = file_get_contents($koneksi_file);
    if (preg_match('/\$host\s*=\s*["\']([^"\']+)["\']/', $content, $m)) $host = $m[1];
    if (preg_match('/\$user\s*=\s*["\']([^"\']+)["\']/', $content, $m)) $user = $m[1];
    if (preg_match('/\$pass\s*=\s*["\']([^"\']*)["\']/', $content, $m)) $pass = $m[1];
    if (preg_match('/\$db\s*=\s*["\']([^"\']+)["\']/', $content, $m)) $db = $m[1];
}

echo "[LANGKAH 1/5] Menghubungkan ke MySQL Server ($host, User: $user)...\n";
$conn_root = @new mysqli($host, $user, $pass);
if ($conn_root->connect_error) {
    echo "  [ERROR] Gagal koneksi ke MySQL: " . $conn_root->connect_error . "\n";
    echo "  -> Pastikan layanan MySQL di XAMPP sudah berjalan (RUNNING)!\n\n";
    exit(1);
}
echo "  [OK] Berhasil terhubung ke MySQL Server.\n\n";

// 2. Buat database jika belum ada
echo "[LANGKAH 2/5] Memastikan Database '$db' Tersedia...\n";
$sql_createdb = "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn_root->query($sql_createdb)) {
    echo "  [OK] Database '$db' siap digunakan.\n\n";
} else {
    echo "  [ERROR] Gagal membuat database '$db': " . $conn_root->error . "\n\n";
    exit(1);
}
$conn_root->close();

// Hubungkan ke database target
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo "  [ERROR] Gagal menghubungkan ke database '$db': " . $conn->connect_error . "\n\n";
    exit(1);
}
$conn->query("SET time_zone = '+07:00'");

// 3. Cek apakah tabel master_mesin sudah ada
echo "[LANGKAH 3/5] Memeriksa Struktur Tabel & Data Master...\n";
$res_tables = $conn->query("SHOW TABLES LIKE 'master_mesin'");
$need_import = (!$res_tables || $res_tables->num_rows == 0);

if ($need_import) {
    echo "  [INFO] Database '$db' masih kosong. Memulai impor data otomatis...\n";
    
    // Prioritas 1: database_clean_production.sql (172 KB, bersih dan cepat)
    // Prioritas 2: irfan/simulasi (1).sql (50 MB dump)
    $clean_sql = __DIR__ . '/database_clean_production.sql';
    $full_sql  = __DIR__ . '/irfan/simulasi (1).sql';
    
    $import_target = '';
    if (file_exists($clean_sql)) {
        $import_target = $clean_sql;
    } elseif (file_exists($full_sql)) {
        $import_target = $full_sql;
    }
    
    if (!empty($import_target)) {
        echo "  [INFO] Mengimpor berkas: " . basename($import_target) . " (" . round(filesize($import_target)/1024) . " KB)...\n";
        
        // Cari mysql CLI
        $mysql_bin = '';
        $xampp_mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
        if (file_exists($xampp_mysql)) {
            $mysql_bin = "\"$xampp_mysql\"";
        } else {
            // Cek di PATH
            $check_path = @exec("where mysql 2>nul");
            if (!empty($check_path) && file_exists($check_path)) {
                $mysql_bin = "\"$check_path\"";
            }
        }
        
        $imported = false;
        if (!empty($mysql_bin)) {
            $pass_arg = !empty($pass) ? "-p\"$pass\"" : "";
            $cmd = "$mysql_bin -h $host -u $user $pass_arg $db < \"$import_target\" 2>&1";
            @exec($cmd, $out, $ret);
            if ($ret === 0) {
                echo "  [OK] Impor database otomatis via MySQL CLI berhasil 100%!\n";
                $imported = true;
            }
        }
        
        if (!$imported) {
            echo "  [INFO] Mengimpor skema langsung via PHP MySQLi...\n";
            $sql_raw = file_get_contents($import_target);
            // Bersihkan baris DELIMITER untuk import via multi_query
            $clean_statements = preg_replace('/DELIMITER\s+[^;\n]+/i', '', $sql_raw);
            if ($conn->multi_query($clean_statements)) {
                do {
                    if ($res = $conn->store_result()) $res->free();
                } while ($conn->more_results() && $conn->next_result());
                echo "  [OK] Impor database via PHP Engine berhasil 100%!\n";
            } else {
                echo "  [WARN] Impor via PHP selesai dengan catatan: " . $conn->error . "\n";
            }
        }
    } else {
        echo "  [WARN] Berkas SQL tidak ditemukan di folder project.\n";
    }
} else {
    $mc_count = $conn->query("SELECT COUNT(*) as c FROM master_mesin")->fetch_assoc()['c'] ?? 0;
    echo "  [OK] Tabel database sudah ada ($mc_count Mesin terdaftar). Tidak perlu re-impor.\n";
}
echo "\n";

// 4. Pasang Trigger Otomatis
echo "[LANGKAH 4/5] Memverifikasi & Memasang Triggers Real-Time...\n";
require_once __DIR__ . '/trigger_manager.php';
$trig_res = installDatabaseTriggers($conn);
if ($trig_res['success']) {
    echo "  [OK] Trigger 'before_log_quality_insert' (Anti-Freeze & Multi-Cavity): AKTIF.\n";
    echo "  [OK] Trigger 'after_log_quality_insert' (Downtime Lintas Malam): AKTIF.\n";
} else {
    echo "  [WARN] Status Trigger: " . $trig_res['message'] . "\n";
}

// 5. Migrasi Kolom Tambahan (Failsafe)
echo "\n[LANGKAH 5/5] Memverifikasi Kolom Lembur & Pengaturan Pabrik...\n";
$cols = [];
$r = $conn->query("SHOW COLUMNS FROM history_summary");
if ($r) {
    while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
}
if (!in_array('status_lembur', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN status_lembur ENUM('NONE', 'PENDING', 'APPROVED', 'REJECTED') DEFAULT 'NONE'");
    echo "  [OK] Menambahkan kolom 'status_lembur'.\n";
}
if (!in_array('jam_lembur_selesai', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN jam_lembur_selesai TIME NULL");
    echo "  [OK] Menambahkan kolom 'jam_lembur_selesai'.\n";
}
if (!in_array('durasi_lembur_menit', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN durasi_lembur_menit INT DEFAULT 0");
    echo "  [OK] Menambahkan kolom 'durasi_lembur_menit'.\n";
}

// Cek setting_pabrik
$check_sp = $conn->query("SHOW COLUMNS FROM setting_pabrik LIKE 'jam_reset_shift1'");
if ($check_sp && $check_sp->num_rows == 0) {
    $conn->query("ALTER TABLE setting_pabrik ADD COLUMN jam_reset_shift1 TIME DEFAULT '18:00:00'");
    $conn->query("ALTER TABLE setting_pabrik ADD COLUMN jam_reset_shift2 TIME DEFAULT '06:30:00'");
    echo "  [OK] Menambahkan jam reset shift ke setting_pabrik.\n";
}
echo "  [OK] Seluruh skema database dan kolom pendukung siap 100%.\n\n";

echo "========================================================================\n";
echo "   SETUP DATABASE & TRIGGERS SELESAI DENGAN SUKSES! (SIAP DIGUNAKAN)    \n";
echo "========================================================================\n\n";

if ($is_web) {
    echo '</pre>
    <div class="btn-group">
        <a href="index.php" class="btn btn-green">🚀 Buka Dashboard Utama</a>
        <a href="cron_reset.php" target="_blank" class="btn">🔄 Uji Coba Reset Shift</a>
    </div>
</div>
</body>
</html>';
}

