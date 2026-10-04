<?php
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/webapp.php';

class Bot {
    private $token;
    private $storage;
    private $config;
    private $answered = [];

    public function __construct($config, $storage) {
        $this->config = $config;
        $this->token = (string)($config['bot_token'] ?? '');
        $this->storage = $storage;
    }

    public function tokenConfigured() {
        return $this->token !== '' && $this->token !== 'YOUR_BOT_TOKEN';
    }

    private function caBundle() {
        if (defined('CURLSSL_CAINFO')) {
            $custom = trim((string)ini_get('curl.cainfo'));
            if ($custom !== '' && is_readable($custom)) return $custom;
        }
        $candidates = [
            'curl.cainfo',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
            '/usr/local/share/certs/ca-root-nss.crt',
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) return $path;
        }
        return null;
    }

    public function api($method, $params = [], $attempts = 3) {
        if (!$this->tokenConfigured()) {
            $this->log('api skipped: bot_token не задан в config.php (' . $method . ')');
            return ['ok' => false, 'description' => 'bot_token is not configured'];
        }

        $url = "https://api.telegram.org/bot{$this->token}/{$method}";
        $caInfo = $this->caBundle();
        $decoded = ['ok' => false];

        for ($attempt = 1; $attempt <= max(1, (int)$attempts); $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            if ($caInfo !== null) curl_setopt($ch, CURLOPT_CAINFO, $caInfo);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode((string)$res, true);
            if (!is_array($decoded)) {
                $decoded = ['ok' => false];
                $this->log('api transport error: ' . $method . ' attempt=' . $attempt . ' http=' . $code . ' curl=' . $err);
                if ($attempt < $attempts) usleep(400000 * $attempt);
                continue;
            }
            if (!empty($decoded['ok'])) return $decoded;

            $retryAfter = (int)($decoded['parameters']['retry_after'] ?? 0);
            $this->log('api rejected: ' . $method . ' attempt=' . $attempt . ' (' . ($decoded['description'] ?? '') . ')');
            if ($retryAfter > 0) {
                if ($retryAfter <= 10 && $attempt < $attempts) {
                    sleep($retryAfter);
                    continue;
                }
                return $decoded;
            }
            if ($code >= 500 && $attempt < $attempts) {
                usleep(400000 * $attempt);
                continue;
            }
            return $decoded;
        }

        return is_array($decoded) ? $decoded : ['ok' => false];
    }

    public function log($message) {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/bot.log';
        if (is_file($file) && filesize($file) > 524288) @unlink($file);
        @file_put_contents($file, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function save($data) {
        if ($this->storage->save($data)) return true;
        $this->log('save failed: ' . $this->storage->lastError());
        return false;
    }

    public function sendMessage($chatId, $text, $keyboard = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        return $this->api('sendMessage', $params);
    }

    public function answerCallback($id, $text = '') {
        $id = (string)$id;
        if ($id === '') return ['ok' => true];
        if (isset($this->answered[$id])) return ['ok' => true];

        $params = ['callback_query_id' => $id];
        if ($text !== '') $params['text'] = $text;
        $res = $this->api('answerCallbackQuery', $params, 3);
        if (!empty($res['ok'])) {
            $this->answered[$id] = true;
        } else {
            $this->log('answerCallback failed: ' . $id . ' (' . ($res['description'] ?? 'error') . ')');
        }
        return $res;
    }

    public function answerCallbackIfNeeded($id) {
        return $this->answerCallback($id, '');
    }

    public function setWebhook($url) {
        return $this->api('setWebhook', [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public function webhookInfo() {
        return $this->api('getWebhookInfo', [], 1);
    }

    public function webappUrl() {
        if (!empty($this->config['webapp_url'])) return $this->config['webapp_url'];
        $url = (string)($this->config['webhook_url'] ?? '');
        $pos = strpos($url, '/webhook.php');
        if ($pos !== false) $url = substr($url, 0, $pos);
        return $url !== '' ? $url : 'https://example.com';
    }

    public function getMainKeyboard($lang = 'ru') {
        $t = $this->strings($lang);
        return [
            'inline_keyboard' => [
                [['text' => $t['btn_app'], 'web_app' => ['url' => $this->webappUrl()]]],
                [['text' => $t['btn_settings'], 'callback_data' => 'settings']],
            ],
        ];
    }

    public function getSettingsKeyboard($lang = 'ru', $notify = false) {
        $t = $this->strings($lang);
        $mark = $notify ? '' : '';
        return [
            'inline_keyboard' => [
                [['text' => $mark . ' ' . $t['notify_item'], 'callback_data' => 'notif_toggle']],
                [['text' => $t['back'], 'callback_data' => 'back']],
            ],
        ];
    }

    public function getLangKeyboard() {
        $t = $this->strings('ru');
        return [
            'inline_keyboard' => [
                [
                    ['text' => $t['btn_lang_ru'], 'callback_data' => 'lang_ru'],
                    ['text' => $t['btn_lang_by'], 'callback_data' => 'lang_by'],
                ],
            ],
        ];
    }

    public function getOnboardingNotifyKeyboard($lang = 'ru', $notify = false) {
        $t = $this->strings($lang);
        $mark = $notify ? '' : '';
        return [
            'inline_keyboard' => [
                [['text' => $mark . ' ' . $t['notify_item'], 'callback_data' => 'notif_toggle']],
                [['text' => $t['btn_skip'], 'callback_data' => 'notif_skip']],
            ],
        ];
    }

    public function strings($lang) {
        $strings = [
            'ru' => [
                'btn_app' => 'Открыть приложение',
                'btn_settings' => 'Настройки',
                'welcome' => "Всё можно смотреть через приложение в ТГ. Просто нажми на кнопку внизу.",
                'settings_title' => '<b>Настройки:</b>',
                'notify_item' => 'Оповещать о парах',
                'pick_lang' => "Выберите язык / Абярыце мову",
                'btn_lang_ru' => '🇷🇺 Русский',
                'btn_lang_by' => '🇧🇾 Беларуская',
                'btn_skip' => 'Пропустить пока что',
                'notify_ask' => '<b>Оповещения о парах:</b>',
                'notify_on' => 'Оповещения включены',
                'notify_off' => 'Оповещения выключены',
                'notify_hint' => 'Бот пришлёт сообщение за 15 минут до первой пары дня, в конце каждой пары — про следующую, а после последней пары — что пора домой.',
                'notify_saved_on' => 'Оповещения о парах включены',
                'notify_saved_off' => 'Оповещения о парах выключены',
                'back' => 'Назад',
                'btn_refresh' => 'Обновить',
                'notif_break_min' => "<b>Сейчас перерыв %s.</b>\n\n",
                'notif_break_plain' => "<b>Сейчас перерыв.</b>\n\n",
                'notif_next_after' => "Потом с %s по %s %s.",
                'notif_room' => 'в кабинете %s',
                'notif_first_soon' => "<b>Пара начнётся через 15 минут:</b>\n\n%s",
                'notif_home' => "Домооой",
                'link_done' => 'Бот и приложение успешно связаны.',
                'link_bad' => 'Не удалось связать ТГ с ботом. Открой приложение в Telegram и попробуй снова.',
                'only_menu' => "Выбери раздел ниже ",
            ],
            'by' => [
                'btn_app' => 'Адкрыць дадатак',
                'btn_settings' => 'Налады',
                'welcome' => "Усё можна глядзець праз дадатак у ТГ. Проста націсні на кнопку знізу.",
                'settings_title' => '<b>Налады:</b>',
                'notify_item' => 'Апавяшчаць пра пары',
                'pick_lang' => "Выберите язык / Абярыце мову",
                'btn_lang_ru' => '🇷🇺 Русский',
                'btn_lang_by' => '🇧🇾 Беларуская',
                'btn_skip' => 'Прапусціць пакуль што',
                'notify_ask' => '<b>Апявашчэнні пра пары:</b>',
                'notify_on' => 'Апавяшчэнні ўключаны',
                'notify_off' => 'Апавяшчэнні адключаны',
                'notify_hint' => 'Бот адправіць паведамленне за 15 хвілін да першай пары, у канцы кожнай пары — пра наступную, а пасля апошняй пары скажа, што пара дадому.',
                'notify_saved_on' => 'Апавяшчэнні пра пары ўключаны',
                'notify_saved_off' => 'Апавяшчэнні пра пары адлкючаны',
                'back' => 'Назад',
                'btn_refresh' => 'Аднавіць',
                'notif_break_min' => "<b>Зараз перапынак %s.</b>\n\n",
                'notif_break_plain' => "<b>Зараз перапынак.</b>\n\n",
                'notif_next_after' => "Потым з %s па %s %s.",
                'notif_room' => 'у аўдыторыі %s',
                'notif_first_soon' => "<b>Пара пачнецца праз 15 хвілін:</b>\n\n%s",
                'notif_home' => "Дадооому",
                'link_done' => 'Бот і дадатак паспяхова злучаны.',
                'link_bad' => 'Не атрымалася злучыць ТГ з ботам. Адкрый дадатак у Telegram і паспрабуй зноў.',
                'only_menu' => "Абяры раздзел ніжэй ",
            ],
        ];
        return $strings[$lang] ?? $strings['ru'];
    }

    public function settingsText($lang, $notify) {
        $t = $this->strings($lang);
        $text = $t['settings_title'] . "\n\n" . ($notify ? $t['notify_on'] : $t['notify_off']);
        if (!$notify) $text .= "\n\n" . $t['notify_hint'];
        return $text;
    }

    public function onboardingNotifyText($lang, $notify) {
        $t = $this->strings($lang);
        $text = $t['notify_ask'] . "\n\n" . ($notify ? $t['notify_on'] : $t['notify_off']);
        if (!$notify) $text .= "\n\n" . $t['notify_hint'];
        return $text;
    }

    public function handleUpdate($update) {
        $data = $this->storage->load();
        if (!empty($this->storage->loadError())) {
            $this->log('storage: ' . $this->storage->loadError());
        }
        $globalLang = $data['settings']['lang'] ?? 'ru';

        $chatId = null;
        if (isset($update['message']['chat']['id'])) $chatId = (string)$update['message']['chat']['id'];
        if (isset($update['callback_query']['message']['chat']['id'])) $chatId = (string)$update['callback_query']['message']['chat']['id'];

        $cbId = isset($update['callback_query']['id']) ? (string)$update['callback_query']['id'] : '';

        if ($chatId === null) {
            if ($cbId !== '') $this->answerCallback($cbId);
            return;
        }

        try {
            $this->handleUpdateInner($update, $data, $globalLang, $chatId);
        } catch (Throwable $e) {
            $this->log('update error: ' . $e->getMessage());
            if ($cbId !== '') $this->answerCallback($cbId);
        }

        if ($cbId !== '') $this->answerCallbackIfNeeded($cbId);
    }

    public function inboxAdd(&$data, $chatId, $text, $dir = 'in') {
        $text = trim((string)$text);
        if ($text === '') return false;
        $chatId = (string)$chatId;
        if (!isset($data['inbox']) || !is_array($data['inbox'])) $data['inbox'] = [];
        if (!isset($data['inbox'][$chatId]) || !is_array($data['inbox'][$chatId])) $data['inbox'][$chatId] = [];
        $data['inbox'][$chatId][] = [
            'dir' => $dir === 'out' ? 'out' : 'in',
            'text' => function_exists('mb_substr') ? mb_substr($text, 0, 1500) : substr($text, 0, 1500),
            'date' => date('c'),
            'read' => $dir === 'out' ? 1 : 0,
        ];
        if (count($data['inbox'][$chatId]) > 30) {
            $data['inbox'][$chatId] = array_slice($data['inbox'][$chatId], -30);
        }
        return $this->save($data);
    }

    private function linkBrowser(&$data, $code, $chatId, $lang) {
        $t = $this->strings($lang);
        $webId = tg_webapp_link_chat($data, $code, $chatId);
        if ($webId === '') {
            $this->sendMessage($chatId, $t['link_bad'], $this->getMainKeyboard($lang));
            return;
        }

        if (!empty($data['users'][$chatId]['step'])) unset($data['users'][$chatId]['step']);
        $this->save($data);

        $text = $t['link_done'];
        if (!empty($data['users'][$chatId]['notify'])) $text .= "\n\n" . $t['notify_on'];
        $this->sendMessage($chatId, $text, $this->getMainKeyboard($lang));
    }

    private function handleUpdateInner($update, &$data, $globalLang, $chatId) {
        if (!isset($data['users'][$chatId]) || !is_array($data['users'][$chatId])) {
            $data['users'][$chatId] = ['lang' => $globalLang, 'notify' => false, 'step' => 'lang'];
            $this->save($data);
        }

        $user = $data['users'][$chatId];
        $lang = in_array($user['lang'] ?? '', ['ru', 'by'], true) ? $user['lang'] : $globalLang;
        $notify = !empty($user['notify']);
        $step = (string)($user['step'] ?? '');

        if (isset($update['message'])) {
            $text = trim((string)($update['message']['text'] ?? ''));
            $isStart = strpos($text, '/start') === 0;
            $isSettings = strpos($text, '/settings') === 0;

            if ($isStart) {
                $code = trim((string)preg_replace('/^\/start(@[A-Za-z0-9_]+)?/', '', $text));
                if ($code !== '' && tg_webapp_id_from_code($code) !== '') {
                    $this->linkBrowser($data, $code, $chatId, $lang);
                    return;
                }
            }

            if ($isStart || $isSettings) {
                if ($isSettings) {
                    $this->sendMessage($chatId, $this->settingsText($lang, $notify), $this->getSettingsKeyboard($lang, $notify));
                    return;
                }
                if ($step === 'lang') {
                    $this->sendMessage($chatId, $this->strings($lang)['pick_lang'], $this->getLangKeyboard());
                    return;
                }
                if ($step === 'notify') {
                    $this->sendMessage($chatId, $this->onboardingNotifyText($lang, $notify), $this->getOnboardingNotifyKeyboard($lang, $notify));
                    return;
                }
                $this->sendMessage($chatId, $this->strings($lang)['welcome'], $this->getMainKeyboard($lang));
                return;
            }

            $t = $this->strings($lang);
            if ($text !== '') $this->inboxAdd($data, $chatId, $text, 'in');
            if ($step === 'lang') {
                $this->sendMessage($chatId, $t['pick_lang'], $this->getLangKeyboard());
                return;
            }
            if ($step === 'notify') {
                $this->sendMessage($chatId, $this->onboardingNotifyText($lang, $notify), $this->getOnboardingNotifyKeyboard($lang, $notify));
                return;
            }
            if ($text !== '') {
                $this->sendMessage($chatId, $t['only_menu'], $this->getMainKeyboard($lang));
            }
            return;
        }

        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $cbId = $cb['id'];
            $action = (string)($cb['data'] ?? '');

            if ($action === 'lang_ru' || $action === 'lang_by') {
                $lang = $action === 'lang_ru' ? 'ru' : 'by';
                $this->answerCallback($cbId);
                $data['users'][$chatId]['lang'] = $lang;
                $data['users'][$chatId]['step'] = 'notify';
                $this->save($data);
                $this->sendMessage($chatId, $this->onboardingNotifyText($lang, $notify), $this->getOnboardingNotifyKeyboard($lang, $notify));
                return;
            }

            if ($action === 'notif_skip') {
                $this->answerCallback($cbId);
                if ($step === 'notify') {
                    unset($data['users'][$chatId]['step']);
                    $this->save($data);
                }
                $this->sendMessage($chatId, $this->strings($lang)['welcome'], $this->getMainKeyboard($lang));
                return;
            }

            if ($action === 'settings') {
                $this->answerCallback($cbId);
                $this->sendMessage($chatId, $this->settingsText($lang, $notify), $this->getSettingsKeyboard($lang, $notify));
                return;
            }

            if ($action === 'notif_toggle') {
                $notify = empty($user['notify']);
                $t = $this->strings($lang);
                $this->answerCallback($cbId, $notify ? $t['notify_saved_on'] : $t['notify_saved_off']);
                $data['users'][$chatId]['notify'] = $notify;
                if ($step === 'notify') unset($data['users'][$chatId]['step']);
                $this->save($data);
                if ($step === 'notify') {
                    $this->sendMessage($chatId, $t['welcome'], $this->getMainKeyboard($lang));
                } else {
                    $this->sendMessage($chatId, $this->settingsText($lang, $notify), $this->getSettingsKeyboard($lang, $notify));
                }
                return;
            }

            if ($action === 'back') {
                $this->answerCallback($cbId);
                $this->sendMessage($chatId, $this->strings($lang)['welcome'], $this->getMainKeyboard($lang));
                return;
            }

            $this->answerCallback($cbId, $this->strings($lang)['btn_refresh']);
            $this->sendMessage($chatId, $this->strings($lang)['welcome'], $this->getMainKeyboard($lang));
        }
    }
}