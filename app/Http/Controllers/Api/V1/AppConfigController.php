<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

/** #19 GET /app/config — remote configuration (backend-handoff C.4). */
class AppConfigController extends Controller
{
    public function __invoke(AppSettings $settings, PaymentGatewayManager $gateways): JsonResponse
    {
        $s = $settings->all();

        return $this->data([
            'donation_url' => $s['donation_url'],
            'min_supported_version' => $s['min_supported_version'],
            'latest_version' => $s['latest_version'],
            'store_urls' => ['android' => $s['store_url_android'], 'ios' => $s['store_url_ios']],
            // First entry = provider the app uses for checkout; only configured providers are listed.
            'payment_providers' => $gateways->available(),
            'support_email' => $s['support_email'],
            // Additive fields (not in the original contract) for maintenance / update banners.
            'maintenance_message' => $s['maintenance_message'],
            'update_message' => $s['update_message'],
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
