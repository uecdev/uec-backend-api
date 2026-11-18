<?php

namespace Database\Seeders;

use App\Models\HymnCategory;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Reference data defined by the design / handoff (not sample content):
 *  - subscription plans (backend-handoff E.2; design "Explore plan")  [CONFIRM J.1 #3]
 *  - hymn categories (B.5)                                             [CONFIRM final list]
 * Existing rows are never overwritten, so admin edits survive re-seeding.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['id' => 'plan_monthly', 'name' => 'Monthly', 'interval' => 'month', 'interval_months' => 1, 'amount_kobo' => 250000, 'savings_percent' => null, 'highlighted' => false, 'sort' => 1],
            ['id' => 'plan_six_months', 'name' => '6 Months', 'interval' => 'six_months', 'interval_months' => 6, 'amount_kobo' => 1200000, 'savings_percent' => null, 'highlighted' => false, 'sort' => 2],
            ['id' => 'plan_yearly', 'name' => 'Yearly', 'interval' => 'year', 'interval_months' => 12, 'amount_kobo' => 1990000, 'savings_percent' => 34, 'highlighted' => true, 'sort' => 3],
        ];
        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(['id' => $plan['id']], $plan + ['currency' => 'NGN', 'is_active' => true]);
        }

        $sort = 0;
        foreach (config('uec.content.hymn_categories') as $slug => $name) {
            HymnCategory::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'sort' => ++$sort]);
        }
    }
}
