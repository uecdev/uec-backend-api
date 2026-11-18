<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResources;
use App\Models\Page;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;

/** #20 GET /pages/{slug} — slug ∈ about | anthem | doctrine (| terms | privacy). */
class PageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = in_array($slug, config('uec.content.pages'), true)
            ? Page::query()->published()->find($slug)
            : null;

        if (! $page) {
            throw ApiException::notFound();
        }

        return $this->data(ContentResources::page($page), 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
