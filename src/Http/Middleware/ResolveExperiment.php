<?php

declare(strict_types=1);

namespace AIArmada\Growth\Http\Middleware;

use AIArmada\Growth\Actions\ResolveExperimentAssignment;
use AIArmada\Growth\Actions\ResolveReadableExperimentBySlug;
use AIArmada\Growth\Contracts\RequestExperimentSubjectResolver;
use AIArmada\Growth\Support\ExperimentContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveExperiment
{
    public function __construct(
        private readonly ResolveReadableExperimentBySlug $resolveReadableExperimentBySlug,
        private readonly ResolveExperimentAssignment $resolveExperimentAssignment,
        private readonly RequestExperimentSubjectResolver $requestExperimentSubjectResolver,
        private readonly ExperimentContextManager $experimentContextManager,
    ) {}

    public function handle(Request $request, Closure $next, ?string $experimentSlug = null): Response
    {
        if (! config('growth.features.experiment_middleware.enabled', false)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $experiment = $this->resolveReadableExperimentBySlug->handle($experimentSlug ?? '');
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
}
