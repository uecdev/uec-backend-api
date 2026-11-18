<?php

namespace App\Livewire\Admin\System;

use App\Jobs\ProcessWebhookEvent;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\WebhookEvent;
use App\Services\Operations\HealthCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Operations')]
class Operations extends Component
{
    use AdminComponent, WithPagination;

    #[Url]
    public string $tab = 'health';

    #[Url(except: '')]
    public string $webhookStatus = '';

    public function mount(): void
    {
        $this->authorizeAbility('system.jobs');
    }

    public function retryJob(string $uuid): void
    {
        $this->authorizeAbility('system.jobs');
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit('queue.job_retried', null, $uuid);
        $this->toast('Job pushed back onto the queue.');
    }

    public function forgetJob(string $uuid): void
    {
        $this->authorizeAbility('system.jobs');
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit('queue.job_forgotten', null, $uuid);
        $this->toast('Failed job removed.');
    }

    public function retryAll(): void
    {
        $this->authorizeAbility('system.jobs');
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->audit('queue.all_retried');
        $this->toast('All failed jobs pushed back onto the queue.');
    }

    public function reprocessWebhook(string $id): void
    {
        $this->authorizeAbility('system.jobs');
        $event = WebhookEvent::query()->findOrFail($id);
        $event->forceFill(['status' => 'received', 'error' => null])->save();
        ProcessWebhookEvent::dispatch($event->id)->onQueue('webhooks');
        $this->audit('webhook.reprocessed', $event, $event->type);
        $this->toast('Webhook queued for processing.');
    }

    public function render(HealthCheck $health)
    {
        return view('livewire.admin.system.operations', [
            'checks' => $this->tab === 'health' ? $health->run() : [],
            'queues' => $this->tab === 'health' ? $health->queueSizes() : [],
            'failedJobs' => $this->tab === 'jobs' ? DB::table('failed_jobs')->latest('failed_at')->paginate(20) : null,
            'webhooks' => $this->tab === 'webhooks' ? WebhookEvent::query()
                ->when($this->webhookStatus !== '', fn ($q) => $q->where('status', $this->webhookStatus))
                ->latest()->paginate(25) : null,
            'failedCount' => DB::table('failed_jobs')->count(),
            'failedWebhooks' => WebhookEvent::query()->where('status', 'failed')->count(),
        ]);
    }
}
