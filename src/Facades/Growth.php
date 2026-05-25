<?php

declare(strict_types=1);

namespace AIArmada\Growth\Facades;

use AIArmada\Growth\Support\ExperimentContextManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null variantCode()
 * @method static string|null experimentSlug()
 * @method static string|null assignmentId()
 * @method static bool isVariant(string $code)
 * @method static bool isControl()
 * @method static \AIArmada\Growth\Support\ExperimentContext|null current()
 * @method static void store(\Illuminate\Http\Request $request, \AIArmada\Growth\Models\Experiment $experiment, \AIArmada\Growth\Models\Assignment $assignment)
 * @method static \AIArmada\Growth\Support\ExperimentContext|null resolve(\Illuminate\Http\Request $request)
 *
 * @see ExperimentContextManager
 */
final class Growth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'growth';
    }
}
