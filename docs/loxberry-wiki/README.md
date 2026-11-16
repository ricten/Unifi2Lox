# Vorlage für das LoxBerry-Wiki

`unifi2lox_start.txt` ist die Seite für das [LoxBerry-Wiki](https://wiki.loxberry.de/plugins/start) im **DokuWiki-Format** (Aufbau wie bei den anderen Plugins: Plugin-Daten, Überblick, Installation, Konfiguration, Referenz, Miniserver-Anbindung, FAQ, Changelog). Rico lädt sie selbst ins Wiki hoch.

## Vor dem Einstellen anpassen

- Die Zeile „Diskussion“ der Plugin-Daten steht noch auf `FORUM-LINK-EINTRAGEN`: nach dem Posten im LoxForum den Link eintragen.
- Bild `plugins:unifi2lox:unifi2lox.png`: Datei `docs/logo/unifi2lox.png` im Medien-Manager des Wikis hochladen (Namensraum `plugins:unifi2lox`).
- **Status** („BETA“) und **Min. LB Version** („4.0.0.0“) prüfen; die Version steht in `unifi2lox/plugin.cfg`.
- **Release Download** muss genau **eine nackte ZIP-URL** sein (`…/releases/latest/download/unifi2lox.zip`), sonst ist das Plugin im Shop nicht installierbar. Bei jedem Release nur **Version** und die Changelog-Tabelle nachziehen. Der Link funktioniert erst, wenn es ein GitHub-Release mit `unifi2lox.zip` gibt.

## Einstellen

Die Seite gehört in den Namensraum `plugins:unifi2lox:start`. Das Anlegen setzt ein Wiki-Konto bzw. die Freigabe durch das LoxBerry-Team voraus.
