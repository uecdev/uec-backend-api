<?php

namespace App\Livewire\Admin\System;

use App\Integrations\IntegrationRegistry;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\IntegrationLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Integrations')]
class Integrations extends Component
{
    use AdminComponent, WithPagination;

    #[Url(except: '')]
    public string $logIntegration = '';

    #[Url(except: '')]
    public string $logOutcome = '';

    /** @var array<string, array{ok: bool, message: string, at: string}> */
    public array $results = [];

    public string $testEmail = '';

    public function mount(): void
    {
        $this->authorizeAbility('system.integrations');
        $this->testEmail = (string) auth()->user()->email;
    }

    public function test(string $integration, IntegrationRegistry $registry): void
    {
        $this->authorizeAbility('system.integrations');
        if ($integration === 'zeptomail') {
            $this->validate(['testEmail' => ['required', 'email']]);
        }
        [$ok, $message] = $registry->test($integration, $this->testEmail);
        $this->results[$integration] = ['ok' => $ok, 'message' => $message, 'at' => now()->format('H:i:s')];
        $this->audit('integration.tested', null, $integration, ['ok' => $ok]);
    }

    public function render(IntegrationRegistry $registry)
    {
        $integrations = $registry->all();

        return view('livewire.admin.system.integrations', [
            'integrations' => $integrations,
            'stats' => collect(array_keys($integrations))->mapWithKeys(fn ($k) => [$k => $registry->stats($k)])->all(),
            'logs' => IntegrationLog::query()
                ->when($this->logIntegration !== '', fn ($q) => $q->where('integration', $this->logIntegration))
                ->when($this->logOutcome !== '', fn ($q) => $q->where('success', $this->logOutcome === 'ok'))
                ->latest('id')->paginate(25),
        ]);
    }
}
