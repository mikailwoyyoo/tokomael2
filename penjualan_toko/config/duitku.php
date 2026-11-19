<?php

$localConfigPath = __DIR__ . '/duitku.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig)) {
    throw new RuntimeException('config/duitku.local.php harus mengembalikan array konfigurasi.');
}

$readConfig = static function (string $environmentName, string $configName, string $default = '') use ($localConfig): string {
    $value = getenv($environmentName);
    if ($value !== false && $value !== '') {
        return trim($value);
    }

    return trim((string) ($localConfig[$configName] ?? $default));
};

$environment = strtolower($readConfig('DUITKU_ENVIRONMENT', 'environment', 'sandbox'));
if (!in_array($environment, ['sandbox', 'production'], true)) {
    throw new RuntimeException('DUITKU_ENVIRONMENT hanya boleh bernilai sandbox atau production.');
}

return [
    'merchant_code' => $readConfig('DUITKU_MERCHANT_CODE', 'merchant_code'),
    'api_key' => $readConfig('DUITKU_API_KEY', 'api_key'),
    'environment' => $environment,
    'base_url' => rtrim($readConfig('DUITKU_BASE_URL', 'base_url'), '/'),
    'expiry_period' => max(10, min(1440, (int) $readConfig('DUITKU_EXPIRY_PERIOD', 'expiry_period', '60'))),
];
