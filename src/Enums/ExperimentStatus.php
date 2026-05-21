<?php

declare(strict_types=1);

namespace AIArmada\Growth\Enums;

enum ExperimentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Concluded = 'concluded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Concluded => 'Concluded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Concluded => 'primary',
        };
    }
}
