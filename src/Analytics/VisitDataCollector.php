<?php

namespace Sorane\ErrorReporting\Analytics;

use Illuminate\Http\Request;

class VisitDataCollector
{
    public static function collect(Request $request): array
    {
        return [
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->headers->get('referer'),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
