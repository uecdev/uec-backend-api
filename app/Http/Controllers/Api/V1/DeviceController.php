<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Endpoints #17–#18: FCM device registration (backend-handoff H.1). */
class DeviceController extends Controller
{
    /** Upsert by token; a token that moves to another account is re-assigned. */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'app_version' => ['nullable', 'string', 'max:20'],
            'locale' => ['nullable', 'string', Rule::in(config('uec.locales'))],
        ]);

        DB::transaction(function () use ($request, $data) {
            $device = Device::query()->where('token', $data['token'])->lockForUpdate()->first() ?? new Device(['token' => $data['token']]);
            $device->forceFill([
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'locale' => $data['locale'] ?? null,
                'last_seen_at' => now(),
            ])->save();
        });

        return $this->noContent();
    }

    public function destroy(Request $request, string $token): Response
    {
        Device::query()->where('user_id', $request->user()->id)->where('token', $token)->delete();

        return $this->noContent();
    }
}
