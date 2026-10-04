<?php
class Storage {
    private $file;
    private $key;
    private $lastError = '';
    private $loadError = '';

    public function __construct($file, $key) {
        $this->file = $file;
        $this->key = hash('sha256', $key, true);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
    }

    private function defaultData() {
        return [
            'schedule' => [],
            'works' => [],
            'homework' => [],
            'events' => [],
            'users' => [],
            'subscribers' => [],
            'settings' => [
                'notifications' => true,
                'lang' => 'ru',
            ],
        ];
    }

    public function file() {
        return $this->file;
    }

    public function lastError() {
        return $this->lastError !== '' ? $this->lastError : $this->loadError;
    }

    public function loadError() {
        return $this->loadError;
    }

    public function writable() {
        $dir = dirname($this->file);
        return is_dir($dir) && is_writable($dir) && (!file_exists($this->file) || is_writable($this->file));
    }

    public function load() {
        $this->loadError = '';

        if (!file_exists($this->file)) {
            return $this->defaultData();
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            $this->loadError = 'Не удалось прочитать файл данных (нет прав на чтение)';
            return $this->defaultData();
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) < 17) {
            $this->loadError = 'Файл данных повреждён, загружены значения по умолчанию';
            return $this->defaultData();
        }
        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            $this->loadError = 'Не удалось расшифровать данные (неверный encryption_key), загружены значения по умолчанию';
            return $this->defaultData();
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            $this->loadError = 'Файл данных повреждён, загружены значения по умолчанию';
            return $this->defaultData();
        }
        return array_merge($this->defaultData(), $data);
    }

    public function save($data) {
        $this->lastError = '';

        $plain = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($plain === false) {
            $this->lastError = 'Не удалось подготовить данные к сохранению';
            return false;
        }
        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            $this->lastError = 'Ошибка шифрования (проверьте расширение openssl)';
            return false;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $tmp = $this->file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, base64_encode($iv . $cipher), LOCK_EX) === false) {
            @unlink($tmp);
            $this->lastError = 'Нет прав на запись в папку data';
            return false;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            $this->lastError = 'Не удалось заменить файл данных';
            return false;
        }
        return true;
    }
}
