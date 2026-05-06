-- Migration to add helper tables for time series generation
-- Date: 2026-05-06

CREATE TABLE IF NOT EXISTS days (n INTEGER PRIMARY KEY);
INSERT OR IGNORE INTO days (n) VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23),(24),(25),(26),(27),(28),(29);

CREATE TABLE IF NOT EXISTS hours (n INTEGER PRIMARY KEY);
INSERT OR IGNORE INTO hours (n) VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23);
