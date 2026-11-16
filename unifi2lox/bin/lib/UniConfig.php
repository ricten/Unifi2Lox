<?php
/**
 * Konfiguration des Plugins (unifi.json, Rechte 0600).
 * Enthaelt das Passwort des lokalen UniFi-Kontos und ggf. das Passwort eines eigenen MQTT-Brokers: nie anzeigen, nie loggen, nie per MQTT senden.
 *
 * Die Geraete kommen vom Gateway (stat/device); hier stehen nur die Einstellungen je Geraet, gespeichert unter der MAC-Adresse:
 * aktiv, Name im Topic, welche Ports veroeffentlicht werden und fuer welche Ports Befehle freigegeben sind (ab Werk keine).
 */
final class UniConfig
{
    public static function defaultGeraet(): array
    {
        return ['aktiv' => true, 'name' => '', 'ports' => '', 'befehle_ports' => ''];
    }

    public static function defaults(): array
    {
        return [
            'controller' => ['adresse' => '', 'benutzer' => '', 'passwort' => '', 'seite' => 'default', 'tls_pruefen' => false],
            'abfrage_abstand' => 30,     // Sekunden zwischen zwei Abfragen
            'verkehr' => false,          // Datenraten (rx_bps, tx_bps) je Port veroeffentlichen
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
            'geraete' => [],             // MAC => Einstellungen
        ];
    }

    /** MAC-Adresse als 12 Hexziffern in Kleinbuchstaben ('' bei Unsinn). */
    public static function cleanMac(string $s): string
    {
        $m = strtolower((string)preg_replace('/[^0-9a-fA-F]/', '', $s));
        return strlen($m) === 12 ? $m : '';
    }

    /** Adresse des Gateways: Name oder IP, optional mit https://, http:// und Port. Ohne Schema gilt https. */
    public static function cleanAdresse(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }
        $s = rtrim((string)preg_replace('#[^A-Za-z0-9_.:/\[\]-]#', '', $s), '/');
        if (!preg_match('#^(https?)://([A-Za-z0-9_.\[\]-]+(?::[0-9]{1,5})?)$#i', $s, $m)) {
            return '';
        }
        return strtolower($m[1]) . '://' . $m[2];
    }

    public static function cleanSeite(string $s): string
    {
        $s = (string)preg_replace('/[^A-Za-z0-9_-]/', '', trim($s));
        return $s !== '' ? $s : 'default';
    }

    /**
     * Portliste wie "1-8, 22, 24" in eine sortierte Liste von Portnummern umwandeln. Leer = null (alle Ports).
     * @return list<int>|null
     */
    public static function parsePorts(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $s) as $teil) {
            if ($teil === '') {
                continue;
            }
            if (preg_match('/^(\d{1,3})-(\d{1,3})$/', $teil, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                for ($i = min($a, $b); $i <= max($a, $b) && $i <= 999; $i++) {
                    $out[$i] = true;
                }
            } elseif (preg_match('/^\d{1,3}$/', $teil) && (int)$teil > 0) {
                $out[(int)$teil] = true;
            }
        }
        $k = array_keys($out);
        sort($k);
        return $k;
    }

    /** Portliste bereinigen (nur Ziffern, Komma, Minus, Leerzeichen), fuer die Speicherung. */
    public static function cleanPortText(string $s): string
    {
        return trim((string)preg_replace('/[\s,;]+/', ', ', (string)preg_replace('/[^0-9,\- ;]/', '', $s)), ' ,;');
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $out['controller'] = array_merge($def['controller'], is_array($c['controller'] ?? null) ? $c['controller'] : []);
        $out['controller']['adresse'] = self::cleanAdresse((string)$out['controller']['adresse']);
        $out['controller']['seite'] = self::cleanSeite((string)$out['controller']['seite']);
        $out['controller']['tls_pruefen'] = !empty($out['controller']['tls_pruefen']);
        $out['abfrage_abstand'] = max(getenv('UNI_DEV_DIR') ? 1 : 10, min(600, (int)$out['abfrage_abstand']));
        $out['verkehr'] = !empty($out['verkehr']);
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        $g = [];
        foreach (is_array($c['geraete'] ?? null) ? $c['geraete'] : [] as $mac => $x) {
            $mac = self::cleanMac((string)$mac);
            if ($mac === '' || !is_array($x)) {
                continue;
            }
            $x = array_merge(self::defaultGeraet(), $x);
            $g[$mac] = ['aktiv' => !empty($x['aktiv']), 'name' => Util::name((string)$x['name']), 'ports' => self::cleanPortText((string)$x['ports']),
                'befehle_ports' => self::cleanPortText((string)$x['befehle_ports'])];
        }
        $out['geraete'] = $g;
        return $out;
    }

    /** Sind Adresse, Benutzer und Passwort eingetragen? */
    public static function verbindungVollstaendig(array $cfg): bool
    {
        $c = $cfg['controller'];
        return (string)$c['adresse'] !== '' && trim((string)$c['benutzer']) !== '' && (string)$c['passwort'] !== '';
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren (jeder Reiter sendet nur seine Abschnitte). */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        if (is_array($post['controller'] ?? null)) {
            $k = $post['controller'];
            $pw = (string)($k['passwort'] ?? '');
            $new['controller'] = [
                'adresse' => self::cleanAdresse((string)($k['adresse'] ?? '')),
                'benutzer' => trim((string)($k['benutzer'] ?? '')),
                'passwort' => $pw !== '' ? $pw : (string)$old['controller']['passwort'],
                'seite' => self::cleanSeite((string)($k['seite'] ?? 'default')),
                'tls_pruefen' => !empty($k['tls_pruefen']),
            ];
        }
        if (isset($post['abfrage_abstand'])) {
            $new['abfrage_abstand'] = max(10, min(600, (int)$post['abfrage_abstand']));
        }
        if (isset($post['verkehr_gesendet'])) {
            $new['verkehr'] = !empty($post['verkehr']);
        }
        if (is_array($post['geraete'] ?? null)) {
            $names = [];
            foreach ($post['geraete'] as $mac => $p) {
                $mac = self::cleanMac((string)$mac);
                if ($mac === '' || !is_array($p)) {
                    continue;
                }
                // Ein leeres Namensfeld bleibt leer: der Name kommt dann vom Gateway (nicht fest speichern, sonst aendern sich die Topics)
                $name = Util::name((string)($p['name'] ?? ''));
                if ($name !== '') {
                    $base = $name;
                    for ($n = 2; isset($names[$name]); $n++) {
                        $name = $base . '_' . $n;
                    }
                    $names[$name] = true;
                }
                $new['geraete'][$mac] = ['aktiv' => !empty($p['aktiv']), 'name' => $name, 'ports' => self::cleanPortText((string)($p['ports'] ?? '')),
                    'befehle_ports' => self::cleanPortText((string)($p['befehle_ports'] ?? ''))];
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return $new;
    }

    /**
     * Namen der Geraete im Topic: gewaehlter Name, sonst Name vom Gateway, sonst <typ><Nummer>; doppelte Namen bekommen eine Nummer.
     * @param list<array{mac:string,name:string,typ:string}> $gefunden
     * @return array<string,string> MAC => Name (nur aktive Geraete)
     */
    public static function namen(array $cfg, array $gefunden): array
    {
        $out = [];
        $used = [];
        foreach ($gefunden as $i => $d) {
            $mac = (string)$d['mac'];
            $c = array_merge(self::defaultGeraet(), $cfg['geraete'][$mac] ?? []);
            if (empty($c['aktiv'])) {
                continue;
            }
            $name = Util::name((string)$c['name']);
            if ($name === '') {
                $name = Util::name((string)($d['name'] ?? ''));
            }
            $name = $name !== '' ? $name : Util::name((string)($d['typ'] ?? 'geraet')) . ($i + 1);
            $base = $name;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $used[$name] = true;
            $out[$mac] = $name;
        }
        return $out;
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }

    /** Alle Geheimnisse der Konfiguration (fuer die Maskierung im Log). @return list<string> */
    public static function secrets(array $cfg): array
    {
        $s = [(string)$cfg['controller']['passwort'], (string)$cfg['mqtt']['passwort']];
        return array_values(array_filter($s, fn($x) => strlen($x) >= 4));
    }
}
