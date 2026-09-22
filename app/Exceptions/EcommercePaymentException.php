<?php

namespace App\Exceptions;

use RuntimeException;

class EcommercePaymentException extends RuntimeException
{
    public const ORDER_NOT_READY_FOR_PAYMENT = 'ORDER_NOT_READY_FOR_PAYMENT';

    public const ORDER_NOT_PAYABLE = 'ORDER_NOT_PAYABLE';

    public const RESERVATION_EXPIRED = 'RESERVATION_EXPIRED';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const PAYMENT_ALREADY_COMPLETED = 'PAYMENT_ALREADY_COMPLETED';

    public const PAYMENT_RECONCILIATION_REQUIRED = 'PAYMENT_RECONCILIATION_REQUIRED';

    public const UNSUPPORTED_CURRENCY = 'UNSUPPORTED_CURRENCY';

    public const INVALID_ORDER_TOTAL = 'INVALID_ORDER_TOTAL';

    public const WOMPI_NOT_CONFIGURED = 'WOMPI_NOT_CONFIGURED';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('No fue posible iniciar el pago.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::WOMPI_NOT_CONFIGURED => 503,
            self::UNSUPPORTED_CURRENCY, self::INVALID_ORDER_TOTAL => 422,
            default => 409,
        };
    }
}
