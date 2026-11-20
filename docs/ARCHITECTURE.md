# AR License Manager v1.0 - Architecture
Copyright (c) ARNIT CREATION

## Components

```
+------------------------+        HTTPS/JSON        +---------------------------+
|   Customer software    |------------------------->|   Central License Server  |
| (SMF mods, WP plugins, |  X-ARLM-Client-Id/Secret |  arnitcreation.online/arlm|
|  PHP apps, Android,    |<-------------------------|  PHP 8.1+ / MySQL 5.7+    |
|  desktop apps)         |   signed Ed25519 reply   +---------------------------+
+------------------------+                                    ^
        ^                                                     | signed webhook
        | member portal (SMF session)                         |
+------------------------+        +---------------------------+
|   SMF 2.1.8 forum      |        | ARNIT store (PHP+MySQL,   |
| (license portal mod)   |        | Cashfree payments)        |
+------------------------+        +---------------------------+
```

## Central License Server

Standalone PHP 8.1+ application, no Composer dependencies, no SMF dependency.
Deployed at `https://arnitcreation.online/arlm/`.

```
license-server/
  api/v1/index.php     REST API v1 entry point (activate/validate/deactivate/check/products)
  public/index.php     Admin dashboard entry point
  install/index.php    One-time web installer (DB tables, keys, admin)
  webhooks/store/      Signed server-to-server store integration (Cashfree adapter)
  src/                 ARLM\* classes, PSR-4-like autoloader, no external libs
  config/              config.php (DB credentials; web-denied via .htaccess)
  sql/schema.sql       11 InnoDB/utf8mb4 tables with FKs and indexes
  storage/logs/        File fallback logs (web-denied)
```

## Database (prefix `arlm_` by default, configurable at install)

| Table            | Purpose |
|------------------|---------|
| settings         | name/value runtime configuration + signing keys/key pepper |
| products         | digital products, statuses `active`/`inactive` (never hard-deleted while licenses exist - FK RESTRICT) |
| customers        | SMF-linked (`smf_member_id`) or external (verified email) customers |
| licenses         | encrypted-at-rest policy: only SHA-256 hash (salted with per-install pepper) of each key is stored; status `active/expired/suspended/revoked/pending` |
| activations      | one row per (license, normalized domain), UNIQUE constraint, soft deactivation |
| orders           | store orders, idempotent by UNIQUE `order_ref`, lifecycle `pending->paid->fulfilled` |
| api_clients      | API client registry; secrets stored hashed; per-client rate limits |
| api_logs         | every API request incl. request_id + stored response (idempotency + audit) |
| security_logs    | auth failures, blocked logins, limit hits, mismatches |
| audit_logs       | admin/customer/api/system state changes |
| admins           | license-server admin accounts (password_hash/verify) |

Critical operations (activation, fulfillment) run inside transactions.

## API contract

- Base: `https://arnitcreation.online/arlm/api/v1/`
- Auth headers: `X-ARLM-Client-Id`, `X-ARLM-Secret` (hashed at rest).
- POST bodies also include `timestamp` (±300 s skew) and `request_id`
  (idempotency). Replays of a `(client, request_id)` pair return the stored
  original response.
- Every license response payload is signed with Ed25519 (ext/sodium).
  If sodium is missing the installer refuses to complete; there is no
  silent downgrade. See docs/API-REFERENCE.md for the canonical string.

## Stage status

- Stage 1 (COMPLETE): schema, server core, key generator, domain policy,
  signatures, rate limiting, API client auth, license engine, API v1
  router, installer, admin login + dashboard shell.
- Stage 2 (PLANNED): full admin pages (products, licenses, customers,
  orders, activations, API clients, logs, reports, settings).
- Stage 3 (PLANNED): SMF 2.1.8 package + customer portal.
- Stage 4 (PLANNED): store webhook adapter, SDKs, docs, packaging, grading.
