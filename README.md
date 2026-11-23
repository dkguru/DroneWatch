# Drone Watch

A Raspberry Pi monitor for drones flying over a private property. It listens for
the Remote ID broadcasts that drones are required to send, shows every drone in
range on a live web dashboard, and keeps a timestamped log that can be handed to
the police or the FAA.

| File | What it is |
|---|---|
| `drone_watch.py` | The scanner. It receives and decodes Remote ID, tracks drones, and writes the log and the live status. |
| `dashboard/index.php` | The web dashboard. It shows a live map, contact list, history, CSV export, configuration viewer and email-report buttons. It never changes the log. |
| `drone_watch.conf.example` | Settings template: home location, radius and email alerts. Installed as `/etc/drone_watch.conf`. |
| `systemd/drone-watch.service` | Service file that starts the scanner at boot. |
| `README.md` | This document. |
| `LICENSE` | CC BY-ND 4.0 license. |

Repository: https://github.com/dkguru/DroneWatch
Version: **1.0.0**, last updated 2026-11-22.
Authors: Kianna Angelo and Jesper Angelo, kj@angelo.net. Idea by Kianna Angelo.
Copyright notice: https://dkguru.wordpress.com/copyright/
License: [CC BY-ND 4.0](https://creativecommons.org/licenses/by-nd/4.0/). See section 10.

---

## 1. Background

Drones flying low over a house are a growing nuisance and a privacy concern.
When you see one from the yard, it is usually hard to tell:

- whether it is the same drone that came last week
- how high and how close it actually came
- where the pilot was standing
- what you would need to show the police to get anything done

Since 16 March 2024, the FAA has required most drones flown in the United
States to broadcast **Remote ID**. Remote ID works like a digital license plate.
Every few hundred milliseconds the drone sends an unencrypted radio message
containing:

- its serial number (or a session ID)
- its position, altitude, speed and heading
- its status (on the ground, airborne, emergency)
- the position of the pilot's controller, or the takeoff point

Anyone with a suitable receiver can pick these messages up. The broadcast was
designed to be received by the public. Phone apps such as *Drone Scanner* and
*OpenDroneID* show the same data.

This project turns a Raspberry Pi into a permanent, unattended receiver for
those broadcasts.

## 2. Purpose

The goal is to **monitor and document drones that fly over my house**:

1. **Know when it happens.** Get a live view of every drone in radio range,
   with drones inside the property radius highlighted.
2. **Know who to point to.** Record the drone's Remote ID serial number, its
   manufacturer, and the pilot or takeoff location the drone itself reports.
3. **Build a record.** Log every sighting with a timestamp, so repeat visits by
   the same drone can be shown with dates, times, heights and distances.
4. **Hand it over properly.** Export the record (CSV) for local police or the
   FAA. They are the ones who can resolve a serial number to an owner.

### What it deliberately does not do

- **It does not identify the owner by name.** The broadcast does not contain
  the owner's identity. The FAA registration database that links serial numbers
  to owners is only open to authorized law enforcement, through the FAA DiSCVR
  tool. No hobby or commercial receiver can do this lookup. The program records
  exactly what police need in order to run that lookup themselves.
- **It does not interfere with drones.** It only receives. Jamming, spoofing,
  taking control of, or shooting at a drone is a federal crime: drones are
  aircraft under federal law, and the FCC prohibits jamming. It is also
  dangerous. This tool is for documentation only.
- **It does not record video or audio** of anyone.

### Legal context (Oregon). This is not legal advice.

- Oregon law (ORS 837.380) gives property owners a civil claim against a drone
  operator who has flown over their property before and has been told not to.
  A log that shows repeat flights over the property after you gave notice is
  exactly the kind of evidence that claim needs. Check the current text of the
  statute, or ask a lawyer, before relying on it.
- Under ORS 837.370, operating a drone over private property in a way meant to
  harass or annoy the occupants is an offense. Report that to local police.
- Pilot positions are part of a public broadcast. Use them to report to the
  authorities, not to confront anyone.

---

## 3. How it works

```
 Drone ──BLE advertisement──┐
       ──Wi-Fi beacon───────┤
                            ▼
              ┌──────────────────────────┐
              │      drone_watch.py      │   decode ASTM F3411 messages
              │  BLE scanner (bleak)     │   merge per transmitter
              │  Wi-Fi sniffer (scapy)   │   distance to home, radius check
              └─────────┬────────────────┘
          every 2 s     │        every packet (max 1 per 2 s per drone)
                        ▼                         ▼
     /var/lib/drone_watch/status.json   /var/lib/drone_watch/drone_watch.db
       (heartbeat + drones in range)        (SQLite log of all sightings)
                        │                         │
                        └──────────┬──────────────┘
                                   ▼
                       dashboard/index.php (Apache + PHP)
          ?api=status  ?api=history  ?api=csv  ?api=config  (read-only)
          ?api=report → requests/  ──►  scanner emails it  ──►  results/
                                   │
                                   ▼
                    Browser: map, live contacts, history
```

### The Remote ID protocol, briefly

Remote ID uses the **ASTM F3411 / Open Drone ID** message format. Every message
is exactly 25 bytes. The first byte holds the message type (high 4 bits) and
the protocol version (low 4 bits).

| Type | Message | What we take from it |
|---|---|---|
| 0x0 | Basic ID | Serial number / session ID, ID type, aircraft type |
| 0x1 | Location/Vector | Status, lat/lon, altitudes, height above takeoff, speed, heading |
| 0x3 | Self-ID | Free text the pilot entered, e.g. "Roof inspection" |
| 0x4 | System | Operator or takeoff location |
| 0x5 | Operator ID | Operator registration number (required in the EU, usually blank in the US) |
| 0xF | Message Pack | Several of the above bundled into one transmission |

The messages are carried in one of these ways:

- **Bluetooth LE**: service data with the 16-bit UUID `0xFFFA`, then the app
  code `0x0D`, a counter byte, and the message.
- **Wi-Fi Beacon**: a vendor-specific information element (ID 221) with the
  OUI `FA:0B:BC` and type `0x0D`, then a counter byte and a message pack.
- **Wi-Fi NAN** (Neighbor Awareness Networking): not decoded yet. See Limitations.

The first four characters of an ANSI/CTA-2063-A serial number are a
manufacturer code. For example, `1581` is DJI and `1748` is Autel. The program
shows the maker for the codes it knows and the raw code otherwise.

---

## 4. Coding thoughts and choices

**Python for the scanner.** Python runs on every Pi, has good libraries for
both radios (`bleak` for Bluetooth, `scapy` for Wi-Fi), and the protocol is
simple enough to decode by hand with `struct`. That keeps dependencies down
and the decoding readable.

**Decoding ASTM F3411 by hand.** Ready-made decoders such as `opendroneid-core-c`
exist, but they need compiling on the Pi. The format is small and fixed, so a
few dozen lines of `struct.unpack` are easier to audit and maintain. Fields
the standard marks as "unknown" are turned into `None`, so the display shows
"–" instead of a misleading zero: latitude `0`, altitude `0`, speed `255` and
vertical speed `63` all mean unknown.

**Tracking by transmitter MAC, not by serial.** A drone often sends its
Location messages before its Basic ID message arrives. If drones were tracked
by serial number, the early position data would have nowhere to go. Grouping
by the radio's MAC address lets the program merge every message type from one
drone into a single record. The serial number fills in as soon as it is
received. The history view then groups by serial number.

**Bluetooth first, Wi-Fi optional.** The Pi's built-in Bluetooth can receive
BLE Remote ID with no extra hardware, and most DJI drones and add-on broadcast
modules use BLE. Wi-Fi reception needs monitor mode, which the Pi's built-in
Wi-Fi chip does not support, so Wi-Fi is an option you turn on with `--wifi`
once you add a USB adapter.

**Channel hopping.** Wi-Fi Remote ID beacons usually sit on channel 6, but
some use 1, 11 or 5 GHz channel 149. The sniffer cycles through them every
0.5 s and visits channel 6 more often than the others.

**Logging throttled to one row per drone every 2 s.** Drones broadcast several
times a second. Logging every packet would fill the SD card and wear it out
without adding useful evidence. One row every two seconds still gives a
detailed flight path.

**SQLite as the log.** One file, no server process, safe if the power is cut
mid-write, and readable by Python, PHP, the command-line `sqlite3` tool or a
spreadsheet after export. It is the simplest format that is still solid
enough to use as evidence.

**A separate `status.json` heartbeat.** The dashboard needs two things the
database cannot easily give it: *is the scanner alive right now?* and *what
is in range this second?* The scanner writes a small JSON file every 2 seconds.
It writes to a temporary file and then renames it over the real one. The
rename is atomic, so the web page never reads a half-written file. If the
heartbeat is more than 10 s old, the dashboard shows OFFLINE.

**Data in `/var/lib/drone_watch`.** The scanner runs as root, because Wi-Fi
sniffing needs raw sockets. The web server runs as `www-data`. Root's home
folder is not readable by `www-data`, so the data lives in a standard system
data folder. The folder is set to mode 755 and the files to 644: readable by
the web server but writable only by the scanner. You can change the folder
with the environment variable `DRONE_WATCH_DIR`, which both programs read.

**PHP for the dashboard, in one file.** PHP needs no build step, runs under
the Apache that is easy to install on a Pi, and has SQLite built in through
PDO. One `index.php` serves both the page and its small JSON API (`?api=...`),
so installing it is a single copy command.

**The dashboard cannot change the log.** It opens the database with
`PRAGMA query_only`, so the evidence log cannot be changed from the web.

The only thing the dashboard writes is a small report request in a drop-box
folder. That folder has mode 1733: the web server can add files but cannot
list or delete them. The scanner, which holds the mail password, does the
sending. This means a compromised web page can never read the password, and
can never send mail to anyone except the configured recipients.
User-controlled text, such as the Self-ID message a drone broadcasts, is
HTML-escaped before display. Anyone can put anything in a broadcast, so it is
treated as untrusted input.

**Timestamps.** Sightings are stored in local time in ISO format
(`2026-11-21T13:18:57`). The SQL filters wrap the column in `datetime(ts)`
before comparing. A plain string comparison against SQLite's
`2026-11-21 13:18:57` format would get the order wrong within the same day.

**Map with Leaflet and OpenStreetMap tiles.** Leaflet is small and needs no
API key. CARTO's dark basemap was the first choice, but it now requires an API
key, so the dashboard uses standard OpenStreetMap tiles and darkens them with a
CSS filter to fit the radar style. The drone, its 10-minute trail
and the operator position are drawn together, with a dotted line connecting
drone and pilot, which is usually the most useful thing to see at a glance.

**Polling instead of WebSockets.** The page fetches the status every 2 s and
the history every 30 s. On a home network this is negligible load, and it
avoids running another server process on the Pi.

---

## 5. Hardware

| Part | Notes |
|---|---|
| Raspberry Pi 4 or 5 (a 3B+ works) | Built-in Bluetooth is enough for BLE Remote ID |
| microSD card, 32 GB or more, good quality | Endurance cards last longer with constant logging |
| Official power supply | Undervoltage causes Bluetooth and USB dropouts |
| *Optional:* USB Wi-Fi adapter with monitor mode | E.g. Alfa AWUS036ACM (MT7612U) or another MT7612U/MT7610U adapter. Needed for Wi-Fi Remote ID. |
| *Optional:* external antenna / USB Bluetooth 5 dongle | Adds range. Place the Pi high, near a window or in a weatherproof box outdoors. |

Typical reception range is a few hundred meters for BLE and more for Wi-Fi,
depending on the drone, antennas and obstacles.

---

## 6. Software installation

These steps were written for Raspberry Pi OS (Bookworm, 64-bit). Run all of
them on the Pi.

### 6.1 Update the system and install packages

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y git python3-pip bluez iw sqlite3 \
                    apache2 php libapache2-mod-php php-sqlite3
sudo pip3 install bleak scapy --break-system-packages
```

### 6.2 Install the programs

```bash
git clone https://github.com/dkguru/DroneWatch.git
cd DroneWatch

sudo mkdir -p /opt/drone_watch /var/lib/drone_watch
sudo cp drone_watch.py /opt/drone_watch/
sudo chmod 755 /opt/drone_watch/drone_watch.py

sudo cp dashboard/index.php /var/www/html/index.php
sudo rm -f /var/www/html/index.html     # remove Apache's default page
```

### 6.3 Create the settings file

All settings live in `/etc/drone_watch.conf`. Install the template and protect
it, because it will hold your mail password:

```bash
sudo cp drone_watch.conf.example /etc/drone_watch.conf
sudo chmod 600 /etc/drone_watch.conf
sudo nano /etc/drone_watch.conf
```

Fill in:

- **`[home]`:** your property's `lat` and `lon`, and `radius_m`. To get the
  coordinates, open Google Maps or OpenStreetMap, right-click the middle of
  your property and copy the latitude and longitude. Choose a radius in meters
  that covers your property line plus a margin. 50–150 m is typical for a
  residential lot.
- **`[alerts]`:** `enabled = yes` and `new_after_minutes` (default 30). See
  section 7.1.
- **`[email]`:** your mail server and recipients.

  For Gmail, follow section 6.3.1 below.

  For Outlook or Microsoft 365, use `smtp.office365.com`, port 587,
  `starttls`. If your provider uses port 465, set `security = ssl`.

Check the mail settings:

```bash
sudo python3 /opt/drone_watch/drone_watch.py --test-email
```

`--home lat,lon` and `--radius` on the command line still work, and override
the file.

### 6.3.1 Setting up Gmail for alerts

Gmail no longer accepts your normal account password from programs like
Drone Watch. Google switched off "less secure app" sign-ins in September 2024.
Instead you create an **App Password**: a 16-character password that only
this program uses, and that you can revoke at any time without changing your
real password.

**1. Choose the account.** A separate Gmail account just for the Pi, such as
`dronewatch.yourname@gmail.com`, is the safest choice. An app password gives
full access to its mailbox, and it is stored on the Pi in plain text. If the
Pi is ever lost or compromised, only that throwaway account is exposed. The
alerts can still be sent *to* your normal address.

**2. Turn on 2-Step Verification.** Google only offers App Passwords on
accounts that use it.

1. Sign in to the sending account and open https://myaccount.google.com/security
2. Under *How you sign in to Google*, open **2-Step Verification** and turn
   it on. A phone number or the Google Authenticator app is enough.

**3. Create the App Password.**

1. Open https://myaccount.google.com/apppasswords. Google may ask you to
   sign in again.
2. Under *App name*, type `Drone Watch` and click **Create**.
3. Google shows a 16-character password in four groups, for example
   `abcd efgh ijkl mnop`. Copy it now. Google will not show it again. If you
   lose it, delete it and create a new one.

**4. Put it in the settings file** on the Pi:

```bash
sudo nano /etc/drone_watch.conf
```

```ini
[email]
smtp_host = smtp.gmail.com
smtp_port = 587
security = starttls
username = dronewatch.yourname@gmail.com
password = abcdefghijklmnop
from = Drone Watch <dronewatch.yourname@gmail.com>
to = you@yourdomain.com, someone.else@example.com
dashboard_url = http://<pi-address>/
```

- `password` is the App Password **without the spaces**.
- `username` and `from` must be the account that owns the App Password.
  Gmail rewrites a different `from` address to the account's own address
  anyway.
- `to` can be any addresses, Gmail or not, separated by commas.
- If port 587 is blocked on your network, use `smtp_port = 465` and
  `security = ssl`.

Then protect the file and send a test email:

```bash
sudo chmod 600 /etc/drone_watch.conf
sudo python3 /opt/drone_watch/drone_watch.py --test-email
sudo systemctl restart drone-watch
```

**5. Make the alerts get noticed (optional).** In the receiving mailbox,
create a filter for the subject `Drone over home` that applies a label, stars
the message and marks it important. Then turn on notifications for that
label in the Gmail phone app.

**Gmail problems**

| Error from `--test-email` | Cause and fix |
|---|---|
| `Application-specific password required` | You used the normal account password. Create an App Password (step 3). |
| `Username and Password not accepted` (535 5.7.8) | Typo, spaces left in, or the App Password was revoked. Changing the Google account password also revokes all App Passwords. Create a new one. |
| The *App passwords* page says the setting is not available | 2-Step Verification is off. Or the account is in Advanced Protection, or is a Google Workspace account whose administrator has disabled App Passwords. Use a personal Gmail account for the Pi instead. |
| `Connection timed out` / `Network is unreachable` | The Pi can't reach smtp.gmail.com on that port. Check its internet access, or try port 465 with `security = ssl`. |
| Alerts land in Spam | Mark one as *Not spam*, or add a filter that never sends `Drone over home` to Spam. |

To stop the Pi sending mail at any time, delete the App Password at
https://myaccount.google.com/apppasswords. Nothing else on the account is
affected.

### 6.4 Test it by hand

```bash
sudo python3 /opt/drone_watch/drone_watch.py
```

The first line shows the home location, the radius, and whether email alerts
are on.

A live table appears in the terminal. Open `http://<pi-address>/` in a browser
on your network. The dashboard should show **SCANNING**. To find the Pi's
address, run `hostname -I`. Stop the scanner with Ctrl+C.

To test without a drone nearby, install a Remote ID simulator app on an
Android phone, or wait for a nearby flight.

### 6.5 Optional: enable Wi-Fi reception

Plug in the USB adapter, then find its name (usually `wlan1`):

```bash
iw dev
```

Put it in monitor mode:

```bash
sudo ip link set wlan1 down
sudo iw dev wlan1 set type monitor
sudo ip link set wlan1 up
```

Stop NetworkManager from managing the adapter, so it doesn't switch the
adapter back to normal mode:

```bash
sudo nmcli device set wlan1 managed no
```

Then add `--wifi wlan1` when you run the scanner.

### 6.6 Run it automatically at boot (systemd)

The repository includes a ready-made service file:

```bash
sudo cp systemd/drone-watch.service /etc/systemd/system/
```

The included file is set up for a USB Wi-Fi adapter named `wlan1`. If you only
use Bluetooth, remove its four `ExecStartPre` lines and `--wifi wlan1`.

For reference, a Bluetooth-only version of
`/etc/systemd/system/drone-watch.service` looks like this:

```ini
[Unit]
Description=Drone Watch Remote ID scanner
After=bluetooth.target network.target
Wants=bluetooth.target

[Service]
Type=simple
# Wi-Fi adapter only: uncomment these four lines and add "--wifi wlan1" to ExecStart.
# Use the USB adapter's name in EVERY line (the built-in wlan0 cannot do monitor mode).
# The "-" after "=" lets the scanner still start with Bluetooth if a Wi-Fi step fails.
# ExecStartPre=-/usr/bin/nmcli device set wlan1 managed no
# ExecStartPre=-/usr/sbin/ip link set wlan1 down
# ExecStartPre=-/usr/sbin/iw dev wlan1 set type monitor
# ExecStartPre=-/usr/sbin/ip link set wlan1 up
ExecStart=/usr/bin/python3 /opt/drone_watch/drone_watch.py --config /etc/drone_watch.conf
Environment=DRONE_WATCH_DIR=/var/lib/drone_watch
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now drone-watch
systemctl status drone-watch          # check it is running
journalctl -u drone-watch -f          # follow its log
```

### 6.7 Optional: password-protect the dashboard

The dashboard shows pilot locations, so keep it off the public internet. To
add a password on your home network:

```bash
sudo apt install -y apache2-utils
sudo htpasswd -c /etc/apache2/.htpasswd jesper
```

Add this to `/etc/apache2/sites-available/000-default.conf`, inside `<VirtualHost>`:

```apache
<Directory /var/www/html>
    AuthType Basic
    AuthName "Drone Watch"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Directory>
```

Then reload Apache:

```bash
sudo systemctl reload apache2
```

---

## 7. Using it

| Task | How |
|---|---|
| Watch live | Open `http://<pi-address>/`. Drones inside your radius are red. Tap a contact to zoom to it. |
| Email a report on a drone | Click **✉ EMAIL REPORT** next to the drone, in the live list or the history table. See 7.2. |
| Name a drone | Click the drone in the live list or the history table, type a name, and click **SAVE**. See 7.4. |
| Check the settings | Click **SHOW CONFIGURATION** at the bottom of the dashboard. See 7.3. |
| History in the terminal | `sudo python3 /opt/drone_watch/drone_watch.py --report` |
| Only drones that came inside the radius | add `--inside-only` |
| Export evidence | Click **EXPORT CSV** on the dashboard, or `sqlite3 -header -csv /var/lib/drone_watch/drone_watch.db "select * from sightings" > sightings.csv` |
| Back up the log | Copy `/var/lib/drone_watch/drone_watch.db` off the Pi regularly |

### 7.1 Email alerts

When a **new** drone enters your home area, Drone Watch sends an email
containing:

- the drone's Remote ID serial, maker and type
- its distance from home, height and speed
- map links to the drone's position and the operator's location
- a link to the dashboard

**What counts as "new":** a drone that has *not* been inside the home area
during the last `new_after_minutes` minutes (default 30, set in
`/etc/drone_watch.conf`).

- A drone that hovers or circles over the house sends **one** email.
- If it leaves and comes back within the window, it is treated as the same
  visit, and no email is sent.
- If it comes back after the window, it sends a new email.
- Restarting the scanner does not re-send alerts. It reads the recent visits
  back from the log.

**How drones are identified.** Drones are matched by their Remote ID serial.
A drone's position often arrives a moment before its serial, so the alert
waits up to `wait_for_id_seconds` (default 15) for it. If no serial arrives,
the email uses the radio's MAC address instead. When the serial arrives later,
it does not trigger a second email.

**Where alerts are recorded.** Every alert is logged in the `alerts` table of
the database, including ones not emailed because alerts are disabled. View
them with:

```bash
sqlite3 /var/lib/drone_watch/drone_watch.db "select * from alerts"
```

Delivery results appear in `journalctl -u drone-watch`, as lines beginning
`[alert]`.

Sending happens in the background, so a slow or failing mail server never
stops the scanner.

### 7.2 Emailed sighting reports

Every drone in the live list and the history table has an **✉ EMAIL REPORT**
button. It emails a formatted report on that drone to the `to` addresses in
`/etc/drone_watch.conf`. The report contains:

- the drone's Remote ID, maker and type, plus any note or operator ID the
  pilot broadcast
- the latest sighting: when it happened, how long it lasted, the closest
  distance to home, the maximum height and speed, whether it entered the home
  area, and map links to the closest point and the operator
- a count of how many times the drone has been seen and how many of those
  visits came inside the home area
- a table of **previous sightings**, newest first, up to 50, with date,
  duration, closest distance, maximum height, whether it came inside, and the
  operator location

A "sighting" here is one visit. Log entries less than `new_after_minutes`
apart count as the same visit, which is the same rule the email alerts use.

**How it works.** The web page cannot read the mail password, because the
settings file is readable only by root. Instead, the dashboard drops a small
request file into `/var/lib/drone_watch/requests/`. The scanner picks it up
within about 2 seconds, builds the report from the log and sends it. It then
writes the result to `/var/lib/drone_watch/results/`, and the button changes
to **✓ SENT** or **✕ FAILED**. Hover over the button to see the reason.

**Safety limits:**
- Reports only ever go to the configured `to` addresses, never to an address
  given by the web page.
- The button only works from the dashboard itself. It sends a POST request
  with a custom header, so a link or another website can't trigger it.
- At most 20 reports are sent per hour.
- If the scanner is offline, the button says so, because the scanner is what
  sends the email.

### 7.3 Viewing the configuration

**SHOW CONFIGURATION**, at the bottom of the dashboard, opens a read-only
view of the scanner's current settings:

- **Scanner:** config file, data folder, receivers
- **Home area:** location and radius
- **Alerts:** the alert settings
- **Email:** the mail settings, with the password shown only as "set" or
  "not set"
- **Dashboard:** checks for the dashboard itself, such as the PHP SQLite
  driver and whether the report queue is ready

The scanner publishes these settings in `status.json`, with the password
removed. To change a setting, edit `/etc/drone_watch.conf` and restart the
scanner.

### 7.4 Naming drones

Drones you recognize, such as your own, a neighbor's or a regular visitor,
can be given a name. A named drone shows as **name (...last 4 characters of
the serial)**, for example `My Skydio 2 (...W52F)`. This label appears in:

- the live list, the map and the history table
- the drone details window
- alert emails and sighting reports

To name a drone, **click it** in the live list or the history table. This
opens its details window, which shows:

- the full Remote ID serial and the maker
- first and last seen, the number of log entries, closest distance and
  maximum height
- an **EMAIL REPORT** button, and **SHOW ON MAP** if the drone is in range now

Type a name of up to 40 characters, then click **SAVE** or press Enter. To
remove a name, click **REMOVE NAME**.

The full serial is never hidden:
- it appears in small type under the name in the live list
- it appears when you hover over a history row
- it appears in the details window
- emails show both the name and the full Remote ID

Names are stored by the scanner in the `drone_names` table of the database.
Like the report button, the dashboard sends a request that the scanner
carries out, so the web page never writes to the database itself. The scanner
must be running to rename a drone.

### 7.5 Reporting a drone

1. Filter the history to the period in question and export the CSV.
2. Note the drone's **Remote ID serial**, the dates and times, the closest
   distance, the height, and the **operator location**.
3. Contact your local police non-emergency line and ask them to look up the
   serial number in FAA DiSCVR. You can also report unsafe flying to the FAA
   through your local Flight Standards District Office.
4. If you want to rely on Oregon's civil remedy, keep a record of when and how
   you notified the operator that you don't want drones over your property.

---

## 8. Troubleshooting

| Symptom | Check |
|---|---|
| Dashboard says "no status file" | Is the service running (`systemctl status drone-watch`)? Does `/var/lib/drone_watch/status.json` exist? |
| Dashboard says OFFLINE | The scanner stopped or crashed. Check `journalctl -u drone-watch`. |
| Blank page or a PHP error | `sudo tail /var/log/apache2/error.log`. Is `php-sqlite3` installed? |
| No drones ever appear | `bluetoothctl show` should list a powered controller. Test with a phone Remote ID simulator. |
| Bluetooth errors on start | `sudo systemctl restart bluetooth`. Also run `rfkill list` and `sudo rfkill unblock bluetooth`. |
| Wi-Fi sniffer sees nothing | Confirm monitor mode with `iw dev wlan1 info`, which should say "type monitor". Not all adapters support it. |
| Map is grey | The browser has no internet access. The map tiles load from the internet. |
| EMAIL REPORT says *report queue not available* | Restart the scanner (`sudo systemctl restart drone-watch`). It creates the queue folders when it starts. |
| EMAIL REPORT says ✕ FAILED | Hover over the button for the reason, or check `journalctl -u drone-watch \| grep report`. Mail problems are the same as for alerts (below). |
| Footer says *⚠ versions differ* | The dashboard (`/var/www/html/index.php`) and the scanner (`/opt/drone_watch/drone_watch.py`) are from different releases, usually because only one was copied or a copy was cut short. Copy both again and restart the scanner. `python3 /opt/drone_watch/drone_watch.py --version` shows the scanner's version. |
| No alert emails | Run `--test-email`. Check `journalctl -u drone-watch \| grep alert`. Gmail needs an App Password (see 6.3.1). Check that `enabled = yes`. |
| Home location didn't change | Edit `/etc/drone_watch.conf`, then `sudo systemctl restart drone-watch`. A `--home` in the service file overrides the config file. |

---

## 9. Limitations and future ideas

- **Owner identity is not available** to anyone except law enforcement.
- **Drones without Remote ID** are invisible: older drones, home-builds, some
  drones under 250 g flown recreationally, and anyone breaking the rules.
- **Wi-Fi NAN** Remote ID is not decoded yet. Many phones and some drones use
  it. Adding it means parsing NAN service discovery frames sent to
  `51:6f:9a:01:00:00`.
- **Bluetooth 5 Long Range** (coded PHY) needs a controller and BlueZ setup
  that supports extended advertising. The Pi's built-in radio may only pick up
  legacy advertisements.
- **The manufacturer table is short.** Add codes to `MANUFACTURERS` in
  `drone_watch.py` as you see new ones.
- Possible additions:
  - phone push notifications (e.g. ntfy) in addition to email
  - a photo trigger on a camera pointed at the sky
  - a print-ready PDF incident report per drone

---

## 10. License

Copyright (c) 2026 Kianna Angelo and Jesper Angelo, kj@angelo.net.
Idea by Kianna Angelo.
See https://dkguru.wordpress.com/copyright/.

Drone Watch is licensed under the **Creative Commons Attribution-NoDerivatives
4.0 International License** ([CC BY-ND 4.0](https://creativecommons.org/licenses/by-nd/4.0/)).
The full legal text is in [`LICENSE`](LICENSE).

**You may:**
- use this software, including commercially
- copy it and share it unchanged

**As long as you:**
- credit Kianna Angelo and Jesper Angelo
- link to the license
- link to this repository

**You may not** distribute modified versions. Changes you make for your own
use on your own Pi are fine, but you may not share them. To get a fix or
feature included, open an issue or contact the author.
