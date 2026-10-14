# 0004 — Zdarzenia Mailguna są per adresat; `failed` nie kasuje `sent`

**Status:** obowiązuje (2026-07-28)

## Kontekst
Błąd „mailgun webhook error, Application X was not sent!” (`WebhooksHandler.php`) miał w 2026 cztery
kolejne przyczyny:
1. brak deduplikacji eventów → `\webhook\isProcessed($id)`;
2. brak `mark()` w gałęzi „not sent”;
3. wyścig TOCTOU między `isProcessed()` a `mark()` przy redundantnych dostawach z różnych IP Mailguna;
4. **właściwa przyczyna:** Mailgun raportuje doręczenie osobno dla każdego adresata tej samej wiadomości
   (`to` = urząd, `cc` = zgłaszający, `bcc` = `MAILER_FROM`). Odbicie `to` robiło
   `unset($application->sent)`, więc późniejsze `delivered` dla `cc`/`bcc` trafiało na `wasSent() == false`.

## Decyzja
- `failed` ustawia tylko `setStatus('sending-failed')`, **nie** usuwa `$application->sent`.
- Deduplikacja: semafor na `webhook-event:<id>` obejmujący cały cykl check→mark, zagnieżdżony
  **na zewnątrz** semafora na `appId` (ten drugi chroni przed równoległym przetwarzaniem *różnych*
  eventów tego samego zgłoszenia — inny cel, zostaje).
- Log błędu zawiera `event`, `recipient`, `eventId`, żeby odróżnić duplikat od innego adresata.

## Konsekwencje
- `wasSent()` steruje też UI (`_application-short-details.html.twig`: „Wysłano do…”, numer sprawy) —
  błędne kasowanie `sent` chowa te informacje użytkownikowi.
- Ręczne narzędzie `src/tools/common.php` też nie robi tego `unset` — trzymaj obie ścieżki spójne.
