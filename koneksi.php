<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "simulasi";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal: " . $conn->connect_error);

$conn->query("SET time_zone = '+07:00'");
define('BASE_URL', '/iot/');
?>