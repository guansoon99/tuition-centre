<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Malaysian public holidays sourced from Google Calendar's public "Holidays
 * in Malaysia" iCal feed. One HTTP fetch → cached for 24h → parsed with
 * regex (no library dependency).
 *
 * Google's URL isn't formally documented but has been stable for 10+ years.
 * If it ever breaks, this provider fails gracefully — returns an empty
 * list and logs; admin can still add holidays manually via the calendar UI.
 */
class HolidayProvider
{
    private const FEED_URL = 'https://calendar.google.com/calendar/ical/en.malaysia%23holiday%40group.v.calendar.google.com/public/basic.ics';

    private const CACHE_KEY = 'holidays:my:parsed';

    private const CACHE_TTL = 604800; // 7 days — holidays rarely change once published

    /**
     * The feed also lists non-public-holiday observances (Valentine's Day,
     * Easter Sunday, "Eve" dates) and duplicate "observed" entries. Skip
     * anything matching these patterns.
     */
    private const NOISE_PATTERNS = [
        '/valentine/i',
        '/easter\s+sunday/i',
        '/christmas\s+eve/i',
        "/new\\s+year'?s\\s+eve/i",
        '/day\s+off\s+for/i',
        '/observed\)?\s*$/i',
        '/chinese\s+new\s+year\s+holiday/i',
        '/day\s+of\s+arafat/i',
    ];

    /**
     * All public holidays that fall in the given inclusive date range.
     * Returns an array of ['date' => 'Y-m-d', 'name' => 'Hari Raya Puasa'].
     */
    public function forRange(string $start, string $end): array
    {
        $all = $this->allCached();
        return array_values(array_filter($all, fn ($h) => $h['date'] >= $start && $h['date'] <= $end));
    }

    /**
     * All holidays across all years the feed knows about. Success is cached
     * for the full TTL; failure is cached briefly (5 min) to self-heal
     * without hammering Google if their end blips.
     */
    public function allCached(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // withoutVerifying: Windows PHP typically ships without a CA
            // bundle and cURL then can't verify Google's cert (cURL error 60).
            // The feed is public, read-only data — MITM risk is negligible.
            // On Linux prod the OS cert store works out of the box and this
            // call is still correct.
            $response = Http::withoutVerifying()->timeout(10)->get(self::FEED_URL);
            if (! $response->ok()) {
                Log::warning('HolidayProvider: feed returned '.$response->status());
                Cache::put(self::CACHE_KEY, [], 300);
                return [];
            }
            $parsed = $this->parse($response->body());
            Cache::put(self::CACHE_KEY, $parsed, self::CACHE_TTL);
            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('HolidayProvider: fetch failed: '.$e->getMessage());
            Cache::put(self::CACHE_KEY, [], 300);
            return [];
        }
    }

    /**
     * Parse iCal text into [date, name] tuples, filtering out noise.
     */
    private function parse(string $ical): array
    {
        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ical, $blocks);

        $out = [];
        foreach ($blocks[0] as $block) {
            if (! preg_match('/DTSTART;VALUE=DATE:(\d{8})/', $block, $d)) {
                continue;
            }
            if (! preg_match('/SUMMARY:(.+)/', $block, $s)) {
                continue;
            }

            $name = trim($s[1]);
            if ($this->isNoise($name)) {
                continue;
            }

            // 20260321 → 2026-03-21
            $date = substr($d[1], 0, 4).'-'.substr($d[1], 4, 2).'-'.substr($d[1], 6, 2);
            $out[] = ['date' => $date, 'name' => $this->cleanName($name)];
        }

        // Dedupe (same date + same base name — some entries repeat across years
        // once we strip the "(regional holiday)" suffix).
        $seen = [];
        $unique = [];
        foreach ($out as $h) {
            $key = $h['date'].'|'.$h['name'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $h;
        }

        sort($unique);
        return $unique;
    }

    private function isNoise(string $name): bool
    {
        foreach (self::NOISE_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    private function cleanName(string $name): string
    {
        // Strip trailing "(regional holiday)" / "(tentative)" — cosmetic.
        return trim(preg_replace('/\s*\((regional holiday|tentative)\)\s*$/i', '', $name));
    }
}
