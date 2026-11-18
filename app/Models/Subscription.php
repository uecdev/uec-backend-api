<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['provider_email_token', 'authorization'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'provider_email_token' => 'encrypted',
            'authorization' => 'encrypted:array',
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_billing_date' => 'datetime',
            'cancelled_at' => 'datetime',
            'access_until' => 'datetime',
            'past_due_at' => 'datetime',
            'expired_at' => 'datetime',
            'pending_plan_effective_at' => 'datetime',
            'gateway_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Whether this subscription currently grants premium access (backend-handoff D.1/E.5):
     * active, cancelled-but-not-yet-ended, or past due within the grace period.
     */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active => true,
            SubscriptionStatus::Cancelled => $this->access_until?->isFuture() ?? false,
            SubscriptionStatus::PastDue => $this->past_due_at !== null
                && $this->past_due_at->copy()->addDays(config('uec.subscriptions.grace_days'))->isFuture(),
            SubscriptionStatus::Expired => false,
        };
    }

    public function scopeStatus(Builder $query, SubscriptionStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
    }

    public function paymentMethodLabel(): ?string
    {
        $auth = $this->authorization;
        if (! $auth) {
            return null;
        }
        $brand = isset($auth['brand']) ? ucfirst(strtolower((string) $auth['brand'])) : 'Card';

        return isset($auth['last4']) ? "{$brand} •••• {$auth['last4']}" : $brand;
    }
}
