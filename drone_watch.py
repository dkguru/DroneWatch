#!/usr/bin/env python3
# SPDX-License-Identifier: CC-BY-ND-4.0
# Copyright (c) 2026 Kianna Angelo and Jesper Angelo <kj@angelo.net>
# Idea by Kianna Angelo
# https://github.com/dkguru/DroneWatch
# Version 1.0.0, last updated 2026-11-22
"""
drone_watch.py - Remote ID drone monitor for Raspberry Pi.

Listens for FAA / ASTM F3411 "Open Drone ID" broadcasts over:
  * Bluetooth LE (legacy advertising)  - works with the Pi's built-in Bluetooth
  * Wi-Fi Beacon frames                - needs a USB Wi-Fi adapter in monitor mode

For every drone heard it records: Remote ID serial / session ID, manufacturer
(from the serial prefix), drone position, altitude, speed, heading, operator
(control station / takeoff) position, distance from your home, and RSSI.
Everything is logged to SQLite so you have a record you can hand to the FAA
or police.

Owner lookup: the FAA does NOT offer public owner lookup by Remote ID serial.
Only authorized law enforcement can resolve a serial to an owner (FAA DiSCVR).
This tool gives you the serial + operator location + timestamps they need.

Settings (home location, radius, email alerts) live in /etc/drone_watch.conf.
See drone_watch.conf.example. --home / --radius on the command line override it.

Usage:
  sudo python3 drone_watch.py                        # uses /etc/drone_watch.conf
  sudo python3 drone_watch.py --wifi wlan1           # add Wi-Fi reception
  sudo python3 drone_watch.py --test-email           # check the email settings
  python3 drone_watch.py --report                    # show history
"""

__version__ = "1.0.0"
__updated__ = "2026-11-22"

import argparse
import asyncio
import math
import os
import sqlite3
import struct
import subprocess
import sys
import threading
import time
from datetime import datetime

import configparser
import html
import re
import json
import smtplib
import ssl
from email.message import EmailMessage

# Shared with the PHP dashboard (dashboard/index.php). Override with DRONE_WATCH_DIR.
DATA_DIR = os.environ.get("DRONE_WATCH_DIR", "/var/lib/drone_watch")
DB_PATH = os.path.join(DATA_DIR, "drone_watch.db")
STATUS_PATH = os.path.join(DATA_DIR, "status.json")
REQUEST_DIR = os.path.join(DATA_DIR, "requests")   # dashboard drops report requests here
RESULT_DIR = os.path.join(DATA_DIR, "results")     # scanner writes the outcome here
ODID_UUID = "0000fffa-0000-1000-8000-00805f9b34fb"
ODID_APP_CODE = 0x0D
WIFI_OUI = bytes.fromhex("FA0BBC")          # ASTM F3411 Wi-Fi OUI
STALE_AFTER = 60                            # seconds before a drone drops off the live list

# ANSI/CTA-2063-A manufacturer codes (first 4 chars of the serial). Add more as you see them.
MANUFACTURERS = {
    "1581": "DJI",
    "1748": "Autel Robotics",
    "1668": "Skydio",
    "1701": "Percepto",
    "1587": "AgEagle",
}

UA_TYPES = ["None", "Aeroplane", "Multirotor", "Gyroplane", "VTOL", "Ornithopter",
            "Glider", "Kite", "Free balloon", "Captive balloon", "Airship",
            "Parachute", "Rocket", "Tethered", "Ground obstacle", "Other"]
ID_TYPES = ["None", "Serial number", "CAA registration", "UTM UUID", "Session ID"]
STATUS = ["Undeclared", "Ground", "Airborne", "EMERGENCY", "RID FAILURE"]


# --------------------------------------------------------------------------
# ASTM F3411 message decoding (each message is 25 bytes)
# --------------------------------------------------------------------------
def _lat_lon(raw):
    v = struct.unpack("<i", raw)[0] * 1e-7
    return v if v != 0 else None


def _alt(raw):
    v = struct.unpack("<H", raw)[0]
    return None if v == 0 else v * 0.5 - 1000.0


def decode_message(msg, out):
    """Decode one 25-byte ODID message into the dict `out`."""
    if len(msg) < 25:
        return
    mtype = msg[0] >> 4

    if mtype == 0x0:  # Basic ID
        id_type, ua_type = msg[1] >> 4, msg[1] & 0x0F
        uas_id = msg[2:22].split(b"\x00")[0].decode("ascii", "replace").strip()
        if uas_id:
            out["uas_id"] = uas_id
            out["id_type"] = ID_TYPES[id_type] if id_type < len(ID_TYPES) else str(id_type)
            out["ua_type"] = UA_TYPES[ua_type]
            if id_type == 1:
                out["manufacturer"] = MANUFACTURERS.get(uas_id[:4], f"code {uas_id[:4]}")

    elif mtype == 0x1:  # Location / vector
        flags = msg[1]
        status = flags >> 4
        ew = (flags >> 1) & 1
        mult = flags & 1
        direction = msg[2] + (180 if ew else 0)
        speed = msg[3] * 0.75 + 255 * 0.25 if mult else msg[3] * 0.25
        vspeed = struct.unpack("<b", msg[4:5])[0] * 0.5
        out.update({
            "status": STATUS[status] if status < len(STATUS) else str(status),
            "heading": direction if direction <= 360 else None,
            "speed_ms": round(speed, 2) if msg[3] != 255 else None,
            "vspeed_ms": vspeed if msg[4] != 63 else None,
            "lat": _lat_lon(msg[5:9]),
            "lon": _lat_lon(msg[9:13]),
            "alt_baro_m": _alt(msg[13:15]),
            "alt_geo_m": _alt(msg[15:17]),
            "height_m": _alt(msg[17:19]),
        })

    elif mtype == 0x3:  # Self-ID (free text, e.g. "Real estate photos")
        text = msg[2:25].split(b"\x00")[0].decode("ascii", "replace").strip()
        if text:
            out["self_id"] = text

    elif mtype == 0x4:  # System: operator / takeoff location
        out["op_lat"] = _lat_lon(msg[2:6])
        out["op_lon"] = _lat_lon(msg[6:10])
        out["op_loc_type"] = ["Takeoff", "Live GNSS", "Fixed"][msg[1] & 0x03] \
            if (msg[1] & 0x03) < 3 else None

    elif mtype == 0x5:  # Operator ID (required in EU, usually blank in US)
        op = msg[2:22].split(b"\x00")[0].decode("ascii", "replace").strip()
        if op:
            out["operator_id"] = op

    elif mtype == 0xF:  # Message pack - several messages bundled
        size, count = msg[1], msg[2]
        if size != 25:
            return
        for i in range(min(count, 9)):
            decode_message(msg[3 + i * 25: 3 + (i + 1) * 25], out)


def decode_pack_bytes(data):
    """Data may be a single 25-byte message or a message pack (variable length)."""
    out = {}
    if data and (data[0] >> 4) == 0xF:
        # Message pack header is 3 bytes then N*25; pad so decode_message accepts it
        decode_message(data + b"\x00" * max(0, 25 - len(data)), out)
    else:
        decode_message(data, out)
    return out


# --------------------------------------------------------------------------
# Tracking + storage
# --------------------------------------------------------------------------
def haversine_m(lat1, lon1, lat2, lon2):
    r = 6371000
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


# --------------------------------------------------------------------------
# Configuration file (/etc/drone_watch.conf) - see drone_watch.conf.example
# --------------------------------------------------------------------------
DEFAULT_CONFIG = "/etc/drone_watch.conf"


def load_config(path):
    cfg = configparser.ConfigParser()
    cfg.read_dict({
        "home": {"lat": "", "lon": "", "radius_m": "150"},
        "alerts": {"enabled": "no", "new_after_minutes": "30", "wait_for_id_seconds": "15"},
        "email": {"smtp_host": "", "smtp_port": "587", "security": "starttls",
                  "username": "", "password": "", "from": "", "to": "", "dashboard_url": ""},
    })
    if path and os.path.exists(path):
        cfg.read(path)
    return cfg


def send_email(cfg, subject, body, html_body=None):
    """Send an email (plain text, plus optional HTML) using the [email] section. Raises on failure."""
    e = cfg["email"]
    recipients = [x.strip() for x in e.get("to").split(",") if x.strip()]
    if not e.get("smtp_host") or not recipients:
        raise ValueError("email not configured: set smtp_host and to in the [email] section")
    msg = EmailMessage()
    msg["Subject"] = subject
    msg["From"] = e.get("from") or e.get("username")
    msg["To"] = ", ".join(recipients)
    msg.set_content(body)
    if html_body:
        msg.add_alternative(html_body, subtype="html")
    host, port, sec = e.get("smtp_host"), e.getint("smtp_port"), e.get("security").lower()
    ctx = ssl.create_default_context()
    if sec == "ssl":
        server = smtplib.SMTP_SSL(host, port, context=ctx, timeout=20)
    else:
        server = smtplib.SMTP(host, port, timeout=20)
        if sec == "starttls":
            server.starttls(context=ctx)
    with server:
        if e.get("username"):
            server.login(e.get("username"), e.get("password"))
        server.send_message(msg)


def display_name(name, serial):
    """'My Skydio 2 (...W52F)' when the drone has a name, otherwise the plain serial."""
    if name and serial:
        return f"{name} (...{serial[-4:]})"
    return serial or name or "unknown"


def maps_link(lat, lon):
    return f"https://www.openstreetmap.org/?mlat={lat:.6f}&mlon={lon:.6f}#map=18/{lat:.6f}/{lon:.6f}"


class Alerter:
    """
    Emails you when a NEW drone enters the home radius.

    "New" = this drone has not been inside the radius during the last
    `new_after_minutes` minutes (default 30). A drone that keeps circling
    triggers one email; if it leaves and returns after the window, it
    triggers another.

    Drones are identified by their Remote ID serial. Location messages often
    arrive before the serial, so the alert waits up to `wait_for_id_seconds`
    for the serial before falling back to the radio MAC address.
    """

    def __init__(self, cfg, db, names=None):
        a = cfg["alerts"]
        self.names = names if names is not None else {}   # serial -> name, shared with the Tracker
        self.cfg, self.db = cfg, db
        self.enabled = a.getboolean("enabled")
        self.window = a.getfloat("new_after_minutes") * 60
        self.wait_for_id = a.getfloat("wait_for_id_seconds")
        self.last_inside = {}    # drone key -> last time seen inside the radius
        self.waiting = {}        # mac -> time it was first seen inside without a serial
        self.alerted_mac = {}    # mac -> time an alert was sent under the MAC fallback
        self.db.execute("""CREATE TABLE IF NOT EXISTS alerts (
            ts TEXT, drone TEXT, mac TEXT, dist_home_m REAL, result TEXT)""")
        self.db.commit()
        # Seed from the log so a restart doesn't re-alert drones already reported.
        rows = self.db.execute(
            "SELECT uas_id, MAX(ts) FROM sightings WHERE inside_radius = 1 AND uas_id IS NOT NULL "
            "AND datetime(ts) >= datetime('now','localtime', ?) GROUP BY uas_id",
            (f"-{int(self.window)} seconds",)).fetchall()
        for uas_id, ts in rows:
            self.last_inside[uas_id] = datetime.fromisoformat(ts).timestamp()

    def check(self, d, now):
        """Call (under the tracker lock) every time a drone is seen inside the radius."""
        mac, key = d["mac"], d.get("uas_id")
        if not key:
            first = self.waiting.setdefault(mac, now)
            if now - first < self.wait_for_id:
                return                      # give the serial a moment to arrive
            key = "MAC " + mac
        else:
            self.waiting.pop(mac, None)
            # Already alerted for this radio under its MAC: don't alert again for the serial.
            if key not in self.last_inside and now - self.alerted_mac.get(mac, -1e12) < self.window:
                self.last_inside[key] = now
                return

        last = self.last_inside.get(key)
        self.last_inside[key] = now
        if last is not None and now - last <= self.window:
            return                          # still the same visit: already reported

        if key.startswith("MAC "):
            self.alerted_mac[mac] = now
        snapshot = dict(d)
        result = "sending" if self.enabled else "disabled"
        self.db.execute("INSERT INTO alerts VALUES (?,?,?,?,?)", (
            datetime.now().isoformat(timespec="seconds"), key, mac, d.get("dist_home_m"), result))
        self.db.commit()
        print(f"[alert] new drone in home area: {key} ({result})", flush=True)
        if self.enabled:
            threading.Thread(target=self._send, args=(key, snapshot), daemon=True).start()

    def _send(self, key, d):
        when = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        maker = d.get("manufacturer") or "Unknown maker"
        name = self.names.get(key)
        label = display_name(name, key) if name else f"{maker} {key}"
        subject = f"Drone over home: {label} at {datetime.now():%H:%M}"
        lines = [
            f"A drone entered your home area at {when}.",
            "",
            *([f"Name:          {name}"] if name else []),
            f"Remote ID:     {key}  ({d.get('id_type', 'ID type unknown')})",
            f"Maker / type:  {maker} / {d.get('ua_type', '-')}",
            f"Status:        {d.get('status', '-')}",
            f"Distance:      {d['dist_home_m']:.0f} m from home" if d.get("dist_home_m") is not None else "Distance: -",
            f"Height:        {d['height_m']:.0f} m above takeoff" if d.get("height_m") is not None else "Height: -",
            f"Speed:         {d['speed_ms']:.1f} m/s" if d.get("speed_ms") is not None else "Speed: -",
            f"Signal:        {d.get('source', '-')} {d.get('rssi', '')} dBm",
        ]
        if d.get("self_id"):
            lines.append(f"Pilot's note:  {d['self_id']}")
        if d.get("lat") is not None:
            lines.append(f"Drone position:    {maps_link(d['lat'], d['lon'])}")
        if d.get("op_lat") is not None:
            lines.append(f"Operator / takeoff: {maps_link(d['op_lat'], d['op_lon'])}")
        url = self.cfg["email"].get("dashboard_url")
        if url:
            lines += ["", f"Live dashboard: {url}"]
        lines += ["", "Owner identity is not part of the broadcast. Police can look up the",
                  "Remote ID serial through the FAA if you report the flight."]
        try:
            send_email(self.cfg, subject, "\n".join(lines))
            print(f"[alert] email sent for {key}", flush=True)
        except Exception as exc:  # never let a mail problem stop the scanner
            print(f"[alert] email FAILED for {key}: {exc}", file=sys.stderr, flush=True)


# --------------------------------------------------------------------------
# Settings shown on the dashboard (the mail password is never included)
# --------------------------------------------------------------------------
def public_config(cfg, path, home, radius, wifi, ble):
    e, a = cfg["email"], cfg["alerts"]
    return {
        "config_file": path if path and os.path.exists(path) else f"{path} (not found, using defaults)",
        "data_dir": DATA_DIR,
        "receivers": {"bluetooth": ble, "wifi": wifi or "off"},
        "home": {"lat": home[0] if home else None, "lon": home[1] if home else None,
                 "radius_m": radius},
        "alerts": {"enabled": a.getboolean("enabled"),
                   "new_after_minutes": a.getfloat("new_after_minutes"),
                   "wait_for_id_seconds": a.getfloat("wait_for_id_seconds")},
        "email": {"smtp_host": e.get("smtp_host"), "smtp_port": e.get("smtp_port"),
                  "security": e.get("security"), "username": e.get("username"),
                  "password": "set (hidden)" if e.get("password") else "not set",
                  "from": e.get("from"), "to": e.get("to"),
                  "dashboard_url": e.get("dashboard_url")},
    }


# --------------------------------------------------------------------------
# Emailed sighting reports, requested from the dashboard
# --------------------------------------------------------------------------
REQUEST_ID = re.compile(r"[a-f0-9]{24}")
DRONE_KEY = re.compile(r"[A-Za-z0-9 :._-]{1,40}")
DRONE_NAME = re.compile(r"[^\x00-\x1f\x7f]{0,40}")     # any printable text, max 40 characters
REPORTS_PER_HOUR = 20


def _fmt_dur(seconds):
    seconds = int(seconds)
    if seconds < 60:
        return f"{seconds} s"
    m, s = divmod(seconds, 60)
    if m < 60:
        return f"{m} min {s:02d} s"
    h, m = divmod(m, 60)
    return f"{h} h {m:02d} min"


def _num(v, unit, digits=0):
    return "–" if v is None else f"{v:.{digits}f} {unit}"


def collect_visits(rows, gap_seconds):
    """Group sightings (sorted by time) into visits split by gaps longer than gap_seconds."""
    visits, cur = [], None
    for r in rows:
        t = datetime.fromisoformat(r["ts"])
        if cur is None or (t - cur["end"]).total_seconds() > gap_seconds:
            cur = {"start": t, "end": t, "rows": []}
            visits.append(cur)
        cur["end"] = t
        cur["rows"].append(r)
    for v in visits:
        rs = v["rows"]
        dists = [r for r in rs if r["dist_home_m"] is not None]
        closest = min(dists, key=lambda r: r["dist_home_m"]) if dists else None
        ops = [r for r in rs if r["op_lat"] is not None]
        v.update(
            duration=(v["end"] - v["start"]).total_seconds(),
            samples=len(rs),
            closest=closest["dist_home_m"] if closest else None,
            closest_pos=(closest["lat"], closest["lon"]) if closest and closest["lat"] else None,
            max_height=max((r["height_m"] for r in rs if r["height_m"] is not None), default=None),
            max_speed=max((r["speed_ms"] for r in rs if r["speed_ms"] is not None), default=None),
            inside=any(r["inside_radius"] for r in rs),
            operator=(ops[-1]["op_lat"], ops[-1]["op_lon"]) if ops else None,
        )
    return visits


def build_report(db, lock, key, cfg):
    """Return (subject, text, html) for one drone, or raise LookupError."""
    with lock:
        cur = db.execute("""SELECT ts, lat, lon, dist_home_m, height_m, speed_ms, inside_radius,
                                   op_lat, op_lon, manufacturer, ua_type, id_type, self_id,
                                   operator_id, source, rssi
                            FROM sightings WHERE uas_id = ? ORDER BY ts""", (key,))
        cols = [c[0] for c in cur.description]
        rows = [dict(zip(cols, r)) for r in cur.fetchall()]
    if not rows:
        raise LookupError(f"no sightings logged for {key}")
    with lock:
        found = db.execute("SELECT name FROM drone_names WHERE uas_id = ?", (key,)).fetchone()
    name = found[0] if found else None
    label = display_name(name, key)

    gap = cfg["alerts"].getfloat("new_after_minutes") * 60
    visits = collect_visits(rows, gap)
    latest, previous = visits[-1], list(reversed(visits[:-1]))

    def last(field):
        return next((r[field] for r in reversed(rows) if r[field]), None)

    maker, ua_type = last("manufacturer") or "Unknown maker", last("ua_type") or "–"
    notes, op_id, id_type = last("self_id"), last("operator_id"), last("id_type") or "–"
    inside_count = sum(1 for v in visits if v["inside"])
    when = latest["start"].strftime("%a %d %b %Y, %H:%M")
    subject = f"Drone sighting report: {label if name else maker + ' ' + key} ({when})"
    dash = cfg["email"].get("dashboard_url")
    now_txt = datetime.now().strftime("%Y-%m-%d %H:%M")

    # ---------- plain-text version ----------
    t = [f"DRONE WATCH - SIGHTING REPORT ({now_txt})", ""]
    if name:
        t.append(f"Name:         {name}")
    t += [f"Remote ID:    {key} ({id_type})", f"Maker/type:   {maker} / {ua_type}"]
    if notes:
        t.append(f"Pilot's note: {notes}")
    if op_id:
        t.append(f"Operator ID:  {op_id}")
    t += ["", "LATEST SIGHTING",
          f"  When:            {latest['start']:%Y-%m-%d %H:%M:%S} - {latest['end']:%H:%M:%S} ({_fmt_dur(latest['duration'])})",
          f"  Closest to home: {_num(latest['closest'], 'm')}",
          f"  Max height:      {_num(latest['max_height'], 'm')}",
          f"  Max speed:       {_num(latest['max_speed'], 'm/s', 1)}",
          f"  Home area:       {'ENTERED' if latest['inside'] else 'not entered'}"]
    if latest["closest_pos"]:
        t.append(f"  Closest point:   {maps_link(*latest['closest_pos'])}")
    if latest["operator"]:
        t.append(f"  Operator:        {maps_link(*latest['operator'])}")
    t += ["", f"TOTALS: {len(visits)} sighting(s) since {visits[0]['start']:%Y-%m-%d}, "
              f"{inside_count} inside the home area", "", "PREVIOUS SIGHTINGS"]
    if previous:
        for v in previous[:50]:
            t.append(f"  {v['start']:%Y-%m-%d %H:%M}  {_fmt_dur(v['duration']):>12}  "
                     f"closest {_num(v['closest'], 'm'):>7}  max {_num(v['max_height'], 'm'):>6}"
                     f"{'  INSIDE' if v['inside'] else ''}")
    else:
        t.append("  None - this is the first time this drone has been logged.")
    if dash:
        t += ["", f"Live dashboard: {dash}"]
    t += ["", "Owner identity is not part of the Remote ID broadcast. Police can look up",
          "the serial through the FAA (DiSCVR) if you report the flight."]

    # ---------- HTML version (inline styles: email clients ignore <style>) ----------
    E = html.escape
    accent, alert, dim, line = "#14b88a", "#e5484d", "#6b7a78", "#e6ecea"

    def link(pos, label):
        return f'<a href="{E(maps_link(*pos))}" style="color:{accent};text-decoration:none">{E(label)}</a>' if pos else "–"

    def stat(label, value, colour="#0d1417"):
        return (f'<td style="padding:14px 10px;text-align:center;border-right:1px solid {line}">'
                f'<div style="font-size:22px;font-weight:700;color:{colour}">{E(value)}</div>'
                f'<div style="font-size:10px;letter-spacing:1.5px;color:{dim};margin-top:4px">{E(label)}</div></td>')

    def kv(label, value_html):
        return (f'<tr><td style="padding:7px 0;color:{dim};font-size:13px;width:150px">{E(label)}</td>'
                f'<td style="padding:7px 0;font-size:13px">{value_html}</td></tr>')

    badge = (f'<span style="background:{alert};color:#fff;border-radius:4px;padding:3px 8px;'
             f'font-size:11px;letter-spacing:1px">ENTERED HOME AREA</span>' if latest["inside"] else
             f'<span style="background:{line};color:{dim};border-radius:4px;padding:3px 8px;'
             f'font-size:11px;letter-spacing:1px">PASSED NEARBY</span>')

    yes_html = f'<b style="color:{alert}">Yes</b>'
    prev_rows = "".join(
        f'<tr style="border-top:1px solid {line}">'
        f'<td style="padding:8px 6px">{v["start"]:%d %b %Y}<br><span style="color:{dim}">{v["start"]:%H:%M}</span></td>'
        f'<td style="padding:8px 6px">{E(_fmt_dur(v["duration"]))}</td>'
        f'<td style="padding:8px 6px">{E(_num(v["closest"], "m"))}</td>'
        f'<td style="padding:8px 6px">{E(_num(v["max_height"], "m"))}</td>'
        f'<td style="padding:8px 6px">{yes_html if v["inside"] else "No"}</td>'
        f'<td style="padding:8px 6px">{link(v["operator"], "map")}</td></tr>'
        for v in previous[:50])
    if not previous:
        prev_rows = (f'<tr><td colspan="6" style="padding:14px 6px;color:{dim}">None. This is the first '
                     f'time this drone has been logged.</td></tr>')
    more = (f'<p style="color:{dim};font-size:12px">Showing the 50 most recent of {len(previous)} previous sightings.</p>'
            if len(previous) > 50 else "")

    details = "".join([
        kv("Name", f'<b>{E(name)}</b>') if name else "",
        kv("Remote ID", f'<b style="font-family:Menlo,Consolas,monospace">{E(key)}</b> <span style="color:{dim}">({E(id_type)})</span>'),
        kv("Maker / type", E(f"{maker} / {ua_type}")),
        kv("Pilot's note", E(notes)) if notes else "",
        kv("Operator ID", E(op_id)) if op_id else "",
        kv("When", E(f"{latest['start']:%A %d %B %Y}, {latest['start']:%H:%M:%S} – {latest['end']:%H:%M:%S}")),
        kv("Closest point", link(latest["closest_pos"], "Open in map")),
        kv("Operator / takeoff", link(latest["operator"], "Open in map")),
    ])

    html_body = f"""<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f3f6f5">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6f5;padding:24px 0">
<tr><td align="center">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0d1417">
  <tr><td style="background:#070b0d;padding:22px 28px">
    <div style="color:#2ef2b4;font-size:12px;letter-spacing:4px">&#9673; DRONE WATCH</div>
    <div style="color:#ffffff;font-size:22px;font-weight:600;margin-top:6px">Sighting report</div>
    <div style="color:#8fa9a5;font-size:13px;margin-top:4px">{E(label if name else maker + " · " + key)} &middot; generated {E(now_txt)}</div>
  </td></tr>
  <tr><td style="padding:22px 28px 6px">{badge}
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:14px">{details}</table>
  </td></tr>
  <tr><td style="padding:10px 28px">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid {line};border-radius:10px">
      <tr>{stat("CLOSEST", _num(latest["closest"], "m"), alert if latest["inside"] else "#0d1417")}
          {stat("MAX HEIGHT", _num(latest["max_height"], "m"))}
          {stat("MAX SPEED", _num(latest["max_speed"], "m/s", 1))}
          <td style="padding:14px 10px;text-align:center">
            <div style="font-size:22px;font-weight:700">{E(_fmt_dur(latest["duration"]))}</div>
            <div style="font-size:10px;letter-spacing:1.5px;color:{dim};margin-top:4px">DURATION</div></td></tr>
    </table>
  </td></tr>
  <tr><td style="padding:12px 28px 0;font-size:13px;color:{dim}">
    Seen <b style="color:#0d1417">{len(visits)}</b> time(s) since {visits[0]['start']:%d %b %Y},
    <b style="color:{alert if inside_count else '#0d1417'}">{inside_count}</b> inside the home area.
  </td></tr>
  <tr><td style="padding:18px 28px 6px">
    <div style="font-size:11px;letter-spacing:2px;color:{dim};margin-bottom:6px">PREVIOUS SIGHTINGS</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:12px">
      <tr style="color:{dim};font-size:10px;letter-spacing:1px;text-align:left">
        <th style="padding:6px;font-weight:500">DATE</th><th style="padding:6px;font-weight:500">DURATION</th>
        <th style="padding:6px;font-weight:500">CLOSEST</th><th style="padding:6px;font-weight:500">MAX HT</th>
        <th style="padding:6px;font-weight:500">INSIDE</th><th style="padding:6px;font-weight:500">OPERATOR</th></tr>
      {prev_rows}
    </table>{more}
  </td></tr>
  {f'<tr><td style="padding:14px 28px"><a href="{E(dash)}" style="display:inline-block;background:#070b0d;color:#2ef2b4;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:13px;letter-spacing:1px">OPEN LIVE DASHBOARD</a></td></tr>' if dash else ''}
  <tr><td style="padding:18px 28px 24px;font-size:11px;color:{dim};border-top:1px solid {line}">
    Owner identity is not part of the Remote ID broadcast. To report the flight, give the serial number,
    times and operator location to local police, who can look up the owner through the FAA (DiSCVR).<br><br>
    Drone Watch &copy; 2026 Kianna Angelo and Jesper Angelo &middot; CC BY-ND 4.0
  </td></tr>
</table></td></tr></table></body></html>"""
    return subject, "\n".join(t), html_body


class ReportService:
    """
    Picks up report requests the dashboard drops in REQUEST_DIR, emails the
    report to the configured recipients, and writes the outcome to RESULT_DIR.
    Reports only ever go to the [email] 'to' addresses, so the dashboard
    cannot be used to send mail anywhere else.
    """

    def __init__(self, tracker, cfg):
        self.tracker, self.cfg = tracker, cfg
        self.sent = []          # timestamps, for the hourly limit
        for d, mode in ((REQUEST_DIR, 0o1733), (RESULT_DIR, 0o755)):
            os.makedirs(d, exist_ok=True)
            os.chmod(d, mode)   # 1733: the web server may drop files but not list or delete others

    def poll(self):
        try:
            names = os.listdir(REQUEST_DIR)
        except OSError:
            return
        for name in names:
            path = os.path.join(REQUEST_DIR, name)
            rid = name[:-5] if name.endswith(".json") else None
            if not rid:
                if name.endswith(".tmp") and time.time() - os.path.getmtime(path) > 60:
                    os.remove(path)                 # abandoned half-written request
                continue
            try:
                with open(path) as f:
                    req = json.load(f)
            except (OSError, ValueError):
                req = None
            try:
                os.remove(path)
            except OSError:
                pass
            if not REQUEST_ID.fullmatch(rid):
                continue
            key = (req or {}).get("drone", "")
            if not isinstance(key, str) or not DRONE_KEY.fullmatch(key):
                self._result(rid, False, "invalid drone id")
                continue
            if req.get("type") == "rename":
                self._rename(rid, key, req.get("name", ""))
                continue
            now = time.time()
            self.sent = [t for t in self.sent if now - t < 3600]
            if len(self.sent) >= REPORTS_PER_HOUR:
                self._result(rid, False, f"limit of {REPORTS_PER_HOUR} reports per hour reached")
                continue
            self.sent.append(now)
            threading.Thread(target=self._send, args=(rid, key), daemon=True).start()
        self._clean_results()

    def _rename(self, rid, key, name):
        if not isinstance(name, str) or not DRONE_NAME.fullmatch(name.strip()):
            self._result(rid, False, "names can be up to 40 characters, without control characters")
            return
        name = name.strip()
        self.tracker.set_name(key, name)
        print(f"[name] {key} -> {name or '(name removed)'}", flush=True)
        self._result(rid, True, display_name(name, key) if name else f"name removed from {key}")

    def _send(self, rid, key):
        try:
            subject, text, html_body = build_report(self.tracker.db, self.tracker.lock, key, self.cfg)
            send_email(self.cfg, subject, text, html_body)
            to = self.cfg["email"].get("to")
            print(f"[report] {key} emailed to {to}", flush=True)
            self._result(rid, True, f"sent to {to}")
        except Exception as exc:
            print(f"[report] {key} FAILED: {exc}", file=sys.stderr, flush=True)
            self._result(rid, False, str(exc))

    @staticmethod
    def _result(rid, ok, message):
        tmp = os.path.join(RESULT_DIR, rid + ".tmp")
        with open(tmp, "w") as f:
            json.dump({"ok": ok, "message": message, "time": time.time()}, f)
        os.chmod(tmp, 0o644)
        os.replace(tmp, os.path.join(RESULT_DIR, rid + ".json"))

    @staticmethod
    def _clean_results():
        for name in os.listdir(RESULT_DIR):
            p = os.path.join(RESULT_DIR, name)
            try:
                if time.time() - os.path.getmtime(p) > 3600:
                    os.remove(p)
            except OSError:
                pass


class Tracker:
    def __init__(self, home, radius, db_path, cfg=None):
        self.home, self.radius = home, radius
        self.started = time.time()
        os.makedirs(os.path.dirname(db_path), exist_ok=True)
        os.chmod(os.path.dirname(db_path), 0o755)
        self.drones = {}            # key: transmitter MAC
        self.lock = threading.Lock()
        self.db = sqlite3.connect(db_path, check_same_thread=False)
        self.db.execute("""CREATE TABLE IF NOT EXISTS sightings (
            ts TEXT, mac TEXT, source TEXT, rssi INTEGER, uas_id TEXT, id_type TEXT,
            manufacturer TEXT, ua_type TEXT, status TEXT, lat REAL, lon REAL,
            alt_geo_m REAL, height_m REAL, speed_ms REAL, heading REAL,
            op_lat REAL, op_lon REAL, operator_id TEXT, self_id TEXT,
            dist_home_m REAL, inside_radius INTEGER)""")
        self.db.execute("""CREATE TABLE IF NOT EXISTS drone_names (
            uas_id TEXT PRIMARY KEY, name TEXT NOT NULL, updated TEXT)""")
        self.db.commit()
        os.chmod(db_path, 0o644)  # let the web server read it
        self._last_log = {}
        self.names = dict(self.db.execute("SELECT uas_id, name FROM drone_names"))
        self.alerter = Alerter(cfg or load_config(None), self.db, self.names)
        self.public_config = {}      # safe copy of the settings for the dashboard (no password)

    def set_name(self, serial, name):
        """Give a drone a friendly name (empty name removes it)."""
        with self.lock:
            if name:
                self.db.execute("INSERT INTO drone_names VALUES (?,?,?) ON CONFLICT(uas_id) "
                                "DO UPDATE SET name = excluded.name, updated = excluded.updated",
                                (serial, name, datetime.now().isoformat(timespec="seconds")))
                self.names[serial] = name
            else:
                self.db.execute("DELETE FROM drone_names WHERE uas_id = ?", (serial,))
                self.names.pop(serial, None)
            self.db.commit()

    def update(self, mac, source, rssi, fields):
        if not fields:
            return
        now = time.time()
        with self.lock:
            d = self.drones.setdefault(mac, {"mac": mac, "first_seen": now})
            d.update({k: v for k, v in fields.items() if v is not None})
            d.update(last_seen=now, source=source, rssi=rssi)
            if self.home and d.get("lat") and d.get("lon"):
                d["dist_home_m"] = haversine_m(*self.home, d["lat"], d["lon"])
                d["inside"] = d["dist_home_m"] <= self.radius
                if d["inside"]:
                    self.alerter.check(d, now)
            # Log at most once every 2 s per drone so the DB stays manageable
            if now - self._last_log.get(mac, 0) >= 2:
                self._last_log[mac] = now
                self._write(d)

    def _write(self, d):
        self.db.execute("INSERT INTO sightings VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", (
            datetime.now().isoformat(timespec="seconds"), d["mac"], d.get("source"),
            d.get("rssi"), d.get("uas_id"), d.get("id_type"), d.get("manufacturer"),
            d.get("ua_type"), d.get("status"), d.get("lat"), d.get("lon"),
            d.get("alt_geo_m"), d.get("height_m"), d.get("speed_ms"), d.get("heading"),
            d.get("op_lat"), d.get("op_lon"), d.get("operator_id"), d.get("self_id"),
            d.get("dist_home_m"), int(bool(d.get("inside")))))
        self.db.commit()

    def write_status(self, sources):
        """Heartbeat + live drone list for the web dashboard (atomic write)."""
        now = time.time()
        with self.lock:
            live = [dict(d, age=round(now - d["last_seen"], 1), name=self.names.get(d.get("uas_id")))
                    for d in self.drones.values()
                    if now - d["last_seen"] < STALE_AFTER]
        status = {"heartbeat": now, "started": self.started, "pid": os.getpid(),
                  "sources": sources, "home": self.home, "radius": self.radius,
                  "drones": live, "config": self.public_config,
                  "version": __version__, "updated": __updated__}
        tmp = STATUS_PATH + ".tmp"
        with open(tmp, "w") as f:
            json.dump(status, f)
        os.chmod(tmp, 0o644)
        os.replace(tmp, STATUS_PATH)

    def render(self):
        now = time.time()
        with self.lock:
            live = [d for d in self.drones.values() if now - d["last_seen"] < STALE_AFTER]
        os.system("clear")
        print(f"Drone Watch  {datetime.now():%Y-%m-%d %H:%M:%S}   "
              f"{len(live)} drone(s) in range   DB: {DB_PATH}")
        print("-" * 118)
        print(f"{'':2}{'Remote ID':<22}{'Maker':<10}{'Status':<10}{'Height':>7}"
              f"{'Speed':>7}{'Dist':>7}  {'Drone lat,lon':<22}{'Operator lat,lon':<22}{'RSSI':>5}")
        print("-" * 118)
        for d in sorted(live, key=lambda x: x.get("dist_home_m", 1e9)):
            flag = "!!" if d.get("inside") else "  "
            pos = f"{d['lat']:.5f},{d['lon']:.5f}" if d.get("lat") else "-"
            op = f"{d['op_lat']:.5f},{d['op_lon']:.5f}" if d.get("op_lat") else "-"
            h = f"{d['height_m']:.0f}m" if d.get("height_m") is not None else "-"
            s = f"{d['speed_ms']:.1f}" if d.get("speed_ms") is not None else "-"
            dist = f"{d['dist_home_m']:.0f}m" if d.get("dist_home_m") is not None else "-"
            label = display_name(self.names.get(d.get("uas_id")), d.get("uas_id")) if d.get("uas_id") else "(waiting)"
            print(f"{flag}{label[:21]:<22}{d.get('manufacturer', '-')[:9]:<10}"
                  f"{d.get('status', '-')[:9]:<10}{h:>7}{s:>7}{dist:>7}  {pos:<22}{op:<22}{d.get('rssi', 0):>5}")
            if d.get("self_id") or d.get("operator_id"):
                print(f"    note: {d.get('self_id', '')}  operator ID: {d.get('operator_id', '-')}")
        print("-" * 118)
        print("!! = inside your radius.  Owner identity is not broadcast; see --report for evidence export.")


# --------------------------------------------------------------------------
# Receivers
# --------------------------------------------------------------------------
async def ble_scan(tracker):
    from bleak import BleakScanner

    def cb(device, adv):
        data = adv.service_data.get(ODID_UUID)
        if not data or data[0] != ODID_APP_CODE:
            return
        tracker.update(device.address, "BLE", adv.rssi, decode_pack_bytes(data[2:]))

    scanner = BleakScanner(detection_callback=cb, scanning_mode="active")
    await scanner.start()
    while True:
        await asyncio.sleep(1)


def wifi_sniff(tracker, iface):
    from scapy.all import sniff, Dot11, Dot11Beacon, Dot11Elt, RadioTap  # noqa

    def handler(pkt):
        if not pkt.haslayer(Dot11Beacon):
            return
        elt = pkt.getlayer(Dot11Elt)
        while elt is not None:
            if elt.ID == 221 and bytes(elt.info[:3]) == WIFI_OUI and elt.info[3] == ODID_APP_CODE:
                rssi = getattr(pkt, "dBm_AntSignal", 0) or 0
                tracker.update(pkt.addr2, "WiFi", rssi, decode_pack_bytes(bytes(elt.info[5:])))
            elt = elt.payload.getlayer(Dot11Elt)

    # Hop the channels drones normally use (6 is the common default for beacons)
    def hop():
        chans = [6, 1, 11, 6, 149, 6]
        i = 0
        while True:
            subprocess.run(["iw", "dev", iface, "set", "channel", str(chans[i % len(chans)])],
                           stderr=subprocess.DEVNULL)
            i += 1
            time.sleep(0.5)

    threading.Thread(target=hop, daemon=True).start()
    sniff(iface=iface, prn=handler, store=False)


# --------------------------------------------------------------------------
def report(only_inside):
    db = sqlite3.connect(DB_PATH)
    q = """SELECT uas_id, manufacturer, MIN(ts), MAX(ts), COUNT(*),
                  MIN(dist_home_m), MAX(height_m), MAX(op_lat), MAX(op_lon)
           FROM sightings WHERE uas_id IS NOT NULL {} GROUP BY uas_id ORDER BY MAX(ts) DESC"""
    rows = db.execute(q.format("AND inside_radius=1" if only_inside else "")).fetchall()
    print(f"{'Remote ID':<24}{'Maker':<10}{'First seen':<21}{'Last seen':<21}"
          f"{'Hits':>5}{'Closest':>9}{'MaxHt':>7}  Operator location")
    for r in rows:
        op = f"{r[7]:.5f},{r[8]:.5f}" if r[7] else "-"
        close = f"{r[5]:.0f}m" if r[5] is not None else "-"
        ht = f"{r[6]:.0f}m" if r[6] is not None else "-"
        print(f"{r[0][:23]:<24}{(r[1] or '-')[:9]:<10}{r[2]:<21}{r[3]:<21}{r[4]:>5}{close:>9}{ht:>7}  {op}")


def main():
    ap = argparse.ArgumentParser(description="Remote ID drone monitor")
    ap.add_argument("--config", default=DEFAULT_CONFIG,
                    help=f"settings file (default {DEFAULT_CONFIG})")
    ap.add_argument("--home", help="property centre as lat,lon (overrides the config file)")
    ap.add_argument("--radius", type=float, help="home radius in metres (overrides the config file)")
    ap.add_argument("--test-email", action="store_true", help="send a test email and exit")
    ap.add_argument("--version", action="version", version=f"Drone Watch {__version__} ({__updated__})")
    ap.add_argument("--wifi", help="monitor-mode Wi-Fi interface, e.g. wlan1")
    ap.add_argument("--no-ble", action="store_true", help="disable Bluetooth scanning")
    ap.add_argument("--report", action="store_true", help="print history from the database")
    ap.add_argument("--inside-only", action="store_true", help="with --report: only drones inside radius")
    a = ap.parse_args()

    if a.report:
        return report(a.inside_only)

    cfg = load_config(a.config)

    if a.test_email:
        try:
            send_email(cfg, "Drone Watch test email",
                       "Email alerts from Drone Watch are working.")
            print("Test email sent to", cfg["email"].get("to"))
            return 0
        except Exception as exc:
            print("Test email FAILED:", exc, file=sys.stderr)
            return 1

    if a.home:
        home = tuple(float(x) for x in a.home.replace(" ", "").split(","))
    elif cfg["home"].get("lat") and cfg["home"].get("lon"):
        home = (cfg["home"].getfloat("lat"), cfg["home"].getfloat("lon"))
    else:
        home = None
    radius = a.radius if a.radius is not None else cfg["home"].getfloat("radius_m")
    tracker = Tracker(home, radius, DB_PATH, cfg)
    tracker.public_config = public_config(cfg, a.config, home, radius, a.wifi, not a.no_ble)
    reports = ReportService(tracker, cfg)
    print(f"Drone Watch {__version__} ({__updated__})", flush=True)
    print(f"Home {home}, radius {radius} m, email alerts "
          f"{'ON' if tracker.alerter.enabled else 'off'} "
          f"(new after {tracker.alerter.window / 60:g} min)", flush=True)

    if a.wifi:
        threading.Thread(target=wifi_sniff, args=(tracker, a.wifi), daemon=True).start()

    sources = ([] if a.no_ble else ["BLE"]) + ([f"WiFi ({a.wifi})"] if a.wifi else [])

    async def run():
        tasks = []
        if not a.no_ble:
            tasks.append(asyncio.create_task(ble_scan(tracker)))
        while True:
            tracker.write_status(sources)
            reports.poll()
            if sys.stdout.isatty():
                tracker.render()
            await asyncio.sleep(2)

    try:
        asyncio.run(run())
    except KeyboardInterrupt:
        print("\nStopped. History saved to", DB_PATH)


if __name__ == "__main__":
    sys.exit(main())
