#include <Wire.h>

const int pinSwitch = 2;  // Pin counter dari mesin
volatile unsigned int switchCount = 0; 

// Variabel untuk Digital Low-Pass Filter (Polling Debounce)
int lastButtonState = HIGH;   // Status pembacaan mentah sebelumnya
int buttonState = HIGH;       // Status valid (sudah disaring)
unsigned long lastDebounceTime = 0; 
const unsigned long debounceDelay = 50; // Waktu wajib stabil (50ms)

void sendData() {
  Wire.write((byte)(switchCount >> 8));
  Wire.write((byte)(switchCount & 0xFF));
}

void receiveEvent(int byteCount) {
  byte cmd = 0;
  while (Wire.available()) {
    cmd = Wire.read();
  }
  if (cmd == 0xAA) {
    switchCount = 0;  // Reset hanya jika perintah eksplisit dari Mega
  }
}

void setup() {
  Wire.begin(8); // Inisialisasi sebagai Slave di alamat 0x08
  Wire.onRequest(sendData);     
  Wire.onReceive(receiveEvent); 
  
  Serial.begin(9600);
  Serial.println("Nano Counter (Filter Digital) Started...");
  
  pinMode(pinSwitch, INPUT_PULLUP);
  
  // INTERRUPT DIMATIKAN!
  // Karena interrupt terlalu sensitif terhadap noise AC 50Hz (kabel floating).
  // Sebagai gantinya, kita gunakan sistem Polling + Filter Digital di loop().
}

void loop() {
  // =================================================================
  // DIGITAL LOW-PASS FILTER (MENYARING NOISE AC & EFEK ANTENA)
  // =================================================================
  int reading = digitalRead(pinSwitch);

  // Jika ada perubahan sekecil apapun (karena noise atau ditekan)
  if (reading != lastButtonState) {
    lastDebounceTime = millis(); // Reset timer
  }

  // Jika sinyal BERHASIL stabil melebihi batas waktu (50ms)
  // Ini berarti bukan noise AC, karena noise AC selalu bergetar tiap 20ms
  if ((millis() - lastDebounceTime) > debounceDelay) {
    
    // Jika status stabil yang baru berbeda dengan status yang disetujui sebelumnya
    if (reading != buttonState) {
      buttonState = reading; // Update status resmi
      
      // Jika status resmi yang baru adalah LOW (relay benar-benar tertutup rapat)
      if (buttonState == LOW) {
        switchCount++;
      }
    }
  }
  lastButtonState = reading; // Simpan untuk putaran berikutnya


  // =================================================================
  // TAMPILKAN KE SERIAL MONITOR
  // =================================================================
  static unsigned int lastPrintedCount = 0;
  if (switchCount != lastPrintedCount) {
    Serial.print("Count: ");
    Serial.println(switchCount);
    lastPrintedCount = switchCount;
  }
  
  // =================================================================
  // I2C AUTO-RECOVERY (ANTI-RFID EMI)
  // =================================================================
  static unsigned long lastI2cReset = 0;
  if (millis() - lastI2cReset > 2000) {
    lastI2cReset = millis();
    Wire.end();
    Wire.begin(8);
    Wire.onRequest(sendData);
    Wire.onReceive(receiveEvent);
  }

  // PENTING: Tidak boleh ada delay() panjang di loop() ini
  // Agar proses digitalRead() dapat merespon secepat kilat menyaring noise.
}
