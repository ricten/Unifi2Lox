<?php
/**
 * Befehle aus Loxone an das Gateway: unifi/befehl/<geraet>/port<nr>/<befehl> = Wert, Quittung unter .../quittung (nie retained).
 *
 * Erlaubt sind nur diese zwei Befehle, und nur fuer Ports, die im Reiter „Geraete“ ausdruecklich freigegeben sind (ab Werk keiner):
 *   poe      - PoE am Port ein (1) oder aus (0)
 *   neustart - PoE am Port kurz aus- und wieder einschalten (power-cycle)
 * Nie vorgesehen: Ports abschalten, VLANs und Profile aendern, Geraete neu starten oder zuruecksetzen, Firmware, Konten.
 * Nie geschaltet werden Uplink-Ports und Ports ohne PoE.
 */
final class UniBefehle
{
    /** Befehl => [erlaubte Werte als Regex, Bezeichnung]. */
    public const LISTE = [
        'poe' => ['/^[01]$/', 'PoE am Port ein (1) oder aus (0)'],
        'neustart' => ['/^1$/', 'PoE am Port kurz aus- und wieder einschalten'],
    ];

    /** Befehlstopic zerlegen; Quittungen (…/quittung) und Fremdes ergeben null. @return array{0:string,1:int,2:string}|null  [geraet, port, befehl] */
    public static function parseTopic(string $topic): ?array
    {
        return preg_match('#^unifi/befehl/([a-z0-9_-]+)/port([0-9]{1,3})/([a-z0-9_]+)$#', $topic, $m) ? [$m[1], (int)$m[2], $m[3]] : null;
    }

    /**
     * Prueft einen Befehl gegen Freigabe, Wert und Zustand. Liefert null (in Ordnung) oder den Ablehnungsgrund (ohne "abgelehnt: ").
     * @param array $cfgGeraet Einstellungen des Geraets (befehle_ports)
     * @param array|null $g Geraet aus UniDaten::modell() (null = unbekannt)
     */
    public static function pruefen(array $cfgGeraet, ?array $g, int $port, string $befehl, string $wert): ?string
    {
        if (!isset(self::LISTE[$befehl])) {
            return 'Befehl nicht erlaubt';
        }
        $frei = UniConfig::parsePorts((string)($cfgGeraet['befehle_ports'] ?? '')) ?? [];
        if (!in_array($port, $frei, true)) {
            return 'Befehle für Port ' . $port . ' sind gesperrt (im Reiter „Geräte“ freigeben)';
        }
        if (!preg_match(self::LISTE[$befehl][0], trim($wert))) {
            return 'ungültiger Wert';
        }
        if ($g === null) {
            return 'keine Daten vom Gateway (Gerät unbekannt)';
        }
        if (!$g['online']) {
            return 'das Gerät ist nicht erreichbar';
        }
        if (!isset($g['ports'][$port])) {
            return 'das Gerät hat keinen Port ' . $port;
        }
        $p = $g['ports'][$port];
        if (!$p['poe']) {
            return 'Port ' . $port . ' hat kein PoE';
        }
        if ($p['uplink']) {
            return 'Port ' . $port . ' ist ein Uplink und wird nie geschaltet';
        }
        if ($befehl === 'neustart' && !$p['poe_an']) {
            return 'am Port ' . $port . ' liegt kein PoE an';
        }
        return null;
    }
}
