<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Entry to a service behind a Multisuite gateway (Lectura, Vídeo, Descargas): the portal posts a
 * single-use ticket to the gateway's own origin, which turns it into that service's session.
 * One place decides the origin, the HTTPS rule and the page people see while it happens.
 */
final class ServiceLaunch
{
    /**
     * Origin (scheme://host[:port]) of a service URL already validated by the caller.
     * Outside HTTPS a production portal refuses to hand tickets over (404).
     *
     * @param  array<string, int|string>  $parts  parse_url() of the service URL
     */
    public static function origin(array $parts): string
    {
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        abort_if($host === '' || ! in_array($scheme, ['http', 'https'], true) || (app()->isProduction() && $scheme !== 'https'), 404);

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** The page that submits the ticket to the gateway (by itself, or with its button). */
    public static function respond(string $name, string $eyebrow, string $origin, string $ticket): Response
    {
        return response()->view('service-launch', [
            'name' => $name,
            'eyebrow' => $eyebrow,
            'action' => $origin.'/_multisuite/launch',
            'ticket' => $ticket,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'origin');
    }
}
