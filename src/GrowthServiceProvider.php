<?php

declare(strict_types=1);

namespace AIArmada\Growth;

use AIArmada\Growth\Actions\DeletePropertyExperiments;
use AIArmada\Growth\Actions\ProjectExperimentContextIntoSignalProperties;
use AIArmada\Growth\Actions\ResolveExperimentPreset;
use AIArmada\Growth\EntryPoints\Commands\ArchiveExperimentsCommand;
use AIArmada\Growth\EntryPoints\Commands\RecomputeExperimentAssignmentsCommand;
use AIArmada\Growth\EntryPoints\Http\DefaultRequestExperimentSubjectResolver;
use AIArmada\Growth\EntryPoints\Http\Middleware\ResolveExperiment;
use AIArmada\Growth\EntryPoints\Http\RequestExperimentSubjectResolver;
use AIArmada\Growth\Support\Context\ExperimentContextManager;
use AIArmada\Signals\Features\Browser\Api\BrowserPageProperties;
use AIArmada\Signals\Features\Browser\Queries\BrowserPagePropertyRegistry;
use AIArmada\Signals\Features\Ingestion\Api\TrackedPropertyDeleting;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class GrowthServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('growth')
            ->hasConfigFile()
            ->runsMigrations()
            ->discoversMigrations()
            ->hasCommands([
                RecomputeExperimentAssignmentsCommand::class,
                ArchiveExperimentsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        require_once __DIR__ . '/helpers.php';

        $this->app->singleton(ResolveExperimentPreset::class);
        $this->app->singleton(ProjectExperimentContextIntoSignalProperties::class);
        $this->app->singleton(ExperimentContextManager::class);
        $this->app->afterResolving(BrowserPageProperties::class, function (mixed $properties): void {
            if ($properties instanceof BrowserPagePropertyRegistry) {
                $properties->add($this->app->make(ExperimentContextManager::class));
            }
        });
        $this->app->bind(RequestExperimentSubjectResolver::class, function ($app): RequestExperimentSubjectResolver {
            $resolverClass = (string) config('growth.http.experiment_middleware.subject_resolver', '');

            if ($resolverClass !== '') {
                if (! class_exists($resolverClass) || ! is_a($resolverClass, RequestExperimentSubjectResolver::class, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured growth request subject resolver [%s] must exist and implement %s.',
                        $resolverClass,
                        RequestExperimentSubjectResolver::class,
                    ));
                }

                return $app->make($resolverClass);
            }

            return $app->make(DefaultRequestExperimentSubjectResolver::class);
        });

        $this->app->bind('growth.signal_event_property_enricher', ProjectExperimentContextIntoSignalProperties::class);
        $this->app->alias(ExperimentContextManager::class, 'growth');
    }

    public function packageBooted(): void
    {
        $this->assertOwnerModeMatchesSignals();
        Event::listen(TrackedPropertyDeleting::class, DeletePropertyExperiments::class);
        $this->registerExperimentMiddleware();
        $this->registerBladeDirectives();
    }

    private function assertOwnerModeMatchesSignals(): void
    {
        if (! config()->has('signals.owner.enabled')) {
            return;
        }

        $growthOwnerEnabled = (bool) config('growth.features.owner.enabled', false);
        $signalsOwnerEnabled = (bool) config('signals.owner.enabled', false);

        if ($growthOwnerEnabled !== $signalsOwnerEnabled) {
            throw new InvalidArgumentException(
                'Growth and Signals owner scoping must be enabled or disabled together.',
            );
        }
    }

    private function registerExperimentMiddleware(): void
    {
        if (! $this->app->bound('router')) {
            return;
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('growth.experiment', ResolveExperiment::class);
    }

    private function registerBladeDirectives(): void
    {
        if (! $this->app->bound('blade.compiler')) {
            return;
        }

        /** @var BladeCompiler $bladeCompiler */
        $bladeCompiler = $this->app['blade.compiler'];
        $customDirectives = $bladeCompiler->getCustomDirectives();
        $reservedDirectiveNames = ['variant', 'elsevariant', 'endvariant', 'unlessvariant'];
        $conflictingDirectiveNames = array_values(array_intersect(array_keys($customDirectives), $reservedDirectiveNames));

        if ($conflictingDirectiveNames !== []) {
            if (config('growth.features.blade_directives.enabled', false)) {
                throw new InvalidArgumentException(sprintf(
                    'Growth Blade directives cannot be registered because these directive names are already defined: %s',
                    implode(', ', $conflictingDirectiveNames),
                ));
            }

            return;
        }

        Blade::if('variant', static function (string $code): bool {
            if (! config('growth.features.blade_directives.enabled', false)) {
                return false;
            }

            return app(ExperimentContextManager::class)->isVariant($code);
        });
    }
}
