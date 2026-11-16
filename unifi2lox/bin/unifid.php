<?php
/**
 * UniFi-Dienst: meldet sich mit einem lokalen Konto am Gateway an, fragt Geraete (Ports, PoE, Access Points) und Clients ab
 * und veroeffentlicht nur geaenderte Werte auf dem MQTT-Broker des LoxBerry. Nimmt Befehle aus Loxone an (unifi/befehl/<geraet>/port<nr>/<befehl>).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('UNI_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

const T = 'unifi';          // Topic-Wurzel
const REFRESH = 300;        // alle Werte spaetestens alle 5 Minuten erneut senden

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile, 'Dienst', 'UniFi-Dienst');
/** Loglevel aus der LoxBerry-Pluginverwaltung (im Test UNI_DEV_LOGLEVEL). Wird im Betrieb regelmaessig neu gelesen. */
function applyLogLevel(Logger $log): void
{
    if (getenv('UNI_DEV_DIR')) {
        $log->setLevel(getenv('UNI_DEV_LOGLEVEL') ?: 'debug');
    } elseif (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
        $lvl = (int)LBSystem::pluginloglevel();
        if ($lvl > 0) {
            $log->setLoxberryLevel($lvl);
        }
    }
}
applyLogLevel($log);
$logLevelCheck = 0;
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/** Broker-Daten: eigener Broker aus den Einstellungen, sonst LoxBerry-Konfiguration; im Test UNI_DEV_MQTT=host:port. */
function brokerDetails(Logger $log, array $uni): ?array
{
    $dev = getenv('UNI_DEV_MQTT');
    if ($dev) {
        [$h, $p] = explode(':', $dev, 2) + [1 => '1883'];
        return [$h, (int)$p, '', ''];
    }
    $own = UniConfig::ownBroker($uni);
    if ($own !== null) {
        return $own;
    }
    if (!function_exists('mqtt_connectiondetails')) {
        $log->error('LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar', 'mqtt-details');
        return null;
    }
    $d = mqtt_connectiondetails();     // ueberschreibt die globale Variable $cfg - die Dienstkonfiguration heisst deshalb $uni
    $host = is_array($d) ? (string)($d['brokerhost'] ?? '') : '';
    $port = is_array($d) ? (int)($d['brokerport'] ?? 0) : 0;
    if (($host === '' || $port === 0) && is_array($d) && !empty($d['brokeraddress']) && str_contains((string)$d['brokeraddress'], ':')) {
        [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
        $host = $host !== '' ? $host : $h2;
        $port = $port ?: (int)$p2;
    }
    if ($host === '') {
        $log->error('Keine Broker-Daten in der LoxBerry-Konfiguration (MQTT eingerichtet?)', 'mqtt-details');
        return null;
    }
    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
}

// ------------------------------------------------------------------ Zustand
$uni = UniConfig::load($paths->config);
$cfgMtime = -1;
/** @var UniController|null $ctl */
$ctl = null;
$authStatus = 'keine_daten';    // keine_daten | anmelden | ok | falsch | fehler
$authText = '';
$authSeit = 0;
$authNext = 0;
$authBackoff = 30;
$abfrageNext = 0;
$abfrageOk = false;
$abfrageText = '';
$abfrageZeit = 0;               // letzte erfolgreiche Abfrage
$abfrageDauerMs = 0;
$abfrageZaehler = 0;
$modell = null;                 // UniDaten::modell()
$roh = [];                      // MAC => Rohdaten des Geraets (fuer Befehle)
$namen = [];                    // MAC => Name im Topic
$nameZuMac = [];
$bekannt = [];                  // alle dem Gateway bekannten Clients (UniDaten::bekannte(); nur fuer die Weboberflaeche und den Online-Status)
$bekanntNext = 0;
$internet = null;               // UniDaten::internet(): Internetverbindung (WAN), nur lesen
$clientNamen = [];              // MAC => Name im Topic der ausgewaehlten Clients
$gesendet = [];                 // Topic => [Wert, Zeit]
$letzterBefehl = 0.0;
$mqtt = null;
$mqttErr = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqttSince = 0;
$zaehler = 0;
$lastStatusTopics = 0;
$lastPublish = 0;
$lastStatus = 0;
$ok = 0;
$started = time();
$log->info('UniFi-Dienst ' . AppVersion::CURRENT . ' gestartet (PID ' . getmypid() . ')');

/** Namen der Geraete neu bilden (nach Abfrage oder Konfigurationsaenderung). */
$baueNamen = function () use (&$namen, &$nameZuMac, &$modell, &$uni, &$bekannt, &$clientNamen) {
    $namen = [];
    $nameZuMac = [];
    $clientNamen = [];
    if ($modell === null) {
        return;
    }
    $clientNamen = UniConfig::clientNamen($uni, UniDaten::clientAnzeige($modell, $bekannt));
    $namen = UniConfig::namen($uni, array_map(fn($g) => ['mac' => $g['mac'], 'name' => $g['name'], 'typ' => $g['typ']], $modell['geraete']));
    $nameZuMac = array_flip($namen);
};

/** Eine Abfrage (Geraete und Clients) am Gateway. Liefert true bei Erfolg. */
$abfragen = function (int $now) use (&$ctl, &$authStatus, &$authText, &$authSeit, &$authNext, &$abfrageOk, &$abfrageText, &$abfrageZeit, &$abfrageDauerMs,
    &$abfrageZaehler, &$modell, &$roh, &$bekannt, &$bekanntNext, &$internet, $paths, $log, $baueNamen): bool {
    $t0 = microtime(true);
    $g = $ctl->geraete();
    $c = $g['ok'] ? $ctl->clients() : $g;
    if ($g['ok'] && $c['ok']) {
        $abfrageDauerMs = (int)round((microtime(true) - $t0) * 1000);
        $modell = UniDaten::modell($g['daten'], $c['daten']);
        $roh = [];
        foreach ($g['daten'] as $d) {
            $roh[UniConfig::cleanMac((string)($d['mac'] ?? ''))] = $d;
        }
        $abfrageOk = true;
        $abfrageText = '';
        $abfrageZeit = $now;
        $abfrageZaehler++;
        if ($now >= $bekanntNext) {                       // alle bekannten Clients (auch offline) nur alle 5 Minuten; ein Fehler hier ist nicht schlimm
            $bekanntNext = $now + 300;
            $u = $ctl->bekannteClients();
            if ($u['ok']) {
                $bekannt = UniDaten::bekannte($u['daten']);
            } else {
                $log->warn('Liste der bekannten Clients nicht lesbar: ' . $u['text'], 'bekannte-clients');
            }
        }
        $h = $ctl->gesundheit();                          // Provider und WAN-Daten; ein Fehler hier ist nicht schlimm (Rest kommt aus dem Gateway-Eintrag)
        if (!$h['ok']) {
            $log->warn('Internetdaten (stat/health) nicht lesbar: ' . $h['text'], 'health');
        }
        $internet = UniDaten::internet($g['daten'], $h['ok'] ? $h['daten'] : []);
        $baueNamen();
        Util::jsonSave($paths->netzFile(), ['zeit' => $now, 'modell' => $modell, 'bekannt' => $bekannt, 'internet' => $internet]);
        $log->debug('Abfrage: ' . count($modell['geraete']) . ' Geräte, ' . $modell['clients']['gesamt'] . ' Clients, ' . $abfrageDauerMs . ' ms');
        return true;
    }
    $f = $g['ok'] ? $c : $g;
    $abfrageOk = false;
    $abfrageText = $f['text'];
    if ($f['status'] === 401) {                       // Sitzung abgelaufen: neu anmelden
        $authStatus = 'anmelden';
        $authText = 'Sitzung abgelaufen, neue Anmeldung';
        $authSeit = $now;
        $authNext = $now;
        $log->info('Sitzung am Gateway abgelaufen: neue Anmeldung');
    } else {
        $log->error('Abfrage am Gateway fehlgeschlagen: ' . $f['text'], 'abfrage');
    }
    return false;
};

/** Befehl aus Loxone: unifi/befehl/<geraet>/port<nr>/<befehl> = Wert. Quittung zuerst "angenommen", dann "ausgefuehrt" oder "abgelehnt: <Grund>". */
$befehl = function (string $topic, string $msg, MqttClient $mqtt) use (&$nameZuMac, &$modell, &$uni, &$ctl, &$authStatus, &$letzterBefehl, &$abfrageNext, &$roh, $paths, $log) {
    $p = UniBefehle::parseTopic($topic);
    if ($p === null) {
        return;         // auch alle .../quittung
    }
    [$name, $port, $cmd] = $p;
    $quit = $topic . '/quittung';
    $msg = trim($msg);
    $mac = $nameZuMac[$name] ?? null;
    if ($mac === null) {
        $log->warn("Befehl $cmd fuer $name abgelehnt: unbekanntes Geraet");
        $mqtt->publish($quit, 'abgelehnt: unbekanntes Geraet');
        return;
    }
    $g = null;
    foreach ($modell['geraete'] ?? [] as $x) {
        if ($x['mac'] === $mac) {
            $g = $x;
        }
    }
    $grund = UniBefehle::pruefen($uni['geraete'][$mac] ?? UniConfig::defaultGeraet(), $g, $port, $cmd, $msg);
    if ($grund === null) {
        if ($ctl === null || $authStatus !== 'ok') {
            $grund = 'keine Verbindung zum Gateway';
        } elseif (microtime(true) - $letzterBefehl < 1.0) {
            $grund = 'zu schnell (höchstens ein Befehl je Sekunde)';
        }
    }
    if ($grund !== null) {
        $log->warn("Befehl $cmd Port $port fuer $name abgelehnt: $grund");
        $mqtt->publish($quit, 'abgelehnt: ' . $grund);
        return;
    }
    $letzterBefehl = microtime(true);
    $log->info("Befehl $cmd=$msg fuer Port $port an $name angenommen");
    $mqtt->publish($quit, 'angenommen');
    // frische Rohdaten holen: das Gateway braucht die komplette Liste port_overrides, ein veralteter Stand wuerde Aenderungen anderer ueberschreiben
    $frisch = $ctl->geraete();
    $dev = null;
    foreach ($frisch['ok'] ? $frisch['daten'] : [] as $d) {
        if (UniConfig::cleanMac((string)($d['mac'] ?? '')) === $mac) {
            $dev = $d;
        }
    }
    if ($dev === null) {
        $mqtt->publish($quit, 'abgelehnt: ' . ($frisch['ok'] ? 'Gerät nicht gefunden' : $frisch['text']));
        $log->error("Befehl $cmd Port $port an $name: " . ($frisch['ok'] ? 'Gerät nicht gefunden' : $frisch['text']), 'befehl');
        return;
    }
    if ($cmd === 'aktiv') {
        // Weiterleitung und setting_preference vor dem Abschalten merken (Datenordner, ueberlebt Neustart und Update), damit der Port beim Einschalten dorthin zurueckkehrt
        $merkDatei = $paths->data . '/port_vorher.json';
        $merk = Util::jsonLoad($merkDatei);
        $schluessel = $mac . ':' . $port;
        $r = $ctl->portAktiv($dev, $port, $msg === '1', is_array($merk[$schluessel] ?? null) ? $merk[$schluessel] : []);
        if ($r['ok'] && $r['aenderung']) {
            if ($msg === '1') {
                unset($merk[$schluessel]);
            } else {
                $merk[$schluessel] = $r['vorher'];
            }
            Util::jsonSave($merkDatei, $merk);
        }
    } else {
        $r = $cmd === 'poe' ? $ctl->poeModus($dev, $port, $msg === '1' ? 'auto' : 'off') : $ctl->powerCycle($mac, $port);
    }
    if ($r['ok']) {
        $log->info("Befehl $cmd Port $port an $name ausgefuehrt");
        $mqtt->publish($quit, 'ausgefuehrt');
        $abfrageNext = time() + 2;                  // neuen Zustand zeitnah lesen
    } else {
        $log->error("Befehl $cmd Port $port an $name fehlgeschlagen: " . $r['text'], 'befehl');
        $mqtt->publish($quit, 'abgelehnt: ' . $r['text']);
    }
};

while ($running) {
    try {
        $now = time();
        if ($now >= $logLevelCheck) {      // Loglevel-Aenderung der Pluginverwaltung ohne Neustart uebernehmen
            $logLevelCheck = $now + 30;
            applyLogLevel($log);
        }

        // Konfiguration neu laden, wenn sich die Datei geaendert hat
        clearstatcache(true, $paths->config);
        $mt = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
        if ($mt !== $cfgMtime) {
            $cfgMtime = $mt;
            $alt = $uni;
            $uni = UniConfig::load($paths->config);
            foreach (UniConfig::secrets($uni) as $s) {
                $log->addSecret($s);
            }
            if ($uni['mqtt'] != $alt['mqtt'] && $mqtt !== null) {      // Broker geaendert: neu verbinden
                $mqtt->close();
                $mqtt = null;
                $mqttNext = 0;
                $mqttBackoff = 2;
            }
            $verbNeu = $uni['controller'] != $alt['controller'];
            if ($verbNeu || ($ctl === null && $authStatus !== 'keine_daten') || $authStatus === 'falsch') {   // bei "falsch" genuegt eine Aenderung der Datei (Knopf "erneut versuchen")
                if ($ctl !== null) {
                    $ctl->schliessen();
                }
                $ctl = null;
                if (!UniConfig::verbindungVollstaendig($uni)) {
                    $authStatus = 'keine_daten';
                    $authText = 'Adresse, Benutzer und Passwort des Gateways fehlen (Reiter „Verbindung“)';
                } else {
                    $authStatus = 'anmelden';
                    $authText = 'Anmeldung läuft';
                    $authNext = 0;
                    $authBackoff = 30;
                    $c = $uni['controller'];
                    $ctl = new UniController($c['adresse'], $c['benutzer'], $c['passwort'], $c['seite'], !empty($c['tls_pruefen']));
                }
                $authSeit = $now;
                $abfrageNext = 0;
            }
            if ($uni['geraete'] != $alt['geraete'] || $uni['verkehr'] !== $alt['verkehr'] || $uni['clients'] != $alt['clients']) {
                $baueNamen();
                $gesendet = [];                  // Namen oder Portauswahl geaendert: alles neu senden
            }
            if ($authStatus === 'keine_daten' && UniConfig::verbindungVollstaendig($uni)) {
                $c = $uni['controller'];
                $ctl = new UniController($c['adresse'], $c['benutzer'], $c['passwort'], $c['seite'], !empty($c['tls_pruefen']));
                $authStatus = 'anmelden';
                $authText = 'Anmeldung läuft';
                $authNext = 0;
                $authSeit = $now;
            }
        }

        // Anmeldung am Gateway (bei falschen Zugangsdaten nie von selbst wiederholen, sonst sperrt das Gateway das Konto)
        if ($ctl !== null && in_array($authStatus, ['anmelden', 'fehler'], true) && $now >= $authNext) {
            $r = $ctl->anmelden();
            if ($r['status'] === 'ok') {
                $authStatus = 'ok';
                $authText = '';
                $authSeit = $now;
                $authBackoff = 30;
                $abfrageNext = 0;
                $log->info('Anmeldung am Gateway erfolgreich');
            } elseif ($r['status'] === 'falsch') {
                $authStatus = 'falsch';
                $authText = $r['text'];
                $authSeit = $now;
                $log->error('Anmeldung am Gateway fehlgeschlagen: ' . $r['text'], 'auth');
            } else {
                $authStatus = 'fehler';
                $authText = $r['text'];
                $authSeit = $now;
                $authNext = $now + $authBackoff;
                $log->error('Anmeldung am Gateway: ' . $r['text'] . " (neuer Versuch in {$authBackoff} s)", 'auth');
                $authBackoff = min(900, $authBackoff * 2);
            }
        }

        // MQTT zum LoxBerry-Broker (mit wachsendem Abstand bei Fehlern)
        if (($mqtt === null || !$mqtt->connected()) && $now >= $mqttNext) {
            $bd = brokerDetails($log, $uni);
            if ($bd === null) {
                $mqttErr = 'Keine Broker-Daten';
                $mqttNext = $now + 30;
            } else {
                $mqtt = new MqttClient($bd[0], $bd[1], $bd[2], $bd[3], 'unifi_' . $paths->folder, T . '/status/ok');
                $log->addSecret($bd[3]);
                $mqtt->subscribe(T . '/befehl/#');
                if ($mqtt->connect()) {
                    $log->info("MQTT verbunden ({$bd[0]}:{$bd[1]})");
                    $mqttErr = '';
                    $mqttSince = $now;
                    $mqttBackoff = 2;
                    $gesendet = [];
                    $lastStatusTopics = 0;
                } else {
                    $mqttErr = $mqtt->lastError();
                    $log->error('MQTT: ' . $mqttErr, 'mqtt-connect');
                    $mqttNext = $now + $mqttBackoff;
                    $mqttBackoff = min(60, $mqttBackoff * 2);
                }
            }
        }
        $mqttOk = $mqtt !== null && $mqtt->connected();
        if ($mqttOk && !$mqtt->loop()) {
            $mqttErr = $mqtt->lastError();
            $mqttOk = false;
            $log->warn('MQTT getrennt: ' . $mqttErr, 'mqtt-lost');
        }
        if ($mqttOk) {
            foreach ($mqtt->takeMessages() as [$topic, $payload]) {
                $befehl($topic, $payload, $mqtt);
            }
        }

        // Abfrage am Gateway
        if ($authStatus === 'ok' && $ctl !== null && $now >= $abfrageNext) {
            $abfrageNext = $now + (int)$uni['abfrage_abstand'];
            $abfragen($now);
        }

        // Geaenderte Werte hoechstens einmal pro Sekunde senden
        if ($now !== $lastPublish) {
            $lastPublish = $now;
            $frisch = $abfrageOk && $now - $abfrageZeit <= 3 * (int)$uni['abfrage_abstand'] + 60;
            $ok = ($authStatus === 'ok' && $frisch) ? 1 : 0;
            if ($mqttOk && $modell !== null) {
                $alle = [];
                foreach ($modell['geraete'] as $g) {
                    if (!isset($namen[$g['mac']])) {
                        continue;
                    }
                    $cg = $uni['geraete'][$g['mac']] ?? UniConfig::defaultGeraet();
                    foreach (UniDaten::werte($g, UniConfig::parsePorts((string)$cg['ports']), !empty($uni['verkehr'])) as $k => $v) {
                        $alle[T . '/' . $namen[$g['mac']] . '/' . $k] = $v;
                    }
                }
                foreach (UniDaten::netzWerte($modell) as $k => $v) {
                    $alle[T . '/netz/' . $k] = $v;
                }
                foreach (UniDaten::clientWerte(UniDaten::clientAnzeige($modell, $bekannt), $clientNamen, $uni['clients']) as $k => $v) {
                    $alle[T . '/client/' . $k] = $v;
                }
                if ($internet !== null) {
                    foreach (UniDaten::internetWerte($internet, !empty($uni['verkehr'])) as $k => $v) {
                        $alle[T . '/internet/' . $k] = $v;
                    }
                }
                foreach ($alle as $topic => $v) {
                    if (!isset($gesendet[$topic]) || $gesendet[$topic][0] !== $v || $now - $gesendet[$topic][1] >= REFRESH) {
                        if ($mqtt->publish($topic, $v)) {
                            $gesendet[$topic] = [$v, $now];
                        }
                    }
                }
            }
        }
        if ($mqttOk && $now - $lastStatusTopics >= 10) {     // Lebenszeichen alle 10 s
            $lastStatusTopics = $now;
            $zaehler = ($zaehler + 1) % 1000;
            $mqtt->publish(T . '/status/ok', (string)$ok);
            $mqtt->publish(T . '/status/zaehler', (string)$zaehler);
            $mqtt->publish(T . '/status/ts', (string)time());
            $log->rotateCheck();
        }

        if ($now !== $lastStatus) {      // Status hoechstens einmal pro Sekunde (liegt im RAM, /dev/shm); nie Passwoerter
            $lastStatus = $now;
            $geraete = [];
            foreach ($modell['geraete'] ?? [] as $g) {
                if (isset($namen[$g['mac']])) {
                    $geraete[$namen[$g['mac']]] = ['online' => $g['online'], 'typ' => $g['typ'], 'modell' => $g['modell'], 'clients' => $g['clients'], 'ports' => count($g['ports'])];
                }
            }
            Util::jsonSave($paths->statusFile(), [
                'service' => 'running', 'time' => $now, 'started' => $started,
                'ok' => $ok, 'zaehler' => $zaehler, 'ts' => $lastStatusTopics,
                'mqtt' => $mqttOk ? 1 : 0, 'mqtt_error' => $mqttErr, 'mqtt_seit' => $mqttSince,
                'anmeldung' => ['status' => $authStatus, 'text' => $authText, 'seit' => $authSeit],
                'abfrage' => ['ok' => $abfrageOk, 'text' => $abfrageText, 'zeit' => $abfrageZeit, 'dauer_ms' => $abfrageDauerMs, 'anzahl' => $abfrageZaehler],
                'geraete' => $geraete, 'clients' => $modell['clients'] ?? null, 'version' => AppVersion::CURRENT,
            ]);
        }
    } catch (Throwable $e) {
        $log->error('Fehler in der Hauptschleife: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'loop');
    }
    usleep(100000);
}

if ($mqtt !== null && $mqtt->connected()) {
    $mqtt->publish(T . '/status/ok', '0');
    $mqtt->close();
}
if ($ctl !== null) {
    $ctl->schliessen();
}
$log->info('UniFi-Dienst beendet');
$log->end();
@unlink($paths->pidFile());
