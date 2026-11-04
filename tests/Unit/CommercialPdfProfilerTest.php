<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CommercialPdfProfilerTest extends TestCase
{
    public function test_fixture_probe_reports_only_metrics_without_any_database_or_customer_output(): void
    {
        $process = new Process([PHP_BINARY, 'ops/profile-commercial-pdf.php', '--fixture=quotation', '--repeat=2'], dirname(__DIR__, 2), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'upgrade_test', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        ]);
        $process->mustRun();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('OK', $report['status']);
        $this->assertSame('synthetic_dto', $report['source']);
        $this->assertCount(2, $report['rows']);
        $this->assertArrayNotHasKey('customer', $report);
        foreach ($report['rows'] as $row) {
            $this->assertArrayNotHasKey('customer', $row);
            $this->assertNull($row['sql_ms']);
            $this->assertNull($row['http_total_ms']);
            $this->assertSame(1, $row['pages']);
            $this->assertGreaterThan(1000, $row['bytes']);
            foreach (['blade_ms', 'load_html_ms', 'dompdf_render_ms', 'dompdf_output_ms'] as $stage) {
                $this->assertGreaterThanOrEqual(0, $row['renderer_stages_ms'][$stage]);
            }
        }
        foreach (['cliente@example.test', 'QA-PANTALLA', 'password', '%PDF-'] as $private) {
            $this->assertStringNotContainsString($private, $process->getOutput());
        }
    }

    public function test_unknown_or_unbounded_probe_arguments_fail_without_private_exception_details(): void
    {
        foreach ([['--fixture=unknown'], ['--fixture=quotation', '--repeat=100']] as $arguments) {
            $process = new Process([PHP_BINARY, 'ops/profile-commercial-pdf.php', ...$arguments], dirname(__DIR__, 2));
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $report = json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('REFUSED', $report['status']);
            $this->assertSame(['status', 'exception_class'], array_keys($report));
            $this->assertSame('', $process->getOutput());
        }
    }
}
