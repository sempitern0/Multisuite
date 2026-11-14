<?php

namespace Tests\Unit;

use App\Services\Storage\StorageOverview;
use App\Support\Bytes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BytesTest extends TestCase
{
    public function test_parses_human_amounts_in_binary_units(): void
    {
        $this->assertSame(5 * 1_073_741_824, Bytes::fromInput('5', 'GB'));
        $this->assertSame(1_610_612_736, Bytes::fromInput('1,5', 'GB'));
        $this->assertSame(1_610_612_736, Bytes::fromInput(' 1.5 ', 'GB'));
        $this->assertSame(0, Bytes::fromInput('0', 'MB'));
        $this->assertSame(1_099_511_627_776, Bytes::fromInput('1', 'TB'));
    }

    public function test_rejects_ambiguous_or_unsafe_input(): void
    {
        foreach ([['1e3', 'GB'], ['-1', 'GB'], ['1.234', 'GB'], ['1234567', 'GB'], ['5', 'PB'], ['abc', 'GB'], ['', 'GB']] as [$amount, $unit]) {
            try {
                Bytes::fromInput($amount, $unit);
                $this->fail("Accepted {$amount} {$unit}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_formats_for_people(): void
    {
        $this->assertSame('0 B', Bytes::format(0));
        $this->assertSame('1,5 GB', Bytes::format(1_610_612_736));
        $this->assertSame('500 GB', Bytes::format(500 * 1_073_741_824));
        $this->assertSame('Desconocido', Bytes::format(null));
        $this->assertSame(50.0, Bytes::percent(5, 10));
        $this->assertSame(0.0, Bytes::percent(5, 0));
    }

    public function test_detects_docker_desktop_virtual_machines_by_kernel_release(): void
    {
        $this->assertTrue(StorageOverview::runsInDesktopVm('6.18.33.2-microsoft-standard-WSL2'));
        $this->assertTrue(StorageOverview::runsInDesktopVm('6.10.14-linuxkit'));
        $this->assertFalse(StorageOverview::runsInDesktopVm('6.8.0-49-generic'));
    }
}
