<?php

namespace App\Models;

use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevotionalEdition extends Model
{
    use HasFactory, Publishable;

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_premium' => 'boolean',
            'published_at' => 'datetime',
            'bundle_size' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DevotionalEntry::class, 'edition_year', 'year')->orderBy('date');
    }
}
