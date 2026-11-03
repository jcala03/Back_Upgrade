<?php

namespace Tests\Unit;

use App\Services\CommercialDocumentData;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;

class CommercialDocumentPresentationTest extends TestCase
{
    private function render(array $document): string
    {
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $source = file_get_contents(__DIR__.'/../../resources/views/documents/commercial.blade.php');
        $compiled = $compiler->compileString($source);
        $__env = new Factory(
            new EngineResolver,
            new FileViewFinder(new Filesystem, [__DIR__]),
            new Dispatcher,
        );
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/branding/upgrade79-logo.png'));
        ob_start();
        try {
            eval('?>'.$compiled);

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function documents(): array
    {
        return require __DIR__.'/../Fixtures/commercial-document-presentation.php';
    }

    public function test_pdf_logo_is_the_authentic_original_not_the_ai_file_with_the_same_name(): void
    {
        $path = __DIR__.'/../../resources/branding/upgrade79-logo.png';
        $this->assertSame('46fea4f7782c9c99022adfedc251b928434669942f3ff0bd5ae266afc7d7a956', hash_file('sha256', $path));
        $this->assertSame([150, 150], array_slice(getimagesize($path), 0, 2));
    }

    public function test_presentation_retains_all_fields_including_repeated_memory_and_sku(): void
    {
        foreach ($this->documents() as $document) {
            $html = $this->render($document);
            $text = html_entity_decode(strip_tags(substr($html, strpos($html, '<body>'))), ENT_QUOTES, 'UTF-8');
            foreach (['title', 'code', 'number', 'created_at', 'status', 'payment_status', 'valid_until', 'branch', 'vehicle', 'notes', 'notice'] as $field) {
                if ($document[$field] !== null) {
                    $this->assertStringContainsString($document[$field], $text, $field);
                }
            }
            foreach ([$document['business'], $document['customer']] as $values) {
                foreach ($values as $value) {
                    $this->assertStringContainsString($value, $text);
                }
            }
            foreach ($document['items'] as $item) {
                foreach (array_merge([$item['name'], $item['variant'], $item['sku']], $item['configuration']) as $value) {
                    if ($value !== null) {
                        $this->assertStringContainsString($value, $text);
                    }
                }
            }
            $this->assertSame(2, substr_count($text, 'QA-PANTALLA-8256'));
            $this->assertSame(2, substr_count($text, '8 GB / 256 GB'));
            foreach (['subtotal', 'discount_total', 'total'] as $field) {
                $this->assertStringContainsString(CommercialDocumentData::money($document[$field], $document['currency']), $text);
            }
            foreach ($document['charges'] as $charge) {
                $this->assertStringContainsString($charge['label'], $text);
                $this->assertStringContainsString(CommercialDocumentData::money($charge['amount']), $text);
            }
            foreach (['paid', 'balance'] as $field) {
                if ($document[$field] !== null) {
                    $this->assertStringContainsString(CommercialDocumentData::money($document[$field]), $text);
                }
            }
        }
    }

    public function test_document_differences_and_legal_text_remain_unchanged(): void
    {
        $documents = $this->documents();
        $quote = $this->render($documents['quotation']);
        $order = $this->render($documents['pending-order']);
        $sale = $this->render($documents['paid-sale']);
        $this->assertStringContainsString('Vigencia: 18/11/2026', $quote);
        $this->assertStringNotContainsString('Abonos completados', $quote);
        $this->assertStringContainsString('Estado de pago: Sin pagar', $order);
        $this->assertStringContainsString('Este documento no acredita una venta pagada.', $order);
        $this->assertStringContainsString('Comprobante de venta pagada', $sale);
        $this->assertStringContainsString('Estado de pago: Pagada', $sale);
        foreach ([$order, $sale] as $html) {
            $this->assertStringContainsString('No es factura electrónica DIAN.', $html);
            $this->assertStringContainsString('Abonos completados', $html);
            $this->assertStringContainsString('Saldo', $html);
        }
        foreach ([$quote, $order, $sale] as $html) {
            $this->assertStringContainsString('Los productos, referencias e importes corresponden al registro comercial guardado. No se sustituyen por el catálogo actual. Datos de contacto del negocio vigentes al generar el documento.', $html);
        }
    }

    public function test_layout_preserves_pagination_and_escaped_customer_data(): void
    {
        $document = $this->documents()['quotation'];
        $document['customer']['name'] = '<script>cliente</script>';
        $html = $this->render($document);
        $this->assertStringContainsString('&lt;script&gt;cliente&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>cliente</script>', $html);
        $this->assertStringContainsString('display: table-header-group', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('object-fit: contain', $html);
        $this->assertStringContainsString('width="150" height="150"', $html);
    }

    public function test_editorial_layout_is_monochrome_without_filled_sections_or_decorations(): void
    {
        $html = $this->render($this->documents()['quotation']);
        preg_match('/<style>(.*?)<\/style>/s', $html, $match);
        $css = $match[1];
        preg_match_all('/#([0-9a-f]{6})\b/i', $css, $colors);
        $this->assertNotEmpty($colors[1]);
        foreach ($colors[1] as $color) {
            $this->assertSame(substr($color, 0, 2), substr($color, 2, 2));
            $this->assertSame(substr($color, 2, 2), substr($color, 4, 2));
        }
        preg_match_all('/background(?:-color)?\s*:\s*([^;{}]+)/', $css, $backgrounds);
        $this->assertSame(['#ffffff'], array_map('trim', $backgrounds[1]));
        $this->assertDoesNotMatchRegularExpression('/gradient|box-shadow|filter:|border-left:/', $css);
        $this->assertStringContainsString('class="document-code">COT-000125', $html);
        $this->assertStringContainsString('text-align: center', $css);
    }
}
