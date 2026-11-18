<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

abstract class Controller
{
    /** Contract success envelope: { "data": … } (backend-handoff C.1). */
    protected function data(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data], $status, $headers);
    }

    protected function noContent(int $status = 204): Response
    {
        return response()->noContent($status);
    }

    /** Laravel-style pagination meta, exactly as the app expects. */
    protected function paginated(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return response()->json([
            'data' => array_map($map, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
