<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HymnAudio extends Model
{
    use HasUlids;

    protected $table = 'hymn_audio';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'integer', 'size_bytes' => 'integer', 'position' => 'integer'];
    }

    public function hymn(): BelongsTo
    {
        return $this->belongsTo(Hymn::class);
    }
}
