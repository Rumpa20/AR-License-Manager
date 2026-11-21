<?php
/**
 * AR License Manager - Web installer.
 *
 * Safe, one-time installer: creates tables from sql/schema.sql, generates
 * the Ed25519 signing key pair, seeds default settings and creates the
 * first admin account. Refuses to run twice and refuses to run without
 * ext/sodium (no silent security downgrade).
 *
 * Mobile-friendly single page. Delete this folder after installation.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

define('ARLM_ROOT', dirname(__DIR__) . '/src');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ARLM\\')) {
        $file = ARLM_ROOT . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

error_reporting(E_ALL);
ini_set('display_errors', '0');

use ARLM\Signature;

$errors = [];
$done = false;
$publicKey = '';
$configFile = dirname(__DIR__) . '/config/config.php';
$schemaFile = dirname(__DIR__) . '/sql/schema.sql';

if (!Signature::available()) {
    $errors[] = 'The PHP sodium extension is not available. AR License Manager requires Ed25519 (ext/sodium, bundled with PHP 7.2+). Ask your host to enable the sodium extension before continuing.';
}
if (is_file($configFile)) {
    $existing = @include $configFile;
    if (is_array($existing) && !empty($existing['installed'])) {
        $errors[] = 'AR License Manager is already installed. Remove config/config.php to reinstall (this does not delete existing tables).';
    }
}
if (!extension_loaded('pdo_mysql')) {
    $errors[] = 'The pdo_mysql PHP extension is required.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost')) ?: 'localhost';
    $dbPort = (int) ($_POST['db_port'] ?? 3306) ?: 3306;
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['db_prefix'] ?? 'arlm_')) ?: 'arlm_';
    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $serverUrl = rtrim(trim((string) ($_POST['server_url'] ?? '')), '/');

    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'Database name and user are required.';
    }
    if ($adminUser === '' || strlen($adminPass) < 10) {
        $errors[] = 'An admin username and a password of at least 10 characters are required.';
    }
    if ($serverUrl === '' || !preg_match('#^https://#', $serverUrl)) {
        $errors[] = 'License Server URL must be a full https:// URL, e.g. https://www.abruar.online/arlm';
    }

    if ($errors === []) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $errors[] = 'Could not connect to the database. Check host, name, user and password. (Credentials were not stored.)';
        }

        if ($errors === []) {
            try {
                $schema = (string) file_get_contents($schemaFile);
                $schema = str_replace('{ARLM_PREFIX}', $prefix, $schema);
                $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $schema) ?: []));
                foreach ($statements as $statement) {
                    if ($statement !== '') {
                        $pdo->exec($statement);
                    }
                }

                $keys = Signature::generateKeyPair();
                $defaults = [
                    'key_pepper'               => bin2hex(random_bytes(32)),
                    'sign_private_key'         => $keys['private'],
                    'sign_public_key'          => $keys['public'],
                    'server_url'               => $serverUrl,
                    'api_base_url'             => $serverUrl . '/api/v1/',
                    'default_activation_limit' => '1',
                    'default_duration_days'    => '365',
                    'default_license_type'     => 'annual',
                    'key_prefix'               => 'ARLM',
                    'rate_limit_m'             => '60',
                    'allow_local_domains'      => '0',
                    'maintenance_mode'         => '0',
                    'notify_email'             => '',
                    'security_logging'         => '1',
                    'store_shared_secret'      => bin2hex(random_bytes(32)),
                    'installed_at'             => date('Y-m-d H:i:s'),
                ];
                $insert = $pdo->prepare(
                    'INSERT INTO `' . $prefix . 'settings` (name, value) VALUES (:n, :v)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
                foreach ($defaults as $name => $value) {
                    $insert->execute([':n' => $name, ':v' => $value]);
                }

                $pdo->prepare('INSERT INTO `' . $prefix . 'admins` (username, password_hash) VALUES (?, ?)')
                    ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

                $config = [
                    'db_host'   => $dbHost,
                    'db_port'   => $dbPort,
                    'db_name'   => $dbName,
                    'db_user'   => $dbUser,
                    'db_pass'   => $dbPass,
                    'db_prefix' => $prefix,
                    'installed' => true,
                ];
                $export = "<?php\n/* AR License Manager configuration - generated by the installer.\n"
                    . " * Contains database credentials: keep this file out of public reach.\n"
                    . " * Copyright (c) ARNIT CREATION. */\nreturn " . var_export($config, true) . ";\n";
                if (@file_put_contents($configFile, $export, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write config/config.php. Make the config/ folder writable (chmod 755 or 775) and retry.');
                }
                @chmod($configFile, 0640);
                $publicKey = $keys['public'];
                $done = true;
            } catch (Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage() . ' Tables that already existed were left untouched.';
            }
        }
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install - AR License Manager</title>
<style>
:root { --brand:#1e3a5f; --accent:#2e86de; --bg:#f4f6f9; --card:#fff; --text:#1f2933; --line:#d9e1e8; }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif; background:var(--bg); color:var(--text); }
.wrap { max-width:640px; margin:24px auto; padding:0 16px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:16px; }
h1 { font-size:1.3rem; color:var(--brand); }
h2 { font-size:1rem; color:var(--brand); margin-top:20px; }
label { display:block; margin:12px 0 4px; font-weight:600; font-size:.9rem; }
input[type=text],input[type=password],input[type=url],input[type=number] { width:100%; padding:10px; border:1px solid var(--line); border-radius:6px; font-size:1rem; }
button { margin-top:16px; width:100%; padding:12px; background:var(--accent); border:0; color:#fff; font-size:1rem; border-radius:6px; }
.alert { padding:10px 12px; border-radius:6px; margin-bottom:10px; font-size:.9rem; }
.alert.error { background:#fdecea; color:#8c2f26; border:1px solid #f5c6c0; }
.alert.ok { background:#e6f6ec; color:#1e5631; border:1px solid #b7e2c6; }
code, pre { background:#eef2f6; padding:2px 5px; border-radius:4px; word-break:break-all; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>AR License Manager v1.0 &ndash; Installer</h1>
    <p>Installs the central license server tables, signing keys and your first admin account. Your existing SMF tables are never touched.</p>
  </div>
<?php foreach ($errors as $error): ?>
  <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($done): ?>
  <div class="alert ok">Installation complete.</div>
  <div class="card">
    <p><strong>Public signing key</strong> (bundle this with your software; verify signatures with it - never share the private key):</p>
    <pre><?= e($publicKey) ?></pre>
    <p>You can now open <a href="../public/index.php">the admin dashboard</a> and delete the <code>install/</code> folder.</p>
  </div>
<?php else: ?>
  <form class="card" method="post">
    <h2>Database</h2>
    <label for="db_host">DB host</label><input id="db_host" type="text" name="db_host" value="localhost" required>
    <label for="db_port">DB port</label><input id="db_port" type="number" name="db_port" value="3306" required>
    <label for="db_name">Database name</label><input id="db_name" type="text" name="db_name" required>
    <label for="db_user">DB user</label><input id="db_user" type="text" name="db_user" required>
    <label for="db_pass">DB password</label><input id="db_pass" type="password" name="db_pass" autocomplete="off">
    <label for="db_prefix">Table prefix</label><input id="db_prefix" type="text" name="db_prefix" value="arlm_" required>
    <h2>Admin account</h2>
    <label for="admin_user">Admin username</label><input id="admin_user" type="text" name="admin_user" required>
    <label for="admin_pass">Admin password (min 10 chars)</label><input id="admin_pass" type="password" name="admin_pass" autocomplete="new-password" required>
    <h2>Server</h2>
    <label for="server_url">License Server URL (https)</label>
    <input id="server_url" type="url" name="server_url" value="https://www.abruar.online/arlm" required>
    <button type="submit">Install AR License Manager</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
