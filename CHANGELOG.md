# Changelog

Alle Änderungen an diesem Plugin. Schema: MAJOR.MINOR.PATCH.

## [0.1.0] - 2026-11-14

Erste Version (Beta). Ersetzt den ioBroker-Adapter für UniFi.

### Neu
- Anmeldung lokal am UniFi-Gateway (Cloud Gateway, Dream Machine; Rückfall auf klassische Controller-Software) mit einem eigenen lokalen Konto. Sitzung mit Cookie, bei Ablauf automatische Neuanmeldung. Falsche Zugangsdaten (auch Zwei-Faktor-Konten) werden nie von selbst wiederholt, damit das Gateway das Konto nicht sperrt. Das Plugin spricht nur mit dem Gateway im eigenen Netz.
- **Alle Ports** von Switches und Gateway: Link, Geschwindigkeit, Anzahl Clients je Port, optional Datenraten.
- **PoE je Port**: Leistung (W), Strom (mA) und ob PoE Strom liefert; je Gerät Gesamtleistung und PoE-Budget, dazu die Summe im ganzen Netz.
- **Clients je Port** (Kabel-Clients über Switch und Port) und **Clients je Access Point** (gesamt, Gäste, je Funkband 2,4/5/6 GHz); im ganzen Netz Clients gesamt, Kabel und WLAN. Die Namen der Clients je Port und je Access Point zeigt die Weboberfläche (Reiter „Netzwerk“), UniFi-Geräte an Ports (Access Point am Switch) werden erkannt.
- **Befehle** aus Loxone (ab Werk gesperrt, mit Quittung): PoE am Port ein oder aus (`poe`) und PoE-Neustart (`neustart`, power-cycle), nur für die je Gerät ausdrücklich freigegebenen Ports. Uplink-Ports und Ports ohne PoE werden nie geschaltet. Höchstens ein Befehl je Sekunde.
- Je Gerät Name im Topic und Auswahl der veröffentlichten Ports einstellbar; Gateway-Abo-Prüfung, Lebenszeichen `unifi/status/ok|zaehler|ts`, Healthcheck, Weboberfläche im LoxBerry Design System (ohne JavaScript), PHP 7.4 bis 8.4.

### Hinweise
- Geprüft an einem Cloud Gateway mit 24-Port-PoE-Switch und zwei Access Points: Lesen aller Werte und die PoE-Befehle an einem Kamera-Port. Die Quittung `ausgefuehrt` heißt „vom Gateway angenommen“: `poe 0` wirkt nach etwa 12 Sekunden (das Gateway konfiguriert den Switch neu), nach `poe 1` liefert der Port erst nach bis zu etwa einer Minute wieder Strom. Ein `neustart` kurz nach `poe 1` lehnt das Gateway ab (Port liefert noch kein PoE), das Plugin meldet das verständlich in der Quittung.
- Inoffizielle Schnittstelle des Network-Dienstes (wie die Web-Oberfläche): Ubiquiti kann sie mit einem Update ändern.
- Das Gateway hat meist ein selbstsigniertes Zertifikat: die Prüfung ist ab Werk aus (einstellbar), die Verbindung bleibt verschlüsselt.
- Nicht enthalten: Statistiken über längere Zeit, Firewall, VLANs, Protect und Access, Port-Profile ändern.
