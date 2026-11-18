<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResources;
use App\Models\SsLesson;
use App\Models\SsManual;
use App\Services\Content\ContentCache;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** #29–#33 Sunday School Manual (backend-handoff B.4, C.4). */
class SundaySchoolController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function manuals(): JsonResponse
    {
        $manuals = SsManual::query()->published()->withCount('lessons')->orderByDesc('year')->get();

        return $this->data($manuals->map(fn ($m) => ContentResources::manual($m))->all(), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function manual(Request $request, int $year): JsonResponse
    {
        $manual = $this->publishedManual($year, $request);
        $manual->load(['lessons:id,manual_year,number,title,passage', 'planWeeks.lesson:id,number']);

        return $this->data([
            'manual' => ContentResources::manual($manual, $manual->lessons->count()),
            'lessons' => $manual->lessons->map(fn ($l) => ContentResources::lessonSummary($l))->all(),
            'plan' => $manual->planWeeks->map(fn ($w) => ContentResources::planWeek($w))->all(),
        ], 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function lesson(Request $request, string $id): JsonResponse
    {
        $lesson = SsLesson::query()->visible()->find($id);
        if (! $lesson) {
            throw ApiException::notFound();
        }
        $this->publishedManual($lesson->manual_year, $request);

        return $this->data(ContentResources::lesson($lesson), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    /** #32 Offline bundle — token + offline.downloads entitlement. */
    public function download(Request $request, int $year, ContentCache $cache): JsonResponse
    {
        $manual = SsManual::query()->published()->find($year);
        if (! $manual) {
            throw ApiException::notFound();
        }

        $freeBypass = ! $manual->is_premium && config('uec.content.free_downloads_without_entitlement');
        if (! $freeBypass && ! $this->subscriptions->hasEntitlement($request->user(), 'offline.downloads')) {
            throw ApiException::premiumRequired();
        }

        return $this->data($cache->manualBundle($year), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** #33 Search across all years: title, passage and "lesson N". Up to 50, best match first. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        return $this->data(self::searchLessons($data['q'], 50));
    }

    /** @return list<array<string, mixed>> */
    public static function searchLessons(string $term, int $limit): array
    {
        $term = trim($term);
        $number = preg_match('/^(?:lesson\s*)?(\d{1,3})$/i', $term, $m) ? (int) $m[1] : null;

        $lessons = SsLesson::query()->visible()
            ->where(function ($q) use ($term, $number) {
                $q->whereLike('title', "%{$term}%")->orWhereLike('passage', "%{$term}%");
                if ($number !== null) {
                    $q->orWhere('number', $number);
                }
            })
            ->get(['id', 'manual_year', 'number', 'title', 'passage']);

        $needle = mb_strtolower($term);

        return $lessons
            ->sortBy([
                fn ($a, $b) => self::rank($b, $needle, $number) <=> self::rank($a, $needle, $number),
                fn ($a, $b) => $b->manual_year <=> $a->manual_year,
                fn ($a, $b) => $a->number <=> $b->number,
            ])
            ->take($limit)
            ->map(fn ($l) => ContentResources::lessonSummary($l))
            ->values()
            ->all();
    }

    private static function rank(SsLesson $l, string $needle, ?int $number): int
    {
        $title = mb_strtolower($l->title);

        return match (true) {
            $number !== null && $l->number === $number => 4,
            $title === $needle => 3,
            str_starts_with($title, $needle) => 2,
            str_contains($title, $needle) => 1,
            default => 0,
        };
    }

    private function publishedManual(int $year, Request $request): SsManual
    {
        $manual = SsManual::query()->published()->find($year);
        if (! $manual) {
            throw ApiException::notFound();
        }
        if ($manual->is_premium && ! $this->subscriptions->hasEntitlement($request->user('sanctum'), 'archive.editions')) {
            throw ApiException::premiumRequired();
        }

        return $manual;
    }
}
