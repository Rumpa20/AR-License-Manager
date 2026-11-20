<?php
/**
 * AR License Manager - Domain normalization and validation policy.
 *
 * Policy (documented in docs/API-REFERENCE.md):
 *  - Scheme, port, path, query and fragment are stripped; host is lowercased.
 *  - IDN hosts are converted to punycode when the intl extension exists.
 *  - A single leading "www." is stripped (www.example.com == example.com).
 *  - Every activation is bound to the EXACT normalized host. Subdomains are
 *    NOT auto-authorized by the parent domain; each subdomain must be
 *    activated and consumes its own activation slot.
 *  - Localhost, loopback, link-local and bare IP addresses are rejected
 *    unless the admin explicitly enables 'allow_local_domains'.
 *  - Reserved/development TLDs (.localhost, .local, .test, .example,
 *    .invalid) are rejected unless 'allow_local_domains' is enabled.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Domains
{
    private const DENY_SUFFIXES = ['.localhost', '.local', '.test', '.example', '.invalid'];

    public static function normalize(string $input): ?string
    {
        $host = trim(mb_strtolower($input));
        if ($host === '') {
            return null;
        }
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host) ?? '';
        $host = explode('/', $host)[0];
        if (str_contains($host, '@')) {
            return null;
        }
        $host = explode(':', $host)[0];
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        return $host;
    }

    public static function isAllowedFormat(string $host, bool $allowLocal = false): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $allowLocal;
        }
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $allowLocal;
        }
        if (!$allowLocal) {
            foreach (self::DENY_SUFFIXES as $suffix) {
                if (str_ends_with($host, $suffix)) {
                    return false;
                }
            }
        }
        return self::isValidHostname($host);
    }

    public static function isValidHostname(string $host): bool
    {
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        $tld = array_pop($labels);
        if (strlen($tld) < 2 || !ctype_alnum($tld)) {
            return false;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return true;
    }

    public static function hash(string $host): string
    {
        return hash('sha256', $host);
    }
}
