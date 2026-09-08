<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "simulasi";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");

// Auto-migration: Pastikan kolom jam_reset_shift1 dan jam_reset_shift2 ada di setting_pabrik
$check_cols = $conn->query("SHOW COLUMNS FROM setting_pabrik LIKE 'jam_reset_shift1'");
if ($check_cols && $check_cols->num_rows == 0) {
    $conn->query("ALTER TABLE setting_pabrik ADD COLUMN jam_reset_shift1 TIME DEFAULT '16:00:00'");
    $conn->query("ALTER TABLE setting_pabrik ADD COLUMN jam_reset_shift2 TIME DEFAULT '06:00:00'");
}

define('BASE_URL', '/iot/');
?>