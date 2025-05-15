<?php

namespace Sorane\ErrorReporting\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Sorane\ErrorReporting\Analytics\VisitDataCollector;
use Sorane\ErrorReporting\Jobs\SendPageVisitToSoraneJob;

class TrackPageVisit
{
    public function handle(Request $request, Closure $next)
    {
        // Check for internal requests
        if ($request->header('X-Client-Mode') === 'passive') {
            return $next($request);
        }

        // Excluded paths
        $excludedPaths = config('sorane.website_analytics.excluded_paths', []);
        $firstSegment = explode('/', ltrim($request->path(), '/'))[0];

        if (in_array($firstSegment, $excludedPaths, true)) {
            return $next($request);
        }

        // Is this a request from a crawler?
        $crawlerDetect = new CrawlerDetect;
        if ($crawlerDetect->isCrawler($request->userAgent())) {
            // Don't track crawlers
            return $next($request);
        }

        // Collect visit data
        $visitData = VisitDataCollector::collect($request);

        // Build a throttle key, based on the IP and path
        $cacheKey = 'sorane:visit:'.md5(
            $request->ip().'|'.
            $visitData['path'].'|'.
            now()->format('Y-m-d-H-i') // optional: bucket by minute
        );

        // Only dispatch the job if not sent recently
        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addSeconds(30));
            SendPageVisitToSoraneJob::dispatch($visitData);
        }

        return $next($request);
    }
}
