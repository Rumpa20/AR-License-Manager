<?php
/**
 * AR License Manager - Database-backed sliding window rate limiter.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

use ARLM\ApiException;

final class RateLimiter
{
    public static function check(int $clientId, int $limitPerMinute): void
    {
        if ($limitPerMinute <= 0) {
            return;
        }
        $count = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::table('api_logs') . '
             WHERE api_client_id = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)',
            [$clientId]
        );
        if ($count >= $limitPerMinute) {
            throw new ApiException(429, 'RATE_LIMITED', 'API rate limit exceeded. Retry later.');
        }
    }

    public static function attempts(string $scope, int $windowSeconds, int $max): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::table('security_logs') . '
             WHERE event = ? AND created_at >= (NOW() - INTERVAL ' . (int) $windowSeconds . ' SECOND)',
            [substr($scope, 0, 64)]
        );
    }
}
