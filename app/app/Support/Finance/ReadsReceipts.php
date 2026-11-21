<?php

namespace App\Support\Finance;

use App\Services\Storage\StoredBlob;

/** Suggests date and total of a receipt. Bound to ReceiptReader; tests bind their own. */
interface ReadsReceipts
{
    /**
     * @return array{amount: string, date: string} amount as a plain decimal ("1234.56") or ''
     *
     * @throws ReceiptUnreadable with a message for the person
     */
    public function read(StoredBlob $blob, string $mime): array;
}
