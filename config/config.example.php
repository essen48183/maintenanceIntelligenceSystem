<?php
// Copy this file to config/config.php and fill in real values.
// config/config.php is gitignored.

return [
    // Database (MAMP defaults shown — change when migrating to a host)
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 8889,                 // MAMP MySQL port; host migration: 3306
        'socket'   => '/Applications/MAMP/tmp/mysql/mysql.sock', // null when not on MAMP
        'name'     => 'mis',
        'user'     => 'root',
        'password' => 'root',
        'charset'  => 'utf8mb4',
    ],
    // Test database — used by PHPUnit; schema is recreated each test run
    'db_test' => [
        'host'     => '127.0.0.1',
        'port'     => 8889,
        'socket'   => '/Applications/MAMP/tmp/mysql/mysql.sock',
        'name'     => 'mis_test',
        'user'     => 'root',
        'password' => 'root',
        'charset'  => 'utf8mb4',
    ],
    // Anthropic API key — leave empty to run with stub responses
    'anthropic' => [
        'api_key' => '',
        'model'   => 'claude-opus-4-7',
        'max_tokens' => 1024,
    ],
    // Session cookie & auth settings
    'auth' => [
        'session_name'     => 'mis_session',
        'session_lifetime' => 60 * 60 * 8, // 8h shift
        'cookie_secure'    => false,       // true once on HTTPS host
        'cookie_samesite'  => 'Lax',
    ],
    'app' => [
        'name'     => 'Maintenance Intelligence System',
        'env'      => 'local',  // local | staging | production
        'base_url' => 'http://localhost:8888/mis',
    ],
];
