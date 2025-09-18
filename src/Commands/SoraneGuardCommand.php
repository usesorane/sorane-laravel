<?php

namespace Sorane\ErrorReporting\Commands;

use Illuminate\Console\Command;
use Sorane\ErrorReporting\Guard\GuardChecks;

class SoraneGuardCommand extends Command
{
    public $signature = 'sorane:guard {--json : Output results as JSON}';

    public $description = 'Run proactive Sorane guard checks to ensure your app is production-ready.';

    public function handle(GuardChecks $guard): int
    {
        $results = $guard->run();

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($r) => $r->toArray(), $results), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $rows = [];
        $passedCount = 0;
        foreach ($results as $res) {
            $rows[] = [
                $res->id,
                $res->description,
                $res->passed ? 'PASS' : 'FAIL',
                is_array($res->current) ? json_encode($res->current) : var_export($res->current, true),
                is_array($res->expected) ? json_encode($res->expected) : var_export($res->expected, true),
                $res->severity,
            ];
            if ($res->passed) {
                $passedCount++;
            }
        }

        $this->table(['ID', 'Description', 'Result', 'Current', 'Expected', 'Severity'], $rows);

        $failed = count($results) - $passedCount;
        if ($failed > 0) {
            $this->newLine();
            $this->warn('Recommendations:');
            foreach ($results as $res) {
                if (! $res->passed) {
                    $rec = $res->recommendation ?: 'Review the configuration.';
                    $extra = $res->helpUrl ? ' ('.$res->helpUrl.')' : '';
                    $this->line(" - {$res->id}: {$rec}{$extra}");
                }
            }
        }

        $this->newLine();
        $this->info("{$passedCount}/".count($results).' checks passed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
