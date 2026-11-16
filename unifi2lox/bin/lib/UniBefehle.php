<?php
/**
 * Befehle aus Loxone an das Gateway: unifi/befehl/<geraet>/port<nr>/<befehl> = Wert, Quittung unter .../quittung (nie retained).
 *
 * Erlaubt sind nur diese drei Befehle, und nur fuer Ports, die im Reiter „Geraete“ ausdruecklich freigegeben sind (ab Werk keiner):
 *   poe      - PoE am Port ein (1) oder aus (0)                          (Freigabe: befehle_ports)
 *   neustart - PoE am Port kurz aus- und wieder einschalten (power-cycle) (Freigabe: befehle_ports)
 *   aktiv    - Port ganz ein (1) oder aus (0), wie in der Oberflaeche des Gateways: forward "disabled", kein Netz, tagged_vlan_mgmt "block_all" (Freigabe: schalt_ports)
 * Nie vorgesehen: VLANs und Profile aendern, Geraete neu starten oder zuruecksetzen, Firmware, Konten.
 * Nie geschaltet werden Uplink-Ports; PoE-Befehle gibt es nur an Ports mit PoE.
 */
final class UniBefehle
{
    /** Befehl => [erlaubte Werte als Regex, Bezeichnung]. */
    public const LISTE = [
        'poe' => ['/^[01]$/', 'PoE am Port ein (1) oder aus (0)'],
        'neustart' => ['/^1$/', 'PoE am Port kurz aus- und wieder einschalten'],
        'aktiv' => ['/^[01]$/', 'Port ganz ein (1) oder aus (0), nicht nur PoE'],
    ];

    /** Weiterleitung eines Ports laut Eintrag in port_overrides, sonst laut port_table ('' = unbekannt). */
    public static function portForward(array $dev, int $port): string
    {
        foreach (is_array($dev['port_overrides'] ?? null) ? $dev['port_overrides'] : [] as $o) {
            if (is_array($o) && (int)($o['port_idx'] ?? 0) === $port && isset($o['forward'])) {
                return (string)$o['forward'];
            }
        }
        foreach (is_array($dev['port_table'] ?? null) ? $dev['port_table'] : [] as $r) {
            if (is_array($r) && (int)($r['port_idx'] ?? 0) === $port) {
                return (string)($r['forward'] ?? '');
            }
        }
        return '';
    }

    /** Welche Freigabeliste (Schluessel der Geraetekonfiguration) gilt fuer den Befehl? */
    public static function freigabeFeld(string $befehl): string
    {
        return $befehl === 'aktiv' ? 'schalt_ports' : 'befehle_ports';
    }

    /** Befehlstopic zerlegen; Quittungen (…/quittung) und Fremdes ergeben null. @return array{0:string,1:int,2:string}|null  [geraet, port, befehl] */
    public static function parseTopic(string $topic): ?array
    {
        return preg_match('#^unifi/befehl/([a-z0-9_-]+)/port([0-9]{1,3})/([a-z0-9_]+)$#', $topic, $m) ? [$m[1], (int)$m[2], $m[3]] : null;
    }

    /**
     * Prueft einen Befehl gegen Freigabe, Wert und Zustand. Liefert null (in Ordnung) oder den Ablehnungsgrund (ohne "abgelehnt: ").
     * @param array $cfgGeraet Einstellungen des Geraets (befehle_ports, schalt_ports)
     * @param array|null $g Geraet aus UniDaten::modell() (null = unbekannt)
     */
    public static function pruefen(array $cfgGeraet, ?array $g, int $port, string $befehl, string $wert): ?string
    {
        if (!isset(self::LISTE[$befehl])) {
            return 'Befehl nicht erlaubt';
        }
        $frei = UniConfig::parsePorts((string)($cfgGeraet[self::freigabeFeld($befehl)] ?? '')) ?? [];
        if (!in_array($port, $frei, true)) {
            return ($befehl === 'aktiv' ? 'Ein- und Ausschalten für Port ' . $port . ' ist' : 'Befehle für Port ' . $port . ' sind') . ' gesperrt (im Reiter „Geräte“ freigeben)';
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
        if ($p['uplink']) {
            return 'Port ' . $port . ' ist ein Uplink und wird nie geschaltet';
        }
        if ($befehl !== 'aktiv' && !$p['poe']) {
            return 'Port ' . $port . ' hat kein PoE';
        }
        if ($befehl === 'neustart' && !$p['poe_an']) {
            return 'am Port ' . $port . ' liegt kein PoE an';
        }
        return null;
    }

    /**
     * Neue Liste port_overrides, mit der ein Port ganz ein- oder ausgeschaltet wird. Rein rechnerisch (kein Netzzugriff).
     *
     * So schaltet die Oberflaeche des Gateways einen Port ab (gemessen an einem Port des Cloud Gateways, den Rico dort abgeschaltet hat): Im Eintrag des Ports
     * stehen danach forward = "disabled", native_networkconf_id = "" (kein Netz), tagged_vlan_mgmt = "block_all" und port_security_enabled = true;
     * setting_preference bleibt "auto". Nur forward allein verwirft das Gateway (es leitet forward aus Netz und tagged_vlan_mgmt ab).
     * Deshalb setzt das Plugin diese vier Felder gemeinsam und laesst alle anderen Felder des Eintrags (ausgeschlossene Netze, PoE-Modus, Name ...) unveraendert.
     * Die vier alten Werte kommen als "vorher" zurueck (null = Feld fehlte) und werden beim Einschalten wiederhergestellt. Ohne Merker (Port wurde anderswo
     * abgeschaltet) wird forward = all, tagged_vlan_mgmt = auto, port_security_enabled = false und das haeufigste Netz der uebrigen Ports gesetzt.
     * Ein Port mit Port-Profil (portconf_id) wird abgelehnt: Dort wuerde das Abschalten das Profil statt der Weiterleitung betreffen.
     *
     * @param array $dev Rohdaten des Geraets aus stat/device
     * @param array $merk gemerkte Werte vor dem Abschalten (forward, native_networkconf_id, tagged_vlan_mgmt, port_security_enabled); leer = keine
     * @return array{ok:bool,text:string,overrides:list<array>,aenderung:bool,vorher:array}  aenderung = false: der Port war schon so; vorher = Werte vor dem Abschalten
     */
    public static function overridesAktiv(array $dev, int $port, bool $an, array $merk): array
    {
        $zeile = null;
        foreach (is_array($dev['port_table'] ?? null) ? $dev['port_table'] : [] as $p) {
            if (is_array($p) && (int)($p['port_idx'] ?? 0) === $port) {
                $zeile = $p;
            }
        }
        $ov = [];
        $pos = null;
        foreach (is_array($dev['port_overrides'] ?? null) ? $dev['port_overrides'] : [] as $o) {
            if (is_array($o) && (int)($o['port_idx'] ?? 0) === $port) {
                $pos = count($ov);
            }
            $ov[] = $o;
        }
        $o = $pos !== null ? $ov[$pos] : ['port_idx' => $port];
        if (!empty($o['portconf_id']) || ($pos === null && is_array($zeile) && !empty($zeile['portconf_id']))) {
            return ['ok' => false, 'text' => 'Der Port nutzt ein Port-Profil: Ein- und Ausschalten wird dafür nicht unterstützt', 'overrides' => [], 'aenderung' => false, 'vorher' => []];
        }
        $jetzt = (string)($o['forward'] ?? (is_array($zeile) ? ($zeile['forward'] ?? '') : ''));
        if ($jetzt === '') {
            $jetzt = 'all';
        }
        if ($an === ($jetzt !== 'disabled')) {
            return ['ok' => true, 'text' => '', 'overrides' => $ov, 'aenderung' => false, 'vorher' => []];
        }
        $felder = ['forward', 'native_networkconf_id', 'tagged_vlan_mgmt', 'port_security_enabled'];
        $vorher = [];
        if (!$an) {
            foreach ($felder as $k) {                   // Ausgangswerte merken: aus dem Eintrag, bei neuem Eintrag aus der Zeile der port_table
                $vorher[$k] = array_key_exists($k, $o) ? $o[$k] : (($pos === null && is_array($zeile) && array_key_exists($k, $zeile)) ? $zeile[$k] : null);
            }
            if ($pos === null && is_array($zeile) && isset($zeile['setting_preference'])) {
                $o['setting_preference'] = $zeile['setting_preference'];
            }
            $o['forward'] = 'disabled';
            $o['native_networkconf_id'] = '';
            $o['tagged_vlan_mgmt'] = 'block_all';
            $o['port_security_enabled'] = true;
        } else {
            $ok = array_key_exists('forward', $merk);
            if ($ok) {                                  // gemerkte Werte pruefen und zurueckschreiben (null = Feld entfernen)
                $zul = ['forward' => [null, 'all', 'native', 'customize'], 'tagged_vlan_mgmt' => [null, 'auto', 'block_all', 'custom']];
                foreach ($felder as $k) {
                    $v = $merk[$k] ?? null;
                    if (isset($zul[$k]) && !in_array($v, $zul[$k], true)) {
                        $ok = false;
                    }
                    if ($k === 'native_networkconf_id' && $v !== null && !is_string($v)) {
                        $ok = false;
                    }
                    if ($k === 'port_security_enabled' && $v !== null && !is_bool($v)) {
                        $ok = false;
                    }
                }
            }
            if ($ok) {
                foreach ($felder as $k) {
                    if (($merk[$k] ?? null) === null) {
                        unset($o[$k]);
                    } else {
                        $o[$k] = $merk[$k];
                    }
                }
            } else {                                    // kein brauchbarer Merker: Standard (alle Netze, Netz der uebrigen Ports)
                $netze = [];
                foreach (is_array($dev['port_table'] ?? null) ? $dev['port_table'] : [] as $p) {
                    $nid = is_array($p) ? (string)($p['native_networkconf_id'] ?? '') : '';
                    if ($nid !== '' && (string)($p['forward'] ?? '') !== 'disabled') {
                        $netze[$nid] = ($netze[$nid] ?? 0) + 1;
                    }
                }
                arsort($netze);
                $o['forward'] = 'all';
                $o['tagged_vlan_mgmt'] = 'auto';
                $o['port_security_enabled'] = false;
                if ($netze) {
                    $o['native_networkconf_id'] = (string)array_key_first($netze);
                }
            }
        }
        if ($pos === null) {
            $ov[] = $o;
        } else {
            $ov[$pos] = $o;
        }
        return ['ok' => true, 'text' => '', 'overrides' => $ov, 'aenderung' => true, 'vorher' => $vorher];
    }
}
