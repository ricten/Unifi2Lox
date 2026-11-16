# Unifi2Lox

LoxBerry-Plugin für **UniFi-Netzwerkgeräte** (Cloud Gateway, Dream Machine, Switches, Access Points). Es meldet sich lokal am Gateway an, liest **alle Ports** mit Link und **PoE-Leistung je Port**, zeigt, **welche Clients an welchem Port** hängen und **wie viele Clients je Access Point** verbunden sind, und stellt die Werte als **MQTT-Topics** auf dem MQTT-Broker des LoxBerry bereit. Das LoxBerry-MQTT-Gateway macht daraus **virtuelle Eingänge** für den Loxone Miniserver. In der Gegenrichtung nimmt das Plugin **Befehle** aus Loxone an: PoE am Port ein/aus und PoE-Neustart.

Ersetzt den ioBroker-Adapter für UniFi. Spezifikation und Messbefunde: [SPEZIFIKATION.md](SPEZIFIKATION.md).

**Version 0.1.0 (Beta).** Läuft mit PHP 7.4, 8.2, 8.3 und 8.4 (LoxBerry 4.0 oder neuer).

```
Switch / AP  <->  UniFi-Gateway  <->  Unifi2Lox (LoxBerry)  ->  MQTT-Broker  ->  MQTT-Gateway  ->  Miniserver
```

## Stand: was geprüft ist und was nicht

| Teil | Stand |
|---|---|
| Anmeldung (UniFi OS), Sitzung, CSRF-Token | an einem **Cloud Gateway** geprüft (nur lesend) |
| Ports, PoE je Port, Clients je Port, Clients je Access Point | an einem Cloud Gateway mit einem 24-Port-PoE-Switch und zwei Access Points geprüft (nur lesend): alle Clients lassen sich einem Port oder Access Point zuordnen, die Summen stimmen |
| **PoE-Befehle** (`poe`, `neustart`) | am Cloud Gateway an **einem Kamera-Port** des Switches geprüft (2026-11-14): `poe 0` schaltet das Gerät nach etwa 12 s stromlos, `poe 1` stellt es wieder her (die Rückkehr des PoE dauert bis etwa eine Minute), `neustart` wirkt bei anliegendem PoE; die Liste `port_overrides` blieb danach unverändert. Außerdem gegen ein nachgebildetes Gateway getestet |
| Klassische Controller-Software (ohne UniFi OS) | nur als Rückfall eingebaut, ungeprüft |
| Tests | 190 automatische Prüfungen gegen ein Fake-Gateway, unter PHP 7.4, 8.2, 8.3 und 8.4 |

Das Plugin nutzt die **inoffizielle** Schnittstelle des Network-Dienstes (dieselbe wie die Weboberfläche des Gateways). Ubiquiti kann sie mit einem Update ändern. Es spricht nur mit dem Gateway im eigenen Netz, nicht mit der Ubiquiti-Cloud. Das Plugin ist nicht von Ubiquiti.

## Funktionen

- **Ports:** Link, Geschwindigkeit und Anzahl der Clients je Port, optional Datenraten. UniFi-Geräte an Ports (z. B. ein Access Point am Switch) erkennt das Plugin.
- **PoE:** Leistung (W), Strom (mA) und „liefert Strom“ je Port; je Gerät Gesamtleistung und PoE-Budget, dazu die Summe im ganzen Netz.
- **Clients:** je Port (Kabel), je Access Point (gesamt, Gäste, je Funkband 2,4/5/6 GHz) und im ganzen Netz (gesamt, Kabel, WLAN). Die **Namen** der Clients je Port und je Access Point zeigt die Weboberfläche im Reiter „Netzwerk“; nach MQTT gehen nur Zähler.
- **Befehle aus Loxone** (ab Werk gesperrt, mit Quittung): `poe` (1 = ein, 0 = aus) und `neustart` (PoE kurz aus und ein), nur für **ausdrücklich freigegebene Ports**. Uplink-Ports und Ports ohne PoE werden nie geschaltet.
- Je Gerät Name im Topic und Auswahl der veröffentlichten Ports (viele Ports = viele Topics). Veröffentlicht nur **Änderungen** (alle 5 Minuten alle Werte). Lebenszeichen `unifi/status/ok`, `zaehler`, `ts`, Autostart, Watchdog, Healthcheck, Prüfung des Gateway-Abos.
- Weboberfläche im LoxBerry Design System, **ohne JavaScript**.

## Installation

1. ZIP von der Release-Seite laden (oder im LoxBerry-Shop installieren), in der **Plugin-Verwaltung** installieren. Voraussetzung: LoxBerry 4.0 mit PHP 7.4 oder neuer, mit curl und openssl.
2. Am Gateway ein **eigenes lokales Konto** anlegen (UniFi OS: *Admins & Users*, „nur lokaler Zugriff“, ohne Zwei-Faktor-Anmeldung). Für reines Lesen genügt für Network die Rolle „View Only“; für Befehle braucht das Konto „Full Management“.
3. Plugin öffnen, Reiter **Verbindung**: Adresse des Gateways, Benutzer und Passwort eintragen, speichern. Der Reiter **Netzwerk** zeigt danach alle Geräte, Ports und Clients.
4. Reiter **Geräte**: Namen vergeben, bei Bedarf die veröffentlichten Ports einschränken und Ports für Befehle freigeben, speichern.
5. Im **MQTT Gateway** das Abo `unifi/#` von Hand eintragen (Gateway V2 liest die Abo-Dateien der Plugins nicht; das Plugin prüft es im Reiter MQTT). `unifi/status/ok` und `unifi/status/zaehler` im Gateway auf *No Cache* stellen.

## Topics

Schema `unifi/<gerät>/…` und `unifi/<gerät>/port<nr>/…`, Zahlen mit Punkt ohne Einheit, Zustände 0/1, nicht retained. Die vollständige Liste steht im Plugin im Reiter „Einbindung in Loxone“ und in [docs/wiki/MQTT-Topics.md](docs/wiki/MQTT-Topics.md).

## Befehle

Virtueller Ausgang in Loxone: Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>` (Standard 11884), Befehl bei EIN `publish unifi/befehl/<gerät>/port<nr>/<befehl> <wert>`. Quittung unter `…/quittung`: `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`. **PoE aus oder Neustart schaltet das angeschlossene Gerät stromlos** (Access Point, Kamera, Telefon): nur Ports freigeben, die dafür taugen.

## Sicherheit und Datenschutz

- Das Passwort liegt in der Konfigurationsdatei des Plugins (Rechte 0600), wird nie angezeigt, geloggt oder per MQTT gesendet. Die Sitzung des Gateways lebt nur im Speicher.
- Falsche Zugangsdaten (auch ein Konto mit Zwei-Faktor-Anmeldung) wiederholt der Dienst nie von selbst, damit das Gateway das Konto nicht sperrt.
- Das Gateway hat meist ein selbstsigniertes Zertifikat: die Prüfung ist ab Werk aus (einstellbar), die Verbindung bleibt verschlüsselt.
- Client-Namen und IPs stehen nur in der Weboberfläche und im RAM (`/dev/shm`), nie in MQTT, Log oder Konfiguration.

## Entwicklung

- Tests: `php tests/run.php` (Unit, Ende-zu-Ende gegen Fake-Gateway und Fake-Broker, Healthcheck, Weboberfläche, Build) und `bash tests/test_phpcheck.sh`. Die Tests brauchen die PHP-Erweiterungen openssl und curl. Die Beispieldaten in `tests/fixture.php` sind erfunden (MACs `02:00:…`, IPs im Dokumentationsbereich).
- Build: `bash build.sh` erzeugt `unifi2lox-<version>.zip` und `unifi2lox.zip` (ohne Version).
- Gemeinsamer Code (`Util`, `Logger`, `MqttClient`, `GatewayInfo`) kommt aus `../common/php` und wird vom Build ins ZIP kopiert.
- Der Code läuft ab PHP 7.4: keine PHP-8-Syntax (kein `match`, keine Konstruktor-Promotion, keine Union-Typen …).

## Lizenz und Hinweise

MIT-Lizenz (siehe [LICENSE](LICENSE)). Eigene PHP-Umsetzung; Aufbau und Felder der Schnittstelle stammen aus der Beobachtung eines Gateways. Ich stehe in keiner Verbindung zu Ubiquiti Inc.
