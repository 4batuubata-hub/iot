<?php
/**
 * ============================================================
 *  STARTUP RECONCILIATION SCRIPT
 *  Dijalankan saat server pertama kali menyala setelah downtime.
 * ============================================================
 * 
 * Fungsi:
 * 1. Cek apakah ada data "basi" di log_quality yang belum ter-reset
 * 2. Jalankan cron_reset.php untuk memproses data lama ke history
 * 3. Log semua aktivitas ke debug_log
 * 
 * Cara penggunaan:
 * - Tambahkan ke Windows Task Scheduler dengan trigger "At System Startup"
 * - Atau panggil manual: php startup_reconcile.php
 * ============================================================
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Jakarta');

$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);
$conn->query("SET time_zone = '+07:00'");

$now = date('Y-m-d H:i:s');
echo "[STARTUP] Reconciliation dimulai pada $now\n";
$conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] Reconciliation dimulai pada $now')");

// ============================================================
// STEP 1: Cek apakah ada data basi di log_quality
// ============================================================
$STALE_THRESHOLD_MINUTES = 60; // Data dianggap basi jika >60 menit dari sekarang

$res = $conn->query("SELECT mcID, MIN(timestamp) as min_ts, MAX(timestamp) as max_ts, MAX(prodCount) as max_prod, COUNT(*) as cnt 
                     FROM log_quality 
                     GROUP BY mcID");

if (!$res || $res->num_rows == 0) {
    echo "[STARTUP] Tidak ada data di log_quality. Tidak perlu reconciliation.\n";
    $conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] log_quality kosong, skip.')");
    exit;
}

$stale_machines = [];
while ($row = $res->fetch_assoc()) {
    $max_ts = strtotime($row['max_ts']);
    $age_minutes = (time() - $max_ts) / 60;
    
    echo "[STARTUP] Mesin {$row['mcID']}: {$row['cnt']} rows, last={$row['max_ts']}, age={$age_minutes}m, maxProd={$row['max_prod']}\n";
    
    if ($age_minutes >= $STALE_THRESHOLD_MINUTES) {
        $stale_machines[] = $row;
        echo "  -> BASI! Akan diproses.\n";
    }
}

if (empty($stale_machines)) {
    echo "[STARTUP] Tidak ada data basi. Sistem normal.\n";
    $conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] Tidak ada data basi, skip.')");
    exit;
}

// ============================================================
// STEP 2: Jalankan cron_reset.php untuk memproses data lama
// ============================================================
echo "\n[STARTUP] Menemukan " . count($stale_machines) . " mesin dengan data basi. Menjalankan cron_reset.php...\n";
$conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] " . count($stale_machines) . " mesin basi ditemukan. Menjalankan cron_reset...')");

// Jalankan cron_reset.php
$cron_path = __DIR__ . '/cron_reset.php';
if (file_exists($cron_path)) {
    ob_start();
    include $cron_path;
    $cron_output = ob_get_clean();
    echo "[STARTUP] cron_reset.php output:\n$cron_output\n";
    $conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] cron_reset selesai.')");
} else {
    echo "[STARTUP] ERROR: cron_reset.php tidak ditemukan di $cron_path\n";
}

// ============================================================
// STEP 3: Verifikasi hasil
// ============================================================
$res2 = $conn->query("SELECT COUNT(*) as cnt FROM log_quality");
$remaining = $res2->fetch_assoc()['cnt'];
echo "\n[STARTUP] Selesai. Sisa data di log_quality: $remaining rows.\n";
$conn->query("INSERT INTO debug_log (msg) VALUES ('[STARTUP] Selesai. Sisa log_quality: $remaining rows.')");
?>
