<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Illuminate\Console\Command;
use Sorane\Laravel\Jobs\SendBatchToSoraneJob;
use Sorane\Laravel\Services\SoraneBatchBuffer;

class SoraneWorkCommand extends Command
{
    protected $signature = 'sorane:work
                            {--type= : Specific type to process (events, logs, page_visits, javascript_errors)}';

    protected $description = 'Process pending Sorane batches and send to the API';

    public function handle(SoraneBatchBuffer $buffer): int
    {
        $specificType = $this->option('type');

        $types = $specificType
            ? [$specificType]
            : ['events', 'logs', 'page_visits', 'javascript_errors'];

        $sentCount = 0;

        foreach ($types as $type) {
            $count = $buffer->count($type);

            if ($count === 0) {
                continue;
            }

            // Dispatch batch job to send items
            SendBatchToSoraneJob::dispatch($type);

            $this->info("Dispatched batch job for {$type}: {$count} items");
            $sentCount++;
        }

        if ($sentCount === 0) {
            $this->info('No batches to send.');
        } else {
            $this->info("Dispatched {$sentCount} batch job(s).");
        }

        return self::SUCCESS;
    }
}
