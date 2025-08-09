/*
  ESP32 MedBot — Line Follower + Telemetry + Web-Mode Control (pinMode + analogWrite)
  - DHT11 on GPIO18
  - IR sensors: Left=GPIO22, Right=GPIO23 (digital DO)
  - L293D: ENA=33 IN1=25 IN2=26, ENB=32 IN3=27 IN4=14  (two motors in parallel per side)
  - Telemetry → https://itzblaze.net/MedBot/receive.php
  - Mode/reset pulled from https://itzblaze.net/MedBot/state.php
  - Brand stamps: "made by itzblaze.net auth"
*/

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "DHT.h"
#include <time.h>

// ===== Brand =====
const char* BRAND = "made by itzblaze.net auth";
static void brandStamp(const char* where) { Serial.printf("[BRAND] %s — %s\n", BRAND, where); }

// ===== WIFI =====
const char* WIFI_SSID = "auth";
const char* WIFI_PASS = "PASSWORD";

// ===== SERVER / AUTH =====
const char* POST_URL   = "https://itzblaze.net/MedBot/receive.php";
const char* STATE_URL  = "https://itzblaze.net/MedBot/state.php";
const char* API_KEY    = "SECRET_KEY";
const char* DEVICE_ID  = "medbot-01";

// 0 = HTTP (testing), 1 = HTTPS (uses setInsecure())
#define USE_HTTPS 1

// ===== DHT11 =====
#define DHTPIN 18
#define DHTTYPE DHT11
DHT dht(DHTPIN, DHTTYPE);

// ===== L293D pins =====
#define ENA 33
#define IN1 25
#define IN2 26
#define ENB 32
#define IN3 27
#define IN4 14

// ===== IR sensors =====
#define SEN_L 22
#define SEN_R 23
#define LINE_ON_BLACK 1 // most LM393 boards output LOW on black

// ===== Speeds (0..255 for analogWrite) =====
int BASE_SPEED    = 170;
int CORRECT_DELTA = 60;
int SEARCH_SPEED  = 150;

// ===== Finish-line detection =====
const uint32_t FINISH_BLACK_MS = 800;
const uint32_t FINISH_CLEAR_MS = 200;
bool finished = false;
bool finishArmed = false;
uint32_t bothBlackStart = 0;
uint32_t bothWhiteStart = 0;

// ===== Mode (from website) =====
bool followEnabled = true;

// ===== Telemetry cadence =====
const unsigned long SEND_MS = 5000;
unsigned long lastSend = 0;

// ===== Wi-Fi maintenance =====
unsigned long lastWifiAttempt = 0;
bool wifiWasConnected = false;

// ===== State poll cadence =====
unsigned long lastStatePoll = 0;
const unsigned long STATE_MS = 1200;

// ===== Drive state =====
enum Dir { LEFT, RIGHT };
Dir lastDir = LEFT;
enum DriveState { DS_INIT, DS_BOTH, DS_LEFT, DS_RIGHT, DS_LOST, DS_FINISHED, DS_IDLE };
DriveState driveState = DS_INIT;

// ---- Wi-Fi helpers ----
static void beginWiFiIfNeeded() {
  if (WiFi.status() == WL_CONNECTED) return;
  unsigned long now = millis();
  if (now - lastWifiAttempt < 5000) return;
  lastWifiAttempt = now;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.printf("[WIFI] Connecting to %s...\n", WIFI_SSID);
  brandStamp("Wi-Fi connect attempt");
}
static void syncTimeOnce() {
  static bool done=false; if(done) return;
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");
  for (int i=0;i<20;i++){ time_t n=time(nullptr); if(n>1700000000){done=true;break;} delay(100); }
}
static String iso8601utc() {
  time_t n=time(nullptr);
  if (n<=1700000000){ char buf[40]; snprintf(buf,sizeof(buf),"uptime:%lu.ms",millis()); return String(buf); }
  struct tm tm; gmtime_r(&n,&tm); char buf[32]; strftime(buf,sizeof(buf),"%Y-%m-%dT%H:%M:%SZ",&tm); return String(buf);
}

// ---- HTTP: telemetry ----
static bool postReading(float t, float h, int &httpCodeOut, int &respLenOut) {
  HTTPClient http;
#if USE_HTTPS
  WiFiClientSecure secure; secure.setInsecure();
  if (!http.begin(secure, POST_URL)) return false;
#else
  if (!http.begin(POST_URL)) return false;
#endif
  http.setTimeout(6000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body = String("{\"device\":\"") + DEVICE_ID +
                "\",\"t\":" + String(t,1) +
                ",\"h\":" + String(h,1) +
                ",\"rssi\":" + String(WiFi.RSSI()) +
                ",\"brand\":\"" + String(BRAND) + "\"}";

  brandStamp("Telemetry POST start");
  int code = http.POST(body);
  httpCodeOut = code;
  String resp = (code>0)? http.getString() : String();
  respLenOut = resp.length();
  if (resp.length()>200) resp = resp.substring(0,200)+"...";
  Serial.printf("[HTTP] POST %s code=%d resp(len=%d): %s\n", POST_URL, code, respLenOut, resp.c_str());
  brandStamp(code==200 ? "Telemetry POST success" : "Telemetry POST failed");
  http.end();
  return code==200;
}

// ---- HTTP: state poll (simple JSON peek for booleans) ----
static bool findBool(const String& s, const char* key, bool defVal){
  int i = s.indexOf(String("\"")+key+"\"");
  if (i<0) return defVal;
  int t = s.indexOf("true",  i);
  int f = s.indexOf("false", i);
  if (t!=-1 && (f==-1 || t<f)) return true;
  if (f!=-1 && (t==-1 || f<t)) return false;
  return defVal;
}
static void pollState() {
  if (WiFi.status()!=WL_CONNECTED) return;
  unsigned long now = millis();
  if (now - lastStatePoll < STATE_MS) return;
  lastStatePoll = now;

  HTTPClient http;
#if USE_HTTPS
  WiFiClientSecure secure; secure.setInsecure();
  if (!http.begin(secure, STATE_URL)) return;
#else
  if (!http.begin(STATE_URL)) return;
#endif
  http.setTimeout(4000);
  int code = http.GET();
  if (code==200) {
    String s = http.getString();
    bool newMode = findBool(s, "mode", followEnabled);
    bool reset   = findBool(s, "reset_finish", false);

    if (newMode != followEnabled) {
      followEnabled = newMode;
      Serial.printf("[MODE] followEnabled=%d (from website) — %s\n", followEnabled, BRAND);
      brandStamp(followEnabled? "Mode ON":"Mode OFF");
      if (!followEnabled) { driveState=DS_IDLE; }
    }
    if (reset) {
      finished=false; finishArmed=false; bothBlackStart=bothWhiteStart=0;
      Serial.printf("[MODE] finish cleared by website — %s\n", BRAND);
      brandStamp("Finish cleared (remote)");
      // Clear the flag on server
      HTTPClient http2;
#if USE_HTTPS
      WiFiClientSecure s2; s2.setInsecure();
      if (http2.begin(s2, STATE_URL)) {
#else
      if (http2.begin(STATE_URL)) {
#endif
        http2.addHeader("Content-Type","application/json");
        http2.POST("{\"reset_finish\":false}");
        http2.end();
      }
    }
  }
  http.end();
}

// ---- IR + motors ----
inline int senseRaw(int pin){ return digitalRead(pin); }
inline int sense(int pin){ int v=senseRaw(pin); return (LINE_ON_BLACK ? (v==LOW) : (v==HIGH)) ? 1:0; }
int senseStable(int pin){ int s=0; for(int i=0;i<3;i++){ s+=sense(pin); delayMicroseconds(250);} return s>=2; }

// Motor low-level: direction + speed (0..255) with analogWrite
void setMotorRaw(bool lf, uint8_t lpwm, bool rf, uint8_t rpwm) {
  // Left bridge
  digitalWrite(IN1, lf ? HIGH : LOW);
  digitalWrite(IN2, lf ? LOW  : HIGH);
  analogWrite(ENA, lpwm);

  // Right bridge
  digitalWrite(IN3, rf ? HIGH : LOW);
  digitalWrite(IN4, rf ? LOW  : HIGH);
  analogWrite(ENB, rpwm);
}

// signed speeds -255..255 (negative = reverse)
void setMotor(int left, int right) {
  left  = constrain(left,  -255, 255);
  right = constrain(right, -255, 255);
  bool lf = left  >= 0, rf = right >= 0;
  uint8_t lp = abs(left), rp = abs(right);
  setMotorRaw(lf, lp, rf, rp);
}
void stopMotors(){ setMotor(0,0); }

// ---- Arduino ----
void setup() {
  Serial.begin(115200); delay(100);
  Serial.println("\n=== MedBot ESP32: Line Follower + Telemetry + Web Control ===");
  Serial.printf("[INFO] Device=%s  Build=%s %s\n", DEVICE_ID, __DATE__, __TIME__); brandStamp("Boot");

  dht.begin(); brandStamp("DHT init");

  pinMode(IN1,OUTPUT); pinMode(IN2,OUTPUT); pinMode(IN3,OUTPUT); pinMode(IN4,OUTPUT);
  pinMode(ENA,OUTPUT); pinMode(ENB,OUTPUT);
  stopMotors(); brandStamp("Motors init (analogWrite)");

  pinMode(SEN_L, INPUT); pinMode(SEN_R, INPUT); brandStamp("IR sensors init");

  beginWiFiIfNeeded(); syncTimeOnce(); brandStamp("Setup complete");
}

void loop() {
  // Wi-Fi transitions
  if (WiFi.status()==WL_CONNECTED) {
    if (!wifiWasConnected) {
      wifiWasConnected = true;
      Serial.printf("[WIFI] Connected IP=%s RSSI=%ddBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
      brandStamp("Wi-Fi connected");
    }
    syncTimeOnce();
  } else {
    if (wifiWasConnected) { wifiWasConnected=false; Serial.println("[WIFI] Lost connection"); brandStamp("Wi-Fi lost"); }
    beginWiFiIfNeeded();
  }

  // Poll state from website
  pollState();

  // Read sensors
  int L = senseStable(SEN_L);
  int R = senseStable(SEN_R);

  // Finish-line FSM
  if (!finished && followEnabled) {
    if (L && R) {
      if (bothBlackStart==0) bothBlackStart=millis();
      if (!finishArmed && millis()-bothBlackStart >= FINISH_BLACK_MS) {
        finishArmed = true; bothWhiteStart=0; brandStamp("Finish armed (bar detected)");
      }
    } else {
      bothBlackStart=0;
      if (finishArmed && !L && !R) {
        if (bothWhiteStart==0) bothWhiteStart=millis();
        if (millis()-bothWhiteStart >= FINISH_CLEAR_MS) {
          finished=true; stopMotors();
          Serial.println("[FINISH] Finish line detected. Motors stopped."); brandStamp("FINISH — robot stopped");
          driveState = DS_FINISHED;
        }
      } else { bothWhiteStart=0; }
    }
  }

  // Drive decisions
  if (followEnabled && !finished) {
    if (L && R) { if (driveState!=DS_BOTH){brandStamp("Drive: BOTH (forward slow)"); driveState=DS_BOTH;} setMotor(BASE_SPEED-20, BASE_SPEED-20); }
    else if (L && !R){ if (driveState!=DS_LEFT){brandStamp("Drive: LEFT"); driveState=DS_LEFT;} setMotor(BASE_SPEED-CORRECT_DELTA, BASE_SPEED); lastDir=LEFT; }
    else if (!L && R){ if (driveState!=DS_RIGHT){brandStamp("Drive: RIGHT"); driveState=DS_RIGHT;} setMotor(BASE_SPEED, BASE_SPEED-CORRECT_DELTA); lastDir=RIGHT; }
    else { if (driveState!=DS_LOST){brandStamp("Drive: LOST — searching"); driveState=DS_LOST;} if (lastDir==LEFT) setMotor(-SEARCH_SPEED/2, SEARCH_SPEED); else setMotor(SEARCH_SPEED, -SEARCH_SPEED/2); }
  } else {
    if (driveState!=DS_IDLE && !finished){ brandStamp("Drive: IDLE (mode OFF)"); driveState=DS_IDLE; }
    stopMotors();
  }

  // Telemetry cadence
  unsigned long nowMs = millis();
  if (nowMs - lastSend >= SEND_MS) {
    lastSend = nowMs;
    float h = dht.readHumidity(); float t = dht.readTemperature();
    if (isnan(h) || isnan(t)) { Serial.printf("[DHT] Read failed (NaN) at %s\n", iso8601utc().c_str()); brandStamp("DHT read failed"); }
    else {
      int httpCode=-1, respLen=0;
      bool ok = (WiFi.status()==WL_CONNECTED) ? postReading(t,h,httpCode,respLen) : false;

      // INFO
      Serial.printf("[INFO] %s dev=%s T=%.1fC H=%.1f%% RSSI=%ddBm http=%d ok=%s finished=%d mode=%d L=%d R=%d\n",
        iso8601utc().c_str(), DEVICE_ID, t, h, WiFi.RSSI(), httpCode, ok?"yes":"no", (int)finished, (int)followEnabled, L, R);

      // CSV (+brand)
      Serial.printf("CSV,%s,%s,%.1f,%.1f,%d,%d,%d,finished=%d,mode=%d,L=%d,R=%d,brand=%s\n",
        iso8601utc().c_str(), DEVICE_ID, t, h, WiFi.RSSI(), httpCode, respLen,
        (int)finished, (int)followEnabled, L, R, BRAND);

      // JSON (+brand)
      String json = String("{\"ts\":\"") + iso8601utc() +
        "\",\"device\":\"" + DEVICE_ID +
        "\",\"t\":" + String(t,1) +
        ",\"h\":" + String(h,1) +
        ",\"ip\":\"" + WiFi.localIP().toString() +
        "\",\"rssi\":" + String(WiFi.RSSI()) +
        ",\"http\":" + String(httpCode) +
        ",\"finished\":" + String(finished ? "true":"false") +
        ",\"mode\":" + String(followEnabled ? "true":"false") +
        ",\"L\":" + String(L) + ",\"R\":" + String(R) +
        ",\"brand\":\"" + String(BRAND) + "\"}";
      Serial.println(json);
    }
  }

  delay(5);
}
