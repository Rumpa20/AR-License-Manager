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
timestamp; per-client rate limiting; SQL-injection-style key input; and
(Stage 4) store webhook cases: full happy-path fulfillment (real license,
orders + customers rows written), duplicate webhook idempotency (no second
license), stale timestamp, forged and missing signatures, missing customer
email, unknown product_uid, blocked customer, refunded order, and
unconfigured-store 503. Execute with:

    php tests/run-tests.php --user=... --pass=...

## Sandbox verification performed (Stages 2-4)

- All PHP files structurally verified with the PHP-segment state-machine
  checker (brace/paren/bracket balance, string/comment handling, corruption
  markers). Stage 4 added StoreWebhook, webhook entry point, store adapter
  and three SDK files - all verified balanced. This is NOT a substitute for
  `php -l` or the runtime suite above.
- smf-mod/package-info.xml validated as well-formed XML (Stage 3).
- NOT executed in sandbox: php -l / actual PHP execution, the MySQL schema,
  tests/run-tests.php, SMF Package Manager install on a live SMF 2.1.8.
  These remain the pre-release gate on a real host.
