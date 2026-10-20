<?php
declare(strict_types=1);
function auth_user(): array { return ['id'=>1]; }
function db_all(string $query, array $values=[]): array { return []; }
function h(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function csrf_token(): string { return 'test-token'; }
function page_header(string $title): void { echo '<!doctype html><html><head><meta charset="utf-8"></head><body>'; }
function page_footer(): void { echo '</body></html>'; }
function render(string $requestedMode): string {
    $_SERVER['REQUEST_METHOD']='GET';
    $_POST=['mode'=>$requestedMode,'reference_url'=>'https://example.com/"<script>unsafe</script>'];
    ob_start(); require __DIR__ . '/../src/content_generation_ui.php'; return ob_get_clean();
}
foreach (['create','rewrite'] as $requestedMode) {
    $html=render($requestedMode);
    $document=new DOMDocument();
    libxml_use_internal_errors(true);$document->loadHTML($html);libxml_clear_errors();
    $xpath=new DOMXPath($document);
    $checked=$xpath->query('//input[@name="mode" and @checked]');
    if ($checked->length!==1||$checked[0]->getAttribute('value')!==$requestedMode) throw new RuntimeException('Wrong selected mode');
    $field=$document->getElementById('cg-reference-field');
    $input=$document->getElementById('cg-reference-url');
    if ($field->hasAttribute('hidden')!==($requestedMode==='create')||$input->hasAttribute('required')!==($requestedMode==='rewrite')) throw new RuntimeException('Reference field requirements do not follow mode');
    if ($input->hasAttribute('disabled')!==($requestedMode==='create')) throw new RuntimeException('Hidden URL must not block new content validation');
    if (str_contains($html,'<script>unsafe</script>')) throw new RuntimeException('Reference URL not escaped');
    if ($requestedMode==='rewrite'&&isset($argv[1])) file_put_contents($argv[1],$html);
}
echo "PASS: create/rewrite selection, conditional reference field, required attribute, escaped retained input\n";
