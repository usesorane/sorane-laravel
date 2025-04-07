<?php

namespace Sorane\ErrorReporting\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sorane\ErrorReporting\Analytics\VisitDataCollector;
use Sorane\ErrorReporting\Jobs\SendPageVisitToSoraneJob;

class TrackPageVisit
{
    public function handle(Request $request, Closure $next)
    {
        $visitData = VisitDataCollector::collect($request);

        SendPageVisitToSoraneJob::dispatch($visitData);

        return $next($request);
    }
}
