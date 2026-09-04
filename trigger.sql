DELIMITER //

DROP TRIGGER IF EXISTS before_log_quality_insert//

CREATE TRIGGER before_log_quality_insert
BEFORE INSERT ON log_quality
FOR EACH ROW
BEGIN
    DECLARE v_cavity INT DEFAULT 1;
    DECLARE v_last_raw INT DEFAULT NULL;
    DECLARE v_last_db INT DEFAULT NULL;
    DECLARE v_delta INT DEFAULT 0;
    DECLARE v_qty_added INT DEFAULT 0;

    -- 1. Ambil nilai cavity dari master_ct berdasarkan kode_proses
    IF NEW.kode_proses IS NOT NULL AND NEW.kode_proses != '' THEN
        SELECT cavity INTO v_cavity FROM master_ct WHERE kode = NEW.kode_proses LIMIT 1;
        IF v_cavity IS NULL OR v_cavity < 1 THEN
            SET v_cavity = 1;
        END IF;
    END IF;

    -- 2. Ambil nilai raw_prodCount dan prodCount terakhir dari mesin ini
    SELECT raw_prodCount, prodCount INTO v_last_raw, v_last_db 
    FROM log_quality 
    WHERE mcID = NEW.mcID 
    ORDER BY id DESC LIMIT 1;

    -- Jika belum ada di log_quality (misal setelah reset shift), ambil dari master_mesin
    IF v_last_raw IS NULL OR v_last_db IS NULL THEN
        SELECT offset_raw_produksi, offset_produksi INTO v_last_raw, v_last_db
        FROM master_mesin
        WHERE mcID = NEW.mcID OR id_mesin = NEW.mcID LIMIT 1;
        
        IF v_last_raw IS NULL THEN SET v_last_raw = 0; END IF;
        IF v_last_db IS NULL THEN SET v_last_db = 0; END IF;
    END IF;

    -- 3. FILTER NOISE LISTRIK & NEGATIF OVERFLOW
    -- Jika angka minus (akibat int16 overflow dari Arduino), abaikan dan pertahankan data sebelumnya
    IF NEW.prodCount < 0 THEN
        SET NEW.raw_prodCount = v_last_raw;
        SET NEW.prodCount = v_last_db;
    ELSE
        -- Hitung selisih stroke fisik (raw pulse dari sensor mesin)
        SET v_delta = NEW.prodCount - v_last_raw;

        -- Jika ada kenaikan wajar (antara 1 s/d 50 stroke dalam interval 2 detik)
        IF v_delta > 0 AND v_delta <= 50 THEN
            SET v_qty_added = v_delta;
            SET NEW.raw_prodCount = NEW.prodCount;
            -- Kalikan stroke valid dengan cavity dari master_ct
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
        -- Jika terjadi reset mikrokontroler (counter kembali ke 0 atau < 5)
        ELSEIF v_delta < 0 AND NEW.prodCount <= 5 THEN
            SET v_qty_added = NEW.prodCount;
            SET NEW.raw_prodCount = NEW.prodCount;
            SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
        -- Jika nilai tidak berubah (mesin idle/standby)
        ELSEIF v_delta = 0 THEN
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
        -- Jika lonjakan melebihi batas fisik (> 50 stroke) atau anomali noise
        ELSE
            -- Tolak lonjakan noise: pertahankan nilai terakhir tanpa menambah produksi
            SET NEW.raw_prodCount = v_last_raw;
            SET NEW.prodCount = v_last_db;
        END IF;
    END IF;
END;
//

DELIMITER ;
