<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\HymnResources;
use App\Models\Hymn;
use App\Services\Content\ContentCache;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** #21–#22 Hymnal (backend-handoff C.4). */
class HymnController extends Controller
{
    /** Full catalogue in one response, cached 1 h, with ETag / If-None-Match support. */
    public function index(Request $request, ContentCache $cache): JsonResponse|Response
    {
        ['payload' => $payload, 'etag' => $etag] = $cache->hymnCatalogue();
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=3600'];

        $ifNoneMatch = array_map('trim', explode(',', (string) $request->header('If-None-Match', '')));
        if (in_array($etag, $ifNoneMatch, true)) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return $this->data($payload, 200, $headers);
    }

    /** Detail; audio URLs and solfa are only returned to entitled users (E.6). */
    public function show(Request $request, string $id, SubscriptionService $subscriptions): JsonResponse
    {
        $hymn = Hymn::query()->published()
            ->with(['categories:id,slug', 'stanzas', 'solfa', 'audio'])
            ->find($id);

        if (! $hymn) {
            throw ApiException::notFound();
        }

        $user = $request->user('sanctum');

        return $this->data(HymnResources::detail(
            $hymn,
            $subscriptions->hasEntitlement($user, 'hymn.audio'),
            $subscriptions->hasEntitlement($user, 'hymn.solfa'),
        ), 200, ['Cache-Control' => 'private, max-age=300', 'Vary' => 'Authorization']);
    }
}
