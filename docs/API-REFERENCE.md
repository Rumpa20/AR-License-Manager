# AR License Manager - API Reference v1
Copyright (c) ARNIT CREATION

Base URL: `https://arnitcreation.online/arlm/api/v1/` (HTTPS required)

## Authentication

| Header              | Value                                      |
|---------------------|--------------------------------------------|
| X-ARLM-Client-Id    | 32-char client id (Admin > API Clients)    |
| X-ARLM-Secret       | client secret (shown once at creation)     |

POST requests must also include in the JSON body:

- `timestamp` - unix seconds; rejected when more than 300 s from server time
- `request_id` - 1-64 chars `[A-Za-z0-9_.:-]`, unique per logical request

Repeating a `request_id` returns the original stored response (idempotent
activation, replay protection). Wrong credentials => 401 AUTH_INVALID.
Per-client rate limiting: HTTP 429 RATE_LIMITED.

## Endpoints

### POST /activate
```json
{
  "license_key": "ARLM-XXXX-XXXX-XXXX-XXXX",
  "product_id":  "PRODUCT-001",
  "domain":      "example.com",
  "timestamp":   1732250000,
  "request_id":  "unique-req-1"
}
```
Success (200):
```json
{
  "success": true,
  "status": "active",
  "message": "License activated successfully",
  "product_id": "PRODUCT-001",
  "product_name": "My Mod",
  "license_type": "single_domain",
  "domain": "example.com",
  "expires_at": null,
  "activation_limit": 1,
  "current_activations": 1,
  "timestamp": 1732250000,
  "request_id": "unique-req-1",
  "signature": "BASE64URL_ED25519_SIGNATURE"
}
```
Errors: 400 BAD_REQUEST / DOMAIN_INVALID / REQUEST_ID_REQUIRED / BAD_TIMESTAMP,
401 AUTH_INVALID, 403 PRODUCT_MISMATCH / PRODUCT_INACTIVE / LICENSE_* /
CUSTOMER_SUSPENDED / PERMISSION_DENIED, 404 LICENSE_NOT_FOUND / DOMAIN_NOT_ACTIVATED,
409 LIMIT_REACHED, 429 RATE_LIMITED.

Idempotency: activating an already-activated domain returns 200 with the
current state and does not consume another slot ("License already activated
on this domain."). Reactivating a previously deactivated domain restores the
slot without consuming a new one.

### POST /validate
Same body. Full verification for an activated domain. Does NOT consume an
activation. Error 404 DOMAIN_NOT_ACTIVATED when the domain isn't bound.

### POST /deactivate
Same body. Frees the activation slot. Succeeds even when the domain was
already inactive.

### POST /check
Same body; `domain` optional. Returns license status/expiry/usage only.

### GET /products
Lists active products: `product_uid, name, version, category,
default_license_type, default_activation_limit`.

## Signature scheme (Ed25519)

Canonical string (UTF-8, `\n` separators):
```
product_id \n domain \n status \n expires_at \n
activation_limit \n current_activations \n timestamp \n request_id
```
`expires_at` is the exact JSON value (empty string when null);
counts are decimal integers; timestamp unix seconds.

`signature = base64url(Ed25519_detached(canonical, PRIVATE_KEY))`

Verify client-side with the public key from install output
(`sdk/php/ARLMClient.php` does this automatically). The private key lives
only in the license-server settings table. If ext/sodium is unavailable the
server refuses to install - it never silently downgrades.

## Domain policy

- Normalized: lowercase; scheme/port/path stripped; IDN -> punycode (intl);
  one leading `www.` removed.
- **Each subdomain is its own activation** (no wildcard authorization).
- Localhost, bare IPs, `.local/.test/.localhost` etc. are rejected unless
  `allow_local_domains` is enabled in admin settings (for dev/testing).
