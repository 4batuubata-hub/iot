#include <Adafruit_PN532.h>

Adafruit_PN532 nfc(255, &Serial2); 

void setup(void) {
  Serial.begin(115200);
  Serial.println("===============================");
  Serial.println("TEST PN532 MODE SERIAL (UART)");
  Serial.println("===============================");

  Serial2.begin(115200);
  nfc.begin();

  uint32_t versiondata = nfc.getFirmwareVersion();
  if (!versiondata) {
    Serial.println("PN532 tidak ditemukan!");
    Serial.println("Cek: TX modul -> RX19, RX modul -> TX18, DIP Switch 1=OFF 2=OFF");
    while (1); 
  }
  
  Serial.print("Chip PN5 Ditemukan! Seri: PN5"); Serial.println((versiondata>>24) & 0xFF, HEX); 
  nfc.SAMConfig();
  Serial.println("\nStatus: OK! Silakan tempelkan kartu NIK Anda...");
}

void loop(void) {
  uint8_t success;
  uint8_t uid[] = { 0, 0, 0, 0, 0, 0, 0 }; 
  uint8_t uidLength;                        
    
  success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 1000);
  
  if (success) {
    Serial.println("\n[+] KARTU TERDETEKSI!");
    Serial.print("    Nilai UID   : ");
    for (uint8_t i = 0; i < uidLength; i++) {
      Serial.print(" 0x");
      if (uid[i] <= 0x0F) Serial.print("0");
      Serial.print(uid[i], HEX);
    }
    Serial.println("");
    delay(1000); 
  }
}