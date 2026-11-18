<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResources;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\DevotionalRead;
use App\Services\Content\ContentCache;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** #23–#28 Daily Delight devotional (backend-handoff B.3, C.4). */
class DevotionalController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function editions(): JsonResponse
    {
        $editions = DevotionalEdition::query()->published()->withCount('entries')->orderByDesc('year')->get();

        return $this->data($editions->map(fn ($e) => ContentResources::edition($e))->all(), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function entries(Request $request, int $year): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'integer', 'between:1,12']]);
        $edition = $this->edition($year, $request);

        $entries = DevotionalEntry::query()->visible()
            ->where('edition_year', $edition->year)
            ->when($request->filled('month'), fn ($q) => $q->whereMonth('date', (int) $request->input('month')))
            ->orderBy('date')
            ->get(['date', 'title', 'passage', 'edition_year']);

        return $this->data($entries->map(fn ($e) => ContentResources::entrySummary($e))->all(), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function entry(Request $request, string $date): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
            throw ApiException::notFound();
        }

        $entry = DevotionalEntry::query()->visible()->whereDate('date', $date)->first();
        if (! $entry) {
            throw ApiException::notFound(__('api.not_found'));
        }
        $this->edition($entry->edition_year, $request);

        return $this->data(ContentResources::entry($entry), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    /** #26 Offline bundle — token + offline.downloads entitlement (E.6). */
    public function download(Request $request, int $year, ContentCache $cache): JsonResponse
    {
        $edition = DevotionalEdition::query()->published()->find($year);
        if (! $edition) {
            throw ApiException::notFound();
        }

        $freeBypass = ! $edition->is_premium && config('uec.content.free_downloads_without_entitlement');
        if (! $freeBypass && ! $this->subscriptions->hasEntitlement($request->user(), 'offline.downloads')) {
            throw ApiException::premiumRequired();
        }

        return $this->data($cache->devotionalBundle($year), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** #27 GET /me/devotional-progress?year= */
    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);

        $dates = DevotionalRead::query()
            ->where('user_id', $request->user()->id)
            ->whereYear('entry_date', $data['year'])
            ->orderBy('entry_date')
            ->pluck('entry_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->all();

        return $this->data(['read_dates' => $dates]);
    }

    /** #28 PUT /me/devotional-progress/{date} — idempotent, 204 */
    public function markRead(Request $request, string $date): Response
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
            throw ApiException::validation('date', __('validation.date_format', ['attribute' => 'date', 'format' => 'YYYY-MM-DD']));
        }

        DevotionalRead::query()->insertOrIgnore([
            'user_id' => $request->user()->id,
            'entry_date' => $date,
            'read_at' => now(),
        ]);

        return $this->noContent();
    }

    /** Published edition, with archive editions gated behind archive.editions. */
    private function edition(int $year, Request $request): DevotionalEdition
    {
        $edition = DevotionalEdition::query()->published()->find($year);
        if (! $edition) {
            throw ApiException::notFound();
        }
        if ($edition->is_premium && ! $this->subscriptions->hasEntitlement($request->user('sanctum'), 'archive.editions')) {
            throw ApiException::premiumRequired();
        }

        return $edition;
    }
}
