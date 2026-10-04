<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/webapp.php';
require_once __DIR__ . '/notify.php';

date_default_timezone_set($config['timezone']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$storage = new Storage(__DIR__ . '/data/data.enc', $config['encryption_key']);
$data = $storage->load();

$lang = $data['settings']['lang'] ?? 'ru';
$t = lang_strings();
$s = $t[$lang] ?? $t['ru'];

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

function api_json($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_param($name, $default = '') {
    if (array_key_exists($name, $_POST)) return $_POST[$name];
    if (array_key_exists($name, $_GET)) return $_GET[$name];
    return $default;
}

if ($action === 'me' || $action === 'notify_set' || $action === 'theme_set') {
    $initData = (string)api_param('init_data');
    $clientId = trim((string)api_param('client_id'));

    $usersBefore = $data['users'];
    $reason = '';
    $who = tg_webapp_resolve($data, $config['bot_token'] ?? '', $initData, $clientId, $reason);
    if ($who === null) {
        tg_webapp_last_error($reason !== '' ? $reason : 'no_client_id');
        api_json(['ok' => false, 'error' => 'unauthorized']);
    }

    $chatId = $who['id'];
    $mode = $who['mode'];
    $changed = ($data['users'] !== $usersBefore);
    if ($mode === 'tg') {
        tg_webapp_last_error('');
    } else {
        tg_webapp_last_error($reason !== '' ? $reason : 'no_init_data');
    }

    $existed = isset($data['users'][$chatId]) && is_array($data['users'][$chatId]);
    $before = $existed ? $data['users'][$chatId] : null;

    if (!$existed) {
        $data['users'][$chatId] = ['lang' => $lang, 'notify' => false, 'theme' => ''];
    }
    $user = $data['users'][$chatId];
    $user['lang'] = in_array($user['lang'] ?? '', ['ru', 'by'], true) ? $user['lang'] : $lang;
    $user['notify'] = !empty($user['notify']);
    $user['theme'] = in_array($user['theme'] ?? '', ['dark', 'light'], true) ? $user['theme'] : '';

    if ($action === 'notify_set') {
        $want = ((string)api_param('notify', '0') === '1');
        if ($user['notify'] !== $want) {
            $user['notify'] = $want;
            $changed = true;
        }
    }
    if ($action === 'theme_set') {
        $want = ((string)api_param('theme', 'light') === 'dark') ? 'dark' : 'light';
        if ($user['theme'] !== $want) {
            $user['theme'] = $want;
            $changed = true;
        }
    }
    if ($user !== $before) {
        $data['users'][$chatId] = $user;
        $changed = true;
    }

    // A visitor who only opened the page from an unknown browser gets no record of their own.
    if (!$existed && $mode === 'web' && $action === 'me') {
        $data['users'] = $usersBefore;
        $changed = ($data['users'] !== $usersBefore);
    }

    if ($changed && !$storage->save($data)) {
        api_json(['ok' => false, 'error' => $storage->lastError()], 500);
    }

    api_json([
        'ok' => true,
        'notify' => $user['notify'],
        'lang' => $user['lang'],
        'theme' => $user['theme'],
        'master' => !empty($data['settings']['notifications']),
        'mode' => $mode,
    ]);
}

if ($action === 'set_lang') {
    $newLang = (string)api_param('lang', '');
    if (!in_array($newLang, ['ru', 'by'], true)) {
        api_json(['ok' => false, 'error' => 'unknown lang'], 400);
    }

    $data['settings']['lang'] = $newLang;

    $initData = (string)api_param('init_data');
    $clientId = trim((string)api_param('client_id'));
    $who = tg_webapp_resolve($data, $config['bot_token'] ?? '', $initData, $clientId);
    if ($who !== null) {
        $chatId = $who['id'];
        if (!isset($data['users'][$chatId]) || !is_array($data['users'][$chatId])) {
            $data['users'][$chatId] = ['lang' => $newLang, 'notify' => false, 'theme' => ''];
        }
        $data['users'][$chatId]['lang'] = $newLang;
    }

    if (!$storage->save($data)) {
        api_json(['ok' => false, 'error' => $storage->lastError()], 500);
    }

    api_json(['ok' => true, 'lang' => $newLang]);
}

if ($action === 'tick') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

    notify_detach_response();
    notify_run($config, $storage, 30);
    exit;
}

if ($action === 'diag') {
    $bot = new Bot($config, $storage);
    $recipients = notify_recipients($data);

    $notifyOn = 0;
    $webOnly = 0;
    foreach ($data['users'] ?? [] as $id => $user) {
        if (!is_array($user) || empty($user['notify'])) continue;
        $notifyOn++;
        if (!preg_match('/^-?[0-9]+$/', trim((string)$id))) $webOnly++;
    }

    $payload = [
        'ok' => true,
        'lang' => $lang,
        'storage_writable' => $storage->writable(),
        'storage_error' => $storage->lastError(),
        'token_configured' => $bot->tokenConfigured(),
        'notifications_master' => !empty($data['settings']['notifications']),
        'users_notify_on' => $notifyOn,
        'users_total' => count($data['users'] ?? []),
        'recipients_total' => count($recipients),
        'recipients_web_only' => $webOnly,
        'linked_browsers' => array_values(array_map('strval', array_filter(array_keys($data['users'] ?? []), function ($id) use ($data) {
            return preg_match('/^-?[0-9]+$/', (string)$id) && !empty($data['users'][$id]['aliases']);
        }))),
        'webapp_last' => tg_webapp_state_read(),
        'lessons_today' => count(array_filter($data['schedule'] ?? [], fn($x) => ($x['date'] ?? '') === date('Y-m-d'))),
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_init') ? 'yes' : 'no',
        'timezone' => date_default_timezone_get(),
        'now' => date('c'),
    ];

    if ($bot->tokenConfigured()) {
        $me = $bot->api('getMe', [], 1);
        $payload['bot_api_ok'] = !empty($me['ok']);
        if (empty($me['ok'])) $payload['bot_api_error'] = $me['description'] ?? 'unknown';
    }

    api_json($payload);
}

if ($action === 'filter') {
    $target = (string)api_param('target');
    $range = (string)api_param('range', 'today');

    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $weekEnd = date('Y-m-d', strtotime('+7 day'));

    $label = $s[$range] ?? $range;

    $result = ['target' => $target, 'label' => $label, 'items' => []];

    $byRange = function ($x) use ($range, $today, $tomorrow, $weekEnd) {
        $d = $x['date'] ?? '';
        if ($range === 'today') return $d === $today;
        if ($range === 'tomorrow') return $d === $tomorrow;
        if ($range === 'week') return $d >= $today && $d <= $weekEnd;
        return true;
    };

    $dayLabel = function ($date) use ($range, $s) {
        if ($range !== 'week') return '';
        $ts = strtotime((string)$date);
        if (!$ts) return '';
        return $s['weekdays'][(int)date('w', $ts)] . ' ' . date('d.m', $ts);
    };

    if ($target === 'schedule') {
        $items = array_filter($data['schedule'], $byRange);
        usort($items, fn($a, $b) => strcmp(($a['date'] ?? '') . ($a['time_start'] ?? ''), ($b['date'] ?? '') . ($b['time_start'] ?? '')));
        foreach ($items as $it) {
            $subj = trim(($lang === 'by' ? ($it['subject_by'] ?? '') : ($it['subject_ru'] ?? '')));
            $bs = fmt_time($it['break_start'] ?? '');
            $be = fmt_time($it['break_end'] ?? '');
            $parts = [];
            $room = trim((string)($it['room'] ?? ''));
            if ($room !== '') $parts[] = sprintf($s['room'], $room);
            if ($bs !== '' && $be !== '') $parts[] = sprintf($s['break'], $bs, $be);
            $result['items'][] = [
                'day' => $dayLabel($it['date'] ?? ''),
                'time' => fmt_time($it['time_start']) . ' — ' . fmt_time($it['time_end']),
                'text' => $subj . ($parts ? ', ' . implode(', ', $parts) : ''),
            ];
        }
    } elseif ($target === 'works' || $target === 'homework') {
        $list = $target === 'works' ? $data['works'] : $data['homework'];
        $items = array_filter($list, $byRange);
        usort($items, fn($a, $b) => strcmp(($a['date'] ?? '') . ($a['time_start'] ?? ''), ($b['date'] ?? '') . ($b['time_start'] ?? '')));
        foreach ($items as $it) {
            $text = trim(($lang === 'by' ? ($it['text_by'] ?? '') : ($it['text_ru'] ?? '')));
            $result['items'][] = ['day' => $dayLabel($it['date'] ?? ''), 'text' => $text, 'link' => trim((string)($it['link'] ?? ''))];
        }
    }

    api_json($result);
}

api_json(['error' => 'unknown action'], 400);
