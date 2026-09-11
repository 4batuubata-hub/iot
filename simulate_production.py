#!/usr/bin/env python3
"""
SIMULATOR PRODUKSI REALISTIS 10 MESIN (8 JAM) - PT CNC OEE SMART MONITORING
===========================================================================
Fitur Utama:
1. Menjalankan 10 mesin sekaligus secara simultan (multi-threading / async loop).
2. Data diambil langsung dari tabel master MySQL:
   - master_mesin (10 mesin terpilih)
   - master_ct (432 part & cycle time acak dinamis)
   - master_operator (807 operator, rotasi berkala)
   - master_downtime (26 jenis losstime: problem mesin, dandory, toilet, dll)
   - master_defect (55 jenis defect / NG)
3. Kecepatan & tempo manusiawi berbasis Cycle Time (ct_pcs) dari master_ct.
4. Profil OEE bervariasi dari sangat rendah (20-40%) hingga unggul (>100%).
5. Menulis langsung ke log_quality (memicu trigger cavity MySQL), log_downtime, dan log_ng.
6. Opsi `--speed` untuk akselerasi (misal 5x / 10x) atau realtime 1x selama 8 jam.
7. Opsi `--mqtt` untuk mengirim ke Mosquitto (SMMS/Data/Produksi).
"""

import sys
import os
import time
import random
import argparse
import signal
import threading
from datetime import datetime, timedelta

# Fix Windows console UnicodeEncodeError for UTF-8 / cp1252
if hasattr(sys.stdout, 'reconfigure'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass
if hasattr(sys.stderr, 'reconfigure'):
    try:
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

import pymysql

# Coba import paho-mqtt jika tersedia
try:
    import paho.mqtt.client as mqtt
    MQTT_AVAILABLE = True
except ImportError:
    MQTT_AVAILABLE = False

# Konfigurasi Default Database
DB_CONFIG = {
    'host': '127.0.0.1',
    'port': 3306,
    'user': 'root',
    'password': '',
    'database': 'simulasi',
    'charset': 'utf8mb4',
    'autocommit': True
}

# 4 PROFIL KEPRIBADIAN KERJA MESIN (Untuk menguji spektrum OEE)
MACHINE_PROFILES = [
    # Mesin 1 & 2: SUPER FAST / HIGH PERFORMER (OEE > 100%)
    {
        'label': 'Super Fast (OEE >100%)',
        'speed_mult_range': (0.82, 0.92),  # Lebih cepat 8-18% dari CT standar
        'dt_chance_per_min': 0.008,        # Sangat jarang downtime
        'dt_duration_range': (20, 60),     # Toilet / minum sebentar
        'ng_rate': 0.001,                  # Nyaris 0% NG
        'preferred_dt': ['Toilet', 'Minum', '5P/5R']
    },
    {
        'label': 'Super Fast (OEE >100%)',
        'speed_mult_range': (0.85, 0.94),
        'dt_chance_per_min': 0.010,
        'dt_duration_range': (30, 90),
        'ng_rate': 0.002,
        'preferred_dt': ['Toilet', 'Minum', 'P5M']
    },
    # Mesin 3 & 4: GOOD STANDARD (OEE 80% - 90%)
    {
        'label': 'Good Standard (80-90%)',
        'speed_mult_range': (0.96, 1.04),  # Sesuai CT ideal
        'dt_chance_per_min': 0.025,        # Wajar
        'dt_duration_range': (60, 240),
        'ng_rate': 0.008,                  # ~0.8% NG
        'preferred_dt': ['Toilet', 'Minum', 'Refill Material', '5P/5R']
    },
    {
        'label': 'Good Standard (80-90%)',
        'speed_mult_range': (0.97, 1.05),
        'dt_chance_per_min': 0.028,
        'dt_duration_range': (60, 240),
        'ng_rate': 0.010,
        'preferred_dt': ['Toilet', 'Refill Material', 'Dandory', 'P5M']
    },
    # Mesin 5 & 6: MODEST / AVERAGE (OEE 70% - 79%)
    {
        'label': 'Average (70-79%)',
        'speed_mult_range': (1.05, 1.15),  # Sedikit lebih lambat
        'dt_chance_per_min': 0.040,
        'dt_duration_range': (120, 400),
        'ng_rate': 0.018,                  # ~1.8% NG
        'preferred_dt': ['Refill Material', 'Dandory', 'Tunggu Material', 'Operator Izin']
    },
    {
        'label': 'Average (70-79%)',
        'speed_mult_range': (1.06, 1.18),
        'dt_chance_per_min': 0.045,
        'dt_duration_range': (120, 420),
        'ng_rate': 0.020,
        'preferred_dt': ['Dandory', 'Refill Material', 'Teaching', '5P/5R']
    },
    # Mesin 7 & 8: LOW PERFORMER (OEE 50% - 65%)
    {
        'label': 'Low Performer (50-65%)',
        'speed_mult_range': (1.20, 1.35),  # Operator lambat
        'dt_chance_per_min': 0.070,
        'dt_duration_range': (240, 700),
        'ng_rate': 0.035,                  # ~3.5% NG
        'preferred_dt': ['Tunggu Material', 'Problem Jig Proses', 'Material Habis', 'Operator Izin']
    },
    {
        'label': 'Low Performer (50-65%)',
        'speed_mult_range': (1.22, 1.38),
        'dt_chance_per_min': 0.075,
        'dt_duration_range': (300, 800),
        'ng_rate': 0.040,
        'preferred_dt': ['Tunggu Material', 'Dandory', 'Problem Insp Jig', 'Material Habis']
    },
    # Mesin 9 & 10: CRITICAL / BREAKDOWN (OEE 20% - 40%)
    {
        'label': 'Critical Problem (20-40%)',
        'speed_mult_range': (1.30, 1.50),  # Lambat
        'dt_chance_per_min': 0.120,        # Sering rusak/berhenti
        'dt_duration_range': (600, 2400),  # Downtime lama (10 - 40 menit)
        'ng_rate': 0.065,                  # Banyak cacat (6.5%)
        'preferred_dt': ['Problem Mesin', 'Perawatan Maintanance', 'Problem Qualitas', 'QC Trial']
    },
    {
        'label': 'Critical Problem (20-40%)',
        'speed_mult_range': (1.35, 1.55),
        'dt_chance_per_min': 0.130,
        'dt_duration_range': (900, 2700),  # Downtime sangat lama (15 - 45 menit)
        'ng_rate': 0.075,
        'preferred_dt': ['Problem Mesin', 'Perawatan Maintanance', 'Tidak Ada Planning', 'Problem Qualitas']
    }
]

class MachineWorker:
    def __init__(self, idx, machine_info, profile, master_data, speed=1.0, use_mqtt=False, mqtt_client=None, start_prod=0, start_ng=0):
        self.idx = idx
        self.mcID = machine_info['id_mesin'] or machine_info['mcID']
        self.raw_mcID = machine_info['mcID']
        self.nama_mesin = machine_info['nama_mesin']
        self.profile = profile
        self.master_data = master_data
        self.speed = speed
        self.use_mqtt = use_mqtt
        self.mqtt_client = mqtt_client
        
        # State Operasional
        self.operator = random.choice(master_data['operators'])
        self.part = random.choice(master_data['parts'])
        self.raw_stroke = start_prod
        self.ng_count = start_ng
        self.ok_count = max(0, self.raw_stroke - self.ng_count)
        
        # Status Kerja
        self.status = 'run'
        self.mc_info = 'Mesin Running'
        self.in_downtime = False
        self.dt_code = None
        self.dt_start_sim_time = 0
        self.dt_duration_sec = 0
        
        # Timing
        self.next_part_change_time = 0
        self.next_op_change_time = 0
        self.next_stroke_time = 0
        self.last_heartbeat_time = 0
        
        # OEE Estimasi
        self.approx_oee = 0.0

    def init_schedule(self, current_sim_time):
        # Waktu ganti model: antara 1.5 s/d 3 jam ke depan
        self.next_part_change_time = current_sim_time + random.uniform(5400, 10800)
        # Waktu ganti operator: antara 2 s/d 4 jam ke depan
        self.next_op_change_time = current_sim_time + random.uniform(7200, 14400)
        # Jadwal stroke pertama
        self.schedule_next_stroke(current_sim_time)

    def schedule_next_stroke(self, current_sim_time):
        ct = float(self.part.get('ct_pcs') or 15.0)
        if ct <= 0:
            ct = 15.0
        # Kecepatan pengerjaan dipengaruhi speed factor profil operator
        mult = random.uniform(*self.profile['speed_mult_range'])
        # Tambahkan sedikit variasi acak stroke-by-stroke (+/- 5%)
        jitter = random.uniform(0.95, 1.05)
        duration = ct * mult * jitter
        self.next_stroke_time = current_sim_time + duration

    def step(self, current_sim_time, delta_sim_sec, db_conn):
        """Dijalankan setiap tick simulasi"""
        
        # 1. CEK ROTASI OPERATOR
        if current_sim_time >= self.next_op_change_time and not self.in_downtime:
            old_op = self.operator['nama']
            self.operator = random.choice(self.master_data['operators'])
            self.next_op_change_time = current_sim_time + random.uniform(7200, 14400)

        # 2. CEK PERGANTIAN MODEL / PART (DANDORY)
        if current_sim_time >= self.next_part_change_time and not self.in_downtime:
            self.start_downtime(current_sim_time, 'Dandory', random.uniform(300, 900))
            # Pilih part baru
            self.part = random.choice(self.master_data['parts'])
            self.next_part_change_time = current_sim_time + random.uniform(5400, 10800)

        # 3. KONDISI DALAM DOWNTIME
        if self.in_downtime:
            if current_sim_time >= (self.dt_start_sim_time + self.dt_duration_sec):
                # Downtime Selesai!
                self.end_downtime(db_conn)
                self.schedule_next_stroke(current_sim_time)

        # 4. KONDISI NORMAL RUNNING
        else:
            # Cek apakah mesin memicu downtime acak
            # Peluang dt dihitung dari chance_per_min dikali delta waktu
            dt_prob = (self.profile['dt_chance_per_min'] / 60.0) * delta_sim_sec
            if random.random() < dt_prob:
                # Pilih kode downtime dari preferensi profil atau random master_downtime
                if self.profile['preferred_dt'] and random.random() < 0.8:
                    chosen_dt = random.choice(self.profile['preferred_dt'])
                else:
                    chosen_dt = random.choice(self.master_data['downtimes'])['kode_dt']
                
                dt_dur = random.uniform(*self.profile['dt_duration_range'])
                self.start_downtime(current_sim_time, chosen_dt, dt_dur)
            
            # Cek penyelesaian stroke produksi
            elif current_sim_time >= self.next_stroke_time:
                # Selesai 1 stroke fisik!
                self.raw_stroke += 1
                
                # Cek apakah produk ini NG (Defect)?
                if random.random() < self.profile['ng_rate']:
                    self.ng_count += 1
                    defect = random.choice(self.master_data['defects'])
                    self.record_ng(db_conn, defect['kode_defect'])
                
                self.ok_count = max(0, self.raw_stroke - self.ng_count)
                
                # Kirim log seketika saat stroke (supaya berurutan 1, 2, 3...)
                self.send_heartbeat(db_conn)
                
                self.schedule_next_stroke(current_sim_time)

    def start_downtime(self, current_sim_time, dt_code, duration_sec):
        self.in_downtime = True
        self.dt_code = dt_code
        self.dt_start_sim_time = current_sim_time
        self.dt_duration_sec = duration_sec
        
        # Klasifikasi status
        if dt_code in ['Problem Mesin', 'Perawatan Maintanance']:
            self.status = 'alarm'
        elif dt_code in ['Mesin Off', 'Off']:
            self.status = 'off'
        else:
            self.status = 'standBy'
            
        self.mc_info = dt_code

    def end_downtime(self, db_conn):
        # Rekam ke log_downtime
        durasi = int(round(self.dt_duration_sec))
        if durasi > 0:
            try:
                with db_conn.cursor() as cursor:
                    sql = "INSERT INTO log_downtime (mcID, kode_dt, durasi_detik, timestamp) VALUES (%s, %s, %s, NOW())"
                    cursor.execute(sql, (self.mcID, self.dt_code, durasi))
            except Exception:
                pass
            
            # MQTT jika aktif
            if self.use_mqtt and self.mqtt_client:
                try:
                    payload = f'{{"mcID":"{self.mcID}","kode_dt":"{self.dt_code}","durasi_detik":{durasi}}}'
                    self.mqtt_client.publish("SMMS/Data/Downtime", payload)
                except Exception:
                    pass

        self.in_downtime = False
        self.status = 'run'
        self.mc_info = 'Mesin Running'
        self.dt_code = None

    def record_ng(self, db_conn, kode_defect):
        try:
            with db_conn.cursor() as cursor:
                sql = "INSERT INTO log_ng (mcID, kode_proses, kode_ng, qty_ng, timestamp) VALUES (%s, %s, %s, 1, NOW())"
                cursor.execute(sql, (self.mcID, self.part['kode'], kode_defect))
        except Exception:
            pass
            
        if self.use_mqtt and self.mqtt_client:
            try:
                payload = f'{{"mcID":"{self.mcID}","kode_proses":"{self.part["kode"]}","kode_ng":"{kode_defect}","qty_ng":1,"kategori":"Tambah NG"}}'
                self.mqtt_client.publish("SMMS/Data/NG", payload)
            except Exception:
                pass

    def send_heartbeat(self, db_conn):
        """Kirim snapshot ke log_quality setiap 2 detik"""
        try:
            with db_conn.cursor() as cursor:
                # Trigger before_log_quality_insert di database MySQL akan secara otomatis:
                # 1. Menghitung delta stroke fisik
                # 2. Mengalikan dengan cavity dari master_ct
                # 3. Mengupdate prodCount akumulasi
                sql = """INSERT INTO log_quality 
                         (mcID, kode_proses, op_NIK, mcStatus, mcInfo, prodCount, NGCount, timestamp) 
                         VALUES (%s, %s, %s, %s, %s, %s, %s, NOW())"""
                cursor.execute(sql, (
                    self.mcID,
                    self.part['kode'],
                    self.operator['nik'],
                    self.status,
                    self.mc_info,
                    self.raw_stroke,
                    self.ng_count
                ))
        except Exception:
            pass

        if self.use_mqtt and self.mqtt_client:
            try:
                payload = f'{{"mcID":"{self.mcID}","op_NIK":"{self.operator["nik"]}","partID":"{self.part["part_name"]}","kode_proses":"{self.part["kode"]}","mcStatus":"{self.status}","mcInfo":"{self.mc_info}","prodCount":{self.raw_stroke},"OKCount":{self.ok_count},"NGCount":{self.ng_count}}}'
                self.mqtt_client.publish("SMMS/Data/Produksi", payload)
            except Exception:
                pass


def load_master_data(conn):
    """Load semua data master dari database MySQL simulasi"""
    data = {}
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        # 1. Mesin (ambil 10 mesin pertama yang valid)
        cur.execute("SELECT mcID, id_mesin, nama_mesin, offset_produksi FROM master_mesin WHERE id_mesin IS NOT NULL AND id_mesin != '' ORDER BY mcID ASC LIMIT 10")
        data['machines'] = cur.fetchall()
        if len(data['machines']) < 10:
            cur.execute("SELECT mcID, id_mesin, nama_mesin, offset_produksi FROM master_mesin LIMIT 10")
            data['machines'] = cur.fetchall()

        # 2. Master Cycle Time / Part
        cur.execute("SELECT kode, part_name, part_number, proses_name, ct_pcs, ct_jam, IFNULL(cavity, 1) as cavity, line FROM master_ct WHERE ct_pcs > 0")
        data['parts'] = cur.fetchall()

        # 3. Master Operator
        cur.execute("SELECT nik, nama FROM master_operator WHERE nik IS NOT NULL AND nik != ''")
        data['operators'] = cur.fetchall()

        # 4. Master Downtime
        cur.execute("SELECT kode_dt, label_dt FROM master_downtime")
        data['downtimes'] = cur.fetchall()

        # 5. Master Defect
        cur.execute("SELECT kode_defect, keterangan FROM master_defect")
        data['defects'] = cur.fetchall()

    return data


def format_seconds(sec):
    hrs = int(sec // 3600)
    mins = int((sec % 3600) // 60)
    secs = int(sec % 60)
    return f"{hrs:02d}:{mins:02d}:{secs:02d}"


def main():
    parser = argparse.ArgumentParser(description="Simulator Produksi 10 Mesin OEE PT CNC (8 Jam)")
    parser.add_argument("--hours", type=float, default=8.0, help="Durasi simulasi dalam jam (default: 8.0 jam)")
    parser.add_argument("--speed", type=float, default=1.0, help="Faktor akselerasi kecepatan (1.0 = realtime, 10.0 = 10x lebih cepat)")
    parser.add_argument("--interval", type=float, default=2.0, help="Interval pengiriman log_quality dalam detik (default: 2.0s)")
    parser.add_argument("--reset-logs", action="store_true", help="Bersihkan data log_quality, log_ng, log_downtime hari ini sebelum mulai")
    parser.add_argument("--mqtt", action="store_true", help="Kirim juga payload MQTT ke localhost:1883")
    args = parser.parse_args()

    print("\n" + "="*80)
    print(" [SIMULASI] SIMULATOR REALISTIS 10 MESIN - PT CNC SMART OEE SYSTEM")
    print("="*80)
    print(f" Target Durasi : {args.hours} Jam ({args.hours * 3600:,.0f} detik waktu produksi)")
    print(f" Mode Pacing   : {'REALTIME (1 detik nyata = 1 detik mesin)' if args.speed == 1.0 else f'ACCELERATED ({args.speed}x kecepatan)'}")
    print(f" Log Interval  : Setiap {args.interval} detik")
    print("="*80)

    # Koneksi awal MySQL
    try:
        conn = pymysql.connect(**DB_CONFIG)
        print(" [OK] Berhasil terhubung ke database MySQL 'simulasi'")
    except Exception as e:
        print(f" [ERROR] Gagal terhubung ke database MySQL: {e}")
        sys.exit(1)

    # Reset logs jika diminta
    if args.reset_logs:
        print(" [!] Membersihkan data aktif log_quality, log_ng, log_downtime...")
        with conn.cursor() as cur:
            cur.execute("TRUNCATE TABLE log_quality")
            cur.execute("TRUNCATE TABLE log_ng")
            cur.execute("TRUNCATE TABLE log_downtime")
        print(" [OK] Log aktif berhasil dibersihkan.")

    # Load master data
    print(" [*] Memuat master data (mesin, cycle time, operator, downtime, defect)...")
    master_data = load_master_data(conn)
    print(f"     - Mesin Terpilih : {len(master_data['machines'])} unit")
    print(f"     - Master CT/Part : {len(master_data['parts'])} model")
    print(f"     - Master Operator: {len(master_data['operators'])} orang")
    print(f"     - Master Downtime: {len(master_data['downtimes'])} kode")
    print(f"     - Master Defect  : {len(master_data['defects'])} kode")

    # Inisialisasi MQTT jika aktif
    mqtt_client = None
    if args.mqtt:
        if not MQTT_AVAILABLE:
            print(" [WARNING] Library 'paho-mqtt' tidak terinstall, melewati MQTT.")
        else:
            try:
                mqtt_client = mqtt.Client(client_id="SMMS_Python_Simulator")
                mqtt_client.connect("127.0.0.1", 1883, 60)
                mqtt_client.loop_start()
                print(" [OK] Terhubung ke Mosquitto MQTT Broker (localhost:1883)")
            except Exception as e:
                print(f" [WARNING] Gagal terhubung ke MQTT Broker: {e}")
                mqtt_client = None

    # Inisialisasi 10 Worker Mesin
    workers = []
    print("\n [*] Mengonfigurasi 10 Mesin dengan Profil Variasi OEE:")
    for i, m_info in enumerate(master_data['machines'][:10]):
        prof = MACHINE_PROFILES[i % len(MACHINE_PROFILES)]
        
        # Ambil stroke terakhir dari database agar count melanjutkan
        start_prod = 0
        start_ng = 0
        if not args.reset_logs:
            try:
                with conn.cursor() as cur:
                    mc_id_val = m_info['id_mesin'] or m_info['mcID']
                    cur.execute("SELECT prodCount, NGCount FROM log_quality WHERE mcID = %s ORDER BY timestamp DESC LIMIT 1", (mc_id_val,))
                    last_log = cur.fetchone()
                    if last_log:
                        start_prod = last_log[0] or 0
                        start_ng = last_log[1] or 0
                    else:
                        start_prod = m_info.get('offset_produksi') or 0
            except:
                pass

        worker = MachineWorker(
            idx=i+1,
            machine_info=m_info,
            profile=prof,
            master_data=master_data,
            speed=args.speed,
            use_mqtt=args.mqtt,
            mqtt_client=mqtt_client,
            start_prod=start_prod,
            start_ng=start_ng
        )
        worker.init_schedule(0)
        workers.append(worker)
        print(f"     {i+1:02d}. [{worker.mcID:<14}] {worker.nama_mesin:<15} | Profil: {prof['label']}")

    # Setup Graceful Shutdown
    running = True
    def signal_handler(sig, frame):
        nonlocal running
        print("\n\n [!] Menerima sinyal STOP (Ctrl+C). Menghentikan simulasi dengan aman...")
        running = False

    signal.signal(signal.SIGINT, signal_handler)

    total_target_sim_sec = args.hours * 3600.0
    sim_time = 0.0
    last_real_time = time.time()
    last_ui_print = 0.0
    last_hb_time = 0.0

    print("\n" + "="*80)
    print(" [MULAI] SIMULASI SEDANG BERJALAN! Tekan Ctrl+C untuk berhenti kapan saja.")
    print(" Buka web browser di http://localhost/iot/user/ untuk melihat dashboard live.")
    print("="*80 + "\n")

    # Loop Utama Simulasi
    while running and sim_time < total_target_sim_sec:
        now_real = time.time()
        delta_real = now_real - last_real_time
        last_real_time = now_real

        # Waktu simulasi maju sesuai faktor speed
        delta_sim = delta_real * args.speed
        sim_time += delta_sim

        # 1. Step setiap mesin
        for w in workers:
            w.step(sim_time, delta_sim, conn)

        # 2. Heartbeat berkala ke log_quality
        if (sim_time - last_hb_time) >= args.interval:
            last_hb_time = sim_time
            for w in workers:
                w.send_heartbeat(conn)

        # 3. Cetak status live console tiap ~3 detik nyata
        if (now_real - last_ui_print) >= 3.0:
            last_ui_print = now_real
            os.system('cls' if os.name == 'nt' else 'clear')
            elapsed_str = format_seconds(sim_time)
            target_str = format_seconds(total_target_sim_sec)
            pct = (sim_time / total_target_sim_sec) * 100

            print(f"[STATUS] PT CNC OEE SIMULATOR | Waktu Shift: [{elapsed_str} / {target_str}] ({pct:.1f}%) | Speed: {args.speed:.1f}x")
            print("-" * 105)
            print(f"{'No':<3} {'ID Mesin':<14} {'Operator':<18} {'Part Name / Proses':<28} {'Status':<10} {'Output':<10} {'NG':<5} {'Info / Downtime'}")
            print("-" * 105)
            
            for w in workers:
                p_desc = f"{w.part['part_name'][:14]} ({w.part['proses_name'][:10]})"
                st_color = "RUNNING" if w.status == 'run' else ("STANDBY" if w.status == 'standBy' else "ALARM")
                print(f"{w.idx:<3} {w.mcID:<14} {w.operator['nama'][:16]:<18} {p_desc:<28} {st_color:<10} {w.raw_stroke:<10} {w.ng_count:<5} {w.mc_info}")
                
            print("-" * 105)
            print(" Tekan Ctrl+C untuk berhenti | Web Dashboard: http://localhost/iot/user/")

        # Istirahat pendek agar tidak menghabiskan 100% CPU
        sleep_needed = (args.interval / args.speed) / 5.0
        time.sleep(max(0.05, min(0.5, sleep_needed)))

    print("\n" + "="*80)
    print(" [SELESAI] SIMULASI SELESAI!")
    print(f" Total Waktu Produksi yang Ter-simulasi : {format_seconds(sim_time)}")
    print(" Semua data produksi, downtime, dan reject telah tercatat rapi di database.")
    print("="*80 + "\n")

    if mqtt_client:
        mqtt_client.loop_stop()
        mqtt_client.disconnect()
    conn.close()

if __name__ == '__main__':
    main()
