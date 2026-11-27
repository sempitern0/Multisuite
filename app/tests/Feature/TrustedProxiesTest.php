<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind the private entry (docs/VPN.md) the portal trusts X-Forwarded-* only from the
 * configured proxy ranges; anyone else's headers are ignored.
 */
class TrustedProxiesTest extends TestCase
{
    public function test_forwarded_scheme_and_host_are_ignored_by_default(): void
    {
        $this->route();

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->get('/_test/origin', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'multisuite.example.org'])
            ->assertJsonPath('secure', false)->assertJsonMissing(['host' => 'multisuite.example.org']);
    }

    public function test_the_private_entry_is_trusted_only_from_its_ranges(): void
    {
        config(['app.trusted_proxies' => ['172.16.0.0/12', '10.0.0.0/8']]);
        $this->route();
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'multisuite.example.org'];

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->get('/_test/origin', $headers)
            ->assertExactJson(['secure' => true, 'host' => 'multisuite.example.org']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/_test/origin', $headers)
            ->assertJsonPath('secure', false)->assertJsonMissing(['host' => 'multisuite.example.org']);
    }

    private function route(): void
    {
        Route::get('/_test/origin', fn (Request $request) => ['secure' => $request->isSecure(), 'host' => $request->getHost()]);
    }
}
