<?php

namespace App\Support\Payments;

use App\Exceptions\WompiWebhookException;

final readonly class WompiTransaction
{
    public function __construct(
        public string $transactionId,
        public string $reference,
        public string $status,
        public int $amountInCents,
        public string $currency,
        public ?string $paymentMethodType,
        public ?string $statusMessage,
    ) {}

    public static function fromResponse(mixed $data): self
    {
        if (! is_array($data)) {
            throw new WompiWebhookException('WOMPI_INVALID_RESPONSE', 502);
        }
        foreach (['id' => 255, 'reference' => 255, 'status' => 40, 'currency' => 3] as $key => $limit) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '' || strlen($data[$key]) > $limit) {
                throw new WompiWebhookException('WOMPI_INVALID_RESPONSE', 502);
            }
        }
        if (! is_int($data['amount_in_cents'] ?? null) || $data['amount_in_cents'] < 1
            || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])) {
            throw new WompiWebhookException('WOMPI_INVALID_RESPONSE', 502);
        }
        foreach (['payment_method_type', 'status_message'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new WompiWebhookException('WOMPI_INVALID_RESPONSE', 502);
            }
        }

        return new self($data['id'], $data['reference'], $data['status'], $data['amount_in_cents'],
            $data['currency'], $data['payment_method_type'] ?? null, $data['status_message'] ?? null);
    }

    public function supported(): bool
    {
        return in_array($this->status, ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true);
    }
}
