# Entwurf für den Beitrag im LoxForum (Rubrik LoxBerry → Plugins)

**Titel:** [LoxBerry-Plugin] Unifi2Lox – UniFi-Ports, PoE-Leistung und Clients per MQTT an den Miniserver (Beta)

---

Hallo zusammen,

ich habe ein LoxBerry-Plugin für **UniFi-Netzwerkgeräte** geschrieben, als Ersatz für den ioBroker-Adapter. Es meldet sich **lokal** an eurem UniFi-Gateway an (Cloud Gateway, Dream Machine), liest Switches, Access Points und Clients und stellt die Werte als **MQTT-Topics** bereit. Über das MQTT-Gateway landen sie als virtuelle Eingänge im Miniserver.

**Was es liefert**

- **Alle Ports** von Switches und Gateway: Link, Geschwindigkeit, Anzahl der Clients je Port (optional Datenraten)
- **PoE je Port:** Leistung in W, Strom in mA, „liefert Strom“; je Switch Gesamtleistung und PoE-Budget, dazu die Summe im ganzen Netz
- **Welche Clients an welchem Port hängen** (Kabel) und **Clients je Access Point** (gesamt, Gäste, je Funkband); im ganzen Netz Clients gesamt, Kabel und WLAN. Die Namen der Clients je Port und je Access Point zeigt die Weboberfläche des Plugins, MQTT bekommt nur Zähler
- **Befehle aus Loxone:** PoE am Port ein/aus und PoE-Neustart (z. B. um eine hängende Kamera neu zu starten), ab Werk gesperrt, nur für Ports, die ihr ausdrücklich freigebt, mit Quittung. Uplink-Ports und Ports ohne PoE werden nie geschaltet
- Je Gerät Name im Topic und Auswahl der veröffentlichten Ports (viele Ports = viele Topics), nur Änderungen werden gesendet
- Lebenszeichen (`unifi/status/ok|zaehler|ts`), Healthcheck, Autoupdate, Läuft mit PHP 7.4 bis 8.4 (LoxBerry 4.0 oder neuer), Weboberfläche im LoxBerry Design System

**Wichtig vorab:** Das Plugin nutzt die **inoffizielle** Schnittstelle des Network-Dienstes (die der Weboberfläche des Gateways); Ubiquiti kann sie mit einem Update ändern. Es spricht nur mit dem Gateway im eigenen Netz, nicht mit der Ubiquiti-Cloud. Das Plugin ist nicht von Ubiquiti. Ihr braucht ein **eigenes lokales Konto** am Gateway (nur lokaler Zugriff, ohne Zwei-Faktor-Anmeldung); zum Lesen genügt „View Only“, für Befehle „Full Management“.

**Stand: Beta.** Geprüft habe ich es an einem **Cloud Gateway** mit einem 24-Port-PoE-Switch und zwei Access Points: Lesen aller Werte und die PoE-Befehle (an einem Kamera-Port: PoE aus wirkt nach etwa 12 Sekunden, nach PoE ein liefert der Port erst nach bis zu etwa einer Minute wieder Strom). Andere Gateway-Modelle, die klassische Controller-Software (nur als Rückfall eingebaut) und WLAN 6 GHz sind **ungeprüft**. Rückmeldungen (Modell, was geht, was nicht, Logauszug ohne Zugangsdaten) sind willkommen.

**Achtung bei Befehlen:** PoE aus oder Neustart schaltet das angeschlossene Gerät stromlos (Access Point, Kamera, Telefon). Gebt nur Ports frei, an denen das verkraftbar ist.

**Download und Quellcode:** https://github.com/ricten/Unifi2Lox (MIT-Lizenz), Release: https://github.com/ricten/Unifi2Lox/releases/latest

Rückmeldungen, Fehler und Wünsche gern hier oder als Issue auf GitHub.

Viele Grüße
Rico
