<?php

declare(strict_types=1);

arch('no debug statements ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Ranetrace\Laravel')
    ->toUseStrictTypes();

arch('commands are suffixed and extend Command')
    ->expect('Ranetrace\Laravel\Commands')
    ->toHaveSuffix('Command')
    ->toExtend(Illuminate\Console\Command::class);

arch('jobs are suffixed')
    ->expect('Ranetrace\Laravel\Jobs')
    ->toHaveSuffix('Job');

arch('facades extend Facade')
    ->expect('Ranetrace\Laravel\Facades')
    ->toExtend(Illuminate\Support\Facades\Facade::class);
