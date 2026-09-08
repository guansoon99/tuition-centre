<?php

namespace App\Http\Middleware;

use App\Support\CloudflareIps;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Which upstreams may tell us the real client address and scheme.
 *
 * Behind Cloudflare every request arrives from a Cloudflare edge IP, with
 * the student's own address and the original scheme in X-Forwarded-*
 * headers. Unless the edge is trusted those headers are ignored, and two
 * things break without a word:
 *
 *   - $request->ip() is the edge, not the student. The login throttle keys
 *     on it (LoginRequest::throttleKey()), so the five-attempt lockout
 *     collapses into one bucket shared by everyone behind that edge.
 *   - $request->secure() is false, so SecurityHeaders never sends HSTS and
 *     any secure-only decision is made wrongly.
 *
 * The value comes from TRUSTED_PROXIES via config/trustedproxy.php, which
 * documents the options. Unset means trust nothing — right for staging and
 * local, where the box faces the internet directly. `cloudflare` expands to
 * the published edge ranges in CloudflareIps.
 *
 * Trusting a range is only half of it: the firewall has to admit 80/443
 * from those ranges alone, or a caller who bypasses Cloudflare can forge
 * the header from an address we would never have believed otherwise.
 */
class TrustProxies extends Middleware
{
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * The framework falls back to config('trustedproxy.proxies') when this
     * returns nothing, but it would hand the literal string "cloudflare"
     * straight to the resolver. Expand it here instead.
     */
    protected function proxies()
    {
        $configured = config('trustedproxy.proxies');

        return $configured === 'cloudflare' ? CloudflareIps::RANGES : $configured;
    }
}
