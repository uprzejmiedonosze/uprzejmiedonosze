# src/templates/ — Twig

- `<route>.html.twig` renderowany przez `AbstractHandler::renderHtml($request, $response, '<route>', [...])`;
  `<route>` trafia też jako `mainClass` do `<body class="index {{ mainClass }} {{ redesignClass }}">` (hook dla JS/SCSS).
- `_nazwa.html.twig` = partial do `include`. `*.email.twig`, `*.tex.twig`, `*.json.twig`, `*.txt.twig` = inne formaty
  (mail, PDF przez LaTeX, JSON, tekst) tego samego zgłoszenia — zmiana treści zgłoszenia zwykle dotyczy kilku z nich.
- Teksty dla użytkownika: po polsku, z formami rodzajowymi przez `sexify`/`config.sex`, liczebniki przez `num`.

## Szkielet strony

```twig
{# SITEMAP-PRIORITY:0.5 #}            {# opcjonalnie: strona trafia do sitemap.xml #}
{% set title = 'Pełny tytuł' %}
{% set shortTitle = 'Krótki' %}
{% set hasSidebar = true %}
{% extends "base.html.twig" %}

{% block main %} ... {% endblock %}
{% block sidebar %}{% include '_sidebar.html.twig' with {'current': '...'} %}{% endblock %}
```
Wzory: strona statyczna — `kontakt.html.twig`; strona aplikacji — `app.html.twig`.

## Flagi layoutu (ustawiane przed `extends`)

| Zmienna | Efekt |
|---|---|
| `redesignClass` | Domyślnie `rd25` dla każdej strony; `''` = opt-out |
| `hasSidebar` | Układ `.mpr-layout--sidebar` |
| `focusLayout` | `.mpr-layout--focus` — szerokość strony z sidebarem, bez sidebara, artykuł wyśrodkowany (np. `/app/new`, `/app/start`, generator) |
| `showAppNav` | Dolny pasek zakładek mobile dla `/app/*` (`_app-tabbar.html.twig`) |
| `hideNavbarLinks` | Przycięty nagłówek desktop (logo + CTA) |
| `hideNavbarCta` | Brak przycisku w nagłówku |
| `navbarCtaHref` / `navbarCtaLabel` | Własne CTA w nagłówku |
| `navbarCtaAction` / `navbarCtaClass` | CTA jako `<a>` bez href, obsługiwane przez JS (np. „Dalej” na `/app/new`) |
| `dialog`, `leftButton`, `rightButton` | Legacy nagłówek mobile |

Strony `/app/*`: `hasSidebar = true` + `{% include '_app-menu.html.twig' %}` jako lewe menu, `showAppNav = true`.
Zanim dodasz CSS, sprawdź gotowe hooki w `header.html.twig` i `src/scss/lib/layout.scss`.

## Zmiana URL-a strony

[ADR 0005](../../docs/adr/0005-url-renames.md): stary GET → 302, stary POST → ten sam handler pod oboma URL-ami,
grep wszystkich odwołań w `.twig`/`.js`/`.php` (poza historią w `changelog.html.twig`).

## changelog.html.twig

Uzupełniany przy commicie na `main` (komenda `/commit`). Wpisy po polsku, opis funkcjonalny, bez nazw plików.
