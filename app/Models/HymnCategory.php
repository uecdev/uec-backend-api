<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HymnCategory extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function hymns(): BelongsToMany
    {
        return $this->belongsToMany(Hymn::class, 'hymn_category_hymn');
    }
}
