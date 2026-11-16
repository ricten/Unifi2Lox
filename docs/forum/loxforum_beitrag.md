# Entwurf für den Beitrag im LoxForum (Rubrik LoxBerry → Plugins)

**Titel:** [LoxBerry-Plugin] Unifi2Lox – UniFi-Ports, PoE-Leistung und Clients per MQTT an den Miniserver (Beta)

---

Hallo zusammen,

ich habe ein LoxBerry-Plugin für **UniFi-Netzwerkgeräte** geschrieben, als Ersatz für den ioBroker-Adapter. Es meldet sich **lokal** an eurem UniFi-Gateway an (Cloud Gateway, Dream Machine), liest Switches, Access Points und Clients und stellt die Werte als **MQTT-Topics** bereit. Über das MQTT-Gateway landen sie als virtuelle Eingänge im Miniserver.

**Was es liefert**

- **Alle Ports** von Switches und Gateway: Link, Geschwindigkeit, Anzahl der Clients je Port (optional Datenraten)
- **PoE je Port:** Leistung in W, Strom in mA, „liefert Strom“; je Switch Gesamtleistung und PoE-Budget, dazu die Summe im ganzen Netz
- **Welche Clients an welchem Port hängen** (Kabel) und **Clients je Access Point** (gesamt, Gäste, je Funkband); im ganzen Netz Clients gesamt, Kabel und WLAN. Die Namen der Clients je Port und je Access Point zeigt die Weboberfläche des Plugins, MQTT bekommt nur Zähler
- **Client-Liste mit IP-Adressen** in der Weboberfläche (alle Clients des Gateways, auch die derzeit nicht verbundenen, per Klick auf die Spaltenköpfe sortierbar). Je Client lässt sich einzeln wählen, was per MQTT an den Miniserver geht: der **Online-Status** (`unifi/client/<name>/online`, 1/0), die **IP-Adresse** (`…/ip`) und die **aktive Verbindung** (`…/verbindung`: Switch und Port bzw. Access Point), z. B. für die Anwesenheit von Handys oder die Erreichbarkeit von Geräten. Namen und IPs nicht gewählter Clients gehen nie nach MQTT
- **Internetverbindung** (nur lesend): Anbieter, Status, **Verbindungsdauer**, öffentliche IP, Latenz, Verfügbarkeit der letzten 24 Stunden, Link der WAN-Anschlüsse und Speedtest als Topics `unifi/internet/…` und als Karte im Reiter „Netzwerk“
- **Befehle aus Loxone:** PoE am Port ein/aus, PoE-Neustart (z. B. um eine hängende Kamera neu zu starten) und den Port ganz ein/aus (`aktiv`), ab Werk gesperrt, nur für Ports, die ihr ausdrücklich freigebt (PoE-Befehle und Ein/Aus getrennt), mit Quittung. Uplink-Ports werden nie geschaltet
- Je Gerät Name im Topic und Auswahl der veröffentlichten Ports (viele Ports = viele Topics), nur Änderungen werden gesendet
- Lebenszeichen (`unifi/status/ok|zaehler|ts`), Healthcheck, Autoupdate, Läuft mit PHP 7.4 bis 8.4 (LoxBerry 4.0 oder neuer), Weboberfläche im LoxBerry Design System

**Wichtig vorab:** Das Plugin nutzt die **inoffizielle** Schnittstelle des Network-Dienstes (die der Weboberfläche des Gateways); Ubiquiti kann sie mit einem Update ändern. Es spricht nur mit dem Gateway im eigenen Netz, nicht mit der Ubiquiti-Cloud. Das Plugin ist nicht von Ubiquiti. Ihr braucht ein **eigenes lokales Konto** am Gateway (nur lokaler Zugriff, ohne Zwei-Faktor-Anmeldung); zum Lesen genügt „View Only“, für Befehle „Full Management“.

**Stand: Beta.** Geprüft habe ich es an einem **Cloud Gateway** (PPPoE-Anschluss) mit einem 24-Port-PoE-Switch und zwei Access Points: Lesen aller Werte, Client-Liste, Internetdaten, die PoE-Befehle an einem Kamera-Port (PoE aus wirkt nach etwa 12 Sekunden, nach PoE ein liefert der Port erst nach bis zu etwa einer Minute wieder Strom) und Port ganz ein/aus an einem Port des Gateways (Wirkung nach etwa 10 Sekunden). **Ungeprüft** sind Port ein/aus an Switch-Ports und an Ports mit angeschlossenem Gerät, andere Gateway-Modelle, die klassische Controller-Software (nur als Rückfall eingebaut) und WLAN 6 GHz. Rückmeldungen (Modell, was geht, was nicht, Logauszug ohne Zugangsdaten) sind willkommen.

**Achtung bei Befehlen:** PoE aus oder Neustart schaltet das angeschlossene Gerät stromlos (Access Point, Kamera, Telefon), ein abgeschalteter Port trennt jedes Gerät vom Netz. Gebt nur Ports frei, an denen das verkraftbar ist.

**Download und Quellcode:** https://github.com/ricten/Unifi2Lox (MIT-Lizenz), Release: https://github.com/ricten/Unifi2Lox/releases/latest

Rückmeldungen, Fehler und Wünsche gern hier oder als Issue auf GitHub.

Viele Grüße
Rico
