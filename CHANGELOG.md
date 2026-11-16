# Changelog

Alle Änderungen an diesem Plugin. Schema: MAJOR.MINOR.PATCH.

## [0.2.2] - 2026-11-16

### Geändert
- **Client-Tabelle sortierbar:** Ein Klick auf eine Spaltenüberschrift sortiert nach dieser Spalte (Auswahl, Name im Topic, Client, IP-Adresse numerisch, Verbindung, Status), ein zweiter Klick kehrt die Richtung um; ein Pfeil zeigt die Sortierung. Leere IP-Adressen und Verbindungen stehen immer am Ende. Die Sortierung bleibt nach dem Speichern und beim Umschalten auf „alle bekannten“ erhalten (Links statt JavaScript).
- Abgeschaltete Ports stehen im Reiter „Netzwerk“ wie in der Oberfläche des Gateways mit weißem Kästchen und „inaktiv“ in normaler Schrift (nicht mehr orange und fett).

## [0.2.1] - 2026-11-16

### Geändert
- Die Weboberfläche (Reiter „Netzwerk“) zeigt einen abgeschalteten Port jetzt als **inaktiv** („abgeschaltet“) statt als „kein Link“. Das gilt für Ports, die per Befehl `aktiv` oder in der Oberfläche des Gateways abgeschaltet wurden (`port<nr>/aktiv` = 0).

## [0.2.0] - 2026-11-16

### Neu
- **Port ganz ein- und ausschalten** (Befehl `aktiv`, 1 = ein, 0 = aus), nicht nur PoE: `unifi/befehl/<gerät>/port<nr>/aktiv`. Das Plugin schreibt den Eintrag des Ports in `port_overrides` so, wie es die Oberfläche des Gateways beim Abschalten tut: `forward` = `disabled`, kein Netz (`native_networkconf_id` leer), `tagged_vlan_mgmt` = `block_all`, `port_security_enabled` = true (ein `forward` allein verwirft das Gateway). Alle anderen Felder des Eintrags bleiben unverändert. Die vier alten Werte merkt sich das Plugin im Datenordner und stellt sie beim Einschalten wieder her; ohne Merker (Port wurde anderswo abgeschaltet) setzt es `forward` = `all`, `tagged_vlan_mgmt` = `auto`, die Port-Sicherheit aus und das häufigste Netz der übrigen Ports.
- **Eigene Freigabe** je Gerät im Reiter „Geräte“: Spalte „Ports ein/aus für Ports“ (ab Werk gesperrt). Die PoE-Freigabe gilt dafür nicht, und umgekehrt. Uplink-Ports werden nie geschaltet; anders als bei PoE sind auch Ports ohne PoE möglich (Switch-Ports, SFP).
- Neues Topic `unifi/<gerät>/port<nr>/aktiv` (0/1): Port eingeschaltet (0 auch, wenn der Port in der Oberfläche des Gateways abgeschaltet wurde). Je Port gibt es damit bis zu sieben Topics.
- Die Weboberfläche zeigt statt des Modellcodes die **Bezeichnung von Ubiquiti** (z. B. `U7PG2` → UAP-AC-Pro, `UAP6MP` → U6-Pro, `US24P250` → US-24-250W); unbekannte Codes bleiben unverändert. In MQTT (`unifi/<gerät>/modell`) steht weiter der Code.
- **Reiter „Clients“:** alle Clients des Gateways mit Name, MAC-Adresse, **IP-Adresse**, Verbindung (Switch und Port bzw. Access Point, Band und WLAN) und Status; verbundene zuerst, auf Wunsch auch alle bekannten, derzeit nicht verbundenen Clients (Link „alle bekannten anzeigen“).
- **Online-Status per MQTT je Client wählbar:** Schalter je Client, Name im Topic änderbar. Gemeldet wird `unifi/client/<name>/online` (1 = verbunden, 0 = nicht verbunden, auch für gewählte Clients, die gerade nicht verbunden oder dem Gateway unbekannt sind). Dazu liest der Dienst alle 5 Minuten die Liste der bekannten Clients (`rest/user`); ist sie nicht lesbar, ist das kein Fehler (nur Warnung im Log).
- Gespeichert werden nur die gewählten Clients (MAC-Adresse und Name im Topic); Namen und IP-Adressen aller anderen Clients bleiben im Arbeitsspeicher, IP-Adressen gehen nie nach MQTT. Der vorgeschlagene Name im Topic wird beim Speichern festgeschrieben (ändert sich der Name im Gateway später, bleibt das Topic gleich).
- Ein Port mit Port-Profil (`portconf_id`) wird nicht geschaltet (Quittung `abgelehnt`), weil dort das Profil statt der Weiterleitung gilt.

### Hinweise
- **Am echten Gateway geprüft (2026-11-16, Port 2 des Cloud Gateways, ein freier Port, den Rico dort abgeschaltet hatte):** Schalten in vier Schritten (ein ohne Merker, aus, ein mit Merker, aus) mit der Zustandsprüfung des Plugins: Das Gateway übernimmt die vier Felder, die Portzeile zeigt `enable` = false und `forward` = `disabled`, das Einschalten mit Merker stellt den Eintrag **exakt** wieder her, alle anderen Einträge von `port_overrides` blieben unverändert; jeder Schritt wirkte nach etwa 10 Sekunden. **Nicht geprüft** sind Ports eines Switches (gleiche Schnittstelle, aber andere Hardware) und ein Port mit angeschlossenem Gerät. Gemessen am Kamera-Port des Switches: Ein `forward` = `disabled` allein (auch mit `setting_preference` = `manual`) nimmt das Gateway an und verwirft es wieder, der Port bleibt an. Welche Felder die Oberfläche setzt, wurde zuerst an dem von Rico dort abgeschalteten Port gelesen. Das Plugin liest nach dem Senden neu und quittiert `abgelehnt: Das Gateway hat die Änderung nicht übernommen`, wenn der Port nicht abgeschaltet ist (und stellt den vorherigen Stand der Liste wieder her). Quittung `ausgefuehrt` heißt wie bei PoE „vom Gateway angenommen“.
- **Ein abgeschalteter Port trennt jedes angeschlossene Gerät vom Netz** (auch den LoxBerry oder den Miniserver, wenn sie daran hängen): nur Ports freigeben, die dafür taugen.

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
