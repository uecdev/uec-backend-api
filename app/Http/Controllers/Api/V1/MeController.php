<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ContactChangeRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Account\AccountService;
use App\Services\Media\MediaService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Endpoints #11–#16 (backend-handoff D.7). */
class MeController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly MediaService $media) {}

    public function show(Request $request): JsonResponse
    {
        return $this->user($request);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['present', 'nullable', 'string', 'max:80']]);
        $name = isset($data['name']) ? trim(strip_tags($data['name'])) : null;
        $request->user()->forceFill(['name' => $name === '' ? null : $name])->save();

        return $this->user($request);
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'max:'.config('uec.media.max_upload_kb'), 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif'],
        ]);

        $user = $request->user();
        $stored = $this->media->storeAvatar($user, $request->file('avatar'));
        $user->forceFill(['avatar_url' => $stored['url'], 'avatar_path' => $stored['path']])->save();

        return $this->user($request);
    }

    public function contact(ContactChangeRequest $request): JsonResponse
    {
        $challenge = $this->accounts->startContactChange($request->user(), $request->validated('channel'), $request->validated('value'), $request);

        return $this->data($challenge, 201);
    }

    public function verifyContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'digits:'.config('uec.otp.length')],
        ]);

        $this->accounts->completeContactChange($request->user(), $data['verification_id'], $data['code']);

        return $this->user($request);
    }

    public function destroy(Request $request): Response
    {
        if ($request->input('confirmation') !== 'DELETE') {
            throw ApiException::validation('confirmation', __('api.delete_confirmation'));
        }

        $this->accounts->delete($request->user());

        return $this->noContent();
    }

    private function user(Request $request): JsonResponse
    {
        return $this->data((new UserResource($request->user()->refresh()))->resolve());
    }
}
