<?php

namespace App\Exceptions;

use RuntimeException;

class IntegrationUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $providerStatus = null)
    {
        // Never include credentials, device identifiers or raw provider bodies.
        parent::__construct('Network information is unavailable: '.$reason);
    }
}
