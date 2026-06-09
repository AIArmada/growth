<?php

declare(strict_types=1);

namespace AIArmada\Growth\Enums;

enum ResolveStrategy: string
{
    case Accessible = 'accessible';
    case Readable = 'readable';
}
