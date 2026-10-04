<?php
// Cron endpoint for the notification scheduler.
// CLI:   php /home/USER/domains/SITE/cron.php
// HTTP:  https://SITE/cron.php?key=SECRET
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/notify.php';

date_default_timezone_set($config['timezone'] ?? 'Europe/Minsk');

$isCli = php_sapi_name() === 'cli';
$key = (string)($_GET['key'] ?? $_POST['key'] ?? '');
$ok = $isCli || hash_equals(notify_cron_key($config), $key);

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

if (!$ok) {
    if (!$isCli) http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

$storage = new Storage(__DIR__ . '/data/data.enc', $config['encryption_key']);
$result = notify_run($config, $storage, 5);
$result['time'] = date('c');

echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
