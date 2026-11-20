# AR License Manager - Test Report

Policy: only actually executed checks are reported as passed.

## Executed in the build sandbox (Stage 1)

Tooling available: Python 3.12, zip. No PHP or MySQL runtime, no network
inbound/outbound for installing runtimes - therefore:

- PHP syntax linting was NOT executed. Compensation: a custom structural
  checker (tests/php-check.py style - brace/paren/bracket/quote state
  machine over PHP segments) was run over all 20 PHP files: all balanced.
- XML validation not yet applicable (package-info.xml comes with the SMF
  mod in Stage 3; will be validated with an XML parser then).
- SQL was reviewed manually; it could NOT be executed in the sandbox.
  The installer and test suite will exercise it on a real host.

## Defined but NOT yet executed (requires PHP 8.1+ sodium/pdo_mysql host)

tests/run-tests.php covers: key format; duplicate key hash unique index;
valid activation incl. www/scheme normalization; Ed25519 signature verify;
idempotent replay; repeat activation not consuming a slot; unknown license;
wrong product; invalid domain; localhost rejection; expired/suspended/
revoked/pending states; activation limits and slot reuse after
deactivation; validate on unbound domain; wrong/missing credentials; stale
timestamp; per-client rate limiting; SQL-injection-style key input.
Execute with: php tests/run-tests.php --user=... --pass=...

CSRF/XSS/ownership/webhook cases are covered once the respective surfaces
exist (Stages 2-4) and will be added to this file with results.
