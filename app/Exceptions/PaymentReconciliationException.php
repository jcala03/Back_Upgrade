<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentReconciliationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 409)
    {
        parent::__construct('No fue posible actualizar la revisión de conciliación.');
    }
}
