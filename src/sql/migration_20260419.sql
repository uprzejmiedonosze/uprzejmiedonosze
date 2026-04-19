CREATE INDEX IF NOT EXISTS applications_added_idx ON applications(JSON_EXTRACT(value, '$.added'));
CREATE INDEX IF NOT EXISTS users_added_idx ON users(JSON_EXTRACT(value, '$.added'));

CREATE INDEX IF NOT EXISTS applications_brand_idx ON applications(JSON_EXTRACT(value, '$.carInfo.brand')) 
WHERE JSON_EXTRACT(value, '$.carInfo.brand') IS NOT NULL;
