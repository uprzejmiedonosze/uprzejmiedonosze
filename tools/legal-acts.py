#!/usr/bin/env python3
"""Odświeżanie aktów prawnych asystenta AI (src/inc/assistant/legal/) z API Sejmu (ELI, api.sejm.gov.pl).

Każdy akt w legal/index.json ma pole `eli` — adres aktu pierwotnego (np. "DU/1997/602"). Skrypt znajduje jego
najnowszy tekst jednolity (obwieszczenie Marszałka Sejmu), pobiera treść (HTML, a gdy go nie ma — PDF przez
`pdftotext` z poppler) i zapisuje `legal/<id>.md` w formacie z legal/README.md (każdy artykuł = `## Art. N`).
W index.json aktualizuje `ref` (Dz.U. tekstu jednolitego) i `url` (strona ISAP).

Użycie:
  python3 tools/legal-acts.py check              # stan: obecny tekst jednolity vs najnowszy, nowelizacje po nim
  python3 tools/legal-acts.py update [id ...]    # pobierz najnowszy tekst jednolity (wszystkie lub wskazane akty)
  python3 tools/legal-acts.py update kw --text DU/2026/1372   # konkretny tekst (np. gdy API zwraca błędny)

Tylko biblioteka standardowa Pythona; do PDF potrzebny `pdftotext` (brew install poppler / apt install poppler-utils).
"""
import argparse
import html
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from collections import Counter
from html.parser import HTMLParser

API = 'https://api.sejm.gov.pl/eli/acts/'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LEGAL = os.path.join(ROOT, 'src', 'inc', 'assistant', 'legal')
SUP = str.maketrans('0123456789', '⁰¹²³⁴⁵⁶⁷⁸⁹')


# ── API ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

def fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers={'User-Agent': 'legal-acts-refresh/1.0', 'Accept': '*/*'})
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read()


def act_meta(eli: str) -> dict:
    return json.loads(fetch(API + eli))


def eli_key(eli: str) -> tuple:
    _, year, pos = eli.split('/')
    return int(year), int(pos)


def latest_text(meta: dict) -> str | None:
    """Najnowszy tekst jednolity aktu (ELI obwieszczenia) — lista w API nie jest posortowana."""
    refs = [r['id'] for r in meta.get('references', {}).get('Inf. o tekście jednolitym', [])]
    return max(refs, key=eli_key) if refs else None


def amendments_after(meta: dict, text_eli: str) -> list[str]:
    """Akty zmieniające ogłoszone po tekście jednolitym (ich zmian tekst jednolity nie zawiera)."""
    after = {r['id'] for r in meta.get('references', {}).get('Akty zmieniające', []) if eli_key(r['id']) > eli_key(text_eli)}
    return sorted(after, key=eli_key)


def ref_of(text_eli: str) -> str:
    _, year, pos = text_eli.split('/')
    return f'Dz.U. z {year} r. poz. {pos}'


def isap_url(text_eli: str) -> str:
    _, year, pos = text_eli.split('/')
    return f'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU{year}{int(pos):07d}'


# ── HTML (api.sejm.gov.pl/eli/acts/…/text.html) ────────────────────────────────────────────────────────────────────

VOID = {'br', 'img', 'hr', 'meta', 'link', 'input', 'col', 'wbr'}


class Node:
    def __init__(self, tag, attrs, parent):
        self.tag, self.attrs, self.parent, self.children = tag, dict(attrs), parent, []

    def cls(self):
        return (self.attrs.get('class') or '').split()


class Tree(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = self.cur = Node('root', [], None)

    def handle_starttag(self, tag, attrs):
        n = Node(tag, attrs, self.cur)
        self.cur.children.append(n)
        if tag not in VOID:
            self.cur = n

    def handle_endtag(self, tag):
        n = self.cur
        while n is not self.root and n.tag != tag:
            n = n.parent
        if n is not self.root:
            self.cur = n.parent

    def handle_data(self, data):
        self.cur.children.append(data)


def node_text(n) -> str:
    """Tekst bez przypisów (gloss-link, tooltip); <sup> poza przypisami (np. art. 60¹) jako indeks górny."""
    if isinstance(n, str):
        return n
    if {'gloss-link', 'tooltip-text'} & set(n.cls()):
        return ''
    t = ''.join(node_text(c) for c in n.children)
    return t.strip().translate(SUP) if n.tag == 'sup' and t.strip().isdigit() else t


def norm(s: str) -> str:
    return re.sub(r'\s+', ' ', s.replace('\xa0', ' ')).strip()


def unit_kind(n):
    if isinstance(n, str) or n.tag != 'div' or 'unit' not in n.cls():
        return None
    return next((c[5:] for c in n.cls() if c.startswith('unit_')), None)


def child(n, pred):
    return next((c for c in n.children if not isinstance(c, str) and pred(c)), None)


def html_to_md(src: str) -> list[str]:
    tree = Tree()
    tree.feed(src)
    out: list[str] = []

    def body(container, depth):
        for c in container.children:
            if isinstance(c, str):
                continue
            k = unit_kind(c)
            if k:
                unit(c, k, depth)
            elif c.attrs.get('data-template') == 'xText':
                if t := norm(node_text(c)):
                    out.append('  ' * depth + t)
            elif c.attrs.get('data-template') == 'xEnum':
                for li in c.children:
                    if not isinstance(li, str) and li.tag == 'li' and (t := norm(node_text(li))):
                        out.append('  ' * depth + t)
            else:
                body(c, depth)

    def unit(n, kind, depth):
        h = child(n, lambda c: c.tag == 'h3')
        label = norm(node_text(h)) if h else ''
        inner = child(n, lambda c: c.tag == 'div' and 'unit-inner' in c.cls())
        if kind == 'arti' or kind in ('bran', 'chpt', 'schp', 'sect', 'part', 'titl', 'divi'):
            out.extend(['', '## ' + (re.sub(r'\.$', '', label) if kind == 'arti' else label)])
            if inner:
                body(inner, 0)
            return
        start = len(out)  # ustęp/punkt/litera/tiret: etykieta i pierwszy tekst w jednej linii
        if inner:
            body(inner, depth + 1)
        if label and len(out) > start:
            out[start] = '  ' * depth + label + ' ' + out[start].lstrip()
        elif label:
            out.append('  ' * depth + label)

    def walk(n):
        for c in n.children:
            if isinstance(c, str):
                continue
            k = unit_kind(c)
            if k == 'pass':
                continue  # punkty obwieszczenia (wykaz zmian, przepisy nieobjęte tekstem) — nie są treścią aktu
            unit(c, k, 0) if k else walk(c)

    walk(tree.root)
    return out


# ── PDF (pdftotext -bbox-layout) ───────────────────────────────────────────────────────────────────────────────────

NEW_PARA = re.compile(r'^(Art\.\s|§\s*\d|\d+[a-z]*\)|[a-z]{1,2}\)|–|-\s|CZĘŚĆ|DZIAŁ|Dział\s|Rozdział\s|Oddział\s)')
HEADING = re.compile(r'^(CZĘŚĆ|DZIAŁ|Dział\s|Rozdział\s|Oddział\s)')
WORD = re.compile(r'<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</word>')


def pdf_lines(pdf: str) -> list[tuple[float, float | None, str]]:
    """Linie treści aktu jako (x, odstęp od poprzedniej linii na stronie lub None, tekst): bez nagłówków stron, przypisów i odnośników do przypisów."""
    if not shutil.which('pdftotext'):
        sys.exit('Brak pdftotext — zainstaluj poppler (brew install poppler / apt install poppler-utils).')
    xml = subprocess.run(['pdftotext', '-bbox-layout', pdf, '-'], capture_output=True, check=True).stdout.decode()
    pages = []
    for page in xml.split('<page ')[1:]:
        words = [(float(a), float(b), float(c), float(d) - float(b), html.unescape(t)) for a, b, c, d, t in WORD.findall(page)]
        rows: list[list] = []  # słowa w tej samej linii (numer punktu i tekst są osobnymi blokami)
        for w in sorted(words, key=lambda w: (round(w[1]), w[0])):
            if rows and abs(rows[-1][0][1] - w[1]) < 2.5:
                rows[-1].append(w)
            else:
                rows.append([w])
        pages.append(rows)
    body_h = Counter(round(w[3], 1) for rows in pages for r in rows for w in r).most_common(1)[0][0]
    lines = []
    for rows in pages:
        prev_y = None
        for r in rows:
            r.sort(key=lambda w: w[0])
            if max(w[3] for w in r) < body_h - 0.5 or r[0][1] < 65:
                continue  # przypisy (mniejsza czcionka) i nagłówek strony „Dziennik Ustaw – N – Poz.”
            text, prev_end = '', None
            for x0, _, x1, h, t in r:
                if h < body_h - 2:  # indeks górny: odnośnik do przypisu „5)” pomijamy, „[1]” (art. 60¹) zostawiamy
                    if m := re.fullmatch(r'\[(\d+)\]', t):
                        text += m.group(1).translate(SUP)
                    prev_end = x1
                    continue
                text += ('' if prev_end is not None and x0 - prev_end < 1.0 else ' ') + t
                prev_end = x1
            if text.strip():
                lines.append((r[0][0], r[0][1] - prev_y if prev_y is not None else None, text.strip()))
                prev_y = r[0][1]
    return lines, body_h


def pdf_to_md(pdf: str) -> list[str]:
    lines, body_h = pdf_lines(pdf)
    start = next((i for i, (_, _, t) in enumerate(lines) if t.startswith('Załącznik do obwieszczenia')), 0)
    start = next(i for i in range(start, len(lines)) if HEADING.match(lines[i][2]) or lines[i][2].startswith('Art. 1.'))
    margin = min(x for x, _, _ in lines[start:])

    paras: list[str] = []
    in_point = False
    pending_heading = False
    for x, gap, t in lines[start:]:
        indented = x > margin + 10
        if HEADING.match(t):
            paras.append(t)
            pending_heading = t.startswith(('Rozdział', 'Dział', 'DZIAŁ', 'Oddział'))  # tytuł w następnej linii
            in_point = False
            continue
        if pending_heading and not NEW_PARA.match(t):
            paras[-1] += ' ' + t
            pending_heading = False
            continue
        pending_heading = False
        # Nowy akapit = większy odstęp w pionie (~1,5 wiersza; złamana linia ~1,15). Na początku strony odstępu
        # nie ma: wtedy decyduje wcięcie (kontynuacja punktu jest wcięta, część wspólna po punktach — nie zawsze).
        new_para = gap > body_h * 1.35 if gap is not None else (indented != in_point)
        if NEW_PARA.match(t) or new_para:
            paras.append(t)
            in_point = bool(re.match(r'^(\d+[a-z]*|[a-z]{1,2})\)', t))
            continue
        prev = paras[-1]
        if prev.endswith('-') and re.match(r'[a-ząćęłńóśźż]', t) and re.search(r'[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]-$', prev):
            paras[-1] = prev[:-1] + t  # przeniesienie wyrazu
        else:
            paras[-1] = prev + ' ' + t

    out: list[str] = []
    for p in paras:
        if HEADING.match(p):
            out.extend(['', '## ' + p])
        elif m := re.match(r'^Art\.\s*(\d+[a-z]*[⁰¹²³⁴⁵⁶⁷⁸⁹]*)\.\s*(.*)$', p):
            out.extend(['', f'## Art. {m.group(1)}'])
            if m.group(2):
                out.append(m.group(2))
        elif re.match(r'^[a-z]{1,2}\)', p):
            out.append('    ' + p)
        elif re.match(r'^(\d+[a-z]*\)|–|-\s)', p):
            out.append('  ' + p)
        else:
            out.append(p)
    return out


# ── zapis ──────────────────────────────────────────────────────────────────────────────────────────────────────────

def convert(text_eli: str) -> list[str]:
    meta = act_meta(text_eli)
    if meta.get('textHTML'):
        return html_to_md(fetch(API + text_eli + '/text.html').decode('utf-8'))
    if not meta.get('textPDF'):
        sys.exit(f'{text_eli}: brak treści HTML i PDF w API')
    with tempfile.NamedTemporaryFile(suffix='.pdf') as f:
        f.write(fetch(API + text_eli + '/text.pdf'))
        f.flush()
        return pdf_to_md(f.name)


def write_act(act: dict, text_eli: str, later: list[str]) -> None:
    body = convert(text_eli)
    arts = sum(1 for l in body if l.startswith('## Art.'))
    keys = [l[8:] for l in body if l.startswith('## Art.')]
    dupes = sorted({k for k in keys if keys.count(k) > 1})
    if arts == 0:
        sys.exit(f'{act["id"]}: nie znaleziono artykułów w {text_eli} — nie zapisuję')
    header = [f'# {act["title"]}', '',
              f'Tekst jednolity: {ref_of(text_eli)} ({isap_url(text_eli)}), pobrany z api.sejm.gov.pl skryptem '
              'tools/legal-acts.py — nie edytuj ręcznie. Przypisy redakcyjne pominięte.']
    if later:
        header.append('Nieuwzględnione zmiany ogłoszone po tekście jednolitym: '
                      + ', '.join(ref_of(e) for e in later) + '.')
    with open(os.path.join(LEGAL, act['id'] + '.md'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(header + body).rstrip() + '\n')
    act['ref'] = ref_of(text_eli) + (' (późniejsze nowelizacje nieuwzględnione)' if later else '')
    act['url'] = isap_url(text_eli)
    print(f'{act["id"]}: {ref_of(text_eli)} — {arts} artykułów' + (f', DUPLIKATY numerów: {dupes}' if dupes else '')
          + (f', {len(later)} nowelizacji po tekście jednolitym' if later else ''))


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('cmd', choices=['check', 'update'])
    ap.add_argument('ids', nargs='*', help='id aktów z index.json (domyślnie wszystkie)')
    ap.add_argument('--text', help='ELI konkretnego tekstu jednolitego (tylko z jednym id)')
    a = ap.parse_args()

    index_file = os.path.join(LEGAL, 'index.json')
    index = json.load(open(index_file, encoding='utf-8'))
    acts = [x for x in index['acts'] if not a.ids or x['id'] in a.ids]
    if a.text and len(acts) != 1:
        sys.exit('--text wymaga dokładnie jednego id')

    for act in acts:
        if not act.get('eli'):
            print(f'{act["id"]}: brak pola "eli" w index.json — pomijam')
            continue
        meta = act_meta(act['eli'])
        text_eli = a.text or latest_text(meta)
        if not text_eli:
            print(f'{act["id"]}: brak tekstu jednolitego w API — pomijam')
            continue
        later = amendments_after(meta, text_eli)
        if a.cmd == 'check':
            current = act.get('ref', '')
            fresh = current.startswith(ref_of(text_eli))
            print(f'{act["id"]}: obecnie „{current}”, najnowszy {ref_of(text_eli)} ({text_eli})'
                  + (' — aktualny' if fresh else ' — DO AKTUALIZACJI')
                  + (f'; nowelizacje po nim: {", ".join(later)}' if later else ''))
        else:
            write_act(act, text_eli, later)

    if a.cmd == 'update':
        with open(index_file, 'w', encoding='utf-8') as f:
            json.dump(index, f, ensure_ascii=False, indent=2)
            f.write('\n')


if __name__ == '__main__':
    main()
