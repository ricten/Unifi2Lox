<?php
/**
 * Gemeinsamer Start fuer Dienst und Weboberflaeche: Klassen, Zeitzone und Pfade.
 * Ausserhalb von LoxBerry (Entwicklung/Tests) greift UNI_DEV_DIR als Basisverzeichnis.
 * Util, Logger, MqttClient und GatewayInfo kommen aus ../common/php (build.sh kopiert sie nach bin/lib).
 */
date_default_timezone_set('Europe/Berlin');     // PHP auf dem LoxBerry laeuft auf UTC

foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo', 'UniConfig', 'UniController', 'UniDaten', 'UniBefehle', 'AppVersion'] as $cls) {
    // im ZIP liegt alles in bin/lib; im Quellbaum kommen die gemeinsamen Klassen aus ../common/php
    $f = __DIR__ . '/' . $cls . '.php';
    require_once is_file($f) ? $f : __DIR__ . '/../../../../common/php/' . $cls . '.php';
}

final class Paths
{
    public string $folder;
    public string $config;     // unifi.json
    public string $logfile;
    public string $data;       // Datenordner
    public string $run;        // /dev/shm/<folder>
    public string $bin;

    public static function detect(): Paths
    {
        $p = new Paths();
        $dev = getenv('UNI_DEV_DIR');
        if ($dev) {
            $p->folder = getenv('UNI_DEV_FOLDER') ?: 'unifi2lox';
            $p->config = $dev . '/unifi.json';
            $p->logfile = $dev . '/unifi.log';
            $p->data = $dev . '/data';
            $p->run = getenv('UNI_DEV_RUN') ?: $dev . '/run';
            $p->bin = dirname(__DIR__);
        } else {
            require_once 'loxberry_system.php';
            $p->folder = LBPPLUGINDIR;
            $p->config = LBPCONFIGDIR . '/unifi.json';
            $p->logfile = LBPLOGDIR . '/unifi.log';
            $p->data = LBPDATADIR;
            $p->run = '/dev/shm/' . LBPPLUGINDIR;
            $p->bin = LBPBINDIR;
        }
        if (!is_dir($p->run)) {
            @mkdir($p->run, 0775, true);
        }
        if (!is_dir($p->data)) {
            @mkdir($p->data, 0775, true);
        }
        return $p;
    }

    public function statusFile(): string
    {
        return $this->run . '/status.json';
    }

    /** Netzuebersicht fuer die Weboberflaeche (Ports, Clients; im RAM, enthaelt Clientnamen und IPs). */
    public function netzFile(): string
    {
        return $this->run . '/netz.json';
    }

    public function pidFile(): string
    {
        return $this->run . '/unifi.pid';
    }

    public function lockFile(): string
    {
        return $this->run . '/run.lock';
    }
}
