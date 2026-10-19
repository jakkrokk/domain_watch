#!/usr/bin/env python3
"""One theme per sheet, using the supplied sample's row and cell styles."""
import json
import math
import re
import sys
import unicodedata
import zipfile
from copy import copy, deepcopy
from pathlib import Path
import openpyxl
from openpyxl.worksheet.views import Selection
from openpyxl.cell.cell import ILLEGAL_CHARACTERS_RE

ROOT = Path(__file__).resolve().parent.parent
LEAD_GUIDANCE = [
    '「共感（問題提起）→答えの提示→記事の内容の紹介」の順にリード文を構成する。',
    'この記事を読むことで何が分かるのか、どのようなメリットがあるのかをユーザーに提示する。',
]


def put(sheet, cell, value):
    """Write values without changing the sample's alignment, fonts or number formats."""
    target = sheet[cell]
    if isinstance(value, str):
        value = ILLEGAL_CHARACTERS_RE.sub('', value)
        if len(value) > 32767:
            raise ValueError('Excel cell too long: ' + cell)
        target.value = value
        target.data_type = 's'  # User/model strings must never become formulas.
    else:
        target.value = value


def sentences(values):
    """Separate paragraphs and sentences while keeping punctuation inside quotations."""
    if isinstance(values, str):
        values = [values]
    result = []
    pairs = {'「': '」', '『': '』', '（': '）', '(': ')', '【': '】', '“': '”'}
    for value in values:
        for line in str(value).splitlines():
            stack, text = [], ''
            for char in line.strip():
                text += char
                if char in pairs:
                    stack.append(pairs[char])
                elif stack and char == stack[-1]:
                    stack.pop()
                if char in '。！？!?' and not stack:
                    if text.strip(): result.append(text.strip())
                    text = ''
            if text.strip(): result.append(text.strip())
    return result


def writing_instructions(value):
    """Convert legacy format labels into actionable writing instructions."""
    value = re.sub(r'^(?:表現|形式)[：:]\s*', '', value.strip())
    if not value: return []
    if re.search(r'(?:する|示す|まとめる|入れる|書く|伝える|置く|添える|並べる|作成|記載|説明)[。.]?$', value):
        return sentences(value)
    instructions = []
    if '表' in value: instructions.append('表を入れ、上記の内容を項目ごとに整理して比較できるようにする。')
    if any(word in value for word in ['箇条書き','チェックリスト','一覧']):
        instructions.append('上記の要点や確認事項を箇条書きで示す。')
    if '手順' in value: instructions.append('実行する順番に番号を付け、各手順で行うことを示す。')
    if '流れ図' in value: instructions.append('工程の順番が分かる図を入れる。')
    if '事例カード' in value: instructions.append('事例ごとに土地条件・取り組み・成果・注意点を同じ順番で記載する。')
    if 'FAQ' in value or '回答' in value: instructions.append('質問の直後に短く回答し、その後に条件や注意点を補足する。')
    if '定義' in value: instructions.append('最初に用語の意味を短く説明し、具体例を添える。')
    if not instructions: instructions.append('最初に結論を短く書き、その後に上記の具体例や注意点を説明する。')
    return instructions


def related_keywords(result):
    suggestions = result.get('keyword_suggestions')
    allowed = set(suggestions['values']) if suggestions else None
    values = []
    for value in result.get('related_keywords', []):
        value = value.strip()
        if not value or len(value) > 60 or re.search(r'https?://|\b(?:site|inurl|intitle|filetype):|[\r\n]', value, re.I):
            continue
        if allowed is not None and value not in allowed:
            continue
        if value not in values:
            values.append(value)
    return values


def format_instruction(text):
    if re.search(r'比較表|一覧表|表を|表に|表で', text) and not text.startswith('（表の作成）'):
        return '（表の作成）' + text
    return text


def theme_title(keyword, used):
    name = re.sub(r'[\\/*?:\[\]\x00-\x1f]', ' ', keyword).strip().strip("'") or 'テーマ'
    if name.casefold() == 'history': name = 'History_'
    base = name[:31].rstrip("'")
    title, n = base, 2
    while title.casefold() in used:
        suffix = f' ({n})'
        title = base[:31-len(suffix)].rstrip("'") + suffix
        n += 1
    used.add(title.casefold())
    return title


def style_row(sheet, sample, row, source):
    # Only B:F move with the outline; competitor tables on the right keep their positions.
    for col in range(2, 7):
        sheet.cell(row, col)._style = copy(sample.cell(source, col)._style)
    sheet.row_dimensions[row].height = sample.row_dimensions[source].height or 16.5


def cache_totals(path, totals):
    # Preserve the sample's SUM formula AND its cached result for previewers.
    with zipfile.ZipFile(path) as archive:
        files = {name: archive.read(name) for name in archive.namelist()}
    for i, total in enumerate(totals, 1):
        name = f'xl/worksheets/sheet{i}.xml'
        xml = files[name].decode('utf-8')
        def cached(match):
            cell = match.group(0)
            return re.sub(r'<v(?:\s[^>]*)?\s*/>|<v>.*?</v>', f'<v>{total}</v>', cell)
        files[name] = re.sub(r'<c\b[^>]*\br="C4"[^>]*>.*?</c>', cached, xml).encode('utf-8')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items(): archive.writestr(name, content)


def export(payload, output):
    job, items = payload['job'], payload['items']
    completed = [item for item in items if item['status'] == 'completed']
    if not completed:
        raise ValueError('No completed outlines to export')
    wb = openpyxl.load_workbook(ROOT / 'resources/content-generation/template.xlsx')
    template, sample = wb['テンプレ'], wb['サンプル']
    originals = list(wb)
    used = set()
    totals, coverage = [], []
    for item in completed:
        result = json.loads(item['result']) if isinstance(item['result'], str) else item['result']
        evidence = json.loads(item['evidence']) if isinstance(item['evidence'], str) else (item['evidence'] or {})
        for key in ['title', 'audience']:
            if not str(result.get(key, '')).strip(): raise ValueError('Missing required field: ' + key)
        if not result.get('sections'): raise ValueError('Missing outline')
        sheet = wb.copy_worksheet(template)
        # copy_worksheet does not copy sheet views or print options.
        sheet.views = deepcopy(template.views)
        sheet.print_options = copy(template.print_options)
        sheet.sheet_view.pane = None
        sheet.sheet_view.topLeftCell = 'B1'
        sheet.sheet_view.selection = [Selection(activeCell='C2', sqref='C2')]
        report = {'theme': item['keyword'], 'unavailable': [], 'intentional_blanks': ['空行・余白', 'クライアント様コメント（未提供）']}

        # Assign final names after removing source worksheets to allow themes named テンプレ etc.
        sheet._content_theme = theme_title(item['keyword'], used)
        # Remove fixed placeholder headings; rebuild each block from the sample's styles.
        for row in sheet.iter_rows(min_row=10, max_col=6):
            for cell in row:
                cell.value = None
                cell.hyperlink = None
                cell.comment = None
                cell._style = copy(sample.cell(13, cell.column)._style)
            sheet.row_dimensions[row[0].row].height = 16.5
        persona = result['audience']
        if not persona.lstrip().startswith(('・','-','•')):
            persona = '\n'.join('・' + line for line in sentences(persona))
        selected_keywords = related_keywords(result)
        for cell, value in {'C2':item['keyword'], 'C3':'、'.join(selected_keywords[:3]) or '未取得', 'C6':result['title'], 'C8':persona}.items():
            put(sheet, cell, value)
        resolved_prompts = item.get('ai_prompts')
        if isinstance(resolved_prompts, str):
            resolved_prompts = json.loads(resolved_prompts)
        prompts = (resolved_prompts or {}).get('prompts', [])
        style_row(sheet, sample, 9, 8)
        put(sheet, 'B9', '想定プロンプト')
        put(sheet, 'C9', '\n'.join('・' + prompt for prompt in prompts) if prompts else '未取得')
        if not prompts:
            report['unavailable'].append('想定プロンプト：保存された質問文なし')
        width = max(1, sheet.column_dimensions['C'].width - 2)
        lines = sum(max(1, math.ceil(sum(2 if unicodedata.east_asian_width(c) in 'FW' else 1 for c in line) / width))
                    for line in sheet['C9'].value.splitlines())
        sheet.row_dimensions[9].height = min(409.5, max(sample.row_dimensions[8].height or 51, lines * 16.5 + 8))
        style_row(sheet, sample, 10, 7)
        # Leave a spacer after the prompts; only the outline columns move.
        for row in range(11,15): style_row(sheet, sample, row, row-1)
        for cell in ['B10','C10','D10','E10','F10','B11']:
            put(sheet, f'{cell[0]}{int(cell[1:])+1}', sample[cell].value)
        guidance = result.get('lead_guidance')
        if guidance is None:
            guidance = [f'「{item["keyword"]}」を調べる読者の悩みに共感し、結論と記事で扱う判断材料を示す。', LEAD_GUIDANCE[1]]
        if not isinstance(guidance, list) or len(guidance) != 2 or any(len(sentences([v])) != 1 for v in guidance):
            raise ValueError('Lead guidance must contain exactly two single-sentence instructions')
        put(sheet, 'C12', guidance[0]); put(sheet, 'C13', guidance[1])
        total, row = 200, 15
        for index, section in enumerate(result['sections']):
            is_h2 = section['level'] == 'H2'
            is_summary = is_h2 and index == len(result['sections'])-1 and bool(re.search(r'まとめ|おわりに', section['heading']))
            heading_row = 183 if is_summary else (14 if is_h2 else 19)
            style_row(sheet, sample, row, heading_row)
            put(sheet, f'B{row}', 'まとめ(H2)' if is_summary else ('見出し(H2)' if is_h2 else '小見出し(H3)'))
            put(sheet, f'C{row}', section['heading'])
            put(sheet, f'D{row}', section['word_count'])
            put(sheet, f'E{row}', '参照URL')
            if is_h2: put(sheet, f'F{row}', 'クライアント様コメント')
            if not section['heading'].strip() or not section['points'] or section['word_count'] <= 0:
                raise ValueError('Missing heading, outline content or planned character count')
            points = []
            for point in sentences(section['points']):
                points.extend([format_instruction(p) for p in writing_instructions(point)] if point.startswith(('形式：','表現：','形式:','表現:')) else [format_instruction(point)])
            for instruction in writing_instructions(section.get('format', '')):
                instruction = format_instruction(instruction)
                if instruction not in points: points.append(instruction)
            attributions = section.get('source_attributions', [])
            points.extend('LLMOのため出典を明示（' + name + '）'
                          for name in dict.fromkeys(a['source_name'] for a in attributions))
            # Reasons are ordinary cells at the bottom of this section, never Excel notes.
            reasons = sentences(section.get('llmo_reasons', []))
            points.extend('※LLMO用：' + reason for reason in reasons)
            source_values = section['sources'] + [a['source_url'] for a in attributions]
            urls = list(dict.fromkeys(url.strip() for value in source_values for url in value.splitlines() if url.strip()))
            if not urls:
                urls = ['未取得']
                report['unavailable'].append(section['heading'] + '：参照URL未取得')
            for offset in range(max(1, len(points), len(urls))):
                body_row = row + 1 + offset
                style_row(sheet, sample, body_row, 15 if is_h2 and offset == 0 else 20)
                if is_h2 and offset == 0: put(sheet, f'B{body_row}', '概要')
                if offset < len(points):
                    put(sheet, f'C{body_row}', points[offset])
                    if points[offset].startswith(('（可能なら）','(可能なら)','（表の作成）')):
                        font = copy(sheet[f'C{body_row}'].font)
                        font.color = 'FFFF0000'
                        sheet[f'C{body_row}'].font = font
                # Match E15: normal text, sample font/alignment/format, one URL per row.
                sheet[f'E{body_row}']._style = copy(sample['E15']._style)
                if offset < len(urls):
                    put(sheet, f'E{body_row}', urls[offset])
                    alignment = copy(sheet[f'E{body_row}'].alignment)
                    alignment.horizontal = 'fill'
                    sheet[f'E{body_row}'].alignment = alignment
            total += section['word_count']
            row += max(1, len(points), len(urls)) + 2  # One empty row before the next heading.
            style_row(sheet, sample, row-1, 13)
        for col in range(2,7):
            border = copy(sheet.cell(row-2, col).border)
            border.bottom = copy(sample['C15'].border.bottom)
            sheet.cell(row-2, col).border = border
        sheet['C4'] = sample['C4'].value
        totals.append(total)
        put(sheet, 'H9', template['H9'].value)
        for r in range(11,21):
            for col in 'HIJK': put(sheet, f'{col}{r}', None)
        report['competitor_count'] = min(len(result['competitors']), 10)
        for r in range(11 + report['competitor_count'], 21):
            put(sheet, f'H{r}', r-10); put(sheet, f'I{r}', '未取得'); put(sheet, f'J{r}', '未取得'); put(sheet, f'K{r}', 0)
        if report['competitor_count'] < 10:
            report['unavailable'].append(f'競合記事：{report["competitor_count"]}/10件取得')
        for r, competitor in enumerate(result['competitors'][:10],11):
            put(sheet, f'H{r}', r-10)
            for col, key in [('I','title'),('J','url'),('K','word_count')]:
                put(sheet, f'{col}{r}', competitor[key])
        if any(not c['rank'] for c in result['competitors']): report['unavailable'].append('競合の検索順位：未計測のため0')
        if any(not c['word_count'] for c in result['competitors']): report['unavailable'].append('競合本文文字数：未計測のため0')
        for cell in ['N10', 'O10', 'P10']: put(sheet, cell, template[cell].value)
        r = 11
        for number, competitor in enumerate(result['competitors'][:10], 1):
            if not competitor['headings']:
                report['unavailable'].append(competitor['url'] + '：見出し未取得')
            for heading in competitor['headings'] or ['見出し未取得']:
                put(sheet, f'N{r}', number); put(sheet, f'O{r}', heading); put(sheet, f'P{r}', competitor['url']); r += 1
        competitor_end = r-1
        put(sheet, 'N10', 'No.')
        put(sheet, 'H23', template['H23'].value)
        suggestions = result.get('keyword_suggestions')
        queries = list(dict.fromkeys(suggestions['values'] if suggestions else selected_keywords))
        for r in range(25,75):
            for col in 'HIJK': put(sheet, f'{col}{r}', None)
        report['related_keyword_count'] = min(len(queries), 50)
        if not queries: report['unavailable'].append('関連キーワード：検索候補未取得')
        report['unavailable'].append('検索回数・CPC：未計測のため0')
        for r in range(25 + report['related_keyword_count'],75):
            put(sheet, f'H{r}', r-24); put(sheet, f'I{r}', '未取得'); put(sheet, f'J{r}', 0); put(sheet, f'K{r}', 0)
        for r, keyword in enumerate(queries[:50],25):
            put(sheet, f'H{r}', r-24); put(sheet, f'I{r}', keyword); put(sheet, f'J{r}', 0); put(sheet, f'K{r}', 0)
        report['limitations'] = result.get('limitations', [])
        report['numbering'] = '競合記事の掲載No.。検索順位の実測ではありません。'
        report['article_measurements'] = [c.get('measurement', {}) for c in result['competitors'][:10]]
        coverage.append(report)
        # Remove template-only formatting below each independent table.
        ends = {**{c: row-2 for c in range(1,7)}, 7: 0, **{c:74 for c in range(8,12)}, 12:0, 13:0, **{c:competitor_end for c in range(14,17)}}
        for key, cell in list(sheet._cells.items()):
            cell.comment = None
            if key[1] in (5,10,16) and isinstance(cell.value,str) and cell.value.startswith(('https://','http://')):
                alignment = copy(cell.alignment)
                alignment.horizontal = 'fill'
                cell.alignment = alignment
            if key[0] > ends.get(key[1], 0): del sheet._cells[key]
        last_row = max(row-2, competitor_end, 74)
        for dimension in list(sheet.row_dimensions):
            if dimension > last_row: del sheet.row_dimensions[dimension]
        sheet.freeze_panes = None
        sheet.print_area = f'B2:P{last_row}'
    for source in originals: wb.remove(source)
    for sheet in wb:
        sheet.title = sheet._content_theme
        del sheet._content_theme
    wb.active = 0
    output = Path(output)
    temp = output.with_suffix('.tmp.xlsx')
    wb.calculation.fullCalcOnLoad = True
    wb.calculation.forceFullCalc = True
    wb.save(temp)
    cache_totals(temp, totals)
    check = openpyxl.load_workbook(temp, read_only=True); check.close()
    temp.replace(output)
    audit = output.with_suffix('.coverage.json')
    audit_temp = audit.with_suffix('.tmp.json')
    audit_temp.write_text(json.dumps(coverage, ensure_ascii=False, indent=2) + '\n')
    audit_temp.replace(audit)


if __name__ == '__main__':
    export(json.loads(Path(sys.argv[1]).read_text()), sys.argv[2])
