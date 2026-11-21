<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spending limit of one person for one month in one currency.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month first day, Y-m-d
 * @property string $currency
 * @property int $limit_minor
 */
class MonthlyBudget extends Model
{
    protected $fillable = ['user_id', 'month', 'currency', 'limit_minor'];

    protected function casts(): array
    {
        return ['limit_minor' => 'integer'];
    }
}
