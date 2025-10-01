<?php

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneApiClient;

class SendJavaScriptErrorToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $errorData;

    public function __construct(array $errorData)
    {
        $this->errorData = $errorData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.javascript_errors.queue_name', 'default'));
    }

    public function handle(SoraneApiClient $client): void
    {
        $payload = $this->filterPayload($this->errorData);

        $client->sendError($payload, 'javascript');
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'message',
            'stack',
            'type',
            'filename',
            'line',
            'column',
            'user_agent',
            'url',
            'timestamp',
            'environment',
            'user_id',
            'session_id',
            'breadcrumbs',
            'context',
            'browser_info',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
