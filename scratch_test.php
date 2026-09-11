<?php
$conn = new mysqli('localhost', 'root', '', 'simulasi');
$res = $conn->query("SELECT DISTINCT tanggal, shift FROM history_summary ORDER BY tanggal DESC LIMIT 10");
while($r = $res->fetch_assoc()) {
    echo $r['tanggal'] . " - " . $r['shift'] . "\n";
}
