<?php
declare(strict_types=1);

function nu_answer_panels(?array $log, string $providerName, string $date, string $timing, string $emptyText): void {
    echo '<section class="panel answer-panel"><div class="panel-heading section-heading"><div><h2>' . h($providerName) . 'の回答</h2><p>' . ($log ? h($log['run_at']) . ' · ' . h($timing) : h($date)) . '</p></div>';
    if ($log) echo '<div class="copy-control"><button type="button" class="button secondary" data-copy="answer-raw">コピー</button><span class="copy-status" role="status" aria-live="polite"></span></div>';
    echo '</div>';
    if (!$log) {
        echo '<div class="empty-state"><span class="empty-icon">≡</span><h3>保存された回答がありません</h3><p>' . h($emptyText) . '</p></div></section>';
        return;
    }
    [$answer,$info] = nu_split_raw($log['response_text']);
    echo '<div class="answer-text">' . nu_answer_html($answer) . '</div><textarea id="answer-raw" class="copy-source" hidden>' . h($log['response_text']) . '</textarea><div class="answer-bottom"><span>コピーには回答全文と取得済みの検索情報が含まれます。</span></div></section><section class="panel search-panel"><div class="panel-heading"><h2>検索・参照情報</h2><p>回答本文とは別にAPIから返された情報です。</p></div>';
    if (!array_filter($info) && empty($log['search_suggestions'])) echo '<div class="empty-inline">この回答の検索情報は取得されていません。</div>';
    foreach ($info as $label=>$values) {
        if (!$values) continue;
        echo '<details class="search-section"' . ($label === '検索クエリ' ? ' open' : '') . '><summary>' . h($label) . '<span class="badge">' . count($values) . '件</span></summary><ul>';
        foreach ($values as $value) {
            $value = trim($value);
            echo '<li>';
            if ($label !== '検索クエリ' && preg_match('~^https?://~i',$value) && filter_var($value,FILTER_VALIDATE_URL)) echo '<a href="' . h($value) . '" target="_blank" rel="noopener noreferrer">' . h($value) . ' ↗</a>';
            else echo h($value);
            echo '</li>';
        }
        echo '</ul></details>';
    }
    if (!empty($log['search_suggestions'])) echo '<div class="search-suggestions"><h3>Google検索候補</h3><p class="muted">Geminiが検索時に返した候補リンクです。参照URLにはGoogle経由のリンクが含まれます。</p><iframe title="Google検索の候補" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="' . h($log['search_suggestions']) . '"></iframe></div>';
    echo '</section>';
}
