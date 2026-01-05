#include <WiFi.h>
#include <HTTPClient.h>

// === KONFIG ===
const char* ssid      = "ccm-batam";        // ganti
const char* password  = "partY25time!";             // ganti
const char* serverUrl = "http://172.10.1.222/ProductionWebSystemV3/server/hit.php"; // ganti

const int  switchPin = 19;   // GPIO limit switch (ke GND)
const int  OP_ID     = 6;    // id operator utk board ini

// Tombol fisik reset (long-press)
const int resetPin = 18;             // tombol fisik reset -> ke GND
const uint32_t LONGPRESS_MS = 800;  // tahan 2 detik utk reset
uint32_t pressStart = 0;
bool resetLatch = false;

// Debounce & re-arm
const uint32_t DEBOUNCE_MS      = 100;  // tahan bouncing saat ditekan
const uint32_t REARM_MS         = 60;   // butuh HIGH stabil utk boleh hit lagi
const uint32_t SEND_INTERVAL_MS = 150;  // interval kirim akumulasi

volatile uint32_t hitCount   = 0;
volatile uint32_t lastEdgeMs = 0;
volatile bool     armed      = true;    // hanya hit kalau armed

// ==== PROTOTYPE ====
void sendHit(int operator_id, int amount);
void sendReset(int operator_id);

// ISR: increment sekali per tekan lalu disarm
void IRAM_ATTR onSwitchFall() {
  uint32_t now = millis();
  if (armed && (now - lastEdgeMs) > DEBOUNCE_MS) {
    hitCount++;
    armed      = false;         // kunci sampai tombol dilepas (HIGH)
    lastEdgeMs = now;
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(switchPin, INPUT_PULLUP);    // 1 kaki tombol ke GPIO, 1 ke GND
  pinMode(resetPin,  INPUT_PULLUP);    // 1 kaki tombol ke GPIO, 1 ke GND
  attachInterrupt(digitalPinToInterrupt(switchPin), onSwitchFall, FALLING);


  WiFi.persistent(false);
  WiFi.setSleep(false);
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);
  WiFi.setAutoReconnect(true);
  Serial.print("Connecting");
  int tries=0;
  while (WiFi.status()!=WL_CONNECTED && tries++<60) { delay(500); Serial.print("."); }
  Serial.println();
  if (WiFi.status()==WL_CONNECTED) {
    Serial.print("WiFi OK: "); Serial.println(WiFi.localIP());
  } else {
    Serial.println("WiFi failed.");
  }
}

void loop() {
  // --- Re-arm: hanya setelah tombol dilepas (HIGH) stabil beberapa ms ---
  static uint32_t highSince = 0;
  int s = digitalRead(switchPin);
  if (!armed) {
    if (s == HIGH) {
      if (highSince == 0) highSince = millis();
      if (millis() - highSince > REARM_MS) { armed = true; highSince = 0; }
    } else {
      highSince = 0; // masih LOW -> tunggu
    }
  }

  // --- Deteksi long-press untuk RESET (independen dari armed) ---
  int rs = digitalRead(resetPin);
  if (rs == LOW) {
    if (pressStart == 0) pressStart = millis();
    if (!resetLatch && millis() - pressStart > LONGPRESS_MS) {
      resetLatch = true;                     // cegah kirim berulang saat tetap ditekan
      sendReset(OP_ID);
      Serial.println("Reset requested");
    }
  } else {
    pressStart = 0;
    resetLatch = false;                      // siap deteksi long-press berikutnya
  }

  // --- Kirim akumulasi secara periodik ---
  static uint32_t lastSend = 0;
  if (millis() - lastSend >= SEND_INTERVAL_MS) {
    noInterrupts();
    uint32_t toSend = hitCount;   // snapshot
    hitCount = 0;                 // reset
    interrupts();

    if (toSend > 0) {
      sendHit(OP_ID, 1);
      Serial.printf("Sent amount=%u\n", (unsigned)toSend);
    }
    lastSend = millis();
  }
}

// ==== DEFINISI FUNGSI ====
void sendHit(int operator_id, int amount) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected.");
    return;
  }
  WiFiClient client;
  HTTPClient http;
  http.setTimeout(5000);
  http.begin(client, serverUrl);
  http.addHeader("Content-Type", "application/json");

  String json = String("{\"operator_id\":") + operator_id + ",\"amount\":" + amount + "}";
  int code = http.POST(json);
  Serial.printf("POST %d\n", code);
  if (code > 0) Serial.println(http.getString());
  http.end();
}

void sendReset(int operator_id) {
  if (WiFi.status() != WL_CONNECTED) { Serial.println("WiFi not connected."); return; }
  WiFiClient client; HTTPClient http; http.setTimeout(5000);
  String url = String(serverUrl); url.replace("/hit.php", "/device_qty_reset.php"); // endpoint reset
  http.begin(client, url);
  http.addHeader("Content-Type", "application/json");
  String json = String("{\"operator_id\":") + operator_id + ",\"note\":\"esp-longpress\"}";
  int code = http.POST(json);
  Serial.printf("RESET POST %d\n", code);
  if (code > 0) Serial.println(http.getString());
  http.end();
}
