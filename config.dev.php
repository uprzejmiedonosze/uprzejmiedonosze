<?php
// Dev bootstrap — committed fixture config (pairs with services/devroot/db/store.sqlite).
// Loaded in Docker dev via compose mount; copied to export/ by watch.sh as a fallback.
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

// S3-compatible object storage (Backblaze B2) — only active in
// production/staging (isEnabled()). Not needed in dev, but constants must be defined.
define('B2_KEY',      '');
define('B2_SECRET',   '');
define('B2_BUCKET',   '');
define('B2_ENDPOINT', 'https://s3.eu-central-003.backblazeb2.com');
define('B2_REGION',   'eu-central-003');

// MCP OAuth 2.1 provider (dev fixtures). league needs an internal keypair +
// encryption key; access tokens are opaque so no public JWKS is exposed.
define('OAUTH_ENCRYPTION_KEY', '8jCHkuE7SvHcmz+qFW8e9vWOVqPTAK9w3wpBT2mWBFI=');
define('OAUTH_PRIVATE_KEY', <<<'OAUTHKEY'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDcNPQa+vx/+0Z+
4CiJma56g1YwEBERymtNPvM2YjcwcJW7yid96oxwnDSlCpPSyRgU2C09H2geVBBE
PA6LR3o1KCjFYpcI37BQ+L+tpHsnnFU1OJPsycroegmvCSHQtRHYvlq35MO9TmrB
R0CECvtlP0Wo6o/vqjmDQor77bw0k0qkGVMGXMFbFdnBNSocJ5E5LR4ltAA0S14M
Ho6vfkf7GVTl7zD4usYl/VY/3KATjDmI0Z/L852yZG6eVyQ7MtnzB/lIrnxr/fqm
ZsDPW3N4hGbPXQ+QswazGBtkURclZK6xjkSh5VaBE+lr9R+j0DOuvUn2IBifMbrO
lKyEU2FRAgMBAAECggEANs7gRfnb0F/ilTAmLs9nL7uSP160XOz4hY6qOso5yc7v
2cBwUWUIRPwAF2b5UYC3Q3Ll2Z2ATPIn5U/cX9qvxlzDPxOxm2YPjKvJC4dRltOQ
mrFFEi3MmM3NLLl1Zuy2by+7xSMFfA/xPr+FBYh1N2dG54rQPfrsmyi8DoXGrv/W
1MgrhLw++FFbm8Qn7hXnDFSSTwQLEOWsNpucqExMMYL9UpnceJhK6HY3OmC90s0K
tQvx5awny6jUoHg0LL+m3aGPTVuKM2VyS35YoSU0APlnTWV8FDooOWSwCx+uoOXZ
o6MnhnDRg8rWD10zIsail/N7rSiNchFtX5PZ9F9K5QKBgQD1qaVBZq4ESaDK8z8L
Kb31jZqqOk9o+8tAjALA1DDLoaOR4SZlLDCz8GNgDA+6aXylH06DfSTGpiGSWnz6
H+v2VGjkzQHF+4Rhs5rIw1h59V6IIU99tUPsI2w4YMyzVi+b6ilkL6mcYFHKiPre
2zm7UZgfDAQkFVCoNndRBTtvPQKBgQDleRcGYl93wfT/iDlWCqShxIYkd1kuAve6
FsKTEEp7/tK1nEkXV/9ztacGMHlDccKPboksYHqUDV6bcXlYOojepeCRrleLDsGx
ktYS9m5IDhscbY+dxAe9EOwm0NA/b9N7dZ5it+sFMg8/jAwT6AXfpmaPOcj8dVZk
RUz4HgJbpQKBgQCwMFKofFcUFiZvSFQP0ok+AqhJrHZlqikVCxWybLzuXuhsaNlb
uHzZoO/049Gn9Z4C41gxL+DfZCkxyRpXXeujCNkOOAYsk35XgDPkB05+cb+xzIox
c37abnFgYfSOLqMIpMG47AIueFpQ8ztR+FMIiLWclsalhnAJpL6gais9VQKBgAOF
vlK8w9Zkxcv+XVLyyuAo0h5RLq9EIGVc4BO91kbc/IMJKR4Qnb069ptjtxjP8Dqf
ab+io38OTXt5XHF8RImWZkIWOQXLbjG5nUuhOMQmY0gmbmPvlGbUkatu3SN8JfTp
M0s0o4jq4c0J602K7Dwoo75jFrC5ZGAZqDrOyx55AoGAQpNbc4Mhs03njOrv9Gf8
tuKTdPLqbfPIUaf4pGlFuF6V22egB5+WgfY9IRNPF3MrYSwWKtqMCfIpzINOthmC
4RBfMV4/4WF5GGXgL7AIK37ZcI8Zvo8o0VHIINJcGyPaUnKmLZ5yhou7xhZx87dK
O7qYXysfZbBPfhYQZaQQXxk=
-----END PRIVATE KEY-----
OAUTHKEY);
