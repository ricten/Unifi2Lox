# Spezifikation Unifi2Lox

Stand: 2026-11-14 (Version 0.1.0). Die Befunde stammen aus einer **lesenden Messung an einem Cloud Gateway** (UniFi OS) mit einem 24-Port-PoE-Switch (US24P250) und zwei Access Points. Echte Adressen, MACs und Client-Daten stehen nicht in diesem Repo.

## 1. Ziel

Ports aller Switches mit Link, **PoE-Verbrauch je Port**, **welche Clients an welchem Port hängen** und **Clients je Access Point** nach MQTT bringen; PoE je Port aus Loxone schalten können (gesperrt ab Werk).

## 2. Schnittstelle (gemessen)

- Anmeldung: `POST /api/auth/login` (JSON `username`, `password`, `remember`), Antwort 200 mit Sitzungs-Cookie `TOKEN` (JWT). Der **CSRF-Token** steht im JWT (Feld `csrfToken`, 36 Zeichen) und/oder im Antwortkopf `X-CSRF-Token` bzw. `X-Updated-CSRF-Token`. Lesen (GET) braucht ihn nicht, Aenderungen (PUT/POST) schon. Falsche Zugangsdaten: 401 (`AUTHENTICATION_FAILED_INVALID_CREDENTIALS`); Konto mit Zwei-Faktor-Anmeldung: Code mit `MFA`.
- Network-Schnittstelle unter `/proxy/network` (klassische Controller-Software ohne Präfix und mit `/api/login`: Rückfall, ungeprüft). Site `default`.
- `GET …/api/s/<site>/stat/device` (rund 130 KB bei 4 Geräten) und `…/stat/sta` (rund 120 KB bei 50 Clients), je unter 1 s. Antworten `{"meta":{"rc":"ok"},"data":[…]}`.
- Selbstsigniertes Zertifikat: Prüfung ab Werk aus (einstellbar).

### stat/device
- Gerät: `_id`, `mac`, `type` (`udm`/`ugw`/`uxg`, `usw`, `uap`), `model`, `name`, `state` (1 = online), `num_sta`, `guest-num_sta`, `uplink` (`type` wire/wireless, `uplink_mac`, `uplink_remote_port`), `total_max_power`, `total_used_power` (PoE-Budget und -Verbrauch, nur Geräte mit PoE).
- Ports (`port_table`): `port_idx`, `name`, `up`, `speed`, `media` (GE, 2.5GE, 10GE, SFP, SFP+), `is_uplink`, `rx_bytes-r`/`tx_bytes-r` (Bytes je Sekunde), `port_poe`, `poe_enable`, `poe_good`, `poe_mode` (auto/off), `poe_power`/`poe_voltage`/`poe_current` (**Text**), `poe_class`, `portconf_id`. `mac_table` und `lldp_table` am Port waren leer: die Zuordnung der Clients läuft über `stat/sta`. Nicht verbundene Ports melden `speed` 10 oder 0 (das Plugin sendet 0). Das Gateway hatte einen Port mit `port_poe` ohne Last und einer Restspannung von 1,12 V: PoE-Werte werden nur bei `poe_good` gesendet.
- Schaltbare Konfiguration: `port_overrides` (Liste je Port mit `port_idx`, `poe_mode`, `portconf_id`, …).
- Access Points: `radio_table_stats` (`radio` ng = 2,4 GHz, na = 5 GHz, 6e = 6 GHz, mit `num_sta` und `channel`), `vap_table` (je WLAN `essid`, `num_sta`). Die Summe der WLAN-Clients je AP aus `stat/sta` entspricht `num_sta`.

### stat/sta
- Alle aktiven Clients. Kabel: `is_wired`, `sw_mac`, `sw_port`, `wired_rate_mbps`. WLAN: `ap_mac`, `essid`, `radio`, `signal`, `rssi`, `channel`. Beide: `mac`, `ip`, `name` (nur bei etwa der Hälfte), `hostname`, `uptime`. In der Messung war jeder Client einem Gerät zugeordnet (kein Client ohne `ap_mac`/`sw_mac`).

## 3. Topics

Wurzel `unifi/`. Gerätename = Name im Plugin, sonst Name im Gateway (ohne Leerzeichen, a–z 0–9 _ -).

| Topic | Einheit | Bedeutung |
|---|---|---|
| `unifi/<gerät>/online` | 0/1 | `state` = 1 |
| `unifi/<gerät>/typ`, `modell` | Text | gateway/switch/ap, Modell |
| `unifi/<gerät>/clients` | - | `num_sta` |
| `unifi/<gerät>/clients_gast`, `clients_2g`, `clients_5g`, `clients_6g` | - | Access Points |
| `unifi/<gerät>/poe_gesamt_w`, `poe_max_w` | W | Geräte mit PoE |
| `unifi/<gerät>/port<nr>/up` | 0/1 | Link |
| `…/port<nr>/speed_mbit` | Mbit/s | 0 ohne Link |
| `…/port<nr>/clients` | - | Kabel-Clients an diesem Port |
| `…/port<nr>/poe_an`, `poe_w`, `poe_ma` | 0/1, W, mA | nur PoE-Ports |
| `…/port<nr>/rx_bps`, `tx_bps` | bit/s | nur mit Option „Datenraten“ |
| `unifi/netz/clients`, `clients_kabel`, `clients_wlan`, `poe_gesamt_w` | - / W | ganzes Netz |
| `unifi/status/ok`, `zaehler`, `ts` | 0/1, 0–999, s | Lebenszeichen |
| `unifi/befehl/<gerät>/port<nr>/<befehl>` | | Eingang, Quittung unter `…/quittung` |

Ports lassen sich je Gerät auswählen (`1-8, 22`). Nur Änderungen, alle 5 Minuten alle Werte. Namen der Clients stehen nur in der Weboberfläche.

## 4. Befehle

- `poe` = 1 (`poe_mode` auto) oder 0 (off): `PUT /api/s/<site>/rest/device/<_id>` mit der **kompletten** `port_overrides`-Liste des Geräts (frisch gelesen, nur der Eintrag des Ports geändert; fehlt der Eintrag, wird er mit `portconf_id` aus der `port_table` ergänzt).
- `neustart` = 1: `POST …/cmd/devmgr` mit `{"cmd":"power-cycle","mac":"aa:bb:…","port_idx":N}`.
- Nur freigegebene Ports, nie Uplink-Ports und Ports ohne PoE, Neustart nur bei anliegendem PoE, höchstens ein Befehl je Sekunde. Quittung `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`; danach Abfrage nach 2 s.
- **Am echten Gateway geprüft (2026-11-14, Port 23 des Switches, eine Kamera, Konto mit Full Management):**
  - `PUT rest/device/<id>` mit der kompletten Liste wird mit `rc ok` angenommen; danach setzt das Gateway den Switch neu auf (`state` 5, dann 1). Die Liste `port_overrides` war nach `off` und `auto` byte-gleich zum Anfang. Das CSRF-Token für Änderungen kommt aus Antwortkopf oder JWT-Cookie.
  - `poe_mode` off: erst nach etwa 12 s sinkt die Leistung auf 0, `poe_good` false, `up` false, `speed` 0. `poe_mode` auto: PoE kehrt erst nach bis zu etwa 70 s zurück (Gateway und Gerät), `up` und Leistung folgen gemeinsam.
  - `cmd/devmgr` power-cycle mit `port_idx` als **Zahl** ist ok (`poe_enable` fällt kurz auf false); `port_idx` als Text wird mit `api.err.InvalidTargetPort` abgelehnt. Dieselbe Ablehnung kommt, solange der Port gerade kein PoE liefert (gemessen 3 s nach `poe 1`): das Plugin übersetzt sie in „Port gerade nicht bereit“.
  - Die Quittung `ausgefuehrt` bedeutet nur „vom Gateway angenommen“; die Wirkung zeigt `port<nr>/poe_an` mit der Verzögerung oben.

## 5. Ablauf des Dienstes

Anmelden → alle `abfrage_abstand` Sekunden (Standard 30, 10–600) `stat/device` und `stat/sta` → Modell (`UniDaten::modell`) → Werte → Änderungen nach MQTT. Sitzung abgelaufen (401): eine Neuanmeldung. `status/ok` = 1, solange angemeldet und die letzte Abfrage nicht älter als das Dreifache des Abstands plus 60 s ist.
