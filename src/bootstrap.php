<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

env_bootstrap(__DIR__ . '/../.env');

// sessions
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/html.php';
require_once __DIR__ . '/domain_match.php';
require_once __DIR__ . '/openai.php';
