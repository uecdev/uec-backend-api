<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\Webhooks\WebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** W1–W3: gateway webhooks and the browser payment callback (backend-handoff E.3, E.4). */
class PaymentWebhookController extends Controller
{
    public function paystack(Request $request, WebhookIngestor $ingestor): JsonResponse
    {
        [$status, $outcome] = $ingestor->ingest('paystack', $request);

        return response()->json(['status' => $outcome], $status);
    }

    public function monnify(Request $request, WebhookIngestor $ingestor): JsonResponse
    {
        [$status, $outcome] = $ingestor->ingest('monnify', $request);

        return response()->json(['status' => $outcome], $status);
    }

    /**
     * W3 GET /payments/callback — the gateway sends the browser here; we bounce to the
     * app's custom scheme. Query params are used only to build the redirect.
     */
    public function callback(Request $request): Response
    {
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? $request->query('paymentReference') ?? '');
        $reference = preg_match('/^[A-Za-z0-9_\-|.]{1,100}$/', $reference) ? $reference : '';

        $target = (string) config('uec.payments.app_callback_url');
        if ($reference !== '') {
            $stored = Payment::query()->where('reference', $reference)->value('app_callback_url');
            $target = $stored ?: $target; // validated at checkout time
        }
        $location = $target.(str_contains($target, '?') ? '&' : '?').http_build_query(['reference' => $reference]);

        $html = view('payments.callback', ['location' => $location])->render();

        return response($html, 302, [
            'Location' => $location,
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
        ]);
    }
}
