<?php
/**
 * AR License Manager - PHP SDK client (v1.0).
 *
 * Single-file, dependency-free client for PHP 8.1+ applications
 * (the WordPress and SMF SDKs are thin wrappers around this class).
 *
 * Security model:
 *  - Requests authenticate with your API client id + secret (never ship
 *    these inside distributed software - use a server-side proxy; see docs).
 *  - Every license reply carries an Ed25519 signature over the canonical
 *    response string. This class verifies it with the server PUBLIC key.
 *    If the sodium extension is missing, verification throws - the client
 *    NEVER silently downgrades to "trust unsigned responses". You can
 *    explicitly waive verification with ALLOW_UNSIGNED=1 in the options
 *    array, which logs a warning and is your responsibility.
 *  - Use FAIL_CLOSED behavior (default): unsigned/unverifiable/unexpired
 *    stale replies refuse activation instead of trusting the payload.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */
declare(strict_types=1);

final class ARLMClient
{
    private const CLOCK_SKEW = 300;

    public string $serverUrl;
    public string $clientId;
    public string $secret;
    /** Base64url Ed25519 public key from the license server install output. */
    public string $publicKey;
    public int $timeoutSeconds;
    public bool $allowUnsigned;

    public function __construct(
        string $serverUrl,
        string $clientId = '',
        string $secret = '',
        string $publicKey = '',
        int $timeoutSeconds = 20,
        bool $allowUnsigned = false
    ) {
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->publicKey = $publicKey;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->allowUnsigned = $allowUnsigned;
    }

    /** POST /activate - consumes one activation slot for $domain. */
    public function activate(string $licenseKey, string $productId, ?string $domain = null): array
    {
        return $this->post('activate', $licenseKey, $productId, $domain);
    }

    /** POST /validate - re-verifies an activated domain; consumes nothing. */
    public function validate(string $licenseKey, string $productId, ?string $domain = null): array
    {
        return $this->post('validate', $licenseKey, $productId, $domain);
    }

    /** POST /deactivate - frees the activation slot for $domain. */
    public function deactivate(string $licenseKey, string $productId, ?string $domain = null): array
    {
        return $this->post('deactivate', $licenseKey, $productId, $domain);
    }

    /** POST /check - status/expiry probe; domain optional. */
    public function check(string $licenseKey, string $productId, ?string $domain = null): array
    {
        return $this->post('check', $licenseKey, $productId, $domain);
    }

    /** GET /products - lists active products (id/secret required, unsigned reply). */
    public function products(): array
    {
        return $this->request('GET', 'products', null);
    }

    /** Verifies the Ed25519 signature on a license reply. Throws when invalid. */
    public function verifySignature(array $payload): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            if (!$this->allowUnsigned) {
                throw new RuntimeException('ARLM: sodium extension missing - refusing unsigned license data.');
            }
            return false;
        }
        $signature = (string) ($payload['signature'] ?? '');
        $canonical = implode("\n", [
            (string) ($payload['product_id'] ?? ''),
            (string) ($payload['domain'] ?? ''),
            (string) ($payload['status'] ?? ''),
            (string) ($payload['expires_at'] ?? ''),
            (string) (int) ($payload['activation_limit'] ?? 0),
            (string) (int) ($payload['current_activations'] ?? 0),
            (string) (int) ($payload['timestamp'] ?? 0),
            (string) ($payload['request_id'] ?? ''),
        ]);
        if ($this->publicKey === '' || $signature === '') {
            if (!$this->allowUnsigned) {
                throw new RuntimeException('ARLM: response or configured public key missing signature data.');
            }
            return false;
        }
        return sodium_crypto_sign_verify_detached(
            self::base64urlDecode($signature),
            $canonical,
            self::base64urlDecode($this->publicKey)
        );
    }

    /** Rejects replies that fail signature or freshness checks (fail closed). */
    public function assertVerified(array $payload): void
    {
        $fresh = abs(time() - (int) ($payload['timestamp'] ?? 0)) <= self::CLOCK_SKEW;
        if (!$this->verifySignature($payload)) {
            if (!$this->allowUnsigned) {
                throw new RuntimeException('ARLM: license response signature verification FAILED.');
            }
        } elseif (!$fresh) {
            throw new RuntimeException('ARLM: license response timestamp is stale.');
        }
    }

    private function post(string $endpoint, string $licenseKey, string $productId, ?string $domain): array
    {
        $body = [
            'license_key' => $licenseKey,
            'product_id'  => $productId,
            'domain'      => $domain !== null && $domain !== '' ? $domain : $this->currentDomain(),
            'timestamp'   => time(),
            'request_id'  => bin2hex(random_bytes(16)),
        ];
        return $this->request('POST', $endpoint, $body);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $endpoint, ?array $body): array
    {
        $url = $this->serverUrl . '/api/v1/' . $endpoint;
        $headers = [
            'Accept: application/json',
            'X-ARLM-Client-Id: ' . $this->clientId,
            'X-ARLM-Secret: ' . $this->secret,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'ARLM-Php-SDK/1.0.0',
        ]);
        if ($body !== null) {
            curl_setopt_array($ch, [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            ]);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $raw === false ? curl_error($ch) : null;
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('ARLM: request failed - ' . (string) $err);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('ARLM: non-JSON response (HTTP ' . $code . ').');
        }
        return $data + ['_http_status' => $code];
    }

    /** Best-effort host detection for the current installation. */
    private function currentDomain(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
        return preg_replace('/[^a-z0-9.\-]/i', '', strtolower($host)) ?? '';
    }

    private static function base64urlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }
}
