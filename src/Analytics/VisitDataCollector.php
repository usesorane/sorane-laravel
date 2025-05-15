<?php

namespace Sorane\ErrorReporting\Analytics;

use Illuminate\Http\Request;

class VisitDataCollector
{
    public static function collect(Request $request): array
    {
        $userAgent = $request->userAgent();
        $url = $request->fullUrl();

        return [
            'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH) ?? '/',
            'ip' => $request->ip(), // Only used internally to resolve geo
            'user_agent' => $userAgent,
            'user_agent_hash' => hash('sha256', $userAgent),

            'referrer' => $request->headers->get('referer'),

            'device_type' => self::detectDeviceType($userAgent),
            'browser_name' => self::detectBrowser($userAgent),

            'utm_source' => $request->get('utm_source'),
            'utm_medium' => $request->get('utm_medium'),
            'utm_campaign' => $request->get('utm_campaign'),
            'utm_content' => $request->get('utm_content'),
            'utm_term' => $request->get('utm_term'),

            'country_code' => self::resolveCountryFromIp($request->ip()),

            'session_id_hash' => self::generateSessionIdHash($request),

            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected static function detectDeviceType(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $ua = strtolower($userAgent);

        return match (true) {
            str_contains($ua, 'mobile') && ! str_contains($ua, 'ipad') => 'mobile',
            str_contains($ua, 'tablet') || str_contains($ua, 'ipad') => 'tablet',
            default => 'desktop',
        };
    }

    protected static function detectBrowser(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Edg') => 'Edge',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => 'Other',
        };
    }

    protected static function resolveCountryFromIp(?string $ip): ?string
    {
        return null; // We don't do this yet.

        if (! $ip) {
            return null;
        }

        return null; // Code goed here to resolve the country from the IP address.
    }

    protected static function generateSessionIdHash(Request $request): string
    {
        // Rotate daily, non-persistent session hash
        $raw = $request->ip().'|'.substr($request->userAgent(), 0, 100).'|'.now()->format('Y-m-d');

        return hash('sha256', $raw);
    }
}
