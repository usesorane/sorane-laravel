<?php

namespace Sorane\ErrorReporting\Commands;

use Illuminate\Console\Command;

class SoraneTestCommand extends Command
{
    public $signature = 'sorane:test';

    public $description = 'This command will send a test message to Sorane';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
