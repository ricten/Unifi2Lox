<?php
/**
 * Erfundene Beispieldaten im Aufbau der Antworten eines UniFi-Gateways (stat/device, stat/sta); der Aufbau entspricht einer Messung
 * an einem Cloud Gateway mit Switch und zwei Access Points. Alle MAC-Adressen und IPs sind erfunden (02:00:… und 192.0.2.x).
 *
 *   Gateway  (udm)  MAC ...01   7 Ports
 *   Switch   (usw)  MAC ...02  26 Ports, 24 mit PoE (Budget 220 W), Uplink an Port 22, Access Points an Port 4 und 6
 *   AP 1     (uap)  MAC ...03  6 Clients (4 im 2,4-, 2 im 5-GHz-Band), davon 1 Gast
 *   AP 2     (uap)  MAC ...04  2 Clients (beide 5 GHz)
 *   Internet (stat/health, uplink/wan1/wan2 des Gateways): erfundener Anbieter, ASN 64496 und IP 203.0.113.7 (Dokumentationsbereiche), PPPoE-Sitzung 126613 s
 *   Bekannte Clients (rest/user): die 19 verbundenen plus 3 derzeit nicht verbundene (MAC 02:00:00:00:30:xx)
 */
const FX_GW = '02:00:00:00:00:01';
const FX_SW = '02:00:00:00:00:02';
const FX_AP1 = '02:00:00:00:00:03';
const FX_AP2 = '02:00:00:00:00:04';

function fx_geraete(): array
{
    $gw = ['_id' => 'id-gw', 'mac' => FX_GW, 'type' => 'udm', 'model' => 'UDMA6A8', 'name' => 'Gateway', 'state' => 1, 'num_sta' => 19, 'guest-num_sta' => 1,
        'total_max_power' => 30, 'total_used_power' => 0, 'port_table' => [],
        'uplink' => ['type' => 'wire', 'uplink_mac' => '02:00:00:00:99:99', 'uplink_remote_port' => 1, 'up' => true, 'name' => 'ppp0', 'uptime' => 126613, 'latency' => 6, 'ip' => '203.0.113.7', 'speed' => 10000],
        'wan1' => ['up' => true, 'speed' => 1000, 'ip' => '203.0.113.7', 'name' => 'eth4'], 'wan2' => ['up' => false, 'speed' => 10, 'name' => 'eth6']];
    for ($i = 1; $i <= 7; $i++) {
        $gw['port_table'][] = ['port_idx' => $i, 'name' => 'Port ' . $i, 'up' => in_array($i, [1, 5], true), 'speed' => in_array($i, [1, 5], true) ? 1000 : 10, 'media' => $i >= 6 ? 'SFP+' : '2.5GE',
            'is_uplink' => false, 'port_poe' => $i === 4, 'poe_enable' => false, 'poe_good' => false, 'poe_power' => '0.00', 'poe_voltage' => $i === 4 ? '1.12' : '0.00', 'poe_current' => '0.00',
            'rx_bytes-r' => $i === 1 ? 1250 : 0, 'tx_bytes-r' => $i === 1 ? 2500 : 0];
    }
    $poe = [4 => [6.19, 53.08, 116.69], 6 => [2.53, 53.14, 47.60], 11 => [3.29, 53.20, 61.76], 13 => [9.09, 53.65, 169.43], 23 => [9.97, 53.33, 186.88]];
    $up = [1, 2, 3, 4, 6, 9, 10, 11, 12, 13, 14, 17, 18, 19, 20, 21, 22, 23];
    $sw = ['_id' => 'id-sw', 'mac' => FX_SW, 'type' => 'usw', 'model' => 'US24P250', 'name' => 'Switch Keller', 'state' => 1, 'num_sta' => 11, 'guest-num_sta' => 0,
        'total_max_power' => 220, 'total_used_power' => 31.07, 'port_table' => [], 'port_overrides' => [],
        'uplink' => ['type' => 'wire', 'uplink_mac' => FX_GW, 'uplink_remote_port' => 5]];
    for ($i = 1; $i <= 26; $i++) {
        $p = ['port_idx' => $i, 'name' => 'Port ' . $i, 'up' => in_array($i, $up, true), 'speed' => in_array($i, $up, true) ? ($i <= 3 || $i === 9 || $i === 10 || $i === 12 || $i === 13 || $i === 14 || $i === 23 ? 100 : 1000) : 0,
            'media' => $i > 24 ? 'SFP' : 'GE', 'is_uplink' => $i === 22, 'portconf_id' => 'pc-all', 'port_poe' => $i <= 24, 'poe_mode' => $i <= 24 ? 'auto' : null,
            'rx_bytes-r' => $i === 18 ? 500000 : 0, 'tx_bytes-r' => $i === 18 ? 125000 : 0, 'mac_table' => [], 'lldp_table' => []];
        if ($i <= 24) {
            $g = isset($poe[$i]);
            $p += ['poe_enable' => $g, 'poe_good' => $g, 'poe_power' => $g ? number_format($poe[$i][0], 2, '.', '') : '0.00', 'poe_voltage' => $g ? number_format($poe[$i][1], 2, '.', '') : '0.00',
                'poe_current' => $g ? number_format($poe[$i][2], 2, '.', '') : '0.00', 'poe_class' => $g ? 'Class 4' : 'Unknown'];
            $sw['port_overrides'][] = ['port_idx' => $i, 'poe_mode' => 'auto', 'name' => 'Port ' . $i, 'portconf_id' => 'pc-all', 'op_mode' => 'switch'];
        }
        if ($i === 15 || $i === 16 || $i === 26) {           // wie am echten Gateway: ohne Port-Profil, die Weiterleitung steht am Port
            unset($p['portconf_id']);
            $p += ['forward' => $i === 16 ? 'customize' : 'all', 'enable' => true, 'native_networkconf_id' => 'net-1', 'tagged_vlan_mgmt' => $i === 16 ? 'custom' : 'auto', 'setting_preference' => 'auto', 'port_security_enabled' => false];
        }
        $sw['port_table'][] = $p;
    }
    foreach ($sw['port_overrides'] as &$o) {
        if ($o['port_idx'] === 15 || $o['port_idx'] === 16) {
            unset($o['portconf_id']);
            $o += ['setting_preference' => 'auto', 'port_security_enabled' => false, 'forward' => $o['port_idx'] === 16 ? 'customize' : 'all', 'native_networkconf_id' => 'net-1', 'tagged_vlan_mgmt' => $o['port_idx'] === 16 ? 'custom' : 'auto'];
            if ($o['port_idx'] === 16) {
                $o['excluded_networkconf_ids'] = ['net-2'];
            }
        }
    }
    unset($o);
    $ap = function (string $id, string $mac, string $name, int $port, int $n, int $gast, int $ng, int $na) {
        return ['_id' => $id, 'mac' => $mac, 'type' => 'uap', 'model' => 'UAP6MP', 'name' => $name, 'state' => 1, 'num_sta' => $n, 'user-num_sta' => $n, 'guest-num_sta' => $gast,
            'radio_table_stats' => [['radio' => 'ng', 'channel' => 3, 'num_sta' => $ng], ['radio' => 'na', 'channel' => 48, 'num_sta' => $na]],
            'uplink' => ['type' => 'wire', 'uplink_mac' => FX_SW, 'uplink_remote_port' => $port], 'port_table' => []];
    };
    return [$gw, $sw, $ap('id-ap1', FX_AP1, 'AP Wohnzimmer', 4, 6, 1, 4, 2), $ap('id-ap2', FX_AP2, 'AP Dachboden', 6, 2, 0, 0, 2)];
}

/** Antwort von stat/health (Auszug): Subsystem wan mit Anbieter und WAN-IP, www mit Latenz, Datenrate und Speedtest. */
function fx_health(int $xputDown = 0): array
{
    return [
        ['subsystem' => 'wan', 'status' => 'ok', 'isp_name' => 'Beispiel Telekom AG', 'isp_organization' => 'Beispiel Telekom AG', 'asn' => 64496, 'wan_ip' => '203.0.113.7',
            'uptime_stats' => ['WAN' => ['availability' => 99.9, 'latency_average' => 7, 'uptime' => 736261], 'WAN2' => ['downtime' => 736277]]],
        ['subsystem' => 'www', 'status' => 'ok', 'latency' => 6, 'drops' => 8, 'rx_bytes-r' => 1250000, 'tx_bytes-r' => 62500, 'uptime' => 126613, 'speedtest_lastrun' => 1700000000, 'speedtest_ping' => $xputDown > 0 ? 9 : 0,
            'xput_down' => (float)$xputDown, 'xput_up' => $xputDown > 0 ? 45.5 : 0.0],
        ['subsystem' => 'lan', 'status' => 'ok', 'num_user' => 29],
    ];
}

/** Antwort von rest/user: alle bekannten Clients, auch die nicht verbundenen. */
function fx_bekannte(): array
{
    $out = [];
    foreach (fx_clients() as $c) {
        $e = ['mac' => $c['mac'], 'is_wired' => $c['is_wired'], 'last_ip' => $c['ip'], 'last_seen' => 1700000000, 'blocked' => false, 'noted' => false];
        foreach (['name', 'hostname'] as $k) {
            if (isset($c[$k])) {
                $e[$k] = $c[$k];
            }
        }
        $out[] = $e;
    }
    $out[] = ['mac' => '02:00:00:00:30:01', 'is_wired' => true, 'name' => 'Alter Drucker', 'last_ip' => '192.0.2.201', 'last_seen' => 1699900000];
    $out[] = ['mac' => '02:00:00:00:30:02', 'is_wired' => false, 'hostname' => 'handy-gast', 'fixed_ip' => '192.0.2.202', 'last_seen' => 1690000000];
    $out[] = ['mac' => '02:00:00:00:30:03', 'is_wired' => false, 'last_seen' => 1600000000];
    return $out;
}

function fx_clients(): array
{
    $out = [];
    $n = 0;
    foreach ([1 => 1, 2 => 1, 3 => 1, 9 => 1, 14 => 2, 18 => 3, 20 => 1, 21 => 1] as $port => $anz) {
        for ($k = 0; $k < $anz; $k++) {
            $n++;
            $c = ['mac' => sprintf('02:00:00:00:10:%02x', $n), 'ip' => '192.0.2.' . (10 + $n), 'is_wired' => true, 'sw_mac' => FX_SW, 'sw_port' => $port, 'wired_rate_mbps' => 1000];
            if ($n % 3 === 0) {
                $c['name'] = 'Kabel-Client ' . $n;
            } elseif ($n % 3 === 1) {
                $c['hostname'] = 'host' . $n;
            }
            $out[] = $c;
        }
    }
    foreach ([[FX_AP1, 'ng', 'Heim'], [FX_AP1, 'ng', 'Heim'], [FX_AP1, 'ng', 'Heim'], [FX_AP1, 'ng', 'Gast'], [FX_AP1, 'na', 'Heim'], [FX_AP1, 'na', 'Heim'], [FX_AP2, 'na', 'Heim'], [FX_AP2, 'na', 'Heim']] as $i => [$ap, $radio, $essid]) {
        $out[] = ['mac' => sprintf('02:00:00:00:20:%02x', $i + 1), 'ip' => '192.0.2.' . (100 + $i), 'is_wired' => false, 'ap_mac' => $ap, 'essid' => $essid, 'radio' => $radio, 'signal' => -50 - $i, 'name' => 'WLAN-Client ' . ($i + 1)];
    }
    return $out;
}
