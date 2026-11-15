<?php

namespace App\Services\Users;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Profile photos. Whatever arrives (the browser editor sends an already cropped square, a
 * plain form may send a phone photo) is decoded, cropped to a centred square and re-encoded
 * as a small WebP, so metadata such as location never survives and every photo weighs a few KB.
 * Files live on the private disk and are served only through AvatarController.
 */
final class Avatars
{
    public const SIZE = 256;

    private const DISK = 'local';

    /** Bounds GD's memory use (a decoded 4096 × 4096 picture needs about 64 MB). */
    private const MAX_SIDE = 4096;

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192']];
    }

    public function path(int $userId, string $hash): string
    {
        return 'avatars/'.$userId.'/'.$hash.'.webp';
    }

    public function exists(int $userId, string $hash): bool
    {
        return Storage::disk(self::DISK)->exists($this->path($userId, $hash));
    }

    public function absolutePath(int $userId, string $hash): string
    {
        return Storage::disk(self::DISK)->path($this->path($userId, $hash));
    }

    /** Stores the processed photo and returns its hash; the caller records it on the user. */
    public function store(int $userId, UploadedFile $file): string
    {
        $bytes = $this->render((string) $file->getRealPath());
        $hash = substr(hash('sha256', $bytes), 0, 20);
        Storage::disk(self::DISK)->put($this->path($userId, $hash), $bytes);

        return $hash;
    }

    public function delete(int $userId, string $hash): void
    {
        Storage::disk(self::DISK)->delete($this->path($userId, $hash));
    }

    public function forget(int $userId): void
    {
        Storage::disk(self::DISK)->deleteDirectory('avatars/'.$userId);
    }

    private function render(string $path): string
    {
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw ValidationException::withMessages(['avatar' => 'Elige una foto en JPG, PNG, WebP o GIF.']);
        }
        [$width, $height] = $info;
        if ($width < 64 || $height < 64) {
            throw ValidationException::withMessages(['avatar' => 'La foto es demasiado pequeña: necesita al menos 64 píxeles de lado.']);
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw ValidationException::withMessages(['avatar' => 'La foto es demasiado grande: como máximo 4096 píxeles de lado.']);
        }
        $contents = file_get_contents($path);
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['avatar' => 'No se ha podido leer la foto. Prueba con otra.']);
        }
        $side = min($width, $height);
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        if (! $canvas instanceof GdImage) {
            throw ValidationException::withMessages(['avatar' => 'No se ha podido guardar la foto. Inténtalo de nuevo.']);
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        $encoded = imagewebp($canvas, null, 82);
        $bytes = (string) ob_get_clean();
        if (! $encoded || $bytes === '') {
            throw ValidationException::withMessages(['avatar' => 'No se ha podido guardar la foto. Inténtalo de nuevo.']);
        }

        return $bytes;
    }
}
