<?php

namespace App\Contracts;

use App\Support\Shipping\ShippingQuoteRequest;
use App\Support\Shipping\ShippingQuoteResult;

interface ShippingQuoteProvider
{
    /** @return list<ShippingQuoteResult> */
    public function quote(ShippingQuoteRequest $request): array;
}
