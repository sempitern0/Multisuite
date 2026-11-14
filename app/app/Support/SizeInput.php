<?php

namespace App\Support;

use App\Services\Storage\StorageReservations;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A size chosen by a non-technical person: a preset in bytes, or "custom" with an
 * amount and a unit. Turns that input into bytes or a validation error on `$field`.
 */
final class SizeInput
{
    /**
     * @param  array<string, mixed>  $input  preset, amount, unit
     *
     * @throws ValidationException
     */
    public static function bytes(array $input, string $field): int
    {
        $data = Validator::make($input, [
            'preset' => ['required', 'string', 'regex:/^(custom|0|[1-9][0-9]{0,15})$/D'],
            'amount' => ['nullable', 'required_if:preset,custom', 'string', 'max:12'],
            'unit' => ['nullable', 'required_if:preset,custom', 'string', 'in:'.implode(',', array_keys(Bytes::UNITS))],
        ], [
            'preset.required' => 'Elige una cantidad.',
            'amount.required_if' => 'Escribe la cantidad.',
        ])->validate();
        try {
            $bytes = $data['preset'] === 'custom'
                ? Bytes::fromInput((string) $data['amount'], (string) $data['unit'])
                : (int) $data['preset'];
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
        if ($bytes > StorageReservations::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'La cantidad supera el máximo admitido.']);
        }

        return $bytes;
    }
}
