# Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| „Benutzername oder Passwort sind falsch“ | Eingaben prüfen, speichern, „Anmeldung erneut versuchen“. Der Dienst wiederholt das nicht von selbst (Kontosperre vermeiden). Das Konto muss ein lokales Konto sein |
| „Zwei-Faktor-Anmeldung aktiv“ | Ein eigenes lokales Konto ohne Zwei-Faktor-Anmeldung anlegen |
| „Verbindung zum Gateway nicht möglich“ | Adresse und Port prüfen, vom LoxBerry aus erreichbar? Firewall? |
| „Zertifikat des Gateways nicht prüfbar“ | „Zertifikat prüfen“ ausschalten (selbstsigniertes Zertifikat) |
| „Keine Berechtigung (Rolle des Kontos prüfen)“ | Für Befehle braucht das Konto „Full Management“ für Network |
| Befehl „abgelehnt: Befehle für Port N sind gesperrt“ | Port im Reiter „Geräte“ bei „Befehle für Ports“ eintragen |
| Befehl „abgelehnt: … Uplink …“ / „… kein PoE“ | Uplink-Ports und Ports ohne PoE werden nie geschaltet |
| Keine Werte in Loxone, aber in MQTT | Gateway V2: Abo `unifi/#` von Hand eintragen; das V2 vergleicht Topics exakt |
| Einzelne Ports fehlen | Im Reiter „Geräte“ ist eine Portauswahl eingetragen |
| Client fehlt an einem Port | Er hängt an einem Gerät hinter einem nicht verwalteten Switch (zählt dann am Port davor) oder ist gerade nicht aktiv |
