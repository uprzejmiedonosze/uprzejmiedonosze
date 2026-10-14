# Architecture Decision Records

Krótkie zapisy decyzji, które nie wynikają z samego kodu: *dlaczego* coś jest zrobione tak, a nie inaczej,
i czego **nie** robić przy następnej zmianie. Kod mówi „co”, ADR mówi „dlaczego”.

Zasady:
- Jeden plik = jedna decyzja, `NNNN-krotki-slug.md`, numeracja rosnąca.
- Nie edytuj starej decyzji, gdy się zmienia — dopisz nowy ADR i oznacz stary jako `Superseded by NNNN`.
- Sekcje: **Status**, **Kontekst**, **Decyzja**, **Konsekwencje**. Max ~1 ekran.
- Linkuj ADR z miejsca, którego dotyczy (komentarz w kodzie albo lokalny `AGENTS.md`).

| # | Decyzja |
|---|---------|
| [0001](0001-sm-keys-immutable.md) | Klucze jednostek SM/Policji są niezmienne; likwidacja = `active: false` |
| [0002](0002-logging-via-journald.md) | Logi aplikacji idą datagramami syslog do journald, nie przez stdout/stderr |
| [0003](0003-face-detection-pipeline.md) | Detekcja twarzy: tani LLM liczy, Cloud Vision lokalizuje, oryginał nietknięty |
| [0004](0004-mailgun-per-recipient-delivery.md) | Status wysyłki: zdarzenia Mailguna są per adresat, `failed` nie kasuje `sent` |
| [0005](0005-url-renames.md) | Zmiana URL-a strony: stary GET → 302, stary POST → ten sam handler |
