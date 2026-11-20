<?php
/**
 * AR License Manager - Ed25519 response signatures (ext/sodium, bundled
 * with PHP 7.2+). If ext/sodium is missing the installer refuses to
 * complete; the server NEVER silently downgrades to a weaker scheme.
 *
 * The canonical string signed for every API response is:
 *   product_id \n domain \n status \n expires_at \n
 *   activation_limit \n current_activations \n timestamp \n request_id
 * (expires_at is the literal value sent in JSON, empty string when null).
 * The signature is base64url encoded. Clients verify with the PUBLIC key
 * distributed in sdk/public/arlm_public.key; the private key never
 * leaves the license server.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Signature
{
    public static function available(): bool
    {
        return extension_loaded('sodium') && function_exists('sodium_crypto_sign_keypair');
    }

    /** @return array{private: string, public: string} PEM-less raw hex keys */
    public static function generateKeyPair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [
            'private' => bin2hex(sodium_crypto_sign_secretkey($kp)),
            'public'  => bin2hex(sodium_crypto_sign_publickey($kp)),
        ];
    }

    public static function privateKey(): string
    {
        $key = Settings::get('sign_private_key');
        if ($key === '') {
            throw new \RuntimeException('Signing keys are not configured. Re-run the installer.');
        }
        return $key;
    }

    public static function publicKey(): string
    {
        return Settings::get('sign_public_key');
    }

    public static function canonical(array $r): string
    {
        return implode("\n", [
            (string) ($r['product_id'] ?? ''),
            (string) ($r['domain'] ?? ''),
            (string) ($r['status'] ?? ''),
            ($r['expires_at'] ?? null) === null ? '' : (string) $r['expires_at'],
            (string) (int) ($r['activation_limit'] ?? 0),
            (string) (int) ($r['current_activations'] ?? 0),
            (string) (int) ($r['timestamp'] ?? 0),
            (string) ($r['request_id'] ?? ''),
        ]);
    }

    public static function sign(array $response): string
    {
        return self::toBase64Url(
            sodium_crypto_sign_detached(self::canonical($response), hex2bin(self::privateKey()))
        );
    }

    public static function verifyWithPublicKey(string $publicKeyHex, array $response, string $signature): bool
    {
        if (!self::available()) {
            return false;
        }
        $pub = @hex2bin($publicKeyHex);
        $sig = self::fromBase64Url($signature);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, self::canonical($response), $pub);
    }

    public static function toBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function fromBase64Url(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
