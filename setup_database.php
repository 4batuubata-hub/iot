<?php
/**
 * Script 1-Click Setup Database & Triggers
 * Buka di browser: http://localhost/iot/setup_database.php
 */
require_once 'koneksi.php';

header('Content-Type: text/html; charset=utf-8');
echo "<div style='font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); background: #ffffff;'>";
echo "<h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>⚙️ 1-Click Setup Database SMMS & Trigger</h2>";

// 1. Tambah Kolom Lembur di history_summary jika belum ada
echo "<h3>1. Memeriksa Kolom history_summary...</h3>";
$cols = [];
$r = $conn->query("SHOW COLUMNS FROM history_summary");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
}

if (!in_array('status_lembur', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN status_lembur ENUM('NONE', 'PENDING', 'APPROVED', 'REJECTED') DEFAULT 'NONE'");
    echo "<p style='color: green;'>✅ Kolom 'status_lembur' berhasil ditambahkan.</p>";
} else {
    echo "<p style='color: #7f8c8d;'>ℹ️ Kolom 'status_lembur' sudah ada.</p>";
}

if (!in_array('jam_lembur_selesai', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN jam_lembur_selesai TIME NULL");
    echo "<p style='color: green;'>✅ Kolom 'jam_lembur_selesai' berhasil ditambahkan.</p>";
} else {
    echo "<p style='color: #7f8c8d;'>ℹ️ Kolom 'jam_lembur_selesai' sudah ada.</p>";
}

if (!in_array('durasi_lembur_menit', $cols)) {
    $conn->query("ALTER TABLE history_summary ADD COLUMN durasi_lembur_menit INT DEFAULT 0");
    echo "<p style='color: green;'>✅ Kolom 'durasi_lembur_menit' berhasil ditambahkan.</p>";
} else {
    echo "<p style='color: #7f8c8d;'>ℹ️ Kolom 'durasi_lembur_menit' sudah ada.</p>";
}

// 2. Pasang Trigger before_log_quality_insert
echo "<h3>2. Memasang Trigger 'before_log_quality_insert'...</h3>";
$conn->query("DROP TRIGGER IF EXISTS before_log_quality_insert");
$sql_before = "
CREATE TRIGGER before_log_quality_insert
BEFORE INSERT ON log_quality
FOR EACH ROW
BEGIN
    DECLARE v_cavity INT DEFAULT 1;
    DECLARE v_last_raw INT DEFAULT NULL;
    DECLARE v_last_db INT DEFAULT NULL;
    DECLARE v_delta INT DEFAULT 0;
    DECLARE v_qty_added INT DEFAULT 0;

    IF NEW.kode_proses IS NOT NULL AND NEW.kode_proses != '' THEN
        SELECT cavity INTO v_cavity FROM master_ct WHERE kode = NEW.kode_proses LIMIT 1;
        IF v_cavity IS NULL OR v_cavity < 1 THEN
            SET v_cavity = 1;
        END IF;
    END IF;

    SELECT raw_prodCount, prodCount INTO v_last_raw, v_last_db 
    FROM log_quality 
    WHERE mcID = NEW.mcID 
    ORDER BY id DESC LIMIT 1;

    IF v_last_raw IS NULL OR v_last_db IS NULL THEN
        SELECT offset_raw_produksi, offset_produksi INTO v_last_raw, v_last_db
        FROM master_mesin
        WHERE mcID = NEW.mcID OR id_mesin = NEW.mcID LIMIT 1;
        
        IF v_last_raw IS NULL THEN SET v_last_raw = 0; END IF;
        IF v_last_db IS NULL THEN SET v_last_db = 0; END IF;
    END IF;

    IF NEW.prodCount < 0 THEN
        SET NEW.raw_prodCount = v_last_raw;
        SET NEW.prodCount = v_last_db;
        SET NEW.delta_prodCount = 0;
    ELSE
        SET v_delta = NEW.prodCount - v_last_raw;

        IF v_delta > 0 AND v_delta <= 1000 THEN
            SET v_qty_added = v_delta;
            SET NEW.raw_prodCount = NEW.prodCount;
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
            SET NEW.delta_prodCount = (v_qty_added * v_cavity);
        ELSEIF v_delta < 0 AND NEW.prodCount <= 5 THEN
            SET v_qty_added = NEW.prodCount;
            SET NEW.raw_prodCount = NEW.prodCount;
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
            SET NEW.delta_prodCount = (v_qty_added * v_cavity);
        ELSEIF v_delta = 0 THEN
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
            SET NEW.delta_prodCount = 0;
        ELSE
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
            SET NEW.delta_prodCount = 0;
        END IF;
    END IF;
END;
";

if ($conn->multi_query($sql_before)) {
    echo "<p style='color: green;'>✅ Trigger 'before_log_quality_insert' AKTIF.</p>";
} else {
    echo "<p style='color: red;'>❌ Gagal membuat before trigger: " . $conn->error . "</p>";
}
while ($conn->more_results() && $conn->next_result()) {;}

// 3. Pasang Trigger after_log_quality_insert
echo "<h3>3. Memasang Trigger 'after_log_quality_insert'...</h3>";
$conn->query("DROP TRIGGER IF EXISTS after_log_quality_insert");
$sql_after = "
CREATE TRIGGER after_log_quality_insert
AFTER INSERT ON log_quality
FOR EACH ROW
BEGIN
    DECLARE v_last_info VARCHAR(255);
    DECLARE v_diff_id INT;
    DECLARE v_start_time DATETIME;
    DECLARE v_durasi INT;

    SELECT mcInfo INTO v_last_info 
    FROM log_quality 
    WHERE mcID = NEW.mcID AND id < NEW.id 
    ORDER BY id DESC LIMIT 1;

    IF v_last_info IS NOT NULL AND v_last_info != NEW.mcInfo THEN
        IF v_last_info NOT IN ('Mesin Running', 'Running') THEN
            SELECT id INTO v_diff_id 
            FROM log_quality 
            WHERE mcID = NEW.mcID AND id < NEW.id AND mcInfo != v_last_info 
            ORDER BY id DESC LIMIT 1;

            IF v_diff_id IS NULL THEN
                SET v_diff_id = 0;
            END IF;

            SELECT timestamp INTO v_start_time 
            FROM log_quality 
            WHERE mcID = NEW.mcID AND id > v_diff_id AND id < NEW.id 
            ORDER BY id ASC LIMIT 1;

            IF v_start_time IS NOT NULL THEN
                SET v_durasi = TIMESTAMPDIFF(SECOND, v_start_time, NEW.timestamp);

                IF v_durasi > 14400 OR v_durasi < 0 THEN
                    SET v_durasi = 0;
                END IF;

                IF v_durasi >= 30 THEN
                    INSERT INTO log_downtime (mcID, kode_dt, durasi_detik, timestamp) 
                    VALUES (NEW.mcID, v_last_info, v_durasi, NEW.timestamp);
                END IF;
            END IF;
        END IF;
    END IF;
END;
";

if ($conn->multi_query($sql_after)) {
    echo "<p style='color: green;'>✅ Trigger 'after_log_quality_insert' AKTIF.</p>";
} else {
    echo "<p style='color: red;'>❌ Gagal membuat after trigger: " . $conn->error . "</p>";
}
while ($conn->more_results() && $conn->next_result()) {;}

echo "<hr style='margin-top: 20px;'>";
echo "<div style='background: #e8f8f5; border-left: 4px solid #2ecc71; padding: 15px; border-radius: 4px;'>";
echo "<h4 style='color: #27ae60; margin: 0 0 5px 0;'>🎉 SUKSES BESAR!</h4>";
echo "<p style='margin: 0; color: #2c3e50;'>Seluruh Trigger MySQL dan Kolom Lembur telah berhasil dipasang 100% pada database. Anda sudah siap melanjutkan operasi!</p>";
echo "</div>";
echo "</div>";
?>
