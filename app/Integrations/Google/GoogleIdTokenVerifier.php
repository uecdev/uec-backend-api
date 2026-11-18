<?php

namespace App\Integrations\Google;

use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Verifies Google Sign-In ID tokens on the server (backend-handoff D.4):
 * RS256 signature against Google's JWKS, aud ∈ configured client IDs,
 * iss = accounts.google.com, not expired, email_verified = true.
 */
class GoogleIdTokenVerifier
{
    use LogsIntegrationCalls;

    protected function integrationName(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return config('services.google.client_ids') !== [];
    }

    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string, picture: ?string}|null
     *                                                                                                        null when the token is invalid for any reason
     */
    public function verify(string $idToken): ?array
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('google');
        }

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (IntegrationException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        $validAud = in_array($aud, config('services.google.client_ids'), true);
        $validIss = in_array($claims['iss'] ?? null, config('services.google.issuers'), true);
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $validAud || ! $validIss || ! $emailVerified || empty($claims['sub'])) {
            return null;
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null,
            'email_verified' => $emailVerified,
            'name' => isset($claims['name']) ? mb_substr((string) $claims['name'], 0, 80) : null,
            'picture' => $claims['picture'] ?? null,
        ];
    }

    /** Google's JWKS, cached per its Cache-Control max-age. */
    public function keys(): array
    {
        $cached = Cache::get('google:jwks');
        if (is_array($cached)) {
            return $cached;
        }

        $url = (string) config('services.google.jwks_url');
        $response = $this->call('jwks.fetch', 'GET', $url, fn () => $this->http(10)->get($url));
        if (! $response->successful() || ! is_array($response->json('keys'))) {
            throw new IntegrationException('google', 'Unable to fetch Google signing keys.', $response->status());
        }

        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);
        $jwks = $response->json();
        Cache::put('google:jwks', $jwks, max(300, (int) ($m[1] ?? 3600)));

        return $jwks;
    }
}
