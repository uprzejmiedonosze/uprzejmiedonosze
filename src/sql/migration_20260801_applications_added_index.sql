-- getInactiveUsers/getWarnedUsers/getUsersToRemove (src/inc/store/Admin.php)
-- aggregate max(json_extract(value, '$.added')) grouped by email over the
-- full `applications` table. The existing `application_email` index only
-- covers `email`, so SQLite still has to read the `value` column off the
-- main table for every row -- on prod (409k rows, 5.3GB file) that scan
-- measured ~247s. This is a covering index: (email, added) lets SQLite
-- satisfy the group-by/max entirely from the index, without touching the
-- table's row data.
--
-- Apply with the other migrations (sqlite3 db/store.sqlite < this-file).
-- Building the index takes a write lock for its duration -- run outside
-- peak traffic.

CREATE INDEX IF NOT EXISTS applications_email_added
ON applications(email, json_extract(value, '$.added'));
