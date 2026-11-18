<?php

namespace App\Livewire\Admin\Finance;

use App\Integrations\IntegrationException;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\Plan;
use App\Services\Payments\Gateways\PaystackGateway;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Plans')]
class Plans extends Component
{
    use AdminComponent;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $amount = '';

    public ?int $savings_percent = null;

    public bool $highlighted = false;

    public bool $is_active = true;

    public int $sort = 0;

    public function mount(): void
    {
        $this->authorizeAbility('finance.view');
    }

    public function edit(string $id): void
    {
        $this->authorizeAbility('finance.manage');
        $this->resetValidation();
        $p = Plan::query()->findOrFail($id);
        $this->fill([
            'editingId' => $p->id, 'name' => $p->name, 'amount' => number_format($p->amount_kobo / 100, 2, '.', ''),
            'savings_percent' => $p->savings_percent, 'highlighted' => $p->highlighted, 'is_active' => $p->is_active, 'sort' => $p->sort, 'showForm' => true,
        ]);
    }

    public function save(): void
    {
        $this->authorizeAbility('finance.manage');
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:100', 'max:10000000'],
            'savings_percent' => ['nullable', 'integer', 'between:1,99'],
            'highlighted' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['integer', 'min:0', 'max:100'],
        ]);

        $plan = Plan::query()->findOrFail($this->editingId);
        $amountKobo = (int) round(((float) $data['amount']) * 100);
        $priceChanged = $amountKobo !== $plan->amount_kobo;

        $plan->fill([
            'name' => $data['name'],
            'amount_kobo' => $amountKobo,
            'savings_percent' => $data['savings_percent'],
            'highlighted' => $data['highlighted'],
            'is_active' => $data['is_active'],
            'sort' => $data['sort'],
        ]);
        if ($priceChanged) {
            // Paystack plan codes carry their own amount; new checkouts need a re-synced plan.
            // Existing subscribers keep their current gateway subscription until they change plan.
            $plan->paystack_plan_code = null;
        }
        $changes = array_keys($plan->getDirty());
        $plan->save();

        if ($data['highlighted']) {
            Plan::query()->whereKeyNot($plan->id)->update(['highlighted' => false]);
        }

        $this->audit('plan.updated', $plan, $plan->name, ['fields' => $changes]);
        $this->showForm = false;
        $this->toast($priceChanged ? 'Plan saved. Sync it to Paystack again so new checkouts use the new price.' : 'Plan saved.');
    }

    public function syncPaystack(string $id, PaystackGateway $paystack): void
    {
        $this->authorizeAbility('finance.manage');
        $plan = Plan::query()->findOrFail($id);

        try {
            $code = $paystack->createPlan($plan);
        } catch (IntegrationException $e) {
            $this->toast('Paystack rejected the request: '.$e->getMessage(), 'error');

            return;
        }

        $plan->forceFill(['paystack_plan_code' => $code])->save();
        $this->audit('plan.synced_paystack', $plan, $code);
        $this->toast("Created Paystack plan {$code}.");
    }

    public function render(PaystackGateway $paystack)
    {
        return view('livewire.admin.finance.plans', [
            'plans' => Plan::query()->withCount(['subscriptions as live_count' => fn ($q) => $q->where('status', '!=', 'expired')])->orderBy('sort')->get(),
            'paystackReady' => $paystack->isConfigured(),
        ]);
    }
}
