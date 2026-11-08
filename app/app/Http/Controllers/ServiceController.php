<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class ServiceController extends Controller
{
    public function __invoke(string $service): RedirectResponse
    {
        $entry = config('portal.services', [])[$service] ?? null;
        abort_unless(is_array($entry) && ($entry['enabled'] ?? false), 404);

        $url = $entry['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! preg_match('/[\x00-\x20\x7f]/', $url), 404);

        return redirect()->away($url);
    }
}
