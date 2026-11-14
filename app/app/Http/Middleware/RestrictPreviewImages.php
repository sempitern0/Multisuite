<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * File previews render the person's own Markdown: images may only come from this
 * site, so opening a document can never call a remote server (tracking pixels).
 */
class RestrictPreviewImages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "img-src 'self' data:");

        return $response;
    }
}
