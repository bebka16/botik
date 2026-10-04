<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/notify.php';

date_default_timezone_set($config['timezone']);

if (empty($config['webapp_url'])) {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($host !== '' && $dir !== '' && preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/i', explode(':', $host)[0])) {
        $config['webapp_url'] = 'https://' . $host . $dir . '/';
    }
}

$storage = new Storage(__DIR__ . '/data/data.enc', $config['encryption_key']);
$bot = new Bot($config, $storage);

$input = (string)file_get_contents('php://input');
$update = json_decode($input, true);

$callbackId = is_array($update) && isset($update['callback_query']['id'])
    ? (string)$update['callback_query']['id']
    : '';
$callbackAnswered = false;

if ($callbackId !== '') {
    register_shutdown_function(function () use ($bot, $callbackId, &$callbackAnswered) {
        if ($callbackAnswered) return;
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $bot->log('fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        }
        $bot->answerCallbackIfNeeded($callbackId);
    });
}

if (is_array($update) && $update !== []) {
    try {
        $bot->handleUpdate($update);
    } catch (Throwable $e) {
        $bot->log('update error: ' . $e->getMessage());
    }
}

if ($callbackId !== '') {
    $bot->answerCallbackIfNeeded($callbackId);
    $callbackAnswered = true;
}

http_response_code(200);
echo 'ok';

notify_detach_response();

notify_webhook_tick($config, $storage, 60);
