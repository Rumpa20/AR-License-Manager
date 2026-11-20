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
