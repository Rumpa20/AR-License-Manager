<?php
/**
 * AR License Manager - Cryptographically secure license key generator.
 * Format: ARLM-XXXX-XXXX-XXXX-XXXX (Crockford base32, 20 random chars,
 * ~100 bits of entropy). Plaintext keys are shown once and stored only
 * as SHA-256 hashes with a per-install pepper.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class LicenseKey
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(string $prefix = 'ARLM'): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix) ?: 'ARLM');
        $prefix = substr($prefix, 0, 8);
        $chars = '';
        $raw = random_bytes(20);
        for ($i = 0; $i < 20; $i++) {
            $chars .= self::ALPHABET[ord($raw[$i]) % 32];
        }
        return $prefix . '-' . implode('-', str_split($chars, 4));
    }

    public static function normalize(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($key)) ?? '');
    }

    public static function isValidFormat(string $key, string $prefix = ''): bool
    {
        $norm = self::normalize($key);
        if (strlen($norm) < 17 || strlen($norm) > 40) {
            return false;
        }
        return preg_match('/^[' . self::ALPHABET . ']+$/', substr($norm, strlen($prefix))) === 1;
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', self::normalize($plainKey) . self::pepper());
    }

    public static function last4(string $plainKey): string
    {
        return substr(self::normalize($plainKey), -4);
    }

    public static function pepper(): string
    {
        $p = Settings::get('key_pepper');
        if ($p === '') {
            $p = bin2hex(random_bytes(32));
            Settings::set('key_pepper', $p);
        }
        return $p;
    }
}
