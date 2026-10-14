---
name: new-migration
description: Dodaj nową tabelę, kolumnę lub indeks w SQLite — schemat, plik migracji, funkcje store i test. Use when a change needs a new table/column/index in store.sqlite, or the user asks for a database migration.
---

# Migracja SQLite

Wzór całego przepływu: commit `c9afca79` (tabela `photo_usage`): `git show --stat c9afca79`.
Konwencje warstwy store: `src/inc/AGENTS.md`.

Najpierw sprawdź, czy migracja jest potrzebna: `applications` i `users` trzymają dane jako JSON w kolumnie
`value` (`json_extract(value, '$.pole')`). Nowe pole zgłoszenia/użytkownika zwykle **nie wymaga** migracji —
dodaj je w dataclassie. Migracja jest dla nowych tabel i indeksów (np. pod `json_extract` w `WHERE`).

## Kroki

1. **`src/sql/base_schema.sql`** — dopisz `CREATE TABLE IF NOT EXISTS …` / `CREATE INDEX IF NOT EXISTS …`
   (świeże instalacje).
2. **`src/sql/migration_YYYYMMDD_<slug>.sql`** — ta sama treść, idempotentna (`IF NOT EXISTS`), z komentarzem
   po polsku na górze: po co tabela i który plik store jej używa. Data = dzisiejsza.
   SQLite nie ma `ADD COLUMN IF NOT EXISTS` — przy nowej kolumnie zaznacz w komentarzu, że migracja nie jest
   idempotentna.
3. **Store** — nowy `src/inc/store/<Nazwa>.php` z `<?PHP namespace <nazwa>;`, funkcje (nie klasa) na
   `\store\prepare()`/`\store\query()`, stała `TABLE`. Dodaj `require` w `src/inc/store/index.php`.
   Wzór: `src/inc/store/PhotoQuota.php`.
4. **Baza testowa/dev** — fixture jest w repo i musi dostać nową tabelę, inaczej testy nie przejdą:
   ```bash
   sqlite3 services/devroot/db/store.sqlite < src/sql/migration_YYYYMMDD_<slug>.sql
   ```
   (ten sam plik montuje lokalny `webapp`, więc dev też jest gotowy). Plik `.sqlite` trafia do commita.
5. **Test** — `tests/store/<Nazwa>Test.php` dziedziczący po `UprzejmieDonosze\Tests\DatabaseTestCase`
   (transakcja + rollback). Wzór: `tests/store/PhotoQuotaTest.php`. Uruchom `make test`.
6. **Sprzątanie danych** — jeśli tabela rośnie w czasie, rozważ wpis w `src/tools/cleanup.php` (jak `photo_usage`).

## Staging / prod — NIE uruchamiaj sam

Migracje nie są aplikowane przy deployu. Po wdrożeniu kodu podaj użytkownikowi komendy do uruchomienia:

```bash
ssh uprzejmiedonosze.net "docker exec -i staging-webapp-srv-1 sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite" < src/sql/migration_YYYYMMDD_<slug>.sql
ssh uprzejmiedonosze.net "docker exec -i prod-webapp-srv-1 sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite" < src/sql/migration_YYYYMMDD_<slug>.sql
```
Shadow współdzieli bazę z prod — nie wymaga osobnej migracji. Kolejność: najpierw migracja, potem deploy kodu,
który z niej korzysta (albo kod odporny na brak tabeli).
