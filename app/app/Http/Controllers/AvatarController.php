<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Users\Avatars;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves profile photos from the private disk. The address carries the photo's hash, so the
 * browser may keep it for a year: a new photo gets a new address.
 */
class AvatarController extends Controller
{
    public function __invoke(int $user, string $hash, Avatars $avatars): BinaryFileResponse
    {
        $person = User::findOrFail($user);
        Gate::authorize('viewAvatar', $person);
        abort_unless($person->avatar_hash === $hash && $avatars->exists($person->id, $hash), 404);

        return response()->file($avatars->absolutePath($person->id, $hash), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
