# Changelog

## v1.0 (in development)

### Stage 1
- Database schema: 11 tables (products, customers, licenses, activations,
  orders, api_clients, api_logs, security_logs, audit_logs, settings, admins)
  with InnoDB, utf8mb4, FKs, unique constraints and indexes.
- Central license server core: PDO layer, settings, HTTP helpers, CSRF,
  admin auth with brute-force lockout, logger, DB rate limiter.
- License key engine: crypto-random ARLM-XXXX-XXXX-XXXX-XXXX (Crockford
  base32), SHA-256+pepper storage, plaintext shown once.
- Domain policy: normalization (scheme/port/www/IDN), per-subdomain
  activation, localhost/IP/reserved-TLD rejection toggle.
- Ed25519 signed API responses (ext/sodium mandatory; no silent downgrade).
- REST API v1: activate, validate, deactivate, check, products; API client
  auth (hashed secrets), timestamp+request_id replay protection with
  idempotent activation, per-client rate limiting, full request logging.
- One-time web installer (tables, keys, settings, admin, config.php).
- Admin login + live dashboard shell.
- Integration test suite (tests/run-tests.php).

### Stage 2
- Full admin dashboard: 13 page modules behind a responsive layout
  (dashboard, products, licenses, generate, customers, orders, activations,
  API clients, API logs, security logs, reports, settings, system).
- Search, whitelisted sorting, pagination, CSV exports (admin-guarded) and
  CSRF-checked/bulk actions on every list page.
- License actions: bulk status change, set status (incl. pending), extend
  from expiry or today, change activation limit, transactional reset of all
  activations, single-activation admin deactivate/reactivate with counter
  correction.
- API Clients: creation with one-time Client ID + Secret display (secret
  stored SHA-256 hashed only), disable/revoke/re-enable, editable per-client
  rate limit, per-client request and error counters.
- Settings: server + API base URLs, license defaults, key prefix, rate
  limit, local-domain allowance, maintenance mode, notification emails,
  language selector, brand; store shared secret rotation with one-time
  display. The key pepper and signing keys are intentionally NOT rotatable
  from the UI (they would invalidate all issued clients/keys at once).
- System page: PHP/extension/database checks, per-table row counts,
  configuration overview; secrets never rendered.
- API hardening: rate limiting enforced on every endpoint (previously GET
  only) and MAINTENANCE_MODE (503) enforcement for the settings toggle.

### Stage 4 (store integration, SDKs, docs)
- Store purchase webhook: POST /webhooks/store/ with HMAC-SHA256 + ±300 s
  timestamp verification (constant-time), 64 KB body cap, POST-only,
  HTTPS-enforced entry point.
- Idempotent transactional fulfillment: SELECT ... FOR UPDATE on UNIQUE
  order_ref; duplicates answered from stored state (already_fulfilled,
  exactly one license per order); failed/refunded/blocked orders answer
  409; unknown/inactive product 422; blocked customer 403; everything
  logged to security/audit logs.
- License creation inside the fulfillment transaction, 5-attempt duplicate
  hash retry, one-time key disclosure in webhook JSON + delivery email;
  plaintext never stored.
- Store-side drop-in adapter sdk/store-adapter/arlm-store-notify.php
  (env-config server URL + shared secret, curl, HMAC signing).
- SDKs: sdk/php/ARLMClient.php (single-file, signed-response
  verification, fail-closed assertVerified), sdk/wordpress/ (plugin with
  nonce-guarded settings + helpers), sdk/smf/ARLM-SDK-example.php, and
  sdk/android/ARLMClient.java (HttpURLConnection + minimal JSON parser +
  pluggable Ed25519 Verifier for Tink) with SDK security documented
  (never embed secrets; client-side checks are bypassable).
- Docs: INSTALLATION-GUIDE, ADMIN-GUIDE, CUSTOMER-GUIDE, SECURITY,
  UPGRADE-UNINSTALL, TROUBLESHOOTING, STORE-INTEGRATION; README/TESTING/
  ARCHITECTURE updated.
- tests/run-tests.php: 12+ webhook cases incl. duplicate delivery,
  forged/stale/missing signatures, unknown product, blocked customer,
  refunded order, unconfigured endpoint.

### Stage 3 (server side + SMF package)
- New portal API endpoints: POST /portal_licenses and
  POST /portal_deactivate, gated by dedicated per-client permissions and
  documented in docs/API-REFERENCE.md. Plaintext keys are never exposed
  through the API (hash-only storage).
- SMF 2.1.8 installable package (smf-mod/): valid package-info.xml
  (install/uninstall with XML-validated hook tags), install/uninstall code
  that only seeds/arlm_* settings (drops nothing else), no core file edits,
  PHP 8.1 + sodium checks before install.
- SMF mod: admin area (connection dashboard + credentials/test settings
  with SMF session + token CSRF protection) and member license portal
  (index.php?action=licenses) with per-member email ownership, domain
  deactivation and status badges.
- English language file (ARLM.english.php) with all strings - Bengali and
  Hindi translations can later be added as drop-in files.
