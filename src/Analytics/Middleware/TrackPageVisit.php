<?php

namespace Sorane\ErrorReporting\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Sorane\ErrorReporting\Analytics\Contracts\RequestFilter;
use Sorane\ErrorReporting\Analytics\VisitDataCollector;
use Sorane\ErrorReporting\Jobs\SendPageVisitToSoraneJob;

class TrackPageVisit
{
    public function handle(Request $request, Closure $next)
    {
        // Skip if the request does not have a user agent
        if (! $request->userAgent()) {
            return $next($request);
        }

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

        // Request Filter
        $filterClass = config('sorane.website_analytics.request_filter');

        if ($filterClass && class_exists($filterClass)) {
            /** @var RequestFilter $filter */
            $filter = app($filterClass);

            if ($filter->shouldSkip($request)) {
                return $next($request);
            }
        }

        // Filter out unrealistic user agents
        $userAgent = $request->userAgent();

        // Check for extremely short user agents
        if (strlen($userAgent) < 10) {
            return $next($request);
        }

        // Check for excessively long user agents
        if (strlen($userAgent) > 1000) {
            return $next($request);
        }

        // List of suspicious patterns that might indicate a fake user agent
        $suspiciousPatterns = [
            'suspicious', 'fake', 'test', 'localhost', 'postman',
            'curl/', 'wget/', 'python-requests', 'empty',
            'clearly-fake', 'not-a-browser', 'unknown',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return $next($request);
            }
        }

        // Is this a request from a crawler?
        $crawlerDetect = new CrawlerDetect;
        if ($crawlerDetect->isCrawler($request->userAgent())) {
            // Don't track crawlers
            return $next($request);
        }

        // Check if the request is from a bot we know, but are not detected by CrawlerDetect
        $extraBotUserAgents = [
            'SaaSHub',
        ];

        foreach ($extraBotUserAgents as $botUserAgent) {
            if ($crawlerDetect->isCrawler($botUserAgent)) {
                // Don't track crawlers
                return $next($request);
            }
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
