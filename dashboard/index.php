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
        $q = $pdo->prepare("SELECT uas_id, MAX(manufacturer) AS maker, MAX(ua_type) AS ua_type,
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

  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th { color: var(--dim); font-weight: 500; font-size: 10px; letter-spacing: .15em; }
  tr.intr td:first-child { color: var(--alert); }
  .wide { grid-column: 1 / -1; }
  .tbl { overflow-x: auto; }
  select, a.btn { background: #000; color: var(--text); border: 1px solid var(--line); border-radius: 6px;
                  padding: 3px 8px; font: inherit; font-size: 11px; text-decoration: none; }
  footer { padding: 4px 22px 22px; color: var(--dim); font-size: 11px; }
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
        <th>HITS</th><th>CLOSEST</th><th>MAX HEIGHT</th><th>OPERATOR LOCATION</th><th>NOTE</th></tr></thead>
      <tbody id="hist"></tbody>
    </table></div>
  </section>
</main>
<footer>Owner identity is not part of the Remote ID broadcast. Give the serial number, times and operator
location to local police, who can query the FAA. Rows in red came inside your alert radius.</footer>

<script>
const $ = id => document.getElementById(id);
const fmt = (v, u = "", d = 0) => v == null ? "–" : Number(v).toFixed(d) + u;
const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

const map = L.map("map", { zoomControl: true }).setView([45.41, -122.70], 16);
// OpenStreetMap tiles (no API key), darkened with a CSS filter to match the theme.
L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 19, className: "dark-tiles", attribution: "© OpenStreetMap contributors" }).addTo(map);

let homeLayer = null, homeKey = null;
const layers = {};   // mac -> {drone, op, link, trail}

const droneIcon = inside => L.divIcon({ className: "", iconSize: [22, 22], iconAnchor: [11, 11],
  html: `<div style="width:22px;height:22px;border-radius:50%;border:2px solid ${inside ? "#ff4d5e" : "#2ef2b4"};
         box-shadow:0 0 14px ${inside ? "#ff4d5e" : "#2ef2b4"};background:#000a;display:grid;place-items:center;
         font-size:12px;color:#fff">✦</div>` });
const opIcon = L.divIcon({ className: "", iconSize: [14, 14], iconAnchor: [7, 7],
  html: `<div style="width:14px;height:14px;transform:rotate(45deg);background:#58a6ff;box-shadow:0 0 10px #58a6ff"></div>` });

function drawHome(home, radius) {
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
      .bindPopup(`<b>${esc(d.uas_id || d.mac)}</b><br>${esc(d.manufacturer || "")} ${esc(d.ua_type || "")}<br>
        Height ${fmt(d.height_m, " m")} · ${fmt(d.speed_ms, " m/s", 1)}<br>${fmt(d.dist_home_m, " m from home")}`);
    l.trail.setLatLngs(trails[d.mac] || []);
    l.trail.setStyle({ color: d.inside ? "#ff4d5e" : "#2ef2b4" });
    if (d.op_lat != null) {
      const op = [d.op_lat, d.op_lon];
      if (!l.op) l.op = L.marker(op, { icon: opIcon }).addTo(map);
      l.op.setLatLng(op).bindTooltip("Operator / takeoff: " + esc(d.uas_id || d.mac));
      l.link.setLatLngs([op, pos]);
    }
  }
  for (const mac in layers) if (!seen.has(mac)) {
    const l = layers[mac];
    [l.drone, l.trail, l.link, l.op].forEach(x => x && map.removeLayer(x));
    delete layers[mac];
  }
}

function updateList(drones) {
  if (!drones.length) { $("live").innerHTML = `<div class="empty">No drones in range</div>`; return; }
  drones.sort((a, b) => (a.dist_home_m ?? 1e9) - (b.dist_home_m ?? 1e9));
  $("live").innerHTML = drones.map(d => `
    <div class="drone ${d.inside ? "inside" : ""}" data-lat="${d.lat ?? ""}" data-lon="${d.lon ?? ""}">
      <div class="id">${esc(d.uas_id || "(waiting for ID) " + d.mac)}
        <em>${d.inside ? "INSIDE RADIUS" : esc(d.status || "—")}</em></div>
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
  document.querySelectorAll(".drone").forEach(el => el.onclick = () => {
    if (el.dataset.lat) map.flyTo([+el.dataset.lat, +el.dataset.lon], 18);
  });
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

async function loadHistory() {
  const rows = await (await fetch("?api=history&hours=" + $("hours").value)).json();
  if (rows.error) {
    $("hist").innerHTML = `<tr><td colspan="10" class="empty">Server error: ${esc(rows.error)}</td></tr>`;
    return;
  }
  $("hist").innerHTML = rows.length ? rows.map(r => `
    <tr class="${+r.intruded ? "intr" : ""}">
      <td>${esc(r.uas_id)}</td><td>${esc(r.maker || "–")}</td><td>${esc(r.ua_type || "–")}</td>
      <td>${esc(r.first_seen.replace("T", " "))}</td><td>${esc(r.last_seen.replace("T", " "))}</td>
      <td>${r.hits}</td><td>${fmt(r.closest, " m")}</td><td>${fmt(r.max_height, " m")}</td>
      <td>${r.op_lat != null ? `<a style="color:var(--op)" target="_blank"
           href="https://www.openstreetmap.org/?mlat=${r.op_lat}&mlon=${r.op_lon}#map=18/${r.op_lat}/${r.op_lon}">
           ${(+r.op_lat).toFixed(5)}, ${(+r.op_lon).toFixed(5)}</a>` : "–"}</td>
      <td>${esc(r.self_id || "")}</td>
    </tr>`).join("") : `<tr><td colspan="10" class="empty">Nothing logged in this period</td></tr>`;
}

$("hours").onchange = loadHistory;
setInterval(() => $("clock").textContent = new Date().toLocaleTimeString(), 1000);
poll(); setInterval(poll, 2000);
loadHistory(); setInterval(loadHistory, 30000);
</script>
</body>
</html>
