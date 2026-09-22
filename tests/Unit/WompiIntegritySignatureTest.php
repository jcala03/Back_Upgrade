<?php

namespace Tests\Unit;

use App\Exceptions\EcommercePaymentException;
use App\Services\WompiIntegritySignatureService;
use App\Support\Payments\CopAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WompiIntegritySignatureTest extends TestCase
{
    public function test_known_signature_without_expiration(): void
    {
        $signature = (new WompiIntegritySignatureService)->sign('UG79-TEST-1', 12000000, 'COP', 'test_integrity_fixture');
        $this->assertSame('ca4ab28c73d6b442a1552e03f997bbc0da2916b864ea78e46dfde41234018e3e', $signature);
    }

    public function test_known_signature_with_exact_expiration(): void
    {
        $service = new WompiIntegritySignatureService;
        $signature = $service->sign('UG79-TEST-1', 12000000, 'COP', 'test_integrity_fixture', '2026-09-20T15:30:00.000Z');
        $this->assertSame('fb331fdfe93045d60f588e41d2a026f0419faea28610da8929b3561943ac1f36', $signature);
        $this->assertNotSame($signature, $service->sign('UG79-TEST-1', 12000001, 'COP', 'test_integrity_fixture', '2026-09-20T15:30:00.000Z'));
        $this->assertNotSame($signature, $service->sign('UG79-TEST-2', 12000000, 'COP', 'test_integrity_fixture', '2026-09-20T15:30:00.000Z'));
        $this->assertNotSame($signature, $service->sign('UG79-TEST-1', 12000000, 'COP', 'test_integrity_fixture', '2026-09-20T15:30:00Z'));
    }

    public function test_cop_conversion_is_integer_and_accepts_safe_boundary(): void
    {
        $this->assertSame(12000000, CopAmount::toCents(120000, 'COP'));
        $this->assertSame(intdiv(PHP_INT_MAX, 100) * 100, CopAmount::toCents(intdiv(PHP_INT_MAX, 100), 'COP'));
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_fail_without_float_coercion(mixed $amount): void
    {
        $this->expectException(EcommercePaymentException::class);
        CopAmount::toCents($amount, 'COP');
    }

    public static function invalidAmounts(): array
    {
        return [[0], [-1], [1.5], [120000.0], ['120000'], [null], [true], [intdiv(PHP_INT_MAX, 100) + 1], [PHP_INT_MAX]];
    }

    public function test_currency_is_cop_only(): void
    {
        try {
            CopAmount::toCents(100, 'USD');
            $this->fail('Currency must be rejected.');
        } catch (EcommercePaymentException $exception) {
            $this->assertSame('UNSUPPORTED_CURRENCY', $exception->errorCode);
        }
    }
}
