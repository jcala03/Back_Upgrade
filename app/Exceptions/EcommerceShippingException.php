<?php

namespace App\Exceptions;

use RuntimeException;

class EcommerceShippingException extends RuntimeException
{
    public const NO_BRANCH_CAN_FULFILL = 'NO_BRANCH_CAN_FULFILL';

    public const MISSING_SHIPPING_ADDRESS = 'MISSING_SHIPPING_ADDRESS';

    public const MISSING_LOGISTICS_DATA = 'MISSING_LOGISTICS_DATA';

    public const MISSING_ORIGIN_DATA = 'MISSING_ORIGIN_DATA';

    public const NO_QUOTES_AVAILABLE = 'NO_QUOTES_AVAILABLE';

    public const QUOTE_EXPIRED = 'QUOTE_EXPIRED';

    public const UNSUPPORTED_DESTINATION = 'UNSUPPORTED_DESTINATION';

    public const STALE_STOCK = 'STALE_STOCK';

    public const ORDER_NOT_PENDING = 'ORDER_NOT_PENDING';

    public const INVALID_QUOTE = 'INVALID_QUOTE';

    public const PROVIDER_CONFIGURATION_ERROR = 'PROVIDER_CONFIGURATION_ERROR';

    public const PROVIDER_AUTHENTICATION_FAILED = 'PROVIDER_AUTHENTICATION_FAILED';

    public const PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';

    public const INVALID_ADDRESS = 'INVALID_ADDRESS';

    public const MALFORMED_PROVIDER_RESPONSE = 'MALFORMED_PROVIDER_RESPONSE';

    public const LANDED_COST_CONFIG_MISSING = 'LANDED_COST_CONFIG_MISSING';

    public const LANDED_COST_AUTHENTICATION_FAILED = 'LANDED_COST_AUTHENTICATION_FAILED';

    public const LANDED_COST_UNAVAILABLE = 'LANDED_COST_UNAVAILABLE';

    public const LANDED_COST_INVALID_REQUEST = 'LANDED_COST_INVALID_REQUEST';

    public const MISSING_CUSTOMS_DATA = 'MISSING_CUSTOMS_DATA';

    public const CURRENCY_MISMATCH = 'CURRENCY_MISMATCH';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
