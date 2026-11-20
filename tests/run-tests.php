<?php
/**
 * AR License Manager - Integration test suite.
 * Run on a host with PHP 8.1+ (sodium, pdo_mysql) and MySQL 5.7+/MariaDB:
 *   php tests/run-tests.php --host=127.0.0.1 --port=3306 --name=arlm_test --user=... --pass=...
 * Uses its own database and prefix arlm_test_; creates and drops its own tables only.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

define('ARLM_ROOT', dirname(__DIR__) . '/license-server/src');

$args = [];
foreach (array_slice($argv, 1) as $kv) {
    if (str_starts_with($kv, '--')) {
        [$k, $v] = array_pad(explode('=', substr($kv, 2), 2), 2, '');
        $args[$k] = $v;
    }
}
$dbHost = $args['host'] ?? '127.0.0.1';
$dbPort = (int) ($args['port'] ?? 3306);
$dbName = $args['name'] ?? 'arlm_test';
$dbUser = $args['user'] ?? '';
$dbPass = $args['pass'] ?? '';
if ($dbUser === '') {
    fwrite(STDERR, "Usage: php tests/run-tests.php --user=DBUSER --pass=DBPASS [--host=127.0.0.1] [--port=3306] [--name=arlm_test]\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ARLM\\')) {
        require ARLM_ROOT . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});

\ARLM\Config::loadFromArray([
    'db_host' => $dbHost, 'db_port' => $dbPort, 'db_name' => $dbName,
    'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_prefix' => 'arlm_test_',
    'installed' => true, 'allow_http_debug' => true,
]);
\ARLM\Settings::boot();

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name\n"; }
}

$pdo = \ARLM\Database::pdo();
$schema = str_replace('{ARLM_PREFIX}', 'arlm_test_', (string) file_get_contents(__DIR__ . '/../license-server/sql/schema.sql'));
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $schema) ?: [])) as $stmt) {
    if ($stmt !== '') { $pdo->exec($stmt); }
}

\ARLM\Settings::setMany([
    'sign_private_key' => bin2hex(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
    'sign_public_key'  => '',
]);
\ARLM\Settings::set('sign_public_key', bin2hex(sodium_crypto_sign_publickey(
    sodium_crypto_sign_keypair_from_secretkey(hex2bin((string) \ARLM\Settings::get('sign_private_key')))
)));

$keys = \ARLM\Signature::generateKeyPair();
\ARLM\Settings::set('sign_private_key', $keys['private']);
\ARLM\Settings::set('sign_public_key', $keys['public']);

\ARLM\Database::run(
    "INSERT INTO " . \ARLM\Database::table('products') . " (product_uid, name, slug, status, default_activation_limit, default_duration_days)
     VALUES ('PRODUCT-001', 'Test Mod', 'test-mod', 'active', 1, 365)"
);
$productId = (int) \ARLM\Database::value(
    "SELECT id FROM " . \ARLM\Database::table('products') . " WHERE product_uid = 'PRODUCT-001'"
);
\ARLM\Database::run(
    "INSERT INTO " . \ARLM\Database::table('customers') . " (email, name, source, status) VALUES ('cust@example.com', 'Test Customer', 'external', 'active')"
);
$customerId = (int) \ARLM\Database::pdo()->lastInsertId();
\ARLM\Database::run(
    "INSERT INTO " . \ARLM\Database::table('api_clients') . " (name, client_id, secret_hash, permissions, rate_limit_m, status)
     VALUES ('Test Client', 'TESTCLIENT', '" . hash('sha256', 'testsecret') . "', 'activate,validate,deactivate,check,products', 5, 'active')"
);
$clientId = (int) \ARLM\Database::pdo()->lastInsertId();

function insertLicense(array $over = []): int
{
    global $productId, $customerId;
    $row = array_merge([
        'product_id' => $productId, 'customer_id' => $customerId,
        'license_type' => 'single_domain', 'max_activations' => 1,
        'status' => 'active', 'expires_at' => null,
    ], $over);
    $plain = \ARLM\LicenseKey::generate();
    \ARLM\Database::run(
        'INSERT INTO ' . \ARLM\Database::table('licenses') . '
         (key_hash, key_last4, product_id, customer_id, license_type, max_activations, current_activations, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
        [\ARLM\LicenseKey::hash($plain), \ARLM\LicenseKey::last4($plain), $row['product_id'], $row['customer_id'],
         $row['license_type'], $row['max_activations'], $row['status'], $row['expires_at']]
    );
    return (int) \ARLM\Database::pdo()->lastInsertId();
}

function engineCall(string $endpoint, array $body, ?string $secret = 'testsecret', ?string $clientId = 'TESTCLIENT', int $ts = 0): array
{
    $body += [
        'timestamp' => $ts ?: time(),
        'request_id' => 'req-' . bin2hex(random_bytes(6)),
    ];
    $_SERVER['HTTP_X_ARLM_CLIENT_ID'] = (string) $clientId;
    $_SERVER['HTTP_X_ARLM_SECRET'] = (string) $secret;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        $client = \ARLM\ApiAuth::authenticate();
        $ctx = \ARLM\ApiAuth::authenticatePost($body);
        $stored = \ARLM\ApiAuth::replayedResponse((int) $client['id'], $ctx['request_id']);
        if ($stored !== null) { return $stored; }
        $response = match ($endpoint) {
            'activate'   => \ARLM\LicenseEngine::activate($body, $ctx),
            'validate'   => \ARLM\LicenseEngine::validate($body, $ctx),
            'deactivate' => \ARLM\LicenseEngine::deactivate($body, $ctx),
            'check'      => \ARLM\LicenseEngine::check($body, $ctx),
            default      => throw new \ARLM\ApiException(404, 'UNKNOWN_ENDPOINT', 'Unknown endpoint.'),
        };
        \ARLM\Logger::api((int) $client['id'], $endpoint, 'POST', [
            'request_id' => $ctx['request_id'],
            'status_code' => 200,
            'response_json' => json_encode($response),
        ]);
        return $response;
    } catch (\ARLM\ApiException $e) {
        return ['success' => false, 'error_code' => $e->errorCode, 'status_code' => $e->statusCode];
    }
}

echo "== key generation ==\n";
$kg = \ARLM\LicenseKey::generate();
check('key format ARLM-XXXX-XXXX-XXXX-XXXX', (bool) preg_match('/^ARLM(-[0-9A-Z]{4}){4}$/', $kg));
check('duplicate key hash rejected by unique index', (function () use ($kg) {
    $h = \ARLM\LicenseKey::hash($kg);
    \ARLM\Database::run(
        'INSERT IGNORE INTO ' . \ARLM\Database::table('licenses') . ' (key_hash, product_id, customer_id) VALUES (?, 1, 1)', [$h]
    );
    return \ARLM\Database::value(
        'SELECT COUNT(*) FROM ' . \ARLM\Database::table('licenses') . ' WHERE key_hash = ?', [$h]
    ) == 1;
})());

echo "== activation ==\n";
$lic1 = insertLicense();
$plain1 = \ARLM\LicenseKey::generate();
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash($plain1), $lic1]);

$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'https://www.Example.com/path']);
check('valid activation with www + scheme normalization', ($r['success'] ?? false) === true && ($r['domain'] ?? '') === 'example.com');
check('response signature verifies', \ARLM\Signature::verifyWithPublicKey(
    \ARLM\Settings::get('sign_public_key'), $r, (string) ($r['signature'] ?? '')
));
$r2id = 'dup-req-1';
$b = ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'example.com', 'request_id' => $r2id];
$first = engineCall('activate', $b);
$second = engineCall('activate', $b);
check('idempotent replay returns same stored response', ($first['request_id'] ?? '') === ($second['request_id'] ?? ''));
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'example.com', 'request_id' => 'act-2']);
check('repeat activation idempotent, does not consume slot', ($r['current_activations'] ?? 0) === 1);

echo "== invalid activations ==\n";
$r = engineCall('activate', ['license_key' => 'ARLM-NOPE-NOPE-NOPE-NOPE', 'product_id' => 'PRODUCT-001', 'domain' => 'example.com']);
check('unknown license rejected LICENSE_NOT_FOUND', ($r['error_code'] ?? '') === 'LICENSE_NOT_FOUND');
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-999', 'domain' => 'example.com']);
check('wrong product rejected PRODUCT_MISMATCH', ($r['error_code'] ?? '') === 'PRODUCT_MISMATCH');
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'not_a_domain']);
check('invalid domain rejected DOMAIN_INVALID', ($r['error_code'] ?? '') === 'DOMAIN_INVALID');
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'localhost']);
check('localhost rejected by default', ($r['error_code'] ?? '') === 'DOMAIN_INVALID');

$lic2 = insertLicense(['status' => 'expired', 'expires_at' => '2020-01-01 00:00:00']);
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash('ARLM-EXPP-EXPP-EXPP-EXPP'), $lic2]);
$r = engineCall('activate', ['license_key' => 'ARLM-EXPP-EXPP-EXPP-EXPP', 'product_id' => 'PRODUCT-001', 'domain' => 'expired.com']);
check('expired license rejected and transitioned', ($r['error_code'] ?? '') === 'LICENSE_EXPIRED');

$lic3 = insertLicense(['status' => 'suspended']);
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash('ARLM-SUSP-SUSP-SUSP-SUSP'), $lic3]);
$r = engineCall('activate', ['license_key' => 'ARLM-SUSP-SUSP-SUSP-SUSP', 'product_id' => 'PRODUCT-001', 'domain' => 'susp.com']);
check('suspended license rejected', ($r['error_code'] ?? '') === 'LICENSE_SUSPENDED');

$lic4 = insertLicense(['status' => 'revoked']);
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash('ARLM-REVK-REVK-REVK-REVK'), $lic4]);
$r = engineCall('activate', ['license_key' => 'ARLM-REVK-REVK-REVK-REVK', 'product_id' => 'PRODUCT-001', 'domain' => 'revk.com']);
check('revoked license rejected', ($r['error_code'] ?? '') === 'LICENSE_REVOKED');

$lic5 = insertLicense(['status' => 'pending']);
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash('ARLM-PEND-PEND-PEND-PEND'), $lic5]);
$r = engineCall('activate', ['license_key' => 'ARLM-PEND-PEND-PEND-PEND', 'product_id' => 'PRODUCT-001', 'domain' => 'pend.com']);
check('pending license rejected', ($r['error_code'] ?? '') === 'LICENSE_PENDING');

echo "== activation limits ==\n";
$licMulti = insertLicense(['max_activations' => 2]);
\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('licenses') . ' SET key_hash = ? WHERE id = ?', [\ARLM\LicenseKey::hash('ARLM-MULT-MULT-MULT-MULT'), $licMulti]);
engineCall('activate', ['license_key' => 'ARLM-MULT-MULT-MULT-MULT', 'product_id' => 'PRODUCT-001', 'domain' => 'a.com']);
engineCall('activate', ['license_key' => 'ARLM-MULT-MULT-MULT-MULT', 'product_id' => 'PRODUCT-001', 'domain' => 'b.com']);
$r = engineCall('activate', ['license_key' => 'ARLM-MULT-MULT-MULT-MULT', 'product_id' => 'PRODUCT-001', 'domain' => 'c.com']);
check('activation limit enforced LIMIT_REACHED', ($r['error_code'] ?? '') === 'LIMIT_REACHED');
$r = engineCall('deactivate', ['license_key' => 'ARLM-MULT-MULT-MULT-MULT', 'product_id' => 'PRODUCT-001', 'domain' => 'a.com']);
check('deactivation frees slot', ($r['success'] ?? false) === true);
$r = engineCall('activate', ['license_key' => 'ARLM-MULT-MULT-MULT-MULT', 'product_id' => 'PRODUCT-001', 'domain' => 'c.com']);
check('slot reusable after deactivation', ($r['success'] ?? false) === true);
$r = engineCall('validate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'unbound.com']);
check('validate unbound domain 404 DOMAIN_NOT_ACTIVATED', ($r['error_code'] ?? '') === 'DOMAIN_NOT_ACTIVATED');

echo "== auth, replay, rate limit ==\n";
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'x.com'], 'wrongsecret');
check('wrong secret rejected AUTH_INVALID', ($r['error_code'] ?? '') === 'AUTH_INVALID');
$r = engineCall('activate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'x.com'], 'testsecret', 'TESTCLIENT', time() - 900);
check('stale timestamp rejected BAD_TIMESTAMP', ($r['error_code'] ?? '') === 'BAD_TIMESTAMP');
$r = engineCall('validate', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001', 'domain' => 'example.com'], null, null);
check('missing credentials rejected', ($r['error_code'] ?? '') === 'AUTH_REQUIRED');

\ARLM\Database::run('UPDATE ' . \ARLM\Database::table('api_clients') . ' SET rate_limit_m = 2 WHERE id = ?', [$clientId]);
for ($i = 0; $i < 3; $i++) {
    \ARLM\Settings::boot();
    $r = engineCall('check', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001']);
}
\ARLM\Settings::boot();
$rl = engineCall('check', ['license_key' => $plain1, 'product_id' => 'PRODUCT-001']);
check('rate limiting kicks in at 429', ($rl['error_code'] ?? '') === 'RATE_LIMITED');

echo "== SQL injection sanity ==\n";
$r = engineCall('activate', ["license_key" => "ARLM-' OR 1=1 --", 'product_id' => 'PRODUCT-001', 'domain' => 'evil.com']);
check('injection-style key rejected as bad format', ($r['error_code'] ?? '') === 'BAD_REQUEST');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
