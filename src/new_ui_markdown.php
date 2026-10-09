<?php

declare(strict_types=1);

// Render basic answer formatting while treating all model-supplied HTML as text.
function nu_inline(string $text): string {
    $pattern = '~\*\*[^\n]+?\*\*|`[^`\n]+`|\[[^\]\n]+\]\(https?://[^\s)]+\)~u';
    preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    $out = ''; $offset = 0;
    foreach ($matches[0] as [$token, $position]) {
        $out .= h(substr($text, $offset, $position - $offset));
        if (str_starts_with($token, '**')) $out .= '<strong>' . h(substr($token, 2, -2)) . '</strong>';
        elseif (str_starts_with($token, '`')) $out .= '<code>' . h(substr($token, 1, -1)) . '</code>';
        elseif (preg_match('~^\[([^\]]+)\]\((.+)\)$~s', $token, $link) && filter_var($link[2], FILTER_VALIDATE_URL)) {
            $out .= '<a href="' . h($link[2]) . '" target="_blank" rel="noopener noreferrer">' . h($link[1]) . '</a>';
        } else $out .= h($token);
        $offset = $position + strlen($token);
    }
    return nl2br($out . h(substr($text, $offset)));
}

function nu_answer_html(string $text): string {
    $out = ''; $paragraph = []; $list = null; $code = []; $fenced = false;
    $flush = static function() use (&$out, &$paragraph, &$list): void {
        if ($paragraph) { $out .= '<p>' . nu_inline(implode("\n", $paragraph)) . '</p>'; $paragraph = []; }
        if ($list) { $out .= '</' . $list . '>'; $list = null; }
    };
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '```')) {
            if ($fenced) { $out .= '<pre><code>' . h(implode("\n", $code)) . '</code></pre>'; $code = []; }
            else $flush();
            $fenced = !$fenced; continue;
        }
        if ($fenced) { $code[] = $line; continue; }
        if ($trimmed === '') { $flush(); continue; }
        if (preg_match('/^#{1,6}\s+(.+)$/u', $trimmed, $match)) {
            $flush(); $out .= '<h3>' . nu_inline($match[1]) . '</h3>'; continue;
        }
        if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $trimmed)) { $flush(); $out .= '<hr>'; continue; }
        if (preg_match('/^(?:[-*+]\s+|(\d+)\.\s+)(.+)$/u', $trimmed, $match)) {
            $kind = $match[1] !== '' ? 'ol' : 'ul';
            if ($paragraph || ($list && $list !== $kind)) $flush();
            if (!$list) { $list = $kind; $out .= '<' . $kind . ($kind === 'ol' ? ' start="' . (int)$match[1] . '"' : '') . '>'; }
            $out .= '<li>' . nu_inline($match[2]) . '</li>'; continue;
        }
        if (str_starts_with($trimmed, '> ')) { $flush(); $out .= '<blockquote>' . nu_inline(substr($trimmed, 2)) . '</blockquote>'; continue; }
        if ($list) $flush();
        $paragraph[] = $line;
    }
    if ($fenced) $out .= '<pre><code>' . h(implode("\n", $code)) . '</code></pre>';
    $flush();
    return $out;
}
