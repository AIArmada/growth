<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Http;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Growth\Contracts\RequestExperimentSubjectResolver;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Support\RequestExperimentSubjects;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class DefaultRequestExperimentSubjectResolver implements RequestExperimentSubjectResolver
{
    public function __construct(
        private readonly ScopeSignalQueryToOwner $scopeSignalQueryToOwner,
    ) {}

    public function resolve(Request $request, Experiment $experiment): RequestExperimentSubjects
    {
        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        return new RequestExperimentSubjects(
            identity: $this->resolveIdentity($request, $experiment, $owner),
            session: $this->resolveSession($request, $experiment, $owner),
            anonymousId: $this->resolveAnonymousId($request),
        );
    }

    private function resolveIdentity(Request $request, Experiment $experiment, ?Model $owner): ?SignalIdentity
    {
        $user = $this->resolveUser($request);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $userIdentifier = $this->stringValue($user->getAuthIdentifier());

        if ($userIdentifier === null) {
            return null;
        }

        $query = $this->identityQuery($experiment, $owner);

        if ($user instanceof Model) {
            $identity = (clone $query)
                ->where('auth_user_type', $user->getMorphClass())
                ->where('auth_user_id', $userIdentifier)
                ->first();

            if ($identity instanceof SignalIdentity) {
                return $identity;
            }
        }

        $identity = (clone $query)
            ->where('external_id', $userIdentifier)
            ->first();

        return $identity instanceof SignalIdentity ? $identity : null;
    }

    private function resolveSession(Request $request, Experiment $experiment, ?Model $owner): ?SignalSession
    {
        $sessionIdentifier = $this->resolveSessionIdentifier($request);

        if ($sessionIdentifier === null) {
            return null;
        }

        $session = $this->scopeSignalQueryToOwner
            ->handle(
                SignalSession::query()
                    ->where('tracked_property_id', $experiment->tracked_property_id),
                $owner,
            )
            ->where('session_identifier', $sessionIdentifier)
            ->first();

        return $session instanceof SignalSession ? $session : null;
    }

    private function resolveAnonymousId(Request $request): ?string
    {
        $source = $this->resolveAnonymousIdSource();
        $key = $this->resolveAnonymousIdKey();

        return match ($source) {
            'header' => $this->stringValue($request->headers->get($key)),
            default => $this->stringValue($request->cookie($key)),
        };
    }

    private function resolveSessionIdentifier(Request $request): ?string
    {
        $source = $this->resolveSessionIdentifierSource();
        $key = $source === 'laravel' ? null : $this->resolveSessionIdentifierKey();

        return match ($source) {
            'cookie' => $this->stringValue($request->cookie($key ?? '')),
            'header' => $this->stringValue($request->headers->get($key ?? '')),
            default => $this->resolveLaravelSessionIdentifier($request),
        };
    }

    /**
     * @return Builder<SignalIdentity>
     */
    private function identityQuery(Experiment $experiment, ?Model $owner): Builder
    {
        return $this->scopeSignalQueryToOwner->handle(
            SignalIdentity::query()
                ->where('tracked_property_id', $experiment->tracked_property_id),
            $owner,
        );
    }

    private function resolveUser(Request $request): ?Authenticatable
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return $user;
        }

        try {
            $resolved = auth()->user();

            return $resolved instanceof Authenticatable ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveLaravelSessionIdentifier(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        try {
            return $this->stringValue($request->session()->getId());
        } catch (Throwable) {
            return null;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveAnonymousIdSource(): string
    {
        $source = mb_strtolower(mb_trim((string) config('growth.http.experiment_middleware.anonymous_id_source', 'cookie')));

        if (! in_array($source, ['cookie', 'header'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid growth http.experiment_middleware.anonymous_id_source [%s]. Supported values: cookie, header.',
                $source,
            ));
        }

        return $source;
    }

    private function resolveSessionIdentifierSource(): string
    {
        $source = mb_strtolower(mb_trim((string) config('growth.http.experiment_middleware.session_identifier_source', 'laravel')));

        if (! in_array($source, ['laravel', 'cookie', 'header'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid growth http.experiment_middleware.session_identifier_source [%s]. Supported values: laravel, cookie, header.',
                $source,
            ));
        }

        return $source;
    }

    private function resolveAnonymousIdKey(): string
    {
        $key = mb_trim((string) config('growth.http.experiment_middleware.anonymous_id_key', 'visitor_id'));

        if ($key === '') {
            throw new InvalidArgumentException(
                'Invalid growth http.experiment_middleware.anonymous_id_key. Value cannot be empty.',
            );
        }

        return $key;
    }

    private function resolveSessionIdentifierKey(): string
    {
        $key = mb_trim((string) config('growth.http.experiment_middleware.session_identifier_key', 'X-Session-Identifier'));

        if ($key === '') {
            throw new InvalidArgumentException(
                'Invalid growth http.experiment_middleware.session_identifier_key. Value cannot be empty.',
            );
        }

        return $key;
    }
}
