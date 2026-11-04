<?php

use App\Http\Controllers\Api\CommercialDocumentController;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\BusinessSettingsService;
use App\Services\CommercialDocumentData;
use App\Services\ProfiledCommercialDocumentPdf;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** CLI-only, read-only SQL; trusted renderer instrumented in memory, never saved. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = is_file(__DIR__.'/../artisan') ? dirname(__DIR__) : getcwd();
$workspace = null;
$connection = null;
$inTransaction = false;
$GLOBALS['commercial_pdf_profile'] = [];
function commercialPdfProfileMark(string $stage): void
{
    $now = hrtime(true);
    if (isset($GLOBALS['commercial_pdf_previous'])) {
        $GLOBALS['commercial_pdf_profile'][$stage] = ($now - $GLOBALS['commercial_pdf_previous']) / 1e6;
    }
    $GLOBALS['commercial_pdf_previous'] = $now;
}

try {
    $arguments = getopt('', ['fixture:', 'type:', 'id:', 'user:', 'scope:', 'repeat:']);
    $fixture = $arguments['fixture'] ?? null;
    $type = $arguments['type'] ?? null;
    $scope = $arguments['scope'] ?? 'own';
    $repeat = (int) ($arguments['repeat'] ?? 2);
    if ($repeat < 1 || $repeat > 3 || ! in_array($scope, ['own', 'admin'], true)) {
        throw new RuntimeException('Invalid profiling options.');
    }
    if ($fixture === null && (! in_array($type, ['quotation', 'order'], true)
        || ! ctype_digit((string) ($arguments['id'] ?? '')) || ! ctype_digit((string) ($arguments['user'] ?? '')))) {
        throw new RuntimeException('Choose a fixture or an existing record and authorized user.');
    }
    if ($fixture !== null && ! in_array($fixture, ['quotation', 'pending-order', 'quotation-long', 'order-long'], true)) {
        throw new RuntimeException('Unknown fixture.');
    }
    $boot = hrtime(true);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $bootstrapMs = (hrtime(true) - $boot) / 1e6;
    $workspace = sys_get_temp_dir().'/upgrade79-pdf-profile-'.bin2hex(random_bytes(12));
    mkdir($workspace, 0700);
    mkdir($workspace.'/views', 0700);
    config(['view.compiled' => $workspace.'/views', 'cache.default' => 'array', 'mail.default' => 'array']);
    Http::preventStrayRequests();
    $GLOBALS['commercial_pdf_probe_fonts'] = $workspace.'/fonts';
    $sqlMs = 0.0;
    $queries = 0;
    DB::listen(function ($query) use (&$sqlMs, &$queries): void {
        $sqlMs += $query->time;
        $queries++;
    });

    // Exact existing renderer body, with stopwatch statements only. Fail closed
    // on unknown source layouts. No arbitrary source path or PHP from arguments.
    $source = file_get_contents($root.'/app/Services/CommercialDocumentPdf.php');
    $rendererHash = hash('sha256', $source);
    $replace = function (string $needle, string $replacement) use (&$source): void {
        $source = str_replace($needle, $replacement, $source, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unsupported renderer instrumentation.');
        }
    };
    $replace('class CommercialDocumentPdf', 'class ProfiledCommercialDocumentPdf extends CommercialDocumentPdf');
    $replace('public function render(array $document): string'."\n    {", 'public function render(array $document): string'."\n    {\n        \\commercialPdfProfileMark('start');");
    $replace('$logo = ', "\\commercialPdfProfileMark('options_workspace_ms');\n            ".'$logo = ');
    $constructor = str_contains($source, '$pdf = $this->makeDompdf($options);') ? '$pdf = $this->makeDompdf($options);' : '$pdf = new Dompdf($options);';
    $replace($constructor, "\\commercialPdfProfileMark('logo_ms');\n            ".$constructor."\n            \\commercialPdfProfileMark('dompdf_construct_ms');");
    $replace('$pdf->loadHtml(view(\'documents.commercial\', compact(\'document\', \'logo\'))->render(), \'UTF-8\');',
        '$html = view(\'documents.commercial\', compact(\'document\', \'logo\'))->render();'."\n            \\commercialPdfProfileMark('blade_ms');\n            ".'$pdf->loadHtml($html, \'UTF-8\');'."\n            \\commercialPdfProfileMark('load_html_ms');");
    $replace('$pdf->render();', '$pdf->render();'."\n            \\commercialPdfProfileMark('dompdf_render_ms');\n            ".'$GLOBALS[\'commercial_pdf_pages\'] = $pdf->getCanvas()->get_page_count();');
    if (str_contains($source, 'return $pdf->output();')) {
        $replace('return $pdf->output();', "\\commercialPdfProfileMark('footer_ms');\n            ".'$bytes = $pdf->output();'."\n            \\commercialPdfProfileMark('dompdf_output_ms');\n            ".'return $bytes;');
    } else {
        $replace('$bytes = $pdf->output();', "\\commercialPdfProfileMark('footer_ms');\n            ".'$bytes = $pdf->output();'."\n            \\commercialPdfProfileMark('dompdf_output_ms');");
    }
    $replace('(new Filesystem)->deleteDirectory($temporary);', '(new Filesystem)->deleteDirectory($temporary);'."\n            \\commercialPdfProfileMark('cleanup_ms');");
    $usesSharedMetrics = str_contains($source, "storage_path('framework/cache/commercial-pdf-fonts')");
    if ($usesSharedMetrics) {
        $replace("storage_path('framework/cache/commercial-pdf-fonts')", '$GLOBALS[\'commercial_pdf_probe_fonts\']');
    }
    eval('?>'.$source);
    $renderer = new ProfiledCommercialDocumentPdf;
    $settings = app(BusinessSettingsService::class);
    $dataService = new class($settings) extends CommercialDocumentData
    {
        public function make(Quotation|Order $record): array
        {
            $start = hrtime(true);
            $GLOBALS['commercial_pdf_record_ms'] = ($start - $GLOBALS['commercial_pdf_controller_start']) / 1e6;
            $data = parent::make($record);
            $GLOBALS['commercial_pdf_data_ms'] = (hrtime(true) - $start) / 1e6;

            return $data;
        }
    };
    $request = null;
    $actorLookupMs = null;
    $actorSqlMs = null;
    $actorQueries = null;
    $connectionMs = null;
    if ($fixture === null) {
        $connection = app('db')->connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Read-only MySQL transaction required.');
        }
        $connectStart = hrtime(true);
        $connection->getPdo();
        $connectionMs = (hrtime(true) - $connectStart) / 1e6;
        $connection->statement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();
        $inTransaction = true;
        $actorStart = hrtime(true);
        $queryStart = $queries;
        $sqlStart = $sqlMs;
        $actor = User::findOrFail((int) $arguments['user']);
        $actorLookupMs = (hrtime(true) - $actorStart) / 1e6;
        $actorSqlMs = $sqlMs - $sqlStart;
        $actorQueries = $queries - $queryStart;
        if (! $actor->is_active) {
            throw new RuntimeException('Inactive actor.');
        }
        $prefix = $scope === 'admin' ? ($type === 'quotation' ? 'admin/quotations' : 'admin/orders') : ($type === 'quotation' ? 'my/quotations' : 'my/sales');
        $request = Request::create('/api/'.$prefix.'/'.$arguments['id'].'/pdf', 'GET');
        $route = new Route(['GET'], 'api/'.$prefix.'/{document}/pdf', []);
        $route->defaults('document_type', $type === 'quotation' ? 'quotation' : 'sale')->defaults('document_scope', $scope);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $actor);
    } else {
        // Fixture rendering cannot accidentally fall through to a live database.
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1,
            'database.connections.mysql.database' => 'upgrade_test']);
        $fixtures = require $root.'/tests/Fixtures/commercial-document-presentation.php';
        $document = $fixtures[str_starts_with($fixture, 'order') ? 'pending-order' : ($fixture === 'pending-order' ? $fixture : 'quotation')];
        if (str_ends_with($fixture, '-long')) {
            $document['items'] = array_fill(0, 18, $fixtures['quotation']['items'][0]);
            $document['subtotal'] = 21600000;
            $document['discount_total'] = 360000;
            $document['total'] = 21240000 + $document['charges_total'];
            if ($document['balance'] !== null) {
                $document['balance'] = $document['total'];
            }
        }
    }
    $rows = [];
    for ($i = 0; $i < $repeat; $i++) {
        $sqlMs = 0.0;
        $queries = 0;
        $GLOBALS['commercial_pdf_profile'] = [];
        unset($GLOBALS['commercial_pdf_previous']);
        $start = hrtime(true);
        if ($request) {
            $GLOBALS['commercial_pdf_controller_start'] = $start;
            $controller = new CommercialDocumentController($dataService, $renderer);
            $response = $controller->download($request);
            $size = strlen($response->getContent());
            $controllerMs = (hrtime(true) - $start) / 1e6;
        } else {
            $size = strlen($renderer->render($document));
            $controllerMs = null;
        }
        $rows[] = ['iteration' => $i + 1, 'sql_ms' => $request ? $sqlMs : null, 'sql_queries' => $request ? $queries : null,
            'data_prepare_ms_including_sql' => $request ? $GLOBALS['commercial_pdf_data_ms'] : null,
            'record_permission_and_load_ms' => $request ? $GLOBALS['commercial_pdf_record_ms'] : null,
            'renderer_stages_ms' => $GLOBALS['commercial_pdf_profile'], 'controller_ms' => $controllerMs,
            'render_ms' => array_sum($GLOBALS['commercial_pdf_profile']), 'bytes' => $size,
            'pages' => $GLOBALS['commercial_pdf_pages'], 'http_total_ms' => null,
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2)];
    }
    if ($inTransaction) {
        $connection->rollBack();
        $inTransaction = false;
    }
    echo json_encode(['status' => 'OK', 'source' => $fixture ? 'synthetic_dto' : 'read_only_mysql',
        'php_sapi' => PHP_SAPI, 'php_version' => PHP_VERSION, 'config_cached' => $app->configurationIsCached(),
        'font_storage_parent_writable_cli' => is_writable(storage_path('framework/cache')),
        'logo_bytes' => filesize(resource_path('branding/upgrade79-logo.png')),
        'logo_sha256' => hash_file('sha256', resource_path('branding/upgrade79-logo.png')),
        'dompdf_version' => InstalledVersions::getPrettyVersion('dompdf/dompdf'),
        'bootstrap_ms' => $bootstrapMs, 'mysql_connect_ms' => $connectionMs,
        'actor_lookup_ms' => $actorLookupMs, 'actor_sql_ms' => $actorSqlMs, 'actor_sql_queries' => $actorQueries,
        'renderer_sha256' => $rendererHash,
        'shared_metrics_supported' => $usesSharedMetrics, 'rows' => $rows,
        'notes' => ['CLI controller checks real permissions/ownership; not HTTP authentication middleware.',
            'SQL duration is included in data preparation; do not add it twice.',
            'HTTP/proxy/transfer must be measured in the authenticated browser.',
            'Private temporary font/view caches only; no PDF saved; no credentials or customer content reported.']], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    // Never print exception messages/SQL, which could contain private data.
    fwrite(STDERR, json_encode(['status' => 'REFUSED', 'exception_class' => $exception::class]).PHP_EOL);
    $exit = 1;
} finally {
    if ($inTransaction) {
        $connection->rollBack();
    }
    if ($workspace !== null && is_dir($workspace)) {
        (new Filesystem)->deleteDirectory($workspace);
    }
}
exit($exit ?? 0);
