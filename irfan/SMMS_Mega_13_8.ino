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

// RFID PN532 I2C (IRQ & RESET diset ke -1)
Adafruit_PN532 nfc(-1, -1);  // IRQ & RESET -1 untuk I2C mode
// Key FFFFFFFFFFFF untuk Autentikasi
uint8_t keyFFFF[] = { 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF }; 
// CATATAN: Untuk mode I2C, panggil nfc.begin() yang otomatis scan alamat I2C.

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
  BOOT,               // 0  - Tampilan splash screen 3 detik
  SETUP_MODE,         // 1  - Input ID Mesin via keypad
  WAIT_SETUP_REPLY,   // 2  - Menunggu balasan MQTT setup
  LOGIN_RFID,         // 3  - Menunggu kartu RFID ditempelkan
  WAIT_NIK_REPLY,     // 4  - Menunggu balasan MQTT login (skill check)
  SKILL_CHECK,        // 5  - Tampilkan hasil skill check
  INPUT_PROCESS,      // 6  - Input kode proses via keypad
  WAIT_PROCESS_REPLY, // 7  - Menunggu balasan MQTT proses
  CONFIRM_PROCESS,    // 8  - Konfirmasi part/proses yang dipilih
  MAIN_SCREEN,        // 9  - Layar utama monitoring produksi
  INFO_SCREEN,        // 10 - Layar info detail (Part, PartNo, Proses, mcID)
  INPUT_NG_QTY,       // 11 - Input jumlah NG / Repair
  INPUT_NG_CODE       // 12 - Input kode NG / Repair
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
int prodCount = 0;   // Total produksi (dari Arduino Nano)
int NGCount   = 0;   // Total NG
int OKCount   = 0;   // OK = prodCount - NGCount
int cycleCountSaved    = 0;  // Counter tersimpan
int cycleCountReceived = 0;  // Counter dari Nano
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

// --- RFID Continuous Hold & Hardware Status ---
bool rfidHardwareFound          = false; // Flag status hardware PN532 terdeteksi I2C
unsigned long lastRfidCheck     = 0;     // Waktu terakhir polling RFID
unsigned long lastCardDetectTime = 0;    // Waktu terakhir kartu terdeteksi
bool rfidCardPresent            = false; // Apakah kartu sedang terbaca
String currentRfidUID           = "";    // UID kartu yang sedang terbaca
const unsigned long RFID_POLL_INTERVAL = 50;    // Polling RFID setiap 50ms (Persis logic_login_nik_rfid.ino)
const unsigned long RFID_TIMEOUT       = 2000;  // Kartu dianggap dicabut setelah 2 detik

// --- Logika Hold Kartu (dari logic_login_nik_rfid.ino) ---
const uint8_t TARGET_BLOCK    = 4;      // Sector 1 Block 0 = Block 4
String activeUID               = "";     // Raw UID Hex kartu aktif
int missingCardCount           = 0;      // Counter toleransi pembacaan lepas
const int MAX_MISSING_LIMIT    = 3;      // Toleransi 3x gagal baca (~150ms) baru LOCK

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
void readNGButtons();
void readRFID();
void checkRFIDHold(unsigned long currentMillis);
String convertUIDToString(uint8_t *uid, uint8_t uidLength);
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
  //Wire.setWireTimeout(5000, true);
  // --- Inisialisasi LCD ---
  lcd.init();
  lcd.backlight();
/*
  // --- Inisialisasi RTC ---
  if (!rtc.begin()) {
    Serial.println(F("[RTC] TIDAK DITEMUKAN!"));
    lcd.setCursor(3, 1); lcd.print(F("RTC ERROR!"));
    delay(2000);
  } else {
    Serial.println(F("[RTC] OK"));
  }
*/
  // --- Inisialisasi RFID PN532 (Auto-Retry 5x) ---
  nfc.begin();
  delay(100);

  uint32_t versiondata = 0;
  for (int retry = 0; retry < 5; retry++) {
    versiondata = nfc.getFirmwareVersion();
    if (versiondata) break;
    delay(100);
  }

  if (!versiondata) {
    rfidHardwareFound = false;
    Serial.println(F("❌ [RFID ERROR] PN532 TIDAK DITEMUKAN pada I2C Address 0x24!"));
    Serial.println(F("               Periksa: 1. DIP Switch PN532 (I2C Mode: 1=ON, 2=OFF)"));
    Serial.println(F("                        2. Kabel SDA (Pin 20) & SCL (Pin 21)"));
    Serial.println(F("                        3. Tegangan VCC 5V & GND"));
  } else {
    rfidHardwareFound = true;
    Serial.print(F("✅ [RFID OK] PN532 Chip Ditemukan! Chip PN5"));
    Serial.println((versiondata >> 24) & 0xFF, HEX);
    Serial.print(F("✅ [RFID OK] Firmware Version: "));
    Serial.print((versiondata >> 16) & 0xFF, DEC);
    Serial.print('.'); Serial.println((versiondata >> 8) & 0xFF, DEC);

    // Konfigurasi PN532 untuk membaca kartu MIFARE (Persis logic_login_nik_rfid.ino)
    nfc.SAMConfig();
    Serial.println(F("✅ [RFID OK] PN532 SAMConfig Ready."));
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
  Serial.println(F("looping masih berjalan"));
  unsigned long currentMillis = millis();
  //Serial.println("cek looping berjalan");
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
  if (callbackMsgActive) {
    Serial.print(F("[DBG] callbackMsgActive=TRUE, currentState=")); Serial.println(currentState);
    handleNonBlockingMsg();
    return;
  }

  // --- DEBUG: Print state setiap 2 detik ---
  static unsigned long lastStatePrint = 0;
  if (millis() - lastStatePrint > 2000) {
    lastStatePrint = millis();
    Serial.print(F("[DBG] currentState=")); Serial.print(currentState);
    Serial.print(F(" | callbackMsgActive=")); Serial.println(callbackMsgActive);
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
        if (mcID == "") {
          changeState(SETUP_MODE);
        } else {
          changeState(LOGIN_RFID);
        }
      }
      break;

    // -------------------- SETUP MODE --------------------
    // Teknisi memasukkan ID Mesin via keypad
    // D = OK (kirim ke MQTT untuk verifikasi)
    // C = Batal (kembali jika mcID sudah ada)
    // # = Backspace
    case SETUP_MODE:
      /*
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'B')) {
          // Input karakter (angka dan A/B)
          inputBuffer += key;
          forceUpdate = true;
        } else if (key == 'C') {
          // Batal Setup, kembali ke Login jika mcID sudah ada
          if (mcID != "") changeState(LOGIN_RFID);
          else changeState(BOOT);
        } else if (key == '#' && inputBuffer.length() > 0) {
          // '#' sebagai backspace di mode setup
          inputBuffer.remove(inputBuffer.length() - 1);
          forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          // Kirim request setup ke Node-RED untuk verifikasi ID mesin
          StaticJsonDocument<200> doc;
          doc["mcID"] = inputBuffer;
          char jsonBuffer[200];
          serializeJson(doc, jsonBuffer);
          if (client.publish("SMMS/Request/Setup", jsonBuffer)) {
            changeState(WAIT_SETUP_REPLY);
          } else {
            showNonBlockingMsg("Koneksi Terputus!", SETUP_MODE, 2000);
          }
        }
      }
      // Update tampilan LCD
      if (forceUpdate) {
        lcd.clear();
        lcd.setCursor(0, 0); lcd.print(F("Mode Setup Teknisi"));
        lcd.setCursor(0, 1); lcd.print(F("Masukkan ID Mesin:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK  C:Batal"));
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      */

      // Hanya publish ke MQTT jika terkoneksi
      if (client.connected()) {
        StaticJsonDocument<200> doc;
        doc["mcID"] = 3012;
        char jsonBuffer[200];
        serializeJson(doc, jsonBuffer);
        if (client.publish("SMMS/Request/Setup", jsonBuffer)) {
          changeState(WAIT_SETUP_REPLY);
        } else {
          showNonBlockingMsg("Koneksi Terputus!", SETUP_MODE, 2000);
        }
      }
      break;

    // -------------------- WAIT STATES --------------------
    // Tampilan "Memproses..." selama menunggu balasan MQTT
    // Timeout 10 detik -> kembali ke state sebelumnya
    case WAIT_SETUP_REPLY:
    case WAIT_NIK_REPLY:
    case WAIT_PROCESS_REPLY:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(4, 1); lcd.print(F("Memproses..."));
        forceUpdate = false;
      }
      // Timeout 10 detik -> kembali ke state sebelumnya
      if (currentMillis - stateStartTime > 10000) {
        if (currentState == WAIT_SETUP_REPLY) {
          showNonBlockingMsg("Timeout! Coba lagi", SETUP_MODE, 2000);
        } else if (currentState == WAIT_NIK_REPLY) {
          showNonBlockingMsg("Timeout! Coba lagi", LOGIN_RFID, 2000);
        } else {
          showNonBlockingMsg("Timeout! Coba lagi", INPUT_PROCESS, 2000);
        }
      }
      break;

    // -------------------- LOGIN RFID --------------------
    // Menunggu operator menempelkan kartu RFID
    // Sistem membaca UID kartu, lalu kirim ke MQTT untuk validasi skill
    case LOGIN_RFID:
      Serial.println(F("\n🚀 fungsi LOGIN_RFID berjalan"));
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print("PT. CNC - " + mcID);
        lcd.setCursor(0, 1); lcd.print(F("Letakkan Kartu NIK"));
        lcd.setCursor(0, 2); lcd.print(F("Anda pada Reader"));
        lcd.setCursor(0, 3); lcd.print(F("...menunggu kartu..."));
        // Pastikan interlock AKTIF saat belum login
        digitalWrite(interlockPin, HIGH);
        Serial.println(F("\n🚀 State LOGIN_RFID Siap! Silakan tempelkan Kartu NIK..."));
        forceUpdate = false;
      }
      // Polling RFID setiap RFID_POLL_INTERVAL ms
      if (currentMillis - lastRfidCheck >= RFID_POLL_INTERVAL) {
        lastRfidCheck = currentMillis;
        readRFID(); // Fungsi ini akan trigger state change jika kartu terbaca
      }
      break;

    // -------------------- SKILL CHECK --------------------
    // Tampilkan hasil pengecekan skill dari callback MQTT
    // Jika skill >= 3: Lanjut ke input proses (auto-advance 1.5 detik)
    // Jika skill < 3: Tampilkan pesan error, kembali ke login RFID
    case SKILL_CHECK:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (op_Level >= 3) {
          // Login berhasil, skill cukup
          lcd.setCursor(0, 0); lcd.print(F("Login Berhasil!"));
          lcd.setCursor(0, 1); lcd.print(op_Name.substring(0, 20));
          lcd.setCursor(0, 2); lcd.print("Level: " + String(op_Level) + "/4");
          lcd.setCursor(0, 3); lcd.print(F("Lanjut otomatis..."));
          // Interlock OFF (mesin boleh jalan)
          digitalWrite(interlockPin, LOW);
        } else {
          // Skill tidak cukup
          lcd.setCursor(0, 0); lcd.print(tempMessage);
          lcd.setCursor(0, 2); lcd.print(F("Ganti kartu / Hubungi"));
          lcd.setCursor(0, 3); lcd.print(F("supervisor Anda."));
          // Interlock tetap ON
          digitalWrite(interlockPin, HIGH);
        }
        forceUpdate = false;
      }
      // Auto-advance ke INPUT_PROCESS jika skill cukup (setelah 1.5 detik)
      if (op_Level >= 3 && currentMillis - stateStartTime > 1500) {
        changeState(INPUT_PROCESS);
      }
      // Jika skill kurang, tunggu kartu dicabut -> kembali ke LOGIN_RFID
      if (op_Level < 3 && currentMillis - stateStartTime > 2000) {
        changeState(LOGIN_RFID);
      }
      break;

    // -------------------- INPUT PROSES --------------------
    // Operator memasukkan kode proses via keypad
    // D = OK (kirim ke MQTT)
    // # = Backspace
    // C = Batal (tidak dipakai, reserved)
    case INPUT_PROCESS:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'C')) {
          inputBuffer += key;
          forceUpdate = true;
        } else if (key == '#' && inputBuffer.length() > 0) {
          inputBuffer.remove(inputBuffer.length() - 1);
          forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          // Kirim request proses ke Node-RED
          StaticJsonDocument<200> doc;
          doc["kode_proses"] = inputBuffer;
          char jsonBuffer[200];
          serializeJson(doc, jsonBuffer);
          if (client.publish("SMMS/Request/Proses", jsonBuffer)) {
            changeState(WAIT_PROCESS_REPLY);
          } else {
            showNonBlockingMsg("Koneksi Terputus!", INPUT_PROCESS, 2000);
          }
        }
      }
      if (forceUpdate) {
        lcd.clear();
        lcd.setCursor(0, 0); lcd.print("OP: " + op_NIK);
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Kd Proses:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK  #:Hapus"));
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }

      // --- RFID Continuous Hold: Cek kartu masih terbaca ---
      if (currentMillis - lastRfidCheck >= RFID_POLL_INTERVAL) {
        lastRfidCheck = currentMillis;
        checkRFIDHold(currentMillis);
      }
      break;

    // -------------------- CONFIRM PROSES --------------------
    // Tampilkan detail Part/Proses dari DB
    // D = Lanjut ke produksi
    // C = Batal, input ulang kode proses
    case CONFIRM_PROCESS:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20));
        lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print(F("D:Lanjut  C:Batal"));
        forceUpdate = false;
      }
      if (key == 'D') {
        changeState(MAIN_SCREEN);
      } else if (key == 'C') {
        changeState(INPUT_PROCESS);
      }

      // --- RFID Continuous Hold ---
      if (currentMillis - lastRfidCheck >= RFID_POLL_INTERVAL) {
        lastRfidCheck = currentMillis;
        checkRFIDHold(currentMillis);
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
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (isRepairMode) {
          lcd.setCursor(0, 0); lcd.print(F("== REPAIR NG =="));
        } else {
          lcd.setCursor(0, 0); lcd.print(F("== TAMBAH NG =="));
        }
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Qty:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK  C:Batal"));
        forceUpdate = false;
      }
      if (key) {
        if (key >= '0' && key <= '9' && inputBuffer.length() < 4) {
          inputBuffer += key;
          lcd.setCursor(0, 2); lcd.print(F("                    "));
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'C') {
          // Batal, kembali ke Main Screen
          changeState(MAIN_SCREEN);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          ngQtyInput = inputBuffer.toInt();
          if (ngQtyInput > 0) {
            // Validasi: Repair tidak boleh melebihi NGCount
            if (isRepairMode && ngQtyInput > NGCount) {
              lcd.setCursor(0, 2); lcd.print(F("Melebihi NG Count!  "));
              lcd.setCursor(0, 3); lcd.print("Max: " + String(NGCount) + "       ");
              inputBuffer = "";
            } else {
              inputBuffer = "";
              changeState(INPUT_NG_CODE);
            }
          }
        }
      }
      break;

    // -------------------- INPUT NG CODE --------------------
    // Input kode NG atau kode Repair
    // D = OK (simpan & publish)
    // C = Backspace
    // # = Batal (kembali ke INPUT_NG_QTY)
    case INPUT_NG_CODE:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (isRepairMode) {
          lcd.setCursor(0, 0); lcd.print("REPAIR Qty: " + String(ngQtyInput));
        } else {
          lcd.setCursor(0, 0); lcd.print("NG Qty: " + String(ngQtyInput));
        }
        lcd.setCursor(0, 1); lcd.print(F("Masukkan Kode:"));
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print(F("D:OK C:Hps #:Batal"));
        forceUpdate = false;
      }
      if (key) {
        if (((key >= '0' && key <= '9') || key == 'A' || key == 'B') && inputBuffer.length() < 10) {
          inputBuffer += key;
          lcd.setCursor(0, 2); lcd.print(F("                    "));
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'C' && inputBuffer.length() > 0) {
          // 'C' sebagai backspace di mode kode
          inputBuffer.remove(inputBuffer.length() - 1);
          lcd.setCursor(0, 2); lcd.print(F("                    "));
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          ngCodeInput = inputBuffer;

          // --- Validasi Anti-Phantom Repair & Simpan ke Memori Lokal ---
          if (!isRepairMode) {
            // MODE NG+ (TAMBAH NG)
            // Cek: Total NG tidak boleh melebihi total produksi
            if ((NGCount + ngQtyInput) > prodCount) {
              showNonBlockingMsg("NG > Produksi!", INPUT_NG_QTY, 1500);
              inputBuffer = "";
              return;
            }

            // Simpan ke memori lokal NG
            bool found = false;
            for (int i = 0; i < activeNgCount; i++) {
              if (localNgCodes[i] == ngCodeInput && localNgProses[i] == kode_proses) {
                localNgQtys[i] += ngQtyInput;
                found = true;
                break;
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
            // MODE NG- (REPAIR)
            // Validasi: Kode NG harus pernah diinput sebelumnya & qty cukup
            bool validRepair = false;
            int foundIndex = -1;

            for (int i = 0; i < activeNgCount; i++) {
              if (localNgCodes[i] == ngCodeInput && localNgProses[i] == kode_proses) {
                foundIndex = i;
                if ((int)localNgQtys[i] >= ngQtyInput) {
                  validRepair = true;
                }
                break;
              }
            }

            if (!validRepair) {
              showNonBlockingMsg("Kode/Qty Invalid!", INPUT_NG_QTY, 1500);
              inputBuffer = "";
              return;
            }

            // Eksekusi Repair
            localNgQtys[foundIndex] -= ngQtyInput;
            NGCount -= ngQtyInput;
          }

          OKCount = prodCount - NGCount;

          // --- Publish ke MQTT topic SMMS/Data/NG ---
          StaticJsonDocument<256> doc;
          doc["mcID"]        = display_idMesin;
          doc["kode_proses"] = kode_proses;
          doc["kode_ng"]     = ngCodeInput;
          if (isRepairMode) {
            doc["qty_ng"]   = -(ngQtyInput);  // Negatif untuk Repair
            doc["kategori"] = "Repair NG";
          } else {
            doc["qty_ng"]   = ngQtyInput;     // Positif untuk Tambah
            doc["kategori"] = "Tambah NG";
          }
          char jsonStr[256];
          serializeJson(doc, jsonStr);
          client.publish("SMMS/Data/NG", jsonStr);

          Serial.print(F("[NG] Published: ")); Serial.println(jsonStr);

          // Kembali ke Main Screen dengan pesan konfirmasi
          String konfirm = isRepairMode ? "Repair OK!" : "NG Tercatat!";
          showNonBlockingMsg(konfirm, MAIN_SCREEN, 1200);

        } else if (key == '#') {
          // Batal, kembali ke INPUT_NG_QTY
          inputBuffer = "";
          changeState(INPUT_NG_QTY);
        }
      }
      break;

  } // end switch

  // =====================================================================
  //          BACKGROUND TASKS (Berjalan selama produksi aktif)
  // =====================================================================
  if (currentState >= MAIN_SCREEN) {

    // --- Baca Status Mesin ---
    readMachineStatus();

    // --- Baca Tombol NG ---
    readNGButtons();

    // --- RFID Continuous Hold: Pastikan kartu masih terbaca ---
    if (currentMillis - lastRfidCheck >= RFID_POLL_INTERVAL) {
      lastRfidCheck = currentMillis;
      checkRFIDHold(currentMillis);
    }

    // --- Baca Counter Produksi dari Arduino Nano ---
    cycleCountReceived = readCounterFromNano();
    prodCount = (cycleCountSaved + cycleCountReceived) * cavity;
    OKCount = prodCount - NGCount;

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
      client.publish("SMMS/Data/Produksi", jsonStr);
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
      showNonBlockingMsg("Berhasil Disimpan!", LOGIN_RFID, 1500);
    } else {
      showNonBlockingMsg("ID Gagal Terdaftar!", SETUP_MODE, 1500);
    }
  }

  // -------------------- Reply Login --------------------
  // Respons dari Node-RED setelah cek master_operator + skill_matrix
  else if (topicStr == "SMMS/Reply/Login" && currentState == WAIT_NIK_REPLY) {
    if (status == "success") {
      // Login berhasil, skill cukup
      op_NIK   = currentRfidUID;   // Simpan UID kartu sebagai NIK
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
    // Reset counter dari Nano (simpan state saat ini)
    cycleCountSaved = cycleCountSaved + cycleCountReceived;
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
//                  RFID PN532 - BACA KARTU (PERSIS logic_login_nik_rfid.ino)
//  Dipanggil saat state LOGIN_RFID untuk mendeteksi kartu pertama kali
// =====================================================================
void readRFID() {
  if (!rfidHardwareFound) {
    // Coba re-initialize PN532 I2C setiap 3 detik jika sebelumnya belum terhubung saat boot
    static unsigned long lastInitRetry = 0;
    if (millis() - lastInitRetry > 3000) {
      lastInitRetry = millis();
      Serial.println(F("🔄 [RFID RETRY] Memeriksa kembali modul PN532 di I2C (0x24)..."));
      nfc.begin();
      uint32_t vData = nfc.getFirmwareVersion();
      if (vData) {
        rfidHardwareFound = true;
        nfc.SAMConfig();
        Serial.println(F("✅ [RFID OK] PN532 Berhasil Terhubung!"));
      } else {
        Serial.println(F("❌ [RFID ERROR] PN532 belum merespons I2C (0x24). Cek DIP Switch 1=ON 2=OFF & Wiring Pin 20(SDA)/21(SCL)."));
      }
    }
    return;
  }

  uint8_t uid[7];      // Buffer UID
  uint8_t uidLength;   // Panjang UID yang terbaca

  // Cek keberadaan kartu (Timeout 50ms seperti logic_login_nik_rfid.ino)
  bool success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 50);

  if (success && uidLength == 4) {
    // Autentikasi menggunakan Key B (Tipe = 1) dengan kunci FFFFFFFFFFFF
    if (nfc.mifareclassic_AuthenticateBlock(uid, uidLength, TARGET_BLOCK, 1, keyFFFF)) {
      uint8_t data[16];

      // Baca Data Block 4
      if (nfc.mifareclassic_ReadDataBlock(TARGET_BLOCK, data)) {
        // Extract NIK ke String Bersih
        String readNIK = "";
        for (uint8_t i = 0; i < 16; i++) {
          if (data[i] >= 32 && data[i] <= 126) {
            readNIK += (char)data[i];
          }
        }

        if (readNIK.length() > 0) {
          Serial.println(F("\n💳 Kartu Terdeteksi!"));
          Serial.print(F("👤 NIK Terbaca dari Block 4: "));
          Serial.println(readNIK);

          currentRfidUID = readNIK;
          activeUID = convertUIDToString(uid, uidLength);
          rfidCardPresent = true;
          missingCardCount = 0;
          lastCardDetectTime = millis();

          // Kirim ke MQTT untuk validasi skill
          StaticJsonDocument<200> doc;
          doc["op_NIK"] = readNIK;
          doc["mcID"]   = mcID;
          char jsonBuffer[200];
          serializeJson(doc, jsonBuffer);

          if (client.publish("SMMS/Request/Login", jsonBuffer)) {
            Serial.println(F("📡 Request Login terkirim ke MQTT..."));
            changeState(WAIT_NIK_REPLY);
          } else {
            showNonBlockingMsg("Koneksi Terputus!", LOGIN_RFID, 2000);
          }
        }
      } else {
        Serial.println(F("⚠️  Gagal membaca Data Block 4!"));
      }
    } else {
      Serial.println(F("🔑 Autentikasi Gagal! Kartu menolak Key B."));
    }
  }
}

// =====================================================================
//              RFID CONTINUOUS HOLD - CEK KARTU MASIH ADA
//  Dipanggil secara berkala saat produksi berjalan (logic_login_nik_rfid.ino).
//  Jika kartu dicabut (gagal baca > 3x limit) -> interlock ON
//  Jika kartu ditukar (UID berbeda) -> reset ke lock & login ulang
// =====================================================================
void checkRFIDHold(unsigned long currentMillis) {
  if (!rfidHardwareFound) return;

  uint8_t uid[7];
  uint8_t uidLength;

  bool success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 50);

  if (success) {
    String currentUID = convertUIDToString(uid, uidLength);

    if (currentUID == activeUID) {
      missingCardCount = 0; // Kartu masih menempel, Mesin tetap Ready
      rfidCardPresent = true;
      lastCardDetectTime = currentMillis;
    } else {
      Serial.println(F("\n🚨 PERINGATAN: Kartu Diganti Personel Lain!"));
      Serial.println(F("🔴 STATUS: INTERLOCK ON"));
      digitalWrite(interlockPin, HIGH);

      activeUID = "";
      currentRfidUID = "";
      op_NIK = "";
      op_Name = "";
      op_Level = 0;
      rfidCardPresent = false;

      showNonBlockingMsg("Kartu Ditukar!", LOGIN_RFID, 1000);
    }
  } else {
    missingCardCount++;

    // Jika kartu diangkat/jauh dari sensor (melebihi limit toleransi 3x)
    if (rfidCardPresent && missingCardCount >= MAX_MISSING_LIMIT) {
      Serial.println(F("\n🖐️ Kartu Diambil / Lepas dari Sensor!"));
      Serial.println(F("🔴 STATUS: INTERLOCK ON"));
      digitalWrite(interlockPin, HIGH);

      activeUID = "";
      currentRfidUID = "";
      op_NIK = "";
      op_Name = "";
      op_Level = 0;
      rfidCardPresent = false;

      showNonBlockingMsg("Kartu Dicabut!", LOGIN_RFID, 1500);
    }
  }
}

// =====================================================================
//           KONVERSI ARRAY UID BYTE KE STRING HEX (DARI LOGIC RFID)
// =====================================================================
String convertUIDToString(uint8_t *uid, uint8_t uidLength) {
  String str = "";
  for (uint8_t i = 0; i < uidLength; i++) {
    if (i > 0) str += " ";
    if (uid[i] < 0x10) str += "0";
    str += String(uid[i], HEX);
  }
  str.toUpperCase();
  return str;
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
      prodCount = 0;
      NGCount   = 0;
      OKCount   = 0;
      cycleCountSaved = 0;

      // Reset memori lokal NG
      for (int i = 0; i < 20; i++) {
        localNgCodes[i] = "";
        localNgQtys[i] = 0;
      }
      activeNgCount = 0;

      // Reset counter di Arduino Nano (kirim sinyal reset)
      Wire.beginTransmission(NANO_I2C_ADDR);
      Wire.endTransmission();

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
  client.publish("SMMS/Data/Downtime", jsonStr);

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
  Wire.requestFrom(NANO_I2C_ADDR, 2);  // Minta 2 byte dari Nano
  if (Wire.available() == 2) {
    return (Wire.read() << 8) | Wire.read();  // Gabung High + Low byte
  }
  return 0;
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
