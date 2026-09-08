<?php

namespace Tests\Feature\Security;

use App\Support\CloudflareIps;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Which upstreams are believed about the real client address and scheme.
 *
 * Behind Cloudflare, $request->ip() is the edge unless the edge is trusted —
 * and the login throttle keys on ip(), so the five-attempt lockout would
 * collapse into one bucket for everyone behind that edge. Trusting the
 * wrong thing is as bad in the other direction: trust everyone, and a caller
 * who reaches the origin directly can forge the header.
 *
 * The probe route echoes what the application actually believes after the
 * middleware has run, which is the only thing worth asserting.
 */
class TrustedProxiesTest extends TestCase
{
    /** A Cloudflare edge address: 104.16.0.0/13. */
    private const EDGE = '104.16.1.1';

    private const STUDENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_proxy-probe', fn () => response()->json([
            'ip' => request()->ip(),
            'secure' => request()->secure(),
        ]));
    }

    private function probe(string $from, array $forwarded = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders($forwarded)
            ->get('/_proxy-probe');
    }

    private function forwardedFromStudent(): array
    {
        return ['X-Forwarded-For' => self::STUDENT, 'X-Forwarded-Proto' => 'https'];
    }

    // ---- Unset: the staging and local case ----------------------------------

    /** A box facing the internet directly must ignore the header from anyone. */
    public function test_with_nothing_configured_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->probe(self::EDGE, $this->forwardedFromStudent())
            ->assertJson(['ip' => self::EDGE, 'secure' => false]);
    }

    // ---- cloudflare: the production case ------------------------------------

    public function test_cloudflare_mode_believes_a_cloudflare_edge(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->probe(self::EDGE, $this->forwardedFromStudent())
            ->assertJson(['ip' => self::STUDENT, 'secure' => true]);
    }

    /**
     * The half that protects the throttle. Someone who bypasses Cloudflare
     * and speaks to the origin directly must not be able to choose the
     * address the lockout is keyed on.
     */
    public function test_cloudflare_mode_ignores_the_header_from_anyone_else(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->probe('198.51.100.7', $this->forwardedFromStudent())
            ->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    /** IPv6 edges are in the list too. */
    public function test_cloudflare_mode_believes_an_ipv6_edge(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->probe('2606:4700::1', $this->forwardedFromStudent())
            ->assertJson(['ip' => self::STUDENT, 'secure' => true]);
    }

    /**
     * The visible consequence of secure(): HSTS is only sent over TLS, and
     * behind the proxy TLS is something the edge tells us about.
     */
    public function test_hsts_is_sent_once_the_edge_reports_https(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->probe(self::EDGE, $this->forwardedFromStudent())
            ->assertHeader('Strict-Transport-Security');

        $this->probe(self::EDGE, ['X-Forwarded-For' => self::STUDENT, 'X-Forwarded-Proto' => 'http'])
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    // ---- The list itself ----------------------------------------------------

    /** Every entry must be a CIDR, or the resolver silently trusts nothing. */
    public function test_every_cloudflare_range_is_a_valid_cidr(): void
    {
        foreach (CloudflareIps::RANGES as $range) {
            $this->assertMatchesRegularExpression('#^[0-9a-f.:]+/\d{1,3}$#i', $range, $range);
            [$ip, $bits] = explode('/', $range);
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), $range);
        }
    }

    // ---- Explicit lists, for a box behind something that is not Cloudflare --

    /**
     * One request per test from here on, deliberately. After a request the
     * harness's URL generator remembers its scheme, so a second probe in the
     * same test is built as https:// and arrives with HTTPS=on — an untrusted
     * caller then reads as secure for reasons that have nothing to do with
     * the middleware. Keeping trusted and untrusted in separate tests keeps
     * each assertion about what it claims to be about.
     */
    public function test_an_explicit_list_is_honoured(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5,10.0.0.6']);

        $this->probe('10.0.0.6', $this->forwardedFromStudent())
            ->assertJson(['ip' => self::STUDENT, 'secure' => true]);
    }

    public function test_an_address_outside_an_explicit_list_is_not_trusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5,10.0.0.6']);

        $this->probe('10.0.0.7', $this->forwardedFromStudent())
            ->assertJson(['ip' => '10.0.0.7', 'secure' => false]);
    }
}
