<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('autocontrol_session');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
require_once __DIR__ . '/functions.php';
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    $setup = htmlspecialchars('Не удалось подключиться к базе данных. Проверьте config.php / config.local.php и импортируйте schema.sql.', ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Настройка АвтоКонтроль</title><body style="margin:0;background:#0b1220;color:#e8eefb;font:16px system-ui;padding:48px"><h1>АвтоКонтроль</h1><p>'.$setup.'</p></body></html>');
}
