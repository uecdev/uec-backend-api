<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'interval_months' => 'integer',
            'savings_percent' => 'integer',
            'highlighted' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function periodEndFrom(CarbonInterface $start): CarbonInterface
    {
        return $start->copy()->addMonthsNoOverflow($this->interval_months);
    }

    /** Paystack plan interval names (backend-handoff E.2). */
    public function paystackInterval(): string
    {
        return match ($this->interval_months) {
            1 => 'monthly',
            3 => 'quarterly',
            6 => 'biannually',
            12 => 'annually',
            default => 'monthly',
        };
    }

    public function formattedAmount(): string
    {
        return '₦'.number_format($this->amount_kobo / 100, 2);
    }
}
