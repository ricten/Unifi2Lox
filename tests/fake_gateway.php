<?php
/**
 * Fake-UniFi-Gateway fuer Tests (Router fuer den eingebauten PHP-Server):
 *   UNI_FAKE_DIR=<verzeichnis> php -S 127.0.0.1:<port> tests/fake_gateway.php
 * Dateien im Verzeichnis: state.json (geraete, clients), ctl.json (Steuerung der Tests), gw.log (Protokoll ohne Zugangsdaten), sessions.json.
 * ctl.json: user, pw, csrf ("header" = Token im Antwortkopf, "cookie" = nur im JWT des Anmelde-Cookies), mfa, ablauf (naechste Abfrage 401, einmalig), nur_lesen (Aenderungen 403).
 * Nachgebildet ist UniFi OS: Anmeldung unter /api/auth/login, Network-Schnittstelle unter /proxy/network, Aenderungen nur mit X-CSRF-Token.
 */
$dir = (string)getenv('UNI_FAKE_DIR');
$ctl = json_decode((string)@file_get_contents("$dir/ctl.json"), true) ?: [];
$state = json_decode((string)@file_get_contents("$dir/state.json"), true) ?: ['geraete' => [], 'clients' => []];
$sess = json_decode((string)@file_get_contents("$dir/sessions.json"), true) ?: [];
$log = function (string $z) use ($dir): void {
    file_put_contents("$dir/gw.log", $z . "\n", FILE_APPEND);
};
$antwort = function (int $code, $daten) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($daten);
    exit;
};
$meta = fn(array $daten) => ['meta' => ['rc' => 'ok'], 'data' => $daten];
$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$methode = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string)file_get_contents('php://input'), true);
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

if ($path === '/api/auth/login' && $methode === 'POST') {
    $log('LOGIN');
    if (!empty($ctl['mfa'])) {
        $antwort(403, ['code' => 'MFA_AUTH_REQUIRED']);
    }
    if (!is_array($body) || ($body['username'] ?? '') !== ($ctl['user'] ?? 'lesen') || ($body['password'] ?? '') !== ($ctl['pw'] ?? 'geheimpw')) {
        $antwort(401, ['code' => 'AUTHENTICATION_FAILED_INVALID_CREDENTIALS']);
    }
    $id = 's' . (count($sess) + 1) . bin2hex(random_bytes(4));
    $csrf = 'csrf-' . $id;
    $sess[$id] = $csrf;
    file_put_contents("$dir/sessions.json", json_encode($sess));
    $jwt = $b64('{"alg":"HS256"}') . '.' . $b64(json_encode(['userId' => 'u1', 'csrfToken' => $csrf, 'sid' => $id])) . '.sig';
    header('Set-Cookie: TOKEN=' . $jwt . '; Path=/; HttpOnly');
    if (($ctl['csrf'] ?? 'header') === 'header') {
        header('X-CSRF-Token: ' . $csrf);
    }
    $antwort(200, ['unique_id' => 'u1']);
}

// ab hier nur mit gueltiger Sitzung
$cookie = (string)($_COOKIE['TOKEN'] ?? '');
$teile = explode('.', $cookie);
$pl = json_decode((string)base64_decode(strtr($teile[1] ?? '', '-_', '+/')), true);
$sid = is_array($pl) ? (string)($pl['sid'] ?? '') : '';
if ($sid === '' || !isset($sess[$sid])) {
    $log('401 ohne Sitzung ' . $methode . ' ' . $path);
    $antwort(401, ['code' => 'UNAUTHORIZED']);
}
$pre = '/proxy/network/api/s/default/';
if (strpos($path, $pre) !== 0) {
    $antwort(404, ['code' => 'NOT_FOUND']);
}
$rest = substr($path, strlen($pre));
if (!empty($ctl['ablauf'])) {                       // Sitzung ablaufen lassen (einmalig)
    $ctl['ablauf'] = false;
    file_put_contents("$dir/ctl.json", json_encode($ctl));
    unset($sess[$sid]);
    file_put_contents("$dir/sessions.json", json_encode($sess));
    $log('401 Sitzung abgelaufen');
    $antwort(401, ['code' => 'UNAUTHORIZED']);
}
if ($methode === 'GET') {
    if ($rest === 'stat/device') {
        $log('GET device');
        $antwort(200, $meta($state['geraete']));
    }
    if ($rest === 'stat/sta') {
        $log('GET sta');
        $antwort(200, $meta($state['clients']));
    }
    $antwort(404, ['code' => 'NOT_FOUND']);
}
// Aenderungen: CSRF-Token und Rechte pruefen
$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($tok !== $sess[$sid]) {
    $log('403 CSRF ' . $methode . ' ' . $rest);
    $antwort(403, ['code' => 'CSRF_TOKEN_MISSING']);
}
if (!empty($ctl['nur_lesen'])) {
    $log('403 nur lesen ' . $methode . ' ' . $rest);
    $antwort(403, ['code' => 'FORBIDDEN']);
}
if ($methode === 'PUT' && preg_match('#^rest/device/([A-Za-z0-9_-]+)$#', $rest, $m)) {
    $log('PUT ' . $m[1] . ' ' . json_encode($body));
    foreach ($state['geraete'] as &$g) {
        if ($g['_id'] === $m[1] && isset($body['port_overrides'])) {
            $g['port_overrides'] = $body['port_overrides'];
            foreach ($g['port_overrides'] as $o) {
                foreach ($g['port_table'] as &$p) {
                    if ($p['port_idx'] === $o['port_idx'] && isset($o['poe_mode'])) {
                        $p['poe_mode'] = $o['poe_mode'];
                        if ($o['poe_mode'] === 'off') {
                            $p['poe_good'] = false;
                            $p['poe_enable'] = false;
                            $p['poe_power'] = '0.00';
                        }
                    }
                }
                unset($p);
            }
        }
    }
    unset($g);
    file_put_contents("$dir/state.json", json_encode($state));
    $antwort(200, $meta([]));
}
if ($methode === 'POST' && $rest === 'cmd/devmgr') {
    $log('CMD ' . json_encode($body));
    if (!empty($ctl['port_nicht_bereit'])) {         // wie am echten Gateway, solange der Port kein PoE liefert
        $antwort(400, ['meta' => ['rc' => 'error', 'port' => $body['port_idx'] ?? 0, 'msg' => 'api.err.InvalidTargetPort'], 'data' => []]);
    }
    if (!is_int($body['port_idx'] ?? null)) {         // am echten Gateway ist port_idx als Text ungueltig
        $antwort(400, ['meta' => ['rc' => 'error', 'msg' => 'api.err.InvalidTargetPort'], 'data' => []]);
    }
    $antwort(200, $meta([]));
}
$antwort(404, ['code' => 'NOT_FOUND']);
