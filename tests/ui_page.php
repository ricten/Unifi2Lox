<?php
// Rendert einen Reiter der Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken (nur fuer Tests): php ui_page.php <reiter>
// Umgebung: STUB_INDEX (index.php), STUB_BIN, STUB_CONFIG, STUB_LOG, STUB_SYSCONFIG, STUB_UDP, <PLUGIN>_DEV_DIR.
$_GET['tab'] = $argv[1] ?? 'einstellungen';
$_SERVER['REQUEST_METHOD'] = 'GET';
if (getenv('STUB_ALLE')) {
    $_GET['alle'] = '1';
}
if (getenv('STUB_GET')) {                     // weitere Abfrageparameter, z. B. sort=ip&dir=desc
    parse_str((string)getenv('STUB_GET'), $zusatz);
    $_GET = $zusatz + $_GET;
}
$_SERVER['SERVER_ADDR'] = '192.0.2.25';
include (string)getenv('STUB_INDEX');
