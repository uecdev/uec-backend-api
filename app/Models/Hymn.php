<?php

namespace App\Models;

use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Hymn extends Model
{
    use HasFactory, HasUlids, Publishable;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // DB cascades remove rows; this removes the private media files they point to.
        static::deleting(function (Hymn $hymn) {
            $disk = Storage::disk(config('uec.media.private_disk'));
            $paths = $hymn->audio()->pluck('storage_path')->push($hymn->solfa?->image_path)->filter()->all();
            if ($paths) {
                $disk->delete($paths);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'scripture_refs' => 'array',
            'tags' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(HymnCategory::class, 'hymn_category_hymn')->orderBy('sort');
    }

    public function stanzas(): HasMany
    {
        return $this->hasMany(HymnStanza::class)->orderBy('position');
    }

    public function solfa(): HasOne
    {
        return $this->hasOne(HymnSolfa::class);
    }

    public function audio(): HasMany
    {
        return $this->hasMany(HymnAudio::class)->orderBy('position');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            if (ctype_digit($term)) {
                $q->orWhere('number', (int) $term);
            }
            $q->orWhereLike('title', "%{$term}%")->orWhereLike('first_line', "%{$term}%");
        });
    }
}
