<?php

namespace App\Services\Auth;

use App\Enums\Platform;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token model (backend-handoff D.1):
 *  - access token: Sanctum personal access token, 15 min expiry
 *  - refresh token: opaque, 60 days, stored as SHA-256, rotated on every use;
 *    presenting a revoked token revokes the whole family (theft detection).
 */
class TokenService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
    public function issue(User $user, ?string $deviceName, Request $request, ?string $familyId = null): array
    {
        $ttl = (int) config('uec.auth.access_token_ttl_minutes');
        $name = $deviceName ? mb_substr($deviceName, 0, 100) : 'mobile';
        $access = $user->createToken($name, ['*'], now()->addMinutes($ttl));

        $plainRefresh = 'rt_'.Str::random(80);
        RefreshToken::query()->create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::ulid(),
            'token_hash' => $this->hash($plainRefresh),
            'access_token_id' => $access->accessToken->getKey(),
            'device_name' => $deviceName ? mb_substr($deviceName, 0, 100) : null,
            'platform' => Platform::fromHeader($request->header('X-Platform'))?->value,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'expires_at' => now()->addDays((int) config('uec.auth.refresh_token_ttl_days')),
        ]);

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $plainRefresh,
            'expires_in' => $ttl * 60,
            'token_type' => 'Bearer',
        ];
    }

    /** POST /auth/refresh — rotate. Throws 401 REFRESH_INVALID on any failure. */
    public function refresh(string $plain, Request $request): array
    {
        // Revocations must commit even when the request is rejected, so the transaction
        // returns null on failure and the 401 is thrown only after it has committed.
        $tokens = DB::transaction(function () use ($plain, $request): ?array {
            /** @var RefreshToken|null $token */
            $token = RefreshToken::query()->where('token_hash', $this->hash($plain))->lockForUpdate()->first();

            if (! $token) {
                return null;
            }

            if ($token->revoked_at !== null) {
                // A rotated/revoked token was presented again: assume theft, kill the family.
                $this->revokeFamily($token->family_id, 'reuse_detected');
                $this->audit->security('refresh_token_reuse', 'Revoked refresh token reused; token family revoked.', [
                    'family_id' => $token->family_id, 'user_id' => $token->user_id, 'ip' => $request->ip(),
                ]);

                return null;
            }

            $user = User::query()->find($token->user_id);
            if ($token->expires_at->isPast() || ! $user || $user->isSuspended()) {
                $this->revoke($token, $token->expires_at->isPast() ? 'expired' : 'user_inactive');

                return null;
            }

            $tokens = $this->issue($user, $token->device_name, $request, $token->family_id);
            $replacement = RefreshToken::query()->where('token_hash', $this->hash($tokens['refresh_token']))->value('id');

            $this->revoke($token, 'rotated', $replacement);

            return $tokens;
        });

        return $tokens ?? throw $this->invalid();
    }

    /** POST /auth/logout — revoke this session's refresh token and the current access token. */
    public function logout(User $user, ?string $plainRefresh): void
    {
        if ($plainRefresh) {
            $token = RefreshToken::query()->where('user_id', $user->id)->where('token_hash', $this->hash($plainRefresh))->first();
            if ($token && $token->revoked_at === null) {
                $this->revoke($token, 'logout');
            }
        }

        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
    }

    /** Revoke every session (password reset, account deletion, admin suspension). */
    public function revokeAll(User $user, string $reason): void
    {
        RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        $user->tokens()->delete();
    }

    private function revoke(RefreshToken $token, string $reason, ?string $replacedBy = null): void
    {
        $token->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason, 'replaced_by_id' => $replacedBy, 'last_used_at' => now()])->save();
        if ($token->access_token_id) {
            PersonalAccessToken::query()->whereKey($token->access_token_id)->delete();
        }
    }

    private function revokeFamily(string $familyId, string $reason): void
    {
        $tokens = RefreshToken::query()->where('family_id', $familyId)->get();
        PersonalAccessToken::query()->whereIn('id', $tokens->pluck('access_token_id')->filter())->delete();
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function invalid(): ApiException
    {
        return new ApiException(401, __('api.refresh_invalid'), 'REFRESH_INVALID');
    }
}
