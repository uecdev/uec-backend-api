<?php

namespace Tests\Feature\Api;

use App\Models\BibleVersion;
use App\Models\Branch;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\Event;
use App\Models\Hymn;
use App\Models\HymnAudio;
use App\Models\HymnCategory;
use App\Models\HymnSolfa;
use App\Models\Page;
use App\Models\SsLesson;
use App\Models\SsManual;
use App\Models\SsPlanWeek;
use App\Models\Subscription;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentTest extends TestCase
{
    private function hymnWithMedia(): Hymn
    {
        $hymn = Hymn::factory()->create(['number' => 23, 'title' => 'Glory Be to God the Father', 'first_line' => 'Glory be to God the Father,']);
        $hymn->categories()->attach(HymnCategory::query()->where('slug', 'praise')->value('id'));
        $hymn->stanzas()->create(['position' => 1, 'type' => 'verse', 'number' => 1, 'lines' => ['Glory be to God the Father,', 'Glory be to God the Son,']]);
        $hymn->stanzas()->create(['position' => 2, 'type' => 'chorus', 'number' => null, 'lines' => ['Glory, glory, glory, glory,']]);
        HymnSolfa::query()->create(['hymn_id' => $hymn->id, 'format' => 'text', 'key' => 'E♭', 'time_signature' => '4/4', 'sections' => [['label' => 'Soprano', 'lines' => ['d : d | m : s']]]]);
        HymnAudio::query()->create(['hymn_id' => $hymn->id, 'label' => 'Vocal', 'storage_path' => 'hymns/h23-vocal.mp3', 'duration_seconds' => 134, 'size_bytes' => 2150000]);

        return $hymn;
    }

    public function test_app_config_lists_only_configured_providers_in_preference_order(): void
    {
        app(AppSettings::class)->put(['donation_url' => 'https://uec.org.ng/give', 'payment_providers' => ['monnify', 'paystack']]);
        $this->configurePaystack();

        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.donation_url', 'https://uec.org.ng/give')
            ->assertJsonPath('data.payment_providers', ['paystack']) // monnify has no credentials
            ->assertJsonStructure(['data' => ['min_supported_version', 'latest_version', 'store_urls' => ['android', 'ios'], 'support_email']]);

        $this->configureMonnify();
        $this->getJson('/api/v1/app/config')->assertJsonPath('data.payment_providers', ['monnify', 'paystack']);
    }

    public function test_pages(): void
    {
        Page::query()->create(['slug' => 'doctrine', 'title' => 'UEC Doctrine', 'body' => 'We believe…', 'published_at' => now()->subDay()]);
        Page::query()->create(['slug' => 'anthem', 'title' => 'Anthem', 'body' => 'Draft', 'published_at' => null]);

        $this->getJson('/api/v1/pages/doctrine')->assertOk()
            ->assertJsonPath('data.slug', 'doctrine')->assertJsonPath('data.title', 'UEC Doctrine')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->getJson('/api/v1/pages/anthem')->assertNotFound(); // unpublished
        $this->getJson('/api/v1/pages/unknown')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_hymn_catalogue_supports_etag(): void
    {
        $this->hymnWithMedia();
        Hymn::factory()->unpublished()->create();

        $res = $this->getJson('/api/v1/hymns')->assertOk()
            ->assertJsonCount(1, 'data.hymns')
            ->assertJsonPath('data.hymns.0.number', 23)
            ->assertJsonPath('data.hymns.0.categories', ['praise'])
            ->assertJsonPath('data.hymns.0.has_audio', true)
            ->assertJsonPath('data.hymns.0.has_solfa', true)
            ->assertJsonStructure(['data' => ['version', 'categories' => [['slug', 'name']]]]);

        $etag = $res->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get('/api/v1/hymns', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_hymn_detail_enforces_audio_and_solfa_entitlements(): void
    {
        Storage::fake('local');
        $hymn = $this->hymnWithMedia();

        // Guest: lyrics yes, audio/solfa withheld, flags still true for the paywall.
        $this->getJson("/api/v1/hymns/{$hymn->id}")->assertOk()
            ->assertJsonPath('data.stanzas.0.type', 'verse')
            ->assertJsonPath('data.stanzas.1.number', null)
            ->assertJsonPath('data.audio', [])
            ->assertJsonPath('data.solfa', null)
            ->assertJsonPath('data.has_audio', true);

        $premium = $this->member();
        Subscription::factory()->create(['user_id' => $premium->id]);

        $this->getJson("/api/v1/hymns/{$hymn->id}", $this->tokenFor($premium))->assertOk()
            ->assertJsonPath('data.solfa.format', 'text')
            ->assertJsonPath('data.solfa.sections.0.label', 'Soprano')
            ->assertJsonPath('data.audio.0.label', 'Vocal')
            ->assertJsonPath('data.audio.0.duration_seconds', 134);
        $this->assertIsString($this->getJson("/api/v1/hymns/{$hymn->id}", $this->tokenFor($premium))->json('data.audio.0.url'));

        $this->getJson('/api/v1/hymns/does-not-exist')->assertNotFound();
    }

    public function test_devotional_editions_entries_and_future_visibility(): void
    {
        $tz = config('uec.timezone');
        $edition = DevotionalEdition::factory()->create();
        $today = now($tz)->toDateString();
        $tomorrow = now($tz)->addDay()->toDateString();
        DevotionalEntry::factory()->create(['edition_year' => $edition->year, 'date' => $today, 'title' => 'Today']);
        DevotionalEntry::factory()->create(['edition_year' => $edition->year, 'date' => $tomorrow, 'title' => 'Tomorrow']);

        $this->getJson('/api/v1/devotionals/editions')->assertOk()
            ->assertJsonPath('data.0.year', $edition->year)
            ->assertJsonPath('data.0.entry_count', 2)
            ->assertJsonPath('data.0.is_premium', false);

        $this->getJson("/api/v1/devotionals/editions/{$edition->year}/entries")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.date', $today);

        $this->getJson("/api/v1/devotionals/entries/{$today}")->assertOk()
            ->assertJsonPath('data.title', 'Today')
            ->assertJsonPath('data.edition_year', $edition->year)
            ->assertJsonStructure(['data' => ['memory_verse' => ['text', 'reference'], 'body', 'declaration', 'prayer']]);
        $this->getJson("/api/v1/devotionals/entries/{$tomorrow}")->assertNotFound();
        $this->getJson('/api/v1/devotionals/entries/1999-01-01')->assertNotFound();

        config(['uec.content.devotional_future_visibility' => true]);
        $this->getJson("/api/v1/devotionals/entries/{$tomorrow}")->assertOk();
    }

    public function test_offline_downloads_require_entitlement(): void
    {
        $edition = DevotionalEdition::factory()->create();
        DevotionalEntry::factory()->create(['edition_year' => $edition->year]);
        $manual = SsManual::factory()->create();
        SsLesson::factory()->create(['manual_year' => $manual->year, 'number' => 1]);

        $free = $this->member();
        $this->getJson("/api/v1/devotionals/editions/{$edition->year}/download")->assertUnauthorized();
        $this->getJson("/api/v1/devotionals/editions/{$edition->year}/download", $this->tokenFor($free))
            ->assertForbidden()->assertJsonPath('code', 'PREMIUM_REQUIRED');
        $this->getJson("/api/v1/sunday-school/manuals/{$manual->year}/download", $this->tokenFor($free))
            ->assertForbidden()->assertJsonPath('code', 'PREMIUM_REQUIRED');

        $premium = $this->member();
        Subscription::factory()->create(['user_id' => $premium->id]);
        $this->getJson("/api/v1/devotionals/editions/{$edition->year}/download", $this->tokenFor($premium))->assertOk()
            ->assertJsonPath('data.edition.year', $edition->year)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonStructure(['data' => ['entries' => [['date', 'body', 'memory_verse']]]]);
        $this->getJson("/api/v1/sunday-school/manuals/{$manual->year}/download", $this->tokenFor($premium))->assertOk()
            ->assertJsonCount(1, 'data.lessons')->assertJsonStructure(['data' => ['manual', 'lessons', 'plan']]);
    }

    public function test_premium_archive_editions_are_gated(): void
    {
        $archive = DevotionalEdition::factory()->create(['year' => 2020, 'is_premium' => true]);
        DevotionalEntry::factory()->create(['edition_year' => 2020, 'date' => '2020-03-01']);

        $this->getJson('/api/v1/devotionals/editions/2020/entries')->assertForbidden()->assertJsonPath('code', 'PREMIUM_REQUIRED');

        $premium = $this->member();
        Subscription::factory()->create(['user_id' => $premium->id]);
        $this->getJson('/api/v1/devotionals/editions/2020/entries', $this->tokenFor($premium))->assertOk()->assertJsonCount(1, 'data');
        $this->assertTrue($archive->is_premium);
    }

    public function test_sunday_school_manual_lessons_plan_and_search(): void
    {
        $manual = SsManual::factory()->create();
        $lesson = SsLesson::factory()->create(['manual_year' => $manual->year, 'number' => 1, 'title' => 'Exceeding Great Joy']);
        SsLesson::factory()->create(['manual_year' => $manual->year, 'number' => 4, 'title' => 'Faith in trials']);
        SsPlanWeek::query()->create(['manual_year' => $manual->year, 'date' => "{$manual->year}-01-04", 'week' => 1, 'lesson_id' => null, 'description' => 'Rededication of Teachers']);
        SsPlanWeek::query()->create(['manual_year' => $manual->year, 'date' => "{$manual->year}-01-11", 'week' => 2, 'lesson_id' => $lesson->id, 'description' => 'Lesson 1: Topic to Outline 1']);

        $this->getJson('/api/v1/sunday-school/manuals')->assertOk()->assertJsonPath('data.0.lesson_count', 2);

        $this->getJson("/api/v1/sunday-school/manuals/{$manual->year}")->assertOk()
            ->assertJsonCount(2, 'data.lessons')
            ->assertJsonPath('data.plan.0.lesson_id', null)
            ->assertJsonPath('data.plan.1.lesson_id', $lesson->id)
            ->assertJsonPath('data.plan.1.lesson_number', 1);

        $this->getJson("/api/v1/sunday-school/lessons/{$lesson->id}")->assertOk()
            ->assertJsonPath('data.number', 1)
            ->assertJsonStructure(['data' => ['memory_verse', 'aim', 'introduction', 'outline' => [['heading', 'body']], 'applications', 'conclusion', 'revision_questions']]);

        $this->getJson('/api/v1/sunday-school/search?q=joy')->assertOk()->assertJsonPath('data.0.id', $lesson->id);
        $this->getJson('/api/v1/sunday-school/search?q=lesson 4')->assertOk()->assertJsonPath('data.0.number', 4);
        $this->getJson('/api/v1/sunday-school/search')->assertUnprocessable();
    }

    public function test_bible_versions_and_chapters(): void
    {
        BibleVersion::query()->create(['id' => 'KJV', 'name' => 'King James Version', 'abbreviation' => 'KJV']);
        DB::table('bible_verses')->insert([
            ['version_id' => 'KJV', 'book_id' => 'JOS', 'chapter' => 1, 'verse' => 2, 'text' => 'Moses my servant is dead;'],
            ['version_id' => 'KJV', 'book_id' => 'JOS', 'chapter' => 1, 'verse' => 1, 'text' => 'Now after the death of Moses…'],
        ]);

        $this->getJson('/api/v1/bible/versions')->assertOk()->assertJsonPath('data.0.id', 'KJV');
        $this->getJson('/api/v1/bible/KJV/chapters/JOS/1')->assertOk()
            ->assertJsonPath('data.book_id', 'JOS')
            ->assertJsonPath('data.verses.0.number', 1)
            ->assertJsonPath('data.verses.1.number', 2)
            ->assertHeader('Cache-Control', 'max-age=604800, public');

        $this->getJson('/api/v1/bible/KJV/chapters/JOS/99')->assertNotFound(); // invalid chapter
        $this->getJson('/api/v1/bible/KJV/chapters/XYZ/1')->assertNotFound(); // invalid book
        $this->getJson('/api/v1/bible/NIV/chapters/JOS/1')->assertNotFound(); // unknown version
    }

    public function test_events_and_branches(): void
    {
        $year = now()->year;
        $event = Event::factory()->create(['title' => 'National Youth Convention', 'start_date' => "{$year}-03-15", 'start_time' => null]);
        Event::factory()->create(['start_date' => ($year - 1).'-12-30']);
        Event::factory()->create(['published_at' => null, 'start_date' => "{$year}-04-01"]);

        $this->getJson("/api/v1/events?year={$year}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.start_time', null)
            ->assertJsonPath('data.0.start_date', "{$year}-03-15");
        $this->getJson("/api/v1/events/{$event->id}")->assertOk()->assertJsonPath('data.title', 'National Youth Convention');

        Branch::factory()->create(['name' => 'UEC Port Harcourt Central', 'city' => 'Port Harcourt']);
        Branch::factory()->count(4)->create(['state' => 'Akwa Ibom']);
        Branch::factory()->create(['name' => 'Inactive branch', 'is_active' => false]);

        $this->getJson('/api/v1/branches?q=port%20harcourt')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => [['id', 'name', 'address', 'city', 'state', 'phone', 'latitude', 'longitude']], 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->getJson('/api/v1/branches?per_page=2&page=2')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 5);
    }

    public function test_global_search_groups_results(): void
    {
        $this->hymnWithMedia();
        $edition = DevotionalEdition::factory()->create();
        DevotionalEntry::factory()->create(['edition_year' => $edition->year, 'title' => 'Glory in the morning']);
        Event::factory()->create(['title' => 'Glory Night', 'start_date' => now()->toDateString()]);

        $res = $this->getJson('/api/v1/search?q=glory')->assertOk()
            ->assertJsonStructure(['data' => ['hymns', 'devotionals', 'lessons', 'events']]);
        $this->assertCount(1, $res->json('data.hymns'));
        $this->assertCount(1, $res->json('data.devotionals'));
        $this->assertCount(1, $res->json('data.events'));

        $this->getJson('/api/v1/search?q=23')->assertJsonPath('data.hymns.0.number', 23);
    }

    public function test_plans_are_returned_in_kobo(): void
    {
        $this->getJson('/api/v1/subscription/plans')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', 'plan_monthly')
            ->assertJsonPath('data.0.amount', 250000)
            ->assertJsonPath('data.2.amount', 1990000)
            ->assertJsonPath('data.2.savings_percent', 34)
            ->assertJsonPath('data.2.highlighted', true)
            ->assertJsonPath('data.2.currency', 'NGN');
    }

    public function test_responses_carry_security_headers_and_cors_for_native_apps(): void
    {
        $this->getJson('/api/v1/subscription/plans', ['Origin' => 'capacitor://localhost'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Access-Control-Allow-Origin', 'capacitor://localhost');

        $res = $this->getJson('/api/v1/subscription/plans', ['Origin' => 'https://evil.example']);
        $this->assertNull($res->headers->get('Access-Control-Allow-Origin'));
    }
}
