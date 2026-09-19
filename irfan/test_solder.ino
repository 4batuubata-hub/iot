#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Keypad.h>

// Inisialisasi LCD I2C 20x4
LiquidCrystal_I2C lcd(0x27, 20, 4);

// Konfigurasi Keypad 4x4
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

// Struktur untuk Tombol
struct Button {
  int pin;
  const char* name;
  bool lastState;
};

// Daftar pin dari top3.ino
Button buttons[] = {
  {30, "CTRL", true},
  {4, "Prog +", true},
  {42, "Prog -", true},
  {25, "NGadd", true},
  {37, "NG-", true},
  {2, "TPM", true},
  {3, "Problem Qualitas", true},
  {7, "Toilet", true},
  {6, "5R", true}, 
  {5, "P5M", true},
  {38, "Sarana", true},
  {40, "Problem Insp Jig", true},
  {48, "Dandory", true},
  {46, "Minum", true},
  {44, "Sholat", true},
  {26, "Refill Material", true},
  {28, "Problem Jig Proses", true},
  {36, "Problem Mesin", true},
  {34, "QC Trial", true},
  {32, "Material Habis", true},
  {29, "Operator Izin", true},
  {27, "No Planning", true},
  {24, "Teaching", true},
  {22, "Perawatan", true},
  {23, "OJT", true},
  {41, "Tunggu Material", true},
  {39, "Eng Trial", true},
  {31, "Nozzle", true},
  {33, "Wire Las", true},
  {35, "Tambahan Proses", true}
};
const int numButtons = sizeof(buttons) / sizeof(buttons[0]);

void setup() {
  Serial.begin(9600);
  
  // Setup LCD
  lcd.init();
  lcd.backlight();
  
  // Tampilan Awal (Loading Test)
  lcd.setCursor(0, 0);
  lcd.print("Loading Test...");
  lcd.setCursor(0, 1);
  lcd.print("Sistem Pengecekan");
  lcd.setCursor(0, 2);
  lcd.print("Solder Hardware");
  delay(3000);
  
  // Setup Pin Tombol dengan Pull-up Internal
  for (int i = 0; i < numButtons; i++) {
    pinMode(buttons[i].pin, INPUT_PULLUP);
    buttons[i].lastState = digitalRead(buttons[i].pin);
  }
  
  // Tampilan Standby
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("--- TES HARDWARE ---");
  lcd.setCursor(0, 1);
  lcd.print("Tekan Tombol Apapun.");
}

void loop() {
  // --- CEK KEYPAD ---
  char key = keypad.getKey();
  
  if (key) {
    lcd.setCursor(0, 2);
    lcd.print("                    "); // Clear line
    lcd.setCursor(0, 2);
    lcd.print("Ditekan: ");
    lcd.print(key);
    
    Serial.print("Tombol Ditekan: ");
    Serial.println(key);
  } 
  
  // --- CEK TOMBOL FISIK ---
  for (int i = 0; i < numButtons; i++) {
    bool currentState = digitalRead(buttons[i].pin);
    
    // Jika terjadi perubahan state
    if (currentState != buttons[i].lastState) {
      delay(50); // Debounce sederhana
      currentState = digitalRead(buttons[i].pin);
      
      if (currentState != buttons[i].lastState) {
        // Asumsi tombol Active LOW (karena pakai INPUT_PULLUP)
        if (currentState == LOW) { 
          lcd.setCursor(0, 2);
          lcd.print("                    "); // Clear line
          lcd.setCursor(0, 2);
          lcd.print("Ditekan: ");
          lcd.print(buttons[i].name);
          
          Serial.print("Tombol Ditekan: ");
          Serial.println(buttons[i].name);
        }
        buttons[i].lastState = currentState;
      }
    }
  }
}
