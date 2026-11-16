# Unifi2Lox – LoxBerry-Plugin

**Version: siehe `unifi2lox/bin/lib/AppVersion.php` (muss mit `unifi2lox/plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Läuft mit PHP 7.4, 8.2, 8.3 und 8.4 (CLI + Web).**

Ersetzt den ioBroker-Adapter für UniFi. Liest Ports, PoE je Port, Clients je Port und je Access Point über die lokale Network-Schnittstelle eines UniFi-Gateways (UniFi OS, `/proxy/network`) und veröffentlicht per MQTT auf dem LoxBerry-Broker; nimmt PoE-Befehle aus Loxone an.
Spezifikation und Messbefunde: [SPEZIFIKATION.md](SPEZIFIKATION.md).
Übergeordnete Regeln (Topics, Status, Versionierung): `../CLAUDE.md` und `../README.md`. Bei Widerspruch gelten die Regeln dieser Datei.

## Layout
- `unifi2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, cron/, daemon/, uninstall/, webfrontend/, icons/, *.sh)
- `../common/php/` = gemeinsamer Code (Util, Logger, MqttClient, GatewayInfo). `build.sh` kopiert ihn nach `bin/lib` des ZIP; nie aus einem anderen Plugin importieren
- `tests/` = `php tests/run.php` (Unit, Ende-zu-Ende mit Fake-Gateway `fake_gateway.php` und Fake-Broker `fake_mqtt.php`, Healthcheck, Oberfläche, Build) und `bash tests/test_phpcheck.sh` – **nicht** im ZIP. Beispieldaten: `tests/fixture.php` (erfunden)
- Klassen: `UniConfig` (Konfiguration), `UniController` (Sitzung, Lesen, Befehle), `UniDaten` (Modell, Client-Liste und MQTT-Werte aus den Rohdaten, reine Funktionen), `UniBefehle` (Befehle und Prüfung)

## Harte Regeln
- **PHP 7.4 bis 8.4:** keine PHP-8-Syntax (kein `match`, keine Konstruktor-Promotion, kein `readonly`, keine Union-Typen, kein `mixed`, kein `catch (X)` ohne Variable, kein `?->`, keine benannten Argumente, keine typisierten Klassenkonstanten); `str_contains` & Co. kommen aus `common/php/Util.php`. Vor Releases `php -l` und `php tests/run.php` mit 7.4, 8.2, 8.3 und 8.4.
- **Die Weboberfläche hat kein JavaScript** (nur Formulare); Änderungen an ihr nie mit Umbauskripten über die Datei laufen lassen. Der Test prüft, dass kein `<script` vorkommt.
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden. `.gitattributes`/`.editorconfig` beachten.
- Plugin-Identität nie ändern (`AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`), sonst entsteht beim Installieren ein zweites Plugin.
- Plugin-Ordner nie fest eintragen (kann `unifi2lox_000` heißen): Skripte nehmen ihn aus `$3` bzw. aus ihrem Ort. MQTT-Client-ID enthält den Ordner (`unifi_<Ordner>`).
- Weboberfläche und Dienst: `LBWeb::lbheader()` und `mqtt_connectiondetails()` überschreiben die globale Variable `$cfg`. Eigene Konfiguration heißt deshalb `$uni`.
- Laufzeitstatus (`status.json`, `netz.json`, PID, Sperre) nur nach `/dev/shm/<Ordner>/`. Konfiguration atomar schreiben (Temp + rename), Rechte 0600.
- **Zugangsdaten** (Passwort des Gateway-Kontos, Passwort eines eigenen Brokers, Sitzungs-Cookie, CSRF-Token) nie loggen (Logger maskiert registrierte Secrets), nie per MQTT senden, nie in der Oberfläche oder in `status.json` anzeigen (Passwortfeld, leer = unverändert). Keine echte Adresse, MAC-Adresse, Seriennummer oder Client-Daten eines echten Netzes in Code, Tests, Fixtures oder Doku; Tests prüfen das (erfundene MACs `02:00:00:00:…`). **Client-Namen und IPs** nur in der Oberfläche und im RAM (`netz.json`), nie in MQTT, Log oder Konfiguration. **Einzige Ausnahme:** Clients, die der Benutzer im Reiter „Clients“ ausdrücklich für den Online-Status wählt (Rico wünscht dafür auch die IP): von ihnen stehen MAC-Adresse und der gewählte Name im Topic (`clients` in `unifi.json`) in der Konfiguration, in MQTT je nach den drei Schaltern des Clients (Online-Status, IP, Verbindung) `unifi/client/<name>/online`, `…/ip` und `…/verbindung`; IP-Adressen und Namen aller anderen Clients nie, und IPs nie in Log oder Konfiguration. Die **öffentliche WAN-IP** geht als `unifi/internet/ip` nach MQTT (nur lokaler Broker), nie ins Log.
- **Anmeldung schonend:** Bei falschen Zugangsdaten (HTTP 400/401/403, auch Zwei-Faktor) **nicht** von selbst wiederholen (Kontosperre), bei anderen Fehlern mit wachsendem Abstand (30 s bis 15 min). Eine abgelaufene Sitzung (401 bei der Abfrage) führt zu genau einer Neuanmeldung. Das Plugin spricht nur mit dem Gateway, nie mit der Ubiquiti-Cloud.
- **Befehle:** nur `UniBefehle::LISTE` (`poe`, `neustart`, `aktiv`), **nur für Ports, die je Gerät ausdrücklich freigegeben sind** (ab Werk keiner; `poe`/`neustart` über `befehle_ports`, `aktiv` über die eigene Liste `schalt_ports`), immer mit Quittung (`angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`), nie retained, höchstens ein Befehl je Sekunde. Nie geschaltet werden Uplink-Ports; PoE-Befehle nur an Ports mit PoE. `aktiv` setzt wie die Oberfläche des Gateways die vier Felder `forward` (`disabled`), `native_networkconf_id` (leer), `tagged_vlan_mgmt` (`block_all`) und `port_security_enabled` (true) im Eintrag des Ports (ein `forward` allein verwirft das Gateway), merkt die alten Werte in `port_vorher.json` (Datenordner) und prüft nach dem Senden durch Neulesen (sonst `abgelehnt` und Liste zurückstellen); Ports mit Port-Profil (`portconf_id`) werden abgelehnt. Nie: VLANs/Profile ändern, Geräte neu starten oder zurücksetzen, Firmware, Konten. `poe` schreibt die **komplette** Liste `port_overrides` des Geräts (frisch gelesen, nur ein Eintrag geändert). **Befehle an einem echten Gateway nur mit Ricos ausdrücklichem Go und nur an von ihm genannten Ports** (PoE aus kappt das angeschlossene Gerät; ein falscher Port am Uplink oder Access Point legt das Netz lahm). Am echten Gateway sind `poe` und `neustart` am Kamera-Port 23 des Switches geprüft (siehe SPEZIFIKATION), `aktiv` am Port 2 des Cloud Gateways (siehe SPEZIFIKATION; Switch-Ports ungeprüft; **jede Änderung an einem Port, auch zum Testen, nur mit Ricos ausdrücklichem Go für genau diesen Port**); die Wirkung ist verzögert (aus nach etwa 12 s, ein bis etwa 70 s), `ausgefuehrt` heißt nur „vom Gateway angenommen“.
- **Erwartete Zustände:** Ein Gerät mit `state` ungleich 1 ist `online` = 0 (Warnung im Healthcheck, kein Grund für `status/ok` = 0). `status/ok` = 0 bei: keine Anmeldung, letzte Abfrage fehlgeschlagen oder zu alt.
- **Zuordnung:** Kabel-Clients über `sw_mac` + `sw_port`, WLAN-Clients über `ap_mac`; UniFi-Geräte am Port über `uplink.uplink_mac` + `uplink_remote_port` des angeschlossenen Geräts (nur `uplink.type` = `wire`). `poe_*`-Werte nur bei `poe_good`, sonst 0.
- Nur Änderungen veröffentlichen, alle 5 Minuten alle Werte. Datenraten nur mit der Option `verkehr` (ändern sich ständig). Je Port bis zu sieben Topics: die Portauswahl je Gerät ist Absicht.
- Topic-Namen unter `unifi/` nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version erhöhen. Keine Leerzeichen in Topics und Gerätenamen. Ein leeres Namensfeld bleibt leer gespeichert (der Name kommt dann vom Gateway; nie fest `roboter`-artige Namen einsetzen, das hat bei Ecovacs2Lox 0.1.0 die Topics verschoben).
- **Logo und Icons:** `tools/make_logo.py` erzeugt `docs/logo/unifi2lox.png` und `unifi2lox/icons/icon_{64,128,256,512}.png` im Stil der anderen Plugins (links ein Switch mit WLAN-Wellen, rechts der Miniserver). Dateinamen `icon_*.png` nie umbenennen, kein `icon.svg`. Änderungen am Logo nur auf Wunsch von Rico. Skripte im Scratchpad mit eindeutigen Namen anlegen (nie einen vorhandenen Namen wiederverwenden).

## Entwicklung
- PHP lokal nötig; fehlt es, das offen sagen und nichts als „getestet“ melden. Unter Windows ohne `php.ini` laufen die Tests mit `php -d extension_dir=<php>/ext -d extension=openssl -d extension=curl tests/run.php`.
- **Tests nie parallel** starten (feste Ports 18871 und 18872). Beim Abbruch einer Testsuite bleiben Fake-Server und Dienst-Prozesse stehen: vor dem nächsten Lauf beenden. Nie `| head` hinter den Testlauf hängen.
- Nicht lokal testbar (nur auf dem LoxBerry bzw. am echten Gateway): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), das Aussehen der Weboberfläche und die PoE-Befehle am echten Gateway.
- Dienst lokal: `UNI_DEV_DIR=<Ordner mit unifi.json> UNI_DEV_MQTT=127.0.0.1:18871 php unifi2lox/bin/unifid.php`; die Adresse des Gateways steht in der Konfiguration (Fake: `UNI_FAKE_DIR=<Ordner> php -S 127.0.0.1:18872 tests/fake_gateway.php`).
- Build: `bash build.sh` → `unifi2lox-<version>.zip` und `unifi2lox.zip` (ohne Version).

## Release-Ablauf (Autoupdate über `release.cfg`)
1. Version in `plugin.cfg` und `AppVersion.php` erhöhen, Eintrag in `CHANGELOG.md`, Tests, `bash build.sh` (baut beide ZIPs).
2. Committen (ohne `release.cfg`), pushen, Tag `vX.Y.Z` setzen, `gh release create vX.Y.Z unifi2lox-X.Y.Z.zip unifi2lox.zip …` (beide ZIPs anhängen).
3. **Danach** `bash build.sh release-cfg`, `release.cfg` committen und pushen. Die Datei darf nie auf eine Version zeigen, deren ZIP noch nicht existiert.
- **`release.cfg` nur nach Rücksprache mit Rico anlegen oder ändern** (auch `bash build.sh release-cfg` nicht ohne Freigabe): Sie löst bei allen Installationen mit aktivierten Auto-Updates sofort ein Update aus.
- Commit, Push, Veröffentlichung nur auf Ricos Anweisung.
- `plugin.cfg` enthält `AUTOMATIC_UPDATES=true` und `RELEASECFG=https://raw.githubusercontent.com/ricten/Unifi2Lox/main/release.cfg` (`PRERELEASECFG` leer).
- Weboberfläche: LoxBerry Design System (`lb-*`, `lbheader(…, true)`), kein jQuery Mobile; Farben nur über `var(--lb-…, Rückfall)`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl frei. Version dreiteilig ohne Buchstaben, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist zeilenweise (`unifi/#`); nur das Gateway V1 liest sie, das Gateway V2 nicht (und vergleicht Topics exakt mit den Einträgen). Die Oberfläche prüft deshalb `subscriptions.json` (`GatewayInfo::subscription`). Nie behaupten, das Plugin trage das Abo selbst ein.
- **UDP-Port** des Gateways nie fest eintragen (`GatewayInfo::udpPort`), Standard 11884. Befehlsform `publish <topic> <wert>`.
- Healthcheck: `bin/healthcheck` (`title` und `check`, JSON, Status 3/4/5/6, schnell, nur lesend).
- Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren). Tests rendern jeden Reiter mit Attrappen (`tests/stubs`, `tests/ui_page.php`).
- Ungenutzte Installationsskripte nicht mitliefern; keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren).
