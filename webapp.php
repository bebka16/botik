<?php
function tg_webapp_user($botToken, $initData, $maxAge = 0, &$reason = null) {
    $reason = '';
    $initData = trim((string)$initData);
    if ($initData === '') { $reason = 'empty'; return null; }
    if ((string)$botToken === '') { $reason = 'no_token'; return null; }

    $params = [];
    parse_str($initData, $params);
    if (!is_array($params)) { $reason = 'parse'; return null; }

    $hash = (string)($params['hash'] ?? '');
    unset($params['hash'], $params['signature']);
    if ($hash === '') { $reason = 'no_hash'; return null; }
    if (!isset($params['user'])) { $reason = 'no_user'; return null; }

    ksort($params);
    $checkParts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) { $reason = 'array_value'; return null; }
        $checkParts[] = $key . '=' . $value;
    }

    $secret = hash_hmac('sha256', 'WebAppData', (string)$botToken, true);
    if (!hash_equals(hash_hmac('sha256', implode("\n", $checkParts), $secret), $hash)) { $reason = 'hash'; return null; }

    $authDate = (int)($params['auth_date'] ?? 0);
    if ($authDate <= 0 || $authDate > time() + 60) { $reason = 'auth_date'; return null; }
    if ($maxAge > 0 && (time() - $authDate) > $maxAge) { $reason = 'expired'; return null; }

    $user = json_decode((string)$params['user'], true);
    if (!is_array($user) || empty($user['id'])) { $reason = 'bad_user'; return null; }

    return $user;
}

function tg_webapp_client_id($clientId) {
    $clientId = trim((string)$clientId);
    return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $clientId) ? $clientId : '';
}

function tg_webapp_default_lang($data) {
    $lang = $data['settings']['lang'] ?? 'ru';
    return in_array($lang, ['ru', 'by'], true) ? $lang : 'ru';
}

function tg_webapp_aliases($user) {
    $out = [];
    if (!is_array($user)) return $out;
    foreach ((array)($user['aliases'] ?? []) as $alias) {
        $alias = trim((string)$alias);
        if ($alias !== '') $out[$alias] = true;
    }
    return $out;
}

function tg_webapp_alias_owner($data, $alias) {
    if ($alias === '') return '';
    foreach ($data['users'] ?? [] as $id => $user) {
        if (!preg_match('/^-?[0-9]+$/', (string)$id)) continue;
        if (!is_array($user)) continue;
        $aliases = tg_webapp_aliases($user);
        if (isset($aliases[$alias])) return (string)$id;
    }
    return '';
}

function tg_webapp_merge(&$data, $fromId, $toId) {
    $fromId = (string)$fromId;
    $toId = (string)$toId;
    if ($fromId === $toId) return;

    if (!isset($data['users'][$fromId]) || !is_array($data['users'][$fromId])) {
        unset($data['users'][$fromId]);
        return;
    }

    $src = $data['users'][$fromId];
    $dst = (isset($data['users'][$toId]) && is_array($data['users'][$toId])) ? $data['users'][$toId] : [];

    $aliases = tg_webapp_aliases($src);
    foreach (tg_webapp_aliases($dst) as $alias => $_) $aliases[$alias] = true;

    $dstLang = in_array($dst['lang'] ?? '', ['ru', 'by'], true) ? $dst['lang'] : '';
    $srcLang = in_array($src['lang'] ?? '', ['ru', 'by'], true) ? $src['lang'] : '';
    $dstTheme = in_array($dst['theme'] ?? '', ['dark', 'light'], true) ? $dst['theme'] : '';
    $srcTheme = in_array($src['theme'] ?? '', ['dark', 'light'], true) ? $src['theme'] : '';

    $merged = $dst;
    $merged['lang'] = $dstLang !== '' ? $dstLang : ($srcLang !== '' ? $srcLang : tg_webapp_default_lang($data));
    $merged['notify'] = !empty($dst['notify']) || !empty($src['notify']);
    $merged['theme'] = $dstTheme !== '' ? $dstTheme : $srcTheme;
    if ($aliases) $merged['aliases'] = array_keys($aliases);

    $data['users'][$toId] = $merged;
    unset($data['users'][$fromId]);
}

function tg_webapp_resolve(&$data, $botToken, $initData, $clientId, &$reason = null) {
    $clientKey = tg_webapp_client_id($clientId);
    $webId = $clientKey !== '' ? 'web_' . $clientKey : '';
    $tgUser = tg_webapp_user($botToken, $initData, 0, $reason);

    if ($tgUser !== null) {
        $id = (string)$tgUser['id'];

        if (!isset($data['users'][$id]) || !is_array($data['users'][$id])) {
            $data['users'][$id] = ['lang' => tg_webapp_default_lang($data), 'notify' => false, 'theme' => ''];
        }
        if ($webId !== '' && $webId !== $id) {
            if (isset($data['users'][$webId]) && is_array($data['users'][$webId])) {
                tg_webapp_merge($data, $webId, $id);
            }
            $aliases = tg_webapp_aliases($data['users'][$id]);
            $aliases[$webId] = true;
            $data['users'][$id]['aliases'] = array_keys($aliases);
            foreach (array_keys($aliases) as $alias) {
                if ($alias !== $webId && isset($data['users'][$alias]) && is_array($data['users'][$alias])) {
                    tg_webapp_merge($data, $alias, $id);
                }
            }
        }
        return ['id' => $id, 'mode' => 'tg'];
    }

    if ($webId === '') return null;

    $owner = tg_webapp_alias_owner($data, $webId);
    if ($owner !== '') {
        if (isset($data['users'][$webId]) && is_array($data['users'][$webId])) {
            tg_webapp_merge($data, $webId, $owner);
        }
        return ['id' => $owner, 'mode' => 'tg'];
    }

    return ['id' => $webId, 'mode' => 'web'];
}

function tg_webapp_code_prefix() {
    return 'web_';
}

function tg_webapp_id_from_code($code) {
    $code = trim((string)$code);
    if (strpos($code, tg_webapp_code_prefix()) === 0) $code = substr($code, strlen(tg_webapp_code_prefix()));
    if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $code)) return '';
    return tg_webapp_code_prefix() . $code;
}

function tg_webapp_link_chat(&$data, $code, $chatId) {
    $webId = tg_webapp_id_from_code($code);
    $chatId = (string)$chatId;
    if ($webId === '' || $chatId === '' || !preg_match('/^-?[0-9]+$/', $chatId)) return '';

    if (!isset($data['users'][$chatId]) || !is_array($data['users'][$chatId])) {
        $data['users'][$chatId] = ['lang' => tg_webapp_default_lang($data), 'notify' => false, 'theme' => ''];
    }
    if (isset($data['users'][$webId]) && is_array($data['users'][$webId])) {
        tg_webapp_merge($data, $webId, $chatId);
    }
    $aliases = tg_webapp_aliases($data['users'][$chatId]);
    $aliases[$webId] = true;
    $data['users'][$chatId]['aliases'] = array_keys($aliases);
    foreach (array_keys($aliases) as $alias) {
        if ($alias !== $webId && isset($data['users'][$alias]) && is_array($data['users'][$alias])) {
            tg_webapp_merge($data, $alias, $chatId);
        }
    }
    return $webId;
}

function tg_webapp_last_error($reason, $minGap = 60) {
    $prev = tg_webapp_state_read();
    if (($prev['reason'] ?? '') === (string)$reason && (time() - (int)($prev['ts'] ?? 0)) < $minGap) return;

    $dir = __DIR__ . '/data';
    $file = $dir . '/webapp_state.json';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($file, json_encode([
        'ts' => time(),
        'reason' => (string)$reason,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function tg_webapp_state_read() {
    $file = __DIR__ . '/data/webapp_state.json';
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $state = json_decode($raw, true);
    return is_array($state) ? $state : [];
}