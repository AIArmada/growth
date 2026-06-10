<?php

declare(strict_types=1);

namespace AIArmada\Growth\Enums;

enum VariantStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Deactivated = 'deactivated';
    case Retired = 'retired';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Deactivated => 'Deactivated',
            self::Retired => 'Retired',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Deactivated => 'warning',
            self::Retired => 'danger',
            self::Archived => 'gray',
        };
    }
}
