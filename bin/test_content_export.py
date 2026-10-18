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
    values = openpyxl.load_workbook(output, data_only=True)
    assert values.worksheets[0]['C4'].value == 700
    assert wb.worksheets[0]['C4'].value == '=SUM(D:D)'
    report = json.loads(output.with_suffix('.coverage.json').read_text())
    assert len(report) == 5 and report[0]['competitor_count'] == 0
    assert report[0]['unavailable']

    assert wb.sheetnames[0] == keywords[0] and wb.sheetnames[1].endswith('(2)')
    assert len(set(n.casefold() for n in wb.sheetnames)) == 5
    assert all(len(n)<=31 and not any(c in n for c in '\\/*?:[]') for n in wb.sheetnames)
    for s in wb:
        assert s.freeze_panes is None
        assert s.sheet_view.showGridLines == sample.sheet_view.showGridLines
        assert s.sheet_view.zoomScale == sample.sheet_view.zoomScale
        for label in ['H9','N9','O10','P10','H23','I24','J24','K24']:
            assert s[label].value == sample[label].value, label
        assert all(s[f'I{r}'].value for r in range(11,21))
        assert all(s[f'I{r}'].value for r in range(25,75))
        assert all(c.comment is None for row in s for c in row)
        assert s['E15'].alignment.horizontal == 'fill'
        assert not any(c.has_style for row in s.iter_rows(min_row=22,max_col=6) for c in row)

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
                if cell == 'E15' and attr == 'alignment': continue
                assert copy(getattr(s[cell],attr)) == copy(getattr(sample[ref],attr)), (cell,attr)
        assert s.row_dimensions[10].height == sample.row_dimensions[10].height
        assert s.column_dimensions['E'].width == sample.column_dimensions['E'].width
        assert s['C2'].comment is None
        assert all('\n' not in str(s[f'{c}{r}'].value or '') for c in ['C','E'] for r in range(10,24))
    assert wb.worksheets[1]['C11'].value == items[1]['result']['lead_guidance'][0]
    filled = deepcopy(items[0])
    filled['result']['related_keywords'] = [f'土地活用 条件{i}' for i in range(50)]
    filled['result']['competitors'] = [dict(rank=0,title=f'確認した競合{i}',url=f'https://example.com/{i}',word_count=0,headings=['土地の条件','注意点']) for i in range(10)]
    filled['result']['lead_guidance'] = ['田舎の土地活用に悩む読者に、土地の条件から判断する方法を示す。','成功条件と失敗要因を比較して自分の土地に合う方法を選べることを伝える。']
    full_output = Path(directory)/'full.xlsx'
    export(dict(job={},items=[filled]),full_output)
    full = openpyxl.load_workbook(full_output).worksheets[0]
    assert [full[f'H{r}'].value for r in range(11,21)] == list(range(1,11))
    assert [full[f'N{r}'].value for r in range(11,31)] == [n for n in range(1,11) for _ in range(2)]
    assert full['I20'].value == '確認した競合9'
    assert full['J20'].value == 'https://example.com/9'
    assert full['I74'].value == '土地活用 条件49'
    assert full['O30'].value == '注意点' and full['P30'].value == 'https://example.com/9'
    assert all(full[f'I{r}'].value != '未取得' for r in list(range(11,21))+list(range(25,75)))
    filled['result']['sections'][0]['format'] = '可食部を扱う際の箇条書き'
    filled['result']['sections'][0]['llmo_reasons'] = ['AI回答の比較表を参考に、土地条件を比較できるようにする。']
    export(dict(job={},items=[filled]),full_output)
    revised = openpyxl.load_workbook(full_output).active
    texts = [revised[f'C{r}'].value for r in range(14,25)]
    instruction = '上記の要点や確認事項を箇条書きで示す。'
    reason = 'LLMOとして入れた理由：AI回答の比較表を参考に、土地条件を比較できるようにする。'
    assert instruction in texts and reason in texts and texts.index(reason) > texts.index(instruction)
    assert all(not str(c.value).startswith(('表現：','形式：')) and c.comment is None for row in revised for c in row)
    complete_report = json.loads(full_output.with_suffix('.coverage.json').read_text())[0]
    assert complete_report['competitor_count'] == 10 and complete_report['related_keyword_count'] == 50
assert sentences('「失敗した？」と聞く。条件を調べる。') == ['「失敗した？」と聞く。','条件を調べる。']
print('PASS: 5 themes/5 sheets, names, lead rows, sentence rows, reference rows, sample styles, no frozen panes')
