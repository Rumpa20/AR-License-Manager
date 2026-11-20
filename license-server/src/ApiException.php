<?php
/**
 * AR License Manager - API exception carrying an HTTP status and a stable
 * machine-readable error code, mapped to JSON responses.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
