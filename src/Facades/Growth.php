<?php

declare(strict_types=1);

namespace AIArmada\Growth\Facades;

use Illuminate\Support\Facades\Facade;

final class Growth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'growth';
    }
}
