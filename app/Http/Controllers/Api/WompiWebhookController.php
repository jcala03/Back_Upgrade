<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WompiWebhookException;
use App\Http\Controllers\Controller;
use App\Services\WompiWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WompiWebhookController extends Controller
{
    public function __invoke(Request $request, WompiWebhookService $webhooks): JsonResponse
    {
        try {
            if (! $request->isJson()) {
                throw new WompiWebhookException('WOMPI_MALFORMED_EVENT', 415);
            }
            $result = $webhooks->receive($request->getContent(), $request->header('X-Event-Checksum'));

            return response()->json(['status' => $result['status']], $result['http'])->header('Cache-Control', 'no-store');
        } catch (WompiWebhookException $exception) {
            return response()->json(['code' => $exception->errorCode], $exception->httpStatus)->header('Cache-Control', 'no-store');
        }
    }
}
