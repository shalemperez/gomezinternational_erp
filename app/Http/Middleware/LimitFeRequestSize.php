<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps anonymous DGII/PSFE requests before any signature or XSD work (GC-26).
 */
class LimitFeRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $upload = $request->file('xml');
        $input = $request->input('xml');

        $largestBytes = max(
            (int) $request->server('CONTENT_LENGTH', 0),
            strlen((string) $request->getContent()),
            $upload instanceof UploadedFile ? (int) $upload->getSize() : 0,
            is_string($input) ? strlen($input) : 0,
        );

        if ($largestBytes > (int) config('ecf.receiver.max_bytes')) {
            return response('Solicitud demasiado grande', 413);
        }

        return $next($request);
    }
}
