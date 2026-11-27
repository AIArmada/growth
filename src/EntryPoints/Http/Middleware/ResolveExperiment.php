<?php

declare(strict_types=1);

namespace AIArmada\Growth\EntryPoints\Http\Middleware;

use AIArmada\Growth\Actions\ResolveExperimentAssignment;
use AIArmada\Growth\EntryPoints\Http\RequestExperimentSubjectResolver;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Queries\ExperimentMiddlewareEnabled;
use AIArmada\Growth\Queries\ExperimentResolver;
use AIArmada\Growth\Support\Context\ExperimentContextManager;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class ResolveExperiment
{
    public function __construct(
        private readonly ExperimentResolver $experimentResolver,
        private readonly ResolveExperimentAssignment $resolveExperimentAssignment,
        private readonly RequestExperimentSubjectResolver $requestExperimentSubjectResolver,
        private readonly ExperimentContextManager $experimentContextManager,
        private readonly ExperimentMiddlewareEnabled $experimentMiddlewareEnabled,
    ) {}

    public function handle(Request $request, Closure $next, ?string $experimentSlug = null): Response
    {
        if (! $this->experimentMiddlewareEnabled->handle()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        try {
            $experiment = $this->experimentResolver->resolveBySlug($experimentSlug ?? '');
        } catch (InvalidArgumentException) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        } catch (AuthorizationException $exception) {
            // Fail closed deliberately: a cross-scope slug is a misconfiguration
            // or probe, not a missing experiment, so it must stay loud.
            Log::warning('Growth experiment middleware denied out-of-scope resolution.', [
                'slug' => $experimentSlug ?? '',
                'path' => $request->path(),
            ]);

            throw $exception;
        }

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
}
