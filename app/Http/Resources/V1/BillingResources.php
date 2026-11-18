<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use App\Models\Program;
use App\Models\Subscription;
use App\Support\Iso;

final class BillingResources
{
    public static function plan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'interval' => $p->interval,
            'amount' => $p->amount_kobo,
            'currency' => $p->currency,
            'savings_percent' => $p->savings_percent,
            'highlighted' => $p->highlighted,
        ];
    }

    /** Subscription object (backend-handoff E.5). */
    public static function subscription(Subscription $s): array
    {
        $s->loadMissing(['plan', 'pendingPlan']);
        $label = $s->paymentMethodLabel();

        return [
            'id' => $s->id,
            'status' => $s->status->value,
            'plan' => self::plan($s->plan),
            'started_at' => Iso::ts($s->started_at),
            'current_period_end' => Iso::ts($s->current_period_end),
            'next_billing_date' => Iso::ts($s->next_billing_date),
            'cancelled_at' => Iso::ts($s->cancelled_at),
            'access_until' => Iso::ts($s->access_until),
            'payment_method' => $label ? ['type' => 'card', 'label' => $label] : null,
            'pending_plan' => $s->pendingPlan ? self::plan($s->pendingPlan) : null,
            'pending_plan_effective_at' => Iso::ts($s->pending_plan_effective_at),
        ];
    }

    public static function program(Program $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'date' => Iso::date($p->date),
            'hymn_ids' => array_values($p->hymn_ids ?? []),
            'updated_at' => Iso::ts($p->updated_at),
        ];
    }
}
