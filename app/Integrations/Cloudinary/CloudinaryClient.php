<?php

namespace App\Integrations\Cloudinary;

use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;

/**
 * Cloudinary Upload API with server-side signatures (no unsigned uploads).
 * Images are delivered with f_auto,q_auto for format/quality optimisation.
 */
class CloudinaryClient
{
    use LogsIntegrationCalls;

    protected function integrationName(): string
    {
        return 'cloudinary';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.cloudinary.cloud_name'))
            && filled(config('services.cloudinary.api_key'))
            && filled(config('services.cloudinary.api_secret'));
    }

    /**
     * Upload an image with an incoming transformation (stored already transformed,
     * so original EXIF/GPS metadata is not retained).
     *
     * @return array{public_id: string, secure_url: string, width: int, height: int, bytes: int}
     */
    public function uploadImage(string $path, string $folder, ?string $publicId = null, ?string $transformation = null): array
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('cloudinary');
        }

        $params = array_filter([
            'folder' => trim(config('services.cloudinary.folder').'/'.$folder, '/'),
            'public_id' => $publicId,
            'overwrite' => $publicId ? 'true' : null,
            'invalidate' => $publicId ? 'true' : null,
            'transformation' => $transformation,
            'timestamp' => (string) time(),
        ]);
        $params['signature'] = $this->sign($params);
        $params['api_key'] = (string) config('services.cloudinary.api_key');

        $url = sprintf('https://api.cloudinary.com/v1_1/%s/image/upload', config('services.cloudinary.cloud_name'));
        $response = $this->call('image.upload', 'POST', '/image/upload', fn () => $this->http((int) config('services.cloudinary.timeout', 30), 1)
            ->attach('file', fopen($path, 'r'), basename($path))
            ->post($url, $params));

        if (! $response->successful()) {
            throw new IntegrationException('cloudinary', (string) ($response->json('error.message') ?? 'Upload failed.'), $response->status());
        }

        return [
            'public_id' => (string) $response->json('public_id'),
            'secure_url' => (string) $response->json('secure_url'),
            'width' => (int) $response->json('width'),
            'height' => (int) $response->json('height'),
            'bytes' => (int) $response->json('bytes'),
        ];
    }

    public function destroy(string $publicId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $params = ['public_id' => $publicId, 'timestamp' => (string) time(), 'invalidate' => 'true'];
        $params['signature'] = $this->sign($params);
        $params['api_key'] = (string) config('services.cloudinary.api_key');

        $url = sprintf('https://api.cloudinary.com/v1_1/%s/image/destroy', config('services.cloudinary.cloud_name'));

        return $this->call('image.destroy', 'POST', '/image/destroy', fn () => $this->http(15, 1)->asForm()->post($url, $params))->successful();
    }

    /** Optimised delivery URL: f_auto,q_auto plus an optional transformation. */
    public function deliveryUrl(string $publicId, ?string $transformation = null): string
    {
        $t = implode(',', array_filter(['f_auto', 'q_auto', $transformation]));

        return sprintf('https://res.cloudinary.com/%s/image/upload/%s/%s', config('services.cloudinary.cloud_name'), $t, $publicId);
    }

    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $url = sprintf('https://api.cloudinary.com/v1_1/%s/usage', config('services.cloudinary.cloud_name'));

        return $this->call('usage', 'GET', '/usage', fn () => $this->http(10, 0)
            ->withBasicAuth((string) config('services.cloudinary.api_key'), (string) config('services.cloudinary.api_secret'))
            ->get($url))->successful();
    }

    /** Cloudinary signature: SHA-1 of sorted "k=v&k=v" params + api_secret. */
    private function sign(array $params): string
    {
        ksort($params);
        $toSign = implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));

        return sha1($toSign.config('services.cloudinary.api_secret'));
    }
}
