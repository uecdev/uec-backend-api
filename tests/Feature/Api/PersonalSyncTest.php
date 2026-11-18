<?php

namespace Tests\Feature\Api;

use App\Models\Hymn;
use App\Models\Program;
use App\Models\SsLesson;
use App\Models\SsManual;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalSyncTest extends TestCase
{
    public function test_favourites_are_full_replacement_and_drop_unknown_ids(): void
    {
        $user = $this->member();
        $auth = $this->tokenFor($user);
        [$h1, $h2] = Hymn::factory()->count(2)->create();
        SsManual::factory()->create();
        $lesson = SsLesson::factory()->create();

        $this->getJson('/api/v1/me/favourites', $auth)->assertOk()->assertExactJson(['data' => ['hymn_ids' => [], 'lesson_ids' => []]]);

        $this->putJson('/api/v1/me/favourites', ['hymn_ids' => [$h2->id, $h1->id, 'deleted-hymn'], 'lesson_ids' => [$lesson->id]], $auth)
            ->assertNoContent();
        $this->getJson('/api/v1/me/favourites', $auth)
            ->assertJsonPath('data.hymn_ids', [$h2->id, $h1->id])
            ->assertJsonPath('data.lesson_ids', [$lesson->id]);

        // Idempotent replay and full replacement.
        $this->putJson('/api/v1/me/favourites', ['hymn_ids' => [$h1->id], 'lesson_ids' => []], $auth)->assertNoContent();
        $this->putJson('/api/v1/me/favourites', ['hymn_ids' => [$h1->id], 'lesson_ids' => []], $auth)->assertNoContent();
        $this->getJson('/api/v1/me/favourites', $auth)->assertJsonPath('data.hymn_ids', [$h1->id])->assertJsonPath('data.lesson_ids', []);

        $this->putJson('/api/v1/me/favourites', ['hymn_ids' => array_fill(0, 2001, 'x'), 'lesson_ids' => []], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['hymn_ids']);
    }

    public function test_programs_upsert_with_last_write_wins_and_user_scoping(): void
    {
        $user = $this->member();
        $auth = $this->tokenFor($user);
        [$h1, $h2] = Hymn::factory()->count(2)->create();
        $id = (string) Str::uuid();

        $this->putJson("/api/v1/me/programs/{$id}", [
            'id' => $id, 'name' => 'Sunday Morning Service', 'date' => '2026-08-02', 'hymn_ids' => [$h2->id, $h1->id], 'updated_at' => '2026-08-01T10:00:00Z',
        ], $auth)->assertCreated();

        $this->getJson('/api/v1/me/programs', $auth)->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.hymn_ids', [$h2->id, $h1->id])
            ->assertJsonPath('data.0.date', '2026-08-02')
            ->assertJsonPath('data.0.updated_at', '2026-08-01T10:00:00Z');

        // Newer write → 204 and applied.
        $this->putJson("/api/v1/me/programs/{$id}", ['name' => 'Renamed', 'date' => null, 'hymn_ids' => [$h1->id], 'updated_at' => '2026-08-01T11:00:00Z'], $auth)
            ->assertNoContent();
        // Stale write → 204 but ignored.
        $this->putJson("/api/v1/me/programs/{$id}", ['name' => 'Stale', 'date' => null, 'hymn_ids' => [], 'updated_at' => '2026-08-01T09:00:00Z'], $auth)
            ->assertNoContent();
        $this->assertSame('Renamed', Program::query()->find($id)->name);

        // Another user can never touch this program.
        $intruder = $this->tokenFor($this->member());
        $this->putJson("/api/v1/me/programs/{$id}", ['name' => 'Hijack', 'hymn_ids' => [], 'updated_at' => '2030-01-01T00:00:00Z'], $intruder)->assertNotFound();
        $this->deleteJson("/api/v1/me/programs/{$id}", [], $intruder)->assertNoContent();
        $this->assertNotNull(Program::query()->find($id));

        $this->deleteJson("/api/v1/me/programs/{$id}", [], $auth)->assertNoContent();
        $this->deleteJson("/api/v1/me/programs/{$id}", [], $auth)->assertNoContent(); // already gone
        $this->assertNull(Program::query()->find($id));
    }

    public function test_program_validation(): void
    {
        $auth = $this->tokenFor($this->member());
        $id = (string) Str::uuid();

        $this->putJson('/api/v1/me/programs/not-a-uuid', ['name' => 'x', 'hymn_ids' => []], $auth)->assertUnprocessable();
        $this->putJson("/api/v1/me/programs/{$id}", ['name' => str_repeat('a', 81), 'hymn_ids' => ['missing']], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'hymn_ids.0']);
        $this->putJson("/api/v1/me/programs/{$id}", ['name' => 'Too many', 'hymn_ids' => array_fill(0, 101, 'x')], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['hymn_ids']);
    }

    public function test_reading_progress_is_idempotent(): void
    {
        $auth = $this->tokenFor($this->member());

        $this->putJson('/api/v1/me/devotional-progress/2026-01-05', [], $auth)->assertNoContent();
        $this->putJson('/api/v1/me/devotional-progress/2026-01-05', [], $auth)->assertNoContent();
        $this->putJson('/api/v1/me/devotional-progress/2026-01-01', [], $auth)->assertNoContent();
        $this->putJson('/api/v1/me/devotional-progress/2025-12-31', [], $auth)->assertNoContent();
        $this->putJson('/api/v1/me/devotional-progress/not-a-date', [], $auth)->assertUnprocessable();

        $this->getJson('/api/v1/me/devotional-progress?year=2026', $auth)->assertOk()
            ->assertExactJson(['data' => ['read_dates' => ['2026-01-01', '2026-01-05']]]);
        $this->getJson('/api/v1/me/devotional-progress', $auth)->assertUnprocessable();
    }

    public function test_device_registration_upserts_by_token(): void
    {
        $user = $this->member();
        $other = $this->member();
        $payload = ['token' => 'fcm-token-1', 'platform' => 'android', 'app_version' => '1.0.0', 'locale' => 'en'];

        $this->postJson('/api/v1/me/devices', $payload, $this->tokenFor($user))->assertNoContent();
        $this->postJson('/api/v1/me/devices', $payload, $this->tokenFor($user))->assertNoContent();
        $this->assertSame(1, $user->devices()->count());

        // Same physical device signs into another account: token moves.
        $this->postJson('/api/v1/me/devices', $payload, $this->tokenFor($other))->assertNoContent();
        $this->assertSame(0, $user->devices()->count());
        $this->assertSame(1, $other->devices()->count());

        $this->postJson('/api/v1/me/devices', ['token' => 'x', 'platform' => 'windows'], $this->tokenFor($user))->assertUnprocessable();

        $this->deleteJson('/api/v1/me/devices/fcm-token-1', [], $this->tokenFor($other))->assertNoContent();
        $this->assertSame(0, $other->devices()->count());
    }
}
