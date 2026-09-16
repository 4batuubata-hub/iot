#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// Inisialisasi LCD 20x4 di alamat 0x27
LiquidCrystal_I2C lcd(0x27, 20, 4);

// Daftar 25 Pin Tombol Downtime + Tombol Navigasi/NG (Total 30 tombol fisik)
const int buttonPins[] = {
  // Tombol Downtime (Kuning)
  2, 3, 7, 6, 5, 
  38, 40, 48, 46, 44, 
  26, 28, 36, 34, 32, 
  29, 27, 24, 22, 23, 
  41, 39, 31, 33, 35, // Pin 35 adalah Tambahan Proses
  // Tombol Navigasi (Biru)
  30, 4, 42,
  // Tombol NG (Merah/Pink)
  25, 37
};

const int numButtons = sizeof(buttonPins) / sizeof(buttonPins[0]);

void setup() {
  Serial.begin(9600);
  
  // Setup LCD
  Wire.begin();
  lcd.init();
  lcd.backlight();
  
  lcd.setCursor(0, 0);
  lcd.print("== DEBUG TOMBOL ==");
  lcd.setCursor(0, 1);
  lcd.print("Silakan tekan tombol");

  // Setup HANYA pin tombol sebagai INPUT_PULLUP
  // Ini menghindari kita membaca "noise" dari pin Ethernet (50-53) & Keypad
  for (int i = 0; i < numButtons; i++) {
    pinMode(buttonPins[i], INPUT_PULLUP);
  }
}

void loop() {
  int pressedPin = -1;
  int countPressed = 0;
  
  // Baca hanya pin-pin tombol kita
  for (int i = 0; i < numButtons; i++) {
    int pin = buttonPins[i];
    if (digitalRead(pin) == LOW) { // Tombol ditekan (karena pakai pullup)
      pressedPin = pin;
      countPressed++;
      
      // Print ke serial jika terhubung ke laptop
      Serial.print("Tombol Ditekan: PIN ");
      Serial.println(pin);
    }
  }

  // Update Tampilan LCD
  lcd.setCursor(0, 2);
  if (countPressed > 0) {
    lcd.print("PIN DITEKAN: ");
    if(pressedPin < 10) lcd.print("0");
    lcd.print(pressedPin);
    lcd.print("      "); // Menimpa sisa karakter lama
    
    lcd.setCursor(0, 3);
    lcd.print("Jml yg ditekan: ");
    lcd.print(countPressed);
    lcd.print("  ");
  } else {
    lcd.print("Menunggu Input...   ");
    lcd.setCursor(0, 3);
    lcd.print("                    ");
  }

  delay(200); // Delay debounce sederhana
}
