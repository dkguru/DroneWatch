<?php
// SPDX-License-Identifier: CC-BY-ND-4.0
// Copyright (c) 2026 Kianna Angelo and Jesper Angelo <kj@angelo.net>
// Idea by Kianna Angelo
// https://github.com/dkguru/DroneWatch
/*
 * Drone Watch dashboard - live web view of drone_watch.py
 *
 * Reads the status heartbeat and SQLite log that drone_watch.py writes to
 * /var/lib/drone_watch (override with the DRONE_WATCH_DIR env variable).
 * Read-only: it never writes to the database.
 *
 *   index.php              -> the dashboard page
 *   index.php?api=status   -> live JSON (heartbeat + drones in range + trails)
 *   index.php?api=history  -> JSON summary of the last N hours (default 24)
 *   index.php?api=csv      -> CSV export of every sighting (evidence file)
 */

$DATA_DIR    = getenv('DRONE_WATCH_DIR') ?: '/var/lib/drone_watch';
$DB_PATH     = "$DATA_DIR/drone_watch.db";
$STATUS_PATH = "$DATA_DIR/status.json";
$REQUEST_DIR = "$DATA_DIR/requests";   // report requests for the scanner to email
$RESULT_DIR  = "$DATA_DIR/results";    // the scanner's answer to each request
$OFFLINE_AFTER = 10; // seconds without a heartbeat before we call the scanner offline

function db() {
    global $DB_PATH;
    if (!is_readable($DB_PATH)) return null;
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PHP SQLite driver missing: run "sudo apt install php-sqlite3 && sudo systemctl restart apache2"');
    }
    $pdo = new PDO("sqlite:$DB_PATH", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only = 1');
    return $pdo;
}

function json_out($data) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

$api = $_GET['api'] ?? null;

// Any failure in the API answers with JSON the page can show, instead of a blank HTTP 500.
try {

if ($api === 'status') {
    $status = is_readable($STATUS_PATH) ? json_decode(file_get_contents($STATUS_PATH), true) : null;
    $now = microtime(true);
    $out = [
        'server_time' => $now,
        'running'     => $status && ($now - $status['heartbeat']) < $OFFLINE_AFTER,
        'heartbeat_age' => $status ? round($now - $status['heartbeat'], 1) : null,
        'status'      => $status,
        'trails'      => [],
        'today'       => null,
    ];
    if ($pdo = db()) {
        // 10-minute position trail for each drone currently in range
        $q = $pdo->prepare("SELECT lat, lon FROM sightings WHERE mac = ? AND lat IS NOT NULL
                            AND datetime(ts) >= datetime('now','localtime','-10 minutes') ORDER BY ts");
        foreach (($status['drones'] ?? []) as $d) {
            $q->execute([$d['mac']]);
            $out['trails'][$d['mac']] = $q->fetchAll(PDO::FETCH_NUM);
        }
        $out['today'] = $pdo->query("SELECT
              COUNT(DISTINCT uas_id) AS drones,
              COUNT(DISTINCT CASE WHEN inside_radius = 1 THEN uas_id END) AS intrusions,
              COUNT(*) AS packets,
              MIN(dist_home_m) AS closest
            FROM sightings WHERE uas_id IS NOT NULL AND date(ts) = date('now','localtime')")
            ->fetch(PDO::FETCH_ASSOC);
    }
    json_out($out);
}

if ($api === 'history') {
    $hours = max(1, min(24 * 90, (int)($_GET['hours'] ?? 24)));
    $rows = [];
    if ($pdo = db()) {
        // Friendly names are kept by the scanner in drone_names (missing on old scanner versions).
        $hasNames = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='drone_names'")->fetchColumn();
        $nameCol = $hasNames ? "(SELECT name FROM drone_names n WHERE n.uas_id = sightings.uas_id)" : "NULL";
        $q = $pdo->prepare("SELECT uas_id, $nameCol AS name, MAX(id_type) AS id_type,
                   COALESCE(MAX(CASE WHEN manufacturer NOT LIKE 'code %' THEN manufacturer END),
                            MAX(manufacturer)) AS maker, MAX(ua_type) AS ua_type,
                   MIN(ts) AS first_seen, MAX(ts) AS last_seen, COUNT(*) AS hits,
                   MIN(dist_home_m) AS closest, MAX(height_m) AS max_height,
                   MAX(inside_radius) AS intruded, MAX(op_lat) AS op_lat, MAX(op_lon) AS op_lon,
                   MAX(self_id) AS self_id
            FROM sightings
            WHERE uas_id IS NOT NULL AND datetime(ts) >= datetime('now','localtime', ?)
            GROUP BY uas_id ORDER BY last_seen DESC LIMIT 500");
        $q->execute(["-$hours hours"]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    json_out($rows);
}

if ($api === 'config') {
    // The scanner publishes its settings in status.json without the mail password.
    $status = is_readable($STATUS_PATH) ? json_decode(file_get_contents($STATUS_PATH), true) : null;
    json_out([
        'scanner'   => $status['config'] ?? null,
        'running'   => $status && (microtime(true) - $status['heartbeat']) < $OFFLINE_AFTER,
        'dashboard' => [
            'php_version'   => PHP_VERSION,
            'sqlite_driver' => extension_loaded('pdo_sqlite') ? 'installed' : 'MISSING (sudo apt install php-sqlite3)',
            'database'      => is_readable($DB_PATH) ? $DB_PATH : "$DB_PATH (not readable)",
            'report_queue'  => is_writable($REQUEST_DIR) ? 'ready' : "$REQUEST_DIR not writable (restart the scanner)",
        ],
    ]);
}

// Requests the scanner carries out: emailing a report, or renaming a drone.
// POST + custom header so a link, prefetch or another website can't trigger them.
// The web page never writes the database or sends mail itself.
function queue_request(array $payload) {
    global $STATUS_PATH, $REQUEST_DIR, $OFFLINE_AFTER;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_DRONEWATCH'] ?? '') !== '1') {
        http_response_code(405);
        json_out(['error' => 'use the dashboard']);
    }
    if (!preg_match('/^[A-Za-z0-9 :._-]{1,40}$/', $payload['drone'])) {
        http_response_code(400);
        json_out(['error' => 'invalid drone id']);
    }
    $status = is_readable($STATUS_PATH) ? json_decode(file_get_contents($STATUS_PATH), true) : null;
    if (!$status || (microtime(true) - $status['heartbeat']) >= $OFFLINE_AFTER) {
        http_response_code(503);
        json_out(['error' => 'the scanner is offline; it carries out this request']);
    }
    if (!is_writable($REQUEST_DIR)) {
        http_response_code(503);
        json_out(['error' => 'request queue not available; restart the scanner to create it']);
    }
    $id = bin2hex(random_bytes(12));
    $tmp = "$REQUEST_DIR/$id.tmp";
    file_put_contents($tmp, json_encode($payload + ['requested' => time()]));
    rename($tmp, "$REQUEST_DIR/$id.json");
    json_out(['id' => $id]);
}

if ($api === 'report' || $api === 'rename') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $drone = (string)($in['drone'] ?? '');
    if ($api === 'report') {
        // Mail only goes to the recipients in the scanner's config, never to an address given here.
        queue_request(['type' => 'report', 'drone' => $drone]);
    }
    $name = trim((string)($in['name'] ?? ''));
    if (!preg_match('/^[^\x00-\x1F\x7F]{0,40}$/u', $name)) {
        http_response_code(400);
        json_out(['error' => 'names can be up to 40 characters']);
    }
    queue_request(['type' => 'rename', 'drone' => $drone, 'name' => $name]);
}

if ($api === 'report_status') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) json_out(['error' => 'invalid id']);
    $file = "$RESULT_DIR/$id.json";
    json_out(is_readable($file) ? json_decode(file_get_contents($file), true) : ['pending' => true]);
}

if ($api === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="drone_sightings_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    if ($pdo = db()) {
        $st = $pdo->query("SELECT * FROM sightings ORDER BY ts");
        $first = true;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($first) { fputcsv($fh, array_keys($r)); $first = false; }
            fputcsv($fh, $r);
        }
    }
    exit;
}

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Drone Watch</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  :root {
    --bg: #070b0d; --panel: #0d1417; --line: #1b2a2f; --text: #cfe8e4; --dim: #6b8a86;
    --accent: #2ef2b4; --warn: #ffb547; --alert: #ff4d5e; --op: #58a6ff;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text);
         font: 14px/1.4 ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace; }
  header { display: flex; align-items: center; gap: 16px; padding: 14px 22px;
           border-bottom: 1px solid var(--line); background: linear-gradient(#0b1215, var(--bg)); }
  .radar { width: 38px; height: 38px; border-radius: 50%; position: relative; flex: none;
           border: 1px solid var(--accent); box-shadow: 0 0 12px #2ef2b455 inset;
           background: repeating-radial-gradient(circle, transparent 0 8px, #2ef2b422 8px 9px); }
  .radar::after { content: ""; position: absolute; inset: 0; border-radius: 50%;
           background: conic-gradient(from 0deg, #2ef2b4aa, transparent 25%);
           animation: sweep 2.5s linear infinite; }
  .offline .radar::after { animation: none; opacity: .15; }
  @keyframes sweep { to { transform: rotate(360deg); } }
  h1 { font-size: 18px; letter-spacing: .25em; margin: 0; font-weight: 600; }
  h1 small { display: block; font-size: 11px; letter-spacing: .1em; color: var(--dim); font-weight: 400; }
  .pill { margin-left: auto; padding: 6px 14px; border-radius: 99px; font-size: 12px; letter-spacing: .15em;
          border: 1px solid var(--accent); color: var(--accent); }
  .pill::before { content: "●  "; animation: blink 1.2s infinite; }
  .offline .pill { border-color: var(--alert); color: var(--alert); }
  @keyframes blink { 50% { opacity: .2; } }

  main { display: grid; grid-template-columns: 1fr 420px; gap: 16px; padding: 16px 22px; }
  @media (max-width: 1000px) { main { grid-template-columns: 1fr; } }
  .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .panel h2 { margin: 0; padding: 10px 14px; font-size: 11px; letter-spacing: .2em; color: var(--dim);
              border-bottom: 1px solid var(--line); font-weight: 500; display: flex; justify-content: space-between; }
  #map { height: 520px; background: #000; }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--line); }
  .stat { background: var(--panel); padding: 14px; }
  .stat b { display: block; font-size: 26px; color: var(--accent); font-weight: 600; }
  .stat.alert b { color: var(--alert); }
  .stat span { font-size: 11px; color: var(--dim); letter-spacing: .1em; }

  #live { max-height: 520px; overflow-y: auto; }
  .drone { padding: 12px 14px; border-bottom: 1px solid var(--line); cursor: pointer; transition: background .2s; }
  .drone:hover { background: #12201f; }
  .drone.inside { border-left: 3px solid var(--alert); background: #ff4d5e0d; }
  .drone .id { font-size: 15px; color: #fff; display: flex; justify-content: space-between; }
  .drone .id em { font-style: normal; font-size: 11px; padding: 2px 8px; border-radius: 4px;
                  background: #2ef2b422; color: var(--accent); }
  .drone.inside .id em { background: var(--alert); color: #fff; }
  .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 12px; margin-top: 8px; font-size: 12px; }
  .grid div span { display: block; color: var(--dim); font-size: 10px; letter-spacing: .1em; }
  .empty { padding: 40px 14px; text-align: center; color: var(--dim); }
  .mail-btn { background: transparent; color: var(--accent); border: 1px solid #2ef2b455; border-radius: 5px;
              font: inherit; font-size: 10px; letter-spacing: .1em; padding: 3px 8px; cursor: pointer; white-space: nowrap; }
  .mail-btn:hover { background: #2ef2b415; border-color: var(--accent); }
  .mail-btn:disabled { cursor: default; opacity: .8; }
  .mail-btn.ok { color: var(--accent); border-color: var(--accent); }
  .mail-btn.fail { color: var(--alert); border-color: var(--alert); }
  .cfg-btn { margin-top: 10px; background: transparent; color: var(--dim); border: 1px solid var(--line);
             border-radius: 5px; font: inherit; font-size: 10px; letter-spacing: .12em; padding: 4px 10px; cursor: pointer; }
  .cfg-btn:hover { color: var(--text); border-color: var(--dim); }
  .modal { position: fixed; inset: 0; background: #000b; display: none; align-items: center;
               justify-content: center; z-index: 2000; padding: 16px; }
  .modal.open { display: flex; }
  .modal .box { background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
                    width: min(640px, 100%); max-height: 85vh; overflow-y: auto; }
  .modal h3 { margin: 0; padding: 12px 16px; font-size: 11px; letter-spacing: .2em; color: var(--dim);
                  font-weight: 500; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; }
  .modal h3 button { background: none; border: 0; color: var(--dim); font-size: 16px; cursor: pointer; }
  .modal h4 { margin: 14px 16px 4px; font-size: 10px; letter-spacing: .2em; color: var(--accent); font-weight: 500; }
  .modal table td { white-space: normal; word-break: break-word; }
  .modal table td:first-child { color: var(--dim); width: 42%; }
  .drone .id, #hist tr[data-drone] { cursor: pointer; }
  #hist tr[data-drone]:hover td { background: #12201f; }
  .drone .id > span:last-child { flex: none; white-space: nowrap; }
  .serial { font-size: 10px; color: var(--dim); letter-spacing: .05em; margin-top: 2px; }
  .name-row { display: flex; gap: 8px; padding: 6px 16px 4px; }
  .name-row input { flex: 1; background: #000; color: var(--text); border: 1px solid var(--line);
                    border-radius: 6px; padding: 7px 10px; font: inherit; font-size: 13px; }
  .name-row input:focus { outline: none; border-color: var(--accent); }
  .act-btn { background: transparent; color: var(--accent); border: 1px solid #2ef2b455; border-radius: 6px;
             font: inherit; font-size: 11px; letter-spacing: .1em; padding: 6px 12px; cursor: pointer; }
  .act-btn:hover { background: #2ef2b415; border-color: var(--accent); }
  .act-btn.plain { color: var(--dim); border-color: var(--line); }
  #dm-msg { padding: 2px 16px; font-size: 11px; min-height: 16px; color: var(--dim); }
  #dm-msg.ok { color: var(--accent); } #dm-msg.fail { color: var(--alert); }
  .dm-actions { display: flex; gap: 8px; padding: 10px 16px 16px; flex-wrap: wrap; }
  .mono { font-family: inherit; user-select: all; color: #fff; }
  .modal .note { padding: 10px 16px 16px; font-size: 11px; color: var(--dim); }

  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th { color: var(--dim); font-weight: 500; font-size: 10px; letter-spacing: .15em; }
  tr.intr td:first-child { color: var(--alert); }
  .wide { grid-column: 1 / -1; }
  .tbl { overflow-x: auto; }
  select, a.btn { background: #000; color: var(--text); border: 1px solid var(--line); border-radius: 6px;
                  padding: 3px 8px; font: inherit; font-size: 11px; text-decoration: none; }
  footer { padding: 4px 22px 22px; color: var(--dim); font-size: 11px; }
  .copyright { margin-top: 10px; font-size: 10px; opacity: .6; letter-spacing: .03em; }
  .copyright a { color: inherit; }
  .leaflet-container { font: inherit; background: #000; }
  .dark-tiles { filter: invert(1) hue-rotate(180deg) brightness(.85) contrast(.9) saturate(.6); }
  .leaflet-popup-content-wrapper, .leaflet-popup-tip { background: var(--panel); color: var(--text); }
</style>
</head>
<body class="offline">
<header>
  <div class="radar"></div>
  <h1>DRONE WATCH<small id="sub">connecting…</small></h1>
  <div class="pill" id="pill">OFFLINE</div>
</header>

<main>
  <section class="panel">
    <h2><span>AIRSPACE</span><span id="clock"></span></h2>
    <div id="map"></div>
    <div class="stats">
      <div class="stat"><b id="s-live">0</b><span>IN RANGE NOW</span></div>
      <div class="stat alert"><b id="s-intr">0</b><span>INTRUSIONS TODAY</span></div>
      <div class="stat"><b id="s-today">0</b><span>DRONES TODAY</span></div>
      <div class="stat"><b id="s-close">–</b><span>CLOSEST TODAY</span></div>
    </div>
  </section>

  <section class="panel">
    <h2><span>LIVE CONTACTS</span><span id="srcs"></span></h2>
    <div id="live"><div class="empty">No drones in range</div></div>
  </section>

  <section class="panel wide">
    <h2><span>HISTORY</span>
      <span>
        <select id="hours">
          <option value="24">24 h</option><option value="168">7 days</option><option value="720">30 days</option>
        </select>
        <a class="btn" href="?api=csv">EXPORT CSV</a>
      </span>
    </h2>
    <div class="tbl"><table>
      <thead><tr><th>REMOTE ID</th><th>MAKER</th><th>TYPE</th><th>FIRST SEEN</th><th>LAST SEEN</th>
        <th>HITS</th><th>CLOSEST</th><th>MAX HEIGHT</th><th>OPERATOR LOCATION</th><th>NOTE</th><th>REPORT</th></tr></thead>
      <tbody id="hist"></tbody>
    </table></div>
  </section>
</main>
<footer>Owner identity is not part of the Remote ID broadcast. Give the serial number, times and operator
location to local police, who can query the FAA. Rows in red came inside your alert radius.
<div class="copyright">© 2026 Kianna Angelo and Jesper Angelo · Idea by Kianna Angelo ·
<a href="https://creativecommons.org/licenses/by-nd/4.0/" target="_blank" rel="noopener">CC BY-ND 4.0</a> ·
<a href="https://github.com/dkguru/DroneWatch" target="_blank" rel="noopener">github.com/dkguru/DroneWatch</a></div>
<button class="cfg-btn" id="cfg-open">SHOW CONFIGURATION</button>
</footer>

<div class="modal" id="drone-modal" role="dialog" aria-modal="true" aria-labelledby="dm-title">
  <div class="box">
    <h3><span id="dm-title">DRONE</span><button id="dm-close" aria-label="Close">✕</button></h3>
    <h4>NAME</h4>
    <div class="name-row">
      <input id="dm-name" maxlength="40" placeholder="e.g. My Skydio 2" autocomplete="off">
      <button class="act-btn" id="dm-save">SAVE</button>
    </div>
    <div id="dm-msg"></div>
    <div id="dm-info"></div>
    <div class="dm-actions" id="dm-actions"></div>
  </div>
</div>

<div class="modal" id="cfg-modal" role="dialog" aria-modal="true" aria-labelledby="cfg-title">
  <div class="box">
    <h3><span id="cfg-title">CONFIGURATION</span><button id="cfg-close" aria-label="Close">✕</button></h3>
    <div id="cfg-body"><div class="empty">Loading…</div></div>
    <div class="note">Read-only. To change settings, edit <code>/etc/drone_watch.conf</code> on the Pi and run
      <code>sudo systemctl restart drone-watch</code>. The mail password is never shown.</div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const fmt = (v, u = "", d = 0) => v == null ? "–" : Number(v).toFixed(d) + u;
// "My Skydio 2 (...W52F)" when named, otherwise the full serial.
const names = {};       // serial -> name, from the scanner's drone_names table
const label = (serial, name = names[serial]) => name ? `${name} (...${String(serial).slice(-4)})` : serial;
const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

// The map library loads from the internet. If it can't, the rest of the dashboard still works.
const HAS_MAP = typeof L !== "undefined";
if (!HAS_MAP) document.getElementById("map").innerHTML =
  `<div class="empty">Map unavailable: this browser could not load the Leaflet map library from unpkg.com.</div>`;
const map = HAS_MAP ? L.map("map", { zoomControl: true }).setView([45.41, -122.70], 16) : null;
// OpenStreetMap tiles (no API key), darkened with a CSS filter to match the theme.
if (HAS_MAP) L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 19, className: "dark-tiles", attribution: "© OpenStreetMap contributors" }).addTo(map);

let homeLayer = null, homeKey = null;
const layers = {};   // mac -> {drone, op, link, trail}

const droneIcon = inside => L.divIcon({ className: "", iconSize: [22, 22], iconAnchor: [11, 11],
  html: `<div style="width:22px;height:22px;border-radius:50%;border:2px solid ${inside ? "#ff4d5e" : "#2ef2b4"};
         box-shadow:0 0 14px ${inside ? "#ff4d5e" : "#2ef2b4"};background:#000a;display:grid;place-items:center;
         font-size:12px;color:#fff">✦</div>` });
const opIcon = HAS_MAP && L.divIcon({ className: "", iconSize: [14, 14], iconAnchor: [7, 7],
  html: `<div style="width:14px;height:14px;transform:rotate(45deg);background:#58a6ff;box-shadow:0 0 10px #58a6ff"></div>` });

function drawHome(home, radius) {
  if (!HAS_MAP) return;
  // Redraw (and re-centre) whenever the scanner reports a different home or radius.
  const key = home ? home.join(",") + "/" + radius : null;
  if (!home || key === homeKey) return;
  if (homeLayer) map.removeLayer(homeLayer);
  homeKey = key;
  homeLayer = L.layerGroup([
    L.circle(home, { radius, color: "#ff4d5e", weight: 1, dashArray: "4 6", fillOpacity: .05 }),
    L.circleMarker(home, { radius: 5, color: "#fff", fillColor: "#fff", fillOpacity: 1 }).bindTooltip("Home"),
  ]).addTo(map);
  map.setView(home, 17);
}

function updateMap(drones, trails) {
  if (!HAS_MAP) return;
  const seen = new Set();
  for (const d of drones) {
    if (d.lat == null) continue;
    seen.add(d.mac);
    const pos = [d.lat, d.lon];
    let l = layers[d.mac];
    if (!l) {
      l = layers[d.mac] = {
        drone: L.marker(pos).addTo(map),
        trail: L.polyline([], { color: "#2ef2b4", weight: 2, opacity: .5 }).addTo(map),
        link: L.polyline([], { color: "#58a6ff", weight: 1, dashArray: "3 5" }).addTo(map),
        op: null,
      };
    }
    l.drone.setLatLng(pos).setIcon(droneIcon(d.inside))
      .bindPopup(`<b>${esc(d.uas_id ? label(d.uas_id) : d.mac)}</b><br>
        ${d.uas_id && names[d.uas_id] ? `<span style="opacity:.7">${esc(d.uas_id)}</span><br>` : ""}${esc(d.manufacturer || "")} ${esc(d.ua_type || "")}<br>
        Height ${fmt(d.height_m, " m")} · ${fmt(d.speed_ms, " m/s", 1)}<br>${fmt(d.dist_home_m, " m from home")}`);
    l.trail.setLatLngs(trails[d.mac] || []);
    l.trail.setStyle({ color: d.inside ? "#ff4d5e" : "#2ef2b4" });
    if (d.op_lat != null) {
      const op = [d.op_lat, d.op_lon];
      if (!l.op) l.op = L.marker(op, { icon: opIcon }).addTo(map);
      l.op.setLatLng(op).bindTooltip("Operator / takeoff: " + esc(d.uas_id ? label(d.uas_id) : d.mac));
      l.link.setLatLngs([op, pos]);
    }
  }
  for (const mac in layers) if (!seen.has(mac)) {
    const l = layers[mac];
    [l.drone, l.trail, l.link, l.op].forEach(x => x && map.removeLayer(x));
    delete layers[mac];
  }
}

const liveData = {};    // serial -> latest live record, for the drone dialog
let liveIds = new Set(); // serials in range right now
function updateList(drones) {
  liveIds = new Set(drones.map(d => d.uas_id).filter(Boolean));
  for (const d of drones) if (d.uas_id) {
    liveData[d.uas_id] = d;
    if (d.name) names[d.uas_id] = d.name; else delete names[d.uas_id];
  }
  if (!drones.length) { $("live").innerHTML = `<div class="empty">No drones in range</div>`; return; }
  drones.sort((a, b) => (a.dist_home_m ?? 1e9) - (b.dist_home_m ?? 1e9));
  $("live").innerHTML = drones.map(d => `
    <div class="drone ${d.inside ? "inside" : ""}" data-drone="${esc(d.uas_id || "")}"
         data-lat="${d.lat ?? ""}" data-lon="${d.lon ?? ""}" title="Click for details and to rename">
      <div class="id"><span>${esc(d.uas_id ? label(d.uas_id) : "(waiting for ID) " + d.mac)}
        ${d.uas_id && names[d.uas_id] ? `<div class="serial">${esc(d.uas_id)}</div>` : ""}</span>
        <span>${d.uas_id ? mailButton(d.uas_id) + " " : ""}<em>${d.inside ? "INSIDE RADIUS" : esc(d.status || "—")}</em></span></div>
      <div class="grid">
        <div><span>MAKER</span>${esc(d.manufacturer || "–")}</div>
        <div><span>TYPE</span>${esc(d.ua_type || "–")}</div>
        <div><span>DISTANCE</span>${fmt(d.dist_home_m, " m")}</div>
        <div><span>HEIGHT</span>${fmt(d.height_m, " m")}</div>
        <div><span>SPEED</span>${fmt(d.speed_ms, " m/s", 1)}</div>
        <div><span>HEADING</span>${fmt(d.heading, "°")}</div>
        <div><span>SIGNAL</span>${esc(d.source)} ${fmt(d.rssi, " dBm")}</div>
        <div><span>LAST PACKET</span>${fmt(d.age, " s ago", 0)}</div>
        <div><span>OPERATOR</span>${d.op_lat != null ? d.op_lat.toFixed(5) + "," + d.op_lon.toFixed(5) : "–"}</div>
      </div>
      ${d.self_id ? `<div style="margin-top:6px;color:var(--warn)">“${esc(d.self_id)}”</div>` : ""}
    </div>`).join("");
  // Clicking an entry is handled by the shared click handler below (opens the drone dialog).
}

async function poll() {
  try {
    const r = await (await fetch("?api=status", { cache: "no-store" })).json();
    if (r.error) throw new Error(r.error);
    document.body.classList.toggle("offline", !r.running);
    $("pill").textContent = r.running ? "SCANNING" : "OFFLINE";
    const s = r.status;
    if (s) {
      const up = Math.round((s.heartbeat - s.started) / 60);
      $("sub").textContent = r.running
        ? `pid ${s.pid} · up ${up >= 60 ? Math.floor(up / 60) + "h " : ""}${up % 60}m · radius ${s.radius} m`
        : `scanner silent for ${Math.round(r.heartbeat_age)} s`;
      $("srcs").textContent = (s.sources || []).join(" + ");
      drawHome(s.home, s.radius);
      const drones = r.running ? s.drones : [];
      updateMap(drones, r.trails || {});
      updateList(drones);
      $("s-live").textContent = drones.length;
    } else {
      $("sub").textContent = "no status file — is drone_watch.py running?";
    }
    if (r.today) {
      $("s-intr").textContent = r.today.intrusions ?? 0;
      $("s-today").textContent = r.today.drones ?? 0;
      $("s-close").textContent = fmt(r.today.closest, " m");
    }
  } catch (e) {
    document.body.classList.add("offline");
    $("sub").textContent = "server error: " + (e.message || e);
  }
}

const histData = {};    // serial -> history summary row, for the drone dialog
async function loadHistory() {
  const rows = await (await fetch("?api=history&hours=" + $("hours").value)).json();
  if (rows.error) {
    $("hist").innerHTML = `<tr><td colspan="11" class="empty">Server error: ${esc(rows.error)}</td></tr>`;
    return;
  }
  for (const r of rows) {
    histData[r.uas_id] = r;
    if (r.name) names[r.uas_id] = r.name; else delete names[r.uas_id];
  }
  $("hist").innerHTML = rows.length ? rows.map(r => `
    <tr class="${+r.intruded ? "intr" : ""}" data-drone="${esc(r.uas_id)}" title="${esc(r.uas_id)}: click for details and to rename">
      <td>${esc(label(r.uas_id))}</td><td>${esc(r.maker || "–")}</td><td>${esc(r.ua_type || "–")}</td>
      <td>${esc(r.first_seen.replace("T", " "))}</td><td>${esc(r.last_seen.replace("T", " "))}</td>
      <td>${r.hits}</td><td>${fmt(r.closest, " m")}</td><td>${fmt(r.max_height, " m")}</td>
      <td>${r.op_lat != null ? `<a style="color:var(--op)" target="_blank"
           href="https://www.openstreetmap.org/?mlat=${r.op_lat}&mlon=${r.op_lon}#map=18/${r.op_lat}/${r.op_lon}">
           ${(+r.op_lat).toFixed(5)}, ${(+r.op_lon).toFixed(5)}</a>` : "–"}</td>
      <td>${esc(r.self_id || "")}</td>
      <td>${mailButton(r.uas_id)}</td>
    </tr>`).join("") : `<tr><td colspan="11" class="empty">Nothing logged in this period</td></tr>`;
}

// ----- Email report buttons -----
// State is kept per drone so it survives the list and history re-rendering.
const mailState = {};   // drone -> {label, cls, title, busy}
function mailButton(drone) {
  const st = mailState[drone] || { label: "✉ EMAIL REPORT", cls: "", title: "Email a report on this drone" };
  return `<button class="mail-btn ${st.cls}" data-drone="${esc(drone)}" title="${esc(st.title)}"
           ${st.busy ? "disabled" : ""}>${esc(st.label)}</button>`;
}
function setMail(drone, st) {
  mailState[drone] = st;
  document.querySelectorAll(".mail-btn").forEach(b => {
    if (b.dataset.drone !== drone) return;
    b.textContent = st.label; b.title = st.title; b.disabled = !!st.busy;
    b.className = "mail-btn " + st.cls;
  });
}
async function sendReport(drone) {
  if (mailState[drone]?.busy) return;
  setMail(drone, { label: "SENDING…", cls: "", title: "Waiting for the scanner", busy: true });
  try {
    const r = await (await fetch("?api=report", { method: "POST",
      headers: { "Content-Type": "application/json", "X-DroneWatch": "1" },
      body: JSON.stringify({ drone }) })).json();
    if (r.error) throw new Error(r.error);
    for (let i = 0; i < 45; i++) {               // the scanner picks it up within ~2 s
      await new Promise(res => setTimeout(res, 1500));
      const s = await (await fetch("?api=report_status&id=" + r.id, { cache: "no-store" })).json();
      if (s.pending) continue;
      if (!s.ok) throw new Error(s.message || "failed");
      setMail(drone, { label: "✓ SENT", cls: "ok", title: s.message });
      setTimeout(() => { delete mailState[drone]; setMail(drone, mailState[drone] ||
        { label: "✉ EMAIL REPORT", cls: "", title: "Email a report on this drone" }); }, 60000);
      return;
    }
    throw new Error("no answer from the scanner");
  } catch (e) {
    setMail(drone, { label: "✕ FAILED", cls: "fail", title: e.message + " (click to retry)" });
  }
}
document.addEventListener("click", ev => {
  const b = ev.target.closest(".mail-btn");
  if (b) { ev.stopPropagation(); sendReport(b.dataset.drone); return; }
  if (ev.target.closest("a, .modal")) return;          // links and dialogs handle themselves
  const entry = ev.target.closest("[data-drone]");
  if (!entry) return;
  if (entry.dataset.drone) openDrone(entry.dataset.drone);
  else if (HAS_MAP && entry.dataset.lat) map.flyTo([+entry.dataset.lat, +entry.dataset.lon], 18);
});

// ----- Drone dialog: full serial, details, rename -----
let dmSerial = null;
function infoRow(k, v) { return v == null || v === "" ? "" : `<tr><td>${esc(k)}</td><td>${v}</td></tr>`; }
function openDrone(serial) {
  dmSerial = serial;
  const d = liveData[serial] || {}, h = histData[serial] || {};
  const inRange = liveIds.has(serial);
  $("dm-title").textContent = label(serial);
  $("dm-name").value = names[serial] || "";
  $("dm-msg").textContent = ""; $("dm-msg").className = "";
  $("dm-info").innerHTML = `<h4>DETAILS</h4><table>
    ${infoRow("Remote ID (full)", `<span class="mono">${esc(serial)}</span>`)}
    ${infoRow("ID type", esc(d.id_type || h.id_type || ""))}
    ${infoRow("Maker", esc(d.manufacturer || h.maker || ""))}
    ${infoRow("Type", esc(d.ua_type || h.ua_type || ""))}
    ${infoRow("Pilot's note", esc(d.self_id || h.self_id || ""))}
    ${infoRow("First seen", h.first_seen ? esc(h.first_seen.replace("T", " ")) : "")}
    ${infoRow("Last seen", h.last_seen ? esc(h.last_seen.replace("T", " ")) : "")}
    ${infoRow("Log entries", h.hits)}
    ${infoRow("Closest to home", h.closest != null ? fmt(h.closest, " m") : (d.dist_home_m != null ? fmt(d.dist_home_m, " m") : ""))}
    ${infoRow("Max height", h.max_height != null ? fmt(h.max_height, " m") : "")}
    ${infoRow("In range now", inRange ? "yes" : "")}
  </table>`;
  const onMap = inRange && d.lat != null && HAS_MAP;
  $("dm-actions").innerHTML = mailButton(serial) +
    (onMap ? ` <button class="act-btn plain" id="dm-map">SHOW ON MAP</button>` : "") +
    (names[serial] ? ` <button class="act-btn plain" id="dm-clear">REMOVE NAME</button>` : "");
  if (onMap) $("dm-map").onclick = () => { closeDrone(); map.flyTo([d.lat, d.lon], 18); };
  if (names[serial]) $("dm-clear").onclick = () => renameDrone("");
  $("drone-modal").classList.add("open");
  setTimeout(() => $("dm-name").focus(), 50);
}
const closeDrone = () => { $("drone-modal").classList.remove("open"); dmSerial = null; };
$("dm-close").onclick = closeDrone;
$("drone-modal").onclick = ev => { if (ev.target === $("drone-modal")) closeDrone(); };
$("dm-save").onclick = () => renameDrone($("dm-name").value);
$("dm-name").addEventListener("keydown", ev => { if (ev.key === "Enter") renameDrone($("dm-name").value); });

async function renameDrone(name) {
  const serial = dmSerial; if (!serial) return;
  name = name.trim();
  const msg = (t, cls = "") => { $("dm-msg").textContent = t; $("dm-msg").className = cls; };
  msg("Saving…");
  try {
    const r = await (await fetch("?api=rename", { method: "POST",
      headers: { "Content-Type": "application/json", "X-DroneWatch": "1" },
      body: JSON.stringify({ drone: serial, name }) })).json();
    if (r.error) throw new Error(r.error);
    for (let i = 0; i < 20; i++) {
      await new Promise(res => setTimeout(res, 1000));
      const s = await (await fetch("?api=report_status&id=" + r.id, { cache: "no-store" })).json();
      if (s.pending) continue;
      if (!s.ok) throw new Error(s.message || "failed");
      if (name) names[serial] = name; else delete names[serial];
      poll(); loadHistory();
      if (dmSerial === serial) { openDrone(serial); msg(name ? "Saved: " + label(serial) : "Name removed", "ok"); }
      return;
    }
    throw new Error("no answer from the scanner");
  } catch (e) { msg("Not saved: " + e.message, "fail"); }
}

// ----- Configuration viewer -----
function cfgSection(title, obj) {
  if (!obj) return "";
  const val = v => v === null || v === undefined || v === "" ? "–"
    : typeof v === "boolean" ? (v ? "yes" : "no") : String(v);
  return `<h4>${esc(title)}</h4><table>${Object.entries(obj).map(([k, v]) =>
    `<tr><td>${esc(k.replace(/_/g, " "))}</td><td>${esc(val(v))}</td></tr>`).join("")}</table>`;
}
async function showConfig() {
  $("cfg-modal").classList.add("open");
  $("cfg-body").innerHTML = `<div class="empty">Loading…</div>`;
  try {
    const c = await (await fetch("?api=config", { cache: "no-store" })).json();
    if (c.error) throw new Error(c.error);
    const s = c.scanner;
    $("cfg-body").innerHTML = s ? [
      cfgSection("SCANNER", { running: c.running, config_file: s.config_file, data_dir: s.data_dir,
                              bluetooth: s.receivers.bluetooth, wifi: s.receivers.wifi }),
      cfgSection("HOME AREA", s.home),
      cfgSection("ALERTS", s.alerts),
      cfgSection("EMAIL", s.email),
      cfgSection("DASHBOARD", c.dashboard),
    ].join("") : `<div class="empty">The scanner hasn't published its settings yet.
      Update drone_watch.py and restart it.</div>` + cfgSection("DASHBOARD", c.dashboard);
  } catch (e) {
    $("cfg-body").innerHTML = `<div class="empty">Could not load: ${esc(e.message)}</div>`;
  }
}
const closeConfig = () => $("cfg-modal").classList.remove("open");
$("cfg-open").onclick = showConfig;
$("cfg-close").onclick = closeConfig;
$("cfg-modal").onclick = ev => { if (ev.target === $("cfg-modal")) closeConfig(); };
document.addEventListener("keydown", ev => { if (ev.key === "Escape") { closeConfig(); closeDrone(); } });

$("hours").onchange = loadHistory;
setInterval(() => $("clock").textContent = new Date().toLocaleTimeString(), 1000);
poll(); setInterval(poll, 2000);
loadHistory(); setInterval(loadHistory, 30000);
</script>
</body>
</html>
