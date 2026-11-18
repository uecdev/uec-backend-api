<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Livewire\Admin\Content\Devotionals\Entries;
use App\Livewire\Admin\Content\Devotionals\EntryForm;
use App\Livewire\Admin\Content\Hymns\Form as HymnForm;
use App\Livewire\Admin\Engagement\MobileApp;
use App\Livewire\Admin\Engagement\Notifications;
use App\Livewire\Admin\Engagement\Reports;
use App\Livewire\Admin\Finance\Payments;
use App\Livewire\Admin\Finance\Plans;
use App\Livewire\Admin\Finance\Subscriptions;
use App\Livewire\Admin\System\Admins;
use App\Livewire\Admin\Users\Show;
use App\Models\Device;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\Hymn;
use App\Models\HymnCategory;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PushCampaign;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\ReportBuilder;
use App\Services\Settings\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWorkflowsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole('super_admin')->create();
        $this->actingAs($this->admin);
    }

    public function test_create_hymn_with_stanzas_categories_and_text_solfa(): void
    {
        $praise = HymnCategory::query()->where('slug', 'praise')->value('id');

        Livewire::test(HymnForm::class)
            ->set('number', 23)->set('title', 'Glory Be to God the Father')->set('first_line', 'Glory be to God the Father,')
            ->set('categoryIds', [$praise])->set('published', true)
            ->set('stanzas', [['type' => 'verse', 'number' => 1, 'lines' => "Glory be to God the Father,\nGlory be to God the Son,"], ['type' => 'chorus', 'number' => null, 'lines' => 'Glory, glory']])
            ->set('solfaFormat', 'text')->set('solfaSections', [['label' => 'Soprano', 'lines' => 'd : d | m : s']])
            ->set('scripture_refs', 'Revelation 5:13; Psalm 150')
            ->call('save')->assertHasNoErrors();

        $hymn = Hymn::query()->with(['stanzas', 'solfa', 'categories'])->where('number', 23)->sole();
        $this->assertTrue($hymn->isPublished());
        $this->assertSame(['Glory be to God the Father,', 'Glory be to God the Son,'], $hymn->stanzas[0]->lines);
        $this->assertNull($hymn->stanzas[1]->number);
        $this->assertSame(['Revelation 5:13', 'Psalm 150'], $hymn->scripture_refs);
        $this->assertSame('Soprano', $hymn->solfa->sections[0]['label']);
        $this->assertSame(['praise'], $hymn->categories->pluck('slug')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'hymn.created', 'actor_id' => $this->admin->id]);

        // The API reflects it immediately (catalogue cache invalidated on save).
        $this->getJson('/api/v1/hymns')->assertJsonPath('data.hymns.0.number', 23);

        // Duplicate numbers are rejected.
        Livewire::test(HymnForm::class)->set('number', 23)->set('title', 'X')->set('first_line', 'X')
            ->set('stanzas', [['type' => 'verse', 'number' => 1, 'lines' => 'x']])->call('save')->assertHasErrors('number');
    }

    public function test_upload_hymn_audio_to_private_storage(): void
    {
        Storage::fake('local');
        $hymn = Hymn::factory()->create();

        Livewire::test(HymnForm::class, ['hymn' => $hymn->id])
            ->set('newAudio', UploadedFile::fake()->create('vocal.mp3', 500, 'audio/mpeg'))
            ->set('newAudioLabel', 'Vocal')->set('newAudioDuration', 134)
            ->call('uploadAudio')->assertHasNoErrors();

        $audio = $hymn->audio()->sole();
        Storage::disk('local')->assertExists($audio->storage_path);
        $this->assertSame(134, $audio->duration_seconds);

        Livewire::test(HymnForm::class, ['hymn' => $hymn->id])->call('deleteAudio', $audio->id);
        Storage::disk('local')->assertMissing($audio->storage_path);
    }

    public function test_devotional_entry_editor_and_csv_import(): void
    {
        $edition = DevotionalEdition::factory()->create(['year' => 2026]);

        Livewire::test(EntryForm::class, ['year' => 2026])
            ->set('date', '2026-01-01')->set('title', 'Keys to Good Success')->set('passage', 'Joshua 1:1-9')
            ->set('body', "Paragraph one.\n\n**Bold** and [v.1](bible:Joshua 1:1).")
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('devotional_entries', ['date' => '2026-01-01', 'title' => 'Keys to Good Success']);

        Livewire::test(EntryForm::class, ['year' => 2026])
            ->set('date', '2025-12-31')->set('title', 'Wrong year')->set('passage', 'x')->set('body', 'x')
            ->call('save')->assertHasErrors('date');

        $csv = "date,title,passage,body,memory_verse_ref\n2026-01-02,Day two,Psalm 23,Body two,Psalm 23:1\n2026-01-01,Updated day one,Joshua 1,Body one,\n";
        Livewire::test(Entries::class, ['year' => 2026])
            ->set('importFile', UploadedFile::fake()->createWithContent('entries.csv', $csv))
            ->call('import')->assertSet('importErrors', []);
        $this->assertSame(2, DevotionalEntry::query()->where('edition_year', 2026)->count());
        $this->assertSame('Updated day one', DevotionalEntry::query()->whereDate('date', '2026-01-01')->value('title'));

        $bad = "date,title,passage,body\n2025-05-05,Bad,x,y\n";
        Livewire::test(Entries::class, ['year' => 2026])
            ->set('importFile', UploadedFile::fake()->createWithContent('bad.csv', $bad))
            ->call('import')->assertNotSet('importErrors', []);
        $this->assertTrue($edition->exists);
    }

    public function test_suspend_and_reactivate_member_revokes_sessions(): void
    {
        $member = $this->member();
        $member->createToken('app');

        Livewire::test(Show::class, ['user' => $member->id])->set('suspensionReason', 'Abusive feedback')->call('suspend')->assertHasNoErrors();
        $member->refresh();
        $this->assertNotNull($member->suspended_at);
        $this->assertSame(0, $member->tokens()->count());

        $this->postJson('/api/v1/auth/login', ['channel' => 'email', 'identifier' => $member->email, 'password' => 'Password123'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        Livewire::test(Show::class, ['user' => $member->id])->call('reactivate');
        $this->assertNull($member->fresh()->suspended_at);
    }

    public function test_mobile_app_settings_feed_the_remote_config(): void
    {
        $this->configurePaystack();

        Livewire::test(MobileApp::class)
            ->set('donation_url', 'https://uec.org.ng/give')->set('latest_version', '1.2.0')->set('min_supported_version', '1.0.0')
            ->set('enabledProviders', ['paystack'])->set('support_email', 'support@uec.org.ng')
            ->call('save')->assertHasNoErrors();

        $this->getJson('/api/v1/app/config')->assertJsonPath('data.donation_url', 'https://uec.org.ng/give')
            ->assertJsonPath('data.latest_version', '1.2.0')->assertJsonPath('data.payment_providers', ['paystack']);

        Livewire::test(MobileApp::class)->set('min_supported_version', '2.0.0')->set('latest_version', '1.2.0')->call('save')
            ->assertHasErrors('min_supported_version');
        Livewire::test(MobileApp::class)->set('donation_url', 'http://insecure.example')->call('save')->assertHasErrors('donation_url');
        $this->assertSame('https://uec.org.ng/give', app(AppSettings::class)->get('donation_url'));
    }

    public function test_schedule_and_cancel_push_campaign(): void
    {
        config(['services.firebase.project_id' => 'p', 'services.firebase.credentials_base64' => base64_encode(json_encode(['client_email' => 'x@y', 'private_key' => 'k']))]);
        Device::query()->create(['user_id' => $this->member()->id, 'token' => 't1', 'platform' => 'ios']);

        Livewire::test(Notifications::class)->call('create')
            ->set('title', 'Convention')->set('body', 'Starts Friday')->set('route', 'https://evil.example')
            ->call('send')->assertHasErrors('route');

        Livewire::test(Notifications::class)->call('create')
            ->set('title', 'Convention')->set('body', 'Starts Friday')->set('route', '/events')
            ->set('audience', 'platform')->set('platform', 'ios')
            ->set('sendMode', 'later')->set('scheduledAt', now('Africa/Lagos')->addDay()->format('Y-m-d\TH:i'))
            ->call('send')->assertHasNoErrors();

        $campaign = PushCampaign::query()->sole();
        $this->assertSame('scheduled', $campaign->status->value);
        $this->assertSame(1, $campaign->target_count);
        $this->assertSame(['platform' => 'ios'], $campaign->filters);

        Livewire::test(Notifications::class)->call('cancel', $campaign->id);
        $this->assertSame('cancelled', $campaign->fresh()->status->value);
    }

    public function test_finance_reverify_refund_and_cancel(): void
    {
        Notification::fake();
        $this->configurePaystack();
        $payment = Payment::factory()->create(['amount_kobo' => 250000, 'plan_id' => 'plan_monthly', 'created_at' => now()->subHour()]);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'id' => 1, 'status' => 'success', 'reference' => $payment->reference, 'amount' => 250000, 'currency' => 'NGN',
            'authorization' => ['authorization_code' => 'AUTH_1', 'reusable' => true, 'last4' => '1111', 'brand' => 'visa'],
            'customer' => ['customer_code' => 'CUS_1'],
        ]]), 'api.paystack.co/subscription/disable' => Http::response(['status' => true])]);

        Livewire::test(Payments::class)->call('reverifyStale');
        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $subscription = Subscription::query()->sole();

        Livewire::test(Subscriptions::class)->call('cancel', $subscription->id);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);

        Livewire::test(Payments::class)->call('markRefunded', $payment->id);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
    }

    public function test_plan_price_change_clears_paystack_code_and_sync_creates_a_new_one(): void
    {
        $this->configurePaystack();
        Http::fake(['api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_new']])]);
        Plan::query()->whereKey('plan_monthly')->update(['paystack_plan_code' => 'PLN_old']);

        Livewire::test(Plans::class)->call('edit', 'plan_monthly')->set('amount', '3000.00')->call('save')->assertHasNoErrors();
        $plan = Plan::query()->find('plan_monthly');
        $this->assertSame(300000, $plan->amount_kobo);
        $this->assertNull($plan->paystack_plan_code);

        Livewire::test(Plans::class)->call('syncPaystack', 'plan_monthly');
        $this->assertSame('PLN_new', $plan->fresh()->paystack_plan_code);
        Http::assertSent(fn ($r) => $r['amount'] === 300000 && $r['interval'] === 'monthly');
        $this->getJson('/api/v1/subscription/plans')->assertJsonPath('data.0.amount', 300000);
    }

    public function test_add_admin_with_one_time_password_and_cannot_remove_last_super_admin(): void
    {
        $component = Livewire::test(Admins::class)->call('create')
            ->set('name', 'Content Lead')->set('email', 'Editor@UEC.org.ng')->set('role', 'content_editor')
            ->call('save')->assertHasNoErrors();

        $password = $component->get('generatedPassword');
        $this->assertNotEmpty($password);
        $editor = User::query()->where('email', 'editor@uec.org.ng')->sole();
        $this->assertTrue($editor->hasRole('content_editor'));
        $this->assertTrue(Hash::check($password, $editor->password));

        Livewire::test(Admins::class)->call('revoke', $this->admin->id)->assertForbidden();
    }

    public function test_reports_render_and_export(): void
    {
        $this->member();
        Livewire::test(Reports::class)->set('report', 'registrations')->assertSee('Registrations by day')->call('export')->assertFileDownloaded();
        foreach (array_keys(ReportBuilder::REPORTS) as $report) {
            Livewire::test(Reports::class)->set('report', $report)->assertOk();
        }
    }
}
