<?php
// Weboberflaeche Unifi2Lox: Verbindung · Geräte · Netzwerk · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $uni.
// Diese Seite enthaelt bewusst kein JavaScript (nur Formulare).

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['uni_token'])) {
    $_SESSION['uni_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['uni_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Schalter im Stil des LoxBerry Design Systems (Checkbox mit lb-toggle); $text darf feste HTML-Auszeichnung enthalten. */
function schalter(string $name, bool $an, string $text = ''): string
{
    return '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label>'
        . ($text !== '' ? ' <span class="uni-schalter-text">' . $text . '</span>' : '');
}

$paths = Paths::detect();
$uni = UniConfig::load($paths->config);
$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$anmStatus = $laeuft ? (string)($status['anmeldung']['status'] ?? '') : '';
$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['verbindung', 'geraete', 'netz', 'clients', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = ($anmStatus === 'ok') ? 'netz' : 'verbindung';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = UniConfig::fromPost($uni, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $uni = $neu;
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    } elseif ($aktion === 'neu_anmelden') {
        // Der Dienst liest die Konfiguration neu, wenn sich die Datei aendert: danach meldet er sich erneut an.
        if (is_file($paths->config) ? @touch($paths->config) : Util::jsonSave($paths->config, $uni)) {
            $meldungen[] = 'Der Dienst meldet sich in wenigen Sekunden neu an.';
        } else {
            $meldungen[] = 'FEHLER: Konfigurationsdatei nicht beschreibbar.';
        }
    }
    $status = Util::jsonLoad($paths->statusFile());
}

$basis = '?tab=';
$tabs = ['verbindung' => 'Verbindung', 'geraete' => 'Geräte', 'netz' => 'Netzwerk', 'clients' => 'Clients', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());
$netz = Util::jsonLoad($paths->netzFile());
$modell = is_array($netz['modell'] ?? null) ? $netz['modell'] : null;
$gefunden = [];
foreach ($modell['geraete'] ?? [] as $g) {
    $gefunden[] = ['mac' => $g['mac'], 'name' => $g['name'], 'typ' => $g['typ']];
}
$namenNachMac = UniConfig::namen($uni, $gefunden);
$bekannteClients = is_array($netz['bekannt'] ?? null) ? $netz['bekannt'] : [];

// 4. Parameter true: LoxBerry Design System (lb-*-Klassen) statt jQuery Mobile
LBWeb::lbheader('Unifi2Lox ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 .uni pre { background:var(--lb-gray-100,#f4f4f4); color:var(--lb-text,#222); padding:8px; overflow:auto; max-height:420px; border-radius:var(--lb-radius-sm,4px); }
 .uni .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .uni .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .uni .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .uni .lb-card { margin:12px 0; padding:4px 16px 12px 16px; }
 .uni .uni-opt { margin:6px 0; display:flex; align-items:flex-start; gap:8px; }
 .uni .uni-opt .lb-toggle { flex:none; }
 .uni .lb-table td .lb-input, .uni .lb-table td .lb-select { width:auto; max-width:100%; }
 .uni .uni-schmal { width:90px !important; } .uni .uni-mittel { width:170px !important; } .uni .uni-breit { width:280px !important; }
 .uni-led { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; border:1px solid rgba(0,0,0,.35); }
 .uni-warn { color:var(--lb-warning,#b9770e); }
 .uni-led-inaktiv { background:var(--lb-warning,#e0a800); }
 .uni-led-ok { background:var(--lb-success,#2ecc40); } .uni-led-fehler { background:var(--lb-danger,#e74c3c); } .uni-led-aus { background:var(--lb-gray-400,#bbb); }
 .uni .uni-kl { font-size:90%; }
</style>
<div class="lb-content uni">
<?php foreach ($meldungen as $m) { echo '<div class="lb-callout' . (str_starts_with($m, 'FEHLER') ? ' lb-callout-warning' : '') . '"><span class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</span></div>'; } ?>

<?php if ($tab === 'verbindung') { ?>
<form method="post" action="<?= $basis ?>verbindung">
<input type="hidden" name="token" value="<?= h($token) ?>">
<div class="lb-card">
<h3>UniFi-Gateway</h3>
<p class="hinweis">Das Plugin meldet sich lokal am Gateway an (Cloud Gateway, Dream Machine oder Controller-Software) und liest Ports, PoE-Leistung und Clients. Dazu ein <b>eigenes lokales Konto</b> anlegen (UniFi OS: Admins &amp; Users, „nur lokaler Zugriff“, ohne Zwei-Faktor-Anmeldung). Zum reinen Lesen genügt für Network die Rolle „View Only“; für Befehle (PoE schalten) braucht das Konto „Full Management“.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Adresse des Gateways</th><td><input class="lb-input uni-breit" type="text" name="controller[adresse]" value="<?= h($uni['controller']['adresse']) ?>" placeholder="IP oder Name, z. B. https://unifi.lan" autocomplete="off"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input uni-mittel" type="text" name="controller[benutzer]" value="<?= h($uni['controller']['benutzer']) ?>" autocomplete="off"></td></tr>
<tr><th>Passwort</th><td><input class="lb-input uni-mittel" type="password" name="controller[passwort]" value="" autocomplete="new-password" placeholder="<?= $uni['controller']['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
<tr><th>Site</th><td><input class="lb-input uni-schmal" type="text" name="controller[seite]" value="<?= h($uni['controller']['seite']) ?>"> <span class="hinweis">meist „default“</span></td></tr>
<tr><th>Zertifikat prüfen</th><td><?= schalter('controller[tls_pruefen]', !empty($uni['controller']['tls_pruefen']), 'Zertifikat des Gateways prüfen (aus, solange es selbstsigniert ist)') ?></td></tr>
<tr><th>Abfrage alle … Sekunden (10–600)</th><td><input class="lb-input uni-schmal" type="number" name="abfrage_abstand" min="10" max="600" value="<?= (int)$uni['abfrage_abstand'] ?>"></td></tr>
</table></div>
<p class="hinweis">Das Passwort wird nie angezeigt; ein leeres Feld behält das gespeicherte Passwort. Es liegt in der Konfigurationsdatei des Plugins (nur für den Systembenutzer lesbar). Das Plugin spricht nur mit dem Gateway im eigenen Netz, nicht mit der Ubiquiti-Cloud.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</div>
</form>

<div class="lb-card">
<h3>Anmeldung</h3>
<?php
    $texte = ['keine_daten' => ['aus', 'Adresse, Benutzer und Passwort fehlen'], 'anmelden' => ['aus', 'Anmeldung läuft'], 'ok' => ['ok', 'angemeldet'],
        'falsch' => ['fehler', 'Benutzer oder Passwort falsch'], 'fehler' => ['fehler', 'Anmeldung fehlgeschlagen']];
    [$led, $tx] = $laeuft ? ($texte[$anmStatus] ?? ['aus', 'unbekannt']) : ['aus', 'der Dienst läuft nicht'];
?>
<p><span class="uni-led uni-led-<?= $led ?>"></span> <b><?= h($tx) ?></b>
<?php if ($laeuft && $anmStatus !== 'ok' && !empty($status['anmeldung']['text'])) { echo ' – ' . h($status['anmeldung']['text']); } ?>
<?php if ($anmStatus === 'ok' && isset($status['abfrage'])) { echo '<br><span class="hinweis">Letzte Abfrage: ' . (!empty($status['abfrage']['zeit']) ? h(date('d.m.Y H:i:s', (int)$status['abfrage']['zeit'])) . ', ' . (int)$status['abfrage']['dauer_ms'] . ' ms' : 'noch keine') . (!empty($status['abfrage']['ok']) ? '' : ' – <span class="fehler">' . h($status['abfrage']['text']) . '</span>') . '</span>'; } ?></p>
<?php if ($laeuft && in_array($anmStatus, ['falsch', 'fehler'], true)) { ?>
<form method="post" action="<?= $basis ?>verbindung"><input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn" type="submit" name="aktion" value="neu_anmelden">Anmeldung erneut versuchen</button></form>
<p class="hinweis">Bei „Benutzer oder Passwort falsch“ versucht der Dienst es nicht von selbst wieder (damit das Gateway das Konto nicht sperrt). Nach Korrektur der Eingaben speichern, dann hier erneut versuchen.</p>
<?php } ?>
</div>

<?php } elseif ($tab === 'geraete') { ?>
<form method="post" action="<?= $basis ?>geraete">
<input type="hidden" name="token" value="<?= h($token) ?>">
<div class="lb-card">
<h3>Geräte</h3>
<?php if ($modell === null) { ?>
<p class="hinweis">Noch keine Geräte gefunden. Erst im Reiter „Verbindung“ anmelden; dann erscheinen die Geräte des Gateways hier.</p>
<?php } else { ?>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Status</th><th>Aktiv</th><th>Gerät</th><th>Name im Topic</th><th>Ports veröffentlichen</th><th>PoE-Befehle für Ports</th><th>Ports ein/aus für Ports</th></tr>
<?php
foreach ($modell['geraete'] as $g) {
    $mac = $g['mac'];
    $c = array_merge(UniConfig::defaultGeraet(), $uni['geraete'][$mac] ?? []);
    $name = $namenNachMac[$mac] ?? null;
    [$led, $tip] = !$g['online'] ? ['fehler', 'nicht erreichbar'] : (empty($c['aktiv']) ? ['aus', 'nicht aktiv'] : ['ok', 'online']);
    $hatPoe = $g['poe_max_w'] > 0 || array_filter($g['ports'], fn($p) => $p['poe']);
    $i = h($mac);
    ?>
<tr>
 <td><span class="uni-led uni-led-<?= $led ?>" title="<?= h($tip) ?>"></span> <span class="hinweis"><?= h($tip) ?></span></td>
 <td><?= schalter('geraete[' . $mac . '][aktiv]', !empty($c['aktiv'])) ?></td>
 <td><b><?= h($g['name']) ?></b><br><span class="hinweis"><?= h($g['modell_name'] ?? $g['modell']) ?> · <?= h(['gateway' => 'Gateway', 'switch' => 'Switch', 'ap' => 'Access Point'][$g['typ']] ?? 'Gerät') ?><?= $g['ports'] ? ' · ' . count($g['ports']) . ' Ports' : '' ?></span></td>
 <td><input class="lb-input uni-mittel" type="text" name="geraete[<?= $i ?>][name]" value="<?= h($c['name']) ?>" placeholder="<?= h($name ?? Util::name($g['name'])) ?>"></td>
 <td><?php if ($g['ports']) { ?><input class="lb-input uni-mittel" type="text" name="geraete[<?= $i ?>][ports]" value="<?= h($c['ports']) ?>" placeholder="alle"><?php } else { echo '<span class="hinweis">-</span>'; } ?></td>
 <td><?php if ($hatPoe) { ?><input class="lb-input uni-mittel" type="text" name="geraete[<?= $i ?>][befehle_ports]" value="<?= h($c['befehle_ports']) ?>" placeholder="gesperrt"><?php } else { echo '<span class="hinweis">-</span>'; } ?></td>
 <td><?php if ($g['ports']) { ?><input class="lb-input uni-mittel" type="text" name="geraete[<?= $i ?>][schalt_ports]" value="<?= h($c['schalt_ports']) ?>" placeholder="gesperrt"><?php } else { echo '<span class="hinweis">-</span>'; } ?></td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis">Der Name steckt im Topic (<code>unifi/&lt;name&gt;/…</code>; nur a–z, 0–9, _ und -), leer = Name aus dem Gateway. <b>Ports veröffentlichen:</b> z. B. <code>1-8, 22</code>, leer = alle Ports (je Port gibt es bis zu 7 Topics: bei vielen Ports lohnt eine Auswahl). <b>PoE-Befehle für Ports:</b> nur die hier genannten PoE-Ports lassen sich aus Loxone mit <code>poe</code> und <code>neustart</code> schalten (z. B. <code>4, 6, 11-13</code>), leer = gesperrt. <b>Ports ein/aus für Ports:</b> nur die hier genannten Ports lassen sich mit <code>aktiv</code> ganz abschalten und wieder einschalten (nicht nur PoE), leer = gesperrt. Uplink-Ports werden nie geschaltet.</p>
<p class="hinweis"><b>Achtung:</b> PoE aus oder Neustart an einem Port schaltet das angeschlossene Gerät (Access Point, Kamera, Telefon) stromlos, ein abgeschalteter Port trennt jedes angeschlossene Gerät vom Netz. Geben Sie keine Ports frei, an denen ein Gerät hängt, ohne das Sie das Netz nicht mehr erreichen (auch nicht den LoxBerry oder den Miniserver).</p>
<div class="uni-opt"><input type="hidden" name="verkehr_gesendet" value="1"><?= schalter('verkehr', !empty($uni['verkehr']), 'Datenraten je Port veröffentlichen (<code>rx_bps</code>, <code>tx_bps</code>; ändern sich ständig und erzeugen viele Meldungen)') ?></div>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
<?php } ?>
</div>
</form>

<?php } elseif ($tab === 'netz') { ?>
<h3>Netzwerk</h3>
<?php if ($modell === null) { ?>
<p class="hinweis"><?= $laeuft ? 'Noch keine Daten vom Gateway (' . h($status['anmeldung']['text'] ?? 'Anmeldung läuft') . ').' : 'Der Dienst läuft nicht.' ?></p>
<?php } else { ?>
<p class="hinweis">Stand: <?= h(date('d.m.Y H:i:s', (int)($netz['zeit'] ?? 0))) ?> · <?= (int)$modell['clients']['gesamt'] ?> Clients (<?= (int)$modell['clients']['kabel'] ?> Kabel, <?= (int)$modell['clients']['wlan'] ?> WLAN) · PoE gesamt <?= h(UniDaten::zahl($modell['poe_gesamt_w'], 1)) ?> W. Die Seite aktualisiert sich nicht von selbst (neu laden).</p>
<?php
foreach ($modell['geraete'] as $g) {
    $typText = ['gateway' => 'Gateway', 'switch' => 'Switch', 'ap' => 'Access Point'][$g['typ']] ?? 'Gerät';
    echo '<div class="lb-card"><h4><span class="uni-led uni-led-' . ($g['online'] ? 'ok' : 'fehler') . '"></span> ' . h($g['name']) . ' <span class="hinweis">' . h($g['modell_name'] ?? $g['modell']) . ' · ' . h($typText) . ($g['online'] ? '' : ' · nicht erreichbar') . '</span></h4>';
    if ($g['poe_max_w'] > 0) {
        $pr = (int)round(100 * $g['poe_gesamt_w'] / $g['poe_max_w']);
        echo '<p>PoE: <b>' . h(UniDaten::zahl($g['poe_gesamt_w'], 1)) . ' W</b> von ' . h(UniDaten::zahl($g['poe_max_w'], 0)) . ' W (' . $pr . ' %)</p>';
    }
    if ($g['typ'] === 'ap' || $g['funk']) {
        $b = [];
        foreach ($g['funk'] as $band => $n) {
            $b[] = $n . ' im ' . str_replace('g', ' GHz', $band === '2g' ? '2,4g' : $band);
        }
        echo '<p>Clients: <b>' . (int)$g['clients'] . '</b>' . ($b ? ' (' . h(implode(', ', $b)) . ')' : '') . ($g['clients_gast'] ? ', davon ' . (int)$g['clients_gast'] . ' Gäste' : '') . '</p>';
        if ($g['wlan_clients']) {
            echo '<div class="lb-table-scroll"><table class="lb-table uni-kl"><tr><th>Client</th><th>IP</th><th>WLAN</th><th>Band</th><th>Signal</th></tr>';
            foreach ($g['wlan_clients'] as $c) {
                echo '<tr><td>' . h($c['name']) . '</td><td>' . h($c['ip']) . '</td><td>' . h($c['essid'] ?? '') . '</td><td>' . h(str_replace('g', ' GHz', ($c['band'] ?? '') === '2g' ? '2,4g' : ($c['band'] ?? ''))) . '</td><td>' . (isset($c['signal']) ? (int)$c['signal'] . ' dBm' : '') . '</td></tr>';
            }
            echo '</table></div>';
        }
    }
    if ($g['ports']) {
        echo '<div class="lb-table-scroll"><table class="lb-table uni-kl"><tr><th>Port</th><th>Link</th><th>PoE</th><th>Angeschlossen</th></tr>';
        foreach ($g['ports'] as $p) {
            $link = ($p['aktiv'] ?? true) === false ? '<span class="uni-led uni-led-inaktiv" title="Port abgeschaltet"></span> <b>inaktiv</b> <span class="hinweis">(abgeschaltet)</span>' : ($p['up'] ? '<span class="uni-led uni-led-ok"></span> ' . ($p['speed'] >= 1000 ? h(UniDaten::zahl($p['speed'] / 1000, 1)) . ' Gbit/s' : (int)$p['speed'] . ' Mbit/s') : '<span class="uni-led uni-led-aus"></span> <span class="hinweis">kein Link</span>');
            $poe = !$p['poe'] ? '<span class="hinweis">-</span>' : ($p['poe_an'] ? '<b>' . h(UniDaten::zahl($p['poe_w'], 1)) . ' W</b> <span class="hinweis">(' . h(UniDaten::zahl($p['poe_ma'], 0)) . ' mA)</span>' : '<span class="hinweis">' . ($p['poe_modus'] === 'off' ? 'aus' : 'keine Last') . '</span>');
            $an = [];
            if ($p['gegenstelle'] !== '') {
                $an[] = '<b>' . h($p['gegenstelle']) . '</b> (UniFi-Gerät)';
            }
            foreach ($p['clients'] as $c) {
                $an[] = h($c['name']);
            }
            echo '<tr><td>' . (int)$p['idx'] . ($p['name'] !== '' && $p['name'] !== 'Port ' . $p['idx'] ? ' <span class="hinweis">' . h($p['name']) . '</span>' : '') . ($p['uplink'] ? ' <span class="hinweis">(Uplink)</span>' : '') . '</td><td>' . $link . '</td><td>' . $poe . '</td><td>' . ($an ? implode(', ', $an) : '<span class="hinweis">-</span>') . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
}
?>
<?php } ?>

<?php } elseif ($tab === 'clients') { ?>
<?php
$alleZeigen = ($_GET['alle'] ?? '') === '1';
$cl = $modell === null ? [] : UniDaten::clientAnzeige($modell, $bekannteClients);
foreach (array_keys($uni['clients']) as $mac) {               // ausgewählte Clients, die das Gateway gar nicht (mehr) kennt, bleiben sichtbar
    if (!isset($cl[$mac])) {
        $cl[$mac] = ['name' => '', 'ip' => '', 'online' => false, 'kabel' => false, 'ort' => '', 'zuletzt' => 0];
    }
}
$cNamen = UniConfig::clientNamen($uni, $cl);
$anzeigeName = function (string $mac, array $c): string {
    return $c['name'] !== '' ? $c['name'] : $mac;
};
$offlineBekannt = 0;
$zeilen = [];
foreach ($cl as $mac => $c) {
    $gewaehlt = isset($uni['clients'][$mac]);
    if (!$c['online'] && !$gewaehlt) {
        $offlineBekannt++;
        if (!$alleZeigen) {
            continue;
        }
    }
    $zeilen[$mac] = $c;
}
uksort($zeilen, function ($a, $b) use ($zeilen, $anzeigeName) {
    $x = $zeilen[$a];
    $y = $zeilen[$b];
    if ($x['online'] !== $y['online']) {
        return $x['online'] ? -1 : 1;
    }
    if (!$x['online'] && $x['zuletzt'] !== $y['zuletzt']) {
        return $y['zuletzt'] <=> $x['zuletzt'];                 // zuletzt gesehene zuerst
    }
    return strcasecmp($anzeigeName($a, $x), $anzeigeName($b, $y));
});
?>
<form method="post" action="<?= $basis ?>clients<?= $alleZeigen ? '&amp;alle=1' : '' ?>">
<input type="hidden" name="token" value="<?= h($token) ?>">
<div class="lb-card">
<h3>Clients</h3>
<?php if ($modell === null) { ?>
<p class="hinweis"><?= $laeuft ? 'Noch keine Daten vom Gateway (' . h($status['anmeldung']['text'] ?? 'Anmeldung läuft') . ').' : 'Der Dienst läuft nicht.' ?></p>
<?php } else { ?>
<p class="hinweis">Alle Clients des Gateways mit IP-Adresse. Bei den hier gewählten Clients meldet das Plugin den <b>Online-Status</b> (1 = verbunden, 0 = nicht verbunden) unter <code>unifi/client/&lt;name&gt;/online</code> an MQTT; für den Miniserver ist das ein virtueller Eingang je Client.
Die Liste zeigt die gerade verbundenen und die gewählten Clients.
<?php if ($alleZeigen) { ?>Es sind auch alle weiteren bekannten Clients aufgeführt (<a href="<?= $basis ?>clients">nur verbundene und gewählte anzeigen</a>).
<?php } elseif ($offlineBekannt > 0) { ?><?= (int)$offlineBekannt ?> weitere, derzeit nicht verbundene Clients sind ausgeblendet (<a href="<?= $basis ?>clients&amp;alle=1">alle bekannten anzeigen</a>).<?php } ?></p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Online-Status per MQTT</th><th>Name im Topic</th><th>Client</th><th>IP-Adresse</th><th>Verbindung</th><th>Status</th></tr>
<?php foreach ($zeilen as $mac => $c) {
    $gewaehlt = isset($uni['clients'][$mac]);
    $macText = implode(':', str_split($mac, 2));
    $vorschlag = $gewaehlt ? ($uni['clients'][$mac]['name'] !== '' ? $uni['clients'][$mac]['name'] : $cNamen[$mac]) : ($c['name'] !== '' ? Util::name($c['name']) : 'client_' . substr($mac, -6));
    if ($c['online']) {
        $statusText = '<span class="uni-led uni-led-ok"></span> <span class="hinweis">online</span>';
    } else {
        $statusText = '<span class="uni-led uni-led-aus"></span> <span class="hinweis">offline' . ($c['zuletzt'] > 0 ? ', zuletzt ' . h(date('d.m.Y H:i', $c['zuletzt'])) : '') . '</span>';
    }
    ?>
<tr>
 <td><input type="hidden" name="clients[<?= h($mac) ?>][da]" value="1"><?= schalter('clients[' . $mac . '][aktiv]', $gewaehlt) ?></td>
 <td><input class="lb-input uni-mittel" type="text" name="clients[<?= h($mac) ?>][name]" value="<?= h($vorschlag) ?>"></td>
 <td><b><?= h($c['name'] !== '' ? $c['name'] : '(ohne Namen)') ?></b><br><span class="hinweis"><?= h($macText) ?></span></td>
 <td><?= $c['ip'] !== '' ? h($c['ip']) : '<span class="hinweis">-</span>' ?></td>
 <td><?= $c['ort'] !== '' ? h($c['ort']) : '<span class="hinweis">' . ($c['online'] ? ($c['kabel'] ? 'Kabel' : 'WLAN') : '-') . '</span>' ?></td>
 <td><?= $statusText ?></td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis"><b>Name im Topic:</b> nur a–z, 0–9, _ und -; der Vorschlag kommt aus dem Namen im Gateway und wird mit dem Speichern festgeschrieben (ändert sich der Name später im Gateway, bleibt das Topic gleich). Doppelte Namen bekommen eine Nummer.
Gespeichert werden nur die gewählten Clients (MAC-Adresse und Name im Topic); Namen und IP-Adressen aller anderen Clients bleiben im Arbeitsspeicher und gehen nie nach MQTT. Ein Client, der nicht verbunden ist, wird mit 0 gemeldet.
Das Gateway entfernt WLAN-Clients erst nach einiger Zeit aus seiner Liste, der Wechsel auf 0 kann deshalb einige Minuten dauern.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
<?php } ?>
</div>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = UniConfig::ownBroker($uni);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('unifi/#', GatewayInfo::general());
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'unifi/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table></div>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $uni['mqtt']; ?>
<div class="uni-opt"><?= schalter('mqtt[eigener_broker]', !empty($mq['eigener_broker']), 'Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Host/IP</th><td><input class="lb-input" type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>"></td>
<th>Port</th><td><input class="lb-input uni-schmal" type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input uni-mittel" type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></td>
<th>Passwort</th><td><input class="lb-input uni-mittel" type="password" name="mqtt[passwort]" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table></div>
<p class="hinweis">Ohne Haken verwendet das Plugin den MQTT-Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $beispiel = $namenNachMac ? array_values($namenNachMac)[0] : 'switch1';
    foreach ($modell['geraete'] ?? [] as $g) {      // Beispielname: erster Switch mit PoE-Ports
        if (isset($namenNachMac[$g['mac']]) && $g['typ'] === 'switch' && ($g['poe_max_w'] > 0 || array_filter($g['ports'], fn($p) => $p['poe']))) {
            $beispiel = $namenNachMac[$g['mac']];
            break;
        }
    }
    ?>
<h3>Einbindung in Loxone</h3>
<p>Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo unifi/#) als virtuelle Eingänge, z. B. <code>unifi_<?= h($beispiel) ?>_port4_poe_w</code>.
Gesendet werden nur Änderungen und alle 5 Minuten alle Werte. Texte (<code>typ</code>, <code>modell</code>) als virtuelle Texteingänge anlegen. Je Port gibt es mehrere Topics: im Reiter „Geräte“ die Ports auswählen, die Sie brauchen.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php
$zeilen = [
    ['unifi/<gerät>/online', '0/1', 'Gerät erreichbar (0 = Gerät ausgefallen oder getrennt)'],
    ['unifi/<gerät>/clients', '-', 'Anzahl Clients am Gerät (Access Point: alle verbundenen WLAN-Clients; Switch: Kabel-Clients)'],
    ['unifi/<gerät>/clients_gast | clients_2g | clients_5g | clients_6g', '-', 'Access Points: davon Gäste, Clients im 2,4-, 5- und 6-GHz-Band'],
    ['unifi/<gerät>/typ | modell', 'Text', 'Art (gateway, switch, ap) und Modell'],
    ['unifi/<gerät>/poe_gesamt_w | poe_max_w', 'W', 'Switch/Gateway mit PoE: aktuelle Gesamtleistung der Ports und PoE-Budget'],
    ['unifi/<gerät>/port<nr>/up', '0/1', 'Link am Port'],
    ['unifi/<gerät>/port<nr>/aktiv', '0/1', 'Port eingeschaltet (0 = abgeschaltet, auch durch den Befehl aktiv)'],
    ['unifi/<gerät>/port<nr>/speed_mbit', 'Mbit/s', 'ausgehandelte Geschwindigkeit (0 ohne Link)'],
    ['unifi/<gerät>/port<nr>/clients', '-', 'Anzahl Clients, die an diesem Port hängen (Kabel)'],
    ['unifi/<gerät>/port<nr>/poe_w | poe_ma', 'W, mA', 'PoE-Leistung und -Strom am Port (nur PoE-Ports; 0 ohne Last)'],
    ['unifi/<gerät>/port<nr>/poe_an', '0/1', 'PoE liefert Strom am Port'],
    ['unifi/<gerät>/port<nr>/rx_bps | tx_bps', 'bit/s', 'Datenrate (nur mit der Option „Datenraten veröffentlichen“)'],
    ['unifi/netz/clients | clients_kabel | clients_wlan', '-', 'Clients im ganzen Netz'],
    ['unifi/netz/poe_gesamt_w', 'W', 'PoE-Leistung aller Geräte zusammen'],
    ['unifi/client/<name>/online', '0/1', 'Online-Status eines im Reiter „Clients“ gewählten Clients (1 = verbunden)'],
    ['unifi/status/ok', '0/1', 'Anmeldung und letzte Abfrage in Ordnung (No Cache)'],
    ['unifi/status/zaehler', '0–999', 'Lebenszeichen, zählt alle 10 s (No Cache)'],
    ['unifi/status/ts', 's', 'Unix-Zeitstempel des letzten Lebenszeichens'],
];
foreach ($zeilen as $w) {
    echo '<tr><td>' . h($w[0]) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
}
?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker unifi/status/ok per Letzter-Wille-Nachricht auf 0 (retained). Die Namen der Clients je Port stehen nur in der Weboberfläche (Reiter „Netzwerk“), nicht in MQTT.</p>

<h3>Befehle aus Loxone</h3>
<p>Gesperrt, bis die Ports je Gerät im Reiter „Geräte“ freigegeben sind (<code>poe</code> und <code>neustart</code> über „PoE-Befehle“, <code>aktiv</code> über „Ports ein/aus“). Virtueller Ausgang: Adresse <code>/dev/udp/&lt;LoxBerry-IP&gt;/<?= (int)$udp ?></code>,
Befehl bei EIN <code>publish unifi/befehl/&lt;gerät&gt;/port&lt;nr&gt;/&lt;befehl&gt; &lt;wert&gt;</code> (ohne HTTP-Header/Body). Die Quittung steht unter <code>…/&lt;befehl&gt;/quittung</code>:
<code>angenommen</code>, dann <code>ausgefuehrt</code> oder <code>abgelehnt: &lt;grund&gt;</code> (nie retained). Höchstens ein Befehl je Sekunde. <code>ausgefuehrt</code> heißt „vom Gateway angenommen“: PoE aus wirkt nach etwa 12 Sekunden, nach PoE ein liefert der Port erst nach bis zu etwa einer Minute wieder Strom (<code>port&lt;nr&gt;/poe_an</code> zeigt es).</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Wert</th><th>Wirkung</th></tr>
<?php
foreach (UniBefehle::LISTE as $k => [, $text]) {
    echo '<tr><td>unifi/befehl/' . h($beispiel) . '/port&lt;nr&gt;/' . h($k) . '</td><td>' . ($k === 'neustart' ? '1' : '1 | 0') . '</td><td>' . h($text) . '</td></tr>';
}
?>
</table></div>
<p class="hinweis"><code>aktiv</code> 0 schaltet den Port ganz ab (so, als würden Sie ihn in der Oberfläche des Gateways deaktivieren; nimmt das Gateway die Änderung nicht an, lautet die Quittung „abgelehnt“), 1 stellt die vorherige Einstellung wieder her; <code>port&lt;nr&gt;/aktiv</code> zeigt den Zustand. Nie vorgesehen: VLANs und Profile ändern, Geräte neu starten oder zurücksetzen, Firmware, Konten. Uplink-Ports werden nie geschaltet, PoE-Befehle gibt es nur an Ports mit PoE.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="selbst">Selbstprüfung</button>
<button class="lb-btn lb-btn-danger" type="submit" name="aktion" value="neustart">Dienst neu starten</button>
</form>
<?php
    if ((string)($_POST['aktion'] ?? '') === 'selbst') {
        $zeile = function (string $name, bool $ok, string $text): void {
            echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
        };
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('PHP', PHP_VERSION_ID >= 70400 && extension_loaded('curl') && extension_loaded('openssl'), 'PHP ' . PHP_VERSION . ', curl ' . (extension_loaded('curl') ? 'vorhanden' : 'FEHLT') . ', openssl ' . (extension_loaded('openssl') ? 'vorhanden' : 'FEHLT'));
        $voll = UniConfig::verbindungVollstaendig($uni);
        $zeile('Zugang eingetragen', $voll, $voll ? 'Adresse, Benutzer und Passwort sind eingetragen' : 'Adresse, Benutzer oder Passwort fehlen');
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        if ($voll) {      // eigene, kurze Sitzung nur zum Lesen
            $c = $uni['controller'];
            $ctl = new UniController($c['adresse'], $c['benutzer'], $c['passwort'], $c['seite'], !empty($c['tls_pruefen']));
            $a = $ctl->anmelden();
            $zeile('Anmeldung am Gateway', $a['status'] === 'ok', $a['status'] === 'ok' ? 'angemeldet (eigene Sitzung für diesen Test)' : $a['text']);
            if ($a['status'] === 'ok') {
                $g = $ctl->geraete();
                $zeile('Geräte lesen', $g['ok'], $g['ok'] ? count($g['daten']) . ' Geräte' : $g['text']);
                $cl = $ctl->clients();
                $zeile('Clients lesen', $cl['ok'], $cl['ok'] ? count($cl['daten']) . ' Clients' : $cl['text']);
            }
            $ctl->schliessen();
        }
        $own = UniConfig::ownBroker($uni);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'unifi_test_' . $paths->folder, 'unifi/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('unifi/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        echo '</table></div>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        // Beim allerersten Start meldet die LoxBerry-Logbibliothek, dass die neue Logdatei noch nicht in ihrer Datenbank steht. Harmlos, nicht anzeigen.
        $zeilen = array_filter($zeilen, fn($z) => !str_contains($z, 'log_db_queryid: Could not find database entry'));
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
