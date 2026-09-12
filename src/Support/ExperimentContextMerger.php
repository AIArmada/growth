<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support;

use Illuminate\Database\Eloquent\Model;

final class ExperimentContextMerger
{
    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function merge(Model $source, array $properties): array
    {
        $contexts = $this->mergeContexts(
            $this->normalizeContexts($properties['experiment_contexts'] ?? null),
            $this->normalizeContexts($properties),
            $this->explicitContexts($source),
        );

        if ($contexts === []) {
            return $properties;
        }

        $primaryContext = $contexts[0];

        return array_merge(
            $properties,
            array_merge($primaryContext, [
                'experiment_contexts' => $contexts,
            ]),
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function explicitContexts(Model $source): array
    {
        return $this->mergeContexts(
            $this->normalizeContexts(data_get($this->attributeValue($source, 'billing_data'), 'metadata.experiment_contexts')),
            $this->normalizeContexts(data_get($this->attributeValue($source, 'payment_data'), 'experiment_contexts')),
            $this->normalizeContexts(data_get($this->attributeValue($source, 'payment_data'), 'metadata.experiment_contexts')),
            $this->normalizeContexts(data_get($this->attributeValue($source, 'metadata'), 'experiment_contexts')),
            $this->normalizeContexts(data_get($this->attributeValue($source, 'metadata'), 'payment_data.experiment_contexts')),
            $this->normalizeContexts(data_get($this->attributeValue($source, 'metadata'), 'billing_data.metadata.experiment_contexts')),
        );
    }

    private function attributeValue(Model $source, string $attribute): mixed
    {
        if (! array_key_exists($attribute, $source->getAttributes())) {
            return null;
        }

        return $source->getAttribute($attribute);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return list<array<string, string>>
     */
    private function normalizeContexts(mixed $contexts): array
    {
        if (! is_array($contexts)) {
            return [];
        }

        $normalizedContext = $this->normalizeContext($contexts);

        if ($normalizedContext !== null) {
            return [$normalizedContext];
        }

        $normalizedContexts = [];

        foreach ($contexts as $context) {
            $normalizedContext = $this->normalizeContext($context);

            if ($normalizedContext === null) {
                continue;
            }

            $normalizedContexts[] = $normalizedContext;
        }

        return $normalizedContexts;
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeContext(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $experimentId = data_get($context, 'experiment_id');
        $variantId = data_get($context, 'variant_id');

        if (! is_scalar($experimentId) || ! is_scalar($variantId)) {
            return null;
        }

        $normalizedContext = [
            'experiment_id' => (string) $experimentId,
            'variant_id' => (string) $variantId,
        ];

        foreach (['experiment_slug', 'variant_code', 'assignment_id', 'module_type'] as $key) {
            $value = data_get($context, $key);

            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $normalizedContext[$key] = (string) $value;
        }

        return $normalizedContext;
    }

    /**
     * @param  list<array<string, string>>  ...$groups
     * @return list<array<string, string>>
     */
    private function mergeContexts(array ...$groups): array
    {
        $mergedContexts = [];

        foreach ($groups as $group) {
            foreach ($group as $context) {
                $mergedContexts[$context['experiment_id']] = array_merge(
                    $mergedContexts[$context['experiment_id']] ?? [],
                    $context,
                );
            }
        }

        return array_values($mergedContexts);
    }
}
