<?php

namespace Sorane\ErrorReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPageVisitToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $visitData;

    public function __construct(array $visitData)
    {
        $this->visitData = $visitData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.analytics.queue', 'default'));
    }

    public function handle(): void
    {
        // For now, just log the data
        Log::info('[QUEUE] Sending page visit to Sorane', $this->visitData);

        // In future: call Sorane API here
    }
}
