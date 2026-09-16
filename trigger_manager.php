<?php
/**
 * trigger_manager.php
 * Pengelola dan Pemasang Trigger Otomatis MySQL SMMS / IoT MES
 */

function installDatabaseTriggers($conn) {
    // 1. Bersihkan trigger lama jika ada
    $conn->query("DROP TRIGGER IF EXISTS before_log_quality_insert");
    $conn->query("DROP TRIGGER IF EXISTS after_log_quality_insert");

    // 2. Trigger BEFORE INSERT (Anti-Freeze, Auto-Cavity, Offset Sync)
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

    -- Ambil faktor cavity dari master_ct
    IF NEW.kode_proses IS NOT NULL AND NEW.kode_proses != '' THEN
        SELECT cavity INTO v_cavity FROM master_ct WHERE kode = NEW.kode_proses LIMIT 1;
        IF v_cavity IS NULL OR v_cavity < 1 THEN
            SET v_cavity = 1;
        END IF;
    END IF;

    -- Ambil counter terakhir mesin
    SELECT raw_prodCount, prodCount INTO v_last_raw, v_last_db 
    FROM log_quality 
    WHERE mcID = NEW.mcID 
    ORDER BY id DESC LIMIT 1;

    -- Jika tabel log_quality kosong pasca reset shift, ambil dari master_mesin
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

        -- Normal increment (menerima lonjakan wajar hingga 1000 saat restart)
        IF v_delta > 0 AND v_delta <= 1000 THEN
            SET v_qty_added = v_delta;
            SET NEW.raw_prodCount = NEW.prodCount;
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
            SET NEW.delta_prodCount = (v_qty_added * v_cavity);
        -- Reset hardware ESP32 / Arduino (delta < 0 dan nilai baru <= 5)
        ELSEIF v_delta < 0 AND NEW.prodCount <= 5 THEN
            SET v_qty_added = NEW.prodCount;
            SET NEW.raw_prodCount = NEW.prodCount;
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
            SET NEW.delta_prodCount = (v_qty_added * v_cavity);
        -- Tanpa perubahan part (heartbeat snapshot)
        ELSEIF v_delta = 0 THEN
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
            SET NEW.delta_prodCount = 0;
        -- Spike anomali tak wajar (> 1000)
        ELSE
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
            SET NEW.delta_prodCount = 0;
        END IF;
    END IF;
END;
    ";

    if (!$conn->multi_query($sql_before)) {
        return ['success' => false, 'message' => "Gagal membuat trigger before: " . $conn->error];
    }
    while ($conn->more_results() && $conn->next_result()) {;}

    // 3. Trigger AFTER INSERT (Perhitungan Downtime Presisi Lintas Jam & Malam)
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
        -- Catat semua status non-running (tombol downtime & Mesin Off berdurasi)
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

                -- Batas maksimal 4 jam (14400 detik) untuk mengabaikan downtime anomali/pabrik libur lama
                IF v_durasi > 14400 OR v_durasi < 0 THEN
                    SET v_durasi = 0;
                END IF;

                -- Hanya rekam downtime jika durasi valid minimal 30 detik (menghilangkan bounce tombol)
                IF v_durasi >= 30 THEN
                    INSERT INTO log_downtime (mcID, kode_dt, durasi_detik, timestamp) 
                    VALUES (NEW.mcID, v_last_info, v_durasi, NEW.timestamp);
                END IF;
            END IF;
        END IF;
    END IF;
END;
    ";

    if (!$conn->multi_query($sql_after)) {
        return ['success' => false, 'message' => "Gagal membuat trigger after: " . $conn->error];
    }
    while ($conn->more_results() && $conn->next_result()) {;}

    return ['success' => true, 'message' => "Trigger berhasil dipasang 100%"];
}
