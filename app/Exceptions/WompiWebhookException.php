<?php

namespace App\Exceptions;

use RuntimeException;

class WompiWebhookException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        public readonly ?int $paymentId = null,
        public readonly ?int $orderId = null,
    ) {
        // Never chain transport exceptions or provider bodies (may contain credentials/PII).
        parent::__construct('No fue posible verificar el evento.');
    }
}
