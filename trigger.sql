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

    -- Fetch current cavity
    IF NEW.kode_proses IS NOT NULL AND NEW.kode_proses != '' THEN
        SELECT cavity INTO v_cavity FROM master_ct WHERE kode = NEW.kode_proses LIMIT 1;
        IF v_cavity IS NULL OR v_cavity < 1 THEN
            SET v_cavity = 1;
        END IF;
    END IF;

    -- Fetch the last raw_prodCount and db prodCount for this machine
    SELECT raw_prodCount, prodCount INTO v_last_raw, v_last_db 
    FROM log_quality 
    WHERE mcID = NEW.mcID 
    ORDER BY id DESC LIMIT 1;

    -- If no records in log_quality (e.g., after daily reset), fetch from master_mesin!
    IF v_last_raw IS NULL OR v_last_db IS NULL THEN
        SELECT offset_raw_produksi, offset_produksi INTO v_last_raw, v_last_db
        FROM master_mesin
        WHERE mcID = NEW.mcID OR id_mesin = NEW.mcID LIMIT 1;
        
        IF v_last_raw IS NULL THEN SET v_last_raw = 0; END IF;
        IF v_last_db IS NULL THEN SET v_last_db = 0; END IF;
    END IF;

    -- Calculate delta (Node-RED sends cumulative counter, so difference is the new strokes)
    SET v_delta = NEW.prodCount - v_last_raw;

    -- Handle ESP32 reset (delta < 0 means it reset to 0)
    IF v_delta < 0 THEN
        SET v_qty_added = NEW.prodCount;
    ELSEIF v_delta > 0 THEN
        SET v_qty_added = v_delta;
    ELSE
        SET v_qty_added = 0;
    END IF;

    -- Set the raw value to the new column for future reference (to calculate delta next time)
    SET NEW.raw_prodCount = NEW.prodCount;

    -- Multiply the added qty by cavity and add to the last DB prodCount
    -- This stores the correct cumulative total with historical cavity multipliers locked in!
    SET NEW.prodCount = v_last_db + (v_qty_added * v_cavity);
END;
//

DELIMITER ;
