<?php

declare(strict_types=1);

namespace AIArmada\Growth\Http\Middleware;

use AIArmada\Growth\Actions\ResolveAccessibleExperimentBySlug;
use AIArmada\Growth\Actions\ResolveExperimentAssignment;
use AIArmada\Growth\Contracts\RequestExperimentSubjectResolver;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Settings\GrowthSettings;
use AIArmada\Growth\Support\ExperimentContextManager;
use Closure;
use Illuminate\Http\Request;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Symfony\Component\HttpFoundation\Response;

final class ResolveExperiment
{
    public function __construct(
        private readonly ResolveAccessibleExperimentBySlug $resolveAccessibleExperimentBySlug,
        private readonly ResolveExperimentAssignment $resolveExperimentAssignment,
        private readonly RequestExperimentSubjectResolver $requestExperimentSubjectResolver,
        private readonly ExperimentContextManager $experimentContextManager,
    ) {}

    public function handle(Request $request, Closure $next, ?string $experimentSlug = null): Response
    {
        if (! $this->isExperimentMiddlewareEnabled()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $experiment = $this->resolveAccessibleExperimentBySlug->handle($experimentSlug ?? '');

        if ($experiment->status !== ExperimentStatus::Active) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $subjects = $this->requestExperimentSubjectResolver->resolve($request, $experiment);
        $assignment = $this->resolveExperimentAssignment->handle(
            $experiment,
            identity: $subjects->identity,
            session: $subjects->session,
            anonymousId: $subjects->anonymousId,
        );

        $this->experimentContextManager->store($request, $experiment, $assignment);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExperimentMiddlewareEnabled(): bool
    {
        if (! $this->hasConfiguredSettingsRepository()) {
            return (bool) config('growth.features.experiment_middleware.enabled', false);
        }

        try {
            return app(GrowthSettings::class)->experimentMiddlewareEnabled;
        } catch (MissingSettings) {
            return (bool) config('growth.features.experiment_middleware.enabled', false);
        }
    }

    private function hasConfiguredSettingsRepository(): bool
    {
        $defaultRepository = config('settings.default_repository');
        $repositories = config('settings.repositories');

        return is_string($defaultRepository)
            && $defaultRepository !== ''
            && is_array($repositories)
            && array_key_exists($defaultRepository, $repositories);
    }
}
