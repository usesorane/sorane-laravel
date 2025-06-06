<?php

namespace Sorane\ErrorReporting\Analytics;

use Illuminate\Http\Request;

class FingerprintGenerator
{
    /**
     * Generate a session ID hash for linking visits and events
     * This uses the same logic as VisitDataCollector for consistency
     */
    public static function generateSessionIdHash(?Request $request = null): string
    {
        $request = $request ?: request();
        
        // Rotate daily, non-persistent session hash
        $raw = $request->ip() . '|' . substr($request->userAgent() ?? '', 0, 100) . '|' . now()->format('Y-m-d');

        return hash('sha256', $raw);
    }

    /**
     * Generate a user agent hash
     */
    public static function generateUserAgentHash(?Request $request = null): ?string
    {
        $request = $request ?: request();
        $userAgent = $request->userAgent();
        
        return $userAgent ? hash('sha256', $userAgent) : null;
    }
}
