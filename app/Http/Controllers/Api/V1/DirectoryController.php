<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResources;
use App\Http\Resources\V1\HymnResources;
use App\Models\Branch;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\Event;
use App\Models\Hymn;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** #36–#39 Events, branches and global search (backend-handoff B.7, B.8, C.4). */
class DirectoryController extends Controller
{
    /** Events that start in the requested year. */
    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($data['year'] ?? now(config('uec.timezone'))->year);

        $events = Event::query()->published()
            ->whereYear('start_date', $year)
            ->orderBy('start_date')->orderBy('start_time')
            ->get();

        return $this->data($events->map(fn ($e) => ContentResources::event($e))->all(), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function event(string $id): JsonResponse
    {
        $event = Event::query()->published()->find($id);
        if (! $event) {
            throw ApiException::notFound();
        }

        return $this->data(ContentResources::event($event), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    /** Paginated, searchable branch directory; alphabetical by default. */
    public function branches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Branch::query()->where('is_active', true)
            ->search($data['q'] ?? null)
            ->orderBy('name')
            ->paginate((int) ($data['per_page'] ?? 30));

        return $this->paginated($page, fn (Branch $b) => ContentResources::branch($b));
    }

    /** Global search: ≤10 per group; devotionals from the current edition only. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $q = trim($data['q']);

        $hymns = Hymn::query()->published()->search($q)
            ->with('categories:id,slug')->withCount('audio')->withExists('solfa')
            ->orderBy('number')->limit(10)->get();

        $currentYear = DevotionalEdition::query()->published()->max('year');
        $devotionals = $currentYear ? DevotionalEntry::query()->visible()
            ->where('edition_year', $currentYear)
            ->where(fn ($w) => $w->whereLike('title', "%{$q}%")->orWhereLike('passage', "%{$q}%"))
            ->orderByDesc('date')->limit(10)->get() : collect();

        $events = Event::query()->published()->search($q)->orderByDesc('start_date')->limit(10)->get();

        return $this->data([
            'hymns' => $hymns->map(fn ($h) => HymnResources::summary($h))->all(),
            'devotionals' => $devotionals->map(fn ($e) => ContentResources::entrySummary($e))->all(),
            'lessons' => SundaySchoolController::searchLessons($q, 10),
            'events' => $events->map(fn ($e) => ContentResources::event($e))->all(),
        ]);
    }
}
