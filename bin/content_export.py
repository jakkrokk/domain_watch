#!/usr/bin/env python3
"""One theme per sheet, using the supplied sample's row and cell styles."""
import json
import re
import sys
from copy import copy
from pathlib import Path
import openpyxl
from openpyxl.comments import Comment
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


def export(payload, output):
    job, items = payload['job'], payload['items']
    completed = [item for item in items if item['status'] == 'completed']
    if not completed:
        raise ValueError('No completed outlines to export')
    wb = openpyxl.load_workbook(ROOT / 'resources/content-generation/template.xlsx')
    template, sample = wb['テンプレ'], wb['サンプル']
    originals = list(wb)
    used = set()
    for item in completed:
        result = json.loads(item['result']) if isinstance(item['result'], str) else item['result']
        evidence = json.loads(item['evidence']) if isinstance(item['evidence'], str) else (item['evidence'] or {})
        sheet = wb.copy_worksheet(template)
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
        for cell, value in {'C2':item['keyword'], 'C3':'、'.join(result['related_keywords']), 'C6':result['title'], 'C8':result['audience']}.items():
            put(sheet, cell, value)
        # Editorial context stays available in a comment, without additional sheets or body rows.
        context = []
        if job.get('notes'): context.append('構成案作成の参考メモ\n' + job['notes'])
        if result.get('assumptions'): context.append('推定と根拠\n' + '\n'.join(result['assumptions']))
        if result.get('limitations'): context.append('制約・未取得情報\n' + '\n'.join(result['limitations']))
        if result.get('llmo_analysis'): context.append('LLMO調整\n' + result['llmo_analysis'])
        if context: sheet['C2'].comment = Comment('\n\n'.join(context), 'Domain Watch')
        # Row 10 is a heading, not the lead paragraph. Rows 11/12 describe how to write it.
        for row in range(10,14): style_row(sheet, sample, row, row)
        for cell in ['B10','C10','D10','E10','F10','B11']:
            put(sheet, cell, sample[cell].value)
        guidance = result.get('lead_guidance', LEAD_GUIDANCE)
        if not isinstance(guidance, list) or len(guidance) != 2 or any(len(sentences([v])) != 1 for v in guidance):
            guidance = LEAD_GUIDANCE
        put(sheet, 'C11', guidance[0]); put(sheet, 'C12', guidance[1])
        total, row = 200, 14
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
            points = sentences(section['points'])
            # Older results sometimes put format only in this field; preserve that guidance.
            if section.get('format') and not any(p.startswith(('形式：','表現：')) for p in points):
                points.extend(sentences('表現：' + section['format']))
            urls = list(dict.fromkeys(url.strip() for value in section['sources'] for url in value.splitlines() if url.strip()))
            for offset in range(max(1, len(points), len(urls))):
                body_row = row + 1 + offset
                style_row(sheet, sample, body_row, 15 if is_h2 and offset == 0 else 20)
                if is_h2 and offset == 0: put(sheet, f'B{body_row}', '概要')
                if offset < len(points): put(sheet, f'C{body_row}', points[offset])
                # Match E15: normal text, sample font/alignment/format, one URL per row.
                sheet[f'E{body_row}']._style = copy(sample['E15']._style)
                if offset < len(urls): put(sheet, f'E{body_row}', urls[offset])
            total += section['word_count']
            row += max(1, len(points), len(urls)) + 2  # One empty row before the next heading.
            style_row(sheet, sample, row-1, 13)
        put(sheet, 'C4', total)
        put(sheet, 'H9', '競合調査（最大10件・順位未確認は0）')
        for r in range(11,21):
            for col in 'HIJK': put(sheet, f'{col}{r}', None)
        for r, competitor in enumerate(result['competitors'][:10],11):
            for col, key in [('H','rank'),('I','title'),('J','url'),('K','word_count')]:
                put(sheet, f'{col}{r}', competitor[key])
        put(sheet, 'N10', '順位'); put(sheet, 'O10', '見出し'); put(sheet, 'P10', '参照URL')
        r = 11
        for competitor in result['competitors']:
            for heading in competitor['headings']:
                put(sheet, f'N{r}', competitor['rank']); put(sheet, f'O{r}', heading); put(sheet, f'P{r}', competitor['url']); r += 1
        competitor_end = r
        put(sheet, 'H23', '関連・ファンアウトKW（検索回数・CPC未取得は0）')
        queries = list(dict.fromkeys(result['related_keywords'] + [q for e in evidence.values() for q in e.get('search_queries',[])]))
        for r in range(25,75):
            for col in 'HIJK': put(sheet, f'{col}{r}', None)
        for r, keyword in enumerate(queries[:50],25):
            put(sheet, f'H{r}', r-24); put(sheet, f'I{r}', keyword); put(sheet, f'J{r}', 0); put(sheet, f'K{r}', 0)
        sheet.freeze_panes = None
        sheet.print_area = f'B2:P{max(row, competitor_end, 75)}'
    for source in originals: wb.remove(source)
    for sheet in wb:
        sheet.title = sheet._content_theme
        del sheet._content_theme
    wb.active = 0
    output = Path(output)
    temp = output.with_suffix('.tmp.xlsx')
    wb.save(temp)
    check = openpyxl.load_workbook(temp, read_only=True); check.close()
    temp.replace(output)


if __name__ == '__main__':
    export(json.loads(Path(sys.argv[1]).read_text()), sys.argv[2])
