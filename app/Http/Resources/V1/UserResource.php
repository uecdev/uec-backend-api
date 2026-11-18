<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'phone_verified_at' => Iso::ts($this->phone_verified_at),
            'email_verified_at' => Iso::ts($this->email_verified_at),
            'avatar_url' => $this->avatar_url,
            'is_premium' => app(SubscriptionService::class)->isPremium($this->resource),
            'roles' => $this->getRoleNames()->values()->all() ?: ['member'],
            'created_at' => Iso::ts($this->created_at),
        ];
    }
}
