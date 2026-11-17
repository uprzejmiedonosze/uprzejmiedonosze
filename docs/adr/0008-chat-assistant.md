# 0008 — Asystent AI: narzędzia zamiast wielkiego kontekstu, rozmowa jako skrzynka powiadomień

**Status:** obowiązuje (2026-11-16)

## Kontekst
Chcemy czat na stronie, który odpowiada z bazy wiedzy (FAQ, wiki, wnioski z grupy wsparcia), zna zgłoszenia
użytkownika i akty prawne, pamięta rozmowę i — gdy nie umie — kieruje na `/kontakt.html`. Ma też móc w przyszłości
przyjmować powiadomienia systemowe. Akty prawne są obszerne: nie wolno ich wrzucać do kontekstu.

## Decyzja
- **Kod w `src/inc/assistant/`** (kopiowany do `export/inc/` bez zmian w `build.sh`): `Assistant.php` (pętla tool-calling
  na OpenAI chat completions, prompt, zapis rozmowy), `Tools.php` (narzędzia), `Corpus.php` (fragmenty + wyszukiwanie).
- **Wiedza = pliki w repo**, dzielone na sekcje `##`/`## Art. N` i czytane przez narzędzia (`search_knowledge`,
  `search_legal`, `get_legal_provision`). Do promptu trafia tylko `knowledge/podstawy.md`, spis aktów i spis skilli.
  Wyszukiwarka jest prosta (prefiksy bez polskich znaków + IDF), bez zewnętrznego indeksu — wystarczy do setek
  plików; przy dużym korpusie podmienia się tylko `Corpus::search()`.
- **Skille dwojakiego rodzaju:** (1) w kodzie, z dostępem do danych (`list_my_reports`, `get_my_report`),
  (2) instrukcyjne — `skills/*.md` z nagłówkiem `name`/`description`, ładowane narzędziem `load_skill`
  (np. odwołanie, skarga). Nowy skill instrukcyjny = nowy plik; przepisy pobiera z `legal/`, nie cytuje ich.
- **Tożsamość tylko z sesji.** Narzędzia nie przyjmują e-maila od modelu; cudze zgłoszenie = „nie znaleziono”.
  Asystent jest tylko-do-odczytu (zmian w zgłoszeniach nie robi).
- **Rozmowa** to jedna ciągła nić na użytkownika w `store.sqlite` (`chat_messages`, `store/ChatStore.php`); w kontekście
  ostatnie `CHAT_CONTEXT_MESSAGES`. Rola `notification` + `\chat\notify($email, $tekst, $link)` to przyszłe centrum
  powiadomień: wiadomość trafia do tej samej rozmowy, daje plakietkę, a asystent widzi ją w kontekście.
  Usunięcie konta kasuje rozmowę; użytkownik może ją skasować przyciskiem „Nowa rozmowa”.
- **Awaria lub brak wiedzy → kontakt.** Błąd modelu nie rzuca wyjątku: użytkownik dostaje odpowiedź z linkiem do
  kontaktu; model sam woła `escalate_to_contact`, gdy nie potrafi odpowiedzieć (UI pokazuje przycisk).
- **Dostęp:** tylko zalogowani i zarejestrowani (limit kosztów i potrzeba tożsamości dla zgłoszeń);
  `CHAT_RATE_MAX` pytań na `CHAT_RATE_WINDOW`. Odpowiedź bez streamingu (jedno żądanie, prostsza pętla narzędzi).
- **Załączniki (obrazy JPEG/PNG i PDF) tylko dla patronów** (`assistant/Attachments.php`, `\patronite\is`). Każdy plik (obraz i PDF) zużywa kredyt
  zdjęć jak w appce i MCP (`\quota\reserve` przed wywołaniem modelu, zwrot przy awarii modelu, to samo zdjęcie w oknie
  liczy się raz, 402 przy wyczerpaniu), PDF też liczy się jako jeden kredyt niezależnie od liczby stron. Bajtów nie przechowujemy — w rozmowie zostaje nazwa
  i typ (`meta.attachments`), model widzi plik tylko w turze, w której go wysłano. Przeglądarka zmniejsza zdjęcia do
  1600 px, bo JSON/base64 musi się zmieścić w `post_max_size` 5 MB (limity `CHAT_MAX_ATTACHMENT*`).
- Odpowiedzi modelu to niezaufane dane: Markdown renderowany po stronie serwera z `html_input: strip`
  i bez niebezpiecznych linków; treść użytkownika wstawiana jako `textContent`.

## Konsekwencje
- Zmiana schematu: migracja `src/sql/migration_20261116_chat.sql` (stosowana ręcznie na staging/prod).
- Zmiana bazy wiedzy/aktów/skilli nie wymaga zmian w kodzie, tylko plików w `src/inc/assistant/{knowledge,legal,skills}/`
  (każdy katalog ma `README.md` z formatem).
- Koszt: gpt-5-mini × do `CHAT_MAX_TOOL_ROUNDS`+1 wywołań na pytanie; tokeny zapisywane w `meta` wiadomości.
