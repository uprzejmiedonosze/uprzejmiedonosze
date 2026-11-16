# Baza wiedzy asystenta AI

Pliki `*.md` w tym katalogu czyta `\assistant\corpus\knowledge()` (src/inc/assistant/Corpus.php).

- `podstawy.md` — **zawsze** w prompcie systemowym. Krótki, tylko twarde fakty.
- Pozostałe `*.md` — dzielone na fragmenty po nagłówkach `##` i przeszukiwane narzędziem `search_knowledge`.
  Do kontekstu modelu trafia kilka najlepiej pasujących fragmentów, nie całość — plików może być dowolnie dużo.
- Ten plik (`README.md`) też jest indeksowany, więc nie wpisuj tu nic, co ma się trafić do odpowiedzi — nie ma tu `##`.

Zasady pisania:
1. Jeden temat = jedna sekcja `## Pytanie lub hasło`; pierwsze zdanie to gotowa odpowiedź.
2. Pisz po polsku, konkretnie. Przy faktach zależnych od miasta/jednostki podaj, których dotyczą.
3. Link do dłuższego omówienia (wiki, FAQ) wpisz w treści — asystent poda go użytkownikowi.
4. Nie wklejaj tu tekstów ustaw — te idą do `../legal/`.
