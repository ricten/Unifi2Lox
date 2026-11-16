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
| `unifi/<gerät>/port<nr>/speed_mbit` | Mbit/s | Geschwindigkeit, 0 ohne Link |
| `unifi/<gerät>/port<nr>/clients` | - | Clients, die an diesem Port hängen |
| `unifi/<gerät>/port<nr>/poe_an`, `poe_w`, `poe_ma` | 0/1, W, mA | PoE-Ports: liefert Strom, Leistung, Strom |
| `unifi/<gerät>/port<nr>/rx_bps`, `tx_bps` | bit/s | nur mit der Option „Datenraten“ |
| `unifi/netz/clients`, `clients_kabel`, `clients_wlan` | - | Clients im ganzen Netz |
| `unifi/netz/poe_gesamt_w` | W | PoE aller Geräte |
| `unifi/status/ok` | 0/1 | Anmeldung und letzte Abfrage in Ordnung (No Cache) |
| `unifi/status/zaehler` | 0–999 | Lebenszeichen, alle 10 s (No Cache) |
| `unifi/status/ts` | s | Zeitstempel des Lebenszeichens |

Im MQTT-Gateway `unifi/status/ok` und `unifi/status/zaehler` auf *No Cache* stellen. Die Ports je Gerät lassen sich im Plugin auswählen (viele Ports = viele Topics).

## Befehle

`unifi/befehl/<gerät>/port<nr>/<befehl>` mit `poe` (1 = ein, 0 = aus) oder `neustart` (1). Ab Werk gesperrt, im Reiter „Geräte“ je Port freizugeben. Quittung unter `…/<befehl>/quittung`: `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`.
