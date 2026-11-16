<?php
/**
 * Zugriff auf das UniFi-Gateway (UniFi OS: Cloud Gateway, Dream Machine; Rueckfall auf die klassische Controller-Software).
 *
 * Anmeldung mit einem lokalen Konto (POST /api/auth/login), danach laeuft alles ueber die Sitzung (Cookie). UniFi OS stellt die
 * Network-Schnittstelle unter /proxy/network bereit; Lesen braucht keinen CSRF-Token, Aenderungen (PUT/POST) schon.
 * Das Gateway hat meist ein selbstsigniertes Zertifikat: die Pruefung ist deshalb ab Werk aus (einstellbar).
 * In Meldungen stehen nie Zugangsdaten, Antworttexte des Gateways werden nicht uebernommen.
 *
 * Synchron (curl) mit kurzen Zeitlimits: eine Abfrage dauert im Normalfall unter einer Sekunde.
 */
final class UniController
{
    private string $adresse;
    private string $benutzer;
    private string $passwort;
    private string $seite;
    private bool $tlsPruefen;
    private string $praefix = '/proxy/network';
    private string $csrf = '';
    private bool $angemeldet = false;
    /** @var resource|\CurlHandle|null */
    private $ch = null;
    /** @var array<string,string> */
    private array $kopf = [];

    public function __construct(string $adresse, string $benutzer, string $passwort, string $seite = 'default', bool $tlsPruefen = false)
    {
        $this->adresse = rtrim($adresse, '/');
        $this->benutzer = $benutzer;
        $this->passwort = $passwort;
        $this->seite = $seite !== '' ? $seite : 'default';
        $this->tlsPruefen = $tlsPruefen;
    }

    public function angemeldet(): bool
    {
        return $this->angemeldet;
    }

    private static function curlFehler(int $no): string
    {
        $map = [6 => 'Name des Gateways lässt sich nicht auflösen (DNS)', 7 => 'Verbindung zum Gateway nicht möglich (Adresse, Port oder Firewall prüfen)',
            28 => 'Zeitüberschreitung beim Gateway', 35 => 'TLS-Verbindung fehlgeschlagen', 60 => 'Zertifikat des Gateways nicht prüfbar (selbstsigniert: Prüfung abschalten)',
            51 => 'Zertifikat des Gateways stimmt nicht', 52 => 'Leere Antwort des Gateways'];
        return $map[$no] ?? ('Netzwerkfehler (' . $no . ')');
    }

    private function neuesHandle(): void
    {
        $this->schliessen();
        $this->ch = curl_init();
        $this->kopf = [];
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '',                                   // Cookie-Verwaltung im Speicher einschalten
            CURLOPT_SSL_VERIFYPEER => $this->tlsPruefen, CURLOPT_SSL_VERIFYHOST => $this->tlsPruefen ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $zeile): int {
                $p = strpos($zeile, ':');
                if ($p !== false) {
                    $this->kopf[strtolower(trim(substr($zeile, 0, $p)))] = trim(substr($zeile, $p + 1));
                }
                return strlen($zeile);
            },
        ]);
    }

    public function schliessen(): void
    {
        if ($this->ch !== null) {
            curl_close($this->ch);
            $this->ch = null;
        }
        $this->angemeldet = false;
        $this->csrf = '';
    }

    /**
     * Eine Anfrage senden. $pfad ist die volle Adresse hinter dem Host.
     * @return array{status:int,body:?array,error:string}
     */
    private function roh(string $methode, string $pfad, ?array $json): array
    {
        if ($this->ch === null) {
            $this->neuesHandle();
        }
        $this->kopf = [];
        $h = ['Accept: application/json'];
        $opt = [CURLOPT_URL => $this->adresse . $pfad, CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HTTPGET => $methode === 'GET'];
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($methode !== 'GET' && $this->csrf !== '') {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($this->ch, $opt);
        $raw = curl_exec($this->ch);
        $no = curl_errno($this->ch);
        $status = (int)curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        foreach (['x-updated-csrf-token', 'x-csrf-token'] as $k) {
            if (!empty($this->kopf[$k])) {
                $this->csrf = $this->kopf[$k];
                break;
            }
        }
        if ($no !== 0) {
            return ['status' => $status, 'body' => null, 'error' => self::curlFehler($no)];
        }
        $b = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return ['status' => $status, 'body' => is_array($b) ? $b : null, 'error' => ''];
    }

    /** Falls das Gateway keinen CSRF-Token im Kopf liefert: er steckt im Anmelde-Cookie (JWT, Feld csrfToken). */
    private function csrfAusCookie(): string
    {
        $liste = curl_getinfo($this->ch, CURLINFO_COOKIELIST);
        foreach (is_array($liste) ? $liste : [] as $zeile) {
            $f = explode("\t", (string)$zeile);
            if (count($f) >= 7 && $f[5] === 'TOKEN') {
                $teile = explode('.', $f[6]);
                if (count($teile) >= 2) {
                    $p = json_decode((string)base64_decode(strtr($teile[1], '-_', '+/')), true);
                    if (is_array($p) && !empty($p['csrfToken'])) {
                        return (string)$p['csrfToken'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * Anmelden. status: ok | falsch (Benutzer/Passwort, auch Zwei-Faktor) | fehler (Netz, Gateway)
     * @return array{status:string,text:string}
     */
    public function anmelden(): array
    {
        $this->neuesHandle();
        $daten = ['username' => $this->benutzer, 'password' => $this->passwort, 'remember' => false];
        $r = $this->roh('POST', '/api/auth/login', $daten);
        if ($r['status'] === 404) {                                  // klassische Controller-Software ohne UniFi OS
            $this->praefix = '';
            $r = $this->roh('POST', '/api/login', ['username' => $this->benutzer, 'password' => $this->passwort]);
        } else {
            $this->praefix = '/proxy/network';
        }
        if ($r['error'] !== '') {
            return ['status' => 'fehler', 'text' => $r['error']];
        }
        $code = is_array($r['body']) ? (string)($r['body']['code'] ?? '') : '';
        if ($r['status'] === 200) {
            if ($this->csrf === '') {
                $this->csrf = $this->csrfAusCookie();
            }
            $this->angemeldet = true;
            return ['status' => 'ok', 'text' => ''];
        }
        if (stripos($code, 'MFA') !== false || stripos((string)json_encode($r['body']), 'mfa') !== false) {
            return ['status' => 'falsch', 'text' => 'Für das Konto ist eine Zwei-Faktor-Anmeldung aktiv: ein eigenes lokales Konto ohne Zwei-Faktor-Anmeldung verwenden'];
        }
        if (in_array($r['status'], [400, 401, 403], true)) {
            return ['status' => 'falsch', 'text' => 'Benutzername oder Passwort sind falsch (lokales Konto mit „nur lokaler Zugriff“ verwenden)'];
        }
        if ($r['status'] === 429) {
            return ['status' => 'fehler', 'text' => 'Zu viele Anmeldeversuche, das Gateway sperrt vorübergehend'];
        }
        return ['status' => 'fehler', 'text' => 'Anmeldung nicht möglich (HTTP ' . $r['status'] . ')'];
    }

    /**
     * Lesen oder Aendern der Network-Schnittstelle. $pfad ab "/api/s/<seite>/…" ohne Praefix (siehe seitenPfad()).
     * @return array{ok:bool,status:int,daten:array,text:string}
     */
    private function api(string $methode, string $pfad, ?array $json = null): array
    {
        if (!$this->angemeldet) {
            return ['ok' => false, 'status' => 0, 'daten' => [], 'text' => 'nicht angemeldet'];
        }
        $r = $this->roh($methode, $this->praefix . $pfad, $json);
        if ($r['error'] !== '') {
            return ['ok' => false, 'status' => $r['status'], 'daten' => [], 'text' => $r['error']];
        }
        if ($r['status'] === 401) {
            $this->angemeldet = false;
            return ['ok' => false, 'status' => 401, 'daten' => [], 'text' => 'Sitzung abgelaufen'];
        }
        if ($r['status'] === 403) {
            return ['ok' => false, 'status' => 403, 'daten' => [], 'text' => 'Keine Berechtigung (Rolle des Kontos prüfen)'];
        }
        $rc = is_array($r['body']) ? (string)($r['body']['meta']['rc'] ?? '') : '';
        if ($r['status'] !== 200 || $rc !== 'ok') {
            $msg = is_array($r['body']) ? (string)($r['body']['meta']['msg'] ?? '') : '';
            if (stripos($msg, 'InvalidTargetPort') !== false) {      // z. B. power-cycle, solange der Port gerade kein PoE liefert (Gemessen: kurz nach "PoE ein")
                return ['ok' => false, 'status' => $r['status'], 'daten' => [], 'text' => 'Der Port ist für diesen Befehl gerade nicht bereit (liefert kein PoE, z. B. kurz nach „PoE ein“)'];
            }
            return ['ok' => false, 'status' => $r['status'], 'daten' => [], 'text' => 'Das Gateway lehnt die Anfrage ab (HTTP ' . $r['status'] . ($msg !== '' ? ', ' . preg_replace('/[^A-Za-z0-9_.. -]/', '', $msg) : '') . ')'];
        }
        $d = $r['body']['data'] ?? [];
        return ['ok' => true, 'status' => 200, 'daten' => is_array($d) ? $d : [], 'text' => ''];
    }

    private function seitenPfad(string $rest): string
    {
        return '/api/s/' . rawurlencode($this->seite) . '/' . ltrim($rest, '/');
    }

    /** Alle Geraete (stat/device). */
    public function geraete(): array
    {
        return $this->api('GET', $this->seitenPfad('stat/device'));
    }

    /** Alle aktiven Clients (stat/sta). */
    public function clients(): array
    {
        return $this->api('GET', $this->seitenPfad('stat/sta'));
    }

    /** Zustand des Netzes (stat/health): je Subsystem (wan, www, lan, wlan ...) ein Eintrag, u. a. Provider und WAN-Daten. */
    public function gesundheit(): array
    {
        return $this->api('GET', $this->seitenPfad('stat/health'));
    }

    /** Alle dem Gateway bekannten Clients, auch die gerade nicht verbundenen (rest/user). */
    public function bekannteClients(): array
    {
        return $this->api('GET', $this->seitenPfad('rest/user'));
    }

    /**
     * PoE-Modus eines Ports setzen ('auto' = an, 'off' = aus). Das Gateway verlangt die komplette Liste port_overrides des Geraets,
     * deshalb wird die bisherige Liste uebernommen und nur der eine Eintrag geaendert.
     * @param array $dev Rohdaten des Geraets aus stat/device
     */
    public function poeModus(array $dev, int $port, string $modus): array
    {
        $id = (string)($dev['_id'] ?? '');
        if ($id === '' || !in_array($modus, ['auto', 'off'], true)) {
            return ['ok' => false, 'status' => 0, 'daten' => [], 'text' => 'Gerät unbekannt'];
        }
        $ov = [];
        $gefunden = false;
        foreach (is_array($dev['port_overrides'] ?? null) ? $dev['port_overrides'] : [] as $o) {
            if (is_array($o) && (int)($o['port_idx'] ?? 0) === $port) {
                $o['poe_mode'] = $modus;
                $gefunden = true;
            }
            $ov[] = $o;
        }
        if (!$gefunden) {
            $neu = ['port_idx' => $port, 'poe_mode' => $modus];
            foreach (is_array($dev['port_table'] ?? null) ? $dev['port_table'] : [] as $p) {
                if ((int)($p['port_idx'] ?? 0) === $port && !empty($p['portconf_id'])) {
                    $neu['portconf_id'] = (string)$p['portconf_id'];
                }
            }
            $ov[] = $neu;
        }
        return $this->api('PUT', $this->seitenPfad('rest/device/' . rawurlencode($id)), ['port_overrides' => $ov]);
    }

    /**
     * Port ganz ein- oder ausschalten (Weiterleitung "disabled"), siehe UniBefehle::overridesAktiv().
     * @param array $dev Rohdaten des Geraets aus stat/device
     * @param array $merk gemerkte Werte vor dem Abschalten (forward, native_networkconf_id, tagged_vlan_mgmt, port_security_enabled)
     * @return array{ok:bool,status:int,daten:array,text:string,vorher:array,aenderung:bool}
     */
    public function portAktiv(array $dev, int $port, bool $an, array $merk): array
    {
        $id = (string)($dev['_id'] ?? '');
        $n = UniBefehle::overridesAktiv($dev, $port, $an, $merk);
        if ($id === '' || !$n['ok']) {
            return ['ok' => false, 'status' => 0, 'daten' => [], 'text' => $id === '' ? 'Gerät unbekannt' : $n['text'], 'vorher' => [], 'aenderung' => false];
        }
        if (!$n['aenderung']) {                              // der Port ist schon so
            return ['ok' => true, 'status' => 200, 'daten' => [], 'text' => '', 'vorher' => [], 'aenderung' => false];
        }
        $r = $this->api('PUT', $this->seitenPfad('rest/device/' . rawurlencode($id)), ['port_overrides' => $n['overrides']]);
        if (!$r['ok']) {
            return $r + ['vorher' => [], 'aenderung' => false];
        }
        // Das Gateway nimmt auch Aenderungen an, die es danach verwirft: neu lesen und pruefen
        $neu = $this->geraete();
        $forward = '';
        foreach ($neu['ok'] ? $neu['daten'] : [] as $d) {
            if ((string)($d['_id'] ?? '') === $id) {
                $forward = UniBefehle::portForward($d, $port);
            }
        }
        if (($forward === 'disabled') === $an) {
            if (is_array($dev['port_overrides'] ?? null)) {   // urspruenglichen Stand wiederherstellen (andere Felder koennten uebernommen worden sein)
                $this->api('PUT', $this->seitenPfad('rest/device/' . rawurlencode($id)), ['port_overrides' => $dev['port_overrides']]);
            }
            return ['ok' => false, 'status' => 200, 'daten' => [], 'text' => 'Das Gateway hat die Änderung nicht übernommen (die Einstellung des Ports blieb unverändert)', 'vorher' => [], 'aenderung' => false];
        }
        return $r + ['vorher' => $n['vorher'], 'aenderung' => true];
    }

    /** PoE eines Ports kurz aus- und wieder einschalten (power-cycle). */
    public function powerCycle(string $mac, int $port): array
    {
        $m = implode(':', str_split($mac, 2));
        return $this->api('POST', $this->seitenPfad('cmd/devmgr'), ['cmd' => 'power-cycle', 'mac' => $m, 'port_idx' => $port]);
    }
}
