#include <Wire.h>

void setup() {
  Wire.begin();
  Serial.begin(9600);
}

void loop() {
  unsigned int count = readCounterFromNano();
  Serial.print("Counter dari Nano: ");
  Serial.println(count);
  delay(1000); // Tunggu 1 detik sebelum request lagi
}

// Fungsi untuk membaca counter dari Arduino Nano
unsigned int readCounterFromNano() {
  Wire.requestFrom(8, 2); // Minta 2 byte dari Arduino Nano dengan alamat 8
  if (Wire.available() == 2) { // Pastikan menerima 2 byte
    return (Wire.read() << 8) | Wire.read(); // Gabungkan byte tinggi dan rendah
  } else {
    Serial.println("Data tidak tersedia");
    return 0; // Jika gagal membaca, kembalikan 0
  }
}
