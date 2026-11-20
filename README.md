# AR License Manager v1.0

Premium Digital License Management System for SMF 2.1.8 and standalone
PHP products.

Copyright (c) ARNIT CREATION. All rights reserved. See LICENSE.

## What it is

Three integrated components:

1. **Central License Server** (`license-server/`) - standalone PHP 8.1+
   application for arnitcreation.online. Owns products, license keys,
   customers, orders, activations, API clients, logs. No SMF or Composer
   dependency.
2. **SMF 2.1.8 Integration Mod** (`smf-mod/`) - installable package adding
   an admin dashboard and a customer license portal to your forum
   (built in Stages 3-4).
3. **Developer SDK + store adapter** (`sdk/`, `store-integration/`) -
   examples for PHP, WordPress, SMF and Android, plus the signed
   Cashfree store-to-server webhook (Stage 4).

## Requirements

- PHP 8.1+ with pdo_mysql, sodium, mbstring (sodium is mandatory:
  Ed25519 response signing; installer refuses without it)
- MySQL 5.7+ / MariaDB 10.4+
- HTTPS on the license server domain

## Quick start (license server)

1. Upload `license-server/` to e.g. https://arnitcreation.online/arlm/
2. Make `license-server/config/` writable, open `install/` in a browser,
   fill in DB details + admin account, install.
3. Delete `install/`.
4. Log in at `public/index.php`, create an API Client, create a product,
   generate a license.

See docs/INSTALLATION.md (written for DirectAdmin + Android workflow).

## Status / stages

Documented in docs/ARCHITECTURE.md - this working tree is a staged build;
do not redistribute intermediate stages as final.

## Testing

Run `php tests/run-tests.php --user=... --pass=...` on any host with
PHP+MySQL. It uses its own `arlm_test_` prefix. See docs/TESTING.md for
the per-run record of what was actually executed and what was not.
