<?php

declare(strict_types=1);

/**
 * NOTE: This file used to implement "domain" matching.
 * Requirement changed to "target text" matching:
 * - match the given target text as-is (substring)
 * - case-insensitive
 * - if Slack link format <url|label>, use label as the target
 */

function normalize_target_text(string $s): string {
    $s = trim($s);
    if ($s === '') return '';

    // Slack link format: <http://a.com|a.com>
    if (preg_match('~^<[^>|]+\|([^>]+)>$~', $s, $m)) {
        return trim((string)$m[1]);
    }
    return $s;
}

function _ci(string $s): string {
    // casefold-ish (UTF-8). mb_strtolower handles Japanese safely.
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

function text_contains_target(string $text, string $target): bool {
    $target = normalize_target_text($target);
    if ($target === '') return false;
    return str_contains(_ci($text), _ci($target));
}

/**
 * Return matched targets (normalized for Slack link format) preserving order & unique.
 */
function detect_hits(string $text, array $targets): array {
    $hits = [];
    foreach ($targets as $t) {
        $norm = normalize_target_text((string)$t);
        if ($norm === '') continue;
        if (text_contains_target($text, $norm)) {
            $hits[] = $norm;
        }
    }
    return array_values(array_unique($hits));
}
