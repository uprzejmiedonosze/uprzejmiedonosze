<?php
define('PLATERECOGNIZER_SECRET', 'contact author');
define('OPEN_ALPR_SECRET_1', 'contact author');
define('OPEN_ALPR_SECRET_2', 'contact author');
define('MAPBOX_API_TOKEN', 'contact author');
define('GOOGLE_MAPS_API_TOKEN', 'contact author');

define('OPENAI_API_KEY', 'see README.md');
define('OPENAI_PROJECT', 'proj_SOMEID');

define('MAILER_FROM', 'u@dka.email');
define('MAILER_FROM_ALTER', 'u@dka.email');
define('EMAIL_SENDER', 'u@dka.email');
define('MAILER_DSN', 'smtp://mailpit:1025');
define('MAILER_DSN_ALTER', 'smtp://mailpit:1025');

define('CRYPTO_KEY', '7700bcc0327517849e966dd169791439');
define('CRYPTO_IV', '330adc20ed7dcf8561ff4869dd434b85');
define('CRYPTO_TAG', '190181339ab13a971415e977b736053f');

define('BACKEND_API_KEY', 'dev-secret-key-change-in-production');
define('CORS_ALLOWED_DOMAIN', 'localhost'); // includes subdomains

// S3-compatible object storage (Hetzner) — only active in production (isProd()).
// Not needed in dev/staging, but constants must be defined.
define('S3_KEY',      '');
define('S3_SECRET',   '');
define('S3_BUCKET',   '');
define('S3_ENDPOINT', 'https://fsn1.your-objectstorage.com');
define('S3_REGION',   'eu-central-1');
