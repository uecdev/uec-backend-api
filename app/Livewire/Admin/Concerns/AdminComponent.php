<?php

namespace App\Livewire\Admin\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Shared admin behaviour: permission checks, audit trail and toast feedback. */
trait AdminComponent
{
    protected function authorizeAbility(string $ability): void
    {
        Gate::authorize($ability);
    }

    protected function audit(string $action, ?Model $subject = null, ?string $description = null, array $properties = []): void
    {
        app(AuditLogger::class)->log($action, $subject, $description, $properties);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }
}
