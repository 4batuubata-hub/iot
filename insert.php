<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Jakarta');
include 'koneksi.php';

// Menerima input JSON dari Node-RED / Python / IoT
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data) {
    $mcID         = $data['mcID'] ?? '';
    $kode_proses  = $data['kode_proses'] ?? ($data['kode_katalog'] ?? '');
    $op_NIK       = $data['op_NIK'] ?? '';
    $mcStatus     = $data['mcStatus'] ?? 'off';
    $mcInfo       = $data['mcInfo'] ?? 'Mesin Off';
    $prodCount    = $data['prodCount'] ?? 0;
    $NGCount      = $data['NGCount'] ?? 0;

    $mcID_esc = $conn->real_escape_string($mcID);
    $kode_proses_esc = $conn->real_escape_string($kode_proses);
    $op_NIK_esc = $conn->real_escape_string($op_NIK);
    $mcStatus_esc = $conn->real_escape_string($mcStatus);
    $mcInfo_esc = $conn->real_escape_string($mcInfo);
    
    // ======== DOWNTIME TRACKING LOGIC ========
    $res_last = $conn->query("SELECT mcInfo FROM log_quality WHERE mcID = '$mcID_esc' ORDER BY id DESC LIMIT 1");
    if ($res_last && $res_last->num_rows > 0) {
        $last_info = trim($res_last->fetch_assoc()['mcInfo']);
        $current_info = trim($mcInfo);
        
        if ($last_info !== $current_info) {
            $non_downtime = ['Mesin Running', 'Running', 'Mesin Off', 'Off'];
            // Jika status sebelumnya BUKAN 'Mesin Running' dll, berarti itu adalah downtime
            if (!in_array($last_info, $non_downtime, true)) {
                $last_info_esc = $conn->real_escape_string($last_info);
                
                // Cari id terakhir sebelum status ini (awal mula status ini)
                $res_diff = $conn->query("SELECT id FROM log_quality WHERE mcID = '$mcID_esc' AND mcInfo != '$last_info_esc' ORDER BY id DESC LIMIT 1");
                $diff_id = ($res_diff && $res_diff->num_rows > 0) ? $res_diff->fetch_assoc()['id'] : 0;
                
                // Cari waktu mulai status downtime ini
                $res_start = $conn->query("SELECT timestamp FROM log_quality WHERE mcID = '$mcID_esc' AND id > $diff_id ORDER BY id ASC LIMIT 1");
                
                if ($res_start && $res_start->num_rows > 0) {
                    $start_time_str = $res_start->fetch_assoc()['timestamp'];
                    $start_time = strtotime($start_time_str);
                    $end_time = time();
                    
                    $durasi_detik = $end_time - $start_time;
                    
                    $dbg = $conn->real_escape_string("Try: start=$start_time_str ($start_time), end=$end_time, dur=$durasi_detik, last=$last_info_esc, curr=$current_info");
                    $conn->query("INSERT INTO debug_log (msg) VALUES ('$dbg')");
                    
                    if ($durasi_detik > 0) {
                        $kode_dt_esc = $last_info_esc;
                        // Simpan ke log_downtime (gunakan jam server)
                        $q = "INSERT INTO log_downtime (mcID, kode_dt, durasi_detik, timestamp) VALUES ('$mcID_esc', '$kode_dt_esc', $durasi_detik, NOW())";
                        if(!$conn->query($q)) {
                            $err = $conn->real_escape_string($conn->error);
                            $conn->query("INSERT INTO debug_log (msg) VALUES ('Insert DT failed: $err')");
                        } else {
                            $conn->query("INSERT INTO debug_log (msg) VALUES ('Insert DT success')");
                        }
                    } else {
                        $conn->query("INSERT INTO debug_log (msg) VALUES ('Durasi <= 0')");
                    }
                } else {
                    $conn->query("INSERT INTO debug_log (msg) VALUES ('No start time found')");
                }
            }
        }
    }
    // ======== CAVITY LOGIC DITANGANI OLEH TRIGGER MYSQL ========
    
    $query = "INSERT INTO log_quality (mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount) 
              VALUES ('$mcID_esc', '$kode_proses_esc', '$op_NIK_esc', '$mcStatus_esc', '$mcInfo_esc', '$prodCount', '$NGCount')";

    if (mysqli_query($conn, $query)) {
        echo json_encode(["status" => "success", "message" => "Data berhasil disimpan"]);
    } else {
        echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Payload JSON tidak valid"]);
}
?>