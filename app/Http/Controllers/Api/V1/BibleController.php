<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BibleVerse;
use App\Models\BibleVersion;
use App\Support\Api\ApiException;
use App\Support\Usfm;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** #34–#35 Bible (backend-handoff B.6, C.4). Text never changes: long cache headers. */
class BibleController extends Controller
{
    public function versions(): JsonResponse
    {
        $versions = BibleVersion::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        return $this->data($versions->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'abbreviation' => $v->abbreviation,
        ])->all(), 200, ['Cache-Control' => 'public, max-age=86400']);
    }

    public function chapter(string $version, string $book, int $chapter): JsonResponse
    {
        $book = strtoupper($book);
        if (! Usfm::isValid($book, $chapter)) {
            throw ApiException::notFound();
        }

        $key = 'bible:'.Cache::get('bible:rev', 1).":{$version}:{$book}:{$chapter}";
        $payload = Cache::remember($key, 86400 * 7, function () use ($version, $book, $chapter) {
            $active = BibleVersion::query()->where('id', $version)->where('is_active', true)->exists();
            if (! $active) {
                return null;
            }
            $verses = BibleVerse::query()
                ->where(['version_id' => $version, 'book_id' => $book, 'chapter' => $chapter])
                ->orderBy('verse')
                ->get(['verse', 'text']);

            return $verses->isEmpty() ? null : [
                'version' => $version,
                'book_id' => $book,
                'chapter' => $chapter,
                'verses' => $verses->map(fn ($v) => ['number' => $v->verse, 'text' => $v->text])->all(),
            ];
        });

        if ($payload === null) {
            Cache::forget($key);
            throw ApiException::notFound();
        }

        return $this->data($payload, 200, ['Cache-Control' => 'public, max-age=604800']);
    }
}
