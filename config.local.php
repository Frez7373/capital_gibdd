<?php
// Скопируйте файл в config.local.php и заполните реквизиты локальной среды.
// Не публикуйте config.local.php и не помещайте секрет API в JavaScript/HTML.
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'autocontrol',
        'user' => 'root',
        'pass' => '',
    ],
    'bank' => [
        'api_url' => 'http://26.155.57.105:7777/api.php',
        'api_key' => 'cs_live_e0716916c2a1c499bf9bb8d78049a2159f38555de6fda0ea',
        'timeout' => 12,
    ],
];
