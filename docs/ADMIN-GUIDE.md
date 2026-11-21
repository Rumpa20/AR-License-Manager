# AR License Manager v1.0 — Administrator Guide

Copyright (c) ARNIT CREATION.

## Logging in

Open the license server admin URL (`.../arlm/public/index.php`). Sessions are
strict (HttpOnly, SameSite=Strict). Brute-force protection: 5 failed
logins per IP per 10 minutes are locked and written to Security Logs.

## The 13 admin modules

| Menu | What you do here |
|---|---|
| **Dashboard** | Live totals (products, licenses by status, activations), recent activities, recent orders, per-product stats |
| **Products** | Create/edit/enable/disable products. Deleting a product with existing licenses/orders is refused by foreign-key RESTRICT - history is preserved; disable instead of delete |
| **All Licenses** | Search, filter by status/product, sort, paginate, CSV export. Row actions: view, edit, suspend/reactivate/revoke, extend expiry (from current expiry or today), change activation limit, reset activations (transactional, clears all domain bindings safely) |
| **Generate License** | Creates a key for a product/type/limit/duration. Plaintext key shown exactly once, then only the hash remains |
| **Customers** | Search customers, view their licenses/orders/activations, assign manual licenses, block/unblock customers. SMF-linked customers show their member ID |
| **Orders** | Store orders with lifecycle `pending → paid → fulfilled`, source store, payload status, linked license |
| **Activations** | Every domain activation with limits; admin can force-deactivate/reactivate a single domain (counter-accurate) |
| **API Clients** | Register clients, one-time secret display, revoke/disable/re-enable, per-client rate limit, per-client usage + error counts, per-endpoint permissions |
| **API Logs** | Who called what, when, response status, request_id replay trace, CSV export |
| **Security Logs** | Auth failures, bad webhook signatures, rate-limit hits, unknown products, blocked customers - audit these regularly |
| **Reports** | Monthly license generation, active vs expired, product usage, activation stats, API usage, order fulfillment, security events - all CSV-exportable (admin-only, hash-only) |
| **Settings** | URLs, license defaults, key prefix, global rate limit, local-domain allowance, maintenance mode, notification addresses, language, store secret rotation |
| **System Information** | PHP/extensions/DB checks, table row counts, Ed25519 public key for SDKs |

## Status changes that matter

- **Suspend** → temporary, reversible (`Reactivate`).
- **Revoke** → permanent kill; every activation attempt then fails with
  `LICENSE_REVOKED`.
- **Extend expiry** → pick a date or add days; extending an expired license
  into the future returns it to `active`.
- **Reset activations** → clears all domain bindings for that license and
  zeroes `current_activations` inside a transaction. Use for reinstalls.

## Maintenance mode

Settings → Maintenance Mode ON: every API endpoint answers
`503 MAINTENANCE_MODE`. The admin dashboard keeps working; customer
activations pause until you switch it off.

## Store webhook secret

A random store shared secret exists from installation. Rotate it any time
from Settings → Store Integration - the new value is shown ONCE; update the
store-side adapter at the same time (both sides must match or every webhook
fails with `401 BAD_SIGNATURE`).

## Reading security logs

| Event | Meaning |
|---|---|
| `store_bad_signature` | webhook with a wrong/missing HMAC - secret mismatch or an attacker |
| `store_bad_timestamp` | replayed or clock-skewed webhook |
| `store_unknown_product` | webhook for an unknown/inactive `product_uid` |
| `store_blocked_customer` | paid order for a blocked customer (not fulfilled) |
| `store_fulfillment_error` | transaction rolled back - inspect before retrying |
| `store_webhook_replay` | duplicate webhook correctly answered from stored result |
| failed admin logins | lockout events with IP |

## Golden rules

1. The signing keys and key pepper are deliberately NOT rotatable from the
   UI - rotating either invalidates every issued license at once.
2. Plaintext keys are never stored or logged; to reissue, revoke the old
   license and generate a new one.
3. CSV exports are admin-guarded and hash-only - exports never contain
   plaintext keys or secrets.
4. Least-privilege API clients: never give a store integration client portal
   permissions, and never give the SMF portal client activation permissions.
