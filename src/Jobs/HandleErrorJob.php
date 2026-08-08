<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandleErrorJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $errorData
    ) {
        $this->assignQueue();
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->errorData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $this->bufferOrRelease($buffer, 'errors', $payload);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.errors';
    }

    /**
     * @return array<int, string>
     */
    protected function getAllowedKeys(): array
    {
        return [
            'message',
            'file',
            'line',
            'type',
            'environment',
            'trace',
            'headers',
            'context',
            'highlight_line',
            'user',
            'timestamp',
            'url',
            'method',
            'php_version',
            'framework',
            'framework_version',
            'is_console',
            'console_command',
            'console_arguments',
        ];
    }
}
