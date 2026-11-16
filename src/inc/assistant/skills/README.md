# Skille instrukcyjne asystenta

Każdy `*.md` z nagłówkiem (`---`, `name:`, `description:`, `---`) to skill. W prompcie systemowym asystent widzi tylko
`name` i `description`; gdy pytanie pasuje, wywołuje narzędzie `load_skill` i dostaje całą treść jako instrukcję.

- `name`: a-z, 0-9, `-`, `_`. `description`: jedno zdanie — KIEDY użyć skilla (model decyduje po nim).
- Treść pisz jako polecenia dla asystenta: jakie dane zebrać, które narzędzia wywołać, jaka ma być struktura pisma.
- Przepisów nie cytuj w skillu — wskaż, żeby asystent pobrał je z `search_legal` / `get_legal_provision` (`../legal/`).
- Plik bez nagłówka (jak ten) nie jest skillem.
