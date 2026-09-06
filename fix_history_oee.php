<?php
$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal");

function getLogicalDay($time_str) {
    $time = strtotime($time_str);
    $now = date('H:i:s', $time);
    $day_num = date('N', $time);
    if ($now < '07:00:00') {
        $day_num = $day_num - 1;
        if ($day_num == 0) $day_num = 7;
    }
    if ($day_num >= 1 && $day_num <= 4) return 'SENIN-KAMIS';
    if ($day_num == 5) return 'JUMAT';
    if ($day_num == 6 || $day_num == 7) return 'SABTU-MINGGU';
    return 'SENIN-KAMIS';
}

echo "Memulai proses kalkulasi ulang OEE pada history_summary...\n\n";

$res = $conn->query("SELECT * FROM history_summary WHERE oee = 0 AND total_ok > 0");
if (!$res || $res->num_rows == 0) {
    echo "Tidak ada data yang perlu dikalkulasi ulang.\n";
    exit;
}

$count = 0;
while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $mcID = $row['mcID'];
    $tanggal = $row['tanggal'];
    $shift = $row['shift'];
    $part_name = $conn->real_escape_string($row['part_name']);
    $w_mulai = $row['waktu_mulai'];
    $w_selesai = $row['waktu_selesai'];
    $total_ok = (int)$row['total_ok'];

    // 1. Dapatkan Cycle Time dan Line dari part_name
    $ideal_ct = 0;
    $line_m = '';
    $ct_res = $conn->query("SELECT ct_pcs, line FROM master_ct WHERE part_name = '$part_name' LIMIT 1");
    if ($ct_res && $ct_res->num_rows > 0) {
        $c = $ct_res->fetch_assoc();
        $ideal_ct = floatval($c['ct_pcs']);
        $line_m = $c['line'];
    }

    // 2. Dapatkan Template Jadwal Kerja
    $template_aktif = 'DEFAULT';
    if (!empty($line_m)) {
        $res_tpl = $conn->query("SELECT nama_template FROM master_line WHERE nama_line = '$line_m' LIMIT 1");
        if ($res_tpl && $res_tpl->num_rows > 0) {
            $template_aktif = $res_tpl->fetch_assoc()['nama_template'];
        }
    }

    // 3. Hitung PPT (Planned Production Time)
    $hari_history = getLogicalDay($w_mulai);
    $tpl_esc = $conn->real_escape_string($template_aktif);
    $sql_slots = "SELECT menit_efektif FROM master_jam_statis WHERE nama_template = '$tpl_esc' AND shift = '$shift' AND (hari = '$hari_history' OR hari = 'SETIAP HARI')";
    $res_slots = $conn->query($sql_slots);
    
    $ppt_seconds = 0;
    if ($res_slots && $res_slots->num_rows > 0) {
        while($r_slot = $res_slots->fetch_assoc()) {
            $ppt_seconds += ((int)$r_slot['menit_efektif'] * 60);
        }
    }

    // 4. Dapatkan Total Downtime
    $dt_res = $conn->query("SELECT COALESCE(SUM(durasi_detik), 0) as dt FROM history_downtime WHERE mcID = '$mcID' AND timestamp >= '$w_mulai' AND timestamp <= '$w_selesai'");
    $total_dt = $dt_res->fetch_assoc()['dt'] ?? 0;

    // 5. Dapatkan Total NG
    $ng_res = $conn->query("SELECT COALESCE(SUM(qty_ng), 0) as ng FROM history_ng WHERE mcID = '$mcID' AND timestamp >= '$w_mulai' AND timestamp <= '$w_selesai'");
    $total_ng = $ng_res->fetch_assoc()['ng'] ?? 0;

    // 6. Hitung Variabel OEE
    $operating_time_seconds = $ppt_seconds - $total_dt;
    if ($operating_time_seconds < 0) $operating_time_seconds = 0;

    $a_r = ($ppt_seconds > 0) ? ($operating_time_seconds / $ppt_seconds) * 100 : 0;
    $p_r = ($operating_time_seconds > 0 && $ideal_ct > 0) ? (($ideal_ct * $total_ok) / $operating_time_seconds) * 100 : 0;
    
    // Perhatikan: total_ok di history_summary adalah barang yang SUDAH bagus, bukan total produksi kotor.
    // Quality = Total OK / (Total OK + Total NG)
    $total_produksi = $total_ok + $total_ng;
    $q_r = ($total_produksi > 0) ? ($total_ok / $total_produksi) * 100 : 0;

    if ($q_r > 100) $q_r = 100;
    if ($p_r > 100) $p_r = 100;
    if ($a_r > 100) $a_r = 100;

    $oee_r = ($a_r * $p_r * $q_r) / 10000;

    // 7. Update tabel
    $upd = $conn->query("UPDATE history_summary SET total_ng = '$total_ng', oee = '$oee_r', availability = '$a_r', performance = '$p_r', quality = '$q_r' WHERE id = '$id'");
    
    if ($upd) {
        $count++;
        echo "Update OK: [$tanggal - $shift] Mesin: $mcID | A:".round($a_r, 1)."% P:".round($p_r, 1)."% Q:".round($q_r, 1)."% OEE:".round($oee_r, 1)."%\n";
    } else {
        echo "Gagal update ID: $id\n";
    }
}

echo "\nSelesai! Berhasil mengkalkulasi ulang $count baris.\n";
?>
