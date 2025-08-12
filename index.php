<?php
// /MedBot/index.php — Flowy dashboard + streaming console + mode controls

$dir  = __DIR__;
$data = $dir . "/data.json";
$log  = $dir . "/debug.log";

// JSON API for latest reading
if (isset($_GET['json'])) {
  header("Content-Type: application/json");
  if (is_file($data)) { readfile($data); } else { echo json_encode(["ok"=>false,"err"=>"no data yet"]); }
  exit;
}

// Streaming log chunks by byte offset (efficient tail)
if (isset($_GET['log'])) {
  header("Content-Type: application/json");
  $after = isset($_GET['after']) ? max(0, intval($_GET['after'])) : 0;
  if (!is_file($log)) { echo json_encode(["offset"=>0,"chunk"=>""]); exit; }
  $size = filesize($log);
  // realign if offset out of range; keep last 8KB max on first load
  if ($after > $size || $after < 0) { $after = max(0, $size - 8192); }
  $fh = fopen($log, "rb");
  fseek($fh, $after);
  $chunk = stream_get_contents($fh);
  fclose($fh);
  echo json_encode(["offset"=>$after + strlen($chunk), "chunk"=>$chunk, "size"=>$size]);
  exit;
}

// initial values
$latest = ["temperature"=>"--","humidity"=>"--","time"=>0];
if (is_file($data)) {
  $j = json_decode(file_get_contents($data), true);
  if (is_array($j)) $latest = $j;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MedBot — Live Environment</title>
<meta name="theme-color" content="#0e1525">
<style>
  :root {
    --bg1:#0e1525; --bg2:#141c31; --ink:#eaf2ff; --muted:#b6c3e0;
    --accent1:#5da3ff; --accent2:#8a3cff; --glass:rgba(255,255,255,.08);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink)}
  body{background:linear-gradient(135deg,var(--bg1),var(--bg2));overflow:hidden}

  /* flowy blobs */
  .blob{position:fixed;border-radius:50%;filter:blur(90px);opacity:.34;pointer-events:none;animation:float 25s ease-in-out infinite}
  .b1{width:600px;height:600px;left:-150px;top:-100px;background:var(--accent1);animation-delay:0s}
  .b2{width:700px;height:700px;right:-200px;bottom:-200px;background:var(--accent2);animation-delay:5s}
  .b3{width:500px;height:500px;left:40%;top:60%;background:#22d3ee;animation-delay:10s}
  @keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(60px,-40px) scale(1.1)}}

  .wrap{position:relative;height:100%;display:flex;align-items:center;justify-content:center;padding:20px;z-index:2}
  .card{width:min(980px,95vw);background:linear-gradient(180deg,rgba(255,255,255,.12),rgba(255,255,255,.05));
        border:1px solid rgba(255,255,255,.18);border-radius:24px;padding:24px;backdrop-filter:blur(14px);
        box-shadow:0 25px 60px rgba(0,0,0,.45);animation:fadeIn 1.2s ease-out}
  @keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

  header{margin-bottom:20px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center}
  h1{font-size:1.5rem;font-weight:700} .sub{color:var(--muted);font-size:.95rem;margin-top:4px}

  .controls{display:flex;gap:10px;flex-wrap:wrap}
  button{
    border:0;padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:800;
    background:linear-gradient(180deg,#2d6cdf,#234fb2);color:white;box-shadow:0 10px 25px rgba(45,108,223,.45);
    transition:transform .15s ease, box-shadow .2s ease
  }
  button:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(45,108,223,.55)}

  .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
  @media(max-width:720px){.grid{grid-template-columns:1fr}}

  .tile{background:var(--glass);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:20px;
        box-shadow:0 10px 30px rgba(0,0,0,.35);animation:breathe 4s ease-in-out infinite}
  @keyframes breathe{0%,100%{transform:scale(1)}50%{transform:scale(1.02)}}

  .label{color:var(--muted);font-size:.9rem;margin-bottom:6px}
  .value{font-size:3rem;font-weight:800;line-height:1.1}
  .units{font-size:1rem;font-weight:700;margin-left:4px;opacity:.85}
  .status{margin-top:10px;font-size:.9rem;color:var(--muted)}
  .badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-weight:600;font-size:.8rem}
  .ok{background:rgba(34,197,94,.15);color:#c8ffd7;border:1px solid rgba(34,197,94,.35)}
  .warn{background:rgba(245,158,11,.15);color:#ffe9c7;border:1px solid rgba(245,158,11,.35)}
  .bad{background:rgba(239,68,68,.15);color:#ffd2d2;border:1px solid rgba(239,68,68,.35)}

  /* debug console */
  .consoleWrap{margin-top:18px}
  .consoleTitle{color:var(--muted);font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:8px}
  .console{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    background: rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.15);
    border-radius:14px; padding:12px; height:220px; overflow:auto; white-space:pre-wrap; line-height:1.25;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.05);
  }
  .consoleCtrl{display:flex;gap:10px;margin:8px 0 0}
  footer{margin-top:16px;color:var(--muted);font-size:.85rem;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
  code{background:rgba(255,255,255,.08);padding:.2em .45em;border-radius:8px}
</style>
</head>
<body>
<div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div>

<div class="wrap">
  <div class="card">
    <header>
      <div>
        <h1>MedBot — Live Environment</h1>
        <div class="sub">Auto-refresh every <span id="every">0.5/span>s • Control follow mode below</div>
      </div>
      <div class="controls">
        <button id="toggleMode">Follow: …</button>
        <button id="resetFinish">Reset Finish</button>
      </div>
    </header>

    <div class="grid">
      <div class="tile">
        <div class="label">Temperature</div>
        <div class="value" id="temp"><?php echo htmlspecialchars(is_numeric($latest['temperature'])?$latest['temperature']:'--'); ?><span class="units">°C</span></div>
        <div class="status"><span class="badge warn" id="tBadge">Waiting…</span></div>
      </div>
      <div class="tile">
        <div class="label">Humidity</div>
        <div class="value" id="hum"><?php echo htmlspecialchars(is_numeric($latest['humidity'])?$latest['humidity']:'--'); ?><span class="units">%</span></div>
        <div class="status"><span class="badge warn" id="hBadge">Waiting…</span></div>
      </div>
    </div>

    <div class="consoleWrap">
      <div class="consoleTitle">🔧 Debug Console</div>
      <div class="console" id="console" aria-live="polite"></div>
      <div class="consoleCtrl">
        <button id="jumpEnd">Jump to end</button>
        <button id="pauseBtn">Pause</button>
        <button id="tryLog" onclick="tryPullLog()">Fetch Log</button>
        <button id="clear" onclick="clearConsole()">Clear</button>
        <button id="resetLog" onclick="resetLogHistory()">Reset Logs</button>
        <button id="clearHistory" onclick="clearLogHistory()">Clear History</button>
      </div>
    </div>

    <footer>
      <div id="stamp">Last update: <?php echo $latest['time']? date('H:i:s',$latest['time']) : '—'; ?></div>
      <div>Receiver: <code>/MedBot/receive.php</code> • JSON: <code>?json=1</code> • Log: <code>?log=1</code> • State: <code>/MedBot/state.php</code></div>
    </footer>
  </div>
</div>

<script>
  const EVERY = 500;
  let logErr = 0;
  document.getElementById('every').textContent = EVERY/1000;
  const stateURL = 'state.php';

  function fmt(v){ return Number.isFinite(v)? v.toFixed(1) : "--"; }
  function badge(el, cls, txt){ el.className = "badge " + cls; el.textContent = txt; }
  function colorT(c){ if(!Number.isFinite(c)) return "warn"; if(c<10) return "warn"; if(c<=30) return "ok"; if(c<=37) return "warn"; return "bad"; }
  function colorH(h){ if(!Number.isFinite(h)) return "warn"; if(h<25||h>75) return "bad"; if(h<35||h>65) return "warn"; return "ok"; }

  async function pullData(){
    try{
      const r = await fetch('?json=1',{cache:'no-store'});
      const j = await r.json();
      if (j && (j.temperature !== undefined) && (j.humidity !== undefined)){
        const t = Number(j.temperature), h = Number(j.humidity);
        document.getElementById('temp').innerHTML = fmt(t) + '<span class="units">°C</span>';
        document.getElementById('hum').innerHTML  = fmt(h) + '<span class="units">%</span>';
        badge(document.getElementById('tBadge'), colorT(t), fmt(t) + ' °C');
        badge(document.getElementById('hBadge'), colorH(h), fmt(h) + ' %');
        document.getElementById('stamp').textContent =
          'Last update: ' + (j.time ? new Date(j.time*1000).toLocaleTimeString() : new Date().toLocaleTimeString());
      }
    }catch(e){
      badge(document.getElementById('tBadge'), 'bad', 'Fetch failed');
      badge(document.getElementById('hBadge'), 'bad', 'Fetch failed');
    }
  }

  // ----- Live debug console (byte-range tail) -----
  let logOffset = 0;
  let paused = false;
  const consoleEl = document.getElementById('console');
  let historyLogs = "";

  function appendConsole(text){
    if (!text) return;
    const atEnd = consoleEl.scrollTop + consoleEl.clientHeight >= consoleEl.scrollHeight - 5;
    consoleEl.textContent += text;
    historyLogs += text;
    if (atEnd) consoleEl.scrollTop = consoleEl.scrollHeight;
  }
  
  function clearConsole() {
      consoleEl.textContent = "";
      appendConsole(""); // Fix vars
  }
  
  function resetLogHistory(){
      consoleEl.textContent = historyLogs;
      appendConsole(""); //fix vars
  }
  
  function clearLogHistory() {
      historyLogs = "";
  }
  
  async function pullLog(forceAppend=0){
    if (paused) return;
    try{
      const r = await fetch(`?log=1&after=${logOffset}`, {cache:'no-store'});
      const j = await r.json();
      if (typeof j.offset === 'number'){
        if (j.chunk) appendConsole(j.chunk);
        logOffset = j.offset;
      }
      if (logErr == 1) {
          logErr = 0;
      }
    }catch(e){
        if (logErr == 0) {
          appendConsole(`[${new Date().toLocaleTimeString()}] (client) log fetch failed\n`);
          logErr = 1;
        }
        if (forceAppend == 1){
          appendConsole(`[${new Date().toLocaleTimeString()}] (client) log fetch failed\n`);
        }
    }
  }

  // ----- Follow mode controls -----
  function setModeButton(on){
    const b = document.getElementById('toggleMode');
    b.textContent = 'Follow: ' + (on ? 'ON' : 'OFF');
    b.dataset.on = on ? '1' : '0';
  }
  async function pullState(){
    try{
      const r = await fetch(stateURL, {cache:'no-store'});
      const s = await r.json();
      setModeButton(!!s.mode);
    }catch(e){}
  }
  async function toggleMode(){
    const b = document.getElementById('toggleMode');
    const on = b.dataset.on === '1';
    try{
      const r = await fetch(stateURL, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({mode: !on})
      });
      const s = await r.json();
      setModeButton(!!s.mode);
    }catch(e){}
  }
  async function resetFinish(){
    try{
      await fetch(stateURL, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({reset_finish:true})
      });
    }catch(e){}
  }
  
  function tryPullLog(){
    pullLog(1);
  }

  document.getElementById('toggleMode').addEventListener('click', toggleMode);
  document.getElementById('resetFinish').addEventListener('click', resetFinish);

  // start loops
  pullData();  setInterval(pullData, EVERY);
  pullLog(0);  setInterval(pullLog, 500, 0);
  pullState(); setInterval(pullState, 1000);
</script>
</body>
</html>
