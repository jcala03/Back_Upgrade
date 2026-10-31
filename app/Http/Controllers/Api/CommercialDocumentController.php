<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CommercialDocumentMail;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\CommercialDocumentData;
use App\Services\CommercialDocumentPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class CommercialDocumentController extends Controller
{
    public function __construct(private readonly CommercialDocumentData $documents, private readonly CommercialDocumentPdf $pdf) {}

    /** Same permission and employee ownership as the existing CRM detail endpoints. */
    private function record(Request $request): Quotation|Order
    {
        $quotation = $request->route('document_type') === 'quotation';
        $own = $request->route('document_scope') === 'own';
        abort_unless($request->user()->hasPermission(($quotation ? 'quotations.view' : 'orders.view').($own ? '_own' : '')), 403);
        $query = $quotation ? Quotation::query() : Order::query();
        if ($own) {
            $employeeId = $request->user()->employee?->id;
            abort_unless($employeeId, 404);
            $query->where('sales_employee_id', $employeeId);
        }

        return $query->findOrFail($request->route('document'));
    }

    private function canEmail(Request $request): bool
    {
        $own = $request->route('document_scope') === 'own';
        $permission = $request->route('document_type') === 'quotation'
            ? ($own ? 'quotations.send_own' : 'quotations.update')
            : ($own ? 'orders.create_own' : 'orders.create');

        return $request->user()->hasPermission($permission);
    }

    private function simulated(): bool
    {
        return ! app()->environment('production') || ! config('commercial_documents.real_mail_enabled');
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'];
    }

    public function preview(Request $request): JsonResponse
    {
        $document = $this->documents->make($this->record($request));

        return response()->json(['data' => array_intersect_key($document, array_flip([
            'id', 'kind', 'code', 'filename', 'number', 'title', 'customer',
        ])) + ['can_email' => $this->canEmail($request), 'email_simulated' => $this->simulated()]], 200, $this->privateHeaders());
    }

    public function download(Request $request): Response
    {
        $document = $this->documents->make($this->record($request));

        return response($this->pdf->render($document), 200, $this->privateHeaders() + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition('attachment', $document['filename']),
        ]);
    }

    public function email(Request $request): JsonResponse
    {
        $record = $this->record($request);
        abort_unless($this->canEmail($request), 403);
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'from' => ['prohibited'], 'cc' => ['prohibited'], 'bcc' => ['prohibited'],
            'path' => ['prohibited'], 'attachment' => ['prohibited'], 'items' => ['prohibited'],
        ]);
        $document = $this->documents->make($record);
        $simulated = $this->simulated();
        try {
            Mail::mailer($simulated ? 'array' : config('commercial_documents.mailer'))
                ->to($data['email'])->send(new CommercialDocumentMail($document, $this->pdf->render($document)));
        } catch (Throwable $exception) {
            // No PDF bytes, SMTP credentials or customer addresses in application logs.
            Log::warning('Commercial document email failed', ['document_id' => $record->id, 'exception' => $exception::class]);

            return response()->json(['message' => 'No se pudo preparar o entregar el correo. No se modificó la operación comercial.'], 503, $this->privateHeaders());
        }

        return response()->json(['message' => $simulated
            ? 'Correo de prueba generado con el PDF adjunto. No se realizó ningún envío real.'
            : 'Correo aceptado por el transporte de envío. Esto no confirma su recepción.',
            'data' => ['simulated' => $simulated, 'filename' => $document['filename']]], 200, $this->privateHeaders());
    }
}
