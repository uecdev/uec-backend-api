<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HymnSolfa extends Model
{
    protected $table = 'hymn_solfa';

    protected $primaryKey = 'hymn_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sections' => 'array', 'width' => 'integer', 'height' => 'integer'];
    }

    public function hymn(): BelongsTo
    {
        return $this->belongsTo(Hymn::class);
    }
}
