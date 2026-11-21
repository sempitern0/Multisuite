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

        $income = array_fill(1, 12, 0);
        $expense = array_fill(1, 12, 0);
        /** @var array{income: array<string, int>, expense: array<string, int>} $categories */
        $categories = ['income' => [], 'expense' => []];
        $others = [];
        foreach ($rows as $row) {
            if ($row->currency !== $currency) {
                $others[] = (string) $row->currency;

                continue;
            }
            $total = (int) $row->total;
            $month = (int) substr((string) $row->occurred_on, 5, 2);
            $key = (string) $row->category;
            if ($row->kind === 'income') {
                $income[$month] += $total;
                $categories['income'][$key] = ($categories['income'][$key] ?? 0) + $total;
            } else {
                $expense[$month] += $total;
                $categories['expense'][$key] = ($categories['expense'][$key] ?? 0) + $total;
            }
        }
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[$number] = ['income' => $income[$number], 'expense' => $expense[$number], 'balance' => $income[$number] - $expense[$number]];
        }
        arsort($categories['income']);
        arsort($categories['expense']);
        $totalIncome = array_sum($income);
        $totalExpense = array_sum($expense);

        return ['months' => $months, 'income' => $totalIncome, 'expense' => $totalExpense, 'balance' => $totalIncome - $totalExpense,
            'categories' => $categories, 'otherCurrencies' => array_values(array_unique($others))];
    }

    public function budget(User $user, CarbonImmutable $month, string $currency): ?int
    {
        $limit = MonthlyBudget::where('user_id', $user->id)->where('month', $month->startOfMonth()->toDateString())
            ->where('currency', $currency)->value('limit_minor');

        return $limit === null ? null : (int) $limit;
    }
}
