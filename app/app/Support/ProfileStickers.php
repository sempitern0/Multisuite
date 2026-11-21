<?php

namespace App\Support;

/** Local character pictures for the profile (public/stickers, docs/USER_MANAGEMENT.md). */
final class ProfileStickers
{
    /** Raise when an image changes: browsers cache /stickers for a long time. */
    private const VERSION = 1;

    public const LABELS = [
        'botanica' => 'Botánica curiosa',
        'panadero' => 'Panadero sonriente',
        'patinadora' => 'Patinadora atrevida',
        'astronomo' => 'Astrónomo soñador',
        'bateria' => 'Batería entusiasta',
        'lectora' => 'Lectora tranquila',
        'senderista' => 'Senderista aventurero',
        'mecanica' => 'Mecánica ingeniosa',
        'dj' => 'DJ con auriculares',
        'tejedor' => 'Tejedor paciente',
        'esgrimista' => 'Esgrimista decidida',
        'jardinero' => 'Jardinero alegre',
        'fotografa' => 'Fotógrafa',
        'marinero' => 'Marinero de perfil',
        'alfarera' => 'Alfarera de perfil',
        'violinista' => 'Violinista',
        'ciclista' => 'Ciclista de vuelta',
        'mago' => 'Mago de cartas',
    ];

    public static function url(?string $id): ?string
    {
        return $id !== null && isset(self::LABELS[$id]) ? asset('stickers/'.$id.'.webp').'?v='.self::VERSION : null;
    }
}
