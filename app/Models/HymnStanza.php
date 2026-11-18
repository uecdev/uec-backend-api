<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HymnStanza extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'number' => 'integer', 'position' => 'integer'];
    }

    public function hymn(): BelongsTo
    {
        return $this->belongsTo(Hymn::class);
    }
}
