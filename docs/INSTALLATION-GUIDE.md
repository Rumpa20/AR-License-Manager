# AR License Manager v1.0 — Installation Guide

Written for mobile-managed hosting (DirectAdmin on phone). No desktop tools
or SSH are required; whenever a command is shown it is optional.

Copyright (c) ARNIT CREATION.

## 1. Server requirements

**License Server** (install on www.abruar.online, NOT on customer forums):

- PHP 8.1 or newer with extensions: `pdo_mysql`, `sodium`, `mbstring`,
  `json`, `curl` (outgoing), `filter`.
- MySQL 5.7+ / MariaDB 10.4+ (InnoDB, utf8mb4).
- HTTPS certificate on the server domain (mandatory; the API refuses
  plain HTTP unless you deliberately set `allow_http_debug`).
- One MySQL database + user, and one empty folder reachable over the web,
  e.g. `public_html/arlm/`.
- Writable: `license-server/config/` (installer writes config.php) and
  `license-server/storage/logs/`.

**SMF 2.1.8 mod** (customer forums):

- SMF 2.1.x, PHP 8.1+, `sodium` extension (the package checks both and
  refuses installation otherwise), outgoing HTTPS to your license server.

## 2. Installing the License Server

1. Upload `AR-License-Manager-v1.0-License-Server.zip` to your hosting
   (DirectAdmin → Files → Upload), then **Extract** into `public_html/arlm/`.
   After extraction you should see `api/`, `public/`, `install/`, `src/`,
   `config/`, `sql/`, `webhooks/`, `storage/` inside `public_html/arlm/`.
2. Copy `config/config.sample.php` to `config/config.php` in the file
   manager and edit the DB values:
   - `db_host` (usually `localhost`), `db_port` 3306
   - `db_name`, `db_user`, `db_pass`
   - `db_prefix` — keep `arlm_` unless you must share a database
   - leave `installed => false`
3. Create the database + user in DirectAdmin → MySQL Management and grant
   the user ALL privileges on that database only.
4. In a browser open `https://www.abruar.online/arlm/install/`. The
   installer performs its own preflight (PHP version, sodium, pdo_mysql,
   writable paths) and refuses to continue on failure - it never fakes
   success. Fill in DB details and the initial admin account and submit.
   The installer writes all tables, generates the Ed25519 signing keypair
   and the license-key pepper, seeds settings, and creates your admin.
5. **Delete `install/`** from the server immediately (the installer also
   locks itself out afterward, but delete it anyway) and set
   `config/config.php` back to read-only (644).
6. Log in at `https://www.abruar.online/arlm/public/index.php`.

The installer output shows your **Ed25519 public key** (base64url). Copy it
now - SDKs need it to verify signed replies. It is also always visible in
Admin → System Information.

## 3. API configuration

1. Admin → **API Clients** → create a client. The **Client ID + Secret** are
   shown ONCE. Store the secret in your password manager; only its SHA-256
   hash is kept server-side. Lost secret = revoke and create a new client.
2. Give the client only the permissions it needs (e.g. the SMF portal mod
   needs `portal_licenses`, `portal_deactivate`; product SDKs need
   `activate`, `validate`, `deactivate`, `check`).
3. Set a per-client rate limit (requests/minute).

## 4. Installing the SMF 2.1.8 package

1. Download `AR-License-Manager-v1.0-SMF-Mod.zip` (package-info.xml at the
   ZIP root - do not re-zip by hand unless you keep that layout).
2. In SMF: **Admin → Main → Package Manager → Download New Packages →
   Add Package** → upload the ZIP → **Install**. The package:
   - installs via hooks only (`integrate_admin_areas`, `integrate_actions`)
     - **zero SMF core files are modified**;
   - creates no SMF tables - it only seeds `arlm_*` settings rows and adds
     source/template/language files prefixed `ARLM*`;
   - refuses to install under PHP < 8.1 or without the `sodium` extension;
   - never deletes any SMF data on uninstall.
3. **Admin → Configuration → AR License Manager**: enter License Server URL,
   API Client ID/Secret, and the public key; use **Test Connection** to see
   a real, live result (no simulated success).

## 5. First product and license

1. Admin → **Products** → create a product. The `product_uid`
   (e.g. `PRODUCT-001`) is what SDKs send - keep it stable forever.
2. Admin → **Generate License**: pick product, license type, activation
   limit, duration; internal notes optional. The plaintext key
   `ARLM-XXXX-XXXX-XXXX-XXXX` is displayed exactly once.
3. Send the key to the customer through your delivery channel (store
   webhook does this automatically for paid orders).

## 6. Store integration (Cashfree store)

See `docs/STORE-INTEGRATION.md`. Short version: rotate the shared secret in
Admin → Settings → Store Integration, deploy the store-side adapter from
`sdk/store-adapter/`, and point the store post-payment confirmation at
`https://www.abruar.online/arlm/webhooks/store/`. Payment success is only
ever confirmed server-to-server; browser redirects are never trusted.

## 7. Backup and restore

- Back up the database (all `arlm_` tables) plus `config/config.php` and
  ideally `storage/logs/`.
- The signing private key and key pepper live in the DB `settings` table -
  **losing them makes every issued license worthless**. Take a DB dump before
  any upgrade.
- Restore = import the SQL dump and re-upload the files; the structure is
  self-contained under the `arlm_` prefix.

## 8. Upgrade

See `docs/UPGRADE-UNINSTALL.md`.

## 9. Checklist

- HTTPS active, `install/` deleted, `config/` + `storage/` web-denied.
- Admin password strong; create API clients least-privilege.
- Store shared secret rotated from its install default.
- `docs/TESTING.md` test suite executed once on the real host with results
  recorded there.
