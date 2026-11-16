# Einrichtung

1. **Konto am Gateway:** *Admins & Users* → neues Konto, **nur lokaler Zugriff**, ohne Zwei-Faktor-Anmeldung. Zum Lesen reicht für Network „View Only“, für Befehle „Full Management“.
2. **Plugin installieren** (Plugin-Verwaltung, ZIP) und im Reiter **Verbindung** Adresse, Benutzer und Passwort eintragen. Selbstsigniertes Zertifikat: „Zertifikat prüfen“ aus lassen.
3. Reiter **Netzwerk**: alle Geräte, Ports, PoE-Leistung und Clients.
4. Reiter **Clients** (optional): bei Clients, deren Online-Status an den Miniserver gehen soll, den Schalter setzen und speichern; gemeldet wird `unifi/client/<name>/online` (1/0).
5. Reiter **Geräte**: Namen im Topic, veröffentlichte Ports (leer = alle), Ports für PoE-Befehle und Ports für Ein/Aus (je leer = gesperrt).
6. Im **MQTT Gateway** das Abo `unifi/#` von Hand eintragen (Gateway V2 liest die Abo-Dateien der Plugins nicht; das Plugin prüft es im Reiter MQTT). `unifi/status/ok` und `unifi/status/zaehler` auf *No Cache*.
7. Befehle: virtueller Ausgang in Loxone, Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>` (Standard 11884), Befehl bei EIN `publish unifi/befehl/<gerät>/port<nr>/poe 0`. **PoE aus schaltet das angeschlossene Gerät stromlos**, `publish unifi/befehl/<gerät>/port<nr>/aktiv 0` schaltet den Port ganz ab.
