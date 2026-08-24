/*
 * ============================================================
 *  SMART MACHINE MONITORING SYSTEM (SMMS) - PT. CNC
 *  ESP32 Firmware v2.0 - Full FSM, Non-Blocking, RFID Hold
 * ============================================================
 */

#include <WiFi.h>
#include <PubSubClient.h>
#include <Keypad.h>
#include <ArduinoJson.h>
#include <LiquidCrystal_I2C.h>
#include <Wire.h>
#include <Preferences.h>
#include <SPI.h>
#include <MFRC522.h>

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
// SPI PINS: SCK=18, MISO=19, MOSI=23.
#define SS_PIN     5      // Pin SDA RFID
#define RST_PIN    255    // Pin RST RFID (Tidak dipakai, bebaskan pin 13)
MFRC522 mfrc522(SS_PIN, RST_PIN);

#define interlockPin 27    // OUTPUT: Lampu Merah / Relay Interlock (HIGH = Kunci)

#define mcON       34      
#define mcRun      35      
#define alarmPin   39      
#define btnCount   32      
#define ngAdd      33      
#define ngSub      25      
    
#define btnDandory  2      
#define btnMinum   15      

// ================= KEYPAD 4x4 =================
const byte ROWS = 4;
const byte COLS = 4;
char keys[ROWS][COLS] = {
  {'1', '2', '3', 'A'},
  {'4', '5', '6', 'B'},
  {'7', '8', '9', 'C'},
  {'*', '0', '#', 'D'}
};
byte rowPins[ROWS] = {17, 16, 4, 12}; 
byte colPins[COLS] = {26, 0, 14, 13}; 
Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);

// ================= STATE MACHINE ENUM =================
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

// ================= VARIABEL GLOBAL =================
String mcID = "", display_idMesin = "", op_NIK = "", op_Name = "";
String current_uid = "";
int op_Level = 0;
String partID = "", partNo = "", prosesDesc = "", kode_proses = "";
String mcStatus = "off", mcInfo = "Mesin Off", previousMcStatus = "off", previousMcInfo = "Stand By";
int prodCount = 0, NGCount = 0, OKCount = 0;

bool isRepairMode = false;
int ngQtyInput = 0;
String ngCodeInput = "";
String localNgCodes[20];
String localNgProses[20];
unsigned int localNgQtys[20];
int activeNgCount = 0;

String inputBuffer = "", tempMessage = "";
unsigned long stateStartTime = 0, lastMsgTime = 0;
bool forceUpdate = true;

// Variabel RFID Hold
unsigned long lastRfidCheck = 0;
unsigned long lastCardDetectTime = 0;
bool rfidDetectedNow = false;

// Variabel Scrolling UI
unsigned long previousMillis1 = 0, previousMillis2 = 0, previousMillis3 = 0;
const unsigned long intervalScroll = 1500;
int posisi1 = 0, posisi2 = 0, posisi3 = 0;
String lastPartID = "", lastProsesDesc = "", lastmcInfo = "", lastop_NIK = "";
int lastOKCount = 9999, lastNGCount = 9999;

// Tombol & Downtime
unsigned long ngSubPressTime = 0, lastCountDebounce = 0, lastNgAddDebounce = 0;
bool ngSubPressed = false, lastCountState = HIGH, lastNgAddState = HIGH;
const unsigned long debounceMs = 50;
String dtPreviousStatus = "off", dtCurrentCode = "SB";
unsigned long dtStandbyStart = 0;
bool dtIsTracking = false;

// Non-blocking messages
unsigned long lastReconnectAttempt = 0, callbackMsgTime = 0;
bool callbackMsgActive = false;
String callbackMsgText = "";
SystemState callbackNextState = BOOT;

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
  Wire.begin(21, 22); // I2C LCD
  lcd.init();
  lcd.backlight();

  pinMode(interlockPin, OUTPUT);
  digitalWrite(interlockPin, HIGH); // KUNCI MESIN SAAT BOOT

  pinMode(mcON, INPUT); pinMode(mcRun, INPUT); pinMode(alarmPin, INPUT);
  pinMode(btnCount, INPUT_PULLUP); pinMode(ngAdd, INPUT_PULLUP); pinMode(ngSub, INPUT_PULLUP);
  pinMode(btnDandory, INPUT_PULLUP); pinMode(btnMinum, INPUT_PULLUP);

  SPI.begin();
  mfrc522.PCD_Init();

  setup_wifi();
  client.setServer("172.237.72.25", mqtt_port);
  client.setCallback(callback);
  client.setBufferSize(1024);

  preferences.begin("smms", false);
  mcID = preferences.getString("mcID", "");
  display_idMesin = preferences.getString("idMesin", mcID);

  changeState(BOOT);
}

// ======================================================================
//                           LOOP UTAMA
// ======================================================================
void loop() {
  unsigned long currentMillis = millis();

  if (!client.connected()) {
    if (currentMillis - lastReconnectAttempt > 10000) { 
        bool isTyping = ((currentState == SETUP_MODE || currentState == INPUT_PROCESS || currentState == INPUT_NG_QTY || currentState == INPUT_NG_CODE) && inputBuffer.length() > 0);
        if (!isTyping) {
            reconnect(); 
        }
        lastReconnectAttempt = millis(); 
    }
  } else {
    client.loop();
  }

  if (callbackMsgActive) { handleNonBlockingMsg(); return; }

  // --- LOGIKA CONTINUOUS RFID HOLD SYSTEM ---
  if (currentMillis - lastRfidCheck > 300) {
    lastRfidCheck = currentMillis;
    rfidDetectedNow = false;
    
    mfrc522.PCD_Init(); // Reset antena RFID agar mendeteksi kartu yang ditahan
    
    if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
        rfidDetectedNow = true;
        lastCardDetectTime = currentMillis; 
        
        String rfid_uid = "";
        for (byte i = 0; i < mfrc522.uid.size; i++) {
          rfid_uid += String(mfrc522.uid.uidByte[i] < 0x10 ? "0" : "");
          rfid_uid += String(mfrc522.uid.uidByte[i], HEX);
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
          client.publish("SMMS/Request/Login", jsonBuffer);
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
            client.publish("SMMS/Request/Login", jsonBuffer);
            
            changeState(WAIT_NIK_REPLY);
            lcd.clear(); lcd.setCursor(0, 1); lcd.print("Kartu Ditukar!      ");
            delay(800);
          }
        }
        mfrc522.PICC_HaltA(); 
    }
  }

  // --- LOGIKA KARTU DILEPAS ---
  if (current_uid != "") {
      if (currentMillis - lastCardDetectTime > 2000) {
          digitalWrite(interlockPin, HIGH); // KARTU DILEPAS -> INTERLOCK!
          op_NIK = "";
          current_uid = "";
          op_Level = 0;
          if (currentState == SKILL_CHECK) {
             // Berada di SKILL_CHECK dan kartu dilepas, jeda 2 detik sebelum input proses 
             // ditangani otomatis karena pindah ke INPUT_PROCESS.
             changeState(INPUT_PROCESS);
          } else {
             showNonBlockingMsg("Kartu Dilepas!", INPUT_PROCESS, 2000);
          }
      }
  }

  char key = keypad.getKey();

  if (key == '*' && currentState == BOOT) {
    changeState(SETUP_MODE);
    return;
  }

  // ======================== STATE MACHINE ========================
  switch (currentState) {

    case BOOT:
      if (currentMillis - stateStartTime > 3000) {
        if (mcID == "") changeState(SETUP_MODE);
        else changeState(INPUT_PROCESS);
      }
      break;

    case SETUP_MODE:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'B')) { inputBuffer += key; forceUpdate = true; } 
        else if (key == 'C') { if (mcID != "") changeState(LOGIN_NIK); else changeState(BOOT); } 
        else if (key == '#' && inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; } 
        else if (key == 'D' && inputBuffer.length() > 0) {
          if (!client.connected()) reconnect();
          StaticJsonDocument<200> doc; doc["mcID"] = inputBuffer; char jsonBuffer[200]; serializeJson(doc, jsonBuffer);
          if (client.publish("SMMS/Request/Setup", jsonBuffer)) changeState(WAIT_SETUP_REPLY);
          else showNonBlockingMsg("Koneksi Terputus!", SETUP_MODE, 2000);
        }
      }
      if (currentState != SETUP_MODE) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); lcd.print("Mode Setup Teknisi"); lcd.setCursor(0, 1); lcd.print("Masukkan ID Mesin:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(0, 3); lcd.print("D:OK  C:Batal");
        lcd.setCursor(inputBuffer.length(), 2); lcd.blink(); forceUpdate = false;
      }
      break;

    case WAIT_SETUP_REPLY: case WAIT_NIK_REPLY: case WAIT_PROCESS_REPLY:
      if (forceUpdate) { lcd.clear(); lcd.noBlink(); lcd.setCursor(4, 1); lcd.print("Memproses..."); forceUpdate = false; }
      if (millis() - stateStartTime > 10000) {
        if (currentState == WAIT_SETUP_REPLY) showNonBlockingMsg("Timeout! Coba lagi", SETUP_MODE, 2000);
        else if (currentState == WAIT_NIK_REPLY) showNonBlockingMsg("Timeout! Coba lagi", LOGIN_NIK, 2000);
        else showNonBlockingMsg("Timeout! Coba lagi", INPUT_PROCESS, 2000);
      }
      break;

    case LOGIN_NIK:
      if (forceUpdate) {
        digitalWrite(interlockPin, HIGH); // Interlock menyala
        lcd.clear(); lcd.noBlink();
        lcd.setCursor(0, 0); lcd.print("PT. CNC - " + display_idMesin);
        lcd.setCursor(0, 1); lcd.print("Letakkan Kartu NIK!");
        lcd.setCursor(0, 2); lcd.print("(Tahan dlm Scanner)");
        forceUpdate = false;
      }
      break;

    case SKILL_CHECK:
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink();
        if (op_Level >= 3) {
          lcd.setCursor(0, 0); lcd.print("Login Berhasil!");
          lcd.setCursor(0, 1); lcd.print(op_Name.substring(0, 20));
          lcd.setCursor(0, 2); lcd.print("Skill: OK");
          digitalWrite(interlockPin, LOW); // Interlock mati (Lulus)
        } else {
          lcd.setCursor(0, 0); lcd.print(tempMessage); // Skill tdk terpenuhi
          lcd.setCursor(0, 1); lcd.print("Level: " + String(op_Level));
          lcd.setCursor(0, 3); lcd.print("Mesin Dikunci!");
          digitalWrite(interlockPin, HIGH); // Interlock menyala (Gagal)
        }
        forceUpdate = false;
      }
      
      if (op_Level >= 3 && currentMillis - stateStartTime > 1500) {
        changeState(MAIN_SCREEN);
      }
      break;

    case INPUT_PROCESS:
      if (key) {
        if ((key >= '0' && key <= '9') || (key >= 'A' && key <= 'C')) { inputBuffer += key; forceUpdate = true; } 
        else if (key == '#' && inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; } 
        else if (key == 'D' && inputBuffer.length() > 0) {
          if (!client.connected()) reconnect();
          StaticJsonDocument<200> doc; doc["kode_proses"] = inputBuffer; char jsonBuffer[200]; serializeJson(doc, jsonBuffer);
          if (client.publish("SMMS/Request/Proses", jsonBuffer)) changeState(WAIT_PROCESS_REPLY);
          else showNonBlockingMsg("Koneksi Terputus!", INPUT_PROCESS, 2000);
        }
      }
      if (currentState != INPUT_PROCESS) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); 
        if (op_NIK == "") lcd.print("ID: " + display_idMesin.substring(0, 16));
        else lcd.print("Operator: " + op_NIK.substring(0, 10));
        lcd.setCursor(0, 1); lcd.print("Masukkan Kd Proses:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        forceUpdate = false;
      }
      break;

    case CONFIRM_PROCESS:
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink(); lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20)); lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print("D:Lanjut  C:Batal"); forceUpdate = false;
      }
      if (key == 'D') changeState(LOGIN_NIK); else if (key == 'C') changeState(INPUT_PROCESS);
      break;

    case MAIN_SCREEN:
      if (forceUpdate) { lcd.clear(); lcd.noBlink(); lastPartID = ""; lastProsesDesc = ""; lastmcInfo = ""; lastop_NIK = ""; lastOKCount = 9999; lastNGCount = 9999; forceUpdate = false; }
      if (key == 'A') { 
        digitalWrite(interlockPin, HIGH); 
        op_NIK = ""; 
        current_uid = ""; 
        changeState(INPUT_PROCESS); // Logout dan kembali ke input proses
      } 
      else if (key == 'B') changeState(INPUT_PROCESS); 
      else if (key == '*') changeState(INFO_SCREEN);   
      else if (key == '#' && !client.connected()) { lcd.clear(); lcd.setCursor(0, 1); lcd.print("Manual Reconnect..."); reconnect(); lastReconnectAttempt = millis(); forceUpdate = true; }
      processMainScreen();
      break;

    case INFO_SCREEN:
      if (forceUpdate) {
        lcd.clear(); lcd.noBlink(); lcd.setCursor(0, 0); lcd.print(partID.substring(0, 20));
        lcd.setCursor(0, 1); lcd.print(partNo.substring(0, 20)); lcd.setCursor(0, 2); lcd.print(prosesDesc.substring(0, 20));
        lcd.setCursor(0, 3); lcd.print("ID: " + display_idMesin.substring(0, 16)); forceUpdate = false;
      }
      if (key == '*') changeState(MAIN_SCREEN);
      break;

    case INPUT_NG_QTY:
      if (key) {
        if (key >= '0' && key <= '9') {
           inputBuffer += key; forceUpdate = true;
        } else if (key == '#') {
           if (inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; }
        } else if (key == 'C') {
           changeState(MAIN_SCREEN);
        } else if (key == 'D') {
           if (inputBuffer.length() > 0) {
              ngQtyInput = inputBuffer.toInt();
              if (ngQtyInput > 0) {
                 if (isRepairMode && ngQtyInput > (int)NGCount) {
                    showNonBlockingMsg("Melebihi NG Count!", INPUT_NG_QTY, 1500);
                    inputBuffer = "";
                 } else {
                    inputBuffer = "";
                    changeState(INPUT_NG_CODE);
                 }
              }
           }
        }
      }
      if (currentState != INPUT_NG_QTY) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); lcd.print(isRepairMode ? "MODE REPAIR (NG-)" : "MODE TAMBAH (NG+)");
        lcd.setCursor(0, 1); lcd.print("Masukkan QTY:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        lcd.setCursor(0, 3); lcd.print("D:OK C:Batal #:Hps"); forceUpdate = false;
      }
      break;

    case INPUT_NG_CODE:
      if (key) {
        if ((key >= '0' && key <= '9') || key == 'A' || key == 'B') {
           inputBuffer += key; forceUpdate = true;
        } else if (key == '#') {
           if (inputBuffer.length() > 0) { inputBuffer.remove(inputBuffer.length() - 1); forceUpdate = true; }
        } else if (key == 'C') {
           inputBuffer = ""; changeState(INPUT_NG_QTY);
        } else if (key == 'D') {
           if (inputBuffer.length() > 0) {
              ngCodeInput = inputBuffer;

              if (!isRepairMode) {
                if ((NGCount + ngQtyInput) > prodCount) {
                  showNonBlockingMsg("NG > Produksi!", INPUT_NG_QTY, 1500);
                  inputBuffer = "";
                  return;
                }
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
                  inputBuffer = "";
                  return;
                }
                localNgQtys[foundIndex] -= ngQtyInput;
                NGCount -= ngQtyInput;
              }
              OKCount = prodCount - NGCount;

              if (!client.connected()) reconnect();
              StaticJsonDocument<200> doc;
              doc["mcID"] = display_idMesin;
              doc["kode_proses"] = kode_proses;
              doc["kode_ng"] = ngCodeInput;
              if (isRepairMode) {
                doc["qty_ng"]   = -(ngQtyInput);
                doc["kategori"] = "Repair NG";
              } else {
                doc["qty_ng"]   = ngQtyInput;
                doc["kategori"] = "Tambah NG";
              }
              char jsonBuffer[200]; serializeJson(doc, jsonBuffer);
              if (client.publish("SMMS/Data/NG", jsonBuffer)) {
                  showNonBlockingMsg(isRepairMode ? "Repair OK!" : "Data NG Tersimpan!", MAIN_SCREEN, 1500);
              } else {
                  showNonBlockingMsg("Koneksi Terputus!", INPUT_NG_CODE, 2000);
              }
           }
        }
      }
      if (currentState != INPUT_NG_CODE) break;
      if (forceUpdate) {
        lcd.clear(); lcd.setCursor(0, 0); lcd.print(isRepairMode ? "MODE REPAIR (NG-)" : "MODE TAMBAH (NG+)");
        lcd.setCursor(0, 1); lcd.print("Masukkan Kode NG:");
        lcd.setCursor(0, 2); lcd.print(inputBuffer); lcd.setCursor(inputBuffer.length(), 2); lcd.blink();
        lcd.setCursor(0, 3); lcd.print("D:OK C:Batal #:Hps"); forceUpdate = false;
      }
      break;
  }

  // --- BACKGROUND TASKS PROSES MESIN ---
  if (currentState >= MAIN_SCREEN) {
    readMachineStatus(); readProductionButtons(); readNGButtons();

    if (mcStatus != "run" && dtPreviousStatus == "run") {
      dtStandbyStart = currentMillis; dtIsTracking = true;
      if (mcStatus == "alarm") dtCurrentCode = "Problem Mesin"; else if (mcStatus == "off") dtCurrentCode = "Mesin Off"; else { if (digitalRead(btnDandory) == LOW) dtCurrentCode = "Dandory"; else if (digitalRead(btnMinum) == LOW) dtCurrentCode = "Minum"; else dtCurrentCode = "SB"; }
    } else if (mcStatus == "run" && dtPreviousStatus != "run" && dtIsTracking) {
      unsigned long durasi = (currentMillis - dtStandbyStart) / 1000;
      if (durasi >= 5) publishDowntime(dtCurrentCode, (int)durasi);
      dtIsTracking = false;
    }
    if (dtIsTracking && mcStatus == "standBy") { if (digitalRead(btnDandory) == LOW) dtCurrentCode = "Dandory"; else if (digitalRead(btnMinum) == LOW) dtCurrentCode = "Minum"; }
    dtPreviousStatus = mcStatus;

    if (currentMillis - lastMsgTime > 2000) {
      lastMsgTime = currentMillis;
      StaticJsonDocument<512> doc; doc["mcID"] = display_idMesin; doc["op_NIK"] = op_NIK; doc["partID"] = partID; doc["kode_proses"] = kode_proses; doc["mcStatus"] = mcStatus; doc["mcInfo"] = mcInfo; doc["prodCount"] = prodCount; doc["OKCount"] = OKCount; doc["NGCount"] = NGCount;
      char jsonStr[512]; serializeJson(doc, jsonStr); client.publish("SMMS/Data/Produksi", jsonStr);
    }
  }
}

// ======================================================================
//                    CALLBACK MQTT (Terima Data Node-RED)
// ======================================================================
void callback(char* topic, byte* payload, unsigned int length) {
  String topicStr = String(topic);
  StaticJsonDocument<512> doc;
  DeserializationError error = deserializeJson(doc, payload, length);
  if (error) return;
  String status = doc["status"].as<String>();

  if (topicStr == "SMMS/Reply/Setup" && currentState == WAIT_SETUP_REPLY) {
    if (status == "registered") {
      mcID = doc["mcID"].as<String>();
      if (doc.containsKey("id_mesin") && !doc["id_mesin"].isNull()) display_idMesin = doc["id_mesin"].as<String>(); else display_idMesin = mcID;
      preferences.putString("mcID", mcID); preferences.putString("idMesin", display_idMesin);
      showNonBlockingMsg("Berhasil Disimpan!", INPUT_PROCESS, 500);
    } else showNonBlockingMsg("ID Gagal Terdaftar!", SETUP_MODE, 1500);
  }
  else if (topicStr == "SMMS/Reply/Login" && currentState == WAIT_NIK_REPLY) {
    if (status == "success") {
      op_NIK   = doc["nik_asli"].as<String>();
      op_Name  = doc["nama"].as<String>();
      op_Level = doc["level"].as<int>();
      if (op_Level < 3) tempMessage = "Skill tdk terpenuhi";
    } else if (status == "skill_low") {
      op_Level = 0; tempMessage = "Skill tdk terpenuhi";
    } else {
      op_Level = 0; tempMessage = "Skill tdk terpenuhi"; // Sesuai permintaan jika gagal
    }
    changeState(SKILL_CHECK);
  }
  else if (topicStr == "SMMS/Reply/Proses" && currentState == WAIT_PROCESS_REPLY) {
    if (status == "found") {
      kode_proses = inputBuffer; partID = doc["nama_part"].as<String>(); partNo = doc["part_no"].as<String>(); prosesDesc = doc["nama_proses"].as<String>();
      while (partID.length() < 20) partID += " "; while (prosesDesc.length() < 20) prosesDesc += " ";
      changeState(CONFIRM_PROCESS);
    } else showNonBlockingMsg("Kode Tidak Valid!", INPUT_PROCESS, 1500);
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

  if (newState != WAIT_SETUP_REPLY && newState != WAIT_NIK_REPLY && newState != WAIT_PROCESS_REPLY) {
    inputBuffer = "";
  }

  if (newState == BOOT) {
    lcd.clear();
    lcd.setCursor(4, 0);  lcd.print("SMART MACHINE");
    lcd.setCursor(2, 1);  lcd.print("MONITORING SYSTEM");
    lcd.setCursor(7, 3);  lcd.print("PT. CNC");
  }
  else if (newState == MAIN_SCREEN) {
    posisi1 = 0; posisi2 = 0; posisi3 = 0;
  }
  Serial.print("[STATE] -> "); Serial.println(newState);
}

// ======================================================================
//                  NON-BLOCKING MESSAGE DISPLAY
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
//           LOGIKA TOMBOL NG+, NG-, DAN RESET
// ======================================================================
void readNGButtons() {
  unsigned long currentMillis = millis();
  bool currentNgAddState = digitalRead(ngAdd);
  if (lastNgAddState == HIGH && currentNgAddState == LOW) {
    if (currentMillis - lastNgAddDebounce > debounceMs) {
      lastNgAddDebounce = currentMillis;
      isRepairMode = false; ngQtyInput = 0; ngCodeInput = ""; changeState(INPUT_NG_QTY);
    }
  }
  lastNgAddState = currentNgAddState;

  int ngSubState = digitalRead(ngSub);
  if (ngSubState == LOW) { 
    if (!ngSubPressed) { ngSubPressed = true; ngSubPressTime = currentMillis; } 
    else if (currentMillis - ngSubPressTime >= 3000) {
      prodCount = 0; NGCount = 0; OKCount = 0;
      for (int i = 0; i < 20; i++) { localNgCodes[i] = ""; localNgQtys[i] = 0; } activeNgCount = 0;
      lcd.clear(); lcd.setCursor(2, 1); lcd.print("COUNTER DIRESET!"); lcd.setCursor(2, 2); lcd.print("Prod/NG/OK = 0");
      ngSubPressTime = currentMillis + 99999;
      callbackMsgActive = true; callbackMsgTime = millis() + 1500; callbackNextState = MAIN_SCREEN;
    }
  } else { 
    if (ngSubPressed) {
      unsigned long pressDuration = currentMillis - ngSubPressTime;
      if (pressDuration < 5000 && pressDuration > debounceMs) {
        if (NGCount > 0) { isRepairMode = true; ngQtyInput = 0; ngCodeInput = ""; changeState(INPUT_NG_QTY); } 
        else { lcd.setCursor(0, 2); lcd.print("NG = 0, No Repair  "); }
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
  bool currentCountState = digitalRead(btnCount);
  if (lastCountState == HIGH && currentCountState == LOW) {
    if (currentMillis - lastCountDebounce > debounceMs) {
      lastCountDebounce = currentMillis; prodCount++; OKCount = prodCount - NGCount; forceUpdate = true;
    }
  }
  lastCountState = currentCountState;
}

// ======================================================================
//                    LOGIKA STATUS MESIN
// ======================================================================
void readMachineStatus() {
  if (digitalRead(mcON) == LOW) { mcStatus = "off"; mcInfo = "Mesin Off"; }
  else if (digitalRead(alarmPin) == HIGH) { mcStatus = "alarm"; mcInfo = "Mesin Alarm"; }
  else if (digitalRead(mcRun) == HIGH) { mcStatus = "run"; mcInfo = "Mesin Running"; }
  else {
    mcStatus = "standBy";
    if (previousMcStatus != "standBy") previousMcInfo = "Stand By";
    String currentMcInfo = previousMcInfo;
    if (digitalRead(btnDandory) == LOW) currentMcInfo = "Dandory";
    else if (digitalRead(btnMinum) == LOW) currentMcInfo = "Minum";
    mcInfo = currentMcInfo; previousMcInfo = currentMcInfo;
  }
  previousMcStatus = mcStatus;
}

// ======================================================================
//                 FUNGSI LAYAR UTAMA (SCROLLING LCD)
// ======================================================================
void processMainScreen() {
  unsigned long currentMillis = millis();
  if (partID.length() > 20) {
    if (currentMillis - previousMillis1 >= intervalScroll) {
      previousMillis1 = currentMillis; lcd.setCursor(0, 0); lcd.print("                    ");
      lcd.setCursor(0, 0); lcd.print(partID.substring(posisi1, min((int)(posisi1 + 20), (int)partID.length())));
      posisi1 += 5; if (posisi1 >= (int)partID.length() - 15) posisi1 = 0;
    }
  } else if (partID != lastPartID || forceUpdate) { lcd.setCursor(0, 0); lcd.print("                    "); lcd.setCursor(0, 0); lcd.print(partID); lastPartID = partID; }

  if (prosesDesc.length() > 20) {
    if (currentMillis - previousMillis2 >= intervalScroll) {
      previousMillis2 = currentMillis; lcd.setCursor(0, 1); lcd.print("                    ");
      lcd.setCursor(0, 1); lcd.print(prosesDesc.substring(posisi2, min((int)(posisi2 + 20), (int)prosesDesc.length())));
      posisi2 += 5; if (posisi2 >= (int)prosesDesc.length() - 15) posisi2 = 0;
    }
  } else if (prosesDesc != lastProsesDesc || forceUpdate) { lcd.setCursor(0, 1); lcd.print("                    "); lcd.setCursor(0, 1); lcd.print(prosesDesc); lastProsesDesc = prosesDesc; }

  String displayInfo = mcInfo; while (displayInfo.length() < 20) displayInfo += " ";
  if (displayInfo.length() > 20) {
    if (currentMillis - previousMillis3 >= intervalScroll) {
      previousMillis3 = currentMillis; lcd.setCursor(0, 2); lcd.print("                    ");
      lcd.setCursor(0, 2); lcd.print(displayInfo.substring(posisi3, min((int)(posisi3 + 20), (int)displayInfo.length())));
      posisi3 += 5; if (posisi3 >= (int)displayInfo.length() - 15) posisi3 = 0;
    }
  } else if (displayInfo != lastmcInfo || forceUpdate) { lcd.setCursor(0, 2); lcd.print("                    "); lcd.setCursor(0, 2); lcd.print(displayInfo); lastmcInfo = displayInfo; }

  if (op_NIK != lastop_NIK || forceUpdate) { lcd.setCursor(0, 3); lcd.print("         "); lcd.setCursor(0, 3); lcd.print(op_NIK); lastop_NIK = op_NIK; }
  OKCount = prodCount - NGCount;
  if (OKCount != lastOKCount || forceUpdate) { lcd.setCursor(9, 3); lcd.print("|      "); lcd.setCursor(11, 3); lcd.print(OKCount); lastOKCount = OKCount; }
  if (NGCount != lastNGCount || forceUpdate) { lcd.setCursor(16, 3); lcd.print("/   "); lcd.setCursor(17, 3); lcd.print(NGCount); lastNGCount = NGCount; }
}

// ======================================================================
//                   PUBLISH DOWNTIME DATA
// ======================================================================
void publishDowntime(String kode_dt, int durasi) {
  StaticJsonDocument<200> doc; doc["mcID"] = display_idMesin; doc["kode_dt"] = kode_dt; doc["durasi_detik"] = durasi;
  char jsonStr[200]; serializeJson(doc, jsonStr); client.publish("SMMS/Data/Downtime", jsonStr);
}

// ======================================================================
//                     SETUP WiFi & RECONNECT MQTT
// ======================================================================
void setup_wifi() {
  lcd.clear(); lcd.setCursor(0, 0); lcd.print("Koneksi WiFi...     "); lcd.setCursor(0, 1); lcd.print("SSID: "); lcd.setCursor(0, 2); lcd.print(ssid);
  WiFi.begin(ssid, password);
  unsigned long wifiStart = millis(); int dots = 0;
  while (WiFi.status() != WL_CONNECTED) {
    if (millis() - wifiStart > 15000) {
      lcd.setCursor(0, 3); lcd.print("Retry...            "); WiFi.disconnect(); delay(100); WiFi.begin(ssid, password); wifiStart = millis(); dots = 0;
    }
    lcd.setCursor(dots % 20, 3); lcd.print("."); dots++; delay(500);
  }
  lcd.clear(); lcd.setCursor(0, 0); lcd.print("WiFi Terhubung!     "); lcd.setCursor(0, 1); lcd.print(WiFi.localIP().toString()); delay(1000);
}

void reconnect() {
  String clientId = "ESP32_SMMS_" + String(random(0xffff), HEX);
  lcd.setCursor(0, 3); lcd.print("MQTT Connecting...  ");
  if (client.connect(clientId.c_str())) {
    lcd.setCursor(0, 3); lcd.print("MQTT OK!            ");
    client.subscribe("SMMS/Reply/Setup"); client.subscribe("SMMS/Reply/Login"); client.subscribe("SMMS/Reply/Proses");
  } else {
    lcd.setCursor(0, 3); lcd.print("MQTT Retry...       ");
  }
  delay(500);
  forceUpdate = true;
}
