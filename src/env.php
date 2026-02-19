<?php

declare(strict_types=1);

function env_load(string $path): array {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

function env_get(string $key, ?string $default=null): ?string {
    $v = getenv($key);
    if ($v === false) return $default;
    return $v;
}

function env_bootstrap(string $envPath): void {
    $vars = env_load($envPath);
    foreach ($vars as $k => $v) {
        if (getenv($k) === false) {
            putenv($k.'='.$v);
            $_ENV[$k] = $v;
        }
    }
}
