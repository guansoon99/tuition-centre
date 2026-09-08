<?php

namespace App\Support;

/**
 * The address ranges Cloudflare's edge connects from.
 *
 * Published at https://www.cloudflare.com/ips-v4 and /ips-v6. They change
 * rarely — a handful of times in a decade — but they do change, so compare
 * against those two URLs when setting up a new box, and again if
 * $request->ip() ever starts returning a 104.x or 172.6x address in
 * production, which is the symptom of a range missing from this list.
 *
 * Snapshot taken 2026-09-05.
 */
final class CloudflareIps
{
    public const RANGES = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}
