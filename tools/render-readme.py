#!/usr/bin/env python3
"""Render README.md into README.html using tools/readme-template.html.

The template holds the page design; everything between the masthead and the
closing </main> is generated from the markdown, and the sidebar contents are
generated from its `##` headings.

    python3 tools/render-readme.py
"""

import html
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def esc(text):
    return html.escape(text, quote=True)


def slug(text):
    """Heading -> anchor. Backticks and punctuation drop out; a slug that
    would start with a digit gets a `v` so it stays a valid id."""
    s = text.replace('`', '')
    s = re.sub(r'[^a-zA-Z0-9 \-]', '', s).strip().lower()
    s = re.sub(r'[\s\-]+', '-', s).strip('-')
    return 'v' + s if s[:1].isdigit() else s


def inline(text):
    """Inline markdown -> HTML. Code spans are stashed first so that bold
    spanning a code span (**set `debug` on**) still renders as one strong."""
    codes = []

    def stash(m):
        codes.append(m.group(1))
        return '\x00%d\x00' % (len(codes) - 1)

    text = re.sub(r'`([^`]+)`', stash, text)
    text = esc(text)
    text = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', text)
    text = re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', text)
    text = re.sub(r'(?<!\w)\*([^*\n]+)\*(?!\w)', r'<em>\1</em>', text)
    text = re.sub(r'\x00(\d+)\x00',
                  lambda m: '<code>%s</code>' % esc(codes[int(m.group(1))]), text)
    return text


def cells(row):
    return [c.strip() for c in row.strip().strip('|').split('|')]


def render_blocks(lines):
    """Markdown block elements -> HTML, in document order."""
    out = []
    i = 0
    n = len(lines)

    while i < n:
        line = lines[i]

        if not line.strip():
            i += 1
            continue

        # thematic break — the sections carry the separation in HTML
        if re.fullmatch(r'-{3,}', line.strip()):
            i += 1
            continue

        # fenced code
        if line.startswith('```'):
            lang = line[3:].strip()
            i += 1
            body = []
            while i < n and not lines[i].startswith('```'):
                body.append(lines[i])
                i += 1
            i += 1
            cls = ' class="language-%s"' % esc(lang) if lang else ''
            out.append('<pre><code%s>%s</code></pre>' % (cls, esc('\n'.join(body))))
            continue

        # heading
        m = re.match(r'^(#{2,4})\s+(.*)$', line)
        if m:
            level = len(m.group(1))
            out.append((level, m.group(2).strip()))   # resolved by the caller
            i += 1
            continue

        # table
        if line.startswith('|') and i + 1 < n and re.match(r'^\|[\s:|-]+\|$', lines[i + 1]):
            head = cells(line)
            i += 2
            rows = []
            while i < n and lines[i].startswith('|'):
                rows.append(cells(lines[i]))
                i += 1
            thead = ''.join('<th>%s</th>' % inline(c) for c in head)
            tbody = ''.join(
                '<tr>%s</tr>' % ''.join('<td>%s</td>' % inline(c) for c in r)
                for r in rows
            )
            out.append('<div class="table-wrap"><table><thead><tr>%s</tr></thead>'
                       '<tbody>%s</tbody></table></div>' % (thead, tbody))
            continue

        # blockquote
        if line.startswith('>'):
            body = []
            while i < n and lines[i].startswith('>'):
                body.append(lines[i].lstrip('>').strip())
                i += 1
            out.append('<blockquote><p>%s</p></blockquote>' % inline(' '.join(body)))
            continue

        # unordered list
        if re.match(r'^- ', line):
            items = []
            while i < n and (re.match(r'^- ', lines[i]) or
                             (items and lines[i].startswith('  ') and lines[i].strip())):
                if re.match(r'^- ', lines[i]):
                    items.append([lines[i][2:].strip()])
                else:
                    items[-1].append(lines[i].strip())
                i += 1
            out.append('<ul>%s</ul>' % ''.join(
                '<li><p>%s</p></li>' % inline(' '.join(it)) for it in items))
            continue

        # paragraph
        body = []
        while i < n and lines[i].strip() and not lines[i].startswith(('```', '|', '>', '#')) \
                and not re.match(r'^- ', lines[i]) and not re.fullmatch(r'-{3,}', lines[i].strip()):
            body.append(lines[i].strip())
            i += 1
        out.append('<p>%s</p>' % inline(' '.join(body)))

    return out


def render(markdown):
    lines = markdown.split('\n')

    # drop the H1 (it lives in the masthead) and the Contents list (it becomes
    # the sidebar), keeping the intro paragraphs that sit between them
    body_lines = []
    skipping_contents = False
    for line in lines:
        if line.startswith('# '):
            continue
        if line.startswith('## '):
            skipping_contents = line.strip().lower() == '## contents'
            if skipping_contents:
                continue
        if skipping_contents:
            continue
        body_lines.append(line)

    blocks = render_blocks(body_lines)

    html_parts = []
    toc = []
    open_section = False

    for block in blocks:
        if isinstance(block, tuple):
            level, text = block
            anchor = slug(text)
            if level == 2:
                if open_section:
                    html_parts.append('</section>')
                html_parts.append('<section id="%s"><h2>%s</h2>' % (anchor, inline(text)))
                open_section = True
                toc.append('<li><a href="#%s">%s</a></li>' % (anchor, inline(text)))
            else:
                html_parts.append('<h%d id="%s">%s</h%d>' % (level, anchor, inline(text), level))
        else:
            html_parts.append(block)

    if open_section:
        html_parts.append('</section>')

    return ''.join(html_parts), '<ul class="toc">%s</ul>' % ''.join(toc)


def main():
    md = io.open(os.path.join(ROOT, 'README.md'), encoding='utf-8').read()
    tpl = io.open(os.path.join(ROOT, 'tools', 'readme-template.html'), encoding='utf-8').read()

    body, toc = render(md)
    page = tpl.replace('{{TOC}}', toc).replace('{{BODY}}', body)

    out = os.path.join(ROOT, 'README.html')
    io.open(out, 'w', encoding='utf-8').write(page)
    print('wrote %s (%d bytes)' % (out, len(page.encode('utf-8'))))


if __name__ == '__main__':
    sys.exit(main())
