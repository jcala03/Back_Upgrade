<?php

namespace App\Services;

use SensitiveParameter;

class WompiIntegritySignatureService
{
    public function sign(
        string $reference,
        int $amountInCents,
        string $currency,
        #[SensitiveParameter] string $integritySecret,
        ?string $expirationTime = null,
    ): string {
        return hash('sha256', $reference.$amountInCents.$currency.($expirationTime ?? '').$integritySecret);
    }
}
