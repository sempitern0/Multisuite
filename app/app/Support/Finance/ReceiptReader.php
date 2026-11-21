<?php

namespace App\Support\Finance;

use App\Services\Storage\StoredBlob;
use Symfony\Component\Process\Exception\RuntimeException as ProcessFailed;
use Symfony\Component\Process\Process;

/**
 * Suggests the date and total of a receipt with local tools only (pdftotext, pdftoppm,
 * tesseract; docs/DEPENDENCIES.md). Runs in the ReadReceipt job, never in a web request.
 * Expected failures are ReceiptUnreadable, with a message for the person.
 */
final class ReceiptReader implements ReadsReceipts
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** @return array{amount: string, date: string} amount as a plain decimal ("1234.56") or '' */
    public function read(StoredBlob $blob, string $mime): array
    {
        if (! in_array($mime, self::TYPES, true) || $blob->size() > 12 * 1024 * 1024) {
            throw new ReceiptUnreadable('La lectura automática admite JPG, PNG, WebP o PDF de hasta 12 MiB. Puedes introducir el movimiento a mano.');
        }
        $bytes = $blob->prefix($blob->size());
        if ($mime === 'application/pdf') {
            $text = $this->run(['pdftotext', '-f', '1', '-l', '1', '-', '-'], $bytes);
            if (trim($text) !== '') {
                return $this->suggest($text);
            }
            $bytes = $this->run(['pdftoppm', '-f', '1', '-l', '1', '-singlefile', '-scale-to', '2000', '-png', '-'], $bytes);
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > 20000000) {
            throw new ReceiptUnreadable('La imagen es demasiado grande o ilegible. Puedes introducir el movimiento a mano.');
        }
        if ($mime === 'image/webp') {
            $bytes = $this->webpToPng($bytes); // Tesseract's image library may lack WebP; GD has it.
        }

        return $this->suggest($this->run(['tesseract', 'stdin', 'stdout', '-l', 'spa+eng'], $bytes));
    }

    private function webpToPng(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ReceiptUnreadable('La imagen es demasiado grande o ilegible. Puedes introducir el movimiento a mano.');
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param list<string> $command */
    private function run(array $command, string $input): string
    {
        $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1'], $input, 12);
        try {
            $process->mustRun();
        } catch (ProcessFailed) {
            throw new ReceiptUnreadable('No se ha podido leer la factura. Puedes completar el movimiento a mano.');
        }

        return $process->getOutput();
    }

    /** @return array{amount: string, date: string} */
    public function suggest(string $text): array
    {
        $amount = '';
        $date = '';
        if (preg_match_all('/^\h*(?:importe\h+total|total(?:\h+a\h+pagar)?)\h*[:€]?\h*(?:EUR\h*)?(\d+(?:\.\d{3})*[,\.]\d{2})\h*(?:€|EUR)?\h*$/imu', $text, $matches)) {
            $last = $matches[1][array_key_last($matches[1])];
            // "1.234,56" → "1234.56"; "1234.56" stays.
            $amount = str_contains($last, ',') ? str_replace(',', '.', str_replace('.', '', $last)) : $last;
        }
        if (preg_match('~\b(\d{2})[/-](\d{2})[/-](\d{4})\b~', $text, $parts) && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            $date = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }

        return ['amount' => $amount, 'date' => $date];
    }
}
