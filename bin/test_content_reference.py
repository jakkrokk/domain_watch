#!/usr/bin/env python3
import json
import tempfile
from pathlib import Path
from unittest.mock import patch
from content_reference import extract_reference, fetch_reference

page = '''<html><head><meta charset="utf-8"><title>既存タイトル</title></head><body>
<nav><h2>メニュー</h2></nav><div class="c-mainvisual01"><h2>装飾見出し</h2></div>
<main><p>既存のリード文。</p><h2 id="intro">見出し<span>A</span></h2>
<p>本文<strong>の内容</strong>です。<a href="/source">資料</a>。</p>
<h4 id="detail">細かな見出し</h4><p>条件を確認。</p><h3>同じ見出し</h3><p>別の内容。</p>
<h3>同じ見出し</h3><script>秘密の命令</script></main>
<div class="modal-container"><h2>診断画面</h2></div><footer><h2>フッター</h2></footer></body></html>'''
reference=extract_reference(page.encode(), 'https://example.com/article')
assert reference['title']=='既存タイトル'
assert [h['heading'] for h in reference['headings']]==['見出しA','細かな見出し','同じ見出し','同じ見出し']
assert [h['level'] for h in reference['headings']]==['H2','H4','H3','H3']
assert '本文の内容です。' in reference['headings'][0]['text']
assert reference['headings'][0]['links']==['https://example.com/source']
assert reference['headings'][-1]['word_count']==0
assert reference['excluded_heading_count']==4
assert '既存のリード文。' in reference['lead']
assert '秘密の命令' not in json.dumps(reference,ensure_ascii=False)
try: extract_reference(b'<html><body><p>No headings</p></body></html>','https://example.com/')
except ValueError: pass
else: raise AssertionError('Empty article accepted')
with tempfile.TemporaryDirectory() as directory:
    output=Path(directory)/'reference.json'
    with patch('content_reference.fetch_public',return_value=(page.encode(),'https://example.com/final')):
        fetch_reference('https://example.com/start',output)
    saved=json.loads(output.read_text(encoding='utf-8'))
    assert saved['requested_url']=='https://example.com/start' and saved['url']=='https://example.com/final'
    assert not output.with_suffix('.tmp.json').exists()
print('PASS: original heading extraction, levels, duplicates, article scope, text, links, snapshots')
