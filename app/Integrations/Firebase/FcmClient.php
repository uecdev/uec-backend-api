<?php

namespace App\Integrations\Firebase;

use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;
use App\Models\IntegrationLog;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1 using a service account (OAuth2 JWT bearer grant).
 * Payload contract (backend-handoff H.1):
 *   notification: {title, body}, data: {route, type}
 */
class FcmClient
{
    use LogsIntegrationCalls;

    public const RESULT_OK = 'ok';

    public const RESULT_INVALID_TOKEN = 'invalid_token';

    public const RESULT_ERROR = 'error';

    protected function integrationName(): string
    {
        return 'firebase';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.firebase.project_id')) && $this->credentials() !== null;
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string}|null */
    public function credentials(): ?array
    {
        $raw = null;
        if ($b64 = config('services.firebase.credentials_base64')) {
            $raw = base64_decode((string) $b64, true) ?: null;
        } elseif (($path = config('services.firebase.credentials')) && is_readable((string) $path)) {
            $raw = file_get_contents((string) $path) ?: null;
        }
        $json = $raw ? json_decode($raw, true) : null;

        return is_array($json) && isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    public function accessToken(): string
    {
        $creds = $this->credentials();
        if (! $creds || ! filled(config('services.firebase.project_id'))) {
            throw IntegrationException::notConfigured('firebase');
        }

        return Cache::remember('fcm:access_token:'.sha1($creds['client_email']), 3300, function () use ($creds) {
            $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $assertion = JWT::encode([
                'iss' => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $creds['private_key'], 'RS256');

            $response = $this->call('oauth.token', 'POST', $tokenUri, fn () => $this->http(10)->asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]));

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new IntegrationException('firebase', 'Could not obtain an FCM access token.', $response->status());
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * Send one message per token, concurrently. Returns token => result.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    public function sendMany(array $tokens, string $title, string $body, array $data = []): array
    {
        if ($tokens === []) {
            return [];
        }

        $accessToken = $this->accessToken();
        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', config('services.firebase.project_id'));
        $results = [];

        foreach (array_chunk($tokens, (int) config('uec.push.concurrency', 20)) as $chunk) {
            $started = hrtime(true);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $token) => $pool->as($token)->withToken($accessToken)->acceptJson()->timeout((int) config('services.firebase.timeout', 10))
                    ->post($url, ['message' => $this->message($token, $title, $body, $data)]),
                $chunk,
            ));

            $ok = 0;
            foreach ($chunk as $token) {
                $results[$token] = $this->classify($responses[$token] ?? null);
                $ok += $results[$token] === self::RESULT_OK ? 1 : 0;
            }
            $this->writeBatchLog(count($chunk), $ok, $started);
        }

        return $results;
    }

    /** @param array<string, string> $data */
    private function message(string $token, string $title, string $body, array $data): array
    {
        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH', 'notification' => ['sound' => 'default']],
            'apns' => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
        ];
    }

    private function classify(mixed $response): string
    {
        if (! $response instanceof Response) {
            return self::RESULT_ERROR;
        }
        if ($response->successful()) {
            return self::RESULT_OK;
        }

        $code = $response->json('error.details.0.errorCode') ?? $response->json('error.status');
        if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true) || $response->status() === 404) {
            return self::RESULT_INVALID_TOKEN;
        }

        return self::RESULT_ERROR;
    }

    private function writeBatchLog(int $count, int $ok, int $started): void
    {
        IntegrationLog::query()->create([
            'integration' => 'firebase',
            'operation' => "messages.send x{$count}",
            'method' => 'POST',
            'endpoint' => '/v1/projects/{project}/messages:send',
            'status_code' => $ok === $count ? 200 : null,
            'success' => $ok > 0 || $count === 0,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error' => $ok === $count ? null : ($count - $ok).' of '.$count.' messages not delivered',
        ]);
    }

    public function ping(): bool
    {
        Cache::forget('fcm:access_token:'.sha1($this->credentials()['client_email'] ?? ''));

        return $this->accessToken() !== '';
    }
}
