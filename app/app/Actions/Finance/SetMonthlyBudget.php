<?php

namespace App\Actions\Finance;

use App\Models\MonthlyBudget;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Sets (or, with null, removes) the spending limit of one month in one currency. */
class SetMonthlyBudget
{
    public function handle(User $actor, CarbonImmutable $month, string $currency, ?int $limitMinor): void
    {
        $key = ['user_id' => $actor->id, 'month' => $month->startOfMonth()->toDateString(), 'currency' => $currency];
        if ($limitMinor === null) {
            MonthlyBudget::where($key)->delete();

            return;
        }
        MonthlyBudget::updateOrCreate($key, ['limit_minor' => $limitMinor]);
    }
}
