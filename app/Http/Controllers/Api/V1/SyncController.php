<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillingResources;
use App\Models\Favourite;
use App\Models\Hymn;
use App\Models\Program;
use App\Models\SsLesson;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #40–#44 Personal data sync (backend-handoff C.5). Idempotent full-state writes,
 * always scoped to the authenticated user (I.2).
 */
class SyncController extends Controller
{
    public function favourites(Request $request): JsonResponse
    {
        $rows = Favourite::query()->where('user_id', $request->user()->id)->orderBy('id')->get(['favouritable_type', 'favouritable_id']);

        return $this->data([
            'hymn_ids' => $rows->where('favouritable_type', Favourite::TYPE_HYMN)->pluck('favouritable_id')->values()->all(),
            'lesson_ids' => $rows->where('favouritable_type', Favourite::TYPE_LESSON)->pluck('favouritable_id')->values()->all(),
        ]);
    }

    /**
     * Full replacement. Unknown ids are dropped rather than rejected so a client
     * holding a since-deleted hymn can still sync the rest of its favourites.
     */
    public function replaceFavourites(Request $request): Response
    {
        $data = $request->validate([
            'hymn_ids' => ['present', 'array', 'max:2000'],
            'hymn_ids.*' => ['string', 'max:40'],
            'lesson_ids' => ['present', 'array', 'max:2000'],
            'lesson_ids.*' => ['string', 'max:40'],
        ]);

        $hymnIds = Hymn::query()->whereIn('id', array_unique($data['hymn_ids']))->pluck('id')->all();
        $lessonIds = SsLesson::query()->whereIn('id', array_unique($data['lesson_ids']))->pluck('id')->all();
        $userId = $request->user()->id;

        DB::transaction(function () use ($userId, $hymnIds, $lessonIds, $data) {
            Favourite::query()->where('user_id', $userId)->delete();
            $now = now();
            // Preserve client order.
            $ordered = fn (array $requested, array $valid) => array_values(array_intersect(array_unique($requested), $valid));
            $rows = [];
            foreach ($ordered($data['hymn_ids'], $hymnIds) as $id) {
                $rows[] = ['user_id' => $userId, 'favouritable_type' => Favourite::TYPE_HYMN, 'favouritable_id' => $id, 'created_at' => $now];
            }
            foreach ($ordered($data['lesson_ids'], $lessonIds) as $id) {
                $rows[] = ['user_id' => $userId, 'favouritable_type' => Favourite::TYPE_LESSON, 'favouritable_id' => $id, 'created_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                Favourite::query()->insert($chunk);
            }
        });

        return $this->noContent();
    }

    public function programs(Request $request): JsonResponse
    {
        $programs = Program::query()->where('user_id', $request->user()->id)->orderByDesc('updated_at')->get();

        return $this->data($programs->map(fn ($p) => BillingResources::program($p))->all());
    }

    /** PUT /me/programs/{id} — 201 created, 204 updated. Last write wins on updated_at. */
    public function upsertProgram(Request $request, string $id): Response
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw ApiException::validation('id', 'The program id must be a UUID v4.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'hymn_ids' => ['present', 'array', 'max:100'],
            'hymn_ids.*' => ['string', 'distinct', 'exists:hymns,id'],
            'updated_at' => ['nullable', 'date'],
        ]);

        $id = strtolower($id);
        $clientUpdatedAt = isset($data['updated_at']) ? Carbon::parse($data['updated_at'])->utc() : now();
        $userId = $request->user()->id;

        $created = DB::transaction(function () use ($id, $userId, $data, $clientUpdatedAt) {
            $program = Program::query()->lockForUpdate()->find($id);

            if ($program && $program->user_id !== $userId) {
                throw ApiException::notFound(__('api.program_not_found')); // never touch another user's row
            }

            if ($program && $program->updated_at && $program->updated_at->gt($clientUpdatedAt)) {
                return false; // stale write: server copy is newer; idempotent no-op
            }

            $isNew = $program === null;
            $program ??= new Program(['id' => $id, 'user_id' => $userId, 'created_at' => now()]);
            $program->forceFill([
                'name' => trim($data['name']),
                'date' => $data['date'] ?? null,
                'hymn_ids' => array_values($data['hymn_ids']),
                'updated_at' => $clientUpdatedAt,
            ])->save();

            return $isNew;
        });

        return $this->noContent($created ? 201 : 204);
    }

    public function deleteProgram(Request $request, string $id): Response
    {
        Program::query()->where('user_id', $request->user()->id)->where('id', strtolower($id))->delete();

        return $this->noContent(); // 204 even if already gone
    }
}
