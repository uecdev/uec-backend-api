<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsPlanWeek extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'week' => 'integer', 'manual_year' => 'integer'];
    }

    public function manual(): BelongsTo
    {
        return $this->belongsTo(SsManual::class, 'manual_year', 'year');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(SsLesson::class, 'lesson_id');
    }
}
