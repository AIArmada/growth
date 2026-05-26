<?php

declare(strict_types=1);

namespace AIArmada\Growth\Settings;

use Spatie\LaravelSettings\Settings;

class GrowthSettings extends Settings
{
    public bool $experimentMiddlewareEnabled;

    public static function group(): string
    {
        return 'growth';
    }
}
