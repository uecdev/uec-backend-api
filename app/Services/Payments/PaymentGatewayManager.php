<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Settings\AppSettings;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public const PROVIDERS = ['paystack', 'monnify'];

    public function __construct(private readonly AppSettings $settings) {}

    public function gateway(string $name): PaymentGateway
    {
        return match ($name) {
            'paystack' => app(PaystackGateway::class),
            'monnify' => app(MonnifyGateway::class),
            default => throw new InvalidArgumentException("Unknown payment provider [{$name}]."),
        };
    }

    /** Providers enabled by admins (in preference order) that also have credentials. */
    public function available(): array
    {
        return array_values(array_filter(
            $this->settings->paymentProviders(),
            fn (string $p) => $this->gateway($p)->isConfigured(),
        ));
    }

    public function isAvailable(string $name): bool
    {
        return in_array($name, $this->available(), true);
    }
}
