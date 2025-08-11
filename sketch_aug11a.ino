// ESP32 — DHT11 (GPIO18) -> itzblaze.net/MedBot/receive.php
// Sends JSON (POST) every 5s, with GET fallback if POST fails.

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "DHT.h"

// ===== Wi-Fi =====
const char* WIFI_SSID = "Shourya";
const char* WIFI_PASS = "Abcdefgh";

// ===== Server / Auth =====
const char* POST_URL  = "https://itzblaze.net/MedBot/receive.php";
const char* API_KEY   = "akshobhyaishappyandjenilishappy";
const char* DEVICE_ID = "medbot-01";

// ===== DHT11 =====
#define DHTPIN  18
#define DHTTYPE DHT11
DHT dht(DHTPIN, DHTTYPE);

// ===== Cadence =====
const unsigned long SEND_MS = 5000;
unsigned long lastSend = 0;

// Optional: keep the last good reading so we can still send if one read fails
float lastT = NAN, lastH = NAN;

// -------- Helpers --------
static void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  for (int i=0; i<60 && WiFi.status()!=WL_CONNECTED; ++i){ delay(250); }
}

static bool readDHT11(float& tOut, float& hOut) {
  // up to 5 tries; clamp to DHT11 valid range
  for (int i=0;i<5;i++){
    float h = dht.readHumidity();
    float t = dht.readTemperature(); // °C
    if (!isnan(h) && !isnan(t) && t>=-40 && t<=85 && h>=0 && h<=100) {
      tOut = t; hOut = h; return true;
    }
    delay(60);
  }
  return false;
}

static bool sendReading(float t, float h, int &httpCodeOut, int &respLenOut) {
  httpCodeOut = -1; respLenOut = 0;

  HTTPClient http;
  WiFiClientSecure cli; cli.setInsecure(); // quick start; load a real CA for production
  if (!http.begin(cli, POST_URL)) return false;

  http.setTimeout(7000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body = String("{\"device\":\"")+DEVICE_ID+
                "\",\"t\":"+String(t,1)+
                ",\"h\":"+String(h,1)+
                ",\"rssi\":"+String(WiFi.RSSI())+"}";
  int code = http.POST(body);
  httpCodeOut = code;
  String resp = (code>0) ? http.getString() : String();
  respLenOut = resp.length();
  http.end();
  if (code == 200) return true;

  // Fallback: GET (works even if JSON POST blocked)
  HTTPClient http2;
  if (!http2.begin(cli, String(POST_URL)+
      "?api_key="+API_KEY+
      "&device="+DEVICE_ID+
      "&t="+String(t,1)+
      "&h="+String(h,1)+
      "&rssi="+String(WiFi.RSSI()))) return false;
  http2.setTimeout(7000);
  int code2 = http2.GET();
  httpCodeOut = code2;
  String resp2 = (code2>0) ? http2.getString() : String();
  respLenOut = resp2.length();
  http2.end();
  return code2 == 200;
}

// -------- Arduino --------
void setup() {
  Serial.begin(115200);
  dht.begin();
  connectWiFi();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) { connectWiFi(); delay(500); return; }

  if (millis() - lastSend >= SEND_MS) {
    lastSend = millis();

    float t, h;
    bool ok = readDHT11(t, h);
    if (ok) { lastT = t; lastH = h; }
    else if (!isnan(lastT) && !isnan(lastH)) { t = lastT; h = lastH; } // reuse last good
    else { return; } // nothing valid yet; skip this cycle

    int code=-1, len=0;
    bool sent = sendReading(t, h, code, len);
    // If you’re not watching Serial, ignore logs; server’s debug.log will show arrivals.
    Serial.printf("[SEND] T=%.1fC H=%.1f%% code=%d\n", t, h, code);
  }

  delay(10);
}
