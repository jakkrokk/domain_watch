#!/usr/bin/env python3
"""Fetch a public rewrite target and preserve its article heading order."""
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urldefrag, urlsplit
from lxml import etree, html
from content_metrics import fetch_public


def extract_reference(raw, url):
    tree = html.fromstring(raw)
    title = ' '.join(tree.xpath('//title/text()')).strip()
    original_count = len(tree.xpath('//h2|//h3|//h4|//h5|//h6'))
    for node in tree.xpath('//script|//style|//nav|//header|//footer|//aside|//form|'
                           '//*[@role="navigation"]|//*[@hidden]|//*[@aria-hidden="true"]'):
        if node.getparent() is not None: node.drop_tree()
    for node in list(tree.iter()):
        if not isinstance(node.tag, str): continue
        classes = (node.get('class', '') + ' ' + node.get('id', '')).lower()
        if re.search(r'(^|[\s_-])(modal|mainvisual|breadcrumb)([\s_-]|\d|$)', classes) and node.getparent() is not None:
            node.drop_tree()
    root = tree
    for xpath in ['//*[@itemprop="articleBody"]', '//article', '//main|//*[@role="main"]', '//*[@id="contents"]', '//body']:
        candidates = tree.xpath(xpath)
        if candidates:
            root = max(candidates, key=lambda node: len(node.text_content()))
            break
    headings, lead, current, in_heading = [], [], None, False
    for event, node in etree.iterwalk(root, events=('start','end')):
        if not isinstance(node.tag, str): continue
        heading = node.tag.lower() in ('h2','h3','h4','h5','h6')
        if event == 'start':
            if heading:
                text = ' '.join(''.join(node.itertext()).split())
                if not text: continue
                current = {'level':node.tag.upper(),'heading':text,'id':node.get('id',''),'body':[],'links':[]}
                headings.append(current)
                in_heading = True
            elif not in_heading:
                pieces = current['body'] if current else lead
                if node.tag in ('p','div','li','tr','td','th','br'): pieces.append(' ')
                if node.text: pieces.append(node.text)
            if node.tag == 'a' and current and node.get('href'):
                link = urljoin(url,node.get('href'))
                if urlsplit(link).scheme in ('http','https') and link not in current['links']: current['links'].append(link)
        else:
            if heading: in_heading = False
            if not in_heading and node.tail: (current['body'] if current else lead).append(node.tail)
    if not headings: raise ValueError('No article headings found; client-rendered or inaccessible pages are unsupported')
    if len(headings) > 200: raise ValueError('Too many headings (maximum 200)')
    text_limit = min(8000, 200000 // len(headings))
    for i, section in enumerate(headings,1):
        text = ' '.join(''.join(section.pop('body')).split())
        section.update(source_index=i,text=text[:text_limit],word_count=len(re.sub(r'\s','',text)),text_truncated=len(text)>text_limit)
        section['links'] = section['links'][:10]
    return {'url':urldefrag(url)[0],'title':title or headings[0]['heading'],
            'lead':' '.join(''.join(lead).split())[:4000], 'headings':headings,
            'excluded_heading_count':original_count-len(headings),'fetched_at':datetime.now(timezone.utc).isoformat(),
            'limitations':[f'一部の節の本文は調査用に{text_limit}文字まで抜粋。'] if any(h['text_truncated'] for h in headings) else []}


def fetch_reference(url, output):
    raw, final_url = fetch_public(url)
    reference = extract_reference(raw, final_url)
    reference['requested_url'] = url
    output = Path(output)
    temporary = output.with_suffix('.tmp.json')
    temporary.write_text(json.dumps(reference,ensure_ascii=False,indent=2),encoding='utf-8')
    temporary.replace(output)
    print(json.dumps({'headings':len(reference['headings']),'excluded':reference['excluded_heading_count']}))


if __name__ == '__main__': fetch_reference(sys.argv[1],sys.argv[2])
