# src/scss/ — style

Entry: `index3.scss` (kolejność `@use`: tokeny → typografia → variables → layout → komponenty `lib/` → `general` → `sites/*`).
Jeden system wizualny: **rd25** (redesign 2025, mockupy w projekcie Claude Design „Redesign social-tech application”).

## Fundament (`lib/`)

| Plik | Rola |
|---|---|
| `tokens.scss` | **Jedyne źródło tokenów `$mpr-*`**: kolory (zieleń `#0d7a55`, żółć CTA `#E2C439`, kremowe powierzchnie), promienie, cienie, `$mpr-max-width: 75em`, `$mpr-break: 48em`, fonty `$font-display`/`$font-body`/`$font-plate` |
| `typography.scss` | Bricolage Grotesque (nagłówki) + Public Sans (treść), skala h1–h5. Link Google Fonts w `base.html.twig` |
| `layout.scss` | `@mixin container`, `@mixin full-bleed`, `.mpr-container`, `.mpr-fullbleed`, `.mpr-layout` (+ `--sidebar`, `--focus`), `.mpr-article`, `.mpr-sidebar`, `.mpr-menu-list` |
| `variables.scss` | **Warstwa kompatybilności**: `$primaryColor` → `tokens.$mpr-primary`, `$secondaryColor` → `tokens.$mpr-accent`. Dzięki temu legacy komponenty dostają nowy brand bez edycji |
| `button.scss` | `.mpr-button*` (nowe) + legacy `.button` |
| `header.scss`, `footer.scss` | Jedyne style nagłówka/stopki |
| `menu`, `tabs`, `radio`, `checkbox`, `dropdown`, `toast`, `dialog`, `grid`, `badge`, `statuses`… | Komponenty |

`env.js` nie steruje kolorem; emituje `--env-tint` (kropka/pasek dev/staging, `transparent` na prod).

## Zasady

- Nowy kod: `@use "../lib/tokens" as *` i tokeny `$mpr-*`. **Nie wpisuj kolorów/promieni/breakpointów na sztywno**
  i nie twórz równoległej palety — rozszerz `tokens.scss`.
- Szerokość treści / sekcje na całą szerokość: mixiny `container` / `full-bleed`, nie własne `max-width`.
- Przy zmianie wyglądu wolimy **zmiany addytywne i reskin istniejących klas** zamiast przepisywania nawigacji/treści.
- Style strony w `sites/<strona>.scss` + `@use` w `index3.scss`; scope pod klasą strony (`mainClass`).

## Dług (świadomie odłożony — nie ruszaj przy okazji)

- Body stron `sites/*` (new-application, application, confirm, generator, faq, gallery, …) działają na compat shimie,
  nie są jeszcze docelowo przeprojektowane. Migracja strona po stronie, z wizualną weryfikacją użytkownika.
- Dwa breakpointy: legacy `$width-break: 35em` vs `$mpr-break: 48em`.
- Mobile wciąż używa starego nagłówka z hamburgerem na każdej stronie (redesign nie ma jeszcze własnej nawigacji mobile).

## Weryfikacja

`npx sass --no-source-map --load-path=src/scss src/scss/index3.scss /dev/null` (składnia), potem `curl` lokalnego dockera.
Wizualną weryfikację robi użytkownik.
