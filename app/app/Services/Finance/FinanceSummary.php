<?php

namespace App\Services\Finance;

use App\Models\MonthlyBudget;
use App\Models\PersonalTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Totals for the year and month views. Only movements in the chosen currency are added up;
 * other currencies are listed, never converted.
 */
class FinanceSummary
{
    /**
     * @return array{
     *     months: array<int, array{income: int, expense: int, balance: int}>,
     *     income: int, expense: int, balance: int,
     *     categories: array{income: array<string, int>, expense: array<string, int>},
     *     otherCurrencies: list<string>
     * }
     */
    public function year(User $user, int $year, string $currency): array
    {
        $start = CarbonImmutable::create($year, 1, 1);
        $rows = PersonalTransaction::ownedBy($user)
            ->where('occurred_on', '>=', $start->toDateString())->where('occurred_on', '<', $start->addYear()->toDateString())
            ->selectRaw('occurred_on, kind, category, currency, sum(amount_minor) as total')
            ->groupBy('occurred_on', 'kind', 'category', 'currency')
            ->toBase()->get();

        $months = array_fill(1, 12, ['income' => 0, 'expense' => 0, 'balance' => 0]);
        $categories = ['income' => [], 'expense' => []];
        $others = [];
        foreach ($rows as $row) {
            if ($row->currency !== $currency) {
                $others[$row->currency] = true;

                continue;
            }
            $kind = $row->kind === 'income' ? 'income' : 'expense';
            $month = (int) substr((string) $row->occurred_on, 5, 2);
            $months[$month][$kind] += (int) $row->total;
            $categories[$kind][$row->category] = ($categories[$kind][$row->category] ?? 0) + (int) $row->total;
        }
        foreach ($months as $number => $totals) {
            $months[$number]['balance'] = $totals['income'] - $totals['expense'];
        }
        arsort($categories['income']);
        arsort($categories['expense']);
        $income = array_sum(array_column($months, 'income'));
        $expense = array_sum(array_column($months, 'expense'));

        return ['months' => $months, 'income' => $income, 'expense' => $expense, 'balance' => $income - $expense,
            'categories' => $categories, 'otherCurrencies' => array_keys($others)];
    }

    public function budget(User $user, CarbonImmutable $month, string $currency): ?int
    {
        $limit = MonthlyBudget::where('user_id', $user->id)->where('month', $month->startOfMonth()->toDateString())
            ->where('currency', $currency)->value('limit_minor');

        return $limit === null ? null : (int) $limit;
    }
}
