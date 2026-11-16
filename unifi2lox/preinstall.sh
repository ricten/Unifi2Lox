#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
# PHP-Pruefung: Das Plugin braucht PHP 7.4 oder neuer (Kommandozeile fuer den Dienst, Apache fuer die Weboberflaeche) mit curl und openssl.
# Exit 2 bricht die Installation bzw. das Update ab (Exit 1 wuerde nur warnen); die Meldung steht im Installationslog.
UNI_MODS="${UNI_APACHE_MODS:-/etc/apache2/mods-enabled}"
uni_phpbin="$(command -v php 2>/dev/null || echo /usr/bin/php)"
uni_id="$("$uni_phpbin" -r 'echo PHP_VERSION_ID;' 2>/dev/null)"
uni_ver="$("$uni_phpbin" -r 'echo PHP_VERSION;' 2>/dev/null)"
uni_fehler=""
if ! [ "${uni_id:-0}" -ge 70400 ] 2>/dev/null; then
  if [ -n "$uni_ver" ]; then
    uni_fehler="Auf der Kommandozeile laeuft PHP $uni_ver."
  else
    uni_fehler="Auf der Kommandozeile ist kein PHP verfuegbar."
  fi
fi
# die Erweiterungen curl (Cloud-Abfragen) und openssl (HTTPS) muessen geladen sein
if [ -n "$uni_ver" ]; then
  for uni_e in curl openssl; do
    "$uni_phpbin" -r "exit(extension_loaded('$uni_e') ? 0 : 1);" >/dev/null 2>&1 || uni_fehler="$uni_fehler Die PHP-Erweiterung $uni_e fehlt."
  done
fi
for uni_m in "$UNI_MODS"/php*.load; do
  [ -e "$uni_m" ] || continue
  uni_w="$(basename "$uni_m" .load)"
  uni_w="${uni_w#php}"
  case "$uni_w" in [0-9]*.[0-9]*) ;; *) continue ;; esac
  if [ $(( ${uni_w%%.*} * 100 + ${uni_w#*.} )) -lt 704 ]; then
    uni_fehler="$uni_fehler Der Webserver (Apache) nutzt PHP $uni_w."
  fi
done
if [ -n "$uni_fehler" ]; then
  echo "FEHLER: Dieses Plugin braucht PHP 7.4 oder neuer mit den Erweiterungen curl und openssl. $uni_fehler"
  echo "Das Plugin wurde NICHT installiert. Auf Debian 11 und 12 liefert LoxBerry 4.0 PHP 7.4, auf Debian 13 (Trixie) PHP 8.4; ältere Systeme (Debian 10) haben nur PHP 7.3."
  echo "Anleitung zum Upgrade der Debian-Version: https://wiki.loxberry.de/installation_von_loxberry/upgrade_distribution/start"
  exit 2
fi
exit 0
