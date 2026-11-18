<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BibleVerse extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['chapter' => 'integer', 'verse' => 'integer'];
    }
}
