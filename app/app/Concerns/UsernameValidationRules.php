<?php

namespace App\Concerns;

use Illuminate\Validation\Rule;

trait UsernameValidationRules
{
    /** @return array<int, mixed> */
    protected function usernameRules(): array
    {
        return ['nullable', 'string', 'min:3', 'max:32', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/', Rule::unique('users', 'username')];
    }
}
