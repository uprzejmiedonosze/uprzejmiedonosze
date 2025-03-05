CREATE INDEX applications_status_idx ON applications(JSON_EXTRACT(value, '$.status'));
CREATE INDEX applications_status_number ON applications(JSON_EXTRACT(value, '$.number'));
