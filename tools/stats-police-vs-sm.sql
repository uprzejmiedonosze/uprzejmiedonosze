-- Proporcja zgłoszeń wysłanych na Policję vs Straż Miejską, w rozbiciu na miesiące.
--
-- W bazie NIE MA flagi "policja/SM". Adresat jest odtwarzany dopiero przy
-- renderowaniu, przez SM::resolve() (src/inc/dataclasses/SM.php), z dwóch pól
-- JSON-a zgłoszenia: $.smCity (klucz jednostki) i $.stopAgresji. Adres e-mail
-- odbiorcy ($.sent.to) jest zaszyfrowany (Application::encode()), więc nie da
-- się po nim filtrować. Poniższe zapytanie odtwarza logikę SM::resolve() 1:1:
--
--   if ($stopAgresji) return $POLICE_ADDRESSES[$key] ?? $STOP_AGRESJI[$key] ?? $STOP_AGRESJI['default'];
--   return $SM_ADDRESSES[$key] ?? $POLICE_ADDRESSES[$key] ?? $SM_ADDRESSES['_nieznane'];
--
--   stopAgresji = true          -> policja (Police/StopAgresji::isPolice() === true)
--   klucz w sm.json             -> sm      (SM ma pierwszeństwo przy kluczach obecnych w obu plikach)
--   klucz w police.json         -> policja
--   reszta (_nieznane, null)    -> nieznane (w UI renderuje się jako SM-nieznane)
--
-- Listy kluczy czytane są wprost z wdrożonych plików configu (readfile + json_each),
-- więc nie trzeba wklejać ~1000 kluczy i nie zdezaktualizują się po sm-parser.js.
--
-- ZAKRES DAT: zmień trzy literały poniżej ('2026-05-01', '2026-08-01' oraz nazwy
-- w komentarzu). Górna granica jest wyłączna.
--
-- URUCHOMIENIE:
--   ssh nieradka.net 'sqlite3 -readonly -box /var/www/uprzejmiedonosze.net/db/store.sqlite' < tools/stats-police-vs-sm.sql
--
-- Wariant liczony po FAKTYCZNEJ dacie wysyłki (a nie utworzenia) -- patrz na dole pliku.

-- =============================================================================
-- Wariant A: oś czasu = $.added (data utworzenia). Indeksowana, szybka.
-- Zgłoszenia są wysyłane praktycznie od razu po utworzeniu, więc dla proporcji
-- miesięcznych różnica względem daty wysyłki jest pomijalna.
-- =============================================================================

WITH
cfg(dir) AS (VALUES('/var/www/uprzejmiedonosze.net/webapp/public/api/config/')),
sm_keys(k)     AS (SELECT j.key FROM cfg, json_each(readfile(cfg.dir||'sm.json'))     j WHERE j.key <> '_nieznane'),
police_keys(k) AS (SELECT j.key FROM cfg, json_each(readfile(cfg.dir||'police.json')) j),
wyslane AS (
  SELECT substr(json_extract(a.value,'$.added'),1,7)           AS miesiac,
         lower(coalesce(json_extract(a.value,'$.smCity'),''))  AS sm_city,
         coalesce(json_extract(a.value,'$.stopAgresji'),0)     AS stop_agresji
  -- INDEXED BY: bez tego planer wybiera applications_status_idx i przemiela
  -- prawie całą tabelę (409k wierszy); po $.added schodzi do zakresu 3 miesięcy.
  FROM applications a INDEXED BY applications_added_idx
  WHERE json_extract(a.value,'$.added') >= '2026-05-01'
    AND json_extract(a.value,'$.added') <  '2026-08-01'
    -- statusy po wysyłce; 'archived' celowo pominięte (zgłoszenie mogło zostać
    -- zarchiwizowane już po wysłaniu -- dopisz je, jeśli ma się liczyć)
    AND json_extract(a.value,'$.status') IN
        ('confirmed-waiting','confirmed-waitingE','confirmed-sm',
         'confirmed-fined','confirmed-instructed','confirmed-ignored','confirmed-complaint')
),
klas AS (
  SELECT miesiac,
         CASE WHEN stop_agresji                           THEN 'policja'
              WHEN sm_city IN (SELECT k FROM sm_keys)     THEN 'sm'
              WHEN sm_city IN (SELECT k FROM police_keys) THEN 'policja'
              ELSE 'nieznane' END AS adresat
  FROM wyslane
)
SELECT miesiac,
       count(*)                                          AS total,
       sum(adresat='policja')                            AS policja,
       sum(adresat='sm')                                 AS sm,
       sum(adresat='nieznane')                           AS nieznane,
       round(100.0*sum(adresat='policja') /count(*),2)   AS policja_pct,
       round(100.0*sum(adresat='sm')      /count(*),2)   AS sm_pct,
       round(100.0*sum(adresat='nieznane')/count(*),2)   AS nieznane_pct
FROM klas
GROUP BY miesiac
ORDER BY miesiac;

-- =============================================================================
-- Wariant B: oś czasu = faktyczna data wysyłki.
-- $.sent.date jest zaszyfrowane, ale $.statusHistory NIE JEST -- bierzemy
-- pierwsze przejście na confirmed-waiting / confirmed-waitingE. Okno po $.added
-- jest poszerzone o miesiąc z każdej strony (indeks nadal działa), a właściwy
-- filtr idzie po dacie wysyłki. Wolniejsze niż wariant A.
--
-- UWAGA: json_each() ma własną kolumnę "value". Niekwalifikowane `value`
-- w skorelowanym podzapytaniu CICHO rozwiąże się do kolumny json_each zamiast
-- do applications.value -- zapytanie zwróci wtedy zero wierszy bez błędu.
-- Dlatego wszystkie odwołania są kwalifikowane aliasem (a.value, h.value, j.key).
-- =============================================================================

-- WITH
-- cfg(dir) AS (VALUES('/var/www/uprzejmiedonosze.net/webapp/public/api/config/')),
-- sm_keys(k)     AS (SELECT j.key FROM cfg, json_each(readfile(cfg.dir||'sm.json'))     j WHERE j.key <> '_nieznane'),
-- police_keys(k) AS (SELECT j.key FROM cfg, json_each(readfile(cfg.dir||'police.json')) j),
-- wyslane AS (
--   SELECT (SELECT min(h.key) FROM json_each(json_extract(a.value,'$.statusHistory')) h
--            WHERE json_extract(h.value,'$.new') IN ('confirmed-waiting','confirmed-waitingE')) AS wyslano,
--          lower(coalesce(json_extract(a.value,'$.smCity'),''))  AS sm_city,
--          coalesce(json_extract(a.value,'$.stopAgresji'),0)     AS stop_agresji
--   FROM applications a INDEXED BY applications_added_idx
--   WHERE json_extract(a.value,'$.added') >= '2026-04-01'
--     AND json_extract(a.value,'$.added') <  '2026-09-01'
-- ),
-- klas AS (
--   SELECT substr(wyslano,1,7) AS miesiac,
--          CASE WHEN stop_agresji                           THEN 'policja'
--               WHEN sm_city IN (SELECT k FROM sm_keys)     THEN 'sm'
--               WHEN sm_city IN (SELECT k FROM police_keys) THEN 'policja'
--               ELSE 'nieznane' END AS adresat
--   FROM wyslane
--   WHERE wyslano >= '2026-05-01' AND wyslano < '2026-08-01'
-- )
-- SELECT miesiac, count(*) AS total,
--        sum(adresat='policja') AS policja, sum(adresat='sm') AS sm, sum(adresat='nieznane') AS nieznane,
--        round(100.0*sum(adresat='policja')/count(*),2) AS policja_pct,
--        round(100.0*sum(adresat='sm')     /count(*),2) AS sm_pct
-- FROM klas GROUP BY miesiac ORDER BY miesiac;
