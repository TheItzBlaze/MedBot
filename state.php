<?php
// /MedBot/state.php — shared state for Follow mode + Finish reset
// GET  -> returns current state JSON
// POST -> updates any provided fields: { "mode": true|false, "reset_finish": true|false }
// Writes changes to debug.log so you can see them in the console.

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dir   = __DIR__;
$file  = $dir . "/state.json";
$log   = $dir . "/debug.log";

// ---- helpers ----
function load_state($file) {
  $state = ["mode" => true, "reset_finish" => false, "updated_at" => time()];
  if (is_file($file)) {
    $j = json_decode(@file_get_contents($file), true);
    if (is_array($j)) {
      $state = array_merge($state, $j);
    }
  }
  $state["updated_at"] = time();
  return $state;
}

function save_state($file, $state) {
  $tmp = $file . ".tmp";
  if (file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
    rename($tmp, $file);
    return true;
  }
  return false;
}

// ---- main ----
$state = load_state($file);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Prefer JSON body; fall back to form fields if needed
  $raw  = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = $_POST; // tolerate form-POST

  $changed = [];

  if (array_key_exists('mode', $data)) {
    $new = filter_var($data['mode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($new === null) { $new = !!$data['mode']; }
    if (!!$state['mode'] !== !!$new) {
      $state['mode'] = !!$new;
      $changed[] = "mode=" . ($state['mode'] ? "ON" : "OFF");
    }
  }

  if (array_key_exists('reset_finish', $data)) {
    $new = filter_var($data['reset_finish'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($new === null) { $new = !!$data['reset_finish']; }
    if (!!$state['reset_finish'] !== !!$new) {
      $state['reset_finish'] = !!$new;
      $changed[] = "reset_finish=" . ($state['reset_finish'] ? "true" : "false");
    }
  }

  $state['updated_at'] = time();
  if (!save_state($file, $state)) {
    http_response_code(500);
    echo json_encode(["ok"=>false, "err"=>"write_failed"]);
    exit;
  }

  // Log any change to debug.log for visibility in the web console
  if ($changed) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = "[" . date('Y-m-d H:i:s') . "] STATE " . implode(", ", $changed) . " ip=" . $ip . "\n";
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
  }
}

// Always return current state
echo json_encode($state);
