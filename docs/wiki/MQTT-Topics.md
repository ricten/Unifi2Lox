# MQTT-Topics

Schema `unifi/<gerät>/…`, Zahlen mit Punkt ohne Einheit, Zustände 0/1, nicht retained. Das MQTT-Gateway ersetzt `/` durch `_`: `unifi/switch_keller/port4/poe_w` wird zum Eingang `unifi_switch_keller_port4_poe_w`. Texte (`typ`, `modell`) als virtuelle Texteingänge anlegen.

| Topic | Einheit | Bedeutung |
|---|---|---|
| `unifi/<gerät>/online` | 0/1 | Gerät erreichbar |
| `unifi/<gerät>/typ`, `modell` | Text | gateway, switch oder ap; Modell |
| `unifi/<gerät>/clients` | - | Clients am Gerät (Access Point: WLAN-Clients; Switch: Kabel-Clients) |
| `unifi/<gerät>/clients_gast`, `clients_2g`, `clients_5g`, `clients_6g` | - | Access Points: Gäste, Clients je Funkband |
| `unifi/<gerät>/poe_gesamt_w`, `poe_max_w` | W | Geräte mit PoE: aktuelle Leistung, Budget |
| `unifi/<gerät>/port<nr>/up` | 0/1 | Link |
| `unifi/<gerät>/port<nr>/aktiv` | 0/1 | Port eingeschaltet (0 = abgeschaltet) |
| `unifi/<gerät>/port<nr>/speed_mbit` | Mbit/s | Geschwindigkeit, 0 ohne Link |
| `unifi/<gerät>/port<nr>/clients` | - | Clients, die an diesem Port hängen |
| `unifi/<gerät>/port<nr>/poe_an`, `poe_w`, `poe_ma` | 0/1, W, mA | PoE-Ports: liefert Strom, Leistung, Strom |
| `unifi/<gerät>/port<nr>/rx_bps`, `tx_bps` | bit/s | nur mit der Option „Datenraten“ |
| `unifi/netz/clients`, `clients_kabel`, `clients_wlan` | - | Clients im ganzen Netz |
| `unifi/netz/poe_gesamt_w` | W | PoE aller Geräte |
| `unifi/client/<name>/online` | 0/1 | nur für im Reiter „Clients“ gewählte Clients (Schalter „Online-Status senden“): verbunden (1) oder nicht (0) |
| `unifi/client/<name>/ip` | Text | Schalter „IP senden“: IP-Adresse dieses Clients (nicht verbunden: die zuletzt bekannte) |
| `unifi/client/<name>/verbindung` | Text | Schalter „Verbindung senden“: aktive Verbindung (Switch und Port bzw. Access Point, Band, WLAN), nicht verbunden: „nicht verbunden“ |
| `unifi/internet/online` | 0/1 | Internetverbindung des Gateways steht |
| `unifi/internet/verbindungsdauer_s` | s | Dauer der aktuellen Verbindung (Sitzung beim Provider) |
| `unifi/internet/anbieter`, `asn` | Text, - | Internetanbieter und AS-Nummer |
| `unifi/internet/ip` | Text | öffentliche IP-Adresse (WAN) |
| `unifi/internet/latenz_ms`, `verfuegbarkeit_prozent` | ms, % | Latenz, Verfügbarkeit der letzten 24 Stunden |
| `unifi/internet/wan1_up`, `wan2_up`, `link_mbit` | 0/1, Mbit/s | Link der WAN-Anschlüsse, Geschwindigkeit |
| `unifi/internet/speedtest_down_mbit`, `speedtest_up_mbit`, `speedtest_ping_ms`, `speedtest_ts` | Mbit/s, ms, s | letzter Speedtest (nur bei gültigen Werten) |
| `unifi/internet/down_bps`, `up_bps` | bit/s | Datenrate am Internetanschluss (nur mit Option „Datenraten“) |
| `unifi/status/ok` | 0/1 | Anmeldung und letzte Abfrage in Ordnung (No Cache) |
| `unifi/status/zaehler` | 0–999 | Lebenszeichen, alle 10 s (No Cache) |
| `unifi/status/ts` | s | Zeitstempel des Lebenszeichens |

Im MQTT-Gateway `unifi/status/ok` und `unifi/status/zaehler` auf *No Cache* stellen. Die Ports je Gerät lassen sich im Plugin auswählen (viele Ports = viele Topics).

## Befehle

`unifi/befehl/<gerät>/port<nr>/<befehl>` mit `poe` (1 = ein, 0 = aus), `neustart` (1) oder `aktiv` (1 = Port ein, 0 = Port ganz aus, nicht nur PoE). Ab Werk gesperrt, im Reiter „Geräte“ je Port freizugeben (PoE-Befehle und Ein/Aus haben je eine eigene Freigabe). Ein abgeschalteter Port trennt jedes angeschlossene Gerät vom Netz. Quittung unter `…/<befehl>/quittung`: `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`.
