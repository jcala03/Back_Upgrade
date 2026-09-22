<?php

namespace App\Support\Payments;

use App\Exceptions\EcommercePaymentException;

final class CopAmount
{
    public static function toCents(mixed $amount, string $currency): int
    {
        if ($currency !== 'COP') {
            throw new EcommercePaymentException(EcommercePaymentException::UNSUPPORTED_CURRENCY);
        }
        if (! is_int($amount) || $amount <= 0 || $amount > intdiv(PHP_INT_MAX, 100)) {
            throw new EcommercePaymentException(EcommercePaymentException::INVALID_ORDER_TOTAL);
        }

        return $amount * 100;
    }
}
