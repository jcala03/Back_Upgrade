<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

class MimePatchSecurityTest extends TestCase
{
    #[DataProvider('invalidNames')]
    public function test_header_parameter_names_reject_injection_and_non_tokens(string $name): void
    {
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'document.pdf']);
    }

    public static function invalidNames(): array
    {
        return [["filename\r\nBcc: victim@example.test"], ['file name'], ['file;name'], ['file:name']];
    }

    public function test_normal_pdf_attachment_and_spanish_subject_still_serialize(): void
    {
        $email = (new Email)->from('ventas@upgradecolombia.com')->to('cliente@example.test')
            ->subject('Cotización COT-000125 — Upgrade La 79')->text('Documento de prueba')
            ->attach('%PDF-1.4 test', 'COT-000125.pdf', 'application/pdf');
        $serialized = $email->toString();
        $this->assertStringContainsString('Content-Type: application/pdf', $serialized);
        $this->assertStringContainsString('COT-000125.pdf', $serialized);
        $this->assertCount(1, $email->getAttachments());
    }
}
