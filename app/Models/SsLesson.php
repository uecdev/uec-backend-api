<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsLesson extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'manual_year' => 'integer',
            'number' => 'integer',
            'outline' => 'array',
            'applications' => 'array',
            'revision_questions' => 'array',
        ];
    }

    public function manual(): BelongsTo
    {
        return $this->belongsTo(SsManual::class, 'manual_year', 'year');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereHas('manual', fn (Builder $q) => $q->published());
    }
}
