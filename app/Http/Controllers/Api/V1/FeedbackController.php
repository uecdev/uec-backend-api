<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FeedbackRequest;
use App\Models\Feedback;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** #52 POST /feedback — multipart; guests allowed (backend-handoff B.12). */
class FeedbackController extends Controller
{
    public function store(FeedbackRequest $request, MediaService $media): JsonResponse
    {
        $user = $request->user('sanctum');

        $feedback = DB::transaction(function () use ($request, $user, $media) {
            $feedback = Feedback::query()->create([
                'user_id' => $user?->id,
                'type' => $request->validated('type'),
                'message' => $request->validated('message'),
                'contact_consent' => (bool) $request->validated('contact_consent', false),
                'app_version' => mb_substr((string) $request->header('X-App-Version'), 0, 20) ?: null,
                'platform' => Platform::fromHeader($request->header('X-Platform'))?->value,
                'locale' => app()->getLocale(),
                'status' => 'new',
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $feedback->attachments()->create([
                    'disk' => config('uec.media.private_disk'),
                    'path' => $media->storePrivate($file, 'feedback/'.$feedback->id),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ]);
            }

            return $feedback;
        });

        return $this->data(['id' => $feedback->id, 'status' => 'received'], 201);
    }
}
