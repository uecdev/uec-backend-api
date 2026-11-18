<?php

namespace App\Models;

use App\Enums\PushCampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushCampaign extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PushCampaignStatus::class,
            'filters' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'target_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'invalid_token_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveryRate(): ?float
    {
        $done = $this->success_count + $this->failure_count;

        return $done > 0 ? round($this->success_count / $done * 100, 1) : null;
    }
}
