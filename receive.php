<?php
// /MedBot/receive.php — ESP32 -> PHP ingest + debug log
// Accepts JSON (POST) or query params (GET). Uses a shared secret.
// Updated with brand stamping for "made by itzblaze.net Shourya"

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$SECRET = "akshobhyaishappyandjenilishappy"; // must match ESP32
$BRAND  = "made by itzblaze.net Shourya";

$now   = time();
$dir   = __DIR__;
$dataPath = $dir . "/data.json";
$logPath  = $dir . "/debug.log";

// ---- Parse input ----
$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

// auth: header > body > query
$hKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
$api  = $hKey ?: ($body['api_key'] ?? ($_POST['api_key'] ?? ($_GET['api_key'] ?? null)));
if ($api !== $SECRET) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"err"=>"unauthorized"]);
  exit;
}

// params
$t     = $body['t']      ?? ($_POST['t'] ?? ($_GET['t'] ?? null));
$h     = $body['h']      ?? ($_POST['h'] ?? ($_GET['h'] ?? null));
$dev   = $body['device'] ?? ($_POST['device'] ?? ($_GET['device'] ?? "medbot-01"));
$rssi  = $body['rssi']   ?? ($_POST['rssi'] ?? ($_GET['rssi'] ?? null));
$brand = $body['brand']  ?? $BRAND; // prefer incoming but default to ours

if ($t === null || $h === null) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>"missing t/h"]);
  exit;
}

$t = round((float)$t, 1);
$h = round((float)$h, 1);

// sanity check
if (!is_numeric($t) || !is_numeric($h) || $t < -40 || $t > 85 || $h < 0 || $h > 100) {
  http_response_code(422);
  echo json_encode(["ok"=>false,"err"=>"out_of_range"]);
  exit;
}

// build payload
$payload = [
  "device"      => $dev,
  "temperature" => $t,
  "humidity"    => $h,
  "time"        => $now,
  "brand"       => $brand
];
if ($rssi !== null && $rssi !== '') {
  $payload["rssi"] = (int)$rssi;
}

// atomic write latest JSON
$tmp = $dataPath . ".tmp";
if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"write_failed"]);
  exit;
}
rename($tmp, $dataPath);

// append to debug.log
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$line = sprintf(
  "[%s] dev=%s t=%.1f°C h=%.1f%% rssi=%s ip=%s brand=%s\n",
  date('Y-m-d H:i:s', $now),
  $dev,
  $t,
  $h,
  ($rssi === null || $rssi === '') ? 'n/a' : ((int)$rssi . 'dBm'),
  $ip,
  $brand
);
file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);

echo json_encode(["ok"=>true, "saved"=>$payload]);
