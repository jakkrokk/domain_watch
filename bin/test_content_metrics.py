#!/usr/bin/env python3
import tempfile
from pathlib import Path
from unittest.mock import patch
from content_metrics import article_text, fetch_public, measure

body = '田舎の土地活用では需要と接道条件を確認します。' * 30
page = '<html><body><nav>除外するメニュー</nav><article><h1>土地の判断</h1><p>'+body+'</p></article><footer>除外するフッター</footer></body></html>'
text = article_text(page,'https://example.com/article')
assert body in text and '除外する' not in text
with patch('content_metrics.trafilatura.extract',return_value=None):
    text = article_text(page,'https://example.com/article')
    assert body in text and '除外する' not in text
with tempfile.TemporaryDirectory() as tmp:
    with patch('content_metrics.fetch_public',return_value=(page.encode(),'https://example.com/article')) as fetch:
        result = measure('https://example.com/article',Path(tmp))
        assert result['status']=='measured' and result['word_count'] >= len(body)
        assert measure('https://example.com/article',Path(tmp))==result
        assert fetch.call_count==1
    with patch('content_metrics.fetch_public',side_effect=ValueError('HTTP 403')):
        result=measure('https://example.com/blocked',Path(tmp))
        assert result['word_count']==0 and result['error']=='HTTP 403'
for url in ['http://127.0.0.1/','http://169.254.169.254/','file:///etc/passwd','http://example.com:8080/']:
    try: fetch_public(url)
    except ValueError: pass
    else: raise AssertionError(url)
print('PASS article extraction, fallback, measured cache, unavailable handling, private URL rejection')
