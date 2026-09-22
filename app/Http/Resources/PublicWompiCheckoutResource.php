<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicWompiCheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip([
            'provider', 'checkout_url', 'public_key', 'amount_in_cents', 'currency',
            'reference', 'integrity_signature', 'redirect_url', 'expiration_time',
        ]));
    }
}
