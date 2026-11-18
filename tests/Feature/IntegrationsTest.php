<?php

namespace Tests\Feature;

use App\Enums\PushCampaignStatus;
use App\Integrations\Cloudinary\CloudinaryClient;
use App\Integrations\Firebase\FcmClient;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Jobs\DeliverOtp;
use App\Models\Device;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PushCampaign;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\OtpCodeNotification;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\SubscriptionNotification;
use App\Services\Notifications\PushCampaignService;
use App\Services\Notifications\ScheduledReminders;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    private function configureFirebase(): void
    {
        $pair = $this->rsaKeyPair();
        config([
            'services.firebase.project_id' => 'uec-test',
            'services.firebase.credentials_base64' => base64_encode(json_encode([
                'client_email' => 'fcm@uec-test.iam.gserviceaccount.com',
                'private_key' => $pair['private'],
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ])),
        ]);
    }

    private function fakeFcm(array $invalidTokens = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function (Request $request) use ($invalidTokens) {
                return in_array($request['message']['token'], $invalidTokens, true)
                    ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                    : Http::response(['name' => 'projects/uec-test/messages/1']);
            },
        ]);
    }

    public function test_whatsapp_otp_uses_the_approved_authentication_template(): void
    {
        config(['services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_number_id' => '1065', 'services.whatsapp.otp_template' => 'uec_otp']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        (new DeliverOtp('phone', '+2348123456789', '482913', 'register'))->handle(app(WhatsAppClient::class));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/1065/messages')
            && $r['to'] === '2348123456789'
            && $r['template']['name'] === 'uec_otp'
            && $r['template']['components'][0]['parameters'][0]['text'] === '482913'
            && $r['template']['components'][1]['sub_type'] === 'url'
            && $r->hasHeader('Authorization', 'Bearer wa-token'));
        $log = NotificationLog::query()->sole();
        $this->assertSame(['whatsapp', 'otp.register', 'sent', '+234 ••• ••• 6789'], [$log->channel, $log->type, $log->status, $log->destination]);
    }

    public function test_email_otp_is_sent_through_the_mailer(): void
    {
        Mail::fake();
        Notification::fake();

        (new DeliverOtp('email', 'praise@example.com', '123456', 'reset'))->handle(app(WhatsAppClient::class));

        Notification::assertSentOnDemand(OtpCodeNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'praise@example.com' && $n->code === '123456');
    }

    public function test_zeptomail_transport_posts_to_the_http_api(): void
    {
        config(['mail.default' => 'zeptomail', 'services.zeptomail.token' => 'zepto-key', 'services.zeptomail.host' => 'api.zeptomail.com', 'mail.from.address' => 'no-reply@uec.org.ng']);
        Http::fake(['api.zeptomail.com/v1.1/email' => Http::response(['request_id' => 'req-1', 'message' => 'OK'], 201)]);

        Mail::raw('Hello from UEC', fn ($m) => $m->to('member@example.com', 'Member')->subject('Test subject'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.zeptomail.com/v1.1/email'
            && $r->hasHeader('Authorization', 'Zoho-enczapikey zepto-key')
            && $r['to'][0]['email_address']['address'] === 'member@example.com'
            && $r['subject'] === 'Test subject'
            && $r['from']['address'] === 'no-reply@uec.org.ng');
        $this->assertDatabaseHas('integration_logs', ['integration' => 'zeptomail', 'success' => true]);
    }

    public function test_cloudinary_signs_uploads_and_builds_optimised_urls(): void
    {
        config(['services.cloudinary.cloud_name' => 'uec', 'services.cloudinary.api_key' => 'key', 'services.cloudinary.api_secret' => 'secret']);
        Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'uec/avatars/u1', 'secure_url' => 'https://res.cloudinary.com/uec/image/upload/v1/uec/avatars/u1.jpg', 'width' => 512, 'height' => 512, 'bytes' => 1000])]);
        $file = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($file, 'fake');

        $result = app(CloudinaryClient::class)->uploadImage($file, 'avatars', 'u1', 'c_fill,g_auto,w_512,h_512');

        $this->assertSame('uec/avatars/u1', $result['public_id']);
        Http::assertSent(function (Request $r) {
            $fields = collect($r->data())->pluck('contents', 'name');
            $params = ['folder' => 'uec/avatars', 'invalidate' => 'true', 'overwrite' => 'true', 'public_id' => 'u1', 'timestamp' => $fields['timestamp'], 'transformation' => 'c_fill,g_auto,w_512,h_512'];
            ksort($params);
            $expected = sha1(urldecode(http_build_query($params)).'secret');

            return $fields['signature'] === $expected && $fields['api_key'] === 'key';
        });
        $this->assertSame('https://res.cloudinary.com/uec/image/upload/f_auto,q_auto/uec/avatars/u1', app(CloudinaryClient::class)->deliveryUrl('uec/avatars/u1'));
        @unlink($file);
    }

    public function test_push_campaign_segments_by_platform_counts_delivery_and_prunes_invalid_tokens(): void
    {
        $this->configureFirebase();
        $this->fakeFcm(['android-dead']);
        $android = User::factory()->create();
        $ios = User::factory()->create();
        Device::query()->create(['user_id' => $android->id, 'token' => 'android-ok', 'platform' => 'android']);
        Device::query()->create(['user_id' => $android->id, 'token' => 'android-dead', 'platform' => 'android']);
        Device::query()->create(['user_id' => $ios->id, 'token' => 'ios-ok', 'platform' => 'ios']);

        $campaign = PushCampaign::query()->create([
            'title' => 'New event', 'body' => 'National Youth Convention', 'route' => '/events', 'type' => 'announcement',
            'audience' => 'platform', 'filters' => ['platform' => 'android'], 'status' => PushCampaignStatus::Draft,
        ]);
        $service = app(PushCampaignService::class);
        $this->assertSame(2, $service->estimate($campaign));

        $service->dispatch($campaign); // sync queue in tests

        $campaign->refresh();
        $this->assertSame(PushCampaignStatus::Sent, $campaign->status);
        $this->assertSame([2, 1, 1, 1], [$campaign->target_count, $campaign->success_count, $campaign->failure_count, $campaign->invalid_token_count]);
        $this->assertNull(Device::query()->where('token', 'android-dead')->first());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r['message']['data']['route'] === '/events'
            && $r['message']['notification']['title'] === 'New event'
            && $r->hasHeader('Authorization', 'Bearer ya29.test'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com') && ($r['message']['token'] ?? null) === 'ios-ok');
    }

    public function test_push_routes_are_restricted_to_in_app_paths(): void
    {
        $this->assertTrue(PushCampaignService::isAllowedRoute('/devotional/read/2026-01-05'));
        $this->assertTrue(PushCampaignService::isAllowedRoute(null));
        $this->assertFalse(PushCampaignService::isAllowedRoute('https://evil.example'));
        $this->assertFalse(PushCampaignService::isAllowedRoute('/admin'));
    }

    public function test_daily_devotional_reminder_fires_once_when_enabled(): void
    {
        $this->configureFirebase();
        $this->fakeFcm();
        $user = User::factory()->create();
        Device::query()->create(['user_id' => $user->id, 'token' => 't1', 'platform' => 'ios']);
        $edition = DevotionalEdition::factory()->create();
        DevotionalEntry::factory()->create(['edition_year' => $edition->year, 'title' => 'Strength in Weakness']);

        $this->travelTo(now('Africa/Lagos')->setTime(6, 0));
        $this->assertSame([], app(ScheduledReminders::class)->run(), 'Disabled by default');

        app(AppSettings::class)->put(['push_dde_daily_enabled' => true, 'push_dde_daily_time' => '06:00']);
        $this->assertCount(1, app(ScheduledReminders::class)->run());
        $this->assertCount(0, app(ScheduledReminders::class)->run(), 'Only once per day');

        $campaign = PushCampaign::query()->sole();
        $this->assertSame('/devotional/read/'.now('Africa/Lagos')->toDateString(), $campaign->route);
        $this->assertSame(1, $campaign->success_count);
    }

    public function test_subscription_notifications_route_to_mail_and_push_and_are_logged(): void
    {
        $this->configureFirebase();
        $this->fakeFcm();
        Mail::fake();
        $user = User::factory()->create(['email' => 'member@example.com']);
        Device::query()->create(['user_id' => $user->id, 'token' => 't1', 'platform' => 'android']);
        $subscription = Subscription::factory()->create(['user_id' => $user->id]);

        $notice = new SubscriptionNotification($subscription, 'renewal_failed');
        $this->assertSame(['mail', FcmChannel::class], $notice->via($user));
        $user->notifyNow($notice);

        $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'channel' => 'mail', 'type' => 'subscription_renewal_failed', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'channel' => 'fcm', 'status' => 'sent']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com') && $r['message']['data']['route'] === '/subscription');

        // Receipts never push; WhatsApp only when a template is configured.
        $receipt = new PaymentReceiptNotification(Payment::factory()->create(['user_id' => $user->id]));
        $this->assertSame(['mail'], $receipt->via($user));
        config(['services.whatsapp.templates' => ['payment_receipt' => 'uec_receipt']]);
        $this->assertContains(WhatsAppChannel::class, $receipt->via($user));
    }

    public function test_fcm_client_classifies_results(): void
    {
        $this->configureFirebase();
        $this->fakeFcm(['dead']);

        $results = app(FcmClient::class)->sendMany(['ok', 'dead'], 'T', 'B', ['route' => '/hymns']);

        $this->assertSame(['ok' => FcmClient::RESULT_OK, 'dead' => FcmClient::RESULT_INVALID_TOKEN], $results);
    }

    public function test_bible_import_command_accepts_canonical_json(): void
    {
        $books = array_fill(0, 66, ['chapters' => [['Verse one.']]]);
        $books[0] = ['chapters' => [['In the beginning God created the heaven and the earth.', 'And the earth was without form, and void;']]];
        $file = tempnam(sys_get_temp_dir(), 'kjv').'.json';
        file_put_contents($file, json_encode($books));

        $this->artisan('uec:bible:import', ['version' => 'kjv', 'file' => $file])->assertSuccessful();

        $this->getJson('/api/v1/bible/KJV/chapters/GEN/1')->assertOk()
            ->assertJsonCount(2, 'data.verses')
            ->assertJsonPath('data.verses.0.text', 'In the beginning God created the heaven and the earth.');
        $this->getJson('/api/v1/bible/KJV/chapters/REV/1')->assertOk();
        @unlink($file);
    }

    public function test_admin_create_command_enforces_a_strong_password(): void
    {
        $this->artisan('uec:admin:create', ['--email' => 'admin@uec.org.ng', '--name' => 'Admin', '--password' => 'weak'])->assertFailed();
        $this->artisan('uec:admin:create', ['--email' => 'admin@uec.org.ng', '--name' => 'Admin', '--password' => 'Str0ng!Passw0rd'])->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'admin@uec.org.ng')->sole()->hasRole('super_admin'));
    }
}
