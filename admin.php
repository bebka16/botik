<?php
session_start();
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/notify.php';

date_default_timezone_set($config['timezone']);

$storage = new Storage(__DIR__ . '/data/data.enc', $config['encryption_key']);

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$data = $storage->load();

$lang = $_SESSION['admin_lang'] ?? ($data['settings']['lang'] ?? 'ru');
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ru', 'by'], true)) {
    $lang = $_GET['lang'];
    $_SESSION['admin_lang'] = $lang;
}
$allAdmin = admin_strings();
$a = $allAdmin[$lang] ?? $allAdmin['ru'];

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) . '">';
}

function sel_field($id, $name, $options, $value, $redirect = '') {
    $value = (string)$value;
    $list = [];
    $currentLabel = $value;
    $found = false;
    foreach ($options as $v => $label) {
        $v = (string)$v;
        $list[] = ['v' => $v, 'l' => (string)$label];
        if ($v === $value) {
            $currentLabel = (string)$label;
            $found = true;
        }
    }
    if (!$found && $value !== '') array_unshift($list, ['v' => $value, 'l' => $value]);
    $json = htmlspecialchars(json_encode($list, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $val = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $nav = $redirect !== '' ? ' data-redirect="' . htmlspecialchars($redirect, ENT_QUOTES) . '"' : '';
    return '<div class="sel"' . $nav . ' data-options="' . $json . '" data-target="' . $id . '">'
        . '<div class="sel-current"><span class="sel-label">' . htmlspecialchars($currentLabel) . '</span>'
        . '<i class="hgi hgi-stroke hgi-rounded hgi-angle-down"></i></div>'
        . '<div class="sel-drop"></div></div>'
        . '<input type="hidden" id="' . $id . '" name="' . $name . '" value="' . $val . '">';
}

function time_field($id, $name, $value) {
    $val = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    return '<input type="text" class="time-input" id="' . $id . '" name="' . $name . '" value="' . $val . '" placeholder="HH:MM" maxlength="5" inputmode="numeric" autocomplete="off">';
}

function date_label($date, $a) {
    $ts = strtotime((string)$date);
    if (!$ts) return htmlspecialchars((string)$date);
    return htmlspecialchars($a['weekdays'][(int)date('w', $ts)] . ' ' . date('d.m.Y', $ts));
}

function msg_time($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('d.m.Y H:i', $ts) : (string)$date;
}

function schedule_fields($a, $dateOpts, $timeOpts, $v, $prefix) {
    $h = '<div class="row3">';
    $h .= '<div><label>' . $a['date'] . '</label>' . sel_field($prefix . '_date', 'date', $dateOpts, $v['date']) . '</div>';
    $h .= '<div><label>' . $a['start'] . '</label>' . time_field($prefix . '_ts', 'time_start', $v['time_start']) . '</div>';
    $h .= '<div><label>' . $a['end'] . '</label>' . time_field($prefix . '_te', 'time_end', $v['time_end']) . '</div>';
    $h .= '</div><div class="row3">';
    $h .= '<div><label>' . $a['break_from'] . '</label>' . time_field($prefix . '_bs', 'break_start', $v['break_start']) . '</div>';
    $h .= '<div><label>' . $a['break_to'] . '</label>' . time_field($prefix . '_be', 'break_end', $v['break_end']) . '</div>';
    $h .= '<div><label>' . $a['room'] . '</label><input type="text" name="room" value="' . htmlspecialchars($v['room'], ENT_QUOTES) . '"></div>';
    $h .= '</div><div class="row">';
    $h .= '<div><label>' . $a['subject_ru'] . '</label><input type="text" name="subject_ru" value="' . htmlspecialchars($v['subject_ru'], ENT_QUOTES) . '"></div>';
    $h .= '<div><label>' . $a['subject_by'] . '</label><input type="text" name="subject_by" value="' . htmlspecialchars($v['subject_by'], ENT_QUOTES) . '"></div>';
    $h .= '</div>';
    return $h;
}

function text_fields($a, $v) {
    return '<label>' . $a['text_ru'] . '</label><textarea name="text_ru">' . htmlspecialchars($v['text_ru']) . '</textarea>'
        . '<label>' . $a['text_by'] . '</label><textarea name="text_by">' . htmlspecialchars($v['text_by']) . '</textarea>'
        . '<label>' . $a['link'] . '</label><input type="text" name="link" value="' . htmlspecialchars($v['link'], ENT_QUOTES) . '">';
}

function event_fields($a, $dateOpts, $timeOpts, $v, $prefix) {
    $h = '<div class="row3">';
    $h .= '<div><label>' . $a['date'] . '</label>' . sel_field($prefix . '_date', 'date', $dateOpts, $v['date']) . '</div>';
    $h .= '<div><label>' . $a['start'] . '</label>' . time_field($prefix . '_ts', 'time_start', $v['time_start']) . '</div>';
    $h .= '<div><label>' . $a['end'] . '</label>' . time_field($prefix . '_te', 'time_end', $v['time_end']) . '</div>';
    $h .= '</div>';
    return $h . text_fields($a, $v);
}

function flash_set($text, $isError = false) {
    $_SESSION['flash'] = ['text' => $text, 'error' => $isError];
}

if (isset($_POST['password'])) {
    if (($_POST['password'] ?? '') === $config['admin_password']) {
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = $a['wrong_password'];
}

if (empty($_SESSION['admin'])) {
    ?>
    <!DOCTYPE html>
    <html lang="<?= $lang ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($a['login_title']) ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
        <style>
            * {outline: none;}
            body {background: #f9fafe; font-family: 'Noto Sans', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0;}
            .box {background: #fff; padding: 28px; border: 1px solid #ececf2; border-radius: 2px; width: 300px;}
            h1 {color: #71599a; font-size: 16px; margin: 0 0 18px; font-weight: 500;}
            input {width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 2px; box-sizing: border-box; font-size: 13px; font-family: inherit; outline: none;}
            input:focus {border-color: #71599a;}
            button {width: 100%; margin-top: 14px; padding: 9px; background: #71599a; color: #fff; border: none; border-radius: 2px; cursor: pointer; font-size: 13px; outline: none;}
            button:hover {background: #5f4a83;}
            .error {color: #c33; font-size: 12px; margin-top: 10px;}
            .langs {display: flex; gap: 12px; margin-top: 16px; font-size: 12px;}
            .langs a {color: #71599a; text-decoration: none;}
        </style>
    </head>
    <body>
        <form class="box" method="post">
            <h1><?= htmlspecialchars($a['login_title']) ?></h1>
            <input type="password" name="password" placeholder="<?= htmlspecialchars($a['password']) ?>" autofocus>
            <button type="submit"><?= htmlspecialchars($a['enter']) ?></button>
            <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="langs">
                <a href="?lang=ru">Русский</a>
                <a href="?lang=by">Беларуская</a>
            </div>
        </form>
    </body>
    </html>
    <?php
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (($_POST['csrf'] ?? '') !== $_SESSION['csrf']) {
        flash_set($a['wrong_form'], true);
        header('Location: admin.php');
        exit;
    }

    $action = $_POST['action'];
    $tab = 'settings';
    if (in_array($action, ['add_schedule', 'edit_schedule', 'delete_schedule', 'duplicate_week'], true)) $tab = 'schedule';
    if (in_array($action, ['add_work', 'edit_work', 'delete_work'], true)) $tab = 'works';
    if (in_array($action, ['add_homework', 'edit_homework', 'delete_homework'], true)) $tab = 'homework';
    if (in_array($action, ['add_event', 'edit_event', 'delete_event'], true)) $tab = 'events';
    if (in_array($action, ['broadcast_preview', 'broadcast_send', 'run_notify'], true)) $tab = 'settings';
    $weekBack = isset($_POST['week']) && in_array($_POST['week'], ['current', 'next', 'all'], true) ? $_POST['week'] : 'current';

    $dateIn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : date('Y-m-d');

    if ($action === 'save_settings') {
        $data['settings']['notifications'] = !empty($_POST['notifications']);
        $storage->save($data);
    }

    if ($action === 'run_notify') {
        @unlink(notify_marker_path('notify_tick'));
        $result = notify_webhook_tick($config, $storage, 5);
        notify_log('admin run: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        flash_set(sprintf($a['notify_run_result'], (int)($result['sent'] ?? 0), (int)($result['failed'] ?? 0), (string)($result['reason'] ?? '-')));
    }

    if ($action === 'reply_user') {
        $replyChat = trim((string)($_POST['chat_id'] ?? ''));
        $replyText = trim((string)($_POST['reply_text'] ?? ''));
        if (function_exists('mb_substr')) $replyText = mb_substr($replyText, 0, 4000);
        if ($replyText === '') {
            flash_set($a['reply_empty'], true);
        } elseif (!preg_match('/^-?[0-9]+$/', $replyChat)) {
            flash_set($a['reply_bad_chat'], true);
        } else {
            $replyBot = new Bot($config, $storage);
            if (!$replyBot->tokenConfigured()) {
                flash_set($a['broadcast_error_token'], true);
            } else {
                $replyRes = $replyBot->sendMessage($replyChat, htmlspecialchars($replyText, ENT_QUOTES, 'UTF-8'));
                if (!empty($replyRes['ok'])) {
                    $replyBot->inboxAdd($data, $replyChat, $replyText, 'out');
                    flash_set($a['reply_sent']);
                } else {
                    flash_set(sprintf($a['reply_failed'], (string)($replyRes['description'] ?? 'error')), true);
                }
            }
        }
    }

    if ($action === 'add_schedule') {
        $item = [
            'date' => $dateIn,
            'time_start' => time_value($_POST['time_start'] ?? ''),
            'time_end' => time_value($_POST['time_end'] ?? ''),
            'subject_ru' => text_value($_POST['subject_ru'] ?? ''),
            'subject_by' => text_value($_POST['subject_by'] ?? ''),
            'room' => text_value($_POST['room'] ?? ''),
            'break_start' => time_value($_POST['break_start'] ?? ''),
            'break_end' => time_value($_POST['break_end'] ?? ''),
        ];
        if ($item['time_start'] === '' || $item['time_end'] === '' || $item['subject_ru'] === '') {
            flash_set($a['need_lesson'], true);
        } else {
            $data['schedule'][] = $item;
            $storage->save($data);
            flash_set($a['saved']);
        }
    }

    if ($action === 'edit_schedule') {
        $i = (int)($_POST['index'] ?? -1);
        if (isset($data['schedule'][$i])) {
            $data['schedule'][$i]['date'] = $dateIn;
            $data['schedule'][$i]['time_start'] = time_value($_POST['time_start'] ?? '');
            $data['schedule'][$i]['time_end'] = time_value($_POST['time_end'] ?? '');
            $data['schedule'][$i]['subject_ru'] = text_value($_POST['subject_ru'] ?? '');
            $data['schedule'][$i]['subject_by'] = text_value($_POST['subject_by'] ?? '');
            $data['schedule'][$i]['room'] = text_value($_POST['room'] ?? '');
            $data['schedule'][$i]['break_start'] = time_value($_POST['break_start'] ?? '');
            $data['schedule'][$i]['break_end'] = time_value($_POST['break_end'] ?? '');
            $storage->save($data);
            flash_set($a['saved']);
        }
    }

    if ($action === 'delete_schedule') {
        $i = (int)($_POST['index'] ?? -1);
        if (isset($data['schedule'][$i])) {
            array_splice($data['schedule'], $i, 1);
            $storage->save($data);
            flash_set($a['deleted']);
        }
    }

    if ($action === 'duplicate_week') {
        $from = week_start_of(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['week_start'] ?? '')) ? $_POST['week_start'] : date('Y-m-d'));
        $fromEnd = date('Y-m-d', strtotime($from . ' +6 days'));
        $exists = [];
        foreach ($data['schedule'] as $x) {
            $exists[($x['date'] ?? '') . '|' . ($x['time_start'] ?? '') . '|' . ($x['subject_ru'] ?? '')] = true;
        }
        $added = [];
        foreach ($data['schedule'] as $x) {
            $d = $x['date'] ?? '';
            if ($d < $from || $d > $fromEnd) continue;
            $copy = $x;
            $copy['date'] = date('Y-m-d', strtotime($d . ' +7 days'));
            $key = $copy['date'] . '|' . ($copy['time_start'] ?? '') . '|' . ($copy['subject_ru'] ?? '');
            if (isset($exists[$key])) continue;
            $exists[$key] = true;
            $added[] = $copy;
        }
        if ($added) {
            $data['schedule'] = array_merge($data['schedule'], $added);
            $storage->save($data);
        }
        flash_set(sprintf($a['copied'], count($added)));
    }

    if ($action === 'add_work' || $action === 'edit_work') {
        $item = [
            'date' => $dateIn,
            'text_ru' => text_value($_POST['text_ru'] ?? ''),
            'text_by' => text_value($_POST['text_by'] ?? ''),
            'link' => trim((string)($_POST['link'] ?? '')),
        ];
        if ($item['text_ru'] === '') {
            flash_set($a['need_text'], true);
        } else {
            if ($action === 'add_work') {
                $data['works'][] = $item;
            } else {
                $i = (int)($_POST['index'] ?? -1);
                if (isset($data['works'][$i])) $data['works'][$i] = $item;
            }
            $storage->save($data);
            flash_set($a['saved']);
        }
    }

    if ($action === 'delete_work') {
        $i = (int)($_POST['index'] ?? -1);
        if (isset($data['works'][$i])) {
            array_splice($data['works'], $i, 1);
            $storage->save($data);
            flash_set($a['deleted']);
        }
    }

    if ($action === 'add_homework' || $action === 'edit_homework') {
        $item = [
            'date' => $dateIn,
            'text_ru' => text_value($_POST['text_ru'] ?? ''),
            'text_by' => text_value($_POST['text_by'] ?? ''),
            'link' => trim((string)($_POST['link'] ?? '')),
        ];
        if ($item['text_ru'] === '') {
            flash_set($a['need_text'], true);
        } else {
            if ($action === 'add_homework') {
                $data['homework'][] = $item;
            } else {
                $i = (int)($_POST['index'] ?? -1);
                if (isset($data['homework'][$i])) $data['homework'][$i] = $item;
            }
            $storage->save($data);
            flash_set($a['saved']);
        }
    }

    if ($action === 'delete_homework') {
        $i = (int)($_POST['index'] ?? -1);
        if (isset($data['homework'][$i])) {
            array_splice($data['homework'], $i, 1);
            $storage->save($data);
            flash_set($a['deleted']);
        }
    }

    if ($action === 'add_event' || $action === 'edit_event') {
        $item = [
            'date' => $dateIn,
            'time_start' => time_value($_POST['time_start'] ?? ''),
            'time_end' => time_value($_POST['time_end'] ?? ''),
            'text_ru' => text_value($_POST['text_ru'] ?? ''),
            'text_by' => text_value($_POST['text_by'] ?? ''),
            'link' => trim((string)($_POST['link'] ?? '')),
        ];
        if ($item['time_start'] === '' || $item['time_end'] === '' || $item['text_ru'] === '') {
            flash_set($a['need_event'], true);
        } else {
            if (!isset($data['events']) || !is_array($data['events'])) $data['events'] = [];
            if ($action === 'add_event') {
                $data['events'][] = $item;
            } else {
                $i = (int)($_POST['index'] ?? -1);
                if (isset($data['events'][$i])) $data['events'][$i] = $item;
            }
            $storage->save($data);
            flash_set($a['saved']);
        }
    }

    if ($action === 'delete_event') {
        $i = (int)($_POST['index'] ?? -1);
        if (isset($data['events'][$i])) {
            array_splice($data['events'], $i, 1);
            $storage->save($data);
            flash_set($a['deleted']);
        }
    }

    if ($action === 'broadcast_preview' || $action === 'broadcast_send') {
        $textRu = text_value($_POST['text_ru'] ?? '');
        $textBy = text_value($_POST['text_by'] ?? '');

        if ($action === 'broadcast_preview') {
            if ($textRu === '' && $textBy === '') {
                flash_set($a['broadcast_need_text'], true);
            } else {
                $_SESSION['broadcast'] = ['text_ru' => $textRu, 'text_by' => $textBy];
                header('Location: admin.php?tab=settings&modal=broadcast');
                exit;
            }
        } else {
            $draft = $_SESSION['broadcast'] ?? null;
            unset($_SESSION['broadcast']);
            if (is_array($draft)) {
                $textRu = (string)($draft['text_ru'] ?? '');
                $textBy = (string)($draft['text_by'] ?? '');
            }

            if ($textRu === '' && $textBy === '') {
                flash_set($a['broadcast_need_text'], true);
            } else {
                $result = broadcast_send($config, $storage, $data, $textRu, $textBy);

                if ($result['error'] === 'token') {
                    flash_set($a['broadcast_error_token'], true);
                } elseif ($result['error'] === 'busy') {
                    flash_set($a['broadcast_error_busy'], true);
                } elseif ($result['total'] === 0) {
                    flash_set($a['broadcast_no_recipients'], true);
                } else {
                    flash_set(
                        sprintf($a['broadcast_done'], $result['sent'], $result['failed']),
                        $result['failed'] > 0
                    );
                }
            }
        }
    }

    $back = 'admin.php?tab=' . $tab;
    if ($tab === 'schedule') $back .= '&week=' . urlencode($weekBack);
    if (strpos($action, 'add_') === 0) $back .= '&modal=' . $tab;
    header('Location: ' . $back);
    exit;
}

$data = $storage->load();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$tab = in_array($_GET['tab'] ?? '', ['settings', 'schedule', 'works', 'homework', 'events'], true) ? $_GET['tab'] : 'settings';
$weekFilter = in_array($_GET['week'] ?? '', ['current', 'next', 'all'], true) ? $_GET['week'] : 'current';

$today = date('Y-m-d');
$curWeekStart = week_start_of($today);
if ($weekFilter === 'current') {
    $weekFrom = $curWeekStart;
    $weekTo = date('Y-m-d', strtotime($curWeekStart . ' +6 days'));
} elseif ($weekFilter === 'next') {
    $weekFrom = date('Y-m-d', strtotime($curWeekStart . ' +7 days'));
    $weekTo = date('Y-m-d', strtotime($curWeekStart . ' +13 days'));
} else {
    $weekFrom = '0000-01-01';
    $weekTo = '9999-12-31';
}

$dateOpts = [];
for ($i = -30; $i <= 120; $i++) {
    $d = date('Y-m-d', strtotime($i . ' days'));
    $dateOpts[$d] = $a['weekdays'][(int)date('w', strtotime($d))] . ' ' . date('d.m.Y', strtotime($d));
}

$weekOpts = [];
for ($i = -3; $i <= 8; $i++) {
    $ws = date('Y-m-d', strtotime($curWeekStart . ' ' . ($i * 7) . ' days'));
    $weekOpts[$ws] = date('d.m.Y', strtotime($ws)) . ' — ' . date('d.m.Y', strtotime($ws . ' +6 days'));
}

$filterOpts = [
    'current' => $a['filter_current'],
    'next' => $a['filter_next'],
    'all' => $a['filter_all'],
];

$scheduleRows = [];
foreach ($data['schedule'] as $i => $row) {
    $d = $row['date'] ?? '';
    if ($d >= $weekFrom && $d <= $weekTo) $scheduleRows[$i] = $row;
}

$workRows = [];
foreach ($data['works'] as $i => $row) $workRows[] = ['i' => $i, 'row' => $row];
usort($workRows, fn($x, $y) => strcmp($y['row']['date'] ?? '', $x['row']['date'] ?? ''));
$workRows = array_slice($workRows, 0, 300);

$hwRows = [];
foreach ($data['homework'] as $i => $row) $hwRows[] = ['i' => $i, 'row' => $row];
usort($hwRows, fn($x, $y) => strcmp($y['row']['date'] ?? '', $x['row']['date'] ?? ''));
$hwRows = array_slice($hwRows, 0, 300);

$eventRows = [];
foreach ($data['events'] ?? [] as $i => $row) $eventRows[] = ['i' => $i, 'row' => $row];
usort($eventRows, fn($x, $y) => strcmp(($y['row']['date'] ?? '') . ($y['row']['time_start'] ?? ''), ($x['row']['date'] ?? '') . ($x['row']['time_start'] ?? '')));
$eventRows = array_slice($eventRows, 0, 300);

$editEntity = in_array($_GET['edit'] ?? '', ['schedule', 'works', 'homework', 'events'], true) ? $_GET['edit'] : '';
$editIndex = (int)($_GET['index'] ?? -1);
$editItem = null;
if ($editEntity !== '' && $editIndex >= 0 && isset($data[$editEntity][$editIndex])) {
    $editItem = $data[$editEntity][$editIndex];
    $editItem['link'] = $editItem['link'] ?? '';
}

$modal = in_array($_GET['modal'] ?? '', ['schedule', 'works', 'homework', 'events', 'broadcast'], true) ? $_GET['modal'] : '';

$userRows = [];
foreach ($data['users'] ?? [] as $uid => $u) {
    if (!is_array($u)) continue;
    $userRows[] = ['id' => $uid, 'lang' => $u['lang'] ?? ($data['settings']['lang'] ?? 'ru'), 'notify' => !empty($u['notify'])];
}
usort($userRows, fn($x, $y) => $y['notify'] <=> $x['notify']);
$usersNotifyOn = count(array_filter($userRows, fn($u) => $u['notify']));

$botChats = bot_chats($data);
$webOnlyUsers = web_only_users($data);
$usersByLang = ['ru' => 0, 'by' => 0];
foreach ($botChats as $bcLang) {
    if (isset($usersByLang[$bcLang])) $usersByLang[$bcLang]++;
}

$inbox = [];
$inboxUnread = 0;
$inboxDirty = false;
foreach ($data['inbox'] ?? [] as $chatId => $items) {
    if (!is_array($items)) continue;
    $chatId = (string)$chatId;
    $clean = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $msgText = trim((string)($it['text'] ?? ''));
        if ($msgText === '') continue;
        if (empty($it['read'])) {
            $inboxUnread++;
            $inboxDirty = true;
        }
        $clean[] = [
            'dir' => (string)($it['dir'] ?? 'in') === 'out' ? 'out' : 'in',
            'text' => $msgText,
            'date' => (string)($it['date'] ?? ''),
            'read' => 1,
        ];
    }
    if ($clean) $inbox[$chatId] = $clean;
}
uasort($inbox, function ($a, $b) {
    $la = end($a);
    $lb = end($b);
    return strcmp((string)($lb['date'] ?? ''), (string)($la['date'] ?? ''));
});
if ($inboxDirty) {
    $data['inbox'] = $inbox;
    $storage->save($data);
}

$broadcastDraft = (is_array($_SESSION['broadcast'] ?? null) && $modal === 'broadcast') ? $_SESSION['broadcast'] : ['text_ru' => '', 'text_by' => ''];

$blankLesson = ['date' => $today, 'time_start' => '08:00', 'time_end' => '09:45', 'subject_ru' => '', 'subject_by' => '', 'room' => '', 'break_start' => '08:45', 'break_end' => '09:00'];
$blankText = ['date' => $today, 'text_ru' => '', 'text_by' => '', 'link' => ''];
$blankEvent = ['date' => $today, 'time_start' => '10:00', 'time_end' => '11:00', 'text_ru' => '', 'text_by' => '', 'link' => ''];

$editLink = function ($entity, $index) use ($tab, $weekFilter) {
    $url = 'admin.php?tab=' . $tab;
    if ($tab === 'schedule') $url .= '&week=' . urlencode($weekFilter);
    return $url . '&edit=' . $entity . '&index=' . $index;
};

$cancelLink = 'admin.php?tab=' . $tab . ($tab === 'schedule' ? '&week=' . urlencode($weekFilter) : '');

$modalLink = function ($entity) use ($tab, $weekFilter) {
    $url = 'admin.php?tab=' . $entity;
    if ($entity === 'schedule') $url .= '&week=' . urlencode($weekFilter);
    return $url . '&modal=' . $entity;
};

function modal_html($id, $title, $flash, $inner, $isOpen, $cancelLink) {
    $style = $isOpen ? 'display:flex;' : '';
    return '<div class="modal" id="modal-' . $id . '" style="' . $style . '" data-modal="' . $id . '">'
        . '<div class="modal-overlay" data-modal-close="1">'
        . '<div class="modal-box" data-modal-box>'
        . '<div class="modal-head"><h3>' . htmlspecialchars($title) . '</h3>'
        . '<a class="modal-close" href="' . htmlspecialchars($cancelLink) . '" data-modal-close="1">&times;</a></div>'
        . ($flash !== null ? '<div class="flash ' . (!empty($flash['error']) ? 'err' : '') . '">' . htmlspecialchars($flash['text']) . '</div>' : '')
        . $inner
        . '</div></div></div>';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($a['panel']) ?></title>
<link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
* {outline: none;}
body {background: #f7f7fa; font-family: 'Noto Sans', sans-serif; margin: 0; padding: 0; color: #2c2c34; font-size: 12px; line-height: 1.5;}
input, textarea, button, select {outline: none !important;}
input:focus, textarea:focus, button:focus, select:focus {outline: none !important; box-shadow: none !important;}
.header {height: 46px; background: #71599a; display: flex; align-items: center; justify-content: space-between; padding: 0 20px;}
.header h1 {color: #fff; font-size: 13px; margin: 0; font-weight: 400; letter-spacing: .2px;}
.header .hright {display: flex; align-items: center;}
.header .hlink {color: #d9cfe8; font-size: 11px; text-decoration: none; margin-left: 14px; transition: color .15s;}
.header .hlink.active, .header .hlink:hover {color: #fff;}
.wrap {padding: 20px; max-width: 1100px; margin: 0 auto;}
.section {background: #fff; border: 1px solid #ececf2; border-radius: 3px; padding: 16px 18px; margin-bottom: 16px;}
.section h2 {font-size: 12px; margin: 0 0 14px; font-weight: 500; color: #71599a; text-transform: uppercase; letter-spacing: .6px;}
.sub {font-size: 11px; color: #888; margin: -6px 0 12px;}
label {display: block; font-size: 10px; color: #888; margin-top: 8px; letter-spacing: .3px; text-transform: uppercase;}
input[type=text], input[type=password] {width: 100%; padding: 6px 9px; border: 1px solid #e2e2ea; border-radius: 2px; font-size: 12px; box-sizing: border-box; font-family: inherit; margin-top: 3px; color: #2c2c34; background: #fafafc; transition: border-color .15s, background .15s;}
input[type=text]:focus, input[type=password]:focus {border-color: #b5a5d0; background: #fff;}
textarea {width: 100%; padding: 6px 9px; border: 1px solid #e2e2ea; border-radius: 2px; font-size: 12px; box-sizing: border-box; font-family: inherit; margin-top: 3px; resize: vertical; min-height: 40px; color: #2c2c34; background: #fafafc; transition: border-color .15s, background .15s;}
textarea:focus {border-color: #b5a5d0; background: #fff;}
.sel {position: relative; margin-top: 3px;}
.sel-current {border: 1px solid #e2e2ea; border-radius: 2px; background: #fafafc; padding: 6px 9px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; color: #2c2c34; transition: border-color .15s, background .15s;}
.sel-current:hover {background: #f3f1f7;}
.sel-current i {color: #aaa; font-size: 14px;}
.sel.open .sel-current {border-color: #b5a5d0; background: #fff;}
.sel-drop {display: none; position: absolute; left: 0; right: 0; top: calc(100% + 2px); background: #fff; border: 1px solid #e2e2ea; border-radius: 2px; max-height: 230px; overflow-y: auto; z-index: 40; box-shadow: 0 4px 14px rgba(0,0,0,0.08);}
.sel.open .sel-drop {display: block;}
.sel-opt {padding: 5px 9px; font-size: 12px; cursor: pointer; color: #2c2c34; white-space: nowrap; transition: background .1s;}
.sel-opt:hover {background: #f3f1f7;}
.sel-opt.sel-cur {background: #71599a; color: #fff;}
button {margin-top: 12px; padding: 6px 14px; background: #71599a; color: #fff; border: none; border-radius: 2px; cursor: pointer; font-size: 12px; font-family: inherit; transition: background .15s;}
button:hover {background: #5f4a83;}
button.ghost {background: #fff; color: #555; border: 1px solid #e2e2ea;}
button.ghost:hover {background: #f3f1f7; color: #2c2c34;}
button.danger {background: #fff; color: #c33; border: 1px solid #e6c3c3; font-size: 11px; padding: 3px 8px;}
button.danger:hover {background: #c33; color: #fff; border-color: #c33;}
.row {display: grid; grid-template-columns: 1fr 1fr; gap: 10px;}
.row3 {display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;}
.toolbar {display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding-bottom: 14px; border-bottom: 1px solid #f0f0f4; margin-bottom: 14px;}
.toolbar > div {min-width: 150px;}
.toolbar .grow {flex: 0 1 240px;}
.toolbar form {display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;}
.toolbar button {margin-top: 3px;}
table {width: 100%; border-collapse: collapse; font-size: 12px;}
th {text-align: left; padding: 8px 10px; border-bottom: 1px solid #ececf2; color: #71599a; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap;}
td {text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0f0f4; vertical-align: top; color: #3a3a44;}
tbody tr {transition: background .1s;}
tbody tr:nth-child(even) {background: #fafafc;}
tbody tr:hover {background: #f3f1f7;}
td.actions {white-space: nowrap;}
td.actions form {display: inline;}
td.actions button {margin: 0; padding: 3px 8px; font-size: 11px;}
.edited {background: #fcfaff !important;}
.editlink {color: #71599a; text-decoration: none; font-size: 11px; border: 1px solid #e2e2ea; border-radius: 2px; padding: 3px 8px; background: #fff; margin-right: 4px; display: inline-block; transition: background .15s, border-color .15s;}
.editlink:hover {background: #f3f1f7; border-color: #d0c6e0;}
.empty {padding: 16px 4px; color: #999; font-size: 12px; text-align: center;}
.flash {background: #f0f7f0; border: 1px solid #d0e6d0; color: #2f6b2f; padding: 8px 12px; font-size: 12px; border-radius: 2px; margin-bottom: 14px;}
.flash.err {background: #fdf2f2; border-color: #f0d0d0; color: #a33;}
.toggle {display: flex; align-items: center; gap: 10px; font-size: 12px;}
.toggle label {margin: 0; font-size: 12px; color: #3a3a44; letter-spacing: 0; text-transform: none;}
.toggle input[type=checkbox] {width: 15px; height: 15px; accent-color: #71599a; cursor: pointer;}
.total {font-size: 11px; color: #aaa; margin-top: 12px; text-align: right;}
.on {color: #2f8f4e; font-size: 12px;}
.off {color: #ccc; font-size: 12px;}
.wraplink {font-size: 11px; color: #71599a; text-decoration: none; border-bottom: 1px dashed #c5b8d8;}
.wraplink:hover {border-bottom-style: solid;}
.cancellink {font-size: 11px; color: #555; text-decoration: none; border: 1px solid #e2e2ea; border-radius: 2px; padding: 4px 9px; background: #fff; margin: 12px 0 0 6px; display: inline-block; transition: background .15s;}
.cancellink:hover {background: #f3f1f7;}
.sechead {display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px;}
.sechead h2 {margin: 0;}
.addbtn {background: #71599a; color: #fff; border: none; border-radius: 2px; padding: 6px 14px; font-size: 12px; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; transition: background .15s;}
.addbtn:hover {background: #5f4a83;}
.modal {display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 60; align-items: center; justify-content: center; padding: 24px 16px;}
.modal-overlay {position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(35, 28, 48, .45);}
.modal-box {left: 30%; top:20%;position: relative; background: #fff; border: none; border-radius: 0; width: 100%; max-width: 760px; max-height: calc(100vh - 48px); overflow-y: auto; padding: 20px 22px 22px; box-shadow: 0 20px 60px rgba(0, 0, 0, .25);}
.modal-head {display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;}
.modal-head h3 {margin: 0; font-size: 12px; color: #71599a; font-weight: 500; text-transform: uppercase; letter-spacing: .5px;}
.modal-close {font-size: 18px; line-height: 1; color: #888; text-decoration: none; border: none; border-radius: 0; padding: 2px 8px; background: transparent; transition: color .15s;}
.modal-close:hover {color: #2c2c34;}
.bpreview {background: #fafafc; border: 1px solid #ececf2; padding: 10px 12px; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; margin-top: 4px; border-radius: 2px; color: #3a3a44;}
.unreadbadge {background: #71599a; color: #fff; border-radius: 10px; padding: 2px 9px; font-size: 10px;}
.chat {border: 1px solid #ececf2; border-radius: 3px; padding: 12px; margin-bottom: 12px; background: #fafafc;}
.chatHead {display: flex; align-items: center; gap: 10px; font-size: 12px; margin-bottom: 8px;}
.chatHead b {color: #3a3a44; font-size: 12px;}
.chatHead .muted {color: #aaa; font-size: 11px;}
.msgs {display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px;}
.msg {border-left: 2px solid #dcd2ec; background: #f5f3f8; padding: 5px 10px; font-size: 12px; line-height: 1.4; border-radius: 0 2px 2px 0;}
.msg.out {border-left-color: #71599a; background: #efeaf7;}
.msg .mtime {color: #aaa; font-size: 10px; margin-right: 8px;}
.msg .mtext {white-space: pre-wrap; word-break: break-word; color: #3a3a44;}
body.modal-open {overflow: hidden;}
.time-input {width: 100%; padding: 6px 9px; border: 1px solid #e2e2ea; border-radius: 2px; font-size: 12px; box-sizing: border-box; font-family: inherit; margin-top: 3px; color: #2c2c34; background: #fafafc; transition: border-color .15s, background .15s;}
.time-input:focus {border-color: #b5a5d0; background: #fff;}
code {background: #f3f1f7; padding: 2px 6px; border-radius: 2px; font-size: 11px; color: #5a4a7a;}
@media (max-width: 720px) { .row, .row3 {grid-template-columns: 1fr;} .modal {padding: 12px 10px;} .modal-box {max-height: calc(100vh - 24px); padding: 16px;} .wrap {padding: 12px;} .section {padding: 12px 14px;} }
</style>
</head>
<body>
<div class="header">
    <h1><?= htmlspecialchars($a['panel']) ?></h1>
    <div class="hright">
        <a class="hlink <?= $lang === 'ru' ? 'active' : '' ?>" href="?tab=<?= $tab ?><?= $tab === 'schedule' ? '&week=' . urlencode($weekFilter) : '' ?>&lang=ru">Русский</a>
        <a class="hlink <?= $lang === 'by' ? 'active' : '' ?>" href="?tab=<?= $tab ?><?= $tab === 'schedule' ? '&week=' . urlencode($weekFilter) : '' ?>&lang=by">Беларуская</a>
        <a class="hlink" href="?logout=1"><?= htmlspecialchars($a['logout']) ?></a>
    </div>
</div>
<div class="wrap">

<?php if ($flash && $modal === ''): ?>
<div class="flash <?= !empty($flash['error']) ? 'err' : '' ?>"><?= htmlspecialchars($flash['text']) ?></div>
<?php endif; ?>

<div class="section" id="sec-settings">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['settings']) ?></h2>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save_settings">
        <?= csrf_field() ?>
        <div class="toggle">
            <input type="checkbox" name="notifications" id="n" <?= !empty($data['settings']['notifications']) ? 'checked' : '' ?>>
            <label for="n"><?= htmlspecialchars($a['notify_master']) ?></label>
        </div>
        <button type="submit"><?= htmlspecialchars($a['save']) ?></button>
    </form>
    <table style="margin-top: 18px;">
        <thead>
        <tr>
            <th><?= htmlspecialchars($a['th_user']) ?></th>
            <th><?= htmlspecialchars($a['th_lang']) ?></th>
            <th><?= htmlspecialchars($a['th_notify']) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($userRows)): ?>
        <tr><td class="empty" colspan="3"><?= htmlspecialchars($a['no_users']) ?></td></tr>
        <?php else: ?>
        <?php foreach ($userRows as $u): ?>
        <tr>
            <td style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($u['id']) ?></td>
            <td><?= $u['lang'] === 'by' ? htmlspecialchars($a['lang_by_name']) : htmlspecialchars($a['lang_ru_name']) ?></td>
            <td><?= $u['notify'] ? '<span class="on">●</span>' : '<span class="off">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="total"><?= sprintf(htmlspecialchars($a['users_total']), count($userRows), $usersNotifyOn) ?></div>

    <?php
    $cronBase = rtrim((string)($config['webhook_url'] ?? ''), '/');
    if ($cronBase !== '') $cronBase = preg_replace('#/webhook\.php$#', '', $cronBase);
    $cronUrl = $cronBase . '/cron.php?key=' . notify_cron_key($config);
    $cronCmd = '*/5 * * * * curl -s ' . escapeshellarg($cronUrl) . ' > /dev/null';
    ?>
    <div style="margin-top: 18px; border-top: 1px solid #f0f0f4; padding-top: 16px;">
        <strong style="font-size: 11px; color: #71599a; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['cron_title']) ?></strong>
        <p style="font-size: 11px; color: #888; margin: 6px 0 10px;"><?= htmlspecialchars($a['cron_hint']) ?></p>
        <p style="font-size: 11px; color: #888; margin: 0 0 12px;">
            <code><?= htmlspecialchars($cronUrl) ?></code>
        </p>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="run_notify">
                <?= csrf_field() ?>
                <button type="submit" style="margin-top: 0;"><?= htmlspecialchars($a['cron_run_now']) ?></button>
            </form>
            <input type="text" readonly value="<?= htmlspecialchars($cronCmd) ?>" style="flex: 1; min-width: 260px; font-family: monospace; font-size: 11px; margin-top: 0; background: #f3f1f7; border-color: #e2e2ea;" onclick="this.select();">
            <button type="button" data-copy="<?= htmlspecialchars($cronCmd) ?>" style="margin-top: 0;"><?= htmlspecialchars($a['cron_copy']) ?></button>
        </div>
    </div>
</div>

<div class="section" id="sec-messages">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['messages']) ?></h2>
        <?php if ($inboxUnread > 0): ?><span class="unreadbadge"><?= sprintf(htmlspecialchars($a['messages_new']), (int)$inboxUnread) ?></span><?php endif; ?>
    </div>
    <div class="sub"><?= htmlspecialchars($a['messages_hint']) ?></div>
    <?php if (empty($inbox)): ?>
    <div class="empty"><?= htmlspecialchars($a['no_messages']) ?></div>
    <?php else: ?>
    <?php foreach ($inbox as $chatId => $items):
        $chatLang = in_array($data['users'][$chatId]['lang'] ?? '', ['ru', 'by'], true) ? $data['users'][$chatId]['lang'] : ($data['settings']['lang'] ?? 'ru');
    ?>
    <div class="chat">
        <div class="chatHead">
            <b><?= htmlspecialchars($chatId) ?></b>
            <span class="muted"><?= $chatLang === 'by' ? htmlspecialchars($a['lang_by_name']) : htmlspecialchars($a['lang_ru_name']) ?></span>
        </div>
        <div class="msgs">
        <?php foreach (array_slice($items, -8) as $it): ?>
            <div class="msg <?= $it['dir'] === 'out' ? 'out' : 'in' ?>">
                <span class="mtime"><?= htmlspecialchars(msg_time($it['date'])) ?></span>
                <span class="mtext"><?= nl2br(htmlspecialchars($it['text'])) ?></span>
            </div>
        <?php endforeach; ?>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reply_user">
            <input type="hidden" name="chat_id" value="<?= htmlspecialchars($chatId) ?>">
            <?= csrf_field() ?>
            <textarea name="reply_text" rows="2" placeholder="<?= htmlspecialchars($a['reply_placeholder']) ?>"></textarea>
            <button type="submit"><?= htmlspecialchars($a['reply']) ?></button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="section" id="sec-broadcast">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['broadcast']) ?></h2>
    </div>
    <div class="sub"><?= htmlspecialchars($a['broadcast_hint']) ?></div>
    <form method="post">
        <input type="hidden" name="action" value="broadcast_preview">
        <?= csrf_field() ?>
        <label><?= htmlspecialchars($a['broadcast_text_ru']) ?></label>
        <textarea name="text_ru" rows="4"><?= htmlspecialchars($broadcastDraft['text_ru']) ?></textarea>
        <label><?= htmlspecialchars($a['broadcast_text_by']) ?></label>
        <textarea name="text_by" rows="3"><?= htmlspecialchars($broadcastDraft['text_by']) ?></textarea>
        <div class="sub" style="margin-top: 6px;"><?= htmlspecialchars($a['broadcast_text_hint']) ?></div>
        <button type="submit"><?= htmlspecialchars($a['broadcast_preview_btn']) ?></button>
    </form>
    <div class="total"><?= sprintf(htmlspecialchars($a['broadcast_recipients']), count($botChats)) ?><?php
        if ($webOnlyUsers): ?> &middot; <?= sprintf(htmlspecialchars($a['broadcast_skipped']), count($webOnlyUsers)) ?><?php endif; ?></div>
</div>

<div class="section" id="sec-schedule">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['schedule']) ?></h2>
        <a class="addbtn" href="<?= htmlspecialchars($modalLink('schedule')) ?>" data-modal-open="schedule">+ <?= htmlspecialchars($a['add_lesson']) ?></a>
    </div>

    <div class="toolbar">
        <div class="grow">
            <label><?= htmlspecialchars($a['week_view']) ?></label>
            <?= sel_field('week_filter', 'week', $filterOpts, $weekFilter, 'admin.php?tab=schedule&week=') ?>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="duplicate_week">
            <?= csrf_field() ?>
            <input type="hidden" name="week" value="<?= htmlspecialchars($weekFilter) ?>">
            <div>
                <label><?= htmlspecialchars($a['week_from']) ?></label>
                <?= sel_field('dup_week_start', 'week_start', $weekOpts, $curWeekStart) ?>
            </div>
            <button type="submit"><?= htmlspecialchars($a['duplicate_week']) ?></button>
        </form>
    </div>

    <?php if ($editEntity === 'schedule' && $editItem !== null): ?>
    <form method="post" style="padding: 14px; border: 1px solid #e8dcf5; border-radius: 3px; margin-bottom: 18px; background: #fcfaff;">
        <input type="hidden" name="action" value="edit_schedule">
        <input type="hidden" name="index" value="<?= $editIndex ?>">
        <input type="hidden" name="week" value="<?= htmlspecialchars($weekFilter) ?>">
        <?= csrf_field() ?>
        <h3 style="font-size: 11px; margin: 0 0 10px; color: #71599a; font-weight: 500; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['edit_lesson']) ?></h3>
        <?= schedule_fields($a, $dateOpts, [], $editItem, 'e') ?>
        <button type="submit"><?= htmlspecialchars($a['save_changes']) ?></button>
        <a class="cancellink" href="<?= htmlspecialchars($cancelLink) ?>"><?= htmlspecialchars($a['cancel']) ?></a>
    </form>
    <?php endif; ?>

    <table>
        <thead>
        <tr>
            <th><?= htmlspecialchars($a['date']) ?></th>
            <th><?= htmlspecialchars($a['th_time']) ?></th>
            <th><?= htmlspecialchars($a['subject_ru']) ?></th>
            <th><?= htmlspecialchars($a['subject_by']) ?></th>
            <th><?= htmlspecialchars($a['th_break']) ?></th>
            <th><?= htmlspecialchars($a['th_room']) ?></th>
            <th><?= htmlspecialchars($a['actions']) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($scheduleRows)): ?>
        <tr><td class="empty" colspan="7"><?= htmlspecialchars($a['no_items']) ?></td></tr>
        <?php else: ?>
        <?php foreach ($scheduleRows as $i => $row):
            $bs = fmt_time($row['break_start'] ?? '');
            $be = fmt_time($row['break_end'] ?? '');
            $break = ($bs && $be) ? $bs . ' — ' . $be : '—';
            $isEdit = ($editEntity === 'schedule' && $editIndex === $i);
        ?>
        <tr class="<?= $isEdit ? 'edited' : '' ?>">
            <td style="white-space: nowrap; font-size: 11px;"><?= date_label($row['date'] ?? '', $a) ?></td>
            <td style="white-space: nowrap; font-size: 11px; color: #71599a; font-weight: 500;"><?= fmt_time($row['time_start'] ?? '') ?> — <?= fmt_time($row['time_end'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['subject_ru'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['subject_by'] ?? '') ?></td>
            <td style="white-space: nowrap; font-size: 11px; color: #888;"><?= htmlspecialchars($break) ?></td>
            <td style="font-size: 11px;"><?= htmlspecialchars(($row['room'] ?? '') !== '' ? $row['room'] : '—') ?></td>
            <td class="actions">
                <a class="editlink" href="<?= htmlspecialchars($editLink('schedule', $i)) ?>"><?= htmlspecialchars($a['edit']) ?></a>
                <form method="post">
                    <input type="hidden" name="action" value="delete_schedule">
                    <input type="hidden" name="index" value="<?= $i ?>">
                    <input type="hidden" name="week" value="<?= htmlspecialchars($weekFilter) ?>">
                    <?= csrf_field() ?>
                    <button class="danger" type="submit"><?= htmlspecialchars($a['delete']) ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="total"><?= sprintf(htmlspecialchars($a['total']), count($scheduleRows)) ?></div>
</div>

<div class="section" id="sec-events">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['events']) ?></h2>
        <a class="addbtn" href="<?= htmlspecialchars($modalLink('events')) ?>" data-modal-open="events">+ <?= htmlspecialchars($a['add_event']) ?></a>
    </div>
    <?php if ($editEntity === 'events' && $editItem !== null): ?>
    <form method="post" style="padding: 14px; border: 1px solid #e8dcf5; border-radius: 3px; margin-bottom: 18px; background: #fcfaff;">
        <input type="hidden" name="action" value="edit_event">
        <input type="hidden" name="index" value="<?= $editIndex ?>">
        <?= csrf_field() ?>
        <h3 style="font-size: 11px; margin: 0 0 10px; color: #71599a; font-weight: 500; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['edit_event']) ?></h3>
        <?php $editValues = array_merge($blankEvent, $editItem); echo event_fields($a, $dateOpts, [], $editValues, 'e'); ?>
        <button type="submit"><?= htmlspecialchars($a['save_changes']) ?></button>
        <a class="cancellink" href="<?= htmlspecialchars($cancelLink) ?>"><?= htmlspecialchars($a['cancel']) ?></a>
    </form>
    <?php endif; ?>

    <table>
        <thead>
        <tr>
            <th><?= htmlspecialchars($a['date']) ?></th>
            <th><?= htmlspecialchars($a['th_time']) ?></th>
            <th><?= htmlspecialchars($a['text_ru']) ?></th>
            <th><?= htmlspecialchars($a['text_by']) ?></th>
            <th><?= htmlspecialchars($a['link']) ?></th>
            <th><?= htmlspecialchars($a['actions']) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($eventRows)): ?>
        <tr><td class="empty" colspan="6"><?= htmlspecialchars($a['no_items']) ?></td></tr>
        <?php else: ?>
        <?php foreach ($eventRows as $pair):
            $row = $pair['row'];
            $i = $pair['i'];
            $isEdit = ($editEntity === 'events' && $editIndex === $i);
        ?>
        <tr class="<?= $isEdit ? 'edited' : '' ?>">
            <td style="white-space: nowrap; font-size: 11px;"><?= date_label($row['date'] ?? '', $a) ?></td>
            <td style="white-space: nowrap; font-size: 11px; color: #71599a; font-weight: 500;"><?= fmt_time($row['time_start'] ?? '') ?> — <?= fmt_time($row['time_end'] ?? '') ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_ru'] ?? '')) ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_by'] ?? '')) ?></td>
            <td><?= ($row['link'] ?? '') !== '' ? '<a class="wraplink" target="_blank" rel="noopener" href="' . htmlspecialchars(safe_link($row['link'])) . '">' . htmlspecialchars($a['open_files']) . '</a>' : '—' ?></td>
            <td class="actions">
                <a class="editlink" href="<?= htmlspecialchars($editLink('events', $i)) ?>"><?= htmlspecialchars($a['edit']) ?></a>
                <form method="post">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="index" value="<?= $i ?>">
                    <?= csrf_field() ?>
                    <button class="danger" type="submit"><?= htmlspecialchars($a['delete']) ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="total"><?= sprintf(htmlspecialchars($a['total']), count($eventRows)) ?></div>
</div>

<div class="section" id="sec-works">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['works']) ?></h2>
        <a class="addbtn" href="<?= htmlspecialchars($modalLink('works')) ?>" data-modal-open="works">+ <?= htmlspecialchars($a['add_work']) ?></a>
    </div>
    <?php if ($editEntity === 'works' && $editItem !== null): ?>
    <form method="post" style="padding: 14px; border: 1px solid #e8dcf5; border-radius: 3px; margin-bottom: 18px; background: #fcfaff;">
        <input type="hidden" name="action" value="edit_work">
        <input type="hidden" name="index" value="<?= $editIndex ?>">
        <?= csrf_field() ?>
        <h3 style="font-size: 11px; margin: 0 0 10px; color: #71599a; font-weight: 500; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['edit_work']) ?></h3>
        <div class="row" style="margin-bottom: 0;">
            <div><label><?= htmlspecialchars($a['date']) ?></label><?php $editValues = array_merge($blankText, $editItem); echo sel_field('e_work_date', 'date', $dateOpts, $editValues['date']); ?></div>
            <div></div>
        </div>
        <?= text_fields($a, $editValues) ?>
        <button type="submit"><?= htmlspecialchars($a['save_changes']) ?></button>
        <a class="cancellink" href="<?= htmlspecialchars($cancelLink) ?>"><?= htmlspecialchars($a['cancel']) ?></a>
    </form>
    <?php endif; ?>

    <table>
        <thead>
        <tr>
            <th><?= htmlspecialchars($a['date']) ?></th>
            <th><?= htmlspecialchars($a['text_ru']) ?></th>
            <th><?= htmlspecialchars($a['text_by']) ?></th>
            <th><?= htmlspecialchars($a['link']) ?></th>
            <th><?= htmlspecialchars($a['actions']) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($workRows)): ?>
        <tr><td class="empty" colspan="5"><?= htmlspecialchars($a['no_items']) ?></td></tr>
        <?php else: ?>
        <?php foreach ($workRows as $pair):
            $row = $pair['row'];
            $i = $pair['i'];
            $isEdit = ($editEntity === 'works' && $editIndex === $i);
        ?>
        <tr class="<?= $isEdit ? 'edited' : '' ?>">
            <td style="white-space: nowrap; font-size: 11px;"><?= date_label($row['date'] ?? '', $a) ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_ru'] ?? '')) ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_by'] ?? '')) ?></td>
            <td><?= ($row['link'] ?? '') !== '' ? '<a class="wraplink" target="_blank" rel="noopener" href="' . htmlspecialchars(safe_link($row['link'])) . '">' . htmlspecialchars($a['open_files']) . '</a>' : '—' ?></td>
            <td class="actions">
                <a class="editlink" href="<?= htmlspecialchars($editLink('works', $i)) ?>"><?= htmlspecialchars($a['edit']) ?></a>
                <form method="post">
                    <input type="hidden" name="action" value="delete_work">
                    <input type="hidden" name="index" value="<?= $i ?>">
                    <?= csrf_field() ?>
                    <button class="danger" type="submit"><?= htmlspecialchars($a['delete']) ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="total"><?= sprintf(htmlspecialchars($a['total']), count($workRows)) ?></div>
</div>

<div class="section" id="sec-homework">
    <div class="sechead">
        <h2><?= htmlspecialchars($a['homework']) ?></h2>
        <a class="addbtn" href="<?= htmlspecialchars($modalLink('homework')) ?>" data-modal-open="homework">+ <?= htmlspecialchars($a['add_homework']) ?></a>
    </div>
    <?php if ($editEntity === 'homework' && $editItem !== null): ?>
    <form method="post" style="padding: 14px; border: 1px solid #e8dcf5; border-radius: 3px; margin-bottom: 18px; background: #fcfaff;">
        <input type="hidden" name="action" value="edit_homework">
        <input type="hidden" name="index" value="<?= $editIndex ?>">
        <?= csrf_field() ?>
        <h3 style="font-size: 11px; margin: 0 0 10px; color: #71599a; font-weight: 500; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['edit_homework']) ?></h3>
        <div class="row" style="margin-bottom: 0;">
            <div><label><?= htmlspecialchars($a['date']) ?></label><?php $editValues = array_merge($blankText, $editItem); echo sel_field('e_hw_date', 'date', $dateOpts, $editValues['date']); ?></div>
            <div></div>
        </div>
        <?= text_fields($a, $editValues) ?>
        <button type="submit"><?= htmlspecialchars($a['save_changes']) ?></button>
        <a class="cancellink" href="<?= htmlspecialchars($cancelLink) ?>"><?= htmlspecialchars($a['cancel']) ?></a>
    </form>
    <?php endif; ?>

    <table>
        <thead>
        <tr>
            <th><?= htmlspecialchars($a['date']) ?></th>
            <th><?= htmlspecialchars($a['text_ru']) ?></th>
            <th><?= htmlspecialchars($a['text_by']) ?></th>
            <th><?= htmlspecialchars($a['link']) ?></th>
            <th><?= htmlspecialchars($a['actions']) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($hwRows)): ?>
        <tr><td class="empty" colspan="5"><?= htmlspecialchars($a['no_items']) ?></td></tr>
        <?php else: ?>
        <?php foreach ($hwRows as $pair):
            $row = $pair['row'];
            $i = $pair['i'];
            $isEdit = ($editEntity === 'homework' && $editIndex === $i);
        ?>
        <tr class="<?= $isEdit ? 'edited' : '' ?>">
            <td style="white-space: nowrap; font-size: 11px;"><?= date_label($row['date'] ?? '', $a) ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_ru'] ?? '')) ?></td>
            <td><?= nl2br(htmlspecialchars($row['text_by'] ?? '')) ?></td>
            <td><?= ($row['link'] ?? '') !== '' ? '<a class="wraplink" target="_blank" rel="noopener" href="' . htmlspecialchars(safe_link($row['link'])) . '">' . htmlspecialchars($a['open_files']) . '</a>' : '—' ?></td>
            <td class="actions">
                <a class="editlink" href="<?= htmlspecialchars($editLink('homework', $i)) ?>"><?= htmlspecialchars($a['edit']) ?></a>
                <form method="post">
                    <input type="hidden" name="action" value="delete_homework">
                    <input type="hidden" name="index" value="<?= $i ?>">
                    <?= csrf_field() ?>
                    <button class="danger" type="submit"><?= htmlspecialchars($a['delete']) ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="total"><?= sprintf(htmlspecialchars($a['total']), count($hwRows)) ?></div>
</div>

</div>

<?php
ob_start();
?>
<form method="post">
    <input type="hidden" name="action" value="add_schedule">
    <input type="hidden" name="week" value="<?= htmlspecialchars($weekFilter) ?>">
    <?= csrf_field() ?>
    <?= schedule_fields($a, $dateOpts, [], $blankLesson, 'n') ?>
    <button type="submit"><?= htmlspecialchars($a['add']) ?></button>
</form>
<?php
$modalSchedule = modal_html('schedule', $a['add_lesson'], $modal === 'schedule' ? $flash : null, ob_get_clean(), $modal === 'schedule', $cancelLink);

ob_start();
?>
<form method="post">
    <input type="hidden" name="action" value="add_event">
    <?= csrf_field() ?>
    <?= event_fields($a, $dateOpts, [], $blankEvent, 'n') ?>
    <button type="submit"><?= htmlspecialchars($a['add']) ?></button>
</form>
<?php
$modalEvents = modal_html('events', $a['add_event'], $modal === 'events' ? $flash : null, ob_get_clean(), $modal === 'events', $cancelLink);

ob_start();
?>
<form method="post">
    <input type="hidden" name="action" value="add_work">
    <?= csrf_field() ?>
    <div class="row" style="margin-bottom: 0;">
        <div><label><?= htmlspecialchars($a['date']) ?></label><?php echo sel_field('n_work_date', 'date', $dateOpts, $today); ?></div>
        <div></div>
    </div>
    <?= text_fields($a, $blankText) ?>
    <button type="submit"><?= htmlspecialchars($a['add']) ?></button>
</form>
<?php
$modalWorks = modal_html('works', $a['add_work'], $modal === 'works' ? $flash : null, ob_get_clean(), $modal === 'works', $cancelLink);

ob_start();
?>
<form method="post">
    <input type="hidden" name="action" value="add_homework">
    <?= csrf_field() ?>
    <div class="row" style="margin-bottom: 0;">
        <div><label><?= htmlspecialchars($a['date']) ?></label><?php echo sel_field('n_hw_date', 'date', $dateOpts, $today); ?></div>
        <div></div>
    </div>
    <?= text_fields($a, $blankText) ?>
    <button type="submit"><?= htmlspecialchars($a['add']) ?></button>
</form>
<?php
$modalHomework = modal_html('homework', $a['add_homework'], $modal === 'homework' ? $flash : null, ob_get_clean(), $modal === 'homework', $cancelLink);

ob_start();
$previewRu = trim((string)$broadcastDraft['text_ru']);
$previewBy = trim((string)$broadcastDraft['text_by']);
$previewReady = ($previewRu !== '' || $previewBy !== '');
?>
<div class="sub"><?= sprintf(htmlspecialchars($a['broadcast_recipients']), count($botChats)) ?><?php
    if ($webOnlyUsers): ?> &middot; <?= sprintf(htmlspecialchars($a['broadcast_skipped']), count($webOnlyUsers)) ?><?php endif; ?></div>
<?php if (!$previewReady): ?>
<div class="flash err"><?= htmlspecialchars($a['broadcast_need_text']) ?></div>
<?php elseif (empty($botChats)): ?>
<div class="flash err"><?= htmlspecialchars($a['broadcast_no_recipients']) ?></div>
<?php else: ?>
<div class="total" style="text-align: left; color: #71599a; font-size: 10px; text-transform: uppercase; letter-spacing: .4px;"><?= htmlspecialchars($a['broadcast_preview_ru']) ?></div>
<div class="bpreview"><?= nl2br(htmlspecialchars($previewRu)) ?></div>
<?php if ($previewBy !== ''): ?>
<div class="total" style="text-align: left; color: #71599a; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; margin-top: 12px;"><?= htmlspecialchars($a['broadcast_preview_by']) ?></div>
<div class="bpreview"><?= nl2br(htmlspecialchars($previewBy)) ?></div>
<?php endif; ?>
<div class="total" style="margin-top: 14px; color: #c0392b; text-align: left;"><?= htmlspecialchars($a['broadcast_confirm']) ?></div>
<form method="post">
    <input type="hidden" name="action" value="broadcast_send">
    <?= csrf_field() ?>
    <button type="submit"><?= htmlspecialchars($a['broadcast_send']) ?></button>
</form>
<?php endif; ?>
<?php
$modalBroadcast = modal_html('broadcast', $a['broadcast_preview_title'], null, ob_get_clean(), $modal === 'broadcast', 'admin.php?tab=settings');
echo $modalSchedule . $modalEvents . $modalWorks . $modalHomework . $modalBroadcast;
?>
<script>
function closeSelects() {
    document.querySelectorAll('.sel.open').forEach(function(s) { s.classList.remove('open'); });
}

function initSelects() {
    document.querySelectorAll('.sel').forEach(function(sel) {
        const input = document.getElementById(sel.dataset.target);
        const cur = sel.querySelector('.sel-current');
        const label = sel.querySelector('.sel-label');
        const drop = sel.querySelector('.sel-drop');
        if (!cur || !label || !drop) return;
        const current = input ? input.value : '';
        let options = [];
        try { options = JSON.parse(sel.dataset.options || '[]'); } catch (e) { options = []; }
        options.forEach(function(o) {
            const opt = document.createElement('div');
            opt.className = 'sel-opt' + (o.v === current ? ' sel-cur' : '');
            opt.textContent = o.l;
            opt.addEventListener('click', function(e) {
                e.stopPropagation();
                if (input) input.value = o.v;
                label.textContent = o.l;
                drop.querySelectorAll('.sel-opt').forEach(function(x) { x.classList.remove('sel-cur'); });
                opt.classList.add('sel-cur');
                closeSelects();
                if (sel.dataset.redirect) {
                    window.location.href = sel.dataset.redirect + encodeURIComponent(o.v);
                }
            });
            drop.appendChild(opt);
        });
        cur.addEventListener('click', function(e) {
            e.stopPropagation();
            const wasOpen = sel.classList.contains('open');
            closeSelects();
            if (wasOpen) return;
            sel.classList.add('open');
            const active = drop.querySelector('.sel-cur');
            if (active) drop.scrollTop = active.offsetTop - (drop.clientHeight - active.clientHeight) / 2;
        });
    });
}

document.addEventListener('click', closeSelects);
initSelects();

function openModal(id) {
    const m = document.getElementById('modal-' + id);
    if (!m) return;
    m.style.display = 'flex';
    document.body.classList.add('modal-open');
    const f = m.querySelector('input, select, textarea');
    if (f) f.focus();
}

function closeModal(id) {
    const m = document.getElementById('modal-' + id);
    if (!m) return;
    m.style.display = 'none';
    document.body.classList.remove('modal-open');
}

document.querySelectorAll('[data-modal-open]').forEach(function(b) {
    b.addEventListener('click', function(e) {
        e.preventDefault();
        openModal(b.dataset.modalOpen);
    });
});

document.addEventListener('click', function(e) {
    const closer = e.target.closest('[data-modal-close]');
    if (!closer || closer.tagName === 'A') return;
    if (e.target !== closer) return;
    const wrap = closer.closest('.modal');
    if (wrap) closeModal(wrap.dataset.modal);
});

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    closeSelects();
    document.querySelectorAll('.modal').forEach(function(m) { m.style.display = 'none'; });
    document.body.classList.remove('modal-open');
});

document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function() {
        form.querySelectorAll('button[type=submit]').forEach(function(b) { b.disabled = true; });
    });
});

document.querySelectorAll('[data-copy]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const text = btn.dataset.copy || '';
        const done = function() {
            const old = btn.textContent;
            btn.textContent = '✓';
            setTimeout(function() { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(done, function() {});
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (err) {}
        document.body.removeChild(ta);
    });
});

document.querySelectorAll('.time-input').forEach(function(inp) {
    inp.addEventListener('input', function() {
        let v = this.value.replace(/[^\d:]/g, '');
        if (v.length === 2 && !v.includes(':') && this.value.length === 2) {
            v = v + ':';
        }
        if (v.length > 5) v = v.slice(0, 5);
        this.value = v;
    });
    inp.addEventListener('blur', function() {
        let v = this.value.trim();
        if (/^\d{1,2}$/.test(v)) v = v.padStart(2, '0') + ':00';
        else if (/^\d{1,2}:\d{1,2}$/.test(v)) {
            const p = v.split(':');
            v = p[0].padStart(2, '0') + ':' + p[1].padStart(2, '0');
        }
        this.value = v;
    });
});
</script>
</body>
</html>