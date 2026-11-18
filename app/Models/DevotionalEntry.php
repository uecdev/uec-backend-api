<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevotionalEntry extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'edition_year' => 'integer'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(DevotionalEdition::class, 'edition_year', 'year');
    }

    /**
     * Entries the public may read: edition published and, unless configured otherwise,
     * not dated after today in the content timezone (backend-handoff B.3 [CONFIRM]).
     */
    public function scopeVisible(Builder $query, bool $includeFuture = false): Builder
    {
        $query->whereHas('edition', fn (Builder $q) => $q->published());

        if (! $includeFuture && ! config('uec.content.devotional_future_visibility')) {
            $query->where('date', '<=', now(config('uec.timezone'))->toDateString());
        }

        return $query;
    }
}
