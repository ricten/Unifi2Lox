<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php
 * Teil 1: Unit-Tests (Konfiguration, Modell aus den Rohdaten, MQTT-Werte, Befehle) mit erfundenen Beispieldaten im Aufbau der Antworten eines
 *         UniFi-Gateways (tests/fixture.php).
 * Teil 2: Ende-zu-Ende: der Dienst (unifid.php im Entwicklungsmodus) gegen ein Fake-Gateway (UniFi-OS-Anmeldung, Sitzung, CSRF fuer Aenderungen,
 *         stat/device, stat/sta, PoE-Befehle) und einen Fake-Broker.
 * Teil 3: Healthcheck, Weboberflaeche mit Attrappen, plugin.cfg, build.sh und ZIP.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, das echte Aussehen der Oberflaeche und die PoE-Befehle an einem echten Gateway.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['UniConfig', 'UniController', 'UniDaten', 'UniBefehle', 'AppVersion'] as $c) {
    require_once $root . '/unifi2lox/bin/lib/' . $c . '.php';
}
require_once __DIR__ . '/fixture.php';

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/uni_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/uni_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    if (!is_resource($pp[0])) {
        return;
    }
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}
function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
        }
    }
    @rmdir($d);
}

$dir = sys_get_temp_dir() . '/uni_test_' . getmypid();
rrmdir($dir);
mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Unit: Konfiguration
check('Adresse: ohne Schema gilt https', UniConfig::cleanAdresse('192.0.2.1'), 'https://192.0.2.1');
check('Adresse: http und Port bleiben, Schluss-/ weg, Kleinschreibung des Schemas', [UniConfig::cleanAdresse('HTTP://gw.example.invalid:8443/'), UniConfig::cleanAdresse(' https://gw.lan/ ')], ['http://gw.example.invalid:8443', 'https://gw.lan']);
check('Adresse: Unsinn und Pfade ergeben leer', [UniConfig::cleanAdresse(''), UniConfig::cleanAdresse('ftp://x'), UniConfig::cleanAdresse('https://a/b/c'), UniConfig::cleanAdresse('ht tp://;;')], ['', '', '', '']);
check('MAC: nur 12 Hexziffern, klein', [UniConfig::cleanMac('AA-BB-CC-DD-EE-FF'), UniConfig::cleanMac('xyz'), UniConfig::cleanMac('0200')], ['aabbccddeeff', '', '']);
check('Portliste: Bereiche, Einzelwerte, sortiert, doppelte weg', UniConfig::parsePorts('3, 1-2; 2  8'), [1, 2, 3, 8]);
check('Portliste: leer = null (alle), Unsinn = leere Liste', [UniConfig::parsePorts(''), UniConfig::parsePorts('  '), UniConfig::parsePorts('abc, 0')], [null, null, []]);
check('Portliste: Bereich rueckwaerts', UniConfig::parsePorts('5-3'), [3, 4, 5]);
check('Portliste bereinigen', UniConfig::cleanPortText('1-8;22 ,  24 <b>'), '1-8, 22, 24');
$c0 = UniConfig::defaults();
check('Konfiguration: Standard hat keine Freigaben, Datenraten aus', [$c0['geraete'], $c0['verkehr'], $c0['controller']['tls_pruefen'], $c0['controller']['seite']], [[], false, false, 'default']);
$p1 = UniConfig::fromPost($c0, ['controller' => ['adresse' => 'gw.lan', 'benutzer' => ' lesen ', 'passwort' => 'geheimpw', 'seite' => 'x y', 'tls_pruefen' => '1'], 'abfrage_abstand' => '3']);
check('Konfiguration: Zugang, Site bereinigt, Abstand mindestens 10', [$p1['controller']['adresse'], $p1['controller']['benutzer'], $p1['controller']['passwort'], $p1['controller']['seite'], $p1['controller']['tls_pruefen'], $p1['abfrage_abstand']],
    ['https://gw.lan', 'lesen', 'geheimpw', 'xy', true, 10]);
$p2 = UniConfig::fromPost($p1, ['controller' => ['adresse' => 'gw.lan', 'benutzer' => 'lesen', 'passwort' => '']]);
check('Konfiguration: leeres Passwortfeld behaelt das Passwort, Haken fehlt = aus', [$p2['controller']['passwort'], $p2['controller']['tls_pruefen']], ['geheimpw', false]);
check('Konfiguration: verbindungVollstaendig', [UniConfig::verbindungVollstaendig($c0), UniConfig::verbindungVollstaendig($p2)], [false, true]);
$p3 = UniConfig::fromPost($p2, ['geraete' => ['02:00:00:00:00:02' => ['aktiv' => '1', 'name' => 'Switch Küche!', 'ports' => '1-8', 'befehle_ports' => '4;6', 'schalt_ports' => '9 ; 15'],
    '02:00:00:00:00:03' => ['aktiv' => '1', 'name' => 'Switch Küche!'], 'zz' => ['aktiv' => '1'], '02:00:00:00:00:04' => ['aktiv' => '1', 'name' => '']]]);
check('Konfiguration: Namen ASCII ohne Leerzeichen, doppelte nummeriert, ungueltige MAC fehlt, Portlisten bereinigt',
    [$p3['geraete']['020000000002']['name'], $p3['geraete']['020000000003']['name'], isset($p3['geraete']['zz']), $p3['geraete']['020000000002']['ports'], $p3['geraete']['020000000002']['befehle_ports'], $p3['geraete']['020000000002']['schalt_ports']],
    ['switch_kueche', 'switch_kueche_2', false, '1-8', '4, 6', '9, 15']);
check('Konfiguration: leerer Name bleibt leer (Gateway-Name gilt, nicht fest gespeichert)', $p3['geraete']['020000000004']['name'], '');
check('Konfiguration: Befehle und Ein/Aus ab Werk gesperrt (leer)', [UniConfig::defaultGeraet()['befehle_ports'], UniConfig::defaultGeraet()['schalt_ports']], ['', '']);
$gef = [['mac' => '020000000001', 'name' => 'Gateway', 'typ' => 'gateway'], ['mac' => '020000000002', 'name' => 'Switch Keller', 'typ' => 'switch'], ['mac' => '020000000003', 'name' => 'Switch Keller', 'typ' => 'switch'],
    ['mac' => '020000000004', 'name' => '', 'typ' => 'ap']];
check('Namen: gewaehlter Name, sonst Gateway-Name, sonst Typ+Nummer, doppelte nummeriert', array_values(UniConfig::namen($p3, $gef)), ['gateway', 'switch_kueche', 'switch_kueche_2', 'ap4']);
$p4 = $p3;
$p4['geraete']['020000000001'] = ['aktiv' => false, 'name' => '', 'ports' => '', 'befehle_ports' => ''];
check('Namen: inaktive Geraete fehlen', array_values(UniConfig::namen($p4, $gef)), ['switch_kueche', 'switch_kueche_2', 'ap4']);
check('Geheimnisse: Passwoerter mit mindestens 4 Zeichen', UniConfig::secrets(['controller' => ['passwort' => 'geheimpw'], 'mqtt' => ['passwort' => 'abc']]), ['geheimpw']);
check('Eigener Broker nur mit Haken und Host', [UniConfig::ownBroker($c0), UniConfig::ownBroker(['mqtt' => ['eigener_broker' => true, 'host' => 'b.lan', 'port' => 0, 'benutzer' => 'u', 'passwort' => 'p']])], [null, ['b.lan', 1883, 'u', 'p']]);
file_put_contents("$dir/c.json", json_encode(['controller' => ['adresse' => 'gw.lan', 'seite' => ''], 'abfrage_abstand' => 5, 'geraete' => ['02:00:00:00:00:02' => ['name' => 'ä b']]]));
$cl = UniConfig::load("$dir/c.json");
check('Konfiguration laden: bereinigt und ergaenzt', [$cl['controller']['adresse'], $cl['controller']['seite'], $cl['abfrage_abstand'], $cl['geraete']['020000000002']['name'], $cl['geraete']['020000000002']['aktiv']], ['https://gw.lan', 'default', 10, 'ae_b', true]);

// ---------------------------------------------------------------- Unit: Modell und MQTT-Werte
$devs = fx_geraete();
$sta = fx_clients();
$m = UniDaten::modell($devs, $sta);
$byName = [];
foreach ($m['geraete'] as $g) {
    $byName[$g['name']] = $g;
}
check('Modell: vier Geraete, Arten', array_map(fn($g) => $g['typ'], $m['geraete']), ['gateway', 'switch', 'ap', 'ap']);
check('Modell: Clients gesamt, Kabel, WLAN', $m['clients'], ['gesamt' => 19, 'kabel' => 11, 'wlan' => 8]);
$sw = $byName['Switch Keller'];
check('Modell: Switch hat 26 Ports, 24 mit PoE', [count($sw['ports']), count(array_filter($sw['ports'], fn($p) => $p['poe']))], [26, 24]);
check('Modell: Clients je Switch-Port (Kabel ueber sw_mac und sw_port)', array_map(fn($p) => count($p['clients']), array_filter($sw['ports'], fn($p) => $p['clients'])), [1 => 1, 2 => 1, 3 => 1, 9 => 1, 14 => 2, 18 => 3, 20 => 1, 21 => 1]);
check('Modell: Summe der Port-Clients = Kabel-Clients', array_sum(array_map(fn($p) => count($p['clients']), $sw['ports'])), 11);
check('Modell: Clientname = Name, sonst Hostname, sonst MAC', [$sw['ports'][1]['clients'][0]['name'], $sw['ports'][3]['clients'][0]['name'], $sw['ports'][2]['clients'][0]['name']], ['host1', 'Kabel-Client 3', '02:00:00:00:10:02']);
check('Modell: PoE je Port (nur wenn poe_good), Gesamtleistung und Budget', [$sw['ports'][4]['poe_w'], $sw['ports'][4]['poe_ma'], $sw['ports'][4]['poe_an'], $sw['ports'][5]['poe_w'], $sw['ports'][5]['poe_an'], $sw['poe_gesamt_w'], $sw['poe_max_w']],
    [6.19, 116.69, true, 0.0, false, 31.07, 220.0]);
check('Modell: Link ohne Verbindung hat Speed 0, Uplink erkannt', [$sw['ports'][5]['speed'], $sw['ports'][5]['up'], $sw['ports'][22]['uplink'], $sw['ports'][1]['speed']], [0, false, true, 100]);
check('Modell: UniFi-Geraete am Port (Access Points am Switch, Switch am Gateway)', [$sw['ports'][4]['gegenstelle'], $sw['ports'][6]['gegenstelle'], $byName['Gateway']['ports'][5]['gegenstelle'], $sw['ports'][7]['gegenstelle']],
    ['AP Wohnzimmer', 'AP Dachboden', 'Switch Keller', '']);
$ap1 = $byName['AP Wohnzimmer'];
check('Modell: Access Point Clients, Gaeste, Baender, WLAN-Clients mit Signal', [$ap1['clients'], $ap1['clients_gast'], $ap1['funk'], count($ap1['wlan_clients']), $ap1['wlan_clients'][0]['essid'], $ap1['wlan_clients'][0]['band'], $ap1['wlan_clients'][0]['signal']],
    [6, 1, ['2g' => 4, '5g' => 2], 6, 'Heim', '2g', -50]);
check('Modell: Summe der WLAN-Clients je AP = num_sta', [count($ap1['wlan_clients']), count($byName['AP Dachboden']['wlan_clients'])], [6, 2]);
check('Modell: PoE gesamt im ganzen Netz', round($m['poe_gesamt_w'], 2), 31.07);
$g1 = $devs;
$g1[1]['state'] = 0;
check('Modell: state != 1 heisst offline', UniDaten::modell($g1, $sta)['geraete'][1]['online'], false);
$sta2 = $sta;
$sta2[] = ['mac' => '02:00:00:00:ff:ff', 'is_wired' => true, 'sw_mac' => '02:00:00:00:77:77', 'sw_port' => 3];
$m2 = UniDaten::modell($devs, $sta2);
check('Modell: Client an unbekanntem Switch zaehlt mit, haengt aber an keinem Port', [$m2['clients']['kabel'], array_sum(array_map(fn($p) => count($p['clients']), $m2['geraete'][1]['ports']))], [12, 11]);
check('Modell: leere Eingabe', UniDaten::modell([], []), ['geraete' => [], 'clients' => ['gesamt' => 0, 'kabel' => 0, 'wlan' => 0], 'poe_gesamt_w' => 0.0, 'client_liste' => []]);
check('Zahl: ohne unnoetige Nullen', [UniDaten::zahl(31.07), UniDaten::zahl(220.0), UniDaten::zahl(116.69, 1), UniDaten::zahl(0), UniDaten::zahl(6.5)], ['31.07', '220', '116.7', '0', '6.5']);
$w = UniDaten::werte($sw, null, false);
check('Werte Switch: online, Typ, Modell, Clients, PoE-Summe und Budget', [$w['online'], $w['typ'], $w['modell'], $w['clients'], $w['poe_gesamt_w'], $w['poe_max_w']], ['1', 'switch', 'US24P250', '11', '31.07', '220']);
check('Werte Switch: Port 4 (PoE, AP) und Port 18 (3 Clients)', [$w['port4/up'], $w['port4/speed_mbit'], $w['port4/poe_an'], $w['port4/poe_w'], $w['port4/poe_ma'], $w['port18/clients'], $w['port18/speed_mbit']], ['1', '1000', '1', '6.19', '116.7', '3', '1000']);
check('Werte Switch: Port ohne Link und ohne Last', [$w['port5/up'], $w['port5/speed_mbit'], $w['port5/poe_an'], $w['port5/poe_w']], ['0', '0', '0', '0']);
check('Werte Switch: SFP-Ports ohne PoE-Topics, ohne Datenraten', [isset($w['port25/up']), isset($w['port25/poe_w']), isset($w['port18/rx_bps'])], [true, false, false]);
check('Werte Switch: 26 Ports mal 4 (up, aktiv, speed, clients) plus 24 PoE-Ports mal 3 plus 6 Geraetewerte', count($w), 26 * 4 + 24 * 3 + 6);
$w2 = UniDaten::werte($sw, null, true);
check('Werte Switch: Datenraten in bit/s (Bytes je Sekunde mal 8)', [$w2['port18/rx_bps'], $w2['port18/tx_bps']], ['4000000', '1000000']);
$w3 = UniDaten::werte($sw, [1, 2, 18], false);
check('Werte Switch: Portauswahl', [isset($w3['port1/up']), isset($w3['port3/up']), isset($w3['port18/clients']), count($w3)], [true, false, true, 3 * 4 + 3 * 3 + 6]);
$wa = UniDaten::werte($ap1, null, false);
check('Werte Access Point: Clients, Gaeste, Baender, keine Port- und PoE-Werte', [$wa['clients'], $wa['clients_gast'], $wa['clients_2g'], $wa['clients_5g'], isset($wa['clients_6g']), isset($wa['poe_gesamt_w']), count(array_filter(array_keys($wa), fn($k) => str_contains($k, 'port')))],
    ['6', '1', '4', '2', false, false, 0]);
$wg = UniDaten::werte($byName['Gateway'], null, false);
check('Werte Gateway: Port 4 mit PoE ohne Last = 0, Budget 30 W', [$wg['port4/poe_an'], $wg['port4/poe_w'], $wg['poe_max_w'], $wg['clients']], ['0', '0', '30', '19']);
check('Werte: Port-Zustand aktiv (1) und abgeschalteter Port (0)', [$w['port4/aktiv'], UniDaten::werte(['ports' => [7 => ['up' => false, 'aktiv' => false, 'speed' => 0, 'clients' => [], 'poe' => false]]] + $sw, null, false)['port7/aktiv']],
    ['1', '0']);
$mDis = UniDaten::modell([['mac' => '02:00:00:00:00:09', 'type' => 'usw', 'state' => 1, 'port_table' => [['port_idx' => 1, 'forward' => 'disabled', 'up' => false], ['port_idx' => 2, 'enable' => false, 'forward' => 'all'], ['port_idx' => 3, 'forward' => 'all', 'enable' => true], ['port_idx' => 4]]]], []);
check('Modell: aktiv = nicht "disabled" und nicht enable=false', array_map(fn($p) => $p['aktiv'], $mDis['geraete'][0]['ports']), [1 => false, 2 => false, 3 => true, 4 => true]);
check('Netzwerte', UniDaten::netzWerte($m), ['clients' => '19', 'clients_kabel' => '11', 'clients_wlan' => '8', 'poe_gesamt_w' => '31.07']);
// ---------------------------------------------------------------- Unit: Clients (Liste, Online-Status per MQTT)
$cliste = $m['client_liste'];
$cMac = [];
foreach ($cliste as $c) {
    $cMac[$c['mac']] = $c;
}
check('Clients: Liste aller verbundenen (19) mit Ort bei Kabel und WLAN', [count($cliste), $cMac['020000001001']['ort'], $cMac['020000001001']['kabel'], $cMac['020000002001']['ort'], $cMac['020000002001']['kabel'], $cMac['020000001003']['ip']],
    [19, 'Switch Keller · Port 1', true, 'AP Wohnzimmer · 2,4 GHz · Heim', false, '192.0.2.13']);
$bk = UniDaten::bekannte(fx_bekannte());
$bkMac = [];
foreach ($bk as $b) {
    $bkMac[$b['mac']] = $b;
}
check('Clients: bekannte (22) mit IP, auch fester IP, und zuletzt gesehen', [count($bk), $bkMac['020000003001']['ip'], $bkMac['020000003001']['zuletzt'], $bkMac['020000003002']['ip'], $bkMac['020000003002']['name'], $bkMac['020000003003']['ip'], $bkMac['020000003001']['kabel']],
    [22, '192.0.2.201', 1699900000, '192.0.2.202', 'handy-gast', '', true]);
$an = UniDaten::clientAnzeige($m, $bk);
check('Clients: zusammengefuehrt (22), 19 online und 3 offline, Namen (Name, Hostname, sonst leer)', [count($an), count(array_filter($an, fn($c) => $c['online'])), $an['020000003001']['online'], $an['020000003001']['name'], $an['020000003001']['zuletzt'],
    $an['020000001003']['name'], $an['020000001001']['name'], $an['020000001002']['name'], $an['020000001002']['ip'], $an['020000003003']['name'], $an['020000001001']['ort']],
    [22, 19, false, 'Alter Drucker', 1699900000, 'Kabel-Client 3', 'host1', '', '192.0.2.12', '', 'Switch Keller · Port 1']);
check('Clients: ohne Liste der bekannten nur die verbundenen', count(UniDaten::clientAnzeige($m, [])), 19);
$pc = UniConfig::fromPost(UniConfig::defaults(), ['clients' => ['02:00:00:00:10:03' => ['da' => '1', 'aktiv' => '1', 'name' => 'Küche Licht'], '020000001002' => ['da' => '1', 'aktiv' => '1', 'ip' => '1', 'verbindung' => '1', 'name' => ''],
    'zz' => ['aktiv' => '1'], '02:00:00:00:30:01' => ['da' => '1', 'ip' => '1', 'name' => 'Küche Licht'], '020000001001' => ['da' => '1']]]);
check('Konfiguration Clients: Auswahl mit den drei Schaltern gespeichert (auch nur IP), Namen ASCII und eindeutig, ungueltige MAC fehlt, Zeile ohne Schalter fehlt',
    $pc['clients'], ['020000001003' => ['name' => 'kueche_licht', 'online' => true, 'ip' => false, 'verbindung' => false], '020000001002' => ['name' => '', 'online' => true, 'ip' => true, 'verbindung' => true],
        '020000003001' => ['name' => 'kueche_licht_2', 'online' => false, 'ip' => true, 'verbindung' => false]]);
$pc2 = UniConfig::fromPost($pc, ['clients' => ['020000001002' => ['da' => '1']], 'verkehr_gesendet' => '1']);
check('Konfiguration Clients: abgewaehlte Zeile wird entfernt, nicht gesendete bleiben', array_keys($pc2['clients']), ['020000001003', '020000003001']);
check('Konfiguration Clients: Beitrag ohne Clients aendert nichts, Standard leer', [UniConfig::fromPost($pc, ['verkehr_gesendet' => '1'])['clients'] === $pc['clients'], UniConfig::defaults()['clients']], [true, []]);
file_put_contents("$dir/c2.json", json_encode(['clients' => ['02:00:00:00:10:03' => ['name' => 'ä ö'], 'zz' => ['name' => 'x'], '02:00:00:00:10:04' => 'kaputt', '020000001005' => [], '020000001006' => ['name' => 'x', 'online' => false, 'ip' => true],
    '020000001007' => ['name' => 'y', 'online' => false]]]));
check('Konfiguration laden: Clients bereinigt; ohne Angabe online = an (Konfiguration bis 0.3.0), Eintrag ohne jeden Schalter faellt weg', UniConfig::load("$dir/c2.json")['clients'],
    ['020000001003' => ['name' => 'ae_oe', 'online' => true, 'ip' => false, 'verbindung' => false], '020000001005' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => false],
        '020000001006' => ['name' => 'x', 'online' => false, 'ip' => true, 'verbindung' => false]]);
$cfgCl = ['clients' => ['020000001003' => ['name' => '', 'online' => true, 'ip' => true, 'verbindung' => true], '020000001002' => ['name' => '', 'online' => true, 'ip' => true, 'verbindung' => false],
    '020000003001' => ['name' => 'Drucker', 'online' => true, 'ip' => true, 'verbindung' => false], '020000003003' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => false],
    '020000009999' => ['name' => '', 'online' => false, 'ip' => false, 'verbindung' => true], '020000001001' => ['name' => 'drucker', 'online' => true, 'ip' => false, 'verbindung' => true]]];
$cn = UniConfig::clientNamen($cfgCl, $an);
check('Clients: Name im Topic (gewaehlt, sonst Gateway-Name, sonst client_<MAC-Ende>), doppelte nummeriert', array_values($cn),
    ['kabel-client_3', 'client_001002', 'drucker', 'client_003003', 'client_009999', 'drucker_2']);
check('Clients: MQTT-Werte je nach Schalter: Online-Status, IP (aktuelle, offline die zuletzt bekannte, ohne IP fehlt das Topic) und aktive Verbindung (nicht verbunden: Text)', UniDaten::clientWerte($an, $cn, $cfgCl['clients']),
    ['kabel-client_3/online' => '1', 'kabel-client_3/ip' => '192.0.2.13', 'kabel-client_3/verbindung' => 'Switch Keller · Port 3', 'client_001002/online' => '1', 'client_001002/ip' => '192.0.2.12',
        'drucker/online' => '0', 'drucker/ip' => '192.0.2.201', 'client_003003/online' => '0', 'client_009999/verbindung' => 'nicht verbunden', 'drucker_2/online' => '1', 'drucker_2/verbindung' => 'Switch Keller · Port 1']);
check('Clients: Verbindung ohne bekannten Ort = Kabel oder WLAN; IP leer = kein Topic; Eintrag ohne Schalter-Angaben = nur Online-Status',
    [UniDaten::clientWerte(['020000000001' => ['online' => true, 'ip' => '', 'ort' => '', 'kabel' => false]], ['020000000001' => 'x'], ['020000000001' => ['online' => true, 'ip' => true, 'verbindung' => true]]),
        UniDaten::clientWerte(['020000000001' => ['online' => true, 'ip' => '', 'ort' => '', 'kabel' => true]], ['020000000001' => 'x'], ['020000000001' => ['verbindung' => true, 'online' => false]]),
        UniDaten::clientWerte(['020000000001' => ['online' => true, 'ip' => '192.0.2.5', 'ort' => 'a', 'kabel' => true]], ['020000000001' => 'x'], ['020000000001' => ['name' => '']])],
    [['x/online' => '1', 'x/verbindung' => 'WLAN'], ['x/verbindung' => 'Kabel'], ['x/online' => '1']]);
// ---------------------------------------------------------------- Unit: Internet (WAN)
$inet = UniDaten::internet(fx_geraete(), fx_health());
check('Internet: Anbieter, ASN, Status, Dauer der Sitzung, IP, Latenz, Verfuegbarkeit, Anschluesse, Link, Datenrate; Speedtest ohne gueltige Werte fehlt',
    [$inet['anbieter'], $inet['asn'], $inet['online'], $inet['dauer'], $inet['ip'], $inet['latenz'], $inet['verfuegbarkeit'], $inet['wan1_up'], $inet['wan2_up'], $inet['link_mbit'], $inet['rx_bps'], $inet['tx_bps'], $inet['speedtest']],
    ['Beispiel Telekom AG', 64496, true, 126613, '203.0.113.7', 6, 99.9, true, false, 1000, 10000000, 500000, null]);
check('Internet: MQTT-Werte ohne Datenraten', UniDaten::internetWerte($inet, false), ['online' => '1', 'verbindungsdauer_s' => '126613', 'anbieter' => 'Beispiel Telekom AG', 'asn' => '64496', 'ip' => '203.0.113.7', 'latenz_ms' => '6',
    'verfuegbarkeit_prozent' => '99.9', 'wan1_up' => '1', 'wan2_up' => '0', 'link_mbit' => '1000']);
$inet2 = UniDaten::internet(fx_geraete(), fx_health(940));
check('Internet: Speedtest mit gueltigen Werten, Datenraten nur mit Option', [$inet2['speedtest'], array_intersect_key(UniDaten::internetWerte($inet2, true), array_flip(['speedtest_down_mbit', 'speedtest_up_mbit', 'speedtest_ping_ms', 'speedtest_ts', 'down_bps', 'up_bps'])),
    isset(UniDaten::internetWerte($inet2, false)['down_bps'])],
    [['down' => 940.0, 'up' => 45.5, 'ping' => 9, 'ts' => 1700000000], ['speedtest_down_mbit' => '940', 'speedtest_up_mbit' => '45.5', 'speedtest_ping_ms' => '9', 'speedtest_ts' => '1700000000', 'down_bps' => '10000000', 'up_bps' => '500000'], false]);
$gwOff = fx_geraete();
$gwOff[0]['uplink']['up'] = false;
$gwOff[0]['wan1']['up'] = false;
$inet3 = UniDaten::internet($gwOff, []);
check('Internet: Verbindung getrennt = online 0 und Dauer 0; ohne stat/health fehlt der Anbieter, der Rest bleibt (IP aus dem Gateway-Eintrag)', [$inet3['online'], $inet3['dauer'], $inet3['anbieter'], $inet3['ip'], isset(UniDaten::internetWerte($inet3, false)['anbieter']), UniDaten::internetWerte($inet3, false)['online']],
    [false, 0, '', '203.0.113.7', false, '0']);
check('Internet: ohne Gateway oder ohne WAN-Daten null', [UniDaten::internet([fx_geraete()[1], fx_geraete()[2]], fx_health()), UniDaten::internet([['type' => 'udm', 'mac' => '02:00:00:00:00:01']], [])], [null, null]);
check('Dauer als Text', [UniDaten::dauerText(126613), UniDaten::dauerText(30), UniDaten::dauerText(3600), UniDaten::dauerText(2 * 86400 + 300), UniDaten::dauerText(86400 + 61)], ['1 Tag 11 Std 10 Min', 'weniger als 1 Min', '1 Std 0 Min', '2 Tage 0 Std 5 Min', '1 Tag 0 Std 1 Min']);
check('Namen: eigene Topic-Zweige (netz, internet, client, status, befehl) werden nicht als Geraetename verwendet', array_values(UniConfig::namen(UniConfig::defaults(), [['mac' => '020000000001', 'name' => 'Internet', 'typ' => 'switch'],
    ['mac' => '020000000002', 'name' => 'Netz', 'typ' => 'switch'], ['mac' => '020000000003', 'name' => 'Client', 'typ' => 'ap']])), ['internet_geraet', 'netz_geraet', 'client_geraet']);
check('Klarname zum Modellcode (unbekannte Codes unveraendert, MQTT behaelt den Code)', [UniDaten::klarname('U7PG2'), UniDaten::klarname('UAP6MP'), UniDaten::klarname('us24p250'), UniDaten::klarname('UDMA6A8'), UniDaten::klarname('XYZ-1'), UniDaten::klarname(''),
    $byName['AP Wohnzimmer']['modell_name'], $byName['AP Wohnzimmer']['modell'], UniDaten::werte($byName['AP Wohnzimmer'], null, false)['modell']], ['UAP-AC-Pro', 'U6-Pro', 'US-24-250W', 'UCG-Fiber', 'XYZ-1', '', 'U6-Pro', 'UAP6MP', 'UAP6MP']);
check('Geraeteart aus dem Typ', [UniDaten::typ('udm'), UniDaten::typ('UGW'), UniDaten::typ('usw'), UniDaten::typ('uap'), UniDaten::typ('ubb'), UniDaten::typ('xyz')], ['gateway', 'gateway', 'switch', 'ap', 'ap', 'andere']);

// ---------------------------------------------------------------- Unit: Befehle
check('Befehlstopic', [UniBefehle::parseTopic('unifi/befehl/switch_keller/port11/poe'), UniBefehle::parseTopic('unifi/befehl/switch_keller/port11/poe/quittung'), UniBefehle::parseTopic('unifi/befehl/x/port/poe'),
    UniBefehle::parseTopic('unifi/befehl/x/port1234/poe'), UniBefehle::parseTopic('fremd/befehl/x/port1/poe')], [['switch_keller', 11, 'poe'], null, null, null, null]);
$cfgSw = ['befehle_ports' => '11-13, 22, 25'];
$pr = fn(array $cfg, ?array $g, int $port, string $b, string $v) => UniBefehle::pruefen($cfg, $g, $port, $b, $v);
check('Befehle: ab Werk gesperrt', $pr(['befehle_ports' => ''], $sw, 11, 'poe', '0'), 'Befehle für Port 11 sind gesperrt (im Reiter „Geräte“ freigeben)');
check('Befehle: nicht freigegebener Port gesperrt, freigegebener erlaubt', [$pr($cfgSw, $sw, 6, 'poe', '0'), $pr($cfgSw, $sw, 11, 'poe', '0'), $pr($cfgSw, $sw, 12, 'poe', '1')], ['Befehle für Port 6 sind gesperrt (im Reiter „Geräte“ freigeben)', null, null]);
check('Befehle: unbekannter Befehl und ungueltige Werte', [$pr($cfgSw, $sw, 11, 'reboot', '1'), $pr($cfgSw, $sw, 11, 'poe', '2'), $pr($cfgSw, $sw, 11, 'poe', ''), $pr($cfgSw, $sw, 11, 'neustart', '0')], ['Befehl nicht erlaubt', 'ungültiger Wert', 'ungültiger Wert', 'ungültiger Wert']);
check('Befehle: Uplink und Ports ohne PoE werden nie geschaltet', [$pr($cfgSw, $sw, 22, 'poe', '0'), $pr($cfgSw, $sw, 25, 'poe', '0')], ['Port 22 ist ein Uplink und wird nie geschaltet', 'Port 25 hat kein PoE']);
check('Befehle: Neustart nur mit PoE-Last, Ein/Aus auch ohne', [$pr($cfgSw, $sw, 12, 'neustart', '1'), $pr($cfgSw, $sw, 11, 'neustart', '1'), $pr($cfgSw, $sw, 12, 'poe', '0')], ['am Port 12 liegt kein PoE an', null, null]);
check('Befehle: Geraet unbekannt, offline, Port fehlt', [$pr($cfgSw, null, 11, 'poe', '0'), $pr($cfgSw, array_replace($sw, ['online' => false]), 11, 'poe', '0'), $pr(['befehle_ports' => '99'], $sw, 99, 'poe', '0')],
    ['keine Daten vom Gateway (Gerät unbekannt)', 'das Gerät ist nicht erreichbar', 'das Gerät hat keinen Port 99']);

check('Befehle: aktiv hat eine eigene Freigabeliste (schalt_ports), PoE-Freigabe gilt nicht fuer aktiv', [UniBefehle::freigabeFeld('aktiv'), UniBefehle::freigabeFeld('poe'), $pr($cfgSw, $sw, 11, 'aktiv', '0'),
    $pr(['schalt_ports' => '11, 25'], $sw, 11, 'poe', '0'), $pr(['schalt_ports' => '11, 25'], $sw, 11, 'aktiv', '0')],
    ['schalt_ports', 'befehle_ports', 'Ein- und Ausschalten für Port 11 ist gesperrt (im Reiter „Geräte“ freigeben)', 'Befehle für Port 11 sind gesperrt (im Reiter „Geräte“ freigeben)', null]);
$cfgAk = ['schalt_ports' => '11, 22, 25, 99'];
check('Befehle aktiv: auch Ports ohne PoE, Uplink nie, Wert nur 0/1', [$pr($cfgAk, $sw, 25, 'aktiv', '0'), $pr($cfgAk, $sw, 22, 'aktiv', '0'), $pr($cfgAk, $sw, 11, 'aktiv', '2'), $pr($cfgAk, $sw, 99, 'aktiv', '1'),
    $pr($cfgAk, array_replace($sw, ['online' => false]), 11, 'aktiv', '0')], [null, 'Port 22 ist ein Uplink und wird nie geschaltet', 'ungültiger Wert', 'das Gerät hat keinen Port 99', 'das Gerät ist nicht erreichbar']);
$roh = fx_geraete()[1];
$ovAn = fn(array $r) => array_values(array_filter($r['overrides'], fn($o) => $o['port_idx'] === 15 || $o['port_idx'] === 16 || $o['port_idx'] === 26));
$r15 = UniBefehle::overridesAktiv($roh, 15, false, []);
$v15 = ['forward' => 'all', 'native_networkconf_id' => 'net-1', 'tagged_vlan_mgmt' => 'auto', 'port_security_enabled' => false];
check('overridesAktiv: Port 15 aus = forward disabled, kein Netz, block_all, Port-Sicherheit; Rest des Eintrags unveraendert, Liste gleich lang, Ausgangswerte gemerkt',
    [$r15['ok'], $r15['aenderung'], $r15['vorher'], count($r15['overrides']), $ovAn($r15)[0]['forward'], $ovAn($r15)[0]['native_networkconf_id'], $ovAn($r15)[0]['tagged_vlan_mgmt'], $ovAn($r15)[0]['port_security_enabled'],
        $ovAn($r15)[0]['setting_preference'], $ovAn($r15)[0]['poe_mode']],
    [true, true, $v15, 24, 'disabled', '', 'block_all', true, 'auto', 'auto']);
$dis15 = ['port_overrides' => $r15['overrides'], 'port_table' => $roh['port_table']];
check('overridesAktiv: eine zweite Abschaltung aendert nichts', UniBefehle::overridesAktiv($dis15, 15, false, $v15)['aenderung'], false);
$ein15 = UniBefehle::overridesAktiv($dis15, 15, true, []);
check('overridesAktiv: Einschalten ohne Merker: forward all, tagged_vlan_mgmt auto, Port-Sicherheit aus, Netz = haeufigstes der uebrigen Ports', [$ein15['aenderung'], $ovAn($ein15)[0]['forward'], $ovAn($ein15)[0]['tagged_vlan_mgmt'], $ovAn($ein15)[0]['port_security_enabled'], $ovAn($ein15)[0]['native_networkconf_id']],
    [true, 'all', 'auto', false, 'net-1']);
$r16 = UniBefehle::overridesAktiv($roh, 16, false, []);
$dis16 = ['port_overrides' => $r16['overrides'], 'port_table' => $roh['port_table']];
check('overridesAktiv: Abschalten und Einschalten mit Merker ergibt wieder exakt die urspruengliche Liste (Port 15 und 16), ausgeschlossene Netze bleiben', [UniBefehle::overridesAktiv($dis15, 15, true, $r15['vorher'])['overrides'] === $roh['port_overrides'],
    UniBefehle::overridesAktiv($dis16, 16, true, $r16['vorher'])['overrides'] === $roh['port_overrides'], $r16['vorher']['forward'], $ovAn($r16)[1]['excluded_networkconf_ids']], [true, true, 'customize', ['net-2']]);
check('overridesAktiv: Merker mit Feld null entfernt das Feld wieder; ungueltiger Merker faellt auf den Standard zurueck', [array_key_exists('port_security_enabled', UniBefehle::overridesAktiv(['port_overrides' => [['port_idx' => 5, 'forward' => 'disabled']]], 5, true,
    ['forward' => 'all', 'native_networkconf_id' => 'n', 'tagged_vlan_mgmt' => 'auto', 'port_security_enabled' => null])['overrides'][0]),
    UniBefehle::overridesAktiv($dis15, 15, true, ['forward' => 'unsinn', 'native_networkconf_id' => 'x', 'tagged_vlan_mgmt' => 'auto', 'port_security_enabled' => false])['overrides'][14]['native_networkconf_id']], [false, 'net-1']);
$r26 = UniBefehle::overridesAktiv($roh, 26, false, []);
check('overridesAktiv: Port ohne Eintrag bekommt einen neuen (wie die Oberflaeche), Liste waechst um 1, Ausgangswerte aus der port_table', [count($r26['overrides']), $r26['overrides'][24], $r26['aenderung'], $r26['vorher']],
    [25, ['port_idx' => 26, 'setting_preference' => 'auto', 'forward' => 'disabled', 'native_networkconf_id' => '', 'tagged_vlan_mgmt' => 'block_all', 'port_security_enabled' => true], true,
        ['forward' => 'all', 'native_networkconf_id' => 'net-1', 'tagged_vlan_mgmt' => 'auto', 'port_security_enabled' => false]]);
check('overridesAktiv: schon eingeschalteter Port wird nicht angefasst', [UniBefehle::overridesAktiv($roh, 15, true, [])['aenderung'], UniBefehle::overridesAktiv($roh, 15, true, [])['overrides'] === $roh['port_overrides']], [false, true]);
check('overridesAktiv: Port mit Port-Profil (portconf_id) wird abgelehnt', [UniBefehle::overridesAktiv($roh, 11, false, [])['ok'], UniBefehle::overridesAktiv($roh, 11, false, [])['text']],
    [false, 'Der Port nutzt ein Port-Profil: Ein- und Ausschalten wird dafür nicht unterstützt']);
check('portForward: aus Eintrag, sonst aus port_table, sonst leer', [UniBefehle::portForward($roh, 16), UniBefehle::portForward($roh, 26), UniBefehle::portForward($roh, 3), UniBefehle::portForward($roh, 99), UniBefehle::portForward($dis15, 15)], ['customize', 'all', '', '', 'disabled']);

// ---------------------------------------------------------------- Prozesse (Fake-Gateway, Fake-Broker)
$php = PHP_BINARY;
$phpOpt = [];
if (php_ini_loaded_file()) {
    $phpOpt = ['-c', php_ini_loaded_file()];
} elseif (PHP_OS_FAMILY === 'Windows') {
    $phpOpt = ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=openssl', '-d', 'extension=curl'];
}
$ext = extension_loaded('openssl') && extension_loaded('curl');
if (!$ext) {
    $fails++;
    $count++;
    echo "FEHLT: PHP-Erweiterungen openssl und curl; Ende-zu-Ende-Tests uebersprungen\n";
}
$mqPort = 18871;       // Broker des LoxBerry
$gwPort = 18872;       // Fake-Gateway
$leerPort = 18873;     // nichts hoert zu (Gateway nicht erreichbar)
$mdir = "$dir/mq";
$gdir = "$dir/gw";
foreach ([$mdir, $gdir] as $d) {
    mkdir($d, 0777, true);
}
$mlog = fn() => (string)@file_get_contents($mdir . '/mqtt.log');
$glog = fn() => (string)@file_get_contents($gdir . '/gw.log');
$has = fn(string $topic, string $val) => (bool)preg_match('/' . preg_quote($topic, '/') . "\t" . preg_quote($val, '/') . '(\r?\n|$)/', $mlog());
$einspeisen = function (string $d, string $zeilen): void {
    waitFor(fn() => !is_file($d . '/inject.txt'), 3);
    file_put_contents($d . '/inject.txt', $zeilen);
    waitFor(fn() => !is_file($d . '/inject.txt'), 3);
};
const GWUSER = 'lesen';
const GWPW = 'geheimpw';
$gwCtl = fn(array $c) => file_put_contents($gdir . '/ctl.json', json_encode($c));
$resetGw = function (array $ctl = []) use ($gdir, $gwCtl) {
    foreach (['gw.log', 'sessions.json'] as $f) {
        @unlink("$gdir/$f");
    }
    file_put_contents("$gdir/state.json", json_encode(['geraete' => fx_geraete(), 'clients' => fx_clients(), 'bekannt' => fx_bekannte(), 'health' => fx_health()]));
    $gwCtl($ctl + ['user' => GWUSER, 'pw' => GWPW, 'csrf' => 'header']);
};

if ($ext) {
    $mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $mdir]);
    $gwProc = startProc(array_merge([$php], $phpOpt, ['-S', "127.0.0.1:$gwPort", __DIR__ . '/fake_gateway.php']), ['UNI_FAKE_DIR' => $gdir]);
    foreach ([$mqPort, $gwPort] as $pt) {
        waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pt", $e, $es, 1) !== false, 8);
    }
    $baseEnv = fn(string $d) => ['UNI_DEV_DIR' => $d, 'UNI_DEV_MQTT' => "127.0.0.1:$mqPort", 'UNI_DEV_LOGLEVEL' => 'debug'];
    $starte = fn(string $d, array $env = []) => startProc(array_merge([$php], $phpOpt, [$root . '/unifi2lox/bin/unifid.php']), $env + $baseEnv($d));
    /** Neues Dienst-Verzeichnis mit Konfiguration. */
    $neuDir = function (string $name, array $konfig = []) use ($dir, $gwPort): string {
        $d = "$dir/$name";
        rrmdir($d);
        mkdir($d, 0777, true);
        $c = UniConfig::defaults();
        $c['controller'] = ['adresse' => 'http://127.0.0.1:' . $gwPort, 'benutzer' => GWUSER, 'passwort' => GWPW, 'seite' => 'default', 'tls_pruefen' => false];
        $c['abfrage_abstand'] = 1;
        Util::jsonSave("$d/unifi.json", array_replace_recursive($c, $konfig));
        return $d;
    };
    $reset = function () use ($mdir) {
        @unlink("$mdir/mqtt.log");
        @unlink("$mdir/inject.txt");
    };
    $statusVon = fn(string $d) => Util::jsonLoad("$d/run/status.json");
    $stopWait = function (array $svc) {
        stopProc($svc);
        usleep(600000);
    };
    /** Befehl aus Loxone einspeisen und die Quittungen danach einsammeln (bis "ausgefuehrt" oder "abgelehnt"). Danach 1,1 s warten (ein Befehl je Sekunde). */
    $befehl = function (string $topic, string $wert) use ($mlog, $mdir, $einspeisen): array {
        $off = strlen($mlog());
        $einspeisen($mdir, "$topic\t$wert\n");
        $q = preg_quote($topic . '/quittung', '/');
        waitFor(fn() => (bool)preg_match('/' . $q . "\t(ausgefuehrt|abgelehnt[^\r\n]*)/", substr($mlog(), $off)), 8);
        preg_match_all('/' . $q . "\t([^\r\n]*)/", substr($mlog(), $off), $mm);
        usleep(1100000);
        return $mm[1];
    };
    $sw = 'switch_keller';
    $macSw = '020000000002';
    $befehleCfg = ['geraete' => [$macSw => ['aktiv' => true, 'name' => '', 'ports' => '', 'befehle_ports' => '11-13, 22, 25', 'schalt_ports' => '11, 15, 16, 22, 26']]];

    // ---------------------------------------------------------------- A: Anmeldung, Werte, Befehle (CSRF im Antwortkopf)
    $resetGw(['csrf' => 'header']);
    $reset();
    $dA = $neuDir('A', $befehleCfg);
    $svc = $starte($dA);
    $logA = fn() => (string)@file_get_contents("$dA/unifi.log");
    check('A: Letzter Wille fuer status/ok beim Broker angemeldet', waitFor(fn() => str_contains($mlog(), "CONNECT\tunifi_unifi2lox\tunifi/status/ok"), 12), true);
    check('A: angemeldet und abgefragt, status/ok 1', waitFor(fn() => $has('unifi/status/ok', '1') && ($statusVon($dA)['anmeldung']['status'] ?? '') === 'ok', 15), true);
    check('A: Anmeldung genau einmal, Zugangsdaten stehen nicht im Protokoll des Gateways', [substr_count($glog(), 'LOGIN'), str_contains($glog(), GWPW)], [1, false]);
    check('A: Werte Switch (Port 4: PoE, Link; Port 18: 3 Clients)', waitFor(fn() => $has("unifi/$sw/port4/poe_w", '6.19') && $has("unifi/$sw/port18/clients", '3') && $has("unifi/$sw/port4/up", '1'), 10), true);
    check('A: Werte Switch (PoE-Summe, Budget, Clients, Typ)', [$has("unifi/$sw/poe_gesamt_w", '31.07'), $has("unifi/$sw/poe_max_w", '220'), $has("unifi/$sw/clients", '11'), $has("unifi/$sw/typ", 'switch'), $has("unifi/$sw/online", '1')], [true, true, true, true, true]);
    check('A: Werte Access Points und Netz', [$has('unifi/ap_wohnzimmer/clients', '6'), $has('unifi/ap_wohnzimmer/clients_2g', '4'), $has('unifi/ap_wohnzimmer/clients_5g', '2'), $has('unifi/ap_wohnzimmer/clients_gast', '1'),
        $has('unifi/ap_dachboden/clients', '2'), $has('unifi/netz/clients', '19'), $has('unifi/netz/clients_kabel', '11'), $has('unifi/netz/clients_wlan', '8'), $has('unifi/netz/poe_gesamt_w', '31.07')], [true, true, true, true, true, true, true, true, true]);
    check('A: Gateway-Port ohne Last, keine Datenraten ohne Option', [$has('unifi/gateway/port4/poe_an', '0'), str_contains($mlog(), 'rx_bps')], [true, false]);
    check('A: Internet (Anbieter, Status, Dauer, IP, Latenz, Verfuegbarkeit, Anschluesse, Link); kein Speedtest ohne gueltige Werte, keine Datenraten ohne Option',
        [$has('unifi/internet/anbieter', 'Beispiel Telekom AG'), $has('unifi/internet/asn', '64496'), $has('unifi/internet/online', '1'), $has('unifi/internet/verbindungsdauer_s', '126613'), $has('unifi/internet/ip', '203.0.113.7'),
            $has('unifi/internet/latenz_ms', '6'), $has('unifi/internet/verfuegbarkeit_prozent', '99.9'), $has('unifi/internet/wan1_up', '1'), $has('unifi/internet/wan2_up', '0'), $has('unifi/internet/link_mbit', '1000'),
            str_contains($mlog(), 'unifi/internet/speedtest'), str_contains($mlog(), 'unifi/internet/down_bps')], [true, true, true, true, true, true, true, true, true, true, false, false]);
    check('A: WAN-IP steht nicht im Log des Dienstes', str_contains($logA(), '203.0.113.7'), false);
    check('A: Lebenszeichen zaehler und ts', [str_contains($mlog(), "unifi/status/zaehler\t"), str_contains($mlog(), "unifi/status/ts\t")], [true, true]);
    check('A: nichts mit Leerzeichen im Topic, nichts retained gesendet', [(bool)preg_match('/^unifi\/[^\t]* [^\t]*\t/m', $mlog()), str_contains($mlog(), "\tR\n")], [false, false]);
    $netz = Util::jsonLoad("$dA/run/netz.json");
    check('A: Netzuebersicht fuer die Weboberflaeche liegt im RAM-Ordner', [count($netz['modell']['geraete'] ?? []), $netz['modell']['clients']['gesamt'] ?? null], [4, 19]);
    check('A: Status.json ohne Passwort, mit Geraeten', [str_contains((string)file_get_contents("$dA/run/status.json"), GWPW), array_keys($statusVon($dA)['geraete'] ?? [])], [false, ['gateway', 'switch_keller', 'ap_wohnzimmer', 'ap_dachboden']]);
    // Befehle
    $q = $befehl("unifi/befehl/$sw/port6/poe", '0');
    check('A: Befehl fuer nicht freigegebenen Port wird abgelehnt', $q, ['abgelehnt: Befehle für Port 6 sind gesperrt (im Reiter „Geräte“ freigeben)']);
    check('A: abgelehnter Befehl erreicht das Gateway nicht', [str_contains($glog(), 'PUT '), str_contains($glog(), 'CMD ')], [false, false]);
    $q = $befehl("unifi/befehl/gateway/port4/poe", '0');
    check('A: Befehl am Gateway ohne Freigabe abgelehnt', str_starts_with($q[0] ?? '', 'abgelehnt: Befehle für Port 4 sind gesperrt'), true);
    $q = $befehl("unifi/befehl/unbekannt/port4/poe", '0');
    check('A: unbekanntes Geraet', $q, ['abgelehnt: unbekanntes Geraet']);
    $q = $befehl("unifi/befehl/$sw/port11/poe", '2');
    check('A: ungueltiger Wert', $q, ['abgelehnt: ungültiger Wert']);
    $q = $befehl("unifi/befehl/$sw/port22/poe", '0');
    check('A: Uplink wird nie geschaltet', $q, ['abgelehnt: Port 22 ist ein Uplink und wird nie geschaltet']);
    $q = $befehl("unifi/befehl/$sw/port25/poe", '0');
    check('A: Port ohne PoE', $q, ['abgelehnt: Port 25 hat kein PoE']);
    $q = $befehl("unifi/befehl/$sw/port12/neustart", '1');
    check('A: Neustart ohne PoE-Last abgelehnt', $q, ['abgelehnt: am Port 12 liegt kein PoE an']);
    check('A: bisher keine Aenderung am Gateway', [str_contains($glog(), 'PUT '), str_contains($glog(), 'CMD ')], [false, false]);
    $q = $befehl("unifi/befehl/$sw/port11/poe", '0');
    check('A: PoE aus an Port 11: angenommen, dann ausgefuehrt', $q, ['angenommen', 'ausgefuehrt']);
    preg_match('/PUT id-sw (.*)/', $glog(), $pm);
    $putBody = json_decode($pm[1] ?? '[]', true);
    $ov = $putBody['port_overrides'] ?? [];
    $o11 = array_values(array_filter($ov, fn($o) => $o['port_idx'] === 11))[0] ?? [];
    check('A: PUT mit der kompletten Liste port_overrides (24 Eintraege, nur Port 11 geaendert, uebrige Felder erhalten)', [count($ov), $o11['poe_mode'] ?? null, $o11['op_mode'] ?? null, $o11['portconf_id'] ?? null,
        count(array_filter($ov, fn($o) => $o['poe_mode'] === 'off'))], [24, 'off', 'switch', 'pc-all', 1]);
    check('A: Aenderung mit gueltigem CSRF-Token (kein 403)', str_contains($glog(), '403'), false);
    check('A: danach liefert das Gateway an Port 11 keinen Strom mehr (naechste Abfrage)', waitFor(fn() => $has("unifi/$sw/port11/poe_an", '0') && $has("unifi/$sw/port11/poe_w", '0') && $has("unifi/$sw/poe_gesamt_w", '31.07'), 10), true);
    $q = $befehl("unifi/befehl/$sw/port13/neustart", '1');
    check('A: Neustart an Port 13: angenommen, dann ausgefuehrt', $q, ['angenommen', 'ausgefuehrt']);
    check('A: power-cycle mit MAC in Doppelpunktschreibweise und Portnummer', (bool)preg_match('/CMD \{"cmd":"power-cycle","mac":"02:00:00:00:00:02","port_idx":13\}/', $glog()), true);
    $q = $befehl("unifi/befehl/$sw/port13/poe", '1');
    check('A: PoE ein an Port 13 (auto)', [$q, (bool)preg_match('/"port_idx":13,"poe_mode":"auto"/', $glog())], [['angenommen', 'ausgefuehrt'], true]);
    // Port ganz ein/aus (aktiv)
    check('A: Zustand aktiv wird veroeffentlicht (Port 15 = 1)', $has("unifi/$sw/port15/aktiv", '1'), true);
    $q = $befehl("unifi/befehl/$sw/port14/aktiv", '0');
    check('A: aktiv an einem nicht freigegebenen Port abgelehnt', $q, ['abgelehnt: Ein- und Ausschalten für Port 14 ist gesperrt (im Reiter „Geräte“ freigeben)']);
    $q = $befehl("unifi/befehl/$sw/port12/aktiv", '0');
    check('A: aktiv an Port 12 (nur PoE-Freigabe) abgelehnt', str_starts_with($q[0] ?? '', 'abgelehnt: Ein- und Ausschalten für Port 12 ist gesperrt'), true);
    $q = $befehl("unifi/befehl/$sw/port22/aktiv", '0');
    check('A: aktiv am Uplink abgelehnt', $q, ['abgelehnt: Port 22 ist ein Uplink und wird nie geschaltet']);
    $q = $befehl("unifi/befehl/$sw/port15/aktiv", '2');
    check('A: aktiv mit ungueltigem Wert abgelehnt', $q, ['abgelehnt: ungültiger Wert']);
    $q = $befehl("unifi/befehl/$sw/port11/aktiv", '0');
    check('A: aktiv an einem Port mit Port-Profil: angenommen, dann abgelehnt', $q, ['angenommen', 'abgelehnt: Der Port nutzt ein Port-Profil: Ein- und Ausschalten wird dafür nicht unterstützt']);
    $putsVorher = substr_count($glog(), 'PUT ');
    $q = $befehl("unifi/befehl/$sw/port15/aktiv", '1');
    check('A: aktiv 1 an einem schon eingeschalteten Port: ausgefuehrt ohne Aenderung am Gateway', [$q, substr_count($glog(), 'PUT ') - $putsVorher], [['angenommen', 'ausgefuehrt'], 0]);
    $q = $befehl("unifi/befehl/$sw/port15/aktiv", '0');
    check('A: Port 15 abschalten: angenommen, dann ausgefuehrt', $q = $befehl("unifi/befehl/$sw/port15/aktiv", '0'), ['angenommen', 'ausgefuehrt']);
    preg_match_all('/PUT id-sw (.*)/', $glog(), $pm);
    $ov = json_decode($pm[1][count($pm[1]) - 1], true)['port_overrides'] ?? [];
    $o15 = array_values(array_filter($ov, fn($o) => $o['port_idx'] === 15))[0] ?? [];
    check('A: PUT enthaelt die komplette Liste (24), Port 15 wie in der Oberflaeche abgeschaltet, nur dieser Eintrag geaendert', [count($ov), $o15['forward'] ?? null, $o15['native_networkconf_id'] ?? null, $o15['tagged_vlan_mgmt'] ?? null, $o15['port_security_enabled'] ?? null,
        $o15['poe_mode'] ?? null, count(array_filter($ov, fn($o) => ($o['forward'] ?? '') === 'disabled'))], [24, 'disabled', '', 'block_all', true, 'auto', 1]);
    check('A: danach meldet das Plugin port15/aktiv = 0 (Gateway hat den Port abgeschaltet)', waitFor(fn() => $has("unifi/$sw/port15/aktiv", '0'), 10), true);
    $merk = Util::jsonLoad("$dA/data/port_vorher.json");
    check('A: Ausgangswerte vor dem Abschalten sind im Datenordner gemerkt, ohne Zugangsdaten', [$merk, str_contains((string)@file_get_contents("$dA/data/port_vorher.json"), GWPW)],
        [[$macSw . ':15' => ['forward' => 'all', 'native_networkconf_id' => 'net-1', 'tagged_vlan_mgmt' => 'auto', 'port_security_enabled' => false]], false]);
    $q = $befehl("unifi/befehl/$sw/port15/aktiv", '1');
    check('A: Port 15 wieder einschalten', $q, ['angenommen', 'ausgefuehrt']);
    check('A: danach port15/aktiv = 1, Merker entfernt', [waitFor(fn() => $has("unifi/$sw/port15/aktiv", '1'), 10), Util::jsonLoad("$dA/data/port_vorher.json")], [true, []]);
    $q = $befehl("unifi/befehl/$sw/port16/aktiv", '0');
    check('A: Port 16 (customize) abschalten, Ausgangswerte gemerkt', [$q, Util::jsonLoad("$dA/data/port_vorher.json")[$macSw . ':16']['forward'] ?? null], [['angenommen', 'ausgefuehrt'], 'customize']);
    $q = $befehl("unifi/befehl/$sw/port16/aktiv", '1');
    preg_match_all('/PUT id-sw (.*)/', $glog(), $pm);
    $ov = json_decode($pm[1][count($pm[1]) - 1], true)['port_overrides'] ?? [];
    $o16 = array_values(array_filter($ov, fn($o) => $o['port_idx'] === 16))[0] ?? [];
    check('A: Port 16 wieder ein: wie vorher (customize, custom, Netz, ausgeschlossene Netze), Merker weg', [$q, $o16['forward'] ?? null, $o16['tagged_vlan_mgmt'] ?? null, $o16['native_networkconf_id'] ?? null, $o16['excluded_networkconf_ids'] ?? null,
        Util::jsonLoad("$dA/data/port_vorher.json")], [['angenommen', 'ausgefuehrt'], 'customize', 'custom', 'net-1', ['net-2'], []]);
    $q = $befehl("unifi/befehl/$sw/port26/aktiv", '0');
    preg_match_all('/PUT id-sw (.*)/', $glog(), $pm);
    $ov = json_decode($pm[1][count($pm[1]) - 1], true)['port_overrides'] ?? [];
    check('A: Port 26 ohne bisherigen Eintrag: neuer Eintrag, abgeschaltet (Liste 25)', [$q, count($ov), array_values(array_filter($ov, fn($o) => $o['port_idx'] === 26))[0]['forward'] ?? null], [['angenommen', 'ausgefuehrt'], 25, 'disabled']);
    $q = $befehl("unifi/befehl/$sw/port26/aktiv", '1');
    check('A: Port 26 wieder ein', [$q, waitFor(fn() => $has("unifi/$sw/port26/aktiv", '1'), 10)], [['angenommen', 'ausgefuehrt'], true]);
    check('A: aktiv-Befehle stehen im Log ohne Zugangsdaten', [str_contains($logA(), 'Befehl aktiv=0 fuer Port 15 an switch_keller angenommen'), str_contains($logA(), GWPW)], [true, false]);
    // schnell hintereinander: zweiter Befehl innerhalb einer Sekunde wird abgelehnt
    $off = strlen($mlog());
    $einspeisen($mdir, "unifi/befehl/$sw/port13/poe\t1\nunifi/befehl/$sw/port13/poe\t1\n");
    waitFor(fn() => substr_count(substr($mlog(), $off), 'abgelehnt: zu schnell') >= 1, 6);
    check('A: zweiter Befehl innerhalb einer Sekunde wird abgelehnt', str_contains(substr($mlog(), $off), 'abgelehnt: zu schnell (höchstens ein Befehl je Sekunde)'), true);
    check('A: Befehle stehen im Log, ohne Zugangsdaten', [str_contains($logA(), 'Befehl poe=0 fuer Port 11 an switch_keller angenommen'), str_contains($logA(), GWPW)], [true, false]);
    $stopWait($svc);

    // ---------------------------------------------------------------- B: falsches Passwort wird nie von selbst wiederholt; danach "erneut versuchen"
    $resetGw(['pw' => 'anderes-passwort']);
    $reset();
    $dB = $neuDir('B');
    $svc = $starte($dB);
    check('B: Anmeldung "falsch"', waitFor(fn() => ($statusVon($dB)['anmeldung']['status'] ?? '') === 'falsch', 12), true);
    check('B: Text nennt Benutzername oder Passwort, nicht das Passwort selbst', [str_contains($statusVon($dB)['anmeldung']['text'] ?? '', 'Benutzername oder Passwort sind falsch'), str_contains((string)file_get_contents("$dB/unifi.log"), GWPW)], [true, false]);
    check('B: status/ok = 0 und Lebenszeichen laeuft weiter', waitFor(fn() => $has('unifi/status/ok', '0') && str_contains($mlog(), "unifi/status/zaehler\t"), 12), true);
    sleep(4);
    check('B: keine automatische Wiederholung (genau eine Anmeldung)', substr_count($glog(), 'LOGIN'), 1);
    $gwCtl(['user' => GWUSER, 'pw' => GWPW, 'csrf' => 'header']);
    sleep(1);
    @touch("$dB/unifi.json", time() + 5);       // "Anmeldung erneut versuchen" der Weboberflaeche
    check('B: nach "erneut versuchen" angemeldet, status/ok 1', waitFor(fn() => ($statusVon($dB)['anmeldung']['status'] ?? '') === 'ok' && $has('unifi/status/ok', '1'), 15), true);
    $stopWait($svc);

    // ---------------------------------------------------------------- C: Gateway nicht erreichbar
    $reset();
    $dC = $neuDir('C', ['controller' => ['adresse' => 'http://127.0.0.1:' . $leerPort]]);
    $svc = $starte($dC);
    check('C: Anmeldung "fehler" mit Klartext', waitFor(fn() => ($statusVon($dC)['anmeldung']['status'] ?? '') === 'fehler' && str_contains($statusVon($dC)['anmeldung']['text'] ?? '', 'Verbindung zum Gateway nicht möglich'), 12), true);
    check('C: status/ok = 0, Lebenszeichen laeuft weiter', waitFor(fn() => $has('unifi/status/ok', '0') && str_contains($mlog(), "unifi/status/zaehler\t"), 12), true);
    check('C: keine Werte ohne Verbindung', str_contains($mlog(), 'unifi/switch'), false);
    $stopWait($svc);

    // ---------------------------------------------------------------- D: Sitzung laeuft ab (401) -> neue Anmeldung, Betrieb geht weiter
    $resetGw();
    $reset();
    $dD = $neuDir('D');
    $svc = $starte($dD);
    check('D: laeuft', waitFor(fn() => $has('unifi/status/ok', '1') && $has("unifi/$sw/port18/clients", '3'), 15), true);
    $gwCtl(['user' => GWUSER, 'pw' => GWPW, 'csrf' => 'header', 'ablauf' => true]);
    check('D: nach 401 neue Anmeldung (zweiter LOGIN) und weiter Abfragen', waitFor(fn() => substr_count($glog(), 'LOGIN') >= 2 && ($statusVon($dD)['anmeldung']['status'] ?? '') === 'ok', 15), true);
    $n = substr_count($glog(), 'GET sta');
    check('D: danach laufen die Abfragen wieder', waitFor(fn() => substr_count($glog(), 'GET sta') > $n + 1, 8), true);
    check('D: Sitzungsende ist kein Fehler im Log', str_contains((string)file_get_contents("$dD/unifi.log"), 'ERROR'), false);
    $stopWait($svc);

    // ---------------------------------------------------------------- E: CSRF-Token nur im Anmelde-Cookie, Konto ohne Schreibrechte
    $resetGw(['csrf' => 'cookie']);
    $reset();
    $dE = $neuDir('E', $befehleCfg);
    $svc = $starte($dE);
    check('E: laeuft (CSRF nur im Cookie)', waitFor(fn() => $has('unifi/status/ok', '1') && $has("unifi/$sw/port18/clients", '3'), 15), true);
    $q = $befehl("unifi/befehl/$sw/port11/poe", '0');
    check('E: PoE-Befehl klappt mit dem Token aus dem Cookie (JWT)', [$q, str_contains($glog(), '403')], [['angenommen', 'ausgefuehrt'], false]);
    $gwCtl(['user' => GWUSER, 'pw' => GWPW, 'csrf' => 'cookie', 'nur_lesen' => true]);
    $q = $befehl("unifi/befehl/$sw/port12/poe", '0');
    check('E: Konto ohne Schreibrechte: abgelehnt mit Hinweis auf die Rolle', $q, ['angenommen', 'abgelehnt: Keine Berechtigung (Rolle des Kontos prüfen)']);
    $gwCtl(['user' => GWUSER, 'pw' => GWPW, 'csrf' => 'cookie', 'port_nicht_bereit' => true]);
    $q = $befehl("unifi/befehl/$sw/port13/neustart", '1');
    check('E: Gateway meldet InvalidTargetPort: verstaendliche Ablehnung ohne Rohtext', $q, ['angenommen', 'abgelehnt: Der Port ist für diesen Befehl gerade nicht bereit (liefert kein PoE, z. B. kurz nach „PoE ein“)']);
    $stopWait($svc);

    // ---------------------------------------------------------------- F: Portauswahl und Datenraten
    $resetGw();
    $reset();
    $dF = $neuDir('F', ['verkehr' => true, 'geraete' => [$macSw => ['aktiv' => true, 'name' => 'sw', 'ports' => '1-3, 18', 'befehle_ports' => '']]]);
    $svc = $starte($dF);
    check('F: ausgewaehlte Ports unter dem eigenen Namen, mit Datenraten', waitFor(fn() => $has('unifi/sw/port18/clients', '3') && $has('unifi/sw/port18/rx_bps', '4000000') && $has('unifi/sw/port1/up', '1'), 15), true);
    check('F: andere Ports fehlen', [str_contains($mlog(), 'unifi/sw/port4/'), str_contains($mlog(), 'unifi/sw/port25/'), str_contains($mlog(), 'unifi/switch_keller/')], [false, false, false]);
    check('F: Geraetewerte bleiben', $has('unifi/sw/poe_gesamt_w', '31.07'), true);
    check('F: mit Option Datenraten auch am Internetanschluss', [$has('unifi/internet/down_bps', '10000000'), $has('unifi/internet/up_bps', '500000')], [true, true]);
    $stopWait($svc);

    // ---------------------------------------------------------------- I: Online-Status ausgewaehlter Clients
    $resetGw();
    $reset();
    $clientCfg = ['clients' => ['020000001003' => ['name' => '', 'online' => true, 'ip' => true, 'verbindung' => true], '020000001002' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => false],
        '020000003001' => ['name' => '', 'online' => true, 'ip' => true, 'verbindung' => false], '020000009999' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => false]]];
    $dI = $neuDir('I', $clientCfg);
    $svc = $starte($dI);
    check('I: Online-Status der ausgewaehlten Clients (verbunden 1, bekannt aber offline 0, unbekannt 0)', waitFor(fn() => $has('unifi/client/kabel-client_3/online', '1') && $has('unifi/client/client_001002/online', '1')
        && $has('unifi/client/alter_drucker/online', '0') && $has('unifi/client/client_009999/online', '0'), 15), true);
    check('I: nicht ausgewaehlte Clients fehlen in MQTT', [str_contains($mlog(), 'unifi/client/host1'), str_contains($mlog(), 'unifi/client/client_001001')], [false, false]);
    check('I: IP nur bei Clients mit Schalter IP (verbunden: aktuelle, offline: zuletzt bekannte)', [$has('unifi/client/kabel-client_3/ip', '192.0.2.13'), $has('unifi/client/alter_drucker/ip', '192.0.2.201'), str_contains($mlog(), 'unifi/client/client_001002/ip'),
        str_contains($mlog(), 'unifi/client/client_009999/ip')], [true, true, false, false]);
    check('I: aktive Verbindung nur bei Clients mit Schalter Verbindung', [$has('unifi/client/kabel-client_3/verbindung', 'Switch Keller · Port 3'), str_contains($mlog(), 'unifi/client/client_001002/verbindung'), str_contains($mlog(), 'unifi/client/alter_drucker/verbindung')],
        [true, false, false]);
    check('I: IPs und Namen nicht gewaehlter Clients und IPs ohne Schalter nicht in MQTT; IPs nicht im Log und nicht in der Konfiguration; keine Original-Clientnamen', [str_contains($mlog(), '192.0.2.11'), str_contains($mlog(), '192.0.2.12'), str_contains($mlog(), '192.0.2.202'), str_contains((string)@file_get_contents("$dI/unifi.log"), '192.0.2.'),
        str_contains((string)@file_get_contents("$dI/unifi.json"), '192.0.2.'), str_contains($mlog(), 'Kabel-Client'), str_contains($mlog(), 'Alter Drucker'), str_contains($mlog(), 'host1')], [false, false, false, false, false, false, false, false]);
    $netzI = Util::jsonLoad("$dI/run/netz.json");
    check('I: Weboberflaeche bekommt alle Clients (verbunden und bekannt) im RAM', [count($netzI['modell']['client_liste'] ?? []), count($netzI['bekannt'] ?? []), str_contains((string)@file_get_contents("$dI/unifi.json"), 'Alter Drucker')], [19, 22, false]);
    check('I: Liste der bekannten Clients einmal geholt (nicht bei jeder Abfrage)', substr_count($glog(), 'GET user'), 1);
    // Client trennt sich: naechste Abfrage meldet 0
    $stI = json_decode((string)file_get_contents("$gdir/state.json"), true);
    $stI['clients'] = array_values(array_filter($stI['clients'], fn($c) => $c['mac'] !== '02:00:00:00:10:03'));
    file_put_contents("$gdir/state.json", json_encode($stI));
    check('I: getrennter Client wird mit 0 gemeldet, die aktive Verbindung mit "nicht verbunden"', waitFor(fn() => $has('unifi/client/kabel-client_3/online', '0') && $has('unifi/client/kabel-client_3/verbindung', 'nicht verbunden'), 12), true);
    $stI['clients'][] = ['mac' => '02:00:00:00:10:03', 'ip' => '192.0.2.13', 'is_wired' => true, 'sw_mac' => FX_SW, 'sw_port' => 1, 'name' => 'Kabel-Client 3'];
    file_put_contents("$gdir/state.json", json_encode($stI));
    check('I: wieder verbunden: 1 und die neue Verbindung (jetzt an Port 1)', waitFor(fn() => substr_count($mlog(), "unifi/client/kabel-client_3/online\t1") >= 2 && $has('unifi/client/kabel-client_3/verbindung', 'Switch Keller · Port 1'), 12), true);
    check('I: die IP bleibt nach dem Trennen die zuletzt bekannte (kein erneutes Senden, keine Leermeldung)', substr_count($mlog(), "unifi/client/kabel-client_3/ip\t"), 1);
    $stopWait($svc);

    // ---------------------------------------------------------------- J: Liste der bekannten Clients nicht lesbar (403) ist kein Fehler
    $resetGw(['user_403' => true, 'health_403' => true]);
    $reset();
    $dJ = $neuDir('J', $clientCfg);
    $svc = $starte($dJ);
    check('J: ohne rest/user: status/ok 1, verbundene Clients 1, offline bekannte 0', waitFor(fn() => $has('unifi/status/ok', '1') && $has('unifi/client/kabel-client_3/online', '1') && $has('unifi/client/client_003001/online', '0'), 15), true);
    check('J: ohne stat/health: Internet-Status und IP aus dem Gateway-Eintrag, kein Anbieter', [$has('unifi/internet/online', '1'), $has('unifi/internet/ip', '203.0.113.7'), $has('unifi/internet/verbindungsdauer_s', '126613'), str_contains($mlog(), 'unifi/internet/anbieter')],
        [true, true, true, false]);
    check('J: Warnung zu stat/health im Log', str_contains((string)@file_get_contents("$dJ/unifi.log"), 'Internetdaten (stat/health) nicht lesbar'), true);
    check('J: Warnung im Log, Weboberflaeche ohne bekannte Clients', [str_contains((string)@file_get_contents("$dJ/unifi.log"), 'Liste der bekannten Clients nicht lesbar'), count(Util::jsonLoad("$dJ/run/netz.json")['bekannt'] ?? [])], [true, 0]);
    $stopWait($svc);

    // ---------------------------------------------------------------- K: Gateway uebernimmt die Aenderung nicht (wie bei "nur forward senden"): abgelehnt und zurueckgerollt
    $resetGw(['aktiv_ignorieren' => true]);
    $reset();
    $dK = $neuDir('K', $befehleCfg);
    $svc = $starte($dK);
    check('K: Dienst laeuft', waitFor(fn() => $has("unifi/$sw/port15/aktiv", '1'), 15), true);
    $q = $befehl("unifi/befehl/$sw/port15/aktiv", '0');
    check('K: Quittung abgelehnt statt ausgefuehrt, mit Grund', $q, ['angenommen', 'abgelehnt: Das Gateway hat die Änderung nicht übernommen (die Einstellung des Ports blieb unverändert)']);
    preg_match_all('/PUT id-sw (.*)/', $glog(), $pm);
    $stK = json_decode((string)file_get_contents("$gdir/state.json"), true);
    check('K: zweimal gesendet (Aenderung, dann urspruenglicher Stand), Liste danach wie am Anfang, kein Merker', [count($pm[1]), $stK['geraete'][1]['port_overrides'] == fx_geraete()[1]['port_overrides'], is_file("$dK/data/port_vorher.json")], [2, true, false]);
    check('K: Port bleibt aktiv', $has("unifi/$sw/port15/aktiv", '1') && !$has("unifi/$sw/port15/aktiv", '0'), true);
    $stopWait($svc);

    // ---------------------------------------------------------------- G: Zwei-Faktor-Anmeldung, keine Daten
    $resetGw(['mfa' => true]);
    $reset();
    $dG = $neuDir('G');
    $svc = $starte($dG);
    check('G: Zwei-Faktor-Konto wird erkannt (falsch, mit Hinweis)', waitFor(fn() => ($statusVon($dG)['anmeldung']['status'] ?? '') === 'falsch' && str_contains($statusVon($dG)['anmeldung']['text'] ?? '', 'Zwei-Faktor'), 12), true);
    $stopWait($svc);
    $reset();
    $dH = $neuDir('H', ['controller' => ['adresse' => '', 'benutzer' => '', 'passwort' => '']]);
    $svc = $starte($dH);
    check('H: ohne Zugangsdaten "keine_daten", status/ok 0, kein Zugriff aufs Gateway', waitFor(fn() => ($statusVon($dH)['anmeldung']['status'] ?? '') === 'keine_daten' && $has('unifi/status/ok', '0'), 12), true);
    $stopWait($svc);

    foreach ([$mqProc, $gwProc] as $pp) {
        stopProc($pp);
    }
}

// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/unifi2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['UNI_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT, 'anmeldung' => ['status' => 'ok', 'text' => ''],
    'abfrage' => ['ok' => true, 'text' => '', 'zeit' => time()], 'clients' => ['gesamt' => 19, 'kabel' => 11, 'wlan' => 8],
    'geraete' => ['switch' => ['online' => true], 'ap1' => ['online' => true]]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Unifi2Lox: Dienst, Anmeldung am Gateway und Geräte');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3, Exitcode 0, keine Fehlerausgabe', [$j['status'], $rc, $e], [3, 0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
check('Healthcheck: Broker weg = Warnung 4', $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0]['status'], 4);
check('Healthcheck: kein Zugang = Info 6', $res(['anmeldung' => ['status' => 'keine_daten']] + $basis)[0]['status'], 6);
check('Healthcheck: falsches Passwort = Fehler 3', $res(['anmeldung' => ['status' => 'falsch', 'text' => 'Benutzername oder Passwort sind falsch']] + $basis)[0]['status'], 3);
$j = $res(['anmeldung' => ['status' => 'fehler', 'text' => 'Zeitüberschreitung beim Gateway']] + $basis)[0];
check('Healthcheck: Gateway gestoert = Warnung 4 mit Grund', [$j['status'], str_contains($j['result'], 'Zeitüberschreitung')], [4, true]);
check('Healthcheck: Anmeldung laeuft = Info 6', $res(['anmeldung' => ['status' => 'anmelden']] + $basis)[0]['status'], 6);
$j = $res(['abfrage' => ['ok' => false, 'text' => 'Zeitüberschreitung beim Gateway', 'zeit' => time() - 5]] + $basis)[0];
check('Healthcheck: Abfrage fehlgeschlagen = Warnung 4', [$j['status'], str_contains($j['result'], 'Abfrage am Gateway fehlgeschlagen')], [4, true]);
check('Healthcheck: noch keine Geraete = Info 6', $res(['geraete' => []] + $basis)[0]['status'], 6);
$j = $res(['geraete' => ['switch' => ['online' => true], 'ap1' => ['online' => false]]] + $basis)[0];
check('Healthcheck: Geraet offline = Warnung 4 mit Name', [$j['status'], str_contains($j['result'], 'Geräte nicht erreichbar: ap1')], [4, true]);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5, Text zaehlt Geraete und Clients, nennt Version', [$j['status'], $j['result']], [5, '2 Geräte online, 19 Clients, Version ' . AppVersion::CURRENT]);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/unifi.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/unifi.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev', 'sess', 'dev/run', 'dev/data'] as $sub) {
    mkdir("$uiDir/$sub", 0777, true);
}
$uiPage = function (string $tab, array $env = []) use ($php, $phpOpt, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/unifi2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/unifi2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'UNI_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = array_merge([$php], $phpOpt, ['-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path="' . $root . '/tests/stubs"', '-d', 'session.save_path="' . "$uiDir/sess" . '"',
        $root . '/tests/ui_page.php', $tab]);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
$uiConf = UniConfig::defaults();
$uiConf['controller'] = ['adresse' => 'https://gw.example.invalid', 'benutzer' => 'uibenutzer', 'passwort' => 'uigeheim777', 'seite' => 'default', 'tls_pruefen' => false];
$uiConf['geraete'] = [$macSw ?? '020000000002' => ['aktiv' => true, 'name' => 'keller', 'ports' => '1-8', 'befehle_ports' => '11-13', 'schalt_ports' => '15, 16']];
$uiConf['clients'] = ['020000001003' => ['name' => 'kabel-client_3', 'online' => true, 'ip' => true, 'verbindung' => false], '020000003001' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => true], '020000009999' => ['name' => '', 'online' => true, 'ip' => false, 'verbindung' => false]];
$uiConf['mqtt']['passwort'] = 'brokerpasswort77';
Util::jsonSave("$uiDir/dev/unifi.json", $uiConf);
foreach (['verbindung', 'geraete', 'netz', 'clients', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 8 Eintraegen, genau ein aktiver (dieser)", [count($nav), count(array_filter($nav, fn($n) => !empty($n['active']))), array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null], [8, 1, '?tab=' . $tab]);
    check("UI $tab: Design System (nojqm gesetzt, lb-content, kein jQuery-Mobile-Rest)", [str_contains($o, '<!--NOJQM:1-->'), str_contains($o, 'class="lb-content'), str_contains($o, 'data-role='), str_contains($o, 'data-ajax=')], [true, true, false, false]);
    check("UI $tab: kein Zugangsdatum im HTML", [str_contains($o, 'uigeheim777'), str_contains($o, 'brokerpasswort77')], [false, false]);
    check("UI $tab: kein JavaScript (nur Formulare)", [str_contains($o, '<script'), str_contains($o, 'onclick=')], [false, false]);
}
[$o] = $uiPage('verbindung');
check('UI Verbindung: Felder, Passwort als leeres Feld mit "(gesetzt)", Hinweis auf lokales Konto', [str_contains($o, 'name="controller[adresse]" value="https://gw.example.invalid"'), str_contains($o, 'name="controller[benutzer]" value="uibenutzer"'),
    str_contains($o, 'type="password" name="controller[passwort]" value=""'), str_contains($o, 'placeholder="(gesetzt)"'), str_contains($o, 'lokales Konto')], [true, true, true, true, true]);
check('UI Verbindung: ohne Dienst "der Dienst läuft nicht"', str_contains($o, 'der Dienst läuft nicht'), true);
$stOk = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'ok' => 1, 'zaehler' => 5, 'version' => AppVersion::CURRENT, 'anmeldung' => ['status' => 'ok', 'text' => ''],
    'abfrage' => ['ok' => true, 'text' => '', 'zeit' => time(), 'dauer_ms' => 120, 'anzahl' => 3], 'geraete' => [], 'clients' => ['gesamt' => 19, 'kabel' => 11, 'wlan' => 8]];
Util::jsonSave("$uiDir/dev/run/status.json", array_replace($stOk, ['anmeldung' => ['status' => 'falsch', 'text' => 'Benutzername oder Passwort sind falsch']]));
[$o] = $uiPage('verbindung');
check('UI Verbindung: "falsch" bietet erneuten Versuch an', [str_contains($o, 'value="neu_anmelden"'), str_contains($o, 'Benutzername oder Passwort sind falsch')], [true, true]);
Util::jsonSave("$uiDir/dev/run/status.json", $stOk);
[$o] = $uiPage('verbindung');
check('UI Verbindung: angemeldet mit letzter Abfrage', [str_contains($o, '<b>angemeldet</b>'), str_contains($o, 'Letzte Abfrage'), str_contains($o, '120 ms')], [true, true, true]);
// Netzuebersicht aus dem Modell
$devsUi = fx_geraete();
$devsUi[1]['port_table'][14]['enable'] = false;          // Port 15 des Switches abgeschaltet (wie in der Oberflaeche des Gateways)
$devsUi[1]['port_table'][14]['forward'] = 'disabled';
Util::jsonSave("$uiDir/dev/run/netz.json", ['zeit' => time(), 'modell' => $m0 = UniDaten::modell($devsUi, fx_clients()), 'bekannt' => UniDaten::bekannte(fx_bekannte()), 'internet' => UniDaten::internet(fx_geraete(), fx_health(940))]);
[$o, $e] = $uiPage('netz');
check('UI Netzwerk: Karte Internet (Anbieter, AS, Status, Dauer, IP, Latenz, Verfuegbarkeit, Anschluesse, Datenrate, Speedtest)', [str_contains($o, '<h4>Internet</h4>'), str_contains($o, 'Beispiel Telekom AG'), str_contains($o, '(AS64496)'), str_contains($o, '1 Tag 11 Std 10 Min'),
    str_contains($o, '203.0.113.7'), str_contains($o, '6 ms'), str_contains($o, '99,9 %'), str_contains($o, 'WAN 1: <span class="uni-led uni-led-ok"></span> Link'), str_contains($o, 'WAN 2: <span class="uni-led uni-led-aus"></span> kein Link'), str_contains($o, '(1 Gbit/s)'),
    str_contains($o, '↓ 10 Mbit/s · ↑ 0,5 Mbit/s'), str_contains($o, '↓ 940 · ↑ 45,5 Mbit/s, Ping 9 ms')], [true, true, true, true, true, true, true, true, true, true, true, true]);
check('UI Netzwerk: abgeschalteter Port steht als inaktiv da (genau einer), andere Ports ohne Link nicht', [substr_count($o, 'uni-led-inaktiv" title="Port abgeschaltet"></span> inaktiv <span'), str_contains($o, '<b>inaktiv'), str_contains($o, '.uni-led-inaktiv { background:#fff; }'), str_contains($o, 'kein Link')], [1, false, true, true]);
check('UI Netzwerk: Geraete, Ports, PoE, Clients je Port, Access-Point-Clients', [str_contains($o, 'Switch Keller'), str_contains($o, 'US-24-250W'), str_contains($o, '6,2 W') || str_contains($o, '6.2 W'), str_contains($o, 'AP Wohnzimmer</b> (UniFi-Gerät)'), str_contains($o, 'WLAN-Client 1'),
    str_contains($o, '4 im 2,4 GHz'), str_contains($o, 'Kabel-Client 3'), str_contains($o, '19 Clients (11 Kabel, 8 WLAN)'), $bad($o, $e)], [true, true, true, true, true, true, true, true, false]);
[$o, $e] = $uiPage('clients');
check('UI Clients: verbundene Clients mit IP, Ort und Status, gewaehlte (auch offline und unbekannte) sichtbar, andere Offline-Clients ausgeblendet', [str_contains($o, 'Kabel-Client 3'), str_contains($o, '192.0.2.13'), str_contains($o, 'Switch Keller · Port 1'),
    str_contains($o, 'AP Wohnzimmer · 2,4 GHz · Heim'), str_contains($o, 'Alter Drucker'), str_contains($o, 'zuletzt 13.11.2023'), str_contains($o, '02:00:00:00:99:99'), str_contains($o, 'handy-gast'), str_contains($o, '2 weitere, derzeit nicht verbundene Clients sind ausgeblendet'), $bad($o, $e)],
    [true, true, true, true, true, true, true, false, true, false]);
check('UI Clients: Schalter und Namensfeld je Zeile, Auswahl aus der Konfiguration', [str_contains($o, 'name="clients[020000001003][aktiv]" value="1" checked'), str_contains($o, 'name="clients[020000001001][aktiv]" value="1"><span'), str_contains($o, 'name="clients[020000001003][name]" value="kabel-client_3"'),
    str_contains($o, 'name="clients[020000001001][da]"'), str_contains($o, 'name="clients[020000001001][name]" value="host1"'), str_contains($o, 'name="clients[020000001002][name]" value="client_001002"'), str_contains($o, 'unifi/client/&lt;name&gt;/online')],
    [true, true, true, true, true, true, true]);
check('UI Clients: Schalter IP und Verbindung je Zeile (gewaehlt/nicht gewaehlt), Spaltenkoepfe', [str_contains($o, 'name="clients[020000001003][ip]" value="1" checked'), str_contains($o, 'name="clients[020000001003][verbindung]" value="1"><span'),
    str_contains($o, 'name="clients[020000003001][verbindung]" value="1" checked'), str_contains($o, 'name="clients[020000003001][ip]" value="1"><span'), str_contains($o, 'name="clients[020000001001][ip]" value="1"><span'),
    str_contains($o, 'name="clients[020000001001][verbindung]" value="1"><span'), str_contains($o, 'Online-Status senden</a>'), str_contains($o, 'IP senden</a>'), str_contains($o, 'Verbindung senden</a>'), str_contains($o, '…/verbindung</code>')],
    [true, true, true, true, true, true, true, true, true, true]);
[$o] = $uiPage('clients', ['STUB_ALLE' => '1']);
check('UI Clients: mit "alle bekannten" auch die nicht verbundenen', [str_contains($o, 'handy-gast'), str_contains($o, '192.0.2.202'), str_contains($o, '(ohne Namen)'), str_contains($o, 'nur verbundene und gewählte anzeigen')], [true, true, true, true]);
$pos = fn(string $o, string $s) => strpos($o, $s);
[$o0] = $uiPage('clients');
check('UI Clients: Spaltenkoepfe sind Sortierlinks (ohne JavaScript), Standard = Status aufsteigend mit Pfeil', [str_contains($o0, 'clients&amp;sort=ip&amp;dir=asc'), str_contains($o0, 'clients&amp;sort=auswahl&amp;dir=asc'), str_contains($o0, 'clients&amp;sort=status&amp;dir=desc'),
    str_contains($o0, 'Status</a> ▲'), substr_count($o0, ' ▲') + substr_count($o0, ' ▼')], [true, true, true, true, 1]);
// Standardreihenfolge: verbundene zuerst nach Namen (host1 vor Kabel-Client 3), dann nicht verbundene (Alter Drucker, gewaehlt)
check('UI Clients: Standardsortierung (online zuerst, nach Namen)', [$pos($o0, '02:00:00:00:10:01') < $pos($o0, '02:00:00:00:10:03'), $pos($o0, '02:00:00:00:10:03') < $pos($o0, '02:00:00:00:30:01')], [true, true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=ip&dir=asc']);
check('UI Clients: nach IP aufsteigend (numerisch: .21 vor .100), Link kehrt die Richtung um, Pfeil an der Spalte', [$pos($o, '192.0.2.13') < $pos($o, '192.0.2.21'), $pos($o, '192.0.2.21') < $pos($o, '192.0.2.100'), str_contains($o, 'clients&amp;sort=ip&amp;dir=desc'), str_contains($o, 'IP-Adresse</a> ▲'),
    str_contains($o, 'action="?tab=clients&amp;sort=ip&amp;dir=asc"')], [true, true, true, true, true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=ip&dir=desc']);
check('UI Clients: nach IP absteigend, leere IP bleibt am Ende (unbekannter gewaehlter Client)', [$pos($o, '192.0.2.100') < $pos($o, '192.0.2.21'), $pos($o, '192.0.2.21') < $pos($o, '192.0.2.13'), $pos($o, '02:00:00:00:99:99') > $pos($o, '192.0.2.13'),
    str_contains($o, 'IP-Adresse</a> ▼')], [true, true, true, true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=auswahl&dir=asc']);
check('UI Clients: nach Auswahl (gewaehlte zuerst)', [$pos($o, '02:00:00:00:10:03') < $pos($o, '02:00:00:00:10:01')], [true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=sip&dir=asc']);
check('UI Clients: nach "IP senden" (gewaehlter vor nicht gewaehltem), nach "Verbindung senden"', [$pos($o, '02:00:00:00:10:03') < $pos($o, '02:00:00:00:10:01'), $pos(($uiPage('clients', ['STUB_GET' => 'sort=sverb&dir=asc']))[0], '02:00:00:00:30:01') < $pos(($uiPage('clients', ['STUB_GET' => 'sort=sverb&dir=asc']))[0], '02:00:00:00:10:01')], [true, true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=auswahl&dir=desc']);
check('UI Clients: nach Auswahl absteigend (nicht gewaehlte zuerst)', [$pos($o, '02:00:00:00:10:01') < $pos($o, '02:00:00:00:10:03')], [true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=name&dir=desc']);
check('UI Clients: nach Client-Name absteigend (Kabel-Client 3 vor host1)', [$pos($o, '02:00:00:00:10:03') < $pos($o, '02:00:00:00:10:01')], [true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=ort&dir=asc']);
check('UI Clients: nach Verbindung (AP Wohnzimmer vor Switch Keller), ohne Ort am Ende', [$pos($o, 'AP Wohnzimmer') < $pos($o, 'Switch Keller · Port 1'), $pos($o, 'Switch Keller · Port 1') < $pos($o, '02:00:00:00:99:99')], [true, true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=topic&dir=asc']);
check('UI Clients: nach Name im Topic aufsteigend (host1 vor kabel-client_3)', [$pos($o, '02:00:00:00:10:01') < $pos($o, '02:00:00:00:10:03')], [true]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=boese&dir=quer']);
check('UI Clients: ungueltige Sortierangabe faellt auf die Standardsortierung zurueck', [str_contains($o, 'Status</a> ▲'), str_contains($o, 'boese')], [true, false]);
[$o] = $uiPage('clients', ['STUB_GET' => 'sort=name&dir=asc', 'STUB_ALLE' => '1']);
check('UI Clients: Sortierung bleibt bei "alle bekannten" und im Link zurueck erhalten', [str_contains($o, 'clients&amp;sort=ip&amp;dir=asc&amp;alle=1'), str_contains($o, 'tab=clients&amp;sort=name&amp;dir=asc">nur verbundene')], [true, true]);
[$o] = $uiPage('geraete');
check('UI Geraete: Name, Portauswahl, Befehlsfreigabe, Hinweis auf Stromlos', [str_contains($o, 'name="geraete[020000000002][name]" value="keller"'), str_contains($o, 'name="geraete[020000000002][ports]" value="1-8"'),
    str_contains($o, 'name="geraete[020000000002][befehle_ports]" value="11-13"'), str_contains($o, 'name="geraete[020000000001][befehle_ports]"'), str_contains($o, 'stromlos'), str_contains($o, 'class="lb-toggle"'), str_contains($o, 'name="geraete[020000000002][schalt_ports]" value="15, 16"'), str_contains($o, 'name="geraete[020000000001][schalt_ports]"')], [true, true, true, true, true, true, true, true]);
check('UI Geraete: Platzhalter zeigt den Namen aus dem Gateway, Access Point ohne Port- und Befehlsfelder', [str_contains($o, 'placeholder="ap_wohnzimmer"'), str_contains($o, 'name="geraete[020000000003][ports]"'), str_contains($o, 'name="geraete[020000000003][befehle_ports]"'), str_contains($o, 'name="geraete[020000000003][schalt_ports]"')], [true, false, false, false]);
[$o] = $uiPage('loxone', ['STUB_UDP' => '12345']);
check('UI Loxone: Topic-Tabelle und Befehle mit UDP-Port und Quittung', [str_contains($o, 'unifi/&lt;gerät&gt;/port&lt;nr&gt;/poe_w') || str_contains($o, 'port<nr>/poe_w'), str_contains($o, 'unifi/status/zaehler'), str_contains($o, '/dev/udp/&lt;LoxBerry-IP&gt;/12345'),
    str_contains($o, 'unifi/befehl/keller/port&lt;nr&gt;/poe'), str_contains($o, 'neustart'), str_contains($o, 'quittung'), str_contains($o, 'unifi/befehl/keller/port&lt;nr&gt;/aktiv'), str_contains($o, 'port&lt;nr&gt;/aktiv</code> zeigt'), str_contains($o, 'unifi/client/&lt;name&gt;/online') || str_contains($o, 'unifi/client/<name>/online'), str_contains($o, 'unifi/internet/verbindungsdauer_s'), str_contains($o, 'unifi/internet/anbieter'), str_contains($o, 'unifi/client/&lt;name&gt;/verbindung') || str_contains($o, 'unifi/client/<name>/verbindung')], [true, true, true, true, true, true, true, true, true, true, true, true]);
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „unifi/#“ fehlt'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'unifi/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Abo eingetragen = gruenes Feld', str_contains($o, 'Gateway V2: eingetragen (unifi/#)') && str_contains($o, 'gw-abo-ok'), true);
[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);
$uiCode = (string)file_get_contents($root . '/unifi2lox/webfrontend/htmlauth/index.php');
check('UI-Code: kein JavaScript', str_contains($uiCode, '<script'), false);

// ---------------------------------------------------------------- plugin.cfg, Installationsskripte, Bauen, ZIP
$pcfg = (string)file_get_contents($root . '/unifi2lox/plugin.cfg');
check('plugin.cfg: Identitaet', [str_contains($pcfg, "NAME=Rico Tengler\nEMAIL=rico@cod-lan.de\n"), str_contains($pcfg, "NAME=unifi2lox\nFOLDER=unifi2lox\nTITLE=Unifi2Lox\n"), str_contains($pcfg, 'VERSION=' . AppVersion::CURRENT . "\n")], [true, true, true]);
check('plugin.cfg: Autoupdate an, RELEASECFG auf raw.githubusercontent.com (main), PRERELEASECFG leer, WEBSITE',
    [str_contains($pcfg, "\nAUTOMATIC_UPDATES=true\n"), str_contains($pcfg, "\nRELEASECFG=https://raw.githubusercontent.com/ricten/Unifi2Lox/main/release.cfg\n"), str_contains($pcfg, "\nPRERELEASECFG=\n"), str_contains($pcfg, "\nWEBSITE=https://github.com/ricten/Unifi2Lox\n")], [true, true, true, true]);
check('plugin.cfg: Loglevel-Auswahl frei, TITLE hoechstens 25 Zeichen', [str_contains($pcfg, "\nCUSTOM_LOGLEVELS=true\n"), strlen('Unifi2Lox') <= 25], [true, true]);
check('Plugin-Icons als PNG in allen vier Groessen, kein icon.svg', [is_file($root . '/unifi2lox/icons/icon_64.png') && is_file($root . '/unifi2lox/icons/icon_128.png') && is_file($root . '/unifi2lox/icons/icon_256.png') && is_file($root . '/unifi2lox/icons/icon_512.png'), is_file($root . '/unifi2lox/icons/icon.svg')], [true, false]);
check('Abo-Datei: ein Topic je Zeile, keine release.cfg im Plugin', [file_get_contents($root . '/unifi2lox/config/mqtt_subscriptions.cfg'), is_file($root . '/unifi2lox/release.cfg')], ["unifi/#\n", false]);
$hart = [];
$ips = [];
$echteDaten = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $pfad = str_replace('\\', '/', $f->getPathname());
    if (str_contains($pfad, '/.git/') || preg_match('/\.(png|zip)$/', $pfad) || str_contains($pfad, '/build/') || str_contains($pfad, '/tests/tmp/')) {
        continue;
    }
    $t = (string)file_get_contents($f->getPathname());
    if (str_contains($pfad, '/unifi2lox/')) {
        if (str_contains($t, '/opt/loxberry')) {
            $hart[] = $f->getFilename();
        }
        if (str_contains($t, "\r\n")) {
            $hart[] = 'CRLF:' . $f->getFilename();
        }
    }
    if (preg_match('/\b192' . '\.168\./', $t)) {
        $ips[] = $f->getFilename();
    }
    if (preg_match('/\b(?!02:00:00:00:)[0-9a-f]{2}(:[0-9a-f]{2}){5}\b/i', $t)) {        // echte MAC-Adressen (erfundene beginnen mit 02:00:00:00)
        $echteDaten[] = $f->getFilename();
    }
}
check('Keine festen /opt/loxberry-Pfade, nur LF-Zeilenenden im Plugin', $hart, []);
check('Keine privaten IP-Adressen (Heimnetz-Bereich) im Repo', $ips, []);
check('Keine echten MAC-Adressen im Repo (nur erfundene 02:00:00:00:…)', $echteDaten, []);

$bashOk = false;
$pr = @proc_open(['bash', '-c', 'command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if (is_resource($pr)) {
    stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    $bashOk = proc_close($pr) === 0;
}
if ($bashOk) {
    // build.sh in einer Kopie des Repos (sonst entstuende eine echte release.cfg bzw. ein ZIP im Repo)
    $rc = $dir . '/rc';
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    };
    mkdir("$rc/unifi", 0777, true);
    copy($root . '/build.sh', "$rc/unifi/build.sh");
    copy($root . '/CHANGELOG.md', "$rc/unifi/CHANGELOG.md");
    $copy($root . '/unifi2lox', "$rc/unifi/unifi2lox");
    $copy(dirname($root) . '/common', "$rc/common");
    $ver = AppVersion::CURRENT;
    $bs = function (string $arg = '') use ($rc): string {
        $cmd = $arg !== '' ? ['bash', 'build.sh', $arg] : ['bash', 'build.sh'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$rc/unifi");
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $o;
    };
    $bo = $bs();
    if (!file_exists("$rc/unifi/unifi2lox-$ver.zip")) {
        echo "build.sh-Ausgabe: " . substr($bo, 0, 600) . "\n";
    }
    check('build.sh: baut beide ZIPs (mit und ohne Version), ohne release.cfg zu schreiben', [file_exists("$rc/unifi/unifi2lox-$ver.zip"), file_exists("$rc/unifi/unifi2lox.zip"), file_exists("$rc/unifi/release.cfg")], [true, true, false]);
    check('build.sh: die beiden ZIPs sind gleich', md5_file("$rc/unifi/unifi2lox-$ver.zip"), md5_file("$rc/unifi/unifi2lox.zip"));
    $bs('release-cfg');
    $cfgtxt = (string)@file_get_contents("$rc/unifi/release.cfg");
    check('build.sh release-cfg: Version, ZIP-Link, Info-Link, LF', [str_contains($cfgtxt, "[AUTOUPDATE]\n"), str_contains($cfgtxt, "\nVERSION=$ver\n"),
        str_contains($cfgtxt, "\nARCHIVEURL=https://github.com/ricten/Unifi2Lox/releases/download/v$ver/unifi2lox-$ver.zip\n"), str_contains($cfgtxt, "\nINFOURL=https://github.com/ricten/Unifi2Lox/releases/tag/v$ver\n"), str_contains($cfgtxt, "\r")], [true, true, true, true, false]);
    $zp = proc_open(['bash', '-c', 'P=$(command -v python3 || command -v python); "$P" -c "import sys,zipfile;z=zipfile.ZipFile(sys.argv[1]);print(chr(10).join(z.namelist()));print(chr(0));print(sum(1 for n in z.namelist() if b\'geheimpw\' in z.read(n) or b\'uigeheim\' in z.read(n)))" "$1"', 'x', "$rc/unifi/unifi2lox-$ver.zip"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $zpp);
    $zout = str_replace("\r\n", "\n", stream_get_contents($zpp[1]));
    stream_get_contents($zpp[2]);
    proc_close($zp);
    [$zl, $zs] = array_pad(explode("\n\0\n", $zout, 2), 2, '');
    $namen = explode("\n", $zl);
    check('ZIP: plugin.cfg zuerst, Dienst, Healthcheck, gemeinsame und eigene Klassen, Weboberflaeche', [$namen[0] ?? '', in_array('bin/unifid.php', $namen, true), in_array('bin/healthcheck', $namen, true), in_array('bin/lib/MqttClient.php', $namen, true),
        in_array('bin/lib/UniController.php', $namen, true), in_array('bin/lib/UniDaten.php', $namen, true), in_array('webfrontend/htmlauth/index.php', $namen, true), in_array('uninstall/uninstall', $namen, true)], ['plugin.cfg', true, true, true, true, true, true, true]);
    check('ZIP: keine release.cfg, vier PNG-Icons und kein icon.svg, keine Tests, keine ZIPs', [in_array('release.cfg', $namen, true), count(array_filter($namen, fn($n) => preg_match('#^icons/icon_(64|128|256|512)\.png$#', $n))), in_array('icons/icon.svg', $namen, true), count(array_filter($namen, fn($n) => str_contains($n, 'tests/'))), count(array_filter($namen, fn($n) => str_contains($n, 'zip')))], [false, 4, false, 0, 0]);
    check('ZIP: keine Zugangsdaten der Tests in den Dateien', trim($zs), '0');
    file_put_contents("$rc/unifi/release.cfg", str_replace("VERSION=$ver", 'VERSION=0.0.1', $cfgtxt));
    check('build.sh: abweichende release.cfg nur als Hinweis (kein Fehler)', str_contains($bs(), 'Hinweis: release.cfg zeigt auf 0.0.1'), true);
} else {
    echo "Hinweis: bash/python fehlen, build.sh-Tests uebersprungen\n";
}

if (getenv('UNI_TEST_KEEP')) {
    echo "Testverzeichnis behalten: $dir\n";
} else {
    rrmdir($dir);
}
echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
