# AR License Manager v1.0 — Troubleshooting

Copyright (c) ARNIT CREATION.

## Installer problems

**"sodium extension missing" / installer refuses PHP version** — the
installer hard-fails on purpose (no silent downgrades). Enable `sodium` and
`pdo_mysql` in DirectAdmin → Select PHP Version / PHP settings, or ask your
host to enable them; PHP must be >= 8.1.

**"config not writable"** — set `config/` to writable (e.g. 755/775, config
file 664) only for the duration of the install, then restore 644.

**Installer says already installed** — `config.php` already has
`installed => true`. If this is a genuine reinstall, fix/remove
`config/config.php` manually; do not run the installer twice over live data.

## Admin dashboard

**Locked out ("too many failed logins")** — 5 failures per IP per 10
minutes trigger a lockout recorded in Security Logs. Wait 10 minutes, then
log in correctly. The lock is DB-timed; no file needs clearing.

**Blank page / 500 after upload** — check the hosting error log
(DirectAdmin → logs). Most common cause: PHP version below 8.1 or a missing
`mbstring` extension.

**CSRF token error on save** — the session expired between loading and
submitting the form. Reload the page and submit again.

## API / SDK errors (server responses, machine-readable `error_code`)

| code | HTTP | Meaning and fix |
|---|---|---|
| `AUTH_REQUIRED` | 401 | `X-ARLM-Client-Id`/`X-ARLM-Secret` headers missing entirely |
| `AUTH_INVALID` | 401 | wrong secret, disabled or revoked client, or wrong permission scope for the endpoint |
| `BAD_TIMESTAMP` | 400 | request `timestamp` missing or outside +/− 300 s → clock skew; fix NTP |
| `BAD_REQUEST` | 400 | malformed/oversized body, missing required fields, malformed key format |
| `PERMISSION_DENIED` | 403 | client lacks this endpoint in its permission list — check Admin → API Clients |
| `RATE_LIMITED` | 429 | per-minute client limit hit — back off, raise the limit in API Clients |
| `MAINTENANCE_MODE` | 503 | server maintenance toggle is ON — switch it off in Settings |
| `LICENSE_NOT_FOUND` | 404 | key does not exist (typo, wrong environment) |
| `PRODUCT_MISMATCH` | 404 | key exists but belongs to another product — check `product_id` |
| `PRODUCT_INACTIVE` | 404 | product is disabled — enable it or use the right product |
| `DOMAIN_INVALID` | 400 | domain failed normalization (localhost/IP unless allowed, malformed host) |
| `DOMAIN_NOT_ACTIVATED` / `DOMAIN_NOT_FOUND` | 404 | validate/deactivate called for a domain not activated on this license |
| `LIMIT_REACHED` | 403 | activation slots exhausted — deactivate an old domain or raise the limit |
| `LICENSE_EXPIRED` / `LICENSE_SUSPENDED` / `LICENSE_REVOKED` / `LICENSE_PENDING` / `LICENSE_INACTIVE` | 403 | license state — see docs/CUSTOMER-GUIDE.md |
| `CUSTOMER_SUSPENDED` | 403 | the owning customer account is blocked |
| `UNKNOWN_ENDPOINT` | 404 | wrong URL path |

## SDK-side failures (client throws instead of returning data)

- **"signature verification FAILED"** — public key mismatch (use the key
  shown in Admin → System Information), tampered/MTM response, or you
  configured a different environment's server. Verification is fail-closed
  on purpose.
- **"no Ed25519 verifier registered" (Android)** — register a Tink-backed
  `Verifier` with `client.setVerifier(...)`; see `sdk/android/README.md`.
- **"response timestamp is stale"** — client/server clock skew > 5 minutes.
- **"sodium extension missing" (PHP SDK)** — install ext/sodium on the
  machine running the SDK; it refuses unsigned trust by default.

## SMF package

**Package manager refuses to install** — confirm the ZIP root contains
`package-info.xml` directly (not nested in a folder); confirm the forum runs
SMF 2.1.x, PHP 8.1+ with `sodium` (the pre-check messages state the exact
missing requirement).

**Menu item missing after install** — hooks apply on the next admin page
load; clear SMF cache (Admin → Maintenance → Clean cache), verify
`Sources/ARLM.php` exists and is readable.

**Portal shows no licenses** — licenses are matched by forum email. The
email on the forum account must match the store purchase email; the admin
can also link a member manually (Customers in the license server).

**Test Connection fails** — check the URL (must point at the license server
root, e.g. `https://arnitcreation.online/arlm`), client credentials +
`portal_licenses`/`portal_deactivate` permissions, and that the forum can
make outbound HTTPS requests (some hosts block outgoing curl/stream).

## Store webhook

| Response | Meaning |
|---|---|
| 503 `STORE_NOT_CONFIGURED` | `store_shared_secret` setting empty — set it in Admin → Settings |
| 401 `BAD_SIGNATURE` | HMAC mismatch — secret out of sync between store and server, or the body was transformed in transit (sign the **raw** body bytes, before any JSON re-encoding) |
| 400 `BAD_TIMESTAMP` | ±300 s window exceeded or missing header |
| 400 `BAD_REQUEST` | missing/invalid `order_ref`, `product_uid` or `customer.email` |
| 422 `PRODUCT_NOT_FOUND` | unknown or inactive `product_uid` on the license server |
| 403 `CUSTOMER_BLOCKED` | buyer's customer record is blocked — order was **not** fulfilled |
| 409 `ORDER_NOT_FULFILLABLE` | order previously marked `failed`/`refunded`/`blocked` — never auto-fulfilled |
| 200 with `already_fulfilled: true` | duplicate delivery handled safely (idempotent), no second license created |

A webhook that already succeeded once keeps echoing the same license id on
retries — by design, so payment providers can retry safely.

## Data questions

**"I lost a plaintext key"** — that cannot be recovered; keys are stored
hash-only. Revoke the license and generate a new one.

**"Customer says activation counted twice"** — check Activations for the
normalized domain; `www.` collapses to the same host = same slot. Use the
admin per-domain force-deactivate to correct, or Reset Activations on the
license for a clean slate.
