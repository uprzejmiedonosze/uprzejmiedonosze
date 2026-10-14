# Słownik domeny (PL ↔ kod)

Kod miesza polski i angielski. Nowe nazwy w kodzie: **angielskie**, chyba że termin jest nazwą własną domeny
(np. `recydywa`, `stopAgresji`) — wtedy zachowaj istniejący.

| Termin (PL) | W kodzie | Znaczenie |
|---|---|---|
| zgłoszenie | `Application` (`\app\`), tabela `applications` | Zgłoszenie wykroczenia parkingowego wysyłane do SM/Policji |
| numer zgłoszenia | `$app->number`, format `UD/<userNumber>/<n>` | Numer widoczny dla ludzi; ≠ `id` (losowy, np. `DGwu66uMCYBb`) |
| zgłaszający / użytkownik | `User` (`\user\`), tabela `users` | Obywatel; identyfikowany e-mailem |
| SM (Straż Miejska / Gminna) | `SM`, `sm.json`, `Application::smCity` | Adresat zgłoszenia; ma priorytet nad Policją (ADR 0001) |
| Policja / komisariat | `Police`, `police.json`, `police-stations.csv` | Adresat, gdy brak SM albo tryb Stop Agresji |
| Stop Agresji Drogowej | `StopAgresji`, `stopAgresji`, `stop-agresji.json` | Tryb zgłaszania do Policji (wykroczenia drogowe) |
| recydywa | `Recydywa`, `RecydywaStore` (`\recydywa\`) | Ten sam numer rejestracyjny zgłoszony wielokrotnie |
| kategoria | `Category`, `categories.json` | Rodzaj wykroczenia (podstawa prawna, opis) |
| rozszerzenie | `Extension`, `extensions.json` | Dodatkowe okoliczności dopisywane do kategorii |
| status | `Status`, `statuses.json` | Cykl życia: `draft` → `ready` → `confirmed` → `sending` → `confirmed-waiting` → `confirmed-fined`/`-instructed`/`-ignored`/… → `archived` |
| mandat / pouczenie | `confirmed-fined` / `confirmed-instructed` | Wynik zgłoszenia raportowany przez użytkownika |
| zażalenie | `confirmed-complaint` | Zażalenie na brak reakcji |
| wysyłka | `sendApplication()`, MailGun / `CityAPI` | Mailem (Mailgun) albo przez API miasta (np. Poznań) |
| e-Doręczenia / ePUAP | `edelivery`, `confirmed-waitingE` | Wysyłka elektroniczną skrzynką urzędową |
| zdjęcie kontekstowe / tablicy | `contextImage` / `carImage` | Dwa zdjęcia zgłoszenia; `contextImage` to dowód, nie modyfikujemy go (ADR 0003) |
| tablica rejestracyjna | `plateId`, `carInfo` | Rozpoznawana przez ALPR (PlateRecognizer/OpenALPR) |
| generator (pism) | `\generator\`, `ApiAiHandler`, `Petition` | Generator wniosków/petycji do urzędów (AI) |
| patron | `Patronite` | Wspierający projekt; wpływa m.in. na limity zdjęć (`PhotoQuota`) |
| odznaki / poziomy | `badges.json`, `Level`, `levels.json` | Gamifikacja aktywności użytkownika |
| galeria | `galeria.html.twig`, `generateGalleryImages()` | Publiczne miniatury zgłoszeń (po rozmyciu twarzy) |
| rd25 | `redesignClass = 'rd25'`, `$mpr-*` | Redesign 2025 — obecny system wizualny |
