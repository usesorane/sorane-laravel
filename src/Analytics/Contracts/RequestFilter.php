<?php

namespace Sorane\Laravel\Analytics\Contracts;

use Illuminate\Http\Request;

interface RequestFilter
{
    public function shouldSkip(Request $request): bool;
}
