# AR License Manager v1.0 — Security Documentation

Copyright (c) ARNIT CREATION.

## Threat model in one paragraph

Two attack surfaces exist: (1) attackers calling the public API directly
to activate stolen/made-up keys, replay old responses, or enumerate
licenses; (2) customers attempting to use software without paying (license
bypass). The server design assumes clients lie: every request is
authenticated, timestamped, idempotency-tracked, rate-limited and logged;
every response that grants anything is Ed25519-signed; and the database
never stores plaintext license keys or API secrets.

## Server-side protections implemented

| Area | Implementation |
|---|---|
| SQL injection | 100% PDO prepared statements (server) / `$smcFunc` prepared queries (SMF mod); no user input concatenated into SQL |
| CSRF | Per-session CSRF token on every admin form (`Csrf::validate()`); SMF mod uses SMF's own session token; WP SDK settings form uses a WordPress nonce |
| XSS | All admin output escaped via `View::h()` (`htmlspecialchars` + charset); templates never echo raw user input; API endpoints send `application/json` |
| Auth | `password_hash`/`password_verify` for admins; per-IP brute-force lockout (5/10 min) recorded in security logs |
| Sessions | `ARLMSESS` cookie, HttpOnly, SameSite=Strict, strict session mode |
| Secrets | API secrets sign-checked with `hash_equals`, stored only as SHA-256; store webhook secret same; one-time display pattern everywhere |
| License keys | Crockford base32 body, stored as SHA-256 hash salted with a per-install pepper (`key_pepper`); only `key_last4` is kept in clear |
| Replay | POST APIs require `timestamp` (±300 s) + `request_id`; `(client, request_id)` replays return the stored original response (`api_logs.response_json`) |
| Webhooks | `hash_hmac('sha256', rawBody, secret)` + ±300 s timestamp, constant-time compare; fulfillment is transactional and idempotent by UNIQUE `order_ref` with `SELECT ... FOR UPDATE` |
| Rate limiting | DB-backed, per-client, per-minute, enforced on every endpoint (429 RATE_LIMITED) |
| Transport | HTTPS enforced for admin, API and webhook entry points (`Http::requireHttps`, overridable only by an explicit localhost debug flag) |
| Request size | 64 KB hard cap on API and webhook bodies |
| Logging | Full audit + security logs; plaintext keys and secrets are never logged; webhook `payload` is not exposed through the API |
| Config exposure | `config/` and `storage/` are web-denied via .htaccess; sensitive settings live in the DB, not the web root |
| Crypto | Ed25519 (ext/sodium) mandatory; installer refuses without it; no silent downgrade. Clients fail closed: missing verifier, unsigned reply, or stale timestamp → refusal. A non-default waiver flag is required to disable verification |
| Maintenance mode | One settings toggle; all API endpoints answer 503 MAINTENANCE_MODE |

## Domain policy (server-enforced)

- Domains are normalized: scheme, credentials, port, path stripped; host
  lowercased; IDN → punycode; `www.` collapsed to the registrable host.
- Every activation consumes a slot per normalized host; arbitrary
  subdomains are NOT auto-authorized (the license activation limit applies).
- `localhost`, IPs, and reserved/local TLDs are rejected unless the admin
  explicitly enables local-domain allowance in Settings (dev convenience,
  audit-logged).

## Client-side licensing reality (must-read for product developers)

Any client-side check — PHP app, WordPress plugin, SMF mod, Android app —
can be bypassed by a determined attacker: decompiling, patching the check
to `return true`, or proxying HTTPS. The License Manager's signatures and
hashes protect the *server's integrity* and make response forgery and
stolen-key farming expensive; they cannot make tamper-proof client
software. Therefore:

1. Keep paid/protected functionality **server-side** where possible.
2. Never embed API secrets or private keys in distributed clients.
3. Treat the license check as a licensing convenience gate, not DRM.
4. Monitor Security Logs - the real defense against a leaked key is
   suspending/revoking it centrally.

## Verify-a-response reference (canonical string)

```
product_id \n domain \n status \n expires_at \n activation_limit \n
current_activations \n timestamp \n request_id
```

Fields exactly as sent by the server (LF separators, no trailing LF),
signature = detached Ed25519 over those UTF-8 bytes, base64url-encoded.
Verify with the public key from Admin → System Information. Reject
anything older than ±300 s or with a missing signature.

## Secrets inventory (where they live, who may see them)

| Secret | Storage | Display |
|---|---|---|
| Ed25519 private key | DB `settings.sign_private_key` (hex) | never |
| Ed25519 public key | `settings.sign_public_key`; shown in System Information | public |
| Key pepper | `settings.key_pepper` | never |
| Store shared secret | `settings.store_shared_secret`; rotatable via UI | once per rotation |
| API client secret | SHA-256 hash in `api_clients.secret_hash` | once at creation |
| DB password | `config/config.php` (web-denied) | never logged |

## What admins must do on their side

- Rotate the store shared secret away from the install default.
- Take DB backups before upgrades (signing keys + pepper live in the DB).
- Review Security Logs after any spike in `store_bad_signature` or
  failed-login events.
- Revoke and reissue API client credentials at the first sign of leakage.
