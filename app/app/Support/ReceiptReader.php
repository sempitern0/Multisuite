<?php

namespace App\Support;

use App\Services\Storage\StoredBlob;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ReceiptReader
{
    public const TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    /** @return array{amount: string, date: string} */
    public function read(StoredBlob $blob, string $mime): array
    {
        if (! in_array($mime, self::TYPES, true) || $blob->size() > 12 * 1024 * 1024) {
            throw new RuntimeException('La lectura automática admite JPG, PNG o PDF de hasta 12 MiB. Puedes introducir el gasto a mano.');
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
            throw new RuntimeException('La imagen es demasiado grande o ilegible. Puedes introducir el gasto a mano.');
        }

        return $this->suggest($this->run(['tesseract', 'stdin', 'stdout', '-l', 'spa+eng'], $bytes));
    }

    /** @param list<string> $command */
    private function run(array $command, string $input): string
    {
        $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1'], $input, 12);
        try {
            $process->mustRun();
        } catch (RuntimeException) {
            throw new RuntimeException('No se ha podido leer la factura. Puedes completar el gasto a mano.');
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
            $amount = str_contains($last, ',') ? str_replace('.', '', $last) : $last;
        }
        if (preg_match('~\b(\d{2})[/-](\d{2})[/-](\d{4})\b~', $text, $parts) && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            $date = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }

        return ['amount' => $amount, 'date' => $date];
    }
}
