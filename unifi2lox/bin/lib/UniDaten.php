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
                    'uplink' => !empty($p['is_uplink']), 'poe' => $poe, 'poe_an' => $gut, 'poe_modus' => (string)($p['poe_mode'] ?? ''),
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
                'typ' => self::typ((string)($d['type'] ?? '')), 'modell' => (string)($d['model'] ?? ''), 'online' => (int)($d['state'] ?? 0) === 1,
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
        foreach ($sta as $c) {
            $info = ['name' => self::clientName($c), 'mac' => (string)($c['mac'] ?? ''), 'ip' => (string)($c['ip'] ?? '')];
            if (!empty($c['is_wired'])) {
                $kabel++;
                $sm = UniConfig::cleanMac((string)($c['sw_mac'] ?? ''));
                $sp = (int)($c['sw_port'] ?? 0);
                if ($sm !== '' && isset($geraete[$sm]['ports'][$sp])) {
                    $info['rate'] = (int)($c['wired_rate_mbps'] ?? 0);
                    $geraete[$sm]['ports'][$sp]['clients'][] = $info;
                }
            } else {
                $wlan++;
                $am = UniConfig::cleanMac((string)($c['ap_mac'] ?? ''));
                if ($am !== '' && isset($geraete[$am])) {
                    $info['essid'] = (string)($c['essid'] ?? '');
                    $info['band'] = ['ng' => '2g', 'na' => '5g', '6e' => '6g'][(string)($c['radio'] ?? '')] ?? (string)($c['radio'] ?? '');
                    $info['signal'] = isset($c['signal']) ? (int)$c['signal'] : (isset($c['rssi']) ? (int)$c['rssi'] : null);
                    $geraete[$am]['wlan_clients'][] = $info;
                }
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
        return ['geraete' => array_values($geraete), 'clients' => ['gesamt' => count($sta), 'kabel' => $kabel, 'wlan' => $wlan], 'poe_gesamt_w' => $poe];
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
