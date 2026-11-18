<?php

namespace Tests\Feature\Api;

use App\Events\AccountEvent;
use App\Jobs\DeliverOtp;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetAndGoogleTest extends TestCase
{
    public function test_forgot_password_is_enumeration_safe(): void
    {
        Queue::fake([DeliverOtp::class]);

        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['channel' => 'email', 'identifier' => 'ghost@example.com'])
            ->assertOk()->assertJsonStructure(['data' => ['verification_id', 'destination_masked']]);
        Queue::assertNothingPushed();

        // No code was sent, so no code can ever verify it.
        $this->postJson('/api/v1/auth/password/verify', ['verification_id' => $unknown->json('data.verification_id'), 'code' => '123456'])
            ->assertUnprocessable();
    }

    public function test_full_reset_flow_revokes_all_sessions(): void
    {
        Queue::fake([DeliverOtp::class]);
        Event::fake([AccountEvent::class]);
        $user = $this->member(['email' => 'member@example.com']);
        $oldToken = $this->tokenFor($user);

        $challenge = $this->postJson('/api/v1/auth/password/forgot', ['channel' => 'email', 'identifier' => 'member@example.com'])->assertOk();
        $reset = $this->postJson('/api/v1/auth/password/verify', [
            'verification_id' => $challenge->json('data.verification_id'), 'code' => $this->capturedOtp(),
        ])->assertOk()->json('data.reset_token');

        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $reset, 'password' => 'NewPass456', 'password_confirmation' => 'Mismatch1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $reset, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertNoContent();

        // Single use.
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $reset, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertStatus(410)->assertJsonPath('code', 'RESET_EXPIRED');

        $this->getJson('/api/v1/me', $oldToken)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['channel' => 'email', 'identifier' => 'member@example.com', 'password' => 'NewPass456'])->assertOk();
        Event::assertDispatched(AccountEvent::class, fn ($e) => $e->event === 'password_changed');
    }

    /** @return array{0: string, 1: array} [private key PEM, JWKS] */
    private function googleKeys(): array
    {
        $pair = $this->rsaKeyPair();
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [$pair['private'], ['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid',
            'n' => $b64($pair['n']), 'e' => $b64($pair['e']),
        ]]]];
    }

    private function idToken(string $privatePem, array $claims): string
    {
        return JWT::encode(array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'web-client-id.apps.googleusercontent.com',
            'sub' => '1234567890',
            'email' => 'praise@gmail.com',
            'email_verified' => true,
            'name' => 'Praise A',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claims), $privatePem, 'RS256', 'test-kid');
    }

    public function test_google_sign_in_verifies_token_and_links_accounts(): void
    {
        [$pem, $jwks] = $this->googleKeys();
        config(['services.google.client_ids' => ['web-client-id.apps.googleusercontent.com']]);
        Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response($jwks, 200, ['Cache-Control' => 'public, max-age=20000'])]);

        // Wrong audience → rejected.
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, ['aud' => 'someone-else'])])
            ->assertUnauthorized();
        // Unverified email → rejected.
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, ['email_verified' => false])])
            ->assertUnauthorized();
        // Expired → rejected.
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, ['exp' => time() - 3600, 'iat' => time() - 7200])])
            ->assertUnauthorized();

        // Existing email account gets linked by verified email.
        $existing = $this->member(['email' => 'praise@gmail.com']);
        $res = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, []), 'device_name' => 'UEC ios app'])->assertOk();
        $this->assertSame($existing->id, $res->json('data.user.id'));
        $this->assertSame('1234567890', $existing->fresh()->google_sub);

        // Next time it links by sub even if the email changed at Google.
        $again = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, ['email' => 'new@gmail.com'])])->assertOk();
        $this->assertSame($existing->id, $again->json('data.user.id'));

        // A brand-new Google user is created as a verified member.
        $new = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($pem, ['sub' => '999', 'email' => 'fresh@gmail.com'])])->assertOk();
        $this->assertSame(['member'], $new->json('data.user.roles'));
        $this->assertNotNull(User::query()->where('google_sub', '999')->value('email_verified_at'));
    }
}
