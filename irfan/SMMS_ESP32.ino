/*
 * ============================================================
 *  SMART MACHINE MONITORING SYSTEM (SMMS) - PT. CNC
 *  ESP32 Firmware v2.0 - Full FSM, Non-Blocking
 * ============================================================
 *  Architecture: Finite State Machine (FSM)
 *  MQTT Broker : 172.237.72.25:1883
 *  Node-RED Topics:
 *    PUB -> SMMS/Request/Setup, SMMS/Request/Login, 
 *           SMMS/Request/Proses, SMMS/Data/Produksi,
 *           SMMS/Data/NG, SMMS/Data/Downtime
 *    SUB <- SMMS/Reply/Setup, SMMS/Reply/Login, SMMS/Reply/Proses
 * ============================================================
 */

#include <WiFi.h>
#include <PubSubClient.h>
#include <Keypad.h>
#include <ArduinoJson.h>
#include <LiquidCrystal_I2C.h>
#include <Wire.h>
#include <Preferences.h>

// ================= KONFIGURASI JARINGAN & MQTT =================
const char* ssid          = "Wokwi-GUEST";
const char* password      = "";
const char* mqtt_server   = "test.mosquitto.org";
const int   mqtt_port     = 1883;

WiFiClient espClient;
PubSubClient client(espClient);
Preferences preferences;
LiquidCrystal_I2C lcd(0x27, 20, 4);

// ================= KONFIGURASI PIN HARDWARE =================
// SDA = 21, SCL = 22
#define mcON       34      // Input: Mesin ON (tanpa pull-up)
#define mcRun      35      // Input: Mesin Running (tanpa pull-up)
#define alarmPin   39      // Input: Alarm (tanpa pull-up)
#define btnCount   23      // Tombol Counter Produksi
#define ngAdd      32      // Tombol NG + (Tambah NG)
#define ngSub      33      // Tombol NG - (Repair / Reset tahan 5 detik)
#define btnUp       4      // Tombol Up (Setup combo)
#define btnDown    12      // Tombol Down (Setup combo)
#define btnDandory  5      // Tombol info Dandory
#define btnMinum   15      // Tombol info Minum

// ================= KEYPAD 4x4 =================
const byte ROWS = 4;
const byte COLS = 4;
char keys[ROWS][COLS] = {
  {'1', '2', '3', 'A'},
  {'4', '5', '6', 'B'},
  {'7', '8', '9', 'C'},
  {'*', '0', '#', 'D'}
};
byte rowPins[ROWS] = {19, 18, 17, 16};
byte colPins[COLS]  = {25, 26, 27, 14};
Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);

// ================= STATE MACHINE ENUM =================
enum SystemState {
  BOOT,
  SETUP_MODE,
  WAIT_SETUP_REPLY,
  LOGIN_NIK,
  WAIT_NIK_REPLY,
  SKILL_CHECK,
  INPUT_PROCESS,
  WAIT_PROCESS_REPLY,
  CONFIRM_PROCESS,
  MAIN_SCREEN,
  INFO_SCREEN,
  INPUT_NG_QTY,
  INPUT_NG_CODE
};
SystemState currentState = BOOT;

// ================= VARIABEL GLOBAL =================
// --- Identitas Mesin & Operator ---
String mcID            = "";
String display_idMesin = "";
String op_NIK          = "";
String op_Name     = "";
int    op_Level    = 0;

// --- Data Proses ---
String partID      = "";
String partNo      = "";
String prosesDesc  = "";
String kode_proses = "";

// --- Status Mesin ---
String mcStatus         = "off";
String mcInfo           = "Mesin Off";
String previousMcStatus = "off";
String previousMcInfo   = "Stand By";

// --- Counter Produksi ---
int prodCount = 0;
int NGCount   = 0;
int OKCount   = 0;

// --- NG / Repair Input ---
bool   isRepairMode    = false;   // false=Tambah NG (NG+), true=Repair NG (NG-)
int    ngQtyInput      = 0;       // Qty NG/Repair yang diinput
String ngCodeInput     = "";      // Kode NG/Repair yang diinput

// --- Memori Lokal NG ---
String localNgCodes[20];
String localNgProses[20];
unsigned int localNgQtys[20];
int activeNgCount = 0;


// --- Buffer & State UI ---
String inputBuffer     = "";
unsigned long stateStartTime = 0;
unsigned long lastMsgTime    = 0;
bool   forceUpdate     = true;
String tempMessage     = "";      // Pesan sementara (Berhasil/Gagal/Error)

// --- Variabel Scrolling LCD (Baris 0, 1, 2) ---
unsigned long previousMillis1 = 0;
unsigned long previousMillis2 = 0;
unsigned long previousMillis3 = 0;
const unsigned long intervalScroll = 1500;
int posisi1 = 0, posisi2 = 0, posisi3 = 0;
String lastPartID    = "";
String lastProsesDesc = "";
String lastmcInfo    = "";
String lastop_NIK    = "";
int lastOKCount = 9999;
int lastNGCount = 9999;

// --- Variabel Tombol & Debounce ---
unsigned long ngSubPressTime   = 0;
bool ngSubPressed              = false;
bool lastCountState            = HIGH;
bool lastNgAddState            = HIGH;
unsigned long lastCountDebounce = 0;
unsigned long lastNgAddDebounce = 0;
const unsigned long debounceMs  = 50;

// --- Downtime Tracking ---
String  dtPreviousStatus       = "off";
unsigned long dtStandbyStart   = 0;
bool    dtIsTracking           = false;
String  dtCurrentCode          = "SB";   // Default kode downtime: StandBy

// --- Callback non-blocking message ---
unsigned long lastReconnectAttempt = 0;
unsigned long callbackMsgTime  = 0;
bool callbackMsgActive         = false;
String callbackMsgText         = "";
SystemState callbackNextState  = BOOT;

// ================= FORWARD DECLARATIONS =================
void setup_wifi();
void reconnect();
void callback(char* topic, byte* payload, unsigned int length);
void changeState(SystemState newState);
void processMainScreen();
void readMachineStatus();
void readProductionButtons();
void readNGButtons();
void publishDowntime(String kode_dt, int durasi);
void showNonBlockingMsg(String msg, SystemState nextState, unsigned long duration = 1500);
void handleNonBlockingMsg();

// ======================================================================
//                              SETUP
// ======================================================================
void setup() {
  Serial.begin(115200);
  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();

  // --- Pin Mode ---
  pinMode(mcON, INPUT);
  pinMode(mcRun, INPUT);
  pinMode(alarmPin, INPUT);
  pinMode(btnCount, INPUT_PULLUP);
  pinMode(ngAdd, INPUT_PULLUP);
  pinMode(ngSub, INPUT_PULLUP);
  pinMode(btnUp, INPUT_PULLUP);
  pinMode(btnDown, INPUT_PULLUP);
  pinMode(btnDandory, INPUT_PULLUP);
  pinMode(btnMinum, INPUT_PULLUP);

  // --- WiFi & MQTT ---
  setup_wifi();
  client.setServer("172.237.72.25", mqtt_port);
  client.setCallback(callback);
  client.setBufferSize(1024);

  // --- Ambil ID Mesin dari NVS ---
  preferences.begin("smms", false);
  mcID = preferences.getString("mcID", "");
  display_idMesin = preferences.getString("idMesin", mcID);

  // --- Mulai State Machine ---
  changeState(BOOT);
}

// ======================================================================
//                           LOOP UTAMA
// ======================================================================
void loop() {
  unsigned long currentMillis = millis();

  // --- Maintain MQTT Connection ---
  if (!client.connected()) {
    if (currentMillis - lastReconnectAttempt > 10000) {
      reconnect();
      lastReconnectAttempt = millis();
    }
  } else {
    client.loop();
  }

  char key = keypad.getKey();

  // --- Handle non-blocking callback message overlay ---
  if (callbackMsgActive) {
    handleNonBlockingMsg();
    return; // Skip state machine saat pesan sedang ditampilkan
  }

  // --- Global Trigger: Setup Mode ---
  if (digitalRead(btnUp) == LOW && digitalRead(btnDown) == LOW) {
    if (currentState != SETUP_MODE) {
      changeState(SETUP_MODE);
      return;
    }
  }

  // ======================== STATE MACHINE ========================
  switch (currentState) {

    // -------------------- BOOT --------------------
    case BOOT:
      if (currentMillis - stateStartTime < 3000) {
        // Wait 3 seconds
      } else {
        // Setelah 3 detik, pindah state
        if (mcID == "") {
          changeState(SETUP_MODE);
        } else {
          changeState(LOGIN_NIK);
        }
      }
      break;

    // -------------------- SETUP MODE --------------------
    case SETUP_MODE:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'B')) {
          inputBuffer += key;
          forceUpdate = true;
        } else if (key == 'C') {
          // Batal Setup, kembali ke Login
          if (mcID != "") changeState(LOGIN_NIK);
          else changeState(BOOT);
        } else if (key == '#' && inputBuffer.length() > 0) {
          // '#' sebagai backspace di mode setup
          inputBuffer.remove(inputBuffer.length() - 1);
          forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          // Kirim request setup ke Node-RED
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
      if (forceUpdate) {
        lcd.clear();
        lcd.setCursor(0, 0); lcd.print("Mode Setup Teknisi");
        lcd.setCursor(0, 1); lcd.print("Masukkan ID Mesin:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print("D:OK  C:Batal");
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;

    // -------------------- WAIT STATES --------------------
    case WAIT_SETUP_REPLY:
    case WAIT_NIK_REPLY:
    case WAIT_PROCESS_REPLY:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(4, 1); lcd.print("Memproses...");
        forceUpdate = false;
      }
      // Timeout 10 detik -> kembali ke state sebelumnya
      if (currentMillis - stateStartTime > 10000) {
        if (currentState == WAIT_SETUP_REPLY) {
          showNonBlockingMsg("Timeout! Coba lagi", SETUP_MODE, 2000);
        } else if (currentState == WAIT_NIK_REPLY) {
          showNonBlockingMsg("Timeout! Coba lagi", LOGIN_NIK, 2000);
        } else {
          showNonBlockingMsg("Timeout! Coba lagi", INPUT_PROCESS, 2000);
        }
      }
      break;

    // -------------------- LOGIN NIK --------------------
    case LOGIN_NIK:
      if (key) {
        if (key >= '0' && key <= '9' && inputBuffer.length() < 10) {
          inputBuffer += key;
          forceUpdate = true;
        } else if (key == 'C' && inputBuffer.length() > 0) {
          inputBuffer.remove(inputBuffer.length() - 1);
          forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
          StaticJsonDocument<200> doc;
          doc["op_NIK"] = inputBuffer;
          doc["mcID"]   = mcID;
          char jsonBuffer[200];
          serializeJson(doc, jsonBuffer);
          if (client.publish("SMMS/Request/Login", jsonBuffer)) {
            changeState(WAIT_NIK_REPLY);
          } else {
            showNonBlockingMsg("Koneksi Terputus!", LOGIN_NIK, 2000);
          }
        }
      }
      if (forceUpdate) {
        lcd.clear();
        lcd.setCursor(0, 0); lcd.print("PT. CNC - " + mcID);
        lcd.setCursor(0, 1); lcd.print("Masukkan NIK:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;

    // -------------------- SKILL CHECK --------------------
    case SKILL_CHECK:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (op_Level >= 3) {
          lcd.setCursor(0, 0); lcd.print("Login Berhasil!");
          lcd.setCursor(0, 1); lcd.print(op_Name.substring(0, 20));
          lcd.setCursor(0, 2); lcd.print("Level: " + String(op_Level) + "/4");
          lcd.setCursor(0, 3); lcd.print("Tekan D lanjut...");
        } else {
          lcd.setCursor(0, 0); lcd.print(tempMessage);
          lcd.setCursor(0, 2); lcd.print("Tekan D coba lagi");
        }
        forceUpdate = false;
      }
      // Auto-advance setelah 1 detik jika skill cukup
      if (op_Level >= 3 && (currentMillis - stateStartTime > 1000 || key == 'D')) {
        changeState(INPUT_PROCESS);
      }
      // Manual retry jika skill kurang
      if (op_Level < 3 && key == 'D') {
        changeState(LOGIN_NIK);
      }
      break;

    // -------------------- INPUT PROSES --------------------
    case INPUT_PROCESS:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'C')) {
          inputBuffer += key;
          forceUpdate = true;
        } else if (key == '#' && inputBuffer.length() > 0) {
          inputBuffer.remove(inputBuffer.length() - 1);
          forceUpdate = true;
        } else if (key == 'D' && inputBuffer.length() > 0) {
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
        lcd.setCursor(0, 0); lcd.print("Operator: " + op_NIK);
        lcd.setCursor(0, 1); lcd.print("Masukkan Kd Proses:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;

    // -------------------- CONFIRM PROSES --------------------
    case CONFIRM_PROCESS:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20));
        lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print("D:Lanjut  C:Batal");
        forceUpdate = false;
      }
      if (key == 'D') {
        changeState(MAIN_SCREEN);
      } else if (key == 'C') {
        changeState(INPUT_PROCESS);
      }
      break;

    // -------------------- MAIN SCREEN --------------------
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
      if (key == 'A') changeState(LOGIN_NIK);         // Ganti NIK
      else if (key == 'B') changeState(INPUT_PROCESS); // Ganti Proses
      else if (key == '*') changeState(INFO_SCREEN);   // Info Screen
      else if (key == '#' && !client.connected()) {
        lcd.clear();
        lcd.setCursor(0, 1); lcd.print("Manual Reconnect...");
        reconnect();
        lastReconnectAttempt = millis();
        forceUpdate = true;
      }

      processMainScreen();    // Update UI LCD (scrolling text)
      break;

    // -------------------- INFO SCREEN --------------------
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
    case INPUT_NG_QTY:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (isRepairMode) {
          lcd.setCursor(0, 0); lcd.print("== REPAIR NG ==");
        } else {
          lcd.setCursor(0, 0); lcd.print("== TAMBAH NG ==");
        }
        lcd.setCursor(0, 1); lcd.print("Masukkan Qty:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print("D:OK  C:Batal");
        forceUpdate = false;
      }
      if (key) {
        if (key >= '0' && key <= '9' && inputBuffer.length() < 4) {
          inputBuffer += key;
          // Update display Qty realtime
          lcd.setCursor(0, 2); lcd.print("                    ");
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'C') {
          // Batal, kembali ke Main Screen
          changeState(MAIN_SCREEN);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          ngQtyInput = inputBuffer.toInt();
          if (ngQtyInput > 0) {
            // Validasi: Repair tidak boleh melebihi NGCount
            if (isRepairMode && ngQtyInput > (int)NGCount) {
              lcd.setCursor(0, 2); lcd.print("Melebihi NG Count!");
              lcd.setCursor(0, 3); lcd.print("Max: " + String(NGCount) + "       ");
              // Reset input agar bisa coba lagi
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
    case INPUT_NG_CODE:
      if (forceUpdate) {
        lcd.clear();
        lcd.noBlink();
        if (isRepairMode) {
          lcd.setCursor(0, 0); lcd.print("REPAIR Qty: " + String(ngQtyInput));
        } else {
          lcd.setCursor(0, 0); lcd.print("NG Qty: " + String(ngQtyInput));
        }
        lcd.setCursor(0, 1); lcd.print("Masukkan Kode:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer);
        lcd.setCursor(0, 3); lcd.print("D:OK C:Hps #:Batal");
        forceUpdate = false;
      }
      if (key) {
        if (((key >= '0' && key <= '9') || key == 'A' || key == 'B') && inputBuffer.length() < 10) {
          inputBuffer += key;
          lcd.setCursor(0, 2); lcd.print("                    ");
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'C' && inputBuffer.length() > 0) {
          // 'C' sebagai backspace di mode kode
          inputBuffer.remove(inputBuffer.length() - 1);
          lcd.setCursor(0, 2); lcd.print("                    ");
          lcd.setCursor(0, 2); lcd.print(inputBuffer);
        } else if (key == 'D' && inputBuffer.length() > 0) {
          ngCodeInput = inputBuffer;

          // --- Validasi Anti-Phantom Repair & Local Memory ---
          if (!isRepairMode) {
            // MODE NG+ (TAMBAH)
            if ((NGCount + ngQtyInput) > prodCount) {
              showNonBlockingMsg("NG > Produksi!", INPUT_NG_QTY, 1500);
              inputBuffer = ""; // Reset input Qty
              return; // Batal eksekusi
            }
            
            // Simpan ke memori lokal
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
            bool validRepair = false;
            int foundIndex = -1;
            
            for (int i = 0; i < activeNgCount; i++) {
              if (localNgCodes[i] == ngCodeInput && localNgProses[i] == kode_proses) {
                foundIndex = i;
                if (localNgQtys[i] >= ngQtyInput) {
                  validRepair = true;
                }
                break;
              }
            }
            
            if (!validRepair) {
              showNonBlockingMsg("Kode/Qty Invalid!", INPUT_NG_QTY, 1500);
              inputBuffer = ""; // Reset input Qty
              return; // Batal eksekusi
            }
            
            // Eksekusi Repair
            localNgQtys[foundIndex] -= ngQtyInput;
            NGCount -= ngQtyInput;
          }
          
          OKCount = prodCount - NGCount;

          // --- Publish ke MQTT topic SMMS/Data/NG ---
          StaticJsonDocument<256> doc;
          doc["mcID"]     = display_idMesin;
          doc["kode_proses"] = kode_proses;
          doc["kode_ng"]  = ngCodeInput;
          if (isRepairMode) {
            doc["qty_ng"]   = -(ngQtyInput); // Negatif untuk Repair
            doc["kategori"] = "Repair NG";
          } else {
            doc["qty_ng"]   = ngQtyInput;    // Positif untuk Tambah
            doc["kategori"] = "Tambah NG";
          }
          char jsonStr[256];
          serializeJson(doc, jsonStr);
          client.publish("SMMS/Data/NG", jsonStr);

          Serial.print("[NG] Published: "); Serial.println(jsonStr);

          // Kembali ke Main Screen dengan pesan konfirmasi
          String konfirm = isRepairMode ? "Repair OK!" : "NG Tercatat!";
          showNonBlockingMsg(konfirm, MAIN_SCREEN, 1200);
        } else if (key == '#') {
          // Batal dan kembali ke mode sebelumnya (INPUT_NG_QTY)
          inputBuffer = "";
          changeState(INPUT_NG_QTY);
        }
      }
      break;

  } // end switch

  // ======================================================================
  //                BACKGROUND TASKS (Berjalan selama produksi)
  // ======================================================================
  if (currentState >= MAIN_SCREEN) {
    readMachineStatus();
    readProductionButtons();
    readNGButtons();        // NG+, NG-, dan Reset 5 detik

    // -------------------------------------------------------------
    // LOGIKA TRACKING DOWNTIME (Untuk Semua Status Non-Running)
    // -------------------------------------------------------------
    if (mcStatus != "run" && dtPreviousStatus == "run") {
      dtStandbyStart = currentMillis;
      dtIsTracking = true;
      
      // Tentukan kode downtime berdasarkan status & info
      if (mcStatus == "alarm") dtCurrentCode = "Problem Mesin";
      else if (mcStatus == "off") dtCurrentCode = "Mesin Off";
      else {
        if (digitalRead(btnDandory) == LOW) dtCurrentCode = "Dandory";
        else if (digitalRead(btnMinum) == LOW) dtCurrentCode = "Minum";
        else dtCurrentCode = "SB";
      }
    } 
    else if (mcStatus == "run" && dtPreviousStatus != "run" && dtIsTracking) {
      unsigned long durasi = (currentMillis - dtStandbyStart) / 1000;
      if (durasi >= 5) { publishDowntime(dtCurrentCode, (int)durasi); }
      dtIsTracking = false;
    }
    // Update current code dynamically if buttons pressed during standby
    if (dtIsTracking && mcStatus == "standBy") {
      if (digitalRead(btnDandory) == LOW) dtCurrentCode = "Dandory";
      else if (digitalRead(btnMinum) == LOW) dtCurrentCode = "Minum";
    }
    
    dtPreviousStatus = mcStatus;

    // --- Publish Realtime Data tiap 2 Detik ---
    if (currentMillis - lastMsgTime > 2000) {
      lastMsgTime = currentMillis;
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

// ======================================================================
//                    CALLBACK MQTT (Terima Data Node-RED)
// ======================================================================
void callback(char* topic, byte* payload, unsigned int length) {
  String topicStr = String(topic);
  
  // Parse JSON payload
  StaticJsonDocument<512> doc;
  DeserializationError error = deserializeJson(doc, payload, length);
  if (error) {
    Serial.print("[MQTT] JSON Parse Error: ");
    Serial.println(error.c_str());
    return;
  }
  
  String status = doc["status"].as<String>();

  Serial.print("[MQTT] Topic: "); Serial.print(topicStr);
  Serial.print(" | Status: "); Serial.println(status);

  // -------------------- Reply Setup --------------------
  if (topicStr == "SMMS/Reply/Setup" && currentState == WAIT_SETUP_REPLY) {
    if (status == "registered") {
      mcID = doc["mcID"].as<String>();
      if (doc.containsKey("id_mesin") && !doc["id_mesin"].isNull()) {
        display_idMesin = doc["id_mesin"].as<String>();
      } else {
        display_idMesin = mcID;
      }
      preferences.putString("mcID", mcID);
      preferences.putString("idMesin", display_idMesin);
      showNonBlockingMsg("Berhasil Disimpan!", LOGIN_NIK, 500);
    } else {
      showNonBlockingMsg("ID Gagal Terdaftar!", SETUP_MODE, 1500);
    }
  }

  // -------------------- Reply Login --------------------
  else if (topicStr == "SMMS/Reply/Login" && currentState == WAIT_NIK_REPLY) {
    if (status == "success") {
      op_NIK   = inputBuffer;
      op_Name  = doc["nama"].as<String>();
      op_Level = doc["level"].as<int>();
      if (op_Level < 3) {
        tempMessage = "Skill tdk terpenuhi";
      }
    } else if (status == "skill_low") {
      op_Level = 0;
      tempMessage = "Skill tdk terpenuhi";
    } else {
      op_Level = 0;
      tempMessage = "NIK Tidak Terdaftar";
    }
    changeState(SKILL_CHECK);
  }

  // -------------------- Reply Proses --------------------
  else if (topicStr == "SMMS/Reply/Proses" && currentState == WAIT_PROCESS_REPLY) {
    if (status == "found") {
      kode_proses = inputBuffer;
      partID      = doc["nama_part"].as<String>();
      partNo      = doc["part_no"].as<String>();
      prosesDesc  = doc["nama_proses"].as<String>();

      // Tambah padding untuk scrolling jika panjang < 20
      while (partID.length() < 20)    partID    += " ";
      while (prosesDesc.length() < 20) prosesDesc += " ";

      changeState(CONFIRM_PROCESS);
    } else {
      showNonBlockingMsg("Kode Tidak Valid!", INPUT_PROCESS, 1500);
    }
  }
}

// ======================================================================
//                        MANAJEMEN STATE
// ======================================================================
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

  if (newState == BOOT) {
    lcd.clear();
    lcd.setCursor(4, 0);  lcd.print("SMART MACHINE");
    lcd.setCursor(2, 1);  lcd.print("MONITORING SYSTEM");
    lcd.setCursor(7, 3);  lcd.print("PT. CNC");
  }
  else if (newState == MAIN_SCREEN) {
    posisi1 = 0;
    posisi2 = 0;
    posisi3 = 0;
  }

  Serial.print("[STATE] -> "); Serial.println(newState);
}

// ======================================================================
//                  NON-BLOCKING MESSAGE DISPLAY
//  Menggantikan delay() di callback untuk tampilkan pesan singkat
// ======================================================================
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

// ======================================================================
//           LOGIKA TOMBOL NG+, NG-, DAN RESET (5 DETIK)
// ======================================================================
void readNGButtons() {
  unsigned long currentMillis = millis();

  // --- Tombol NG+ (Short Press -> Masuk input NG) ---
  bool currentNgAddState = digitalRead(ngAdd);
  if (lastNgAddState == HIGH && currentNgAddState == LOW) {
    if (currentMillis - lastNgAddDebounce > debounceMs) {
      lastNgAddDebounce = currentMillis;
      // Pindah ke state INPUT_NG_QTY (mode Tambah NG)
      isRepairMode = false;
      ngQtyInput   = 0;
      ngCodeInput  = "";
      changeState(INPUT_NG_QTY);
    }
  }
  lastNgAddState = currentNgAddState;

  // --- Tombol NG- (Short Press -> Repair, Tahan 5D -> Reset) ---
  int ngSubState = digitalRead(ngSub);
  if (ngSubState == LOW) { // Tombol sedang ditekan
    if (!ngSubPressed) {
      ngSubPressed   = true;
      ngSubPressTime = currentMillis;
    } else if (currentMillis - ngSubPressTime >= 5000) {
      // ===== RESET ALL COUNTER JIKA DITAHAN 5 DETIK =====
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
      lcd.setCursor(2, 1); lcd.print("COUNTER DIRESET!");
      lcd.setCursor(2, 2); lcd.print("Prod/NG/OK = 0");
      
      // Cegah trigger berulang selama tombol masih ditekan
      ngSubPressTime = currentMillis + 99999;
      
      // Tampilkan pesan non-blocking
      callbackMsgActive = true;
      callbackMsgTime   = millis() + 1500;
      callbackNextState = MAIN_SCREEN;
    }
  } else { // Tombol dilepas
    if (ngSubPressed) {
      unsigned long pressDuration = currentMillis - ngSubPressTime;
      // Jika dilepas sebelum 5 detik (short press) -> Repair Mode
      if (pressDuration < 5000 && pressDuration > debounceMs) {
        if (NGCount > 0) {
          isRepairMode = true;
          ngQtyInput   = 0;
          ngCodeInput  = "";
          changeState(INPUT_NG_QTY);
        } else {
          // Tidak ada NG untuk di-repair
          lcd.setCursor(0, 2); lcd.print("NG = 0, No Repair  ");
        }
      }
      ngSubPressed = false;
    }
  }
}

// ======================================================================
//              LOGIKA TOMBOL COUNTER PRODUKSI
// ======================================================================
void readProductionButtons() {
  unsigned long currentMillis = millis();

  // Tombol Count (btnCount pin 23)
  bool currentCountState = digitalRead(btnCount);
  if (lastCountState == HIGH && currentCountState == LOW) {
    if (currentMillis - lastCountDebounce > debounceMs) {
      lastCountDebounce = currentMillis;
      prodCount++;
      OKCount = prodCount - NGCount;
      forceUpdate = true;
    }
  }
  lastCountState = currentCountState;
}

// ======================================================================
//                    LOGIKA STATUS MESIN
// ======================================================================
void readMachineStatus() {
  if (digitalRead(mcON) == LOW) {
    mcStatus = "off";
    mcInfo   = "Mesin Off";
  }
  else if (digitalRead(alarmPin) == HIGH) {
    mcStatus = "alarm";
    mcInfo   = "Mesin Alarm";
  }
  else if (digitalRead(mcRun) == HIGH) {
    mcStatus = "run";
    mcInfo   = "Mesin Running";
  }
  else {
    mcStatus = "standBy";
    if (previousMcStatus != "standBy") {
      previousMcInfo = "Stand By";
    }

    String currentMcInfo = previousMcInfo;
    if (digitalRead(btnDandory) == LOW) currentMcInfo = "Dandory";
    else if (digitalRead(btnMinum) == LOW) currentMcInfo = "Minum";

    mcInfo = currentMcInfo;
    previousMcInfo = currentMcInfo;
  }
  previousMcStatus = mcStatus;
}

// ======================================================================
//                 FUNGSI LAYAR UTAMA (SCROLLING LCD)
// ======================================================================
void processMainScreen() {
  unsigned long currentMillis = millis();

  // ---- Baris 0: Part ID (scrolling jika > 20 char) ----
  if (partID.length() > 20) {
    if (currentMillis - previousMillis1 >= intervalScroll) {
      previousMillis1 = currentMillis;
      lcd.setCursor(0, 0); lcd.print("                    ");
      lcd.setCursor(0, 0); lcd.print(partID.substring(posisi1, min((int)(posisi1 + 20), (int)partID.length())));
      posisi1 += 5;
      if (posisi1 >= (int)partID.length() - 15) posisi1 = 0;
    }
  } else if (partID != lastPartID || forceUpdate) {
    lcd.setCursor(0, 0); lcd.print("                    ");
    lcd.setCursor(0, 0); lcd.print(partID);
    lastPartID = partID;
  }

  // ---- Baris 1: Proses Description (scrolling jika > 20 char) ----
  if (prosesDesc.length() > 20) {
    if (currentMillis - previousMillis2 >= intervalScroll) {
      previousMillis2 = currentMillis;
      lcd.setCursor(0, 1); lcd.print("                    ");
      lcd.setCursor(0, 1); lcd.print(prosesDesc.substring(posisi2, min((int)(posisi2 + 20), (int)prosesDesc.length())));
      posisi2 += 5;
      if (posisi2 >= (int)prosesDesc.length() - 15) posisi2 = 0;
    }
  } else if (prosesDesc != lastProsesDesc || forceUpdate) {
    lcd.setCursor(0, 1); lcd.print("                    ");
    lcd.setCursor(0, 1); lcd.print(prosesDesc);
    lastProsesDesc = prosesDesc;
  }

  // ---- Baris 2: Machine Info / Status (scrolling jika > 20 char) ----
  String displayInfo = mcInfo;
  while (displayInfo.length() < 20) displayInfo += " ";
  if (displayInfo.length() > 20) {
    if (currentMillis - previousMillis3 >= intervalScroll) {
      previousMillis3 = currentMillis;
      lcd.setCursor(0, 2); lcd.print("                    ");
      lcd.setCursor(0, 2); lcd.print(displayInfo.substring(posisi3, min((int)(posisi3 + 20), (int)displayInfo.length())));
      posisi3 += 5;
      if (posisi3 >= (int)displayInfo.length() - 15) posisi3 = 0;
    }
  } else if (displayInfo != lastmcInfo || forceUpdate) {
    lcd.setCursor(0, 2); lcd.print("                    ");
    lcd.setCursor(0, 2); lcd.print(displayInfo);
    lastmcInfo = displayInfo;
  }

  // ---- Baris 3: NIK | OKCount / NGCount ----
  if (op_NIK != lastop_NIK || forceUpdate) {
    lcd.setCursor(0, 3); lcd.print("         ");
    lcd.setCursor(0, 3); lcd.print(op_NIK);
    lastop_NIK = op_NIK;
  }

  OKCount = prodCount - NGCount;
  if (OKCount != lastOKCount || forceUpdate) {
    lcd.setCursor(9, 3); lcd.print("|      ");
    lcd.setCursor(11, 3); lcd.print(OKCount);
    lastOKCount = OKCount;
  }

  if (NGCount != lastNGCount || forceUpdate) {
    lcd.setCursor(16, 3); lcd.print("/   ");
    lcd.setCursor(17, 3); lcd.print(NGCount);
    lastNGCount = NGCount;
  }
}

// ======================================================================
//                   PUBLISH DOWNTIME DATA
// ======================================================================
void publishDowntime(String kode_dt, int durasi) {
  StaticJsonDocument<200> doc;
  doc["mcID"]          = display_idMesin;
  doc["kode_dt"]       = kode_dt;
  doc["durasi_detik"]  = durasi;

  char jsonStr[200];
  serializeJson(doc, jsonStr);
  client.publish("SMMS/Data/Downtime", jsonStr);

  Serial.print("[DT] Published: "); Serial.println(jsonStr);
}

// ======================================================================
//                     SETUP WiFi
// ======================================================================
void setup_wifi() {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Koneksi WiFi...     ");
  lcd.setCursor(0, 1); lcd.print("SSID: ");
  lcd.setCursor(0, 2); lcd.print(ssid);

  WiFi.begin(ssid, password);
  
  unsigned long wifiStart = millis();
  int dots = 0;
  while (WiFi.status() != WL_CONNECTED) {
    // Timeout 15 detik, restart WiFi
    if (millis() - wifiStart > 15000) {
      lcd.setCursor(0, 3); lcd.print("Retry...            ");
      WiFi.disconnect();
      delay(100);
      WiFi.begin(ssid, password);
      wifiStart = millis();
      dots = 0;
    }
    lcd.setCursor(dots % 20, 3); lcd.print(".");
    dots++;
    delay(500); // Acceptable blocking saat boot WiFi setup
  }
  
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("WiFi Terhubung!     ");
  lcd.setCursor(0, 1); lcd.print(WiFi.localIP().toString());
  delay(1000); // Brief display of IP for debugging
}

// ======================================================================
//                     RECONNECT MQTT
// ======================================================================
void reconnect() {
  String clientId = "ESP32_SMMS_" + String(random(0xffff), HEX);

  lcd.setCursor(0, 3); lcd.print("MQTT Connecting...  ");
  Serial.print("[MQTT] Connecting as "); Serial.println(clientId);

  if (client.connect(clientId.c_str())) {
    Serial.println("[MQTT] Connected!");
    lcd.setCursor(0, 3); lcd.print("MQTT OK!            ");

    // Subscribe ke semua Reply topics dari Node-RED
    client.subscribe("SMMS/Reply/Setup");
    client.subscribe("SMMS/Reply/Login");
    client.subscribe("SMMS/Reply/Proses");
  } else {
    Serial.print("[MQTT] Failed, rc=");
    Serial.println(client.state());
    lcd.setCursor(0, 3); lcd.print("MQTT Retry...       ");
  }
}
