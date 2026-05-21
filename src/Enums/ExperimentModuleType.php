<?php

declare(strict_types=1);

namespace AIArmada\Growth\Enums;

use Illuminate\Support\Str;

enum ExperimentModuleType: string
{
    case AbTest = 'ab_test';
    case SalesPageTest = 'sales_page_test';
    case FunnelTest = 'funnel_test';
    case PricingTest = 'pricing_test';

    public function label(): string
    {
        return match ($this) {
            self::AbTest => 'A/B Test',
            self::SalesPageTest => 'Sales Page Test',
            self::FunnelTest => 'Funnel Test',
            self::PricingTest => 'Pricing Test',
        };
    }

    /**
     * @return array{
     *     goal_event_name: string,
     *     goal_event_category: string,
     *     winner_metric: string,
     *     settings: array<string, mixed>
     * }
     */
    public function preset(): array
    {
        $preset = config("growth.defaults.presets.{$this->value}", []);

        return [
            'goal_event_name' => (string) ($preset['goal_event_name'] ?? config('growth.integrations.signals.purchase_event_name', 'order.paid')),
            'goal_event_category' => (string) ($preset['goal_event_category'] ?? 'conversion'),
            'winner_metric' => (string) ($preset['winner_metric'] ?? config('growth.defaults.winner_metric', 'revenue_per_visitor')),
            'settings' => is_array($preset['settings'] ?? null) ? $preset['settings'] : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $moduleType): array => [$moduleType->value => $moduleType->label()])
            ->all();
    }

    public static function fromValue(?string $value): self
    {
        $default = config('growth.defaults.module_type', self::AbTest->value);

        return self::tryFrom((string) $value)
            ?? self::tryFrom(is_string($default) ? $default : self::AbTest->value)
            ?? self::AbTest;
    }

    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::default()->label();
        }

        return self::tryFrom($value)?->label() ?? Str::headline(str_replace('_', ' ', $value));
    }

    public static function default(): self
    {
        return self::fromValue((string) config('growth.defaults.module_type', self::AbTest->value));
    }
}
