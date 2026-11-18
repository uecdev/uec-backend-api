<?php

namespace Tests\Feature\Api;

use App\Jobs\DeliverOtp;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DeliverOtp::class]);
    }

    public function test_email_registration_creates_account_only_after_otp_verification(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'channel' => 'email', 'email' => ' Praise@Example.com ', 'password' => 'Secret123',
        ]);

        $res->assertCreated()
            ->assertJsonStructure(['data' => ['verification_id', 'channel', 'destination_masked', 'expires_in', 'resend_in']])
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.expires_in', 600)
            ->assertJsonPath('data.resend_in', 45);
        $this->assertSame(0, User::query()->count(), 'No user row before verification');

        $code = $this->capturedOtp();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $verify = $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $res->json('data.verification_id'), 'code' => $code, 'device_name' => 'UEC ios app',
        ]);

        $verify->assertCreated()
            ->assertJsonPath('data.user.email', 'praise@example.com')
            ->assertJsonPath('data.user.is_premium', false)
            ->assertJsonPath('data.user.roles', ['member'])
            ->assertJsonPath('data.tokens.token_type', 'Bearer')
            ->assertJsonPath('data.tokens.expires_in', 900)
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token']]]);
        $this->assertNotNull($verify->json('data.user.email_verified_at'));

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$verify->json('data.tokens.access_token')])
            ->assertOk()->assertJsonPath('data.email', 'praise@example.com');
    }

    public function test_phone_registration_normalises_local_numbers_and_masks_destination(): void
    {
        $this->postJson('/api/v1/auth/register', ['channel' => 'phone', 'phone' => '08123456789', 'password' => 'Secret123'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'phone')
            ->assertJsonPath('data.destination_masked', '+234 ••• ••• 6789');

        Queue::assertPushed(DeliverOtp::class, fn (DeliverOtp $job) => $job->destination === '+2348123456789' && $job->channel === 'phone');
    }

    public function test_registration_validation_rules(): void
    {
        $this->postJson('/api/v1/auth/register', ['channel' => 'phone', 'phone' => '+2341234', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'password']);

        $this->postJson('/api/v1/auth/register', ['channel' => 'email', 'email' => 'a@b.com', 'password' => 'lettersonly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        User::factory()->create(['email' => 'taken@example.com']);
        $this->postJson('/api/v1/auth/register', ['channel' => 'email', 'email' => 'TAKEN@example.com', 'password' => 'Secret123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_wrong_code_returns_422_then_locks_after_five_attempts(): void
    {
        $id = $this->postJson('/api/v1/auth/register', ['channel' => 'email', 'email' => 'x@example.com', 'password' => 'Secret123'])
            ->json('data.verification_id');
        $code = $this->capturedOtp();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $wrong])
                ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        }
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $wrong])
            ->assertStatus(429)->assertJsonPath('code', 'OTP_LOCKED');

        // Even the right code is rejected once locked.
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(429)->assertJsonPath('code', 'OTP_LOCKED');
    }

    public function test_expired_code_returns_410(): void
    {
        $id = $this->postJson('/api/v1/auth/register', ['channel' => 'email', 'email' => 'y@example.com', 'password' => 'Secret123'])
            ->json('data.verification_id');
        $code = $this->capturedOtp();

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(410)->assertJsonPath('code', 'OTP_EXPIRED');
    }

    public function test_resend_respects_cooldown_and_invalidates_previous_code(): void
    {
        $id = $this->postJson('/api/v1/auth/register', ['channel' => 'email', 'email' => 'z@example.com', 'password' => 'Secret123'])
            ->json('data.verification_id');
        $first = $this->capturedOtp();

        $this->postJson('/api/v1/auth/otp/resend', ['verification_id' => $id])
            ->assertStatus(429)->assertHeader('Retry-After');

        $this->travel(46)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['verification_id' => $id])
            ->assertOk()->assertJsonPath('data.resend_in', 45)->assertJsonPath('data.expires_in', 600);

        Queue::assertPushed(DeliverOtp::class, 2);
        $codes = [];
        Queue::assertPushed(DeliverOtp::class, function (DeliverOtp $job) use (&$codes) {
            $codes[] = $job->code;

            return true;
        });
        $second = end($codes);

        if ($first !== $second) {
            $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $first])
                ->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $second])->assertCreated();
    }

    public function test_unknown_verification_id_is_treated_as_expired(): void
    {
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => 'ver_nope', 'code' => '123456'])
            ->assertStatus(410)->assertJsonPath('code', 'OTP_EXPIRED');
    }
}
