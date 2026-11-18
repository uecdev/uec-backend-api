<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionStatus;
use App\Events\AccountEvent;
use App\Jobs\DeliverOtp;
use App\Models\Feedback;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileAndFeedbackTest extends TestCase
{
    public function test_show_and_update_profile(): void
    {
        $user = $this->member(['name' => 'Praise']);
        $auth = $this->tokenFor($user);

        $this->getJson('/api/v1/me', $auth)->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.is_premium', false)
            ->assertJsonStructure(['data' => ['id', 'name', 'phone', 'email', 'phone_verified_at', 'email_verified_at', 'avatar_url', 'is_premium', 'roles', 'created_at']]);

        $this->patchJson('/api/v1/me', ['name' => '<b>Praise A</b>'], $auth)->assertOk()->assertJsonPath('data.name', 'Praise A');
        $this->patchJson('/api/v1/me', ['name' => null], $auth)->assertOk()->assertJsonPath('data.name', null);
        $this->patchJson('/api/v1/me', ['name' => str_repeat('x', 81)], $auth)->assertUnprocessable();
    }

    public function test_avatar_upload_reencodes_to_square_jpeg_on_the_public_disk(): void
    {
        Storage::fake('public');
        $user = $this->member();

        $res = $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 600)], $this->tokenFor($user) + ['Accept' => 'application/json'])
            ->assertOk();

        $path = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([512, 512], [$w, $h]);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertNotNull($res->json('data.avatar_url'));

        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')], $this->tokenFor($user) + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
    }

    public function test_contact_change_requires_otp_and_uniqueness(): void
    {
        Queue::fake([DeliverOtp::class]);
        Event::fake([AccountEvent::class]);
        $user = $this->member(['email' => 'old@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);
        $auth = $this->tokenFor($user);

        $this->postJson('/api/v1/me/contact', ['channel' => 'email', 'value' => 'taken@example.com'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['value']);

        $challenge = $this->postJson('/api/v1/me/contact', ['channel' => 'email', 'value' => 'New@Example.com'], $auth)
            ->assertCreated()->json('data');
        $this->assertSame('old@example.com', $user->fresh()->email, 'Not saved before verification');

        $this->postJson('/api/v1/me/contact/verify', ['verification_id' => $challenge['verification_id'], 'code' => $this->capturedOtp()], $auth)
            ->assertOk()->assertJsonPath('data.email', 'new@example.com');

        Event::assertDispatched(AccountEvent::class, fn ($e) => $e->event === 'email_changed' && $e->email === 'old@example.com');
    }

    public function test_delete_account_cancels_subscription_revokes_tokens_and_anonymises(): void
    {
        $this->configurePaystack();
        Http::fake(['api.paystack.co/subscription/disable' => Http::response(['status' => true, 'message' => 'Subscription disabled successfully'])]);
        Event::fake([AccountEvent::class]);

        $user = $this->member(['email' => 'leaving@example.com']);
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id, 'provider_subscription_code' => 'SUB_abc', 'provider_email_token' => 'tok_123',
        ]);
        $user->devices()->create(['token' => 'fcm-1', 'platform' => 'ios']);
        Program::query()->create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'P', 'hymn_ids' => [], 'updated_at' => now()]);
        $auth = $this->tokenFor($user);

        $this->deleteJson('/api/v1/me', ['confirmation' => 'nope'], $auth)->assertUnprocessable()->assertJsonValidationErrors(['confirmation']);
        $this->deleteJson('/api/v1/me', ['confirmation' => 'DELETE'], $auth)->assertNoContent();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/subscription/disable') && $r['code'] === 'SUB_abc' && $r['token'] === 'tok_123');
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);

        $deleted = User::withTrashed()->find($user->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->email);
        $this->assertNull($deleted->phone);
        $this->assertSame(0, $deleted->devices()->count());
        $this->assertSame(0, Program::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $deleted->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $auth)->assertUnauthorized();
        Event::assertDispatched(AccountEvent::class, fn ($e) => $e->event === 'deleted' && $e->email === 'leaving@example.com');
    }

    public function test_guest_feedback_with_attachments(): void
    {
        Storage::fake('local');

        $this->post('/api/v1/feedback', [
            'type' => 'Suggestion',
            'message' => 'Please add a dark hymn view.',
            'contact_consent' => 'true',
            'attachments' => [
                UploadedFile::fake()->image('screen.png'),
                UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json', 'X-App-Version' => '1.0.0', 'X-Platform' => 'android'])
            ->assertCreated()->assertJsonPath('data.status', 'received');

        $feedback = Feedback::query()->with('attachments')->sole();
        $this->assertNull($feedback->user_id);
        $this->assertSame('suggestion', $feedback->type);
        $this->assertTrue($feedback->contact_consent);
        $this->assertSame('android', $feedback->platform);
        $this->assertCount(2, $feedback->attachments);
        Storage::disk('local')->assertExists($feedback->attachments->first()->path);
    }

    public function test_feedback_links_signed_in_user_and_validates_files(): void
    {
        Storage::fake('local');
        $user = $this->member();

        $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Crash on open'], $this->tokenFor($user) + ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame($user->id, Feedback::query()->value('user_id'));

        $files = array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.png"), range(1, 5));
        $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Too many', 'attachments' => $files], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['attachments']);

        $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Exe', 'attachments' => [UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['attachments.0']);

        $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Big', 'attachments' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['attachments.0']);
    }
}
