ALTER TABLE applications
ADD plateId VARCHAR;

CREATE INDEX IF NOT EXISTS application_plateId
ON applications(plateId);

UPDATE applications
SET plateId = upper(json_extract(value, '$.carInfo.plateId'));
