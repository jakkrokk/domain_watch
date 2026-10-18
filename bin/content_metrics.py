#!/usr/bin/env python3
"""Measure extracted article characters; never use an LLM's estimated count."""
import hashlib
import ipaddress
import json
import re
import socket
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urljoin
import trafilatura
from lxml import html as lxml_html


def fetch_public(url):
    for _ in range(6):
        p = urlsplit(url)
        if p.scheme not in ('http', 'https') or not p.hostname or p.username or p.password:
            raise ValueError('Invalid public URL')
        port = p.port or (443 if p.scheme == 'https' else 80)
        if port not in (80, 443): raise ValueError('Unsupported port')
        host = p.hostname.encode('idna').decode('ascii')
        addresses = list(dict.fromkeys(a[4][0] for a in socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)))
        if not addresses or any(not ipaddress.ip_address(a).is_global for a in addresses):
            raise ValueError('Non-public address')
        address = addresses[0]
        if ':' in address: address = '[' + address + ']'
        with tempfile.TemporaryDirectory() as tmp:
            headers, body = Path(tmp)/'headers', Path(tmp)/'body'
            proc = subprocess.run(['curl','--silent','--show-error','--noproxy','*','--proto','=http,https',
                '--resolve',f'{host}:{port}:{address}','--connect-timeout','10','--max-time','25',
                '--max-filesize','10000000','--user-agent','Mozilla/5.0 (compatible; ContentResearch/1.0)',
                '--dump-header',str(headers),'--output',str(body),'--write-out','%{http_code}',url],
                capture_output=True, timeout=30)
            if proc.returncode: raise ValueError('Download failed: curl ' + str(proc.returncode))
            status = int(proc.stdout)
            header = headers.read_text(errors='replace')
            if status in (301,302,303,307,308):
                match = re.search(r'^location:\s*(.+)$',header,re.I|re.M)
                if not match: raise ValueError('Redirect without location')
                url = urljoin(url,match.group(1).strip()); continue
            if status != 200: raise ValueError('HTTP ' + str(status))
            if not re.search(r'content-type:\s*(text/html|application/xhtml)',header,re.I):
                raise ValueError('Not HTML')
            return body.read_bytes(), url
    raise ValueError('Too many redirects')


def article_text(html, url):
    text = trafilatura.extract(html, url=url, include_comments=False, include_tables=True,
                               include_links=False, favor_precision=True) or ''
    if len(re.sub(r'\s','',text)) < 100:
        tree = lxml_html.fromstring(html)
        for node in tree.xpath('//script|//style|//nav|//footer|//aside|//form'):
            node.drop_tree()
        candidates = tree.xpath('//*[@itemprop="articleBody"]|//article')
        bodies = ['\n'.join(node.xpath('.//h1//text()|.//h2//text()|.//h3//text()|.//h4//text()|.//p//text()|.//li//text()|.//td//text()')) for node in candidates]
        text = max(bodies, key=len, default='')
    if len(re.sub(r'\s','',text)) < 100: raise ValueError('Article body not found')
    return text


def measure(url, cache_dir):
    key = hashlib.sha256(url.encode()).hexdigest()
    path = cache_dir / (key + '.json')
    if path.exists():
        cached = json.loads(path.read_text())
        if cached.get('status') == 'measured': return cached
    record = {'url':url,'measured_at':datetime.now(timezone.utc).isoformat(),
              'method':'trafilatura-2.0.0 with semantic article fallback; whitespace excluded'}
    try:
        html, final_url = fetch_public(url)
        text = article_text(html,final_url)
        record.update(status='measured', word_count=len(re.sub(r'\s','',text)), final_url=final_url)
        (cache_dir/(key+'.txt')).write_text(text)
    except Exception as exc:
        record.update(status='unavailable',word_count=0,error=str(exc))
    path.write_text(json.dumps(record,ensure_ascii=False,indent=2))
    return record


def enrich(payload_path):
    payload_path = Path(payload_path)
    payload = json.loads(payload_path.read_text())
    cache = payload_path.parent/'article-metrics'; cache.mkdir(exist_ok=True,mode=0o700)
    competitors = []
    for item in payload['items']:
        if item['status'] != 'completed': continue
        if isinstance(item['result'],str): item['result'] = json.loads(item['result'])
        competitors.extend(item['result']['competitors'][:10])
    urls = list(dict.fromkeys(c['url'] for c in competitors))
    with ThreadPoolExecutor(max_workers=4) as pool:
        records = dict(zip(urls,pool.map(lambda u: measure(u,cache),urls)))
    for competitor in competitors:
        record = records[competitor['url']]
        competitor['word_count'] = record['word_count']
        competitor['measurement'] = record
    temp = payload_path.with_suffix('.metrics.tmp')
    temp.write_text(json.dumps(payload,ensure_ascii=False))
    temp.replace(payload_path)
    print(json.dumps([{'url':u,'count':r['word_count'],'status':r['status']} for u,r in records.items()],ensure_ascii=False))

if __name__ == '__main__': enrich(sys.argv[1])
