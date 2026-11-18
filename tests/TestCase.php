<?php

namespace Tests;

use App\Jobs\DeliverOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = TestSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Every external call must be explicitly faked in the test that makes it.
        Http::preventStrayRequests();
    }

    /**
     * API requests are stateless: drop guard-cached users between test requests
     * so each request authenticates from its own bearer token, as in production.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (str_starts_with(ltrim((string) parse_url($uri, PHP_URL_PATH), '/'), 'api/')) {
            $this->app['auth']->guard('sanctum')->forgetUser();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Authorization header for a freshly issued access token. */
    protected function tokenFor(User $user): array
    {
        $token = $user->createToken('test', ['*'], now()->addMinutes(15))->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    protected function member(array $attributes = []): User
    {
        return User::factory()->withRole('member')->create($attributes);
    }

    /**
     * Capture the plain OTP code from the queued DeliverOtp job.
     * Call Queue::fake([DeliverOtp::class]) before triggering the OTP.
     */
    protected function capturedOtp(): string
    {
        $code = null;
        Queue::assertPushed(DeliverOtp::class, function (DeliverOtp $job) use (&$code) {
            $code = $job->code;

            return true;
        });

        return (string) $code;
    }

    /**
     * Generate an RSA key pair for signing test JWTs (Google ID tokens, FCM service accounts).
     *
     * @return array{private: string, n: string, e: string}
     */
    protected function rsaKeyPair(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows PHP builds need an explicit openssl.cnf.
        $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($cnf)) {
            $options['config'] = $cnf;
        }

        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);
        $details = openssl_pkey_get_details($key);

        return ['private' => $private, 'n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    protected function paystackSignature(string $body): string
    {
        return hash_hmac('sha512', $body, (string) config('services.paystack.secret_key'));
    }

    protected function configurePaystack(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_secret', 'services.paystack.base_url' => 'https://api.paystack.co']);
    }

    protected function configureMonnify(): void
    {
        config([
            'services.monnify.api_key' => 'MK_TEST',
            'services.monnify.secret_key' => 'monnify_secret',
            'services.monnify.contract_code' => '1234567890',
            'services.monnify.base_url' => 'https://sandbox.monnify.com',
        ]);
    }
}
