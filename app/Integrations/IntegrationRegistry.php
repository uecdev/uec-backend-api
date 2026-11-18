<?php

namespace App\Integrations;

use App\Integrations\Cloudinary\CloudinaryClient;
use App\Integrations\Firebase\FcmClient;
use App\Integrations\Google\GoogleIdTokenVerifier;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\IntegrationLog;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single place that knows whether each integration is configured, how it is
 * performing (from integration_logs) and how to run a harmless live test.
 * Secrets are never exposed: only presence of configuration is reported.
 */
class IntegrationRegistry
{
    /** @return array<string, array{name: string, purpose: string, configured: bool, env: list<string>}> */
    public function all(): array
    {
        return [
            'paystack' => ['name' => 'Paystack', 'purpose' => 'Subscription payments and webhooks', 'configured' => app(PaystackGateway::class)->isConfigured(), 'env' => ['PAYSTACK_SECRET_KEY']],
            'monnify' => ['name' => 'Monnify', 'purpose' => 'Alternative payment gateway', 'configured' => app(MonnifyGateway::class)->isConfigured(), 'env' => ['MONNIFY_API_KEY', 'MONNIFY_SECRET_KEY', 'MONNIFY_CONTRACT_CODE']],
            'zeptomail' => ['name' => 'Zoho ZeptoMail', 'purpose' => 'Transactional email (OTP, receipts, notices)', 'configured' => filled(config('services.zeptomail.token')) && config('mail.default') === 'zeptomail', 'env' => ['MAIL_MAILER=zeptomail', 'ZEPTOMAIL_TOKEN']],
            'whatsapp' => ['name' => 'WhatsApp Business', 'purpose' => 'Phone OTP delivery and notices', 'configured' => app(WhatsAppClient::class)->isConfigured(), 'env' => ['WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_OTP_TEMPLATE']],
            'firebase' => ['name' => 'Firebase Cloud Messaging', 'purpose' => 'Push notifications', 'configured' => app(FcmClient::class)->isConfigured(), 'env' => ['FIREBASE_PROJECT_ID', 'FIREBASE_CREDENTIALS']],
            'google' => ['name' => 'Google Sign-In', 'purpose' => 'Social login (ID token verification)', 'configured' => app(GoogleIdTokenVerifier::class)->isConfigured(), 'env' => ['GOOGLE_WEB_CLIENT_ID', 'GOOGLE_IOS_CLIENT_ID', 'GOOGLE_ANDROID_CLIENT_ID']],
            'cloudinary' => ['name' => 'Cloudinary', 'purpose' => 'Image upload, optimisation and delivery', 'configured' => app(CloudinaryClient::class)->isConfigured(), 'env' => ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET']],
            's3' => ['name' => 'Amazon S3', 'purpose' => 'Private media: audio, solfa scans, attachments', 'configured' => config('uec.media.private_disk') === 's3' && filled(config('filesystems.disks.s3.bucket')), 'env' => ['UEC_PRIVATE_DISK=s3', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET']],
            'redis' => ['name' => 'Redis', 'purpose' => 'Cache, queues, sessions, OTP challenges', 'configured' => in_array('redis', [config('cache.default'), config('queue.default')], true), 'env' => ['REDIS_HOST', 'CACHE_STORE=redis', 'QUEUE_CONNECTION=redis']],
        ];
    }

    /** @return array{calls: int, failures: int, success_rate: ?float, avg_ms: ?int, last_at: ?string, last_error: ?string} */
    public function stats(string $integration, int $days = 7): array
    {
        $q = IntegrationLog::query()->where('integration', $integration)->where('created_at', '>=', now()->subDays($days));
        $calls = (clone $q)->count();
        $failures = (clone $q)->where('success', false)->count();
        $last = IntegrationLog::query()->where('integration', $integration)->latest('id')->first();
        $lastError = IntegrationLog::query()->where('integration', $integration)->where('success', false)->latest('id')->value('error');

        return [
            'calls' => $calls,
            'failures' => $failures,
            'success_rate' => $calls ? round(($calls - $failures) / $calls * 100, 1) : null,
            'avg_ms' => $calls ? (int) (clone $q)->avg('duration_ms') : null,
            'last_at' => $last?->created_at?->toDateTimeString(),
            'last_error' => $lastError,
        ];
    }

    /** Run a non-destructive live check. Returns [ok, message]. */
    public function test(string $integration, ?string $email = null): array
    {
        try {
            $ok = match ($integration) {
                'paystack' => app(PaystackGateway::class)->ping(),
                'monnify' => app(MonnifyGateway::class)->ping(),
                'whatsapp' => app(WhatsAppClient::class)->ping(),
                'firebase' => app(FcmClient::class)->ping(),
                'google' => app(GoogleIdTokenVerifier::class)->keys() !== [],
                'cloudinary' => app(CloudinaryClient::class)->ping(),
                'zeptomail' => $this->testMail($email),
                's3' => $this->testStorage(),
                'redis' => (string) Redis::connection()->ping() !== '',
                default => false,
            };

            return [$ok, $ok ? 'Connection successful.' : 'The provider rejected the request. Check the credentials.'];
        } catch (Throwable $e) {
            return [false, Str::limit($e->getMessage(), 300)];
        }
    }

    private function testMail(?string $email): bool
    {
        if (! $email) {
            return false;
        }
        Mail::raw('This is a test email from the UEC administration dashboard. ZeptoMail delivery is working.', function ($m) use ($email) {
            $m->to($email)->subject('UEC ZeptoMail test');
        });

        return true;
    }

    private function testStorage(): bool
    {
        $disk = Storage::disk(config('uec.media.private_disk'));
        $path = 'healthchecks/'.Str::ulid().'.txt';
        $disk->put($path, 'ok');
        $ok = $disk->get($path) === 'ok';
        $disk->delete($path);

        return $ok;
    }
}
