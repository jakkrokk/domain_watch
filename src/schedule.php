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

function project_schedule_frequency_options(): array {
    return [
        'daily_3' => '1日3回',
        'daily_1' => '1日1回',
        'weekly_1' => '1週間に1回',
    ];
}

function project_schedule_frequency_normalize(?string $frequency): string {
    $frequency = (string)$frequency;
    return array_key_exists($frequency, project_schedule_frequency_options())
        ? $frequency
        : 'daily_1';
}

function project_schedule_hours_for_date(?string $frequency, DateTimeInterface $date): array {
    $frequency = project_schedule_frequency_normalize($frequency);
    $hours = runner_schedule_hours();
    $primaryHour = $hours[0] ?? 0;

    if ($frequency === 'daily_3') return $hours;
    if ($frequency === 'weekly_1' && (int)$date->format('N') !== 1) return [];
    return [$primaryHour];
}

function project_schedule_is_due(?string $frequency, DateTimeInterface $now): bool {
    return in_array(
        (int)$now->format('G'),
        project_schedule_hours_for_date($frequency, $now),
        true
    );
}

function project_schedule_label(?string $frequency): string {
    $frequency = project_schedule_frequency_normalize($frequency);
    $options = project_schedule_frequency_options();
    $hours = runner_schedule_hours();
    $primaryHour = $hours[0] ?? 0;

    if ($frequency === 'daily_3') {
        return $options[$frequency] . '（' . runner_schedule_label() . '）';
    }
    if ($frequency === 'weekly_1') {
        return $options[$frequency] . '（毎週月曜 ' . sprintf('%02d:00', $primaryHour) . '）';
    }
    return $options[$frequency] . '（' . sprintf('%02d:00', $primaryHour) . '）';
}
