<?php
/**
 * Aus den Rohdaten des Gateways (stat/device, stat/sta) ein Modell bauen und daraus die MQTT-Werte ableiten.
 * Reine Funktionen ohne Netzzugriff (gut zu testen).
 *
 * Zuordnung: Kabel-Clients haengen ueber sw_mac und sw_port an einem Switch-Port, WLAN-Clients ueber ap_mac an einem Access Point.
 * Ein UniFi-Geraet am Port (z. B. ein Access Point am Switch) steht nicht in stat/sta, sondern im Feld uplink des angeschlossenen Geraets.
 */
final class UniDaten
{
    /** Geraeteart aus dem Typ der Schnittstelle. */
    public static function typ(string $t): string
    {
        $t = strtolower($t);
        if (in_array($t, ['ugw', 'udm', 'uxg', 'udr', 'ucg'], true)) {
            return 'gateway';
        }
        if ($t === 'usw') {
            return 'switch';
        }
        if (in_array($t, ['uap', 'udb', 'ubb'], true)) {
            return 'ap';
        }
        return 'andere';
    }

    /** Modellcodes des Gateways => Bezeichnung von Ubiquiti (nur sichere Zuordnungen; unbekannte Codes bleiben unveraendert). */
    private const KLARNAMEN = [
        // Access Points
        'U7PG2' => 'UAP-AC-Pro', 'U7LR' => 'UAP-AC-LR', 'U7LT' => 'UAP-AC-Lite', 'U7HD' => 'UAP-AC-HD', 'U7SHD' => 'UAP-AC-SHD', 'U7NHD' => 'UAP-nanoHD',
        'U7MSH' => 'UAP-AC-M', 'U7MP' => 'UAP-AC-M-Pro', 'U7IW' => 'UAP-AC-IW', 'U7IWP' => 'UAP-AC-IW-Pro',
        'UAL6' => 'U6-Lite', 'UAP6MP' => 'U6-Pro', 'U6LR' => 'U6-LR', 'U6IW' => 'U6-IW', 'U6EXT' => 'U6-Extender', 'U6E' => 'U6-Enterprise',
        'U7PRO' => 'U7-Pro', 'U7PROMAX' => 'U7-Pro-Max',
        // Switches
        'US8' => 'US-8', 'US8P60' => 'US-8-60W', 'US8P150' => 'US-8-150W', 'US16P150' => 'US-16-150W', 'US24' => 'US-24', 'US24P250' => 'US-24-250W', 'US24P500' => 'US-24-500W',
        'US48' => 'US-48', 'US48P500' => 'US-48-500W', 'US48P750' => 'US-48-750W', 'US24PL2' => 'USW-24-PoE', 'USL8LP' => 'USW-Lite-8-PoE', 'USL16LP' => 'USW-Lite-16-PoE',
        'USMINI' => 'USW-Flex-Mini', 'USF5P' => 'USW-Flex',
        // Gateways
        'UDMA6A8' => 'UCG-Fiber', 'UDM' => 'UDM', 'UDMPRO' => 'UDM-Pro', 'UDMSE' => 'UDM-SE', 'UDMPROMAX' => 'UDM-Pro-Max', 'UDR' => 'UDR',
        'UGW3' => 'USG-3P', 'UGW4' => 'USG-Pro-4', 'UXGPRO' => 'UXG-Pro',
    ];

    /** Bezeichnung zum Modellcode (z. B. U7PG2 => UAP-AC-Pro); unbekannte Codes kommen unveraendert zurueck. Nur fuer die Weboberflaeche: in MQTT steht der Code. */
    public static function klarname(string $code): string
    {
        return self::KLARNAMEN[strtoupper(trim($code))] ?? $code;
    }

    /** Zahl ohne unnoetige Nullen (Punkt als Dezimaltrenner). */
    public static function zahl($v, int $stellen = 2): string
    {
        $f = round((float)$v, $stellen);
        return $f == (int)$f ? (string)(int)$f : rtrim(rtrim(number_format($f, $stellen, '.', ''), '0'), '.');
    }

    private static function clientName(array $c): string
    {
        foreach (['name', 'hostname'] as $k) {
            if (isset($c[$k]) && trim((string)$c[$k]) !== '') {
                return trim((string)$c[$k]);
            }
        }
        return (string)($c['mac'] ?? '?');
    }

    /**
     * Modell aus den Rohdaten bauen.
     * @param list<array> $devs stat/device
     * @param list<array> $sta stat/sta
     * @return array{geraete:list<array>,clients:array{gesamt:int,kabel:int,wlan:int},poe_gesamt_w:float}
     */
    public static function modell(array $devs, array $sta): array
    {
        $geraete = [];
        foreach ($devs as $d) {
            $mac = UniConfig::cleanMac((string)($d['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }
            $ports = [];
            $poeSumme = 0.0;
            foreach (is_array($d['port_table'] ?? null) ? $d['port_table'] : [] as $p) {
                $idx = (int)($p['port_idx'] ?? 0);
                if ($idx <= 0) {
                    continue;
                }
                $up = !empty($p['up']);
                $poe = !empty($p['port_poe']);
                $gut = $poe && !empty($p['poe_good']);
                $w = $gut ? (float)($p['poe_power'] ?? 0) : 0.0;
                $poeSumme += $w;
                $ports[$idx] = [
                    'idx' => $idx, 'name' => (string)($p['name'] ?? ''), 'up' => $up, 'speed' => $up ? (int)($p['speed'] ?? 0) : 0, 'media' => (string)($p['media'] ?? ''),
                    'uplink' => !empty($p['is_uplink']), 'aktiv' => ($p['enable'] ?? true) !== false && (string)($p['forward'] ?? '') !== 'disabled', 'poe' => $poe, 'poe_an' => $gut, 'poe_modus' => (string)($p['poe_mode'] ?? ''),
                    'poe_w' => $w, 'poe_v' => $gut ? (float)($p['poe_voltage'] ?? 0) : 0.0, 'poe_ma' => $gut ? (float)($p['poe_current'] ?? 0) : 0.0,
                    'rx_bps' => (int)round((float)($p['rx_bytes-r'] ?? 0) * 8), 'tx_bps' => (int)round((float)($p['tx_bytes-r'] ?? 0) * 8),
                    'clients' => [], 'gegenstelle' => '',
                ];
            }
            ksort($ports);
            $funk = [];
            foreach (is_array($d['radio_table_stats'] ?? null) ? $d['radio_table_stats'] : [] as $r) {
                $band = ['ng' => '2g', 'na' => '5g', '6e' => '6g'][(string)($r['radio'] ?? '')] ?? '';
                if ($band !== '') {
                    $funk[$band] = (int)($r['num_sta'] ?? 0);
                }
            }
            $max = (float)($d['total_max_power'] ?? 0);
            $geraete[$mac] = [
                'mac' => $mac, 'name' => trim((string)($d['name'] ?? '')) !== '' ? trim((string)$d['name']) : (string)($d['model'] ?? $mac),
                'typ' => self::typ((string)($d['type'] ?? '')), 'modell' => (string)($d['model'] ?? ''), 'modell_name' => self::klarname((string)($d['model'] ?? '')), 'online' => (int)($d['state'] ?? 0) === 1,
                'clients' => (int)($d['num_sta'] ?? 0), 'clients_gast' => (int)($d['guest-num_sta'] ?? 0), 'funk' => $funk,
                'poe_gesamt_w' => $max > 0 ? (float)($d['total_used_power'] ?? $poeSumme) : $poeSumme, 'poe_max_w' => $max,
                'ports' => $ports, 'wlan_clients' => [], 'roh_id' => (string)($d['_id'] ?? ''),
                'uplink_mac' => ((string)($d['uplink']['type'] ?? '') === 'wire') ? UniConfig::cleanMac((string)($d['uplink']['uplink_mac'] ?? '')) : '',
                'uplink_port' => (int)($d['uplink']['uplink_remote_port'] ?? 0),
            ];
        }
        // Clients zuordnen
        $kabel = 0;
        $wlan = 0;
        $liste = [];                    // alle verbundenen Clients (nur fuer die Weboberflaeche und den Online-Status ausgewaehlter Clients)
        foreach ($sta as $c) {
            $info = ['name' => self::clientName($c), 'mac' => (string)($c['mac'] ?? ''), 'ip' => (string)($c['ip'] ?? '')];
            $ort = '';
            if (!empty($c['is_wired'])) {
                $kabel++;
                $sm = UniConfig::cleanMac((string)($c['sw_mac'] ?? ''));
                $sp = (int)($c['sw_port'] ?? 0);
                if ($sm !== '' && isset($geraete[$sm]['ports'][$sp])) {
                    $info['rate'] = (int)($c['wired_rate_mbps'] ?? 0);
                    $geraete[$sm]['ports'][$sp]['clients'][] = $info;
                    $ort = $geraete[$sm]['name'] . ' · Port ' . $sp;
                }
            } else {
                $wlan++;
                $am = UniConfig::cleanMac((string)($c['ap_mac'] ?? ''));
                if ($am !== '' && isset($geraete[$am])) {
                    $info['essid'] = (string)($c['essid'] ?? '');
                    $info['band'] = ['ng' => '2g', 'na' => '5g', '6e' => '6g'][(string)($c['radio'] ?? '')] ?? (string)($c['radio'] ?? '');
                    $info['signal'] = isset($c['signal']) ? (int)$c['signal'] : (isset($c['rssi']) ? (int)$c['rssi'] : null);
                    $geraete[$am]['wlan_clients'][] = $info;
                    $ort = $geraete[$am]['name'] . ' · ' . (['2g' => '2,4 GHz', '5g' => '5 GHz', '6g' => '6 GHz'][$info['band']] ?? $info['band']) . ($info['essid'] !== '' ? ' · ' . $info['essid'] : '');
                }
            }
            $mac12 = UniConfig::cleanMac($info['mac']);
            if ($mac12 !== '') {
                $liste[] = ['mac' => $mac12, 'name' => $info['name'], 'ip' => $info['ip'], 'kabel' => !empty($c['is_wired']), 'ort' => $ort];
            }
        }
        // UniFi-Geraete am Port (Access Point am Switch usw.)
        foreach ($geraete as $mac => $g) {
            if ($g['uplink_mac'] !== '' && isset($geraete[$g['uplink_mac']]['ports'][$g['uplink_port']])) {
                $geraete[$g['uplink_mac']]['ports'][$g['uplink_port']]['gegenstelle'] = $g['name'];
            }
        }
        $poe = 0.0;
        foreach ($geraete as $g) {
            $poe += $g['poe_gesamt_w'];
        }
        return ['geraete' => array_values($geraete), 'clients' => ['gesamt' => count($sta), 'kabel' => $kabel, 'wlan' => $wlan], 'poe_gesamt_w' => $poe, 'client_liste' => $liste];
    }

    /**
     * Alle dem Gateway bekannten Clients (rest/user), auch die gerade nicht verbundenen. Nur fuer die Weboberflaeche.
     * @param list<array> $user
     * @return list<array{mac:string,name:string,ip:string,kabel:bool,zuletzt:int}>
     */
    public static function bekannte(array $user): array
    {
        $out = [];
        foreach ($user as $c) {
            $mac = UniConfig::cleanMac((string)($c['mac'] ?? ''));
            if ($mac === '' || !is_array($c)) {
                continue;
            }
            $ip = trim((string)($c['last_ip'] ?? '')) !== '' ? (string)$c['last_ip'] : (string)($c['fixed_ip'] ?? '');
            $out[] = ['mac' => $mac, 'name' => self::clientName($c), 'ip' => $ip, 'kabel' => !empty($c['is_wired']), 'zuletzt' => (int)($c['last_seen'] ?? 0)];
        }
        return $out;
    }

    /**
     * Alle Clients zusammengefuehrt: gerade verbundene (online) und nur bekannte (offline), je MAC (12 Hexziffern).
     * name = '' wenn das Gateway weder Namen noch Hostnamen kennt.
     * @param array $m Ergebnis von modell()
     * @param list<array> $bekannt Ergebnis von bekannte()
     * @return array<string,array{name:string,ip:string,online:bool,kabel:bool,ort:string,zuletzt:int}>
     */
    public static function clientAnzeige(array $m, array $bekannt): array
    {
        $echt = function (string $name, string $mac): string {
            return UniConfig::cleanMac($name) === $mac ? '' : $name;      // clientName() liefert ohne Namen die MAC
        };
        $out = [];
        foreach ($bekannt as $b) {
            $out[$b['mac']] = ['name' => $echt($b['name'], $b['mac']), 'ip' => $b['ip'], 'online' => false, 'kabel' => $b['kabel'], 'ort' => '', 'zuletzt' => $b['zuletzt']];
        }
        foreach ($m['client_liste'] ?? [] as $c) {
            $alt = $out[$c['mac']] ?? null;
            $name = $echt($c['name'], $c['mac']);
            $out[$c['mac']] = ['name' => $name !== '' ? $name : ($alt['name'] ?? ''), 'ip' => $c['ip'] !== '' ? $c['ip'] : ($alt['ip'] ?? ''), 'online' => true,
                'kabel' => $c['kabel'], 'ort' => $c['ort'], 'zuletzt' => 0];
        }
        return $out;
    }

    /**
     * MQTT-Werte der ausgewaehlten Clients (Topic-Rest unter unifi/client/): <name>/online = 1 oder 0.
     * @param array<string,array> $anzeige Ergebnis von clientAnzeige()
     * @param array<string,string> $namen MAC => Name im Topic
     */
    public static function clientWerte(array $anzeige, array $namen): array
    {
        $w = [];
        foreach ($namen as $mac => $name) {
            $w[$name . '/online'] = !empty($anzeige[$mac]['online']) ? '1' : '0';
        }
        return $w;
    }

    /**
     * MQTT-Werte eines Geraets (Topic-Rest => Wert, ohne Wurzel und Geraetenamen).
     * @param array $g Geraet aus modell()
     * @param list<int>|null $portFilter nur diese Ports (null = alle)
     */
    public static function werte(array $g, ?array $portFilter, bool $verkehr): array
    {
        $w = ['online' => $g['online'] ? '1' : '0', 'typ' => $g['typ'], 'modell' => $g['modell'], 'clients' => (string)$g['clients']];
        if ($g['typ'] === 'ap' || $g['funk']) {
            $w['clients_gast'] = (string)$g['clients_gast'];
            foreach ($g['funk'] as $band => $n) {
                $w['clients_' . $band] = (string)$n;
            }
        }
        $hatPoe = $g['poe_max_w'] > 0;
        foreach ($g['ports'] as $p) {
            if ($p['poe']) {
                $hatPoe = true;
            }
        }
        if ($hatPoe) {
            $w['poe_gesamt_w'] = self::zahl($g['poe_gesamt_w']);
            if ($g['poe_max_w'] > 0) {
                $w['poe_max_w'] = self::zahl($g['poe_max_w']);
            }
        }
        foreach ($g['ports'] as $idx => $p) {
            if ($portFilter !== null && !in_array($idx, $portFilter, true)) {
                continue;
            }
            $b = 'port' . $idx . '/';
            $w[$b . 'up'] = $p['up'] ? '1' : '0';
            $w[$b . 'aktiv'] = $p['aktiv'] ? '1' : '0';
            $w[$b . 'speed_mbit'] = (string)$p['speed'];
            $w[$b . 'clients'] = (string)count($p['clients']);
            if ($p['poe']) {
                $w[$b . 'poe_an'] = $p['poe_an'] ? '1' : '0';
                $w[$b . 'poe_w'] = self::zahl($p['poe_w']);
                $w[$b . 'poe_ma'] = self::zahl($p['poe_ma'], 1);
            }
            if ($verkehr) {
                $w[$b . 'rx_bps'] = (string)$p['rx_bps'];
                $w[$b . 'tx_bps'] = (string)$p['tx_bps'];
            }
        }
        return $w;
    }

    /** MQTT-Werte ueber das ganze Netz (Topic-Rest unter unifi/netz/). */
    public static function netzWerte(array $m): array
    {
        return ['clients' => (string)$m['clients']['gesamt'], 'clients_kabel' => (string)$m['clients']['kabel'], 'clients_wlan' => (string)$m['clients']['wlan'],
            'poe_gesamt_w' => self::zahl($m['poe_gesamt_w'])];
    }
}
