<?php

namespace App\Services\Files;

use Closure;
use RuntimeException;

/**
 * Writes a ZIP archive as a stream (stored, no compression), so exports can be produced
 * straight into an encrypted file without temporary plaintext copies. Every entry uses
 * ZIP64 records and a data descriptor, which keeps one code path for files of any size;
 * ZIP64 is understood by Windows, macOS, Linux and libzip.
 */
final class ZipStreamWriter
{
    private int $offset = 0;

    /** @var list<array{name: string, crc: int, size: int, offset: int, time: int, date: int}> */
    private array $entries = [];

    /** @param  Closure(string): void  $out */
    public function __construct(private readonly Closure $out) {}

    /**
     * Adds one file; $produce receives a write callback and streams the content through it.
     *
     * @param  callable(callable(string): void): void  $produce
     */
    public function addFile(string $name, callable $produce, ?int $modifiedAt = null): void
    {
        if ($name === '' || str_contains($name, "\0") || strlen($name) > 0xFFFF) {
            throw new RuntimeException('Nombre de archivo no válido para el ZIP.');
        }
        [$time, $date] = $this->dosTime($modifiedAt ?? time());
        $offset = $this->offset;
        // Version 4.5 (ZIP64); flags: data descriptor (bit 3) + UTF-8 names (bit 11).
        $zip64 = pack('vvPP', 0x0001, 16, 0, 0);
        $this->put(pack('VvvvvvVVVvv', 0x04034B50, 45, 0x0808, 0, $time, $date, 0, 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), strlen($zip64)).$name.$zip64);

        $crc = hash_init('crc32b');
        $size = 0;
        $produce(function (string $data) use ($crc, &$size): void {
            hash_update($crc, $data);
            $size += strlen($data);
            $this->put($data);
        });
        $checksum = (int) hexdec(hash_final($crc));
        $this->put(pack('VVPP', 0x08074B50, $checksum, $size, $size));
        $this->entries[] = ['name' => $name, 'crc' => $checksum, 'size' => $size, 'offset' => $offset, 'time' => $time, 'date' => $date];
    }

    public function finish(): void
    {
        $start = $this->offset;
        foreach ($this->entries as $entry) {
            $extra = pack('vvPPP', 0x0001, 24, $entry['size'], $entry['size'], $entry['offset']);
            $this->put(pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 45, 45, 0x0808, 0, $entry['time'], $entry['date'], $entry['crc'],
                0xFFFFFFFF, 0xFFFFFFFF, strlen($entry['name']), strlen($extra), 0, 0, 0, 0, 0xFFFFFFFF).$entry['name'].$extra);
        }
        $size = $this->offset - $start;
        $end = $this->offset;
        $count = count($this->entries);
        $this->put(pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $count, $count, $size, $start));
        $this->put(pack('VVPV', 0x07064B50, 0, $end, 1));
        $this->put(pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
    }

    /** Bytes the archive adds around the content of one entry (headers, descriptor, directory). */
    public static function overhead(string $name): int
    {
        return 30 + 20 + 24 + 46 + 28 + 2 * strlen($name);
    }

    /** Bytes of the closing records. */
    public const END_BYTES = 56 + 20 + 22;

    private function put(string $bytes): void
    {
        ($this->out)($bytes);
        $this->offset += strlen($bytes);
    }

    /** @return array{int, int} */
    private function dosTime(int $timestamp): array
    {
        $parts = getdate(max($timestamp, 315532800)); // ZIP dates start in 1980.

        return [($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2),
            (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']];
    }
}
