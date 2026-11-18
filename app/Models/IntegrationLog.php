<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['success' => 'boolean', 'status_code' => 'integer', 'duration_ms' => 'integer'];
    }
}
