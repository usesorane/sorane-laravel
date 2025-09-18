<?php

namespace Sorane\ErrorReporting\Diagnostics;

class CheckResult
{
    public function __construct(
        public string $id,
        public string $description,
        public bool $passed,
        public mixed $current,
        public mixed $expected,
        public string $severity = 'warning',
        public ?string $recommendation = null,
        public ?string $helpUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'passed' => $this->passed,
            'current' => $this->current,
            'expected' => $this->expected,
            'severity' => $this->severity,
            'recommendation' => $this->recommendation,
            'helpUrl' => $this->helpUrl,
        ];
    }
}
