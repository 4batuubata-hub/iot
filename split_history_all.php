<?php
// Script to fix and split history_summary for orphaned days FOR ALL MACHINES
$host = "localhost"; $user = "root"; $pass = ""; $db = "simulasi";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Koneksi Gagal");

// 1. Sanitize history_quality anomalies globally
$conn->query("UPDATE history_quality SET prodCount = 0 WHERE prodCount < 0 OR prodCount > 10000");

// 2. Identify all mcIDs
$mcRes = $conn->query("SELECT DISTINCT mcID FROM history_quality");
$mcIDs = [];
while ($m = $mcRes->fetch_assoc()) {
    $mcIDs[] = $m['mcID'];
}

foreach ($mcIDs as $mcID) {
    echo "Processing $mcID...\n";
    $res = $conn->query("SELECT * FROM history_quality WHERE mcID = '$mcID' ORDER BY timestamp ASC");
    $shifts_data = [];

    while($row = $res->fetch_assoc()) {
        $ts = $row['timestamp'];
        $time = strtotime($ts);
        $H = (int)date('H', $time);
        $i = (int)date('i', $time);
        
        // Determine Shift and Production Date
        // Shift 1: 07:30 to 19:29
        // Shift 2: 19:30 to 07:29 next day
        $time_decimal = $H + ($i / 60);
        
        if ($time_decimal >= 7.5 && $time_decimal < 19.5) {
            $shift = 'SHIFT 1';
            $tanggal = date('Y-m-d', $time);
        } else {
            $shift = 'SHIFT 2';
            if ($time_decimal >= 0 && $time_decimal < 7.5) {
                // It's past midnight, belongs to previous day's Shift 2
                $tanggal = date('Y-m-d', strtotime('-1 day', $time));
            } else {
                $tanggal = date('Y-m-d', $time);
            }
        }
        
        $key = $tanggal . "_" . $shift;
        if (!isset($shifts_data[$key])) {
            $shifts_data[$key] = [
                'tanggal' => $tanggal,
                'shift' => $shift,
                'waktu_mulai' => $ts,
                'waktu_selesai' => $ts,
                'logs' => [],
                'part_name' => ''
            ];
        }
        $shifts_data[$key]['waktu_selesai'] = $ts;
        $shifts_data[$key]['logs'][] = (int)$row['prodCount'];
        
        // get part name from master_ct
        if (empty($shifts_data[$key]['part_name'])) {
            $kp = $row['kode_proses'];
            $ct_res = $conn->query("SELECT part_name FROM master_ct WHERE kode = '$kp' LIMIT 1");
            if ($ct_res && $ct_res->num_rows > 0) {
                $shifts_data[$key]['part_name'] = $ct_res->fetch_assoc()['part_name'];
            }
        }
    }

    // 3. Process each shift to insert into history_summary
    foreach ($shifts_data as $key => $data) {
        $tanggal = $data['tanggal'];
        $shift = $data['shift'];
        $w_mulai = $data['waktu_mulai'];
        $w_selesai = $data['waktu_selesai'];
        $part_name = $data['part_name'];
        
        // Calculate Total OK
        $total_ok = 0;
        $prev = 0;
        foreach ($data['logs'] as $idx => $curr) {
            if ($idx == 0) {
                $prev = $curr;
                continue;
            }
            $delta = $curr - $prev;
            if ($delta > 0 && $delta <= 50) {
                $total_ok += $delta;
            } else if ($delta < 0 && $curr <= 5) {
                // ESP32 reset assumed
                $total_ok += $curr;
            }
            $prev = $curr;
        }
        
        // Upsert into history_summary
        $check = $conn->query("SELECT id FROM history_summary WHERE mcID='$mcID' AND tanggal='$tanggal' AND shift='$shift'");
        if ($check && $check->num_rows > 0) {
            $conn->query("UPDATE history_summary SET total_ok = '$total_ok', waktu_mulai = '$w_mulai', waktu_selesai = '$w_selesai', part_name='$part_name' WHERE mcID='$mcID' AND tanggal='$tanggal' AND shift='$shift'");
        } else {
            $conn->query("INSERT INTO history_summary (tanggal, shift, mcID, part_name, total_ok, waktu_mulai, waktu_selesai) VALUES ('$tanggal', '$shift', '$mcID', '$part_name', '$total_ok', '$w_mulai', '$w_selesai')");
        }
    }
}
echo "Selesai All Machines!";
?>
