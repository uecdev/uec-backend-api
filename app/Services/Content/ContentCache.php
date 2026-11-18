<?php

namespace App\Services\Content;

use App\Http\Resources\V1\ContentResources;
use App\Http\Resources\V1\HymnResources;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\Hymn;
use App\Models\HymnCategory;
use App\Models\SsManual;
use App\Support\Iso;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-rendered payloads for the heaviest endpoints:
 *  - the full hymn catalogue (#21), with a version/ETag
 *  - offline edition and manual bundles (#26, #32), rebuilt when content is published
 */
class ContentCache
{
    private const TTL = 86400;

    /** @return array{payload: array, etag: string} */
    public function hymnCatalogue(): array
    {
        return Cache::remember('content:hymns:catalogue', self::TTL, function () {
            $hymns = Hymn::query()->published()
                ->with('categories:id,slug')
                ->withCount('audio')
                ->withExists('solfa')
                ->orderBy('number')
                ->get();

            $version = Iso::ts($hymns->max('updated_at') ?? now());
            $payload = [
                'version' => $version,
                'categories' => HymnCategory::query()->orderBy('sort')->orderBy('name')->get(['slug', 'name'])
                    ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->all(),
                'hymns' => $hymns->map(fn (Hymn $h) => HymnResources::summary($h))->all(),
            ];

            return ['payload' => $payload, 'etag' => '"'.sha1(json_encode($payload)).'"'];
        });
    }

    public function forgetHymnCatalogue(): void
    {
        Cache::forget('content:hymns:catalogue');
    }

    public function devotionalBundle(int $year): ?array
    {
        return Cache::get("content:dde:bundle:{$year}") ?? $this->rebuildDevotionalBundle($year);
    }

    public function rebuildDevotionalBundle(int $year): ?array
    {
        $edition = DevotionalEdition::query()->find($year);
        if (! $edition) {
            Cache::forget("content:dde:bundle:{$year}");

            return null;
        }

        $entries = DevotionalEntry::query()->where('edition_year', $year)
            ->when(! config('uec.content.bundle_includes_future'), fn ($q) => $q->where('date', '<=', now(config('uec.timezone'))->toDateString()))
            ->orderBy('date')->get();

        $bundle = [
            'edition' => ContentResources::edition($edition, $entries->count()),
            'entries' => $entries->map(fn ($e) => ContentResources::entry($e))->all(),
        ];
        $size = strlen(json_encode(['data' => $bundle]));
        $bundle['edition']['size_bytes'] = $size;
        if ((int) $edition->bundle_size !== $size) {
            $edition->forceFill(['bundle_size' => $size])->saveQuietly();
        }

        Cache::put("content:dde:bundle:{$year}", $bundle, self::TTL);

        return $bundle;
    }

    public function manualBundle(int $year): ?array
    {
        return Cache::get("content:ssm:bundle:{$year}") ?? $this->rebuildManualBundle($year);
    }

    public function rebuildManualBundle(int $year): ?array
    {
        $manual = SsManual::query()->with(['lessons', 'planWeeks.lesson:id,number'])->find($year);
        if (! $manual) {
            Cache::forget("content:ssm:bundle:{$year}");

            return null;
        }

        $bundle = [
            'manual' => ContentResources::manual($manual, $manual->lessons->count()),
            'lessons' => $manual->lessons->map(fn ($l) => ContentResources::lesson($l))->all(),
            'plan' => $manual->planWeeks->map(fn ($w) => ContentResources::planWeek($w))->all(),
        ];
        $size = strlen(json_encode(['data' => $bundle]));
        $bundle['manual']['size_bytes'] = $size;
        if ((int) $manual->bundle_size !== $size) {
            $manual->forceFill(['bundle_size' => $size])->saveQuietly();
        }

        Cache::put("content:ssm:bundle:{$year}", $bundle, self::TTL);

        return $bundle;
    }
}
