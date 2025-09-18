<?php

namespace Sorane\ErrorReporting\Guard;

use Sorane\ErrorReporting\Diagnostics\CheckResult;
use Sorane\ErrorReporting\Diagnostics\ConfigBestPracticesChecker;

class GuardChecks
{
    public function __construct(
        private ConfigBestPracticesChecker $configChecks,
        private SecurityChecks $securityChecks,
        private OpsChecks $opsChecks,
    ) {}

    /**
     * Run all guard checks and return results.
     *
     * @return array<int, CheckResult>
     */
    public function run(): array
    {
        $results = [];

        // Configuration best-practice checks
        $results = array_merge($results, $this->configChecks->run());

        // Security checks
        $results = array_merge($results, $this->securityChecks->run());

        // Operational checks
        $results = array_merge($results, $this->opsChecks->run());

        return $results;
    }
}
