#!/usr/bin/env python3
import json
import tempfile
from copy import copy, deepcopy
from pathlib import Path
import openpyxl
from content_export import export, sentences, ROOT

sample = openpyxl.load_workbook(ROOT / 'resources/content-generation/template.xlsx')['サンプル']
result = dict(title='土地に合う活用を選ぶ', related_keywords=['土地の適性'], audience='土地所有者',
              lead='旧形式の完成原稿。執筆指示として出力しない。', assumptions=[], limitations=[], llmo_analysis='',
              sections=[dict(level='H2',heading='土地の条件を確認する',word_count=200,
                             points=['接道を確認する。需要も調べる。','・人口\n・交通量'],format='',
                             sources=['https://example.com/a','https://example.com/b']),
                        dict(level='H3',heading='失敗要因を調べる',word_count=300,points=['管理費を確認する。'],format='',sources=['https://example.com/c'])],
              competitors=[], fanout=[])
keywords = ['田舎 土地活用 成功例', '田舎 土地活用 成功例', 'A/B:*?[]', '長いテーマ'*10, 'テンプレ']
items = [dict(position=i+1, keyword=kw, status='completed', result=deepcopy(result), evidence={}) for i,kw in enumerate(keywords)]
items[1]['result']['lead_guidance'] = ['読者の不安に共感し、成功条件を示す。','土地の適性を判断できるメリットを伝える。']
with tempfile.TemporaryDirectory() as directory:
    output = Path(directory)/'test.xlsx'
    export(dict(job={'notes':'判断基準を重視する。'},items=items),output)
    wb = openpyxl.load_workbook(output)
    assert len(wb.worksheets) == 5
    assert wb.sheetnames[0] == keywords[0] and wb.sheetnames[1].endswith('(2)')
    assert len(set(n.casefold() for n in wb.sheetnames)) == 5
    assert all(len(n)<=31 and not any(c in n for c in '\\/*?:[]') for n in wb.sheetnames)
    for s in wb:
        assert s.freeze_panes is None
        assert s['C10'].value == 'リード文' and s['B11'].value == '概要'
        assert s['C11'].value and s['C12'].value and s['C13'].value is None
        assert '旧形式' not in s['C11'].value
        assert [s[f'C{r}'].value for r in range(15,19)] == ['接道を確認する。','需要も調べる。','・人口','・交通量']
        assert s['C19'].value is None and s['B20'].value == '小見出し(H3)'
        assert s['E14'].value == '参照URL' and s['E15'].value == 'https://example.com/a' and s['E16'].value == 'https://example.com/b'
        assert s['E20'].value == '参照URL' and s['E21'].value == 'https://example.com/c'
        assert s['F14'].value == 'クライアント様コメント' and s['F20'].value is None
        for cell, ref in [('C10','C10'),('C11','C11'),('E14','E14'),('E15','E15'),('E20','E19'),('C20','C19')]:
            for attr in ['font','fill','border','alignment','number_format']:
                assert copy(getattr(s[cell],attr)) == copy(getattr(sample[ref],attr)), (cell,attr)
        assert s.row_dimensions[10].height == sample.row_dimensions[10].height
        assert s.column_dimensions['E'].width == sample.column_dimensions['E'].width
        assert '判断基準' in s['C2'].comment.text
        assert all('\n' not in str(s[f'{c}{r}'].value or '') for c in ['C','E'] for r in range(10,24))
    assert wb.worksheets[1]['C11'].value == items[1]['result']['lead_guidance'][0]
assert sentences('「失敗した？」と聞く。条件を調べる。') == ['「失敗した？」と聞く。','条件を調べる。']
print('PASS: 5 themes/5 sheets, names, lead rows, sentence rows, reference rows, sample styles, no frozen panes')
