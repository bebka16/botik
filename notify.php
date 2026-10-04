<?php
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/bot.php';

function notify_data_dir() {
    return __DIR__ . '/data';
}

function notify_log($message) {
    $dir = notify_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/notify.log';
    if (is_file($file) && filesize($file) > 524288) @unlink($file);
    @file_put_contents($file, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function notify_state_path() {
    return notify_data_dir() . '/notify_state.json';
}

function notify_state_load() {
    $file = notify_state_path();
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $state = json_decode($raw, true);
    return is_array($state) ? $state : [];
}

function notify_state_save($state) {
    $file = notify_state_path();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        notify_log('state: не удалось записать файл состояния');
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        notify_log('state: не удалось заменить файл состояния');
        return false;
    }
    return true;
}

function notify_lock() {
    $file = notify_data_dir() . '/notify.lock';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $fh = @fopen($file, 'c');
    if ($fh === false) {
        notify_log('lock: не удалось открыть файл блокировки');
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)getmypid());
    fflush($fh);
    return $fh;
}

function notify_unlock($fh) {
    if (!is_resource($fh)) return;
    flock($fh, LOCK_UN);
    fclose($fh);
}

function bot_chats($data) {
    $globalLang = in_array($data['settings']['lang'] ?? '', ['ru', 'by'], true) ? $data['settings']['lang'] : 'ru';
    $out = [];

    foreach ($data['users'] ?? [] as $chatId => $user) {
        if (!is_array($user)) continue;
        $id = trim((string)$chatId);
        if (!preg_match('/^-?[0-9]+$/', $id)) continue;
        $lang = $user['lang'] ?? $globalLang;
        $out[$id] = in_array($lang, ['ru', 'by'], true) ? $lang : $globalLang;
    }
    foreach ($data['subscribers'] ?? [] as $chatId) {
        $id = trim((string)$chatId);
        if (preg_match('/^-?[0-9]+$/', $id)) $out[$id] = $globalLang;
    }

    return $out;
}

function web_only_users($data) {
    $out = [];
    foreach ($data['users'] ?? [] as $chatId => $user) {
        if (!is_array($user)) continue;
        if (!preg_match('/^-?[0-9]+$/', trim((string)$chatId))) $out[] = (string)$chatId;
    }
    return $out;
}

function notify_recipients($data) {
    $subscribers = [];
    foreach ($data['subscribers'] ?? [] as $id) {
        $id = trim((string)$id);
        if (preg_match('/^-?[0-9]+$/', $id)) $subscribers[$id] = true;
    }

    $out = [];
    foreach (bot_chats($data) as $id => $lang) {
        if (isset($subscribers[$id]) || !empty($data['users'][$id]['notify'])) $out[$id] = $lang;
    }
    return $out;
}

function broadcast_send($config, $storage, $data, $textRu, $textBy, $delayUs = 60000, $bot = null) {
    @set_time_limit(0);

    $chats = bot_chats($data);
    $total = count($chats);

    $result = ['sent' => 0, 'failed' => 0, 'total' => $total, 'error' => ''];
    if ($total === 0) return $result;

    $textRu = trim((string)$textRu);
    $textBy = trim((string)$textBy);
    if ($textRu === '' && $textBy === '') {
        $result['error'] = 'empty';
        $result['failed'] = $total;
        return $result;
    }

    if ($bot === null) $bot = new Bot($config, $storage);
    if (!$bot->tokenConfigured()) {
        $result['error'] = 'token';
        $result['failed'] = $total;
        return $result;
    }

    $lock = notify_lock();
    if ($lock === false) {
        $result['error'] = 'busy';
        return $result;
    }
    if ($lock === null) {
        $result['error'] = 'lock';
        $result['failed'] = $total;
        return $result;
    }

    $errors = [];
    try {
        foreach ($chats as $chatId => $lang) {
            $text = ($lang === 'by' && $textBy !== '') ? $textBy : ($textRu !== '' ? $textRu : $textBy);
            $res = $bot->sendMessage($chatId, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            if (!empty($res['ok'])) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $errors[$chatId] = (string)($res['description'] ?? 'error');
            }
            if ($delayUs > 0) usleep((int)$delayUs);
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        notify_log('broadcast error: ' . $e->getMessage());
    } finally {
        notify_unlock($lock);
    }

    notify_log('broadcast: sent=' . $result['sent'] . ' failed=' . $result['failed'] . ' total=' . $total
        . ($errors ? ' errors=' . json_encode(array_slice($errors, 0, 10), JSON_UNESCAPED_UNICODE) : ''));

    return $result;
}

function notify_marker_path($name) {
    return notify_data_dir() . '/' . $name;
}

function notify_webhook_selfheal($config, $bot) {
    $marker = notify_marker_path('notify_webhook_check');
    if (is_file($marker) && (time() - (int)@filemtime($marker)) < 86400) return false;
    @touch($marker);

    $url = trim((string)($config['webhook_url'] ?? ''));
    if ($url === '' || !$bot->tokenConfigured()) return false;

    $info = $bot->webhookInfo();
    if (empty($info['ok'])) return false;
    $current = $info['result'];
    if (trim((string)($current['url'] ?? '')) !== $url) return false;

    $allowed = $current['allowed_updates'] ?? [];
    if (!is_array($allowed)) $allowed = [];
    if (in_array('callback_query', $allowed, true) && in_array('message', $allowed, true)) return false;

    $res = $bot->setWebhook($url);
    notify_log('webhook selfheal: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
    return true;
}

function notify_cron_key($config) {
    return hash_hmac('sha256', 'decibel-cron', (string)($config['bot_token'] ?? ''));
}

function notify_webhook_tick($config, $storage, $minGap = 60) {
    if (!isset($config['bot_token']) || trim((string)$config['bot_token']) === '') {
        return ['ok' => false, 'reason' => 'no_token'];
    }
    return notify_run($config, $storage, $minGap);
}

function notify_run($config, $storage, $minGap = 30) {
    $gap = max(5, (int)$minGap);
    $marker = notify_marker_path('notify_tick');
    $last = is_file($marker) ? (int)@filemtime($marker) : 0;
    if ($last > 0 && (time() - $last) < $gap) {
        return ['ok' => false, 'reason' => 'throttled'];
    }
    @touch($marker);

    $lock = notify_lock();
    if ($lock === false) return ['ok' => false, 'reason' => 'busy'];
    if ($lock === null) return ['ok' => false, 'reason' => 'lock'];

    try {
        $bot = new Bot($config, $storage);
        $result = notify_dispatch($config, $storage, $bot);
        notify_webhook_selfheal($config, $bot);
        $result['ok'] = true;
        $result['reason'] = 'done';
        notify_log('run: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    } catch (Throwable $e) {
        notify_log('run error: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    } finally {
        notify_unlock($lock);
    }
}

function notify_detach_response() {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}

function notify_background_run($config, $storage, $minGap = 45) {
    notify_detach_response();
    if (!isset($config['bot_token']) || trim((string)$config['bot_token']) === '') return;
    notify_run($config, $storage, $minGap);
}

function notify_dispatch($config, $storage, $bot, $catchUpMinutes = 45) {
    $data = $storage->load();
    if (!empty($storage->loadError())) {
        notify_log('dispatch: ' . $storage->loadError());
    }
    if (empty($data['settings']['notifications'])) {
        return ['sent' => 0, 'reason' => 'disabled'];
    }

    $recipients = notify_recipients($data);
    if (empty($recipients)) {
        return ['sent' => 0, 'reason' => 'no_recipients'];
    }

    $today = date('Y-m-d');
    $nowMinutes = (int)date('H') * 60 + (int)date('i');
    $catchUp = max(1, (int)$catchUpMinutes);

    $state = notify_state_load();
    $changed = false;

    $schedule = array_values(array_filter($data['schedule'] ?? [], fn($x) => is_array($x) && ($x['date'] ?? '') === $today));
    usort($schedule, fn($a, $b) => strcmp((string)($a['time_start'] ?? ''), (string)($b['time_start'] ?? '')));

    $byLang = [];
    foreach ($recipients as $chatId => $lang) {
        $byLang[$lang][] = $chatId;
    }

    $lessonLine = function ($lesson, $lang, $roomFmt) {
        $subj = trim((string)($lang === 'by' ? ($lesson['subject_by'] ?? '') : ($lesson['subject_ru'] ?? '')));
        if ($subj === '') $subj = lang_pick('schedule', $lang);
        $room = trim((string)($lesson['room'] ?? ''));
        return htmlspecialchars($subj, ENT_QUOTES, 'UTF-8') . ($room !== '' ? ' ' . sprintf($roomFmt, htmlspecialchars($room, ENT_QUOTES, 'UTF-8')) : '');
    };

    $lessonHash = function ($lesson) {
        return substr(md5((string)($lesson['subject_ru'] ?? '') . '|' . (string)($lesson['room'] ?? '') . '|' . (string)($lesson['time_end'] ?? '')), 0, 8);
    };

    $sent = 0;
    $failed = 0;

    foreach ($byLang as $lang => $chatIds) {
        $t = $bot->strings($lang);
        $all = lang_strings();
        $ui = $all[$lang] ?? $all['ru'];

        foreach ($schedule as $idx => $lesson) {
            $end = time_to_minutes($lesson['time_end'] ?? '');
            if ($end === null) continue;

            $key = 'end_' . $today . '_' . $lang . '_' . ($lesson['time_start'] ?? '') . '_' . $lessonHash($lesson);
            if (!empty($state[$key])) continue;
            if ($nowMinutes < $end || $nowMinutes >= $end + 1 + $catchUp) continue;

            $nextLesson = $schedule[$idx + 1] ?? null;
            $nextStart = $nextLesson ? time_to_minutes($nextLesson['time_start'] ?? '') : null;
            $gap = ($nextStart !== null && $nextStart > $end) ? $nextStart - $end : 0;

            $head = $gap > 0
                ? sprintf($t['notif_break_min'], minutes_phrase($gap, $lang))
                : $t['notif_break_plain'];

            if ($nextLesson && trim((string)($nextLesson['time_start'] ?? '')) !== '') {
                $tail = sprintf(
                    $t['notif_next_after'],
                    fmt_time($nextLesson['time_start'] ?? ''),
                    fmt_time($nextLesson['time_end'] ?? ''),
                    $lessonLine($nextLesson, $lang, $t['notif_room'])
                );
                $text = $head . ' ' . $tail;
            } else {
                $text = $t['notif_home'];
            }
            $ok = true;
            foreach ($chatIds as $chatId) {
                $res = $bot->sendMessage($chatId, $text);
                if (!empty($res['ok'])) {
                    $sent++;
                } else {
                    $ok = false;
                    $failed++;
                }
            }
            if ($ok) {
                $state[$key] = true;
                $changed = true;
            } else {
                notify_log('dispatch: не удалось отправить уведомление об окончании пары ' . ($lesson['time_start'] ?? '') . ' (' . $lang . ')');
            }
        }

        $firstLesson = null;
        foreach ($schedule as $lesson) {
            if (time_to_minutes($lesson['time_start'] ?? '') === null) continue;
            $firstLesson = $lesson;
            break;
        }

        if (is_array($firstLesson)) {
            $start = time_to_minutes($firstLesson['time_start'] ?? '');

            $key = 'first_' . $today . '_' . $lang . '_' . ($firstLesson['time_start'] ?? '') . '_' . $lessonHash($firstLesson);
            if (empty($state[$key]) && $start !== null && $nowMinutes >= $start - 15 && $nowMinutes <= $start) {
                $text = sprintf($t['notif_first_soon'], $lessonLine($firstLesson, $lang, $ui['room']));
                $ok = true;
                foreach ($chatIds as $chatId) {
                    $res = $bot->sendMessage($chatId, $text);
                    if (!empty($res['ok'])) {
                        $sent++;
                    } else {
                        $ok = false;
                        $failed++;
                    }
                }
                if ($ok) {
                    $state[$key] = true;
                    $changed = true;
                } else {
                    notify_log('dispatch: не удалось отправить напоминание о первой паре ' . ($firstLesson['time_start'] ?? '') . ' (' . $lang . ')');
                }
            }
        }
    }

    $keepFrom = strtotime($today . ' -7 days');
    foreach (array_keys($state) as $key) {
        $parts = explode('_', (string)$key, 3);
        $ts = count($parts) >= 2 ? strtotime($parts[1]) : false;
        if ($ts === false || $ts < $keepFrom) {
            unset($state[$key]);
            $changed = true;
        }
    }

    if ($changed) notify_state_save($state);

    return ['sent' => $sent, 'failed' => $failed, 'reason' => 'ok'];
}
