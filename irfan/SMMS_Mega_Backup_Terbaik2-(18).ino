/*
 * ============================================================
 *  SMART MACHINE MONITORING SYSTEM (SMMS) - PT. CNC
 *  Arduino Mega 2560 Firmware v3.0
 *  Arsitektur: Finite State Machine (FSM), Non-Blocking
 * ============================================================
 *  Hardware:
 *    - Arduino Mega 2560 + Ethernet Shield W5100
 *    - LCD I2C 20x4 (Address 0x27)
 *    - Keypad 4x4 (Membrane)
 *    - RFID PN532 I2C (Address 0x24)
 *    - EEPROM AT24C256 I2C (Address 0x50)
 *    - RTC DS3231 I2C
 *    - Arduino Nano (I2C Slave Address 8, Counter)
 *    - 25 Tombol Downtime Fisik
 *    - Interlock Relay (Pin A13)
 *
 *  MQTT Topics:
 *    PUB -> SMMS/Request/Setup, SMMS/Request/Login,
 *           SMMS/Request/Proses, SMMS/Data/Produksi,
 *           SMMS/Data/NG, SMMS/Data/Downtime
 *    SUB <- SMMS/Reply/Setup, SMMS/Reply/Login, SMMS/Reply/Proses
 *
 *  Koneksi I2C (SDA=20, SCL=21) — Shared Bus:
 *    LCD (0x27), EEPROM (0x50), RTC (0x68), PN532 (0x24), Nano (0x08)
 * ============================================================
 */
// =====================================================================
//                          LIBRARY
// =====================================================================
#include <SPI.h>              // Untuk Ethernet Shield W5100
#include <Ethernet.h>         // Koneksi jaringan LAN
#include <PubSubClient.h>     // MQTT Client
#include <ArduinoJson.h>      // Parsing & build JSON
#include <Wire.h>             // I2C Bus (LCD, EEPROM, RFID, RTC, Nano)
#include <LiquidCrystal_I2C.h>// LCD 20x4
#include <Keypad.h>           // Keypad 4x4
#include <Adafruit_PN532.h>   // RFID PN532 via I2C
#include <RTClib.h>           // RTC DS3231
// =====================================================================
//                    KONFIGURASI JARINGAN & MQTT
// =====================================================================
// MAC Address unik untuk Ethernet Shield (sudah tersolder)
byte mac[] = { 0xDE, 0xAD, 0xBA, 0xEF, 0xBA, 0xCA };
// IP statis Arduino Mega di jaringan lokal pabrik
IPAddress ip(10, 10, 10, 200);
// IP MQTT Broker (Node-RED Server)
IPAddress mqttServer(10, 10, 10, 188);
const int mqttPort = 1883;
// Object koneksi jaringan
EthernetClient ethClient;
PubSubClient client(ethClient);
// =====================================================================
//                    KONFIGURASI I2C DEVICES
// =====================================================================
// LCD I2C 20x4 di alamat 0x27
LiquidCrystal_I2C lcd(0x27, 20, 4);
// RFID PN532 diubah ke Mode Serial (UART / HSU)
// Pastikan DIP Switch di modul: 1=OFF (0), 2=OFF (0)
// Sambungkan TX Modul ke RX2 (Pin 17) dan RX Modul ke TX2 (Pin 16)
Adafruit_PN532 nfc(255, &Serial2);
// RTC DS3231
RTC_DS3231 rtc;
char timestamp[30]; // Buffer timestamp format ISO
// EEPROM AT24C256
#define EEPROM_ADDRESS   0x50   // Alamat I2C EEPROM
#define EEPROM_MCID_ADDR 10     // Alamat mulai simpan mcID di EEPROM
#define EEPROM_INIT_FLAG 0      // Alamat flag inisialisasi
// Arduino Nano I2C Slave (Counter produksi)
#define NANO_I2C_ADDR    8      // Alamat I2C Arduino Nano
// =====================================================================
//                    KONFIGURASI PIN HARDWARE
//          (Sesuai hardware yang sudah tersolder pada PCB)
// =====================================================================
// --- Input Sensor Mesin (Analog pins, aktif berdasar tegangan) ---
#define mcON         A0    // Input: Mesin ON (LOW = Mesin hidup)
#define mcRun        A1    // Input: Mesin Running (HIGH = Running)
#define alarmPin     A2    // Input: Alarm mesin (HIGH = Alarm aktif)
// --- Output Interlock ---
#define interlockPin A13   // OUTPUT: Relay Interlock Mesin (HIGH = Kunci/Stop)
// --- Tombol Navigasi (Biru pada panel) ---
#define btnCTRL      30    // Tombol CTRL (kombinasi untuk setup/reconnect)
#define btnPROG_PLUS  4    // Tombol PROGRAM+ (kombinasi untuk setup)
#define btnPROG_MINUS 42   // Tombol PROGRAM- (kombinasi untuk setup ulang)
// --- Tombol NG ---
#define ngAdd        25    // Tombol QTY NG + (Merah, tambah NG)
#define ngSub        37    // Tombol QTY NG - (Pink, repair / reset tahan 5 detik)
// --- LED Indikator ---
#define brokerLed    13    // LED hijau: status koneksi MQTT
#define stopLed      12    // LED merah: status mesin berhenti
// --- Ethernet Shield SPI ---
// CS=10 (sudah default), SS=53 harus OUTPUT (Mega requirement)
// --- 25 Tombol Downtime Fisik (Kuning pada panel) ---
//     Urutan sesuai label stiker pada panel dari kiri ke kanan, atas ke bawah
#define pbTPM              2    // pb25 - TPM
#define pbProblemQualitas  3    // pb23 - Problem Qualitas
#define pbToilet           7    // pb1  - Toilet
#define pb5P5R             6    // pb20 - 5P/5R (5S)
#define pbP5M              5    // pb12 - P5M
#define pbSarana           38   // pb24 - Persiapan Sarana Proses
#define pbProblemInspJig   40   // pb22 - Problem Insp Jig
#define pbDandory          48   // pb21 - Dandory
#define pbMinum            46   // pb16 - Minum
#define pbSholat           44   // pb19 - Sholat
#define pbRefillMaterial   26   // pb14 - Refill Material
#define pbProblemJigProses 28   // pb6  - Problem Jig Proses
#define pbProblemMesin     36   // pb7  - Problem Mesin
#define pbQCTrial          34   // pb9  - QC Trial
#define pbMaterialHabis    32   // pb11 - Material Habis
#define pbOperatorIzin     29   // pb4  - Operator Izin
#define pbNoPlanning       27   // pb2  - Tidak Ada Planning
#define pbTeaching         24   // pb18 - Teaching
#define pbPerawatan        22   // pb5  - Perawatan Maintanance
#define pbOJT              23   // pb3  - OJT
#define pbTungguMaterial   41   // pb8  - Tunggu Material
#define pbEngTrial         39   // pb10 - Engineering Trial
#define pbNozzle           31   // pb17 - Nozzle / Contact Tip
#define pbWireLas          33   // pb15 - Wire Las Macet
#define pbTambahanProses   35   // pb13 - Tambahan Proses
// =====================================================================
//                      KEYPAD 4x4
// =====================================================================
const byte ROWS = 4;
const byte COLS = 4;
char keys[ROWS][COLS] = {
  {'1', '2', '3', 'A'},
  {'4', '5', '6', 'B'},
  {'7', '8', '9', 'C'},
  {'*', '0', '#', 'D'}
};
uint8_t rowPins[ROWS] = {49, 47, 45, 43};  // R1, R2, R3, R4
uint8_t colPins[COLS] = {11,  9,  8, 14};  // C1, C2, C3, C4
Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);
// =====================================================================
//                   STATE MACHINE ENUM
// =====================================================================
enum SystemState {
  BOOT,
  SETUP_MODE,
  WAIT_SETUP_REPLY,
  INPUT_PROCESS,
  WAIT_PROCESS_REPLY,
  CONFIRM_PROCESS,
  LOGIN_NIK,
  WAIT_NIK_REPLY,
  SKILL_CHECK,
  MAIN_SCREEN,
  INFO_SCREEN,
  INPUT_NG_QTY,
  INPUT_NG_CODE
};
SystemState currentState = BOOT;
// =====================================================================
//                   VARIABEL GLOBAL
// =====================================================================
// --- Identitas Mesin & Operator ---
String mcID            = "";   // ID mesin dari EEPROM (contoh: "2WR2-024")
String display_idMesin = "";   // Nama tampilan mesin dari DB
String op_NIK          = "";   // NIK operator (dari UID kartu RFID)
String op_Name         = "";   // Nama operator dari DB
int    op_Level        = 0;    // Skill level operator (1-4)
// --- Data Proses ---
String partID      = "";   // Nama Part (untuk tampilan LCD baris 0)
String partNo      = "";   // Part Number
String prosesDesc  = "";   // Deskripsi proses
String kode_proses = "";   // Kode proses yang dipilih
// --- Status Mesin ---
String mcStatus         = "off";      // off / standBy / run / alarm
String mcInfo           = "Mesin Off"; // Deskripsi status mesin
String previousMcStatus = "off";       // Status sebelumnya (untuk deteksi transisi)
String previousMcInfo   = "Stand By";  // Info sebelumnya
// --- Counter Produksi ---
int prodCount = 0;   // Total produksi
int NGCount   = 0;   // Total NG
int OKCount   = 0;   // OK = prodCount - NGCount
unsigned int cycleCountSaved    = 0;  // Offset Nano
unsigned int cycleCountReceived = 0;  // Counter dari Nano
int cavity = 1;              // Cavity (default 1, bisa diubah dari proses)
// --- NG / Repair Input ---
bool   isRepairMode = false;  // false = Tambah NG (NG+), true = Repair (NG-)
int    ngQtyInput   = 0;      // Qty NG/Repair yang sedang diinput
String ngCodeInput  = "";     // Kode NG/Repair yang sedang diinput
// --- Memori Lokal NG (Anti-Phantom Repair) ---
// Menyimpan kode NG dan qty-nya agar repair hanya bisa
// dilakukan pada kode yang pernah ditambahkan.
String       localNgCodes[20];   // Kode NG yang pernah diinput
String       localNgProses[20];  // Kode proses saat NG diinput
unsigned int localNgQtys[20];    // Qty per-kode NG
int          activeNgCount = 0;  // Jumlah slot yang terpakai
// --- Buffer & State UI ---
String inputBuffer       = "";   // Buffer input keypad
unsigned long stateStartTime = 0;  // Waktu masuk state saat ini
unsigned long lastMsgTime    = 0;  // Waktu publish terakhir
bool   forceUpdate       = true;   // Flag untuk update LCD
String tempMessage       = "";     // Pesan sementara (error/sukses)
// --- Variabel Scrolling LCD (Baris 0, 1, 2) ---
unsigned long previousMillis1 = 0;
unsigned long previousMillis2 = 0;
unsigned long previousMillis3 = 0;
const unsigned long intervalScroll = 1500;  // Kecepatan scroll (ms)
int posisi1 = 0, posisi2 = 0, posisi3 = 0; // Posisi karakter scroll
String lastPartID     = "";
String lastProsesDesc = "";
String lastmcInfo     = "";
String lastop_NIK     = "";
int lastOKCount = 9999;  // Inisialisasi berbeda agar force update pertama kali
int lastNGCount = 9999;
// --- Variabel Tombol & Debounce ---
unsigned long ngSubPressTime    = 0;     // Waktu awal tombol NG- ditekan
bool ngSubPressed               = false; // Apakah NG- sedang ditekan
bool lastNgAddState             = HIGH;  // State sebelumnya tombol NG+
unsigned long lastNgAddDebounce = 0;     // Waktu debounce NG+
const unsigned long debounceMs  = 50;    // Durasi debounce (ms)
// --- RFID Continuous Hold ---
unsigned long lastRfidCheck     = 0;     // Waktu terakhir polling RFID
unsigned long lastCardDetectTime = 0;    // Waktu terakhir kartu terdeteksi
bool rfidCardPresent            = false; // Apakah kartu sedang terbaca
bool rfidHardwareFound          = false; // Menandakan apakah modul RFID PN532 I2C sukses diinisialisasi
String current_uid           = "";    // UID kartu yang sedang terbaca
const unsigned long RFID_POLL_INTERVAL = 500;   // Polling RFID setiap 500ms
const unsigned long RFID_TIMEOUT       = 3000;  // Kartu dianggap dicabut setelah 3 detik
uint8_t rfidConsecutiveFails            = 0;     // Hitungan kegagalan berturut-turut untuk auto-reinit
// --- Downtime Tracking ---
String  dtPreviousStatus     = "off";
unsigned long dtStandbyStart = 0;
bool    dtIsTracking         = false;
String  dtCurrentCode        = "SB";  // Default kode downtime: StandBy
// --- Callback Non-Blocking Message ---
unsigned long lastReconnectAttempt = 0;
unsigned long callbackMsgTime      = 0;
bool callbackMsgActive             = false;
String callbackMsgText             = "";
SystemState callbackNextState      = BOOT;
// --- LED Berkedip (Mesin Berhenti) ---
unsigned long lastLedBlink = 0;
bool ledState = false;
// =====================================================================
//                    FORWARD DECLARATIONS
// =====================================================================
void setupEthernet();
void reconnectMQTT();
void callback(char* topic, byte* payload, unsigned int length);
void changeState(SystemState newState);
void processMainScreen();
void readMachineStatus();
void checkRFIDHold(unsigned long currentMillis);
bool publishWithRetry(const char* topic, const char* payload);
void publishDowntime(String kode_dt, int durasi);
void showNonBlockingMsg(String msg, SystemState nextState, unsigned long duration = 1500);
void handleNonBlockingMsg();
void writeStringToEEPROM(int addr, String data);
String readStringFromEEPROM(int addr);
void writeByteToEEPROM(int addr, byte val);
byte readByteFromEEPROM(int addr);
unsigned int readCounterFromNano();
String checkDowntimeButton();
// =====================================================================
//                           SETUP
// =====================================================================
void setup() {
  Serial.begin(9600);
  Serial.println(F("============================"));
  Serial.println(F(" SMMS Mega v3.0 - Booting..."));
  Serial.println(F("============================"));
  // --- Inisialisasi I2C ---
  Wire.begin();  // Arduino Mega: SDA=20, SCL=21
  // Tambahkan pelindung Timeout agar Arduino Mega TIDAK FREEZE jika I2C bermasalah/belum siap saat boot.
  // Gunakan 'false' agar tidak mereset bus I2C (karena reset membuat counter hilang).
  #if defined(WIRE_HAS_TIMEOUT)
  Wire.setWireTimeout(25000, false);
  #endif
 // --- Inisialisasi LCD ---
  lcd.init();
  lcd.backlight();
  // --- Inisialisasi RTC ---
  if (!rtc.begin()) {
    Serial.println(F("[RTC] TIDAK DITEMUKAN!"));
    lcd.setCursor(3, 1); lcd.print(F("RTC ERROR!"));
    delay(2000);
  } else {
    Serial.println(F("[RTC] OK"));
  }
  // --- Inisialisasi RFID PN532 (Serial2 Mode) ---
  Serial2.begin(115200);
  nfc.begin();
  uint32_t versiondata = nfc.getFirmwareVersion();
  if (!versiondata) {
    Serial.println(F("[RFID] PN532 TIDAK DITEMUKAN!"));
    // Tidak fatal, lanjutkan boot (mungkin kabel I2C belum terpasang)
    rfidHardwareFound = false;
  } else {
    Serial.print(F("[RFID] PN532 FW: ")); Serial.println((versiondata >> 16) & 0xFF);
    // Konfigurasi PN532 untuk membaca kartu MIFARE
    nfc.SAMConfig();
    // nfc.setPassiveActivationRetries(1);  // Jangan blocking terlalu lama (Dinonaktifkan agar sama dengan versi stabil)
    Serial.println(F("[RFID] PN532 Ready"));
    rfidHardwareFound = true;
  }
  // --- Konfigurasi Pin ---
  // Ethernet SPI
  pinMode(10, OUTPUT);  // CS untuk W5100
  pinMode(53, OUTPUT);  // SS di Mega HARUS OUTPUT (walaupun tidak dipakai)
  // Input sensor mesin
  pinMode(mcON, INPUT_PULLUP);
  pinMode(mcRun, INPUT_PULLUP);
  pinMode(alarmPin, INPUT_PULLUP);
  // Output interlock
  pinMode(interlockPin, OUTPUT);
  digitalWrite(interlockPin, LOW);  // Awal: interlock OFF (mesin bebas)
  // Tombol navigasi
  pinMode(btnCTRL, INPUT_PULLUP);
  pinMode(btnPROG_PLUS, INPUT_PULLUP);
  pinMode(btnPROG_MINUS, INPUT_PULLUP);
  // Tombol NG
  pinMode(ngAdd, INPUT_PULLUP);
  pinMode(ngSub, INPUT_PULLUP);
  // LED indikator
  pinMode(brokerLed, OUTPUT);
  pinMode(stopLed, OUTPUT);
  digitalWrite(brokerLed, LOW);
  digitalWrite(stopLed, LOW);
  // 25 Tombol Downtime
  const int dtPins[] = {
    pbTPM, pbProblemQualitas, pbToilet, pb5P5R, pbP5M,
    pbSarana, pbProblemInspJig, pbDandory, pbMinum, pbSholat,
    pbRefillMaterial, pbProblemJigProses, pbProblemMesin, pbQCTrial, pbMaterialHabis,
    pbOperatorIzin, pbNoPlanning, pbTeaching, pbPerawatan, pbOJT,
    pbTungguMaterial, pbEngTrial, pbNozzle, pbWireLas, pbTambahanProses
  };
  for (int i = 0; i < 25; i++) {
    pinMode(dtPins[i], INPUT_PULLUP);
  }
  // --- Inisialisasi Ethernet ---
  setupEthernet();
  // --- Inisialisasi MQTT ---
  client.setServer(mqttServer, mqttPort);
  client.setCallback(callback);
  client.setKeepAlive(60);
  client.setBufferSize(1024);  // Buffer lebih besar untuk JSON
  // --- Baca ID Mesin dari EEPROM ---
  // Cek apakah EEPROM sudah pernah diinisialisasi (flag 0xA5)
  if (readByteFromEEPROM(EEPROM_INIT_FLAG) == 0xA5) {
    mcID = readStringFromEEPROM(EEPROM_MCID_ADDR);
    display_idMesin = mcID; // Fallback agar ID mesin tidak kosong setelah mesin dimatikan
    Serial.print(F("[EEPROM] mcID = ")); Serial.println(mcID);
  } else {
    mcID = "";
    Serial.println(F("[EEPROM] Kosong, perlu setup."));
  }
   // --- Mulai State Machine ---
  changeState(BOOT);
}
// =====================================================================
//                        LOOP UTAMA
// =====================================================================
void loop() {
  unsigned long currentMillis = millis();
  // --- Maintain Koneksi MQTT (Non-Blocking) ---
  // Jika terputus, coba reconnect setiap 10 detik
  if (!client.connected()) {
    digitalWrite(brokerLed, LOW);  // LED hijau mati = MQTT putus
    if (currentMillis - lastReconnectAttempt > 10000) {
      reconnectMQTT();
      lastReconnectAttempt = millis();
    }
  } else {
    digitalWrite(brokerLed, HIGH); // LED hijau nyala = MQTT terhubung
    client.loop();                 // Proses pesan masuk MQTT
  }
  // --- Baca Keypad (Non-Blocking) ---
  char key = keypad.getKey();
  // --- Handle Pesan Non-Blocking (Overlay Sementara) ---
  // Jika ada pesan yang sedang ditampilkan, tunggu sampai selesai
  if (callbackMsgActive) {
    handleNonBlockingMsg();
    return; // Skip state machine selama pesan ditampilkan
  }
  // --- LED Berkedip: Mesin Tidak Running ---
  // LED merah berkedip saat mesin tidak dalam status "run"
  if (currentState >= MAIN_SCREEN && mcStatus != "run") {
    if (currentMillis - lastLedBlink >= 250) {
      lastLedBlink = currentMillis;
      ledState = !ledState;
      digitalWrite(stopLed, ledState ? HIGH : LOW);
    }
  } else {
    digitalWrite(stopLed, LOW);
  }
  // --- Global Trigger: Mode Setup (CTRL + PROGRAM+ bersamaan) ---
  // Teknisi bisa masuk setup kapan saja dengan menekan kedua tombol
  if (digitalRead(btnCTRL) == LOW && digitalRead(btnPROG_PLUS) == LOW) {
    if (currentState != SETUP_MODE && currentState != WAIT_SETUP_REPLY) {
      Serial.println(F("[SETUP] CTRL+PROG+ detected -> Setup Mode"));
      changeState(SETUP_MODE);
      return;
    }
  }
  // --- Global Trigger: Manual Reconnect (CTRL + PROGRAM+ di MAIN_SCREEN) ---
  // Jika di layar utama dan koneksi MQTT putus
  if (currentState == MAIN_SCREEN && !client.connected()) {
    if (digitalRead(btnCTRL) == LOW && digitalRead(btnPROG_PLUS) == LOW) {
      lcd.clear();
      lcd.setCursor(0, 1); lcd.print(F("Manual Reconnect..."));
      reconnectMQTT();
      lastReconnectAttempt = millis();
      forceUpdate = true;
    }
  }
  // HANYA polling RFID saat di layar LOGIN_NIK, MAIN_SCREEN, atau INFO_SCREEN.
  // Jika di layar INPUT_PROCESS atau INPUT_NG, RFID dimatikan sesaat agar Keypad TIDAK LAG saat diketik!
  if (rfidHardwareFound && (currentState == LOGIN_NIK || currentState == MAIN_SCREEN || currentState == INFO_SCREEN) && (currentMillis - lastRfidCheck > 500)) {
    lastRfidCheck = currentMillis;
    rfidCardPresent = false;
    
    uint8_t uid[7];
    uint8_t uidLength;
    
    // Timeout 100ms agar tidak memblokir loop terlalu lama
    bool success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 100);
    
    // Buang sisa data di buffer Serial2 agar tidak menumpuk dan menyebabkan desync
    while (Serial2.available()) Serial2.read();
    
    if (success) {
        rfidCardPresent = true;
        lastCardDetectTime = currentMillis;
        rfidConsecutiveFails = 0; // Reset hitungan gagal
        
        String rfid_uid = "";
        for (uint8_t i = 0; i < uidLength; i++) {
          if (uid[i] < 0x10) rfid_uid += "0";
          rfid_uid += String(uid[i], HEX);
        }
        rfid_uid.toUpperCase();
        if (currentState == LOGIN_NIK && currentMillis - stateStartTime > 500) {
          inputBuffer = rfid_uid;
          current_uid = rfid_uid;
          StaticJsonDocument<200> doc;
          doc["op_NIK"] = rfid_uid;
          doc["mcID"]   = mcID;
          char jsonBuffer[200];
          serializeJson(doc, jsonBuffer);
          publishWithRetry("SMMS/Request/Login", jsonBuffer);
          changeState(WAIT_NIK_REPLY);
        } 
        else if (current_uid != "" && currentState != INPUT_NG_QTY && currentState != INPUT_NG_CODE) {
          if (rfid_uid != current_uid) {
            digitalWrite(interlockPin, HIGH); // KARTU DITUKAR -> INTERLOCK!
            op_NIK = ""; 
            
            // Langsung verifikasi kartu yang baru
            inputBuffer = rfid_uid;
            current_uid = rfid_uid;
            StaticJsonDocument<200> doc;
            doc["op_NIK"] = rfid_uid;
            doc["mcID"]   = mcID;
            char jsonBuffer[200];
            serializeJson(doc, jsonBuffer);
            publishWithRetry("SMMS/Request/Login", jsonBuffer);
            
            changeState(WAIT_NIK_REPLY);
            lcd.clear(); lcd.setCursor(0, 1); lcd.print("Kartu Ditukar!      ");
            delay(800);
          }
        }
    } else {
      // Kartu tidak terdeteksi kali ini
      rfidConsecutiveFails++;
      
      // Auto-Recovery: Jika gagal 10x berturut-turut, reinit PN532
      // Ini mengatasi masalah Serial buffer desync setelah lama tidak terdeteksi
      if (rfidConsecutiveFails >= 10) {
        Serial.println(F("[RFID] Auto-Reinit PN532..."));
        while (Serial2.available()) Serial2.read(); // Flush buffer
        nfc.begin();
        nfc.SAMConfig();
        rfidConsecutiveFails = 0;
      }
    }
  }
  // --- LOGIKA KARTU DILEPAS ---
  if (current_uid != "") {
      // FITUR BARU: Pause Timer Auto-Logout saat sedang mengetik atau loading data (Mencegah terlogout sendiri)
      if (currentState != MAIN_SCREEN && currentState != INFO_SCREEN) {
          lastCardDetectTime = currentMillis; // Terus perbarui timer seolah-olah kartu ditahan
      }
      if (currentMillis - lastCardDetectTime > 3000) {
          digitalWrite(interlockPin, HIGH); // KARTU DILEPAS -> INTERLOCK!
          op_NIK = "";
          current_uid = "";
          op_Level = 0;
          if (currentState == SKILL_CHECK) {
             changeState(INPUT_PROCESS);
          } else {
             showNonBlockingMsg("Kartu Dilepas!", INPUT_PROCESS, 1500); 
          }
      }
  }
  // ======================== STATE MACHINE ========================
  switch (currentState) {
    // -------------------- BOOT --------------------
    // Tampilan splash screen selama 3 detik
    // Jika mcID kosong -> masuk setup
    // Jika mcID ada -> masuk login RFID
    case BOOT:
      if (currentMillis - stateStartTime < 3000) {
        // Tunggu 3 detik (splash screen)
      } else {
        if (mcID == "") changeState(SETUP_MODE);
        else changeState(INPUT_PROCESS);
      }
      break;
    // -------------------- SETUP MODE --------------------
    // Teknisi memasukkan ID Mesin via keypad
    // D = OK (kirim ke MQTT untuk verifikasi)
    // C = Batal (kembali jika mcID sudah ada)
    // # = Backspace
    case SETUP_MODE:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'B')) {
          inputBuffer += key; forceUpdate = true;
        } else if (key == 'C') {
          if (mcID != "") changeState(INPUT_PROCESS);
          else changeState(BOOT);
        } else if (key == '#' && inputBuffer.length() > 0) {
          inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          if (!client.connected()) reconnectMQTT();
          StaticJsonDocument<200> doc; doc["mcID"] = inputBuffer;
          char jsonBuffer[200]; serializeJson(doc, jsonBuffer);
          if (publishWithRetry("SMMS/Request/Setup", jsonBuffer)) changeState(WAIT_SETUP_REPLY);
          else showNonBlockingMsg("Koneksi Terputus!", SETUP_MODE, 2000);
        }
      }
      if (currentState != SETUP_MODE) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); lcd.print(F("Mode Setup Teknisi"));
        lcd.setCursor(0, 1); lcd.print(F("Masukkan ID Mesin:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK  C:Batal"));
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;
    // -------------------- WAIT STATES --------------------
    // Tampilan "Memproses..." selama menunggu balasan MQTT
    // Timeout 15 detik -> kembali ke state sebelumnya
    case WAIT_SETUP_REPLY: case WAIT_NIK_REPLY: case WAIT_PROCESS_REPLY:
      if (forceUpdate) { lcd.clear(); lcd.noBlink(); lcd.setCursor(4, 1); lcd.print(F("Memproses...")); forceUpdate = false; }
      if (currentMillis - stateStartTime > 15000) {
        if (currentState == WAIT_SETUP_REPLY) showNonBlockingMsg("Timeout! Coba lagi", SETUP_MODE, 2000);
        else if (currentState == WAIT_NIK_REPLY) showNonBlockingMsg("Timeout! Coba lagi", LOGIN_NIK, 2000);
        else showNonBlockingMsg("Timeout! Coba lagi", INPUT_PROCESS, 2000);
      }
      break;
    case INPUT_PROCESS:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'C')) {
          inputBuffer += key; forceUpdate = true;
        } else if (key == '#' && inputBuffer.length() > 0) {
          inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          if (!client.connected()) reconnectMQTT();
          StaticJsonDocument<200> doc; doc["kode_proses"] = inputBuffer;
          char jsonBuffer[200]; serializeJson(doc, jsonBuffer);
          if (publishWithRetry("SMMS/Request/Proses", jsonBuffer)) changeState(WAIT_PROCESS_REPLY);
          else showNonBlockingMsg("Koneksi Terputus!", INPUT_PROCESS, 2000);
        }
      }
      if (currentState != INPUT_PROCESS) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); 
        if (op_NIK == "") lcd.print("ID: " + display_idMesin.substring(0, 16));
        else lcd.print("OP: " + op_NIK);
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Kd Proses:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK  #:Hps"));
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;
    case CONFIRM_PROCESS:
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20));
        lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print(F("D:Lanjut  C:Batal"));
        forceUpdate = false;
      }
      if (key == 'D') changeState(LOGIN_NIK);
      else if (key == 'C') changeState(INPUT_PROCESS);
      break;
    case LOGIN_NIK:
      if (key == 'C') {
        inputBuffer = "";
        changeState(INPUT_PROCESS);
      }
      if (forceUpdate) {
        digitalWrite(interlockPin, HIGH); // Interlock menyala
        lcd.clear(); lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print("PT. CNC - " + display_idMesin);
        lcd.setCursor(0, 1); lcd.print(F("Letakkan Kartu NIK!"));
        lcd.setCursor(0, 2); lcd.print(F("(Tahan dlm Scanner)"));
        forceUpdate = false;
      }
      break;
    case SKILL_CHECK:
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print(op_Name.substring(0, 20));
        if (op_Level >= 3) {
          lcd.setCursor(0, 1); lcd.print("Level: " + String(op_Level));
          lcd.setCursor(0, 3); lcd.print(F("Membuka Mesin..."));
          digitalWrite(interlockPin, LOW); // Interlock mati (Lulus)
        } else {
          lcd.setCursor(0, 0); lcd.print(tempMessage); // Skill tdk terpenuhi
          lcd.setCursor(0, 1); lcd.print("Level: " + String(op_Level));
          lcd.setCursor(0, 3); lcd.print(F("Mesin Dikunci!"));
          digitalWrite(interlockPin, HIGH); // Interlock menyala (Gagal)
        }
        forceUpdate = false;
      }
      if (op_Level >= 3 && currentMillis - stateStartTime > 1500) {
        changeState(MAIN_SCREEN);
      }
      break;
// -------------------- MAIN SCREEN --------------------
    // Layar utama monitoring produksi
    // Baris 0: Part Name (scrolling)
    // Baris 1: Proses Description (scrolling)
    // Baris 2: Machine Info/Status (scrolling)
    // Baris 3: NIK | OKCount / NGCount
    //
    // Navigasi Keypad:
    //   B = Ganti Proses
    //   * = Info Screen
    //   A = (reserved)
    case MAIN_SCREEN:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lastPartID = "";
        lastProsesDesc = "";
        lastmcInfo = "";
        lastop_NIK = "";
        lastOKCount = 9999;
        lastNGCount = 9999;
        forceUpdate = false;
      }
      // Navigasi Keypad
      if (key == 'B') changeState(INPUT_PROCESS);  // Ganti Proses
      else if (key == '*') changeState(INFO_SCREEN); // Info Screen
      // Update tampilan LCD (scrolling text)
      processMainScreen();
      break;
    // -------------------- INFO SCREEN --------------------
    // Tampilan info detail: Part, PartNo, Proses, ID Mesin
    // Tekan * untuk kembali ke Main Screen
    case INFO_SCREEN:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20));
        lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print("ID: " + display_idMesin.substring(0, 16));
        forceUpdate = false;
      }
      if (key == '*') changeState(MAIN_SCREEN);
      break;
    // -------------------- INPUT NG QTY --------------------
    // Input jumlah NG atau Repair
    // D = OK (lanjut ke input kode)
    // C = Batal (kembali ke Main Screen)
    case INPUT_NG_QTY:
      if (key) {
        if (key >= '0' && key <= '9' && inputBuffer.length() < 4) {
          inputBuffer += key; forceUpdate = true;
        } else if (key == '#') {
          if (inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; }
        } else if (key == 'C') {
          inputBuffer = ""; changeState(MAIN_SCREEN);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          ngQtyInput = inputBuffer.toInt();
          if (ngQtyInput > 0) {
            if (isRepairMode && ngQtyInput > NGCount) {
              showNonBlockingMsg("Melebihi NG Count!", INPUT_NG_QTY, 1500);
              inputBuffer = "";
            } else {
              inputBuffer = "";
              changeState(INPUT_NG_CODE);
            }
          }
        }
      }
      if (currentState != INPUT_NG_QTY) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); lcd.print(isRepairMode ? "== REPAIR NG ==" : "== TAMBAH NG ==");
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Qty:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        lcd.setCursor(0, 3); lcd.print(F("D:OK C:Batal #:Hps"));
        forceUpdate = false;
      }
      break;
    case INPUT_NG_CODE:
      if (key) {
        if (((key >= '0' && key <= '9') || key == 'A' || key == 'B') && inputBuffer.length() < 10) {
          inputBuffer += key; forceUpdate = true;
        } else if (key == '#') {
          if (inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; }
        } else if (key == 'C') {
          inputBuffer = ""; changeState(INPUT_NG_QTY);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          if (!client.connected()) reconnectMQTT();
          ngCodeInput = inputBuffer;
          // --- Validasi Anti-Phantom Repair & Simpan ke Memori Lokal ---
          if (!isRepairMode) {
            if ((NGCount + ngQtyInput) > prodCount) {
              showNonBlockingMsg("NG > Produksi!", INPUT_NG_QTY, 1500);
              inputBuffer = ""; return;
            }
            bool found = false;
            for (int i = 0; i < activeNgCount; i++) {
              if (localNgCodes[i] == ngCodeInput && localNgProses[i] == kode_proses) {
                localNgQtys[i] += ngQtyInput; found = true; break;
              }
            }
            if (!found && activeNgCount < 20) {
              localNgCodes[activeNgCount] = ngCodeInput;
              localNgProses[activeNgCount] = kode_proses;
              localNgQtys[activeNgCount] = ngQtyInput;
              activeNgCount++;
            }
            NGCount += ngQtyInput;
          } else {
            bool validRepair = false; int foundIndex = -1;
            for (int i = 0; i < activeNgCount; i++) {
              if (localNgCodes[i] == ngCodeInput && localNgProses[i] == kode_proses) {
                foundIndex = i;
                if ((int)localNgQtys[i] >= ngQtyInput) validRepair = true;
                break;
              }
            }
            if (!validRepair) {
              showNonBlockingMsg("Kode/Qty Invalid!", INPUT_NG_QTY, 1500);
              inputBuffer = ""; return;
            }
            localNgQtys[foundIndex] -= ngQtyInput;
            NGCount -= ngQtyInput;
          }
          OKCount = prodCount - NGCount;
          StaticJsonDocument<256> doc;
          doc["mcID"]        = display_idMesin;
          doc["kode_proses"] = kode_proses;
          doc["kode_ng"]     = ngCodeInput;
          if (isRepairMode) {
            doc["qty_ng"]   = -(ngQtyInput);
            doc["kategori"] = "Repair NG";
          } else {
            doc["qty_ng"]   = ngQtyInput;
            doc["kategori"] = "Tambah NG";
          }
          char jsonStr[256]; serializeJson(doc, jsonStr);
          
          if (publishWithRetry("SMMS/Data/NG", jsonStr)) {
            String konfirm = isRepairMode ? "Repair OK!" : "NG Tercatat!";
            showNonBlockingMsg(konfirm, MAIN_SCREEN, 1200);
          } else {
            showNonBlockingMsg("Koneksi Terputus!", INPUT_NG_CODE, 2000);
          }
        }
      }
      if (currentState != INPUT_NG_CODE) break;
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink();
        if (isRepairMode) lcd.setCursor(0, 0); else lcd.setCursor(0, 0);
        lcd.print(isRepairMode ? "REPAIR Qty: " + String(ngQtyInput) : "NG Qty: " + String(ngQtyInput));
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Kode:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        lcd.setCursor(0, 3); lcd.print(F("D:OK C:Batal #:Hps"));
        forceUpdate = false;
      }
      break;
  } // end switch
// =====================================================================
  //          BACKGROUND TASKS (Polling hardware independen)
  // =====================================================================
  // --- Baca Counter Produksi dari Arduino Nano (Polling setiap 1000ms) ---
  // Sengaja ditaruh di luar MAIN_SCREEN agar bisa di-debug kapan saja
  static unsigned long lastNanoRead = 0;
  
  if (currentState != BOOT && currentMillis - lastNanoRead >= 1000) {
    lastNanoRead = currentMillis;
    cycleCountReceived = readCounterFromNano();
    
    // Rumus IDENTIK dengan smart_oee: additive (saved + received)
    // Bukan subtractive (received - saved) yang bisa return 0 saat I2C gagal
    unsigned int cycleCount = cycleCountSaved + cycleCountReceived;
    prodCount = cycleCount * cavity;
    OKCount = prodCount - NGCount;
    
    // DEBUG: Tampilkan ke Serial Monitor
    Serial.print(F("[DEBUG] Nano=")); 
    Serial.print(cycleCountReceived);
    Serial.print(F(" Saved="));
    Serial.print(cycleCountSaved);
    Serial.print(F(" Cycle="));
    Serial.print(cycleCount);
    Serial.print(F(" Prod="));
    Serial.println(prodCount);
  }
  // =====================================================================
  //          STATE-DEPENDENT TASKS (Hanya jalan saat produksi)
  // =====================================================================
  if (currentState >= MAIN_SCREEN) {
    // --- Baca Status Mesin ---
    readMachineStatus();
    // --- Baca Tombol NG ---
    readNGButtons();
    // --- Tracking Downtime ---
    // Deteksi transisi status mesin untuk mencatat durasi downtime
    if (mcStatus != "run" && dtPreviousStatus == "run") {
      // Mesin baru saja berhenti -> mulai tracking
      dtStandbyStart = currentMillis;
      dtIsTracking = true;
      // Tentukan kode downtime berdasarkan status & tombol
      if (mcStatus == "alarm") dtCurrentCode = "Problem Mesin";
      else if (mcStatus == "off") dtCurrentCode = "Mesin Off";
      else {
        String dtBtn = checkDowntimeButton();
        dtCurrentCode = (dtBtn != "") ? dtBtn : "SB";
      }
    }
    else if (mcStatus == "run" && dtPreviousStatus != "run" && dtIsTracking) {
      // Mesin baru mulai running lagi -> akhiri tracking, publish durasi
      unsigned long durasi = (currentMillis - dtStandbyStart) / 1000;
      if (durasi >= 5) { // Hanya publish jika durasi >= 5 detik
        publishDowntime(dtCurrentCode, (int)durasi);
      }
      dtIsTracking = false;
    }
    // Update kode downtime secara dinamis jika tombol ditekan saat standby
    if (dtIsTracking && mcStatus == "standBy") {
      String dtBtn = checkDowntimeButton();
      if (dtBtn != "") dtCurrentCode = dtBtn;
    }
    dtPreviousStatus = mcStatus;
    // --- Publish Data Produksi Realtime tiap 2 Detik ---
    if (currentMillis - lastMsgTime > 2000) {
      lastMsgTime = currentMillis;
      // Baca timestamp dari RTC
      DateTime now = rtc.now();
      snprintf(timestamp, sizeof(timestamp), "%04d-%02d-%02dT%02d:%02d:%02d",
               now.year(), now.month(), now.day(),
               now.hour(), now.minute(), now.second());
      StaticJsonDocument<512> doc;
      doc["mcID"]        = display_idMesin;
      doc["op_NIK"]      = op_NIK;
      doc["partID"]      = partID;
      doc["kode_proses"] = kode_proses;
      doc["mcStatus"]    = mcStatus;
      doc["mcInfo"]      = mcInfo;
      doc["prodCount"]   = prodCount;
      doc["OKCount"]     = OKCount;
      doc["NGCount"]     = NGCount;
      char jsonStr[512];
      serializeJson(doc, jsonStr);
      publishWithRetry("SMMS/Data/Produksi", jsonStr);
    }
  }
}
// =====================================================================
//              CALLBACK MQTT (Terima Balasan dari Node-RED)
// =====================================================================
void callback(char* topic, byte* payload, unsigned int length) {
  String topicStr = String(topic);
  // Parse JSON dari payload
  StaticJsonDocument<512> doc;
  DeserializationError error = deserializeJson(doc, payload, length);
  if (error) {
    Serial.print(F("[MQTT] JSON Error: ")); Serial.println(error.c_str());
    return;
  }
  String status = doc["status"].as<String>();
  Serial.print(F("[MQTT] ")); Serial.print(topicStr);
  Serial.print(F(" | status=")); Serial.println(status);
  // -------------------- Reply Setup --------------------
  // Respons dari Node-RED setelah cek master_mesin
  if (topicStr == "SMMS/Reply/Setup" && currentState == WAIT_SETUP_REPLY) {
    if (status == "registered") {
      // ID Mesin valid, simpan ke EEPROM
      mcID = doc["mcID"].as<String>();
      if (doc.containsKey("id_mesin") && !doc["id_mesin"].isNull()) {
        display_idMesin = doc["id_mesin"].as<String>();
      } else {
        display_idMesin = mcID;
      }
      // Simpan ke EEPROM I2C
      writeStringToEEPROM(EEPROM_MCID_ADDR, mcID);
      writeByteToEEPROM(EEPROM_INIT_FLAG, 0xA5);  // Set flag inisialisasi
      Serial.print(F("[EEPROM] Saved mcID: ")); Serial.println(mcID);
      showNonBlockingMsg("Berhasil Disimpan!", LOGIN_NIK, 1500);
    } else {
      showNonBlockingMsg("ID Gagal Terdaftar!", SETUP_MODE, 1500);
    }
  }
// -------------------- Reply Login --------------------
  // Respons dari Node-RED setelah cek master_operator + skill_matrix
  else if (topicStr == "SMMS/Reply/Login" && currentState == WAIT_NIK_REPLY) {
    if (status == "success") {
      // Login berhasil, skill cukup
      op_NIK   = doc["nik_asli"].as<String>();   // Simpan NIK asli dari balasan database, bukan UID
      op_Name  = doc["nama"].as<String>();
      op_Level = doc["level"].as<int>();
      if (op_Level < 3) {
        tempMessage = "Skill tdk terpenuhi";
      }
    } else if (status == "skill_low") {
      op_Level = 0;
      tempMessage = "Skill tdk terpenuhi";
    } else {
      // NIK (UID) tidak terdaftar di database
      op_Level = 0;
      tempMessage = "NIK Tidak Terdaftar";
    }
    changeState(SKILL_CHECK);
  }
  // -------------------- Reply Proses --------------------
  // Respons dari Node-RED setelah cek master_ct
  else if (topicStr == "SMMS/Reply/Proses" && currentState == WAIT_PROCESS_REPLY) {
    if (status == "found") {
      kode_proses = inputBuffer;
      partID      = doc["nama_part"].as<String>();
      partNo      = doc["part_no"].as<String>();
      prosesDesc  = doc["nama_proses"].as<String>();
      // Padding untuk scrolling
      while (partID.length() < 20)    partID    += " ";
      while (prosesDesc.length() < 20) prosesDesc += " ";
      changeState(CONFIRM_PROCESS);
    } else {
      showNonBlockingMsg("Kode Tidak Valid!", INPUT_PROCESS, 1500);
    }
  }
}
// =====================================================================
//                      MANAJEMEN STATE
// =====================================================================
void changeState(SystemState newState) {
  currentState   = newState;
  stateStartTime = millis();
  forceUpdate    = true;
  callbackMsgActive = false;
  lcd.noBlink();
  // Jangan clear inputBuffer untuk WAIT states!
  // Callback MQTT butuh inputBuffer untuk set mcID, op_NIK, kode_proses
  if (newState != WAIT_SETUP_REPLY &&
      newState != WAIT_NIK_REPLY &&
      newState != WAIT_PROCESS_REPLY) {
    inputBuffer = "";
  }
  // Tampilan khusus per-state
  if (newState == BOOT) {
    lcd.clear();
    lcd.setCursor(4, 0);  lcd.print(F("SMART MACHINE"));
    lcd.setCursor(2, 1);  lcd.print(F("MONITORING SYSTEM"));
    lcd.setCursor(7, 3);  lcd.print(F("PT. CNC"));
  }
  else if (newState == MAIN_SCREEN) {
    posisi1 = 0;
    posisi2 = 0;
    posisi3 = 0;
    // (Bug fix) Jangan tambahkan offset di sini, karena menyebabkan counter berlipat ganda
  }
  Serial.print(F("[STATE] -> ")); Serial.println(newState);
}
// =====================================================================
//              NON-BLOCKING MESSAGE DISPLAY
//  Menggantikan delay() di callback: tampilkan pesan singkat,
//  lalu otomatis pindah ke state berikutnya.
// =====================================================================
void showNonBlockingMsg(String msg, SystemState nextState, unsigned long duration) {
  callbackMsgActive = true;
  callbackMsgText   = msg;
  callbackNextState = nextState;
  callbackMsgTime   = millis() + duration;
  lcd.clear();
  lcd.noBlink();
  lcd.setCursor(0, 1);
  lcd.print(msg.substring(0, 20));
}
void handleNonBlockingMsg() {
  if (millis() >= callbackMsgTime) {
    callbackMsgActive = false;
    changeState(callbackNextState);
  }
}
// =====================================================================
//        LOGIKA TOMBOL NG+, NG-, DAN RESET (TAHAN 5 DETIK)
// =====================================================================
void readNGButtons() {
  unsigned long currentMillis = millis();
  // --- Tombol NG+ (Short Press -> Masuk input NG) ---
  bool currentNgAddState = digitalRead(ngAdd);
  if (lastNgAddState == HIGH && currentNgAddState == LOW) {
    if (currentMillis - lastNgAddDebounce > debounceMs) {
      lastNgAddDebounce = currentMillis;
      isRepairMode = false;
      ngQtyInput   = 0;
      ngCodeInput  = "";
      changeState(INPUT_NG_QTY);
    }
  }
  lastNgAddState = currentNgAddState;
  // --- Tombol NG- ---
  // Short Press: Masuk mode Repair
  // Tahan 5 detik: Reset semua counter
  int ngSubState = digitalRead(ngSub);
  if (ngSubState == LOW) {
    // Tombol sedang ditekan
    if (!ngSubPressed) {
      ngSubPressed   = true;
      ngSubPressTime = currentMillis;
    } else if (currentMillis - ngSubPressTime >= 5000) {
      // ===== RESET ALL COUNTER JIKA DITAHAN 5 DETIK =====
      // Reset counter di Nano via I2C (kirim magic byte 0xAA)
      Wire.beginTransmission(8);
      Wire.write(0xAA);
      Wire.endTransmission();
      // Reset variabel lokal
      cycleCountSaved = 0;
      cycleCountReceived = 0;
      prodCount = 0;
      NGCount   = 0;
      OKCount   = 0;
      // Reset memori lokal NG
      for (int i = 0; i < 20; i++) {
        localNgCodes[i] = "";
        localNgQtys[i] = 0;
      }
      activeNgCount = 0;
      lcd.clear();
      lcd.setCursor(2, 1); lcd.print(F("COUNTER DIRESET!"));
      lcd.setCursor(2, 2); lcd.print(F("Prod/NG/OK = 0"));
      // Cegah trigger berulang
      ngSubPressTime = currentMillis + 99999;
      callbackMsgActive = true;
      callbackMsgTime   = millis() + 1500;
      callbackNextState = MAIN_SCREEN;
    }
  } else {
    // Tombol dilepas
    if (ngSubPressed) {
      unsigned long pressDuration = currentMillis - ngSubPressTime;
      if (pressDuration < 5000 && pressDuration > debounceMs) {
        // Short press -> Repair Mode (hanya jika ada NG)
        if (NGCount > 0) {
          isRepairMode = true;
          ngQtyInput   = 0;
          ngCodeInput  = "";
          changeState(INPUT_NG_QTY);
        } else {
          lcd.setCursor(0, 2); lcd.print(F("NG = 0, No Repair  "));
        }
      }
      ngSubPressed = false;
    }
  }
}
// =====================================================================
//                   LOGIKA STATUS MESIN
//  Membaca sensor mcON, mcRun, alarmPin
//  dan 25 tombol downtime untuk menentukan mcInfo
// =====================================================================
void readMachineStatus() {
  // Logika prioritas: OFF > ALARM > RUN > STANDBY
  if (digitalRead(mcON) == HIGH) {
    // mcON HIGH = Mesin mati (aktif LOW pada sensor)
    mcStatus = "off";
    mcInfo   = "Mesin Off";
  }
  else if (digitalRead(alarmPin) == LOW) {
    // alarmPin LOW = Alarm aktif (aktif HIGH terbalik di Mega)
    mcStatus = "alarm";
    mcInfo   = "Mesin Alarm";
  }
  else if (digitalRead(mcRun) == LOW) {
    // mcRun LOW = Mesin running (aktif HIGH terbalik)
    mcStatus = "run";
    mcInfo   = "Mesin Running";
  }
  else {
    // Standby: Mesin ON tapi tidak running
    mcStatus = "standBy";
    if (previousMcStatus != "standBy") {
      previousMcInfo = "Stand By";
    }
    // Cek 25 tombol downtime untuk menentukan alasan standby
    String currentMcInfo = previousMcInfo;
    String dtBtn = checkDowntimeButton();
    if (dtBtn != "") currentMcInfo = dtBtn;
    mcInfo = currentMcInfo;
    previousMcInfo = currentMcInfo;
  }
  previousMcStatus = mcStatus;
}
// =====================================================================
//            CEK 25 TOMBOL DOWNTIME FISIK
//  Mengembalikan string kode downtime jika salah satu tombol ditekan
//  Mengembalikan "" jika tidak ada tombol yang ditekan
// =====================================================================
String checkDowntimeButton() {
  if (digitalRead(pbTPM) == LOW)              return "TPM";
  if (digitalRead(pbProblemQualitas) == LOW)   return "Problem Qualitas";
  if (digitalRead(pbToilet) == LOW)            return "Toilet";
  if (digitalRead(pb5P5R) == LOW)              return "5P/5R";
  if (digitalRead(pbP5M) == LOW)               return "P5M";
  if (digitalRead(pbSarana) == LOW)             return "Persiapan Sarana";
  if (digitalRead(pbProblemInspJig) == LOW)     return "Problem Insp Jig";
  if (digitalRead(pbDandory) == LOW)            return "Dandory";
  if (digitalRead(pbMinum) == LOW)              return "Minum";
  if (digitalRead(pbSholat) == LOW)             return "Sholat";
  if (digitalRead(pbRefillMaterial) == LOW)     return "Refill Material";
  if (digitalRead(pbProblemJigProses) == LOW)   return "Problem Jig Proses";
  if (digitalRead(pbProblemMesin) == LOW)       return "Problem Mesin";
  if (digitalRead(pbQCTrial) == LOW)            return "QC Trial";
  if (digitalRead(pbMaterialHabis) == LOW)      return "Material Habis";
  if (digitalRead(pbOperatorIzin) == LOW)       return "Operator Izin";
  if (digitalRead(pbNoPlanning) == LOW)         return "Tidak Ada Planning";
  if (digitalRead(pbTeaching) == LOW)           return "Teaching";
  if (digitalRead(pbPerawatan) == LOW)          return "Perawatan";
  if (digitalRead(pbOJT) == LOW)                return "OJT";
  if (digitalRead(pbTungguMaterial) == LOW)     return "Tunggu Material";
  if (digitalRead(pbEngTrial) == LOW)           return "ENG Trial";
  if (digitalRead(pbNozzle) == LOW)             return "Nozzle/Contact Tip";
  if (digitalRead(pbWireLas) == LOW)            return "Wire Las Macet";
  if (digitalRead(pbTambahanProses) == LOW)     return "Tambahan Proses";
  return "";  // Tidak ada tombol yang ditekan
}
// =====================================================================
//              FUNGSI LAYAR UTAMA (SCROLLING LCD)
// =====================================================================
void processMainScreen() {
  unsigned long currentMillis = millis();
  // ---- Baris 0: Part ID (scrolling jika > 20 char) ----
  if ((int)partID.length() > 20) {
    if (currentMillis - previousMillis1 >= intervalScroll) {
      previousMillis1 = currentMillis;
      lcd.setCursor(0, 0); lcd.print(F("                    "));
      lcd.setCursor(0, 0); lcd.print(partID.substring(posisi1, min((int)(posisi1 + 20), (int)partID.length())));
      posisi1 += 5;
      if (posisi1 >= (int)partID.length() - 15) posisi1 = 0;
    }
  } else if (partID != lastPartID || forceUpdate) {
    lcd.setCursor(0, 0); lcd.print(F("                    "));
    lcd.setCursor(0, 0); lcd.print(partID);
    lastPartID = partID;
  }
  // ---- Baris 1: Proses Description (scrolling jika > 20 char) ----
  if ((int)prosesDesc.length() > 20) {
    if (currentMillis - previousMillis2 >= intervalScroll) {
      previousMillis2 = currentMillis;
      lcd.setCursor(0, 1); lcd.print(F("                    "));
      lcd.setCursor(0, 1); lcd.print(prosesDesc.substring(posisi2, min((int)(posisi2 + 20), (int)prosesDesc.length())));
      posisi2 += 5;
      if (posisi2 >= (int)prosesDesc.length() - 15) posisi2 = 0;
    }
  } else if (prosesDesc != lastProsesDesc || forceUpdate) {
    lcd.setCursor(0, 1); lcd.print(F("                    "));
    lcd.setCursor(0, 1); lcd.print(prosesDesc);
    lastProsesDesc = prosesDesc;
  }
  // ---- Baris 2: Machine Info / Status (scrolling jika > 20 char) ----
  String displayInfo = mcInfo;
  while ((int)displayInfo.length() < 20) displayInfo += " ";
  if ((int)displayInfo.length() > 20) {
    if (currentMillis - previousMillis3 >= intervalScroll) {
      previousMillis3 = currentMillis;
      lcd.setCursor(0, 2); lcd.print(F("                    "));
      lcd.setCursor(0, 2); lcd.print(displayInfo.substring(posisi3, min((int)(posisi3 + 20), (int)displayInfo.length())));
      posisi3 += 5;
      if (posisi3 >= (int)displayInfo.length() - 15) posisi3 = 0;
    }
  } else if (displayInfo != lastmcInfo || forceUpdate) {
    lcd.setCursor(0, 2); lcd.print(F("                    "));
    lcd.setCursor(0, 2); lcd.print(displayInfo);
    lastmcInfo = displayInfo;
  }
  // ---- Baris 3: NIK | OKCount / NGCount ----
  if (op_NIK != lastop_NIK || forceUpdate) {
    lcd.setCursor(0, 3); lcd.print(F("         "));
    lcd.setCursor(0, 3); lcd.print(op_NIK.substring(0, 8));
    lastop_NIK = op_NIK;
  }
  OKCount = prodCount - NGCount;
  if (OKCount != lastOKCount || forceUpdate) {
    lcd.setCursor(9, 3); lcd.print(F("|      "));
    lcd.setCursor(11, 3); lcd.print(OKCount);
    lastOKCount = OKCount;
  }
  if (NGCount != lastNGCount || forceUpdate) {
    lcd.setCursor(16, 3); lcd.print(F("/   "));
    lcd.setCursor(17, 3); lcd.print(NGCount);
    lastNGCount = NGCount;
  }
}
// =====================================================================
//                   PUBLISH DOWNTIME DATA
// =====================================================================
void publishDowntime(String kode_dt, int durasi) {
  StaticJsonDocument<200> doc;
  doc["mcID"]         = display_idMesin;
  doc["kode_dt"]      = kode_dt;
  doc["durasi_detik"] = durasi;
  char jsonStr[200];
  serializeJson(doc, jsonStr);
  publishWithRetry("SMMS/Data/Downtime", jsonStr);
  Serial.print(F("[DT] ")); Serial.println(jsonStr);
}
// =====================================================================
//                    SETUP ETHERNET
// =====================================================================
void setupEthernet() {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(F("Koneksi Ethernet..."));
  Ethernet.begin(mac, ip);
  delay(1000); // Tunggu Ethernet shield siap
  lcd.setCursor(0, 1); lcd.print(F("IP: "));
  lcd.print(Ethernet.localIP());
  Serial.print(F("[ETH] IP: ")); Serial.println(Ethernet.localIP());
  delay(500);
}
// =====================================================================
//                    RECONNECT MQTT
// =====================================================================
void reconnectMQTT() {
  // Buat client ID unik
  String clientId = "SMMS_Mega_" + String(random(0xffff), HEX);
  lcd.setCursor(0, 3); lcd.print(F("MQTT Connecting...  "));
  Serial.print(F("[MQTT] Connecting as ")); Serial.println(clientId);
  if (client.connect(clientId.c_str(), "AdminMQTT", "pwd123")) {
    Serial.println(F("[MQTT] Connected!"));
    lcd.setCursor(0, 3); lcd.print(F("MQTT OK!            "));
    // Subscribe ke semua Reply topics dari Node-RED
    client.subscribe("SMMS/Reply/Setup");
    client.subscribe("SMMS/Reply/Login");
    client.subscribe("SMMS/Reply/Proses");
  } else {
    Serial.print(F("[MQTT] Failed, rc="));
    Serial.println(client.state());
    lcd.setCursor(0, 3); lcd.print(F("MQTT Retry...       "));
  }
}
// =====================================================================
//            BACA COUNTER DARI ARDUINO NANO (I2C SLAVE)
//  Arduino Nano menghitung pulsa dari sensor produksi (encoder/proximity)
//  dan mengirimkan nilai counter via I2C saat diminta.
// =====================================================================
unsigned int readCounterFromNano() {
  // Simpan nilai terakhir yang valid agar counter tidak drop ke 0 saat I2C gagal sesaat
  static unsigned int lastValidCounter = 0;
  Wire.requestFrom(8, 2);
  if (Wire.available() == 2) {
    byte highByte = Wire.read();
    byte lowByte  = Wire.read();
    lastValidCounter = ((unsigned int)highByte << 8) | lowByte;
  }
  return lastValidCounter;
}
// =====================================================================
//            EEPROM I2C AT24C256 - HELPER FUNCTIONS
//  Untuk menyimpan dan membaca ID Mesin secara permanen
// =====================================================================
// Tulis string ke EEPROM (dengan null terminator)
void writeStringToEEPROM(int addr, String data) {
  int len = data.length();
  for (int i = 0; i < len; i++) {
    Wire.beginTransmission(EEPROM_ADDRESS);
    Wire.write((int)(addr >> 8));     // High byte alamat
    Wire.write((int)(addr & 0xFF));   // Low byte alamat
    Wire.write(data[i]);
    Wire.endTransmission();
    delay(5);  // EEPROM butuh waktu tulis ~5ms
    addr++;
  }
  // Tulis null terminator
  Wire.beginTransmission(EEPROM_ADDRESS);
  Wire.write((int)(addr >> 8));
  Wire.write((int)(addr & 0xFF));
  Wire.write('\0');
  Wire.endTransmission();
  delay(5);
}
// Baca string dari EEPROM (sampai null terminator)
String readStringFromEEPROM(int addr) {
  char data[32];
  int len = 0;
  while (len < 31) {
    Wire.beginTransmission(EEPROM_ADDRESS);
    Wire.write((int)(addr >> 8));
    Wire.write((int)(addr & 0xFF));
    Wire.endTransmission();
    Wire.requestFrom(EEPROM_ADDRESS, 1);
    if (Wire.available()) {
      char c = Wire.read();
      if (c == '\0') break;
      data[len++] = c;
    }
    addr++;
  }
  data[len] = '\0';
  return String(data);
}
// Tulis 1 byte ke EEPROM
void writeByteToEEPROM(int addr, byte val) {
  Wire.beginTransmission(EEPROM_ADDRESS);
  Wire.write((addr >> 8) & 0xFF);
  Wire.write(addr & 0xFF);
  Wire.write(val);
  Wire.endTransmission();
  delay(5);
}
// Baca 1 byte dari EEPROM
byte readByteFromEEPROM(int addr) {
  Wire.beginTransmission(EEPROM_ADDRESS);
  Wire.write((addr >> 8) & 0xFF);
  Wire.write(addr & 0xFF);
  Wire.endTransmission();
  Wire.requestFrom(EEPROM_ADDRESS, 1);
  return Wire.available() ? Wire.read() : 0xFF;
}
// =====================================================================
//                    PUBLISH DENGAN RETRY (ETHERNET)
// =====================================================================
bool publishWithRetry(const char* topic, const char* payload) {
  if (!client.connected()) reconnectMQTT();
  if (client.publish(topic, payload)) return true;
  
  // Jika gagal (socket terputus diam-diam), putuskan dan reconnect ulang
  ethClient.stop();
  client.disconnect();
  reconnectMQTT();
  if (client.connected()) return client.publish(topic, payload);
  return false;
}
