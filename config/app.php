<?php
return [
    'name' => 'TMS AI Router',
    'version' => '1.1.2',
    'timezone' => 'Asia/Ho_Chi_Minh',
    'db_path' => dirname(__DIR__) . '/storage/ai-router.sqlite3',
    'master_key_path' => dirname(__DIR__) . '/storage/secure/master.key',
    'session_name' => 'TMSAIRSESSID',
    'request_timeout' => 120,
    'connect_timeout' => 15,
    'max_body_bytes' => 4 * 1024 * 1024,
];
