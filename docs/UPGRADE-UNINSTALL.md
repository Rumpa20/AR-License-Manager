# AR License Manager v1.0 — Upgrade and Uninstall

Copyright (c) ARNIT CREATION.

## SMF 2.1.8 mod: upgrade

The package uses hooks only and stores its state in SMF `settings` rows
named `arlm_*`, so upgrading is additive:

1. **Back up the database** (cheap insurance; the mod touches nothing
   outside `arlm_*` settings, but backups are always right).
2. Package Manager → Download New Packages → upload the newer ZIP.
3. **Uninstall** the previous version first if the package manager requires
   it (uninstall removes only `arlm_*` settings rows and the mod's own
   files - your license server data is completely unaffected), then
   **Install** the new version.
4. Re-enter the Server URL / Client ID / Secret / public key if the version
   adds new settings fields; existing values survive a normal upgrade.

Never edit `Sources/ARLM.php` or template files in place on a live forum;
changes will be lost on the next package operation.

## License server: upgrade

1. Back up the database **and** `config/config.php`.
2. Upload the new `license-server/` files over the old ones (keep
   `config/config.php` - do not overwrite it with the sample).
3. Load any provided migration SQL (each release lists required statements;
   if none are listed, no schema change is needed).
4. Open Admin → **System Information** and confirm all checks still pass:
   PHP version, sodium, pdo_mysql, table row counts.
5. Upgrades never change or delete license data rows. Settings added by
   newer versions are seeded automatically with their defaults.

## Rollback

Restore the file backup and the SQL dump. Because the schema is additive
and prefixed (`arlm_` by default), an old server version stops working only
if it lacks a table added by the newer one - that is a restore requirement,
not a silent data path.

## SMF mod: uninstall

Package Manager → the mod → **Uninstall**. This:

- removes the two registered integration hooks,
- deletes `Sources/ARLM.php`, `Themes/default/ARLM.template.php` and
  `Themes/default/languages/ARLM.*.php`,
- deletes only settings rows `WHERE variable LIKE 'arlm\_%'`.

It does **not** touch members, boards, posts, or any SMF core table, and it
does **not** contact or alter the license server. If you also want license
data gone, revoke the customer's licenses in the license server admin
(the audit trail is preserved by design).

## License server: uninstall / decommission

There is no destructive one-click uninstall - by design. To decommission:

1. Export the data you need first (Reports → CSV).
2. Take a full DB backup.
3. DROP the `arlm_*` tables with SQL and delete the `license-server/`
   folder. Nothing outside the configured prefix is ever created, so
   nothing else on the server can be orphaned.

Never drop tables while customers still hold valid licenses - CSV exports
are the only way to recover key data afterwards, because plaintext keys
were never stored.
