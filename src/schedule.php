<?php

declare(strict_types=1);

function runner_schedule_hours(): array {
    $raw = env_get('RUN_HOURS', '0,8,16') ?? '0,8,16';
    $hours = [];
    foreach (preg_split('~\s*,\s*~', $raw) ?: [] as $part) {
        if ($part === '' || !preg_match('~^\d{1,2}$~', $part)) continue;
        $hour = (int)$part;
        if ($hour < 0 || $hour > 23) continue;
        $hours[$hour] = true;
    }

    if (!$hours) {
        $hours = [0 => true, 8 => true, 16 => true];
    }

    $out = array_keys($hours);
    sort($out, SORT_NUMERIC);
    return $out;
}

function runner_schedule_label(): string {
    return implode(' / ', array_map(fn(int $h): string => sprintf('%02d:00', $h), runner_schedule_hours()));
}
