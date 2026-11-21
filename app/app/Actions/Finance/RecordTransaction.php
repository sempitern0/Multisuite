<?php

namespace App\Actions\Finance;

use App\Models\PersonalTransaction;
use App\Models\User;
use App\Models\UserFile;
use App\Support\Finance\Categories;
use App\Support\Finance\MoneyFormat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or corrects one income or expense. Idempotent: the same entry key (a retried form)
 * or the same receipt (a file charged twice) returns the movement already recorded.
 */
class RecordTransaction
{
    /**
     * @param  array{kind: string, category: string, occurred_on: string, description: string, amount_minor: int, currency: string}  $data
     * @return array{transaction: PersonalTransaction, created: bool}
     */
    public function handle(User $actor, array $data, string $entryKey, ?int $receiptId = null, ?int $editingId = null): array
    {
        if (! Categories::valid($data['kind'], $data['category']) || ! isset(MoneyFormat::CURRENCIES[$data['currency']]) || $data['amount_minor'] < 1) {
            throw new InvalidArgumentException('Invalid transaction data.'); // the page validates first
        }

        return DB::transaction(function () use ($actor, $data, $entryKey, $receiptId, $editingId): array {
            if ($editingId !== null) {
                $transaction = PersonalTransaction::ownedBy($actor)->lockForUpdate()->findOrFail($editingId);
                Gate::forUser($actor)->authorize('update', $transaction);
                $transaction->fill($data)->save();

                return ['transaction' => $transaction, 'created' => false];
            }
            if ($receiptId !== null) {
                $file = UserFile::where('user_id', $actor->id)->lockForUpdate()->find($receiptId);
                if ($file === null || ! $file->isCurrent()) {
                    throw ValidationException::withMessages(['receipt' => 'La factura ya no está en Mis archivos. Guarda el movimiento sin ella o súbela de nuevo.']);
                }
            }
            $existing = $this->existing($actor, $entryKey, $receiptId);
            if ($existing !== null) {
                return ['transaction' => $existing, 'created' => false];
            }
            $transaction = new PersonalTransaction($data);
            $transaction->user_id = $actor->id;
            $transaction->entry_key = $entryKey;
            $transaction->receipt_id = $receiptId;
            try {
                // Savepoint: a concurrent insert of the same key must not abort the outer transaction.
                DB::transaction(fn () => $transaction->save());
            } catch (UniqueConstraintViolationException) {
                return ['transaction' => $this->existing($actor, $entryKey, $receiptId) ?? throw new \RuntimeException('Duplicate without a match.'), 'created' => false];
            }

            return ['transaction' => $transaction, 'created' => true];
        });
    }

    private function existing(User $actor, string $entryKey, ?int $receiptId): ?PersonalTransaction
    {
        return PersonalTransaction::ownedBy($actor)
            ->where(fn ($query) => $query->where('entry_key', $entryKey)->when($receiptId !== null, fn ($q) => $q->orWhere('receipt_id', $receiptId)))
            ->first();
    }
}
