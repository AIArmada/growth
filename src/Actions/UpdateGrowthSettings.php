<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Settings\GrowthSettings;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

final readonly class UpdateGrowthSettings
{
    public static function canWriteInCurrentContext(): bool
    {
        return OwnerContext::isExplicitGlobal()
            || (! Experiment::ownerScopeConfig()->enabled && ! TrackedProperty::ownerScopeConfig()->enabled);
    }

    public function handle(bool $enabled): void
    {
        if (! self::canWriteInCurrentContext()) {
            throw new AuthorizationException('Growth settings are global and require explicit global owner context.');
        }

        try {
            $settings = app(GrowthSettings::class);
            if ($settings->experimentMiddlewareEnabled === $enabled) {
                return;
            }
        } catch (MissingSettings) {
            $settings = new GrowthSettings(['experimentMiddlewareEnabled' => $enabled]);
        }

        $settings->experimentMiddlewareEnabled = $enabled;
        $settings->save();
    }
}
