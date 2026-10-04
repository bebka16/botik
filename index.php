<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/notify.php';

error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

date_default_timezone_set($config['timezone']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$storage = new Storage(__DIR__ . '/data/data.enc', $config['encryption_key']);
$data = $storage->load();

$lang = $data['settings']['lang'] ?? 'ru';
$t = lang_strings();
$s = $t[$lang] ?? $t['ru'];

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$todaySchedule = array_values(array_filter($data['schedule'], fn($x) => ($x['date'] ?? '') === $today));
usort($todaySchedule, fn($a, $b) => strcmp($a['time_start'] ?? '', $b['time_start'] ?? ''));

$todayEvents = array_values(array_filter($data['events'] ?? [], fn($x) => ($x['date'] ?? '') === $today));
usort($todayEvents, fn($a, $b) => strcmp($a['time_start'] ?? '', $b['time_start'] ?? ''));

$tomorrowWorks = array_values(array_filter($data['works'], fn($x) => ($x['date'] ?? '') === $tomorrow));
$tomorrowHw = array_values(array_filter($data['homework'], fn($x) => ($x['date'] ?? '') === $tomorrow));

$nowMinutes = (int)date('H') * 60 + (int)date('i');
$currentLesson = null;
$nextLesson = null;
foreach ($todaySchedule as $lesson) {
    $start = (int)substr($lesson['time_start'], 0, 2) * 60 + (int)substr($lesson['time_start'], 3, 2);
    $end = (int)substr($lesson['time_end'], 0, 2) * 60 + (int)substr($lesson['time_end'], 3, 2);
    if ($nowMinutes >= $start && $nowMinutes < $end) {
        $currentLesson = $lesson;
        $currentLesson['_start'] = $start;
        $currentLesson['_end'] = $end;
        break;
    }
    if ($nowMinutes < $start && !$nextLesson) {
        $nextLesson = $lesson;
        $nextLesson['_start'] = $start;
    }
}

$currentEvent = null;
$nextEvent = null;
foreach ($todayEvents as $event) {
    $start = time_to_minutes($event['time_start'] ?? '');
    if ($start === null) continue;
    $end = time_to_minutes($event['time_end'] ?? '');
    if ($end === null || $end <= $start) $end = $start + 60;
    if ($nowMinutes >= $start && $nowMinutes < $end) {
        $currentEvent = $event;
        $currentEvent['_end'] = $end;
        break;
    }
    if ($nowMinutes < $start && !$nextEvent) {
        $nextEvent = $event;
        $nextEvent['_start'] = $start;
    }
}

$nowIcon = 'hgi-arrow-move-down-right';
$listIcons = ['one-square-stroke-rounded.svg', 'two-square-stroke-rounded.svg', 'three-square-stroke-rounded.svg', 'four-square-stroke-rounded.svg', 'five-square-stroke-rounded.svg'];
$eventTitle = function ($event) use ($lang) {
    $txt = trim(($lang === 'by' ? ($event['text_by'] ?? '') : ($event['text_ru'] ?? '')));
    return $txt !== '' ? $txt : lang_pick('events', $lang);
};

if ($currentEvent) {
    $nowIcon = 'hgi-alert-01';
    $nowTitle = sprintf($s['now_current'], $eventTitle($currentEvent));
    $evEnd = fmt_time($currentEvent['time_end'] ?? '');
    $nowSub = $evEnd !== '' ? sprintf($s['now_event_sub'], $evEnd) : $s['now_event_noend'];
} elseif ($currentLesson) {
    $subj = trim(($lang === 'by' ? $currentLesson['subject_by'] : $currentLesson['subject_ru']) ?? '');
    if ($subj === '') $subj = lang_pick('schedule', $lang);
    $parts = lesson_parts($currentLesson);
    $bs = fmt_time($currentLesson['break_start'] ?? '');
    $be = fmt_time($currentLesson['break_end'] ?? '');
    $endM = $parts['end'] ?? $nowMinutes;
    $nowTitle = sprintf($s['now_current'], $subj);

    if ($parts['two_parts'] && $nowMinutes >= $parts['break_start'] && $nowMinutes < $parts['break_end']) {
        $rem = max($parts['break_end'] - $nowMinutes, 0);
        $nowSub = sprintf($s['now_sub_break'], $bs, $be, $rem . ' ' . $s['min']);
    } else {
        $part = ($parts['two_parts'] && $nowMinutes >= $parts['break_end']) ? 2 : 1;
        $rem = max(($parts['two_parts'] && $part === 2 ? $endM : ($parts['two_parts'] ? $parts['break_start'] : $endM)) - $nowMinutes, 0);
        $remText = $rem . ' ' . $s['min'];
        $nowSub = ($part === 1 && $parts['two_parts'])
            ? sprintf($s['now_sub'], $part, $remText, $bs, $be)
            : sprintf($s['now_sub_nobreak'], $part, $remText);
    }
} elseif ($nextEvent && (!$nextLesson || $nextEvent['_start'] <= $nextLesson['_start'])) {
    $nowIcon = 'hgi-alert-01';
    $nowTitle = sprintf($s['now_soon'], $eventTitle($nextEvent));
    $nowSub = sprintf($s['now_soon_sub'], fmt_time($nextEvent['time_start'] ?? ''));
} elseif ($nextLesson) {
    $subj = trim(($lang === 'by' ? $nextLesson['subject_by'] : $nextLesson['subject_ru']) ?? '');
    if ($subj === '') $subj = lang_pick('schedule', $lang);
    $nowTitle = sprintf($s['now_soon'], $subj);
    $nowSub = sprintf($s['now_soon_sub'], fmt_time($nextLesson['time_start'] ?? ''));
} else {
    $nowTitle = $s['now_rest'];
    $nowSub = $s['now_rest_sub'];
}

$appVersion = '1.1';
$botUsername = '';

$meCache = __DIR__ . '/data/bot_me.json';
$meCacheFresh = is_file($meCache) && (time() - (int)@filemtime($meCache) < 86400);
if ($meCacheFresh) {
    $cached = json_decode((string)@file_get_contents($meCache), true);
    if (is_array($cached) && !empty($cached['username'])) $botUsername = (string)$cached['username'];
}
if ($botUsername === '') {
    try {
        $botForAbout = new Bot($config, $storage);
        if ($botForAbout->tokenConfigured()) {
            $meRes = $botForAbout->api('getMe', [], 1);
            if (!empty($meRes['ok']) && !empty($meRes['result']['username'])) {
                $botUsername = (string)$meRes['result']['username'];
                @file_put_contents($meCache, json_encode(['username' => $botUsername], JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    } catch (Throwable $e) {
        $botUsername = '';
    }
}

$jsStrings = json_encode([
    'open_files' => $s['open_files'],
    'empty' => $s['empty'],
    'today' => $s['today'],
    'tomorrow' => $s['tomorrow'],
    'week' => $s['week'],
    'menu_lang' => $s['menu_lang'],
    'notify_item' => $s['notify_item'],
    'notify_hint' => $s['notify_hint'],
    'notify_on' => $s['notify_on'],
    'notify_off' => $s['notify_off'],
    'notify_paused' => $s['notify_paused'],
    'notify_paused_hint' => $s['notify_paused_hint'],
    'need_telegram' => $s['need_telegram'],
    'notify_web_hint' => $s['notify_web_hint'],
    'settings_loading' => $s['settings_loading'],
    'notify_error' => $s['notify_error'],
    'lang_saving' => $s['lang_saving'],
    'lang_error' => $s['lang_error'],
    'theme_dark' => $s['theme_dark'],
    'theme_light' => $s['theme_light'],
    'link_item' => $s['link_item'],
    'link_hint' => $s['link_hint'],
    'link_wait' => $s['link_wait'],
    'link_btn' => $s['link_btn'],
    'bot' => $botUsername,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($s['title']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<script>
(function () {
    var saved = '';
    try { saved = localStorage.getItem('notify_theme') || ''; } catch (e) { saved = ''; }
    if (saved !== 'dark' && saved !== 'light') {
        saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.classList.toggle('dark', saved === 'dark');
})();
</script>

<style>
:root {
    --bg: #f9fafe;
    --panel: #ffffff;
    --stripe1: #f0f0f0;
    --stripe2: #f7f7f7;
    --stripe3: #ffffff;
    --line: #efeff4;
    --text: #1c1c1e;
    --muted: #6f6f78;
    --accent: #71599a;
    --accentSoft: #f5f3f8;
    --onAccent: #ffffff;
    --overlay: rgba(0, 0, 0, 0.35);
    --switchOff: #dcdcdc;
    --shadow: rgba(0, 0, 0, 0.2);
}
html.dark {
    --bg: #121216;
    --panel: #1c1c22;
    --stripe1: #26262d;
    --stripe2: #212127;
    --stripe3: #1a1a1f;
    --line: #2d2d35;
    --text: #e8e8ec;
    --muted: #9a9aa4;
    --accent: #7d61ad;
    --accentSoft: #272232;
    --onAccent: #ffffff;
    --overlay: rgba(0, 0, 0, 0.6);
    --switchOff: #3a3a44;
    --shadow: rgba(0, 0, 0, 0.5);
}
body,html {background: var(--bg); color: var(--text);
-webkit-user-select: none;
  -ms-user-select: none;
  user-select: none; 
}
::selection {
  background-color: transparent;
}
::-moz-selection {
  background-color: transparent;
}
.blink {animation: blink-animation 1s steps(2, start) infinite;}
@keyframes blink-animation {to {visibility: hidden;}}
a:link, a:visited {color: var(--accent);}
.hdr {height: 50px; background: var(--accent); display: flex; align-items: center; justify-content: space-between; padding: 0 20px;}
.hdr a {color: var(--onAccent); text-decoration: none;}
.hdrTitle {color: var(--onAccent); font-size: 14px; margin: 0 0 0 15px; padding: 0; font-weight: 400;}
.now {padding-left: 20px; display: flex; align-items: center; background: var(--stripe1); height: 80px; gap: 15px;}
.nowIcon {color: var(--accent); font-size: 35px;}
.nowTitle {margin: 0; font-size: 14px; font-weight: 400;}
.nowSub {margin-top: 0; font-size: 13px; color: var(--muted); text-decoration: none;}
.sec {margin: 0; padding: 20px; font-size: 14px; font-weight: 400; display: flex; justify-content: space-between; align-items: center;}
.rangeBtn {display: inline-flex; align-items: center; gap: 6px; background: var(--stripe1); border-radius: 6px; padding: 4px 10px; color: var(--muted); font-size: 12px; text-decoration: none; line-height: 1; cursor: pointer;}
.lline {padding: 20px; padding-top: 6px; padding-bottom: 6px; font-size: 14px; display: flex; align-items: center;}
.bg1 {background: var(--stripe1);}
.bg2 {background: var(--stripe2);}
.bg3 {background: var(--stripe3);}
.lline img {width: 21px; margin-right: 4px;}
.ltime {font-weight: 500; color: var(--accent);}
.daysep {padding: 14px 20px 4px; font-size: 12px; color: var(--accent); font-weight: 500; background: var(--bg);}
.emptyLine {padding: 20px; font-size: 13px; color: var(--muted);}
#menuOverlay {display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 100; opacity: 0; transition: opacity 0.25s ease;}
#menuOverlay.active {display: block; opacity: 1;}
#menuWindow {position: fixed; top: 50px; left: 20px; background: var(--panel); border-radius: 4px; box-shadow: 0 4px 16px var(--shadow); z-index: 101; min-width: 220px; overflow: hidden; display: none; opacity: 0; transform: translateY(-10px) scale(0.96); transform-origin: top left; transition: opacity 0.25s ease, transform 0.25s ease;}
#menuWindow.active {display: block; opacity: 1; transform: translateY(0) scale(1);}
.menuItem {padding: 7px 15px; font-size: 12px; color: var(--text); display: flex; align-items: center; gap: 10px; cursor: pointer; border-bottom: 1px solid var(--line);}
.menuItem:last-child {border-bottom: none;}
.menuItem:hover {background: var(--accentSoft);}
.langOptions {max-height: 0; overflow: hidden; transition: max-height 0.25s ease;}
.langOptions.active {max-height: 100px;}
.langOption {padding: 7px 20px 7px 40px; font-size: 12px; color: var(--muted); cursor: pointer;}
.langOption:hover {background: var(--accentSoft);}
.langOption.disabled {opacity: 0.5; cursor: default;}
.langOption.disabled:hover {background: transparent;}
.rangeMenu {position: fixed; background: var(--panel); border-radius: 4px; box-shadow: 0 4px 16px var(--shadow); z-index: 102; min-width: 150px; overflow: hidden; display: none; opacity: 0; transition: opacity 0.2s ease;}
.rangeMenu.active {display: block; opacity: 1;}
.winOverlay {display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--overlay); z-index: 110; opacity: 0; transition: opacity 0.2s ease;}
.winOverlay.active {opacity: 1;}
.win {position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--panel); z-index: 111; display: none; flex-direction: column; opacity: 0; transform: translateX(24px); transition: opacity 0.22s ease, transform 0.22s ease;}
.win.active {opacity: 1; transform: translateX(0);}
.winHeader {display: flex; align-items: center; gap: 14px; height: 50px; background: var(--accent); color: var(--onAccent); padding: 0 20px; font-size: 14px; flex-shrink: 0;}
.winHeader i {font-size: 18px; cursor: pointer;}
.winBody {overflow-y: auto; flex: 1;}
.setRow {display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 20px; border-bottom: 1px solid var(--line);}
.setRowTitle {font-size: 14px; display: flex; align-items: baseline; gap: 6px;}
.setRowState {font-size: 12px; color: var(--muted);}
.setRowHint {font-size: 12px; color: var(--muted); margin-top: 4px; line-height: 1.35;}
.setRowBox {min-width: 0;}
.switch {flex-shrink: 0; width: 44px; height: 26px; border-radius: 12px; background: var(--switchOff); position: relative; cursor: pointer; transition: background 0.2s ease;}
.switch::after {content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px var(--shadow); transition: transform 0.2s ease;}
.switch.on {background: var(--accent);}
.switch.on::after {transform: translateX(18px);}
.switch.disabled {cursor: default; opacity: 1;}
.setError {padding: 14px 20px; font-size: 12px; color: #c0392b;}
.aboutText {padding: 4px 20px 20px; font-size: 13px; color: var(--muted); line-height: 1.5;}
.aboutText b {color: var(--text); font-weight: 500;}
.aboutBot {display: inline-flex; align-items: center; gap: 8px; margin-top: 14px; background: var(--stripe1); border-radius: 6px; padding: 8px 12px; font-size: 13px; text-decoration: none;}
</style>
</head>
<body style="padding: 0; margin: 0; font-family: 'Noto Sans';">
<div class="hdr">
    <div style="display: flex; align-items: center;">
        <a id="menuBtn" style="font-size: 18px; display: flex; align-items: center; cursor: pointer;"><i class="hgi hgi-stroke hgi-rounded hgi-menu-11"></i></a>
        <h1 class="hdrTitle"><?= htmlspecialchars($s['title']) ?></h1>
    </div>
    <a id="nowtime" style="font-size: 14px;"><?= date('H') ?><span class="blink">:</span><?= date('i') ?></a>
</div>

<div class="now">
    <i class="hgi hgi-stroke hgi-rounded <?= htmlspecialchars($nowIcon) ?>" style="color: var(--accent); font-size: 35px;"></i>
    <div style="display: flex; flex-direction: column; justify-content: center;">
        <h3 class="nowTitle"><?= htmlspecialchars($nowTitle) ?></h3>
        <a class="nowSub"><?= htmlspecialchars($nowSub) ?></a>
    </div>
</div>

<div class="works" style="padding: 0px;">
    <h2 class="sec">
        <?= htmlspecialchars($s['works']) ?>
        <a class="rangeBtn" data-target="works">
            <i class="hgi hgi-stroke hgi-rounded hgi-arrow-down-01"></i>
            <span class="rangeLabel"><?= htmlspecialchars($s['tomorrow']) ?></span>
        </a>
    </h2>
    <div id="worksList">
    <?php
    $worksShow = $tomorrowWorks;
    if (empty($worksShow)) $worksShow = array_slice($data['works'], 0, 3);
    foreach ($worksShow as $idx => $w):
        $bg = $idx % 2 === 0 ? 'bg1' : 'bg2';
        $icon = $listIcons[$idx % 5];
        $text = $lang === 'by' ? $w['text_by'] : $w['text_ru'];
        $link = safe_link($w['link'] ?? '');
    ?>
    <div class="lline <?= $bg ?>">
        <img src="assets/<?= $icon ?>"><?= htmlspecialchars($text) ?><?php if ($link !== ''): ?>,&nbsp;<a target="new" href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($s['open_files']) ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="home" style="padding: 0px;">
    <h2 class="sec">
        <?= htmlspecialchars($s['homework']) ?>
        <a class="rangeBtn" data-target="homework">
            <i class="hgi hgi-stroke hgi-rounded hgi-arrow-down-01"></i>
            <span class="rangeLabel"><?= htmlspecialchars($s['tomorrow']) ?></span>
        </a>
    </h2>
    <div id="homeworkList">
    <?php
    $hwShow = $tomorrowHw;
    if (empty($hwShow)) $hwShow = array_slice($data['homework'], 0, 3);
    foreach ($hwShow as $idx => $h):
        $bg = $idx % 2 === 0 ? 'bg1' : 'bg2';
        $icon = $listIcons[$idx % 5];
        $text = $lang === 'by' ? $h['text_by'] : $h['text_ru'];
        $link = safe_link($h['link'] ?? '');
    ?>
    <div class="lline <?= $bg ?>">
        <img src="assets/<?= $icon ?>"><?= htmlspecialchars($text) ?><?php if ($link !== ''): ?>&nbsp;<a target="new" href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($s['open_files']) ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="schedule" style="padding: 0px;">
    <h2 class="sec">
        <?= htmlspecialchars($s['schedule']) ?>
        <a class="rangeBtn" data-target="schedule">
            <i class="hgi hgi-stroke hgi-rounded hgi-arrow-down-01"></i>
            <span class="rangeLabel"><?= htmlspecialchars($s['today']) ?></span>
        </a>
    </h2>
    <div id="scheduleList">
    <?php foreach ($todaySchedule as $idx => $lesson):
        $bg = $idx % 2 === 0 ? 'bg2' : 'bg3';
        $subj = $lang === 'by' ? $lesson['subject_by'] : $lesson['subject_ru'];
        $bs = fmt_time($lesson['break_start'] ?? '');
        $be = fmt_time($lesson['break_end'] ?? '');
        $parts = [];
        if (!empty($lesson['room'])) $parts[] = sprintf($s['room'], $lesson['room']);
        if ($bs && $be) $parts[] = sprintf($s['break'], $bs, $be);
        $extra = $parts ? ', ' . implode(', ', $parts) : '';
    ?>
    <div class="lline <?= $bg ?>" style="display: block;">
        <b class="ltime"><?= fmt_time($lesson['time_start']) ?> — <?= fmt_time($lesson['time_end']) ?></b>
        <?= htmlspecialchars($subj . $extra) ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div id="menuOverlay"></div>
<div id="menuWindow">
    <div class="menuItem" id="langToggle"><i class="hgi hgi-stroke hgi-rounded hgi-globe-02"></i> <?= htmlspecialchars($s['menu_lang']) ?><span class="setRowState" id="langState" style="margin-left: auto;"></span></div>
    <div class="langOptions" id="langOptions">
        <div class="langOption" data-lang="by"><img style="width: 13px;" src="https://flagcdn.com/32x24/by.png"> <?= htmlspecialchars($s['lang_by']) ?></div>
        <div class="langOption" data-lang="ru"><img style="width: 13px;" src="https://flagcdn.com/32x24/ru.png"> <?= htmlspecialchars($s['lang_ru']) ?></div>
    </div>
    <div class="menuItem" id="settingsBtn"><i class="hgi hgi-stroke hgi-rounded hgi-settings-01"></i> <?= htmlspecialchars($s['menu_settings']) ?></div>
    <div class="menuItem" id="aboutBtn"><i class="hgi hgi-stroke hgi-rounded hgi-information-circle"></i> <?= htmlspecialchars($s['menu_about']) ?></div>
</div>
<div id="rangeMenu" class="rangeMenu"></div>

<div id="settingsOverlay" class="winOverlay"></div>
<div id="settingsWindow" class="win">
    <div class="winHeader">
        <i class="hgi hgi-stroke hgi-rounded hgi-arrow-left-01" id="settingsBack"></i>
        <span><?= htmlspecialchars($s['menu_settings']) ?></span>
    </div>
    <div class="winBody">
        <div class="setRow">
            <div class="setRowBox">
                <div class="setRowTitle">
                    <?= htmlspecialchars($s['notify_item']) ?>
                    <span class="setRowState" id="notifyState"></span>
                </div>
                <div class="setRowHint" id="notifyHint"><?= htmlspecialchars($s['settings_loading']) ?></div>
            </div>
            <div class="switch" id="notifySwitch"></div>
        </div>
        <div class="setRow" id="linkRow" style="display: none;">
            <div class="setRowBox">
                <div class="setRowTitle">
                    <span id="linkTitle"></span>
                    <span class="setRowState" id="linkState"></span>
                </div>
                <div class="setRowHint" id="linkHint"></div>
            </div>
            <a class="aboutBot" id="linkBtn" style="padding: 5px 10px; padding-right: 19px;" target="_blank" rel="noopener">
                <i class="hgi hgi-stroke hgi-rounded hgi-send-01"></i><span id="linkBtnText"></span>
            </a>
        </div>
        <div class="setRow">
            <div class="setRowBox">
                <div class="setRowTitle">
                    <?= htmlspecialchars($s['theme_item']) ?>
                    <span class="setRowState" id="themeState"></span>
                </div>
                <div class="setRowHint"><?= htmlspecialchars($s['theme_hint']) ?></div>
            </div>
            <div class="switch" id="themeSwitch"></div>
        </div>
        <div class="setError" id="settingsError" style="display: none;"></div>
    </div>
</div>

<div id="aboutOverlay" class="winOverlay"></div>
<div id="aboutWindow" class="win">
    <div class="winHeader">
        <i class="hgi hgi-stroke hgi-rounded hgi-arrow-left-01" id="aboutBack"></i>
        <span><?= htmlspecialchars($s['about_title']) ?></span>
    </div>
    <div class="winBody">
        <div style="display: none;" class="setRow">
            <div class="setRowTitle"><?= htmlspecialchars($s['about_name']) ?></div>
        </div>
        <div class="aboutText" style="margin-top: 10px;">
            <div><?= htmlspecialchars($s['about_desc']) ?></div>
            <div style="margin-top: 10px;"><?= htmlspecialchars($s['about_notify']) ?></div>
            <div style="margin-top: 10px;"><?= htmlspecialchars($s['about_data']) ?></div>
            <?php if ($botUsername !== ''): ?>
            <a style="display: none;" class="aboutBot" target="_blank" rel="noopener" href="https://t.me/<?= htmlspecialchars($botUsername) ?>">
                <i class="hgi hgi-stroke hgi-rounded hgi-send-01"></i><?= htmlspecialchars($s['about_bot']) ?>
            </a>
            <?php endif; ?>
            <div style="margin-top: 16px; font-size: 12px;"><?= htmlspecialchars($s['about_version']) ?> <?= htmlspecialchars($appVersion) ?></div>
        </div>
    </div>
</div>

<script>
const T = <?= $jsStrings ?>;

function updateClock() {
    const clockElement = document.getElementById('nowtime');
    if (!clockElement) return;
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    clockElement.innerHTML = `${hours}<span class="blink">:</span>${minutes}`;
}
updateClock();
setInterval(updateClock, 1000);

const menuBtn = document.getElementById('menuBtn');
const menuOverlay = document.getElementById('menuOverlay');
const menuWindow = document.getElementById('menuWindow');
const langToggle = document.getElementById('langToggle');
const langOptions = document.getElementById('langOptions');
let menuOpen = false;

function openMenu() {
    menuOpen = true;
    menuOverlay.style.display = 'block';
    menuWindow.style.display = 'block';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            menuOverlay.classList.add('active');
            menuWindow.classList.add('active');
        });
    });
}
function closeMenu() {
    menuOpen = false;
    menuOverlay.classList.remove('active');
    menuWindow.classList.remove('active');
    langOptions.classList.remove('active');
    setTimeout(function() {
        if (!menuOpen) {
            menuOverlay.style.display = 'none';
            menuWindow.style.display = 'none';
        }
    }, 250);
}
menuBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    if (menuOpen) closeMenu(); else openMenu();
});
menuOverlay.addEventListener('click', closeMenu);
langToggle.addEventListener('click', function(e) {
    e.stopPropagation();
    langOptions.classList.toggle('active');
});

const settingsOverlay = document.getElementById('settingsOverlay');
const settingsWindow = document.getElementById('settingsWindow');
const aboutOverlay = document.getElementById('aboutOverlay');
const aboutWindow = document.getElementById('aboutWindow');

function openWindow(overlay, win) {
    closeMenu();
    document.body.style.overflow = 'hidden';
    overlay.style.display = 'block';
    win.style.display = 'flex';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            overlay.classList.add('active');
            win.classList.add('active');
        });
    });
}
function closeWindow(overlay, win) {
    overlay.classList.remove('active');
    win.classList.remove('active');
    setTimeout(function() {
        overlay.style.display = 'none';
        win.style.display = 'none';
        document.body.style.overflow = '';
    }, 220);
}
function openSettings() { openWindow(settingsOverlay, settingsWindow); }
function closeSettings() { closeWindow(settingsOverlay, settingsWindow); }
function openAbout() { openWindow(aboutOverlay, aboutWindow); }
function closeAbout() { closeWindow(aboutOverlay, aboutWindow); }

document.getElementById('settingsBtn').addEventListener('click', openSettings);
document.getElementById('aboutBtn').addEventListener('click', openAbout);
settingsOverlay.addEventListener('click', closeSettings);
aboutOverlay.addEventListener('click', closeAbout);
document.getElementById('settingsBack').addEventListener('click', closeSettings);
document.getElementById('aboutBack').addEventListener('click', closeAbout);

let langSaving = false;

function setLangState(text) {
    const state = document.getElementById('langState');
    if (state) state.textContent = text || '';
}

function reloadApp() {
    const url = new URL(window.location.href);
    url.searchParams.set('v', String(Date.now()));
    window.location.replace(url.toString());
}

function withTimeout(promise, ms) {
    return new Promise(function(resolve, reject) {
        const timer = setTimeout(function() { reject(new Error('timeout')); }, ms);
        promise.then(function(value) { clearTimeout(timer); resolve(value); },
                     function(err) { clearTimeout(timer); reject(err); });
    });
}

function postApi(action, params) {
    const body = new URLSearchParams();
    body.set('action', action);
    if (params) {
        Object.keys(params).forEach(function(k) { body.set(k, params[k]); });
    }
    return fetch('api.php', {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
    }).then(function(r) {
        return r.text().then(function(text) {
            let data = null;
            try { data = JSON.parse(text); } catch (e) { data = null; }
            if (!r.ok || !data || data.ok !== true) {
                throw new Error((data && data.error) ? data.error : ('HTTP ' + r.status));
            }
            return data;
        });
    });
}

function pickLang(lang) {
    if (langSaving) return;
    langSaving = true;
    setLangState(T.lang_saving);
    document.querySelectorAll('.langOption').forEach(function(opt) { opt.classList.add('disabled'); });
    const payload = {lang: lang, client_id: clientId, init_data: initData};
    withTimeout(postApi('set_lang', payload), 15000)
        .then(function() {
            setLangState('');
            reloadApp();
        })
        .catch(function(err) {
            langSaving = false;
            setLangState(T.lang_error + (err && err.message ? ' (' + err.message + ')' : ''));
            document.querySelectorAll('.langOption').forEach(function(opt) { opt.classList.remove('disabled'); });
        });
}

document.querySelectorAll('.langOption').forEach(function(opt) {
    opt.addEventListener('click', function(e) {
        e.stopPropagation();
        pickLang(this.dataset.lang);
    });
});

const settingsError = document.getElementById('settingsError');
const notifySwitch = document.getElementById('notifySwitch');
const notifyState = document.getElementById('notifyState');
const notifyHint = document.getElementById('notifyHint');
const themeSwitch = document.getElementById('themeSwitch');
const themeState = document.getElementById('themeState');
const tgWebApp = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;
if (tgWebApp) {
    try {
        if (typeof tgWebApp.expand === 'function') tgWebApp.expand();
        if (typeof tgWebApp.ready === 'function') tgWebApp.ready();
    } catch (e) {}
}
const initData = (tgWebApp && tgWebApp.initData) ? tgWebApp.initData : '';

function getClientId() {
    let id = '';
    try { id = window.localStorage.getItem('notify_client_id') || ''; } catch (e) { id = ''; }
    if (!/^[a-zA-Z0-9_-]{8,64}$/.test(id)) {
        id = 'web' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        try { window.localStorage.setItem('notify_client_id', id); } catch (e) {}
    }
    return id;
}
const clientId = getClientId();

let notifyReady = false;
let themeDark = document.documentElement.classList.contains('dark');

function applyTheme(dark, save) {
    themeDark = !!dark;
    document.documentElement.classList.toggle('dark', themeDark);
    if (save) {
        try { window.localStorage.setItem('notify_theme', themeDark ? 'dark' : 'light'); } catch (e) {}
    }
    if (themeSwitch) {
        themeSwitch.classList.toggle('on', themeDark);
        themeState.textContent = themeDark ? T.theme_dark : T.theme_light;
    }
}
applyTheme(themeDark, false);

function setNotify(value, stateText) {
    notifyReady = value !== null;
    notifySwitch.classList.toggle('on', !!value);
    if (value === null) notifySwitch.classList.add('disabled');
    notifyState.textContent = stateText;
}

function showSettingsError(text) {
    settingsError.textContent = text;
    settingsError.style.display = text ? 'block' : 'none';
}

function tgPost(action, params) {
    return postApi(action, Object.assign({
        init_data: initData,
        client_id: clientId
    }, params || {}));
}

const linkRow = document.getElementById('linkRow');
const linkTitle = document.getElementById('linkTitle');
const linkHint = document.getElementById('linkHint');
const linkState = document.getElementById('linkState');
const linkBtn = document.getElementById('linkBtn');
const linkBtnText = document.getElementById('linkBtnText');
const botLinkUrl = T.bot ? ('https://t.me/' + T.bot + '?start=' + encodeURIComponent(clientId)) : '';
let linkedMode = '';

function renderLink(mode) {
    linkedMode = mode || '';
    if (linkedMode === 'tg' || botLinkUrl === '') {
        linkRow.style.display = 'none';
        return;
    }
    linkRow.style.display = 'flex';
    linkTitle.textContent = T.link_item;
    linkBtnText.textContent = T.link_btn;
    linkBtn.href = botLinkUrl;
    linkHint.textContent = T.link_hint;
    linkState.textContent = T.link_wait;
}

let settingsLoadedAt = 0;
function loadSettings() {
    setNotify(null, T.settings_loading);
    notifyHint.textContent = T.settings_loading;
    tgPost('me').then(function(res) {
        if (!res || !res.ok) throw new Error('unauthorized');
        showSettingsError('');
        renderLink(res.mode);
        if (res.mode !== 'tg') {
            notifyHint.textContent = T.notify_web_hint;
        } else if (!res.master) {
            notifyHint.textContent = T.notify_paused_hint;
            setNotify(null, T.notify_paused);
        } else {
            notifyHint.textContent = T.notify_hint;
        }
        setNotify(res.notify, res.notify ? T.notify_on : T.notify_off);
        if (res.theme === 'dark' || res.theme === 'light') applyTheme(res.theme === 'dark', true);
        settingsLoadedAt = Date.now();
    }).catch(function() {
        setNotify(null, T.notify_off);
        notifyHint.textContent = T.need_telegram;
        renderLink('');
        settingsLoadedAt = Date.now();
    });
}
loadSettings();

function refreshSettingsIfUnlinked() {
    if (linkedMode === 'tg') return;
    if (Date.now() - settingsLoadedAt < 5000) return;
    loadSettings();
}
window.addEventListener('focus', refreshSettingsIfUnlinked);
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) refreshSettingsIfUnlinked();
});

function runNotifyTick() {
    if (typeof fetch !== 'function') return;
    try {
        fetch('api.php?action=tick&v=' + Date.now(), {method: 'GET', cache: 'no-store', credentials: 'same-origin'})
            .catch(function() {});
    } catch (e) {}
}
runNotifyTick();
setInterval(runNotifyTick, 60000);

notifySwitch.addEventListener('click', function() {
    if (!notifyReady) return;
    const next = !notifySwitch.classList.contains('on');
    setNotify(next, next ? T.notify_on : T.notify_off);
    showSettingsError('');
    tgPost('notify_set', {notify: next ? '1' : '0'}).then(function(res) {
        if (!res || !res.ok) throw new Error('save failed');
    }).catch(function() {
        setNotify(!next, next ? T.notify_off : T.notify_on);
        showSettingsError(T.notify_error);
    });
});

themeSwitch.addEventListener('click', function() {
    applyTheme(!themeDark, true);
    tgPost('theme_set', {theme: themeDark ? 'dark' : 'light'}).catch(function() {});
});

const rangeMenu = document.getElementById('rangeMenu');
let activeRangeTarget = null;

function showRangeMenu(btn) {
    const rect = btn.getBoundingClientRect();
    activeRangeTarget = btn.dataset.target;
    const labels = [T.today, T.tomorrow, T.week];
    const keys = ['today', 'tomorrow', 'week'];
    let html = '';
    keys.forEach(function(k, i) {
        html += '<div class="menuItem" data-range="' + k + '">' + esc(labels[i]) + '</div>';
    });
    rangeMenu.innerHTML = html;
    rangeMenu.style.top = (rect.bottom + 2) + 'px';
    rangeMenu.style.left = (rect.left - 73) + 'px';
    rangeMenu.style.display = 'block';
    requestAnimationFrame(function() { rangeMenu.classList.add('active'); });
    rangeMenu.querySelectorAll('.menuItem').forEach(function(item) {
        item.addEventListener('click', function() {
            fetch('api.php?action=filter&target=' + encodeURIComponent(activeRangeTarget) + '&range=' + encodeURIComponent(this.dataset.range) + '&v=' + Date.now(),
                {cache: 'no-store', credentials: 'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(res) { renderRange(res); })
                .catch(function() {});
            hideRangeMenu();
        });
    });
}
function hideRangeMenu() {
    rangeMenu.classList.remove('active');
    activeRangeTarget = null;
    setTimeout(function() { rangeMenu.style.display = 'none'; }, 200);
}
document.querySelectorAll('.rangeBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (rangeMenu.style.display === 'block' && activeRangeTarget === this.dataset.target) {
            hideRangeMenu();
        } else {
            showRangeMenu(this);
        }
    });
});
document.addEventListener('click', function() { if (rangeMenu.style.display === 'block') hideRangeMenu(); });

function esc(value) {
    return String(value === undefined || value === null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function iconFor(idx) {
    return ['one-square-stroke-rounded.svg', 'two-square-stroke-rounded.svg', 'three-square-stroke-rounded.svg', 'four-square-stroke-rounded.svg', 'five-square-stroke-rounded.svg'][idx % 5];
}

function itemLine(bg, icon, text, link) {
    let html = '<div class="lline ' + bg + '">' +
        '<img src="assets/' + icon + '">' + esc(text);
    if (link) {
        html += ',&nbsp;<a target="new" href="' + esc(link) + '">' + esc(T.open_files) + '</a>';
    }
    return html + '</div>';
}

function daySep(day) {
    return day ? '<div class="daysep">' + esc(day) + '</div>' : '';
}

function emptyLine() {
    return '<div class="emptyLine">' + esc(T.empty) + '</div>';
}

function renderRange(res) {
    if (res.target === 'schedule') {
        const el = document.getElementById('scheduleList');
        let html = '';
        let lastDay = null;
        (res.items || []).forEach(function(it, idx) {
            if (it.day !== lastDay) {
                html += daySep(it.day);
                lastDay = it.day;
            }
            const bg = idx % 2 === 0 ? 'bg2' : 'bg3';
            html += '<div class="lline ' + bg + '" style="display: block;">' +
                '<b class="ltime">' + esc(it.time) + '</b> ' + esc(it.text) + '</div>';
        });
        el.innerHTML = html || emptyLine();
    } else if (res.target === 'works' || res.target === 'homework') {
        const el = document.getElementById(res.target === 'works' ? 'worksList' : 'homeworkList');
        let html = '';
        let lastDay = null;
        (res.items || []).forEach(function(it, idx) {
            if (it.day !== lastDay) {
                html += daySep(it.day);
                lastDay = it.day;
            }
            const bg = idx % 2 === 0 ? 'bg1' : 'bg2';
            html += itemLine(bg, iconFor(idx), it.text, it.link);
        });
        el.innerHTML = html || emptyLine();
    }
    const btn = document.querySelector('.rangeBtn[data-target="' + res.target + '"]');
    if (btn) btn.querySelector('.rangeLabel').textContent = res.label;
}
</script>
</body>
</html>
<?php notify_background_run($config, $storage, 45); ?>
