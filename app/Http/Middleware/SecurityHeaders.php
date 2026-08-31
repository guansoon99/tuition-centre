<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers the browser uses to constrain the page.
 *
 * Deliberately no Content-Security-Policy. A useful one is not a one-line
 * addition here: the layouts pull Quill, flatpickr and tom-select from
 * jsdelivr, and Alpine's x-data / @click attributes are inline handlers that
 * need 'unsafe-inline', while Alpine's expression evaluator needs
 * 'unsafe-eval' unless the CSP build is adopted. A policy loose enough to
 * keep the app working would block almost nothing, so CSP is left as its own
 * piece of work rather than shipped as decoration.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stop the browser second-guessing Content-Type. Matters most for the
        // file endpoints, which hand back whatever a teacher or student
        // uploaded — sniffing could turn a stored file into a script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking: the app is never framed by anything, including itself
        // across origins.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Send the full URL only to ourselves. Course and material paths carry
        // ids that outside sites have no reason to receive as a Referer.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HTTPS only. Sending it over plain HTTP has no effect anyway, and the
        // guard keeps a locally served HTTP page from pinning the browser.
        // One year, without includeSubDomains or preload — both are hard to
        // walk back and neither is wanted until a domain is actually settled.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
