<?php

namespace Tests\Feature\Api;

use App\Jobs\DeliverOtp;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthLoginAndTokenTest extends TestCase
{
    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'channel' => 'email', 'identifier' => 'member@example.com', 'password' => 'Password123', 'device_name' => 'UEC android app',
        ], $overrides));
    }

    public function test_login_with_email_and_phone(): void
    {
        $this->member(['email' => 'member@example.com', 'phone' => '+2348031234567']);

        $this->login()->assertOk()->assertJsonPath('data.user.email', 'member@example.com')
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token', 'expires_in', 'token_type']]]);

        $this->login(['channel' => 'phone', 'identifier' => '0803 123 4567'])->assertOk()
            ->assertJsonPath('data.user.phone', '+2348031234567');
    }

    public function test_wrong_credentials_are_generic(): void
    {
        $this->member(['email' => 'member@example.com']);

        $wrongPassword = $this->login(['password' => 'Wrong12345'])->assertUnprocessable();
        $unknownUser = $this->login(['identifier' => 'nobody@example.com'])->assertUnprocessable();

        $wrongPassword->assertJsonValidationErrors(['identifier']);
        $this->assertSame($wrongPassword->json('message'), $unknownUser->json('message'));
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $this->member(['email' => 'member@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->login(['password' => 'Wrong12345'])->assertUnprocessable();
        }
        $this->login()->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_unverified_account_gets_403_with_fresh_challenge(): void
    {
        Queue::fake([DeliverOtp::class]);
        $user = User::factory()->unverified()->withRole('member')->create(['email' => 'member@example.com']);

        $res = $this->login()->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_UNVERIFIED')
            ->assertJsonStructure(['message', 'code', 'verification' => ['verification_id', 'channel', 'destination_masked', 'expires_in', 'resend_in']]);

        $verify = $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $res->json('verification.verification_id'), 'code' => $this->capturedOtp(),
        ])->assertCreated();

        $this->assertSame($user->id, $verify->json('data.user.id'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_suspended_account_cannot_login(): void
    {
        User::factory()->suspended()->create(['email' => 'member@example.com']);
        $this->login()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_refresh_rotates_and_reuse_revokes_the_family(): void
    {
        $this->member(['email' => 'member@example.com']);
        $tokens = $this->login()->json('data.tokens');

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()->json('data');
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);

        // The old access token was revoked by rotation.
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        // Theft detection: replaying the old refresh token kills the whole family.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('code', 'REFRESH_INVALID');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $rotated['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('code', 'REFRESH_INVALID');

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.refresh_token_reuse']);
    }

    public function test_access_token_expires_after_15_minutes(): void
    {
        $this->member(['email' => 'member@example.com']);
        $access = $this->login()->json('data.tokens.access_token');

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$access}"])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(16)->minutes();
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$access}"])
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_expired_refresh_token_is_rejected(): void
    {
        $this->member(['email' => 'member@example.com']);
        $refresh = $this->login()->json('data.tokens.refresh_token');
        $this->travel(61)->days();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized()->assertJsonPath('code', 'REFRESH_INVALID');
    }

    public function test_logout_revokes_session(): void
    {
        $this->member(['email' => 'member@example.com']);
        $tokens = $this->login()->json('data.tokens');
        $auth = ['Authorization' => 'Bearer '.$tokens['access_token']];

        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']], $auth)->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', $auth)->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
        $this->getJson('/api/v1/me/subscription')->assertUnauthorized();
        $this->putJson('/api/v1/me/favourites', [])->assertUnauthorized();
    }
}
