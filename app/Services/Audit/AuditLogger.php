<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records administrative actions and security events. Sensitive keys are
 * stripped from properties before storage (backend-handoff I.4).
 */
class AuditLogger
{
    private const REDACT = ['password', 'password_confirmation', 'token', 'code', 'refresh_token', 'access_token', 'secret', 'authorization'];

    /** @param array<string, mixed> $properties */
    public function log(string $action, ?Model $subject = null, ?string $description = null, array $properties = [], ?User $actor = null, string $actorType = 'user'): void
    {
        try {
            $actor ??= Auth::user();
            $request = app()->runningInConsole() ? null : request();

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'description' => $description ? mb_substr($description, 0, 500) : null,
                'properties' => $properties ? $this->redact($properties) : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the business operation; surface it in logs instead.
            Log::error('audit.write_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Security events (token reuse, amount mismatch, bad signatures) are also written to the log stream. */
    public function security(string $action, string $description, array $properties = [], ?Model $subject = null): void
    {
        Log::warning("security.{$action}", $this->redact($properties));
        $this->log("security.{$action}", $subject, $description, $properties, actorType: 'system');
    }

    /** Field-level change set for an updated model. */
    public function changes(Model $model): array
    {
        $changes = [];
        foreach (Arr::except($model->getChanges(), ['updated_at']) as $key => $new) {
            $changes[$key] = ['from' => $model->getOriginal($key), 'to' => $new];
        }

        return $this->redact($changes);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DATE_ATOM);
            }
        }

        return $data;
    }
}
