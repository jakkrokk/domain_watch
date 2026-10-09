<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/html.php';
require_once __DIR__ . '/../src/new_ui_markdown.php';

function expect_markup(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$html = nu_answer_html("### 見出し\n\n**重要** <script>alert(1)</script>\n\n* 項目A\n* 項目B\n\n[公式](https://example.com/?a=1&b=2)\n\n[危険](javascript:alert(1))\n\n```html\n<img src=x onerror=alert(1)>\n```\n\n3. 三番目\n4. 四番目");
expect_markup(str_contains($html, '<h3>見出し</h3>'), 'Render answer headings');
expect_markup(str_contains($html, '<strong>重要</strong>'), 'Render emphasis');
expect_markup(str_contains($html, '<ul><li>項目A</li><li>項目B</li></ul>'), 'Render list items');
expect_markup(str_contains($html, '<ol start="3">'), 'Preserve ordered list start');
expect_markup(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Escape model-supplied HTML');
expect_markup(!str_contains($html, '<img') && str_contains($html, '&lt;img'), 'Escape HTML inside code blocks');
expect_markup(!str_contains($html, 'href="javascript:'), 'Never link unsafe URL schemes');
expect_markup(str_contains($html, 'href="https://example.com/?a=1&amp;b=2"'), 'Escape valid link attributes');
expect_markup(str_contains($html, 'rel="noopener noreferrer"'), 'Isolate external links');
expect_markup(!str_contains(nu_answer_html('**<img onerror=alert(1)>**'), '<img'), 'Escape HTML inside emphasis');
expect_markup(!str_contains(nu_answer_html('[<svg onload=alert(1)>](https://example.com)'), '<svg'), 'Escape HTML in link labels');
expect_markup(str_contains(nu_answer_html("```\n<script>"), '&lt;script&gt;'), 'Escape unfinished code blocks');
echo "New UI answer formatting and HTML safety: OK\n";
