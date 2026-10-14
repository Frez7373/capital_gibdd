<?php
declare(strict_types=1);

$defaults = [
    'site_name' => 'АвтоКонтроль',
    'site_subtitle' => 'Реестр транспорта · ГИБДД Красноград',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'autocontrol',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    // Укажите URL установленного банка до /api.php, например https://bank.example/api.php.
    'bank' => [
        'api_url' => getenv('BANK_API_URL') ?: 'http://26.155.57.105:7777/api.php',
        'api_key' => getenv('BANK_API_KEY') ?: '',
        'timeout' => 12,
    ],
];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $defaults = array_replace_recursive($defaults, $local);
    }
}
return $defaults;
