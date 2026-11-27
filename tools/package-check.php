<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$rawArgs = array_slice($_SERVER['argv'] ?? [], 1);
$expectValue = false;
$unknownOptions = [];
foreach ($rawArgs as $arg) {
    if ($expectValue) {
        $expectValue = false;

        continue;
    }
    if ($arg === '--json') {
        continue;
    }
    if ($arg === '--root' || $arg === '--diff-base') {
        $expectValue = true;

        continue;
    }
    if (str_starts_with($arg, '--root=') || str_starts_with($arg, '--diff-base=')) {
        continue;
    }
    $unknownOptions[] = $arg;
}
if ($expectValue) {
    fwrite(STDERR, 'Missing value for option: ' . end($rawArgs) . "\n");
    exit(2);
}
if ($unknownOptions !== []) {
    fwrite(STDERR, 'Unknown option(s): ' . implode(' ', $unknownOptions) . "\nAllowed: --root=<path>, --diff-base=<ref>, --json\n");
    exit(2);
}

$options = getopt('', ['root:', 'json', 'diff-base:']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Unable to resolve the package root.\n");
    exit(2);
}

$autoload = is_file($root . '/vendor/autoload.php')
    ? $root . '/vendor/autoload.php'
    : dirname($root, 2) . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Run composer install (including dev dependencies) first.\n");
    exit(2);
}

require $autoload;

if (! class_exists(ParserFactory::class)) {
    fwrite(STDERR, "Install PHP-Parser: composer require --dev 'nikic/php-parser:^5'\n");
    exit(2);
}

$diffBase = is_string($options['diff-base'] ?? null) ? mb_trim($options['diff-base']) : null;
$changedFiles = null;
if ($diffBase !== null && $diffBase !== '') {
    $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMRT %s 2>/dev/null', escapeshellarg($root), escapeshellarg($diffBase));
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Unable to compute git diff against '{$diffBase}'.\n");
        exit(2);
    }
    $changedFiles = array_flip(array_map(static fn (string $p): string => str_replace('\\', '/', mb_trim($p)), $out));
}

$findings = [];
$report = static function (string $rule, string $file, int $line, string $target, string $message) use (&$findings): void {
    $key = $rule . '|' . $file . '|' . $line . '|' . $target;
    $findings[$key] = compact('rule', 'file', 'line', 'target', 'message');
};

$finder = new NodeFinder;
$parser = (new ParserFactory)->createForNewestSupportedVersion();

try {
    $configPath = $root . '/tools/architecture.json';
    if (! is_file($configPath)) {
        throw new RuntimeException("Missing {$configPath}. Run pkg_scaffold.py init --apply first.");
    }

    $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    if (($config['schema'] ?? null) !== 1
        || ($config['kind'] ?? null) !== 'package'
        || ! in_array($config['layout'] ?? null, ['single', 'modular'], true)
        || ! is_string($config['namespace'] ?? null)
        || mb_trim($config['namespace']) === ''
        || ! is_array($config['exceptions'] ?? null)) {
        throw new RuntimeException('Invalid tools/architecture.json package schema.');
    }

    $layout = $config['layout'];
    $pkgNs = mb_trim($config['namespace'], '\\');
    $pkgPrefix = $pkgNs . '\\';
    $sourceDir = mb_trim((string) ($config['sourceDir'] ?? 'src'), '/');
    $antiSlop = is_array($config['antiSlop'] ?? null) ? $config['antiSlop'] : [];

    $features = [];
    $owners = [];
    if ($layout === 'modular') {
        $catalogClass = $pkgPrefix . 'Platform\\FeatureCatalog';
        if (! class_exists($catalogClass)) {
            $catalogFile = $root . '/' . $sourceDir . '/Platform/FeatureCatalog.php';
            if (is_file($catalogFile)) {
                require_once $catalogFile;
            }
        }
        if (! class_exists($catalogClass)) {
            throw new RuntimeException("Modular layout requires {$catalogClass} at {$sourceDir}/Platform/FeatureCatalog.php.");
        }
        $catalog = $catalogClass::scan($root, $pkgNs, $sourceDir);
        $features = $catalog['features'];
        $owners = $catalog['owners'];
    }

    $featureOf = static function (string $fqcn) use ($pkgPrefix): ?string {
        if (! str_starts_with($fqcn, $pkgPrefix . 'Features\\')) {
            return null;
        }
        $rest = mb_substr($fqcn, mb_strlen($pkgPrefix . 'Features\\'));

        return preg_match('/^([A-Z][A-Za-z0-9]*)\\\\/', $rest, $m) ? $m[1] : null;
    };

    $paths = [];
    foreach ([$sourceDir, 'config', 'routes'] as $directory) {
        if (! is_dir($root . '/' . $directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', mb_substr($file->getPathname(), mb_strlen($root) + 1));
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[$relative] = $file->getPathname();
            }
        }
    }
    ksort($paths);

    foreach ($paths as $relative => $path) {
        $sourceCode = (string) file_get_contents($path);
        $ast = $parser->parse($sourceCode) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        $inSrc = str_starts_with($relative, $sourceDir . '/');
        $srcRel = $inSrc ? mb_substr($relative, mb_strlen($sourceDir) + 1) : '';
        $isConfigFile = str_starts_with($relative, 'config/');
        $isPlatform = $inSrc && str_starts_with($srcRel, 'Platform/');
        $isDriver = $inSrc && str_starts_with($srcRel, 'Drivers/');
        $isContractOrData = $inSrc && (str_starts_with($srcRel, 'Contracts/') || str_starts_with($srcRel, 'Data/'));
        $sourceFeature = ($layout === 'modular' && $inSrc && preg_match('~^Features/([^/]+)/~', $srcRel, $m)) ? $m[1] : null;
        $isFeatureApi = $sourceFeature !== null && str_contains($srcRel, '/Api/');
        $isPublicContract = $isContractOrData || $isFeatureApi;
        $isFeatureModel = $sourceFeature !== null && str_contains($srcRel, '/Models/');
        $applyAntiSlop = ($changedFiles === null) || isset($changedFiles[$relative]) || $sourceFeature !== null || $isPlatform || $isContractOrData;

        $expectedClass = $inSrc
            ? $pkgPrefix . str_replace('/', '\\', mb_substr($srcRel, 0, -4))
            : null;

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
            if ($inSrc && $class->name !== null && $expectedClass !== null) {
                $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
                if ($declared !== $expectedClass) {
                    $report(
                        'namespace-path',
                        $relative,
                        $class->getStartLine(),
                        $declared,
                        "PSR-4 path mismatch. Expected FQCN {$expectedClass}."
                    );
                }
            }

            if ($isPublicContract && $class instanceof Node\Stmt\Class_ && (! $class->isReadonly() || ! $class->isFinal())) {
                $report(
                    'contract-shape',
                    $relative,
                    $class->getStartLine(),
                    $expectedClass ?? $class->name?->toString() ?? 'class',
                    'Public Contracts, Data, and Feature Api classes must be `final readonly class` immutable DTOs (or use interface/enum).'
                );
            }

            if ($isFeatureModel && $class instanceof Node\Stmt\Class_ && ! $class->isAbstract()) {
                $hasTableOrGetter = false;
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        if ($prop->name->toString() === 'table') {
                            $hasTableOrGetter = true;
                        }
                    }
                }
                foreach ($class->getMethods() as $method) {
                    if (mb_strtolower($method->name->toString()) === 'gettable') {
                        $hasTableOrGetter = true;
                    }
                }
                $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : ($class->name?->toString() ?? 'Model');
                if (! $hasTableOrGetter) {
                    $report(
                        'model-table',
                        $relative,
                        $class->getStartLine(),
                        $declared,
                        'Package models must declare explicit `$table` or override `getTable()` (reading from package config) so table ownership stays deterministic.'
                    );
                }
            }
        }

        $checkDependency = static function (string $target, int $line) use (
            $pkgPrefix,
            $sourceDir,
            $layout,
            $sourceFeature,
            $isPlatform,
            $isDriver,
            $isPublicContract,
            $inSrc,
            $srcRel,
            $relative,
            $featureOf,
            $features,
            $antiSlop,
            $report
        ): void {
            $target = mb_ltrim($target, '\\');

            if (($antiSlop['banAppImports'] ?? true) && $inSrc && (str_starts_with($target, 'App\\') || $target === 'App')) {
                $report(
                    'package-host-leak',
                    $relative,
                    $line,
                    $target,
                    'A reusable Laravel package must NEVER import host application classes (`App\\*`). Depend on a package Contract or config binding instead.'
                );
            }

            if ($isPublicContract) {
                if (str_starts_with($target, 'Illuminate\\Database\\Eloquent\\') || str_starts_with($target, 'Illuminate\\Http\\')) {
                    $report(
                        'contract-leak',
                        $relative,
                        $line,
                        $target,
                        'Public Contracts/Data/Api must not expose Eloquent or Illuminate\\Http runtime types. Use immutable DTOs, enums, or scalar IDs.'
                    );
                }
                if (str_starts_with($target, $pkgPrefix)
                    && ! str_starts_with($target, $pkgPrefix . 'Contracts\\')
                    && ! str_starts_with($target, $pkgPrefix . 'Data\\')
                    && ! str_contains($target, '\\Api\\')) {
                    $report(
                        'contract-leak',
                        $relative,
                        $line,
                        $target,
                        'Public Contracts/Data/Api may depend only on Contracts, Data, Feature Api, or PHP built-ins—never internal Actions, Models, or Drivers.'
                    );
                }
            }

            if ($inSrc && ! $isDriver && ! str_ends_with($srcRel, 'ServiceProvider.php')
                && str_starts_with($target, $pkgPrefix . 'Drivers\\')) {
                $report(
                    'driver-direct',
                    $relative,
                    $line,
                    $target,
                    "Do not import concrete Drivers classes directly. Inject the interface from {$pkgPrefix}Contracts\\ and bind it in the ServiceProvider."
                );
            }

            if ($layout === 'modular') {
                $targetFeature = $featureOf($target);

                if ($targetFeature !== null && ! isset($features[$targetFeature])) {
                    $report(
                        'missing-feature',
                        $relative,
                        $line,
                        $target,
                        "Referenced feature '{$targetFeature}' has no valid {$sourceDir}/Features/{$targetFeature}/feature.json manifest."
                    );
                }

                if ($isPlatform && ($targetFeature !== null || str_starts_with($target, $pkgPrefix . 'Drivers\\'))) {
                    $report(
                        'layer-direction',
                        $relative,
                        $line,
                        $target,
                        "Package Platform core ({$sourceDir}/Platform/) must NEVER import concrete Features or Drivers."
                    );
                } elseif ($isDriver && $targetFeature !== null) {
                    $report(
                        'layer-direction',
                        $relative,
                        $line,
                        $target,
                        "Drivers must NEVER import concrete Features. Communicate via {$sourceDir}/Contracts/ and {$sourceDir}/Data/."
                    );
                } elseif ($targetFeature !== null
                    && $targetFeature !== $sourceFeature
                    && ! str_ends_with($srcRel, 'ServiceProvider.php')
                    && ! str_starts_with($target, $pkgPrefix . "Features\\{$targetFeature}\\Api\\")) {
                    $report(
                        'feature-private',
                        $relative,
                        $line,
                        $target,
                        "Private class in '{$targetFeature}' cannot cross its feature boundary. Call {$pkgPrefix}Features\\{$targetFeature}\\Api\\ instead."
                    );
                }
            }
        };

        foreach ($finder->find($ast, static fn (Node $node): bool => true) as $node) {
            if ($node instanceof Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $checkDependency($use->name->toString(), $use->getStartLine());
                }
            }
            if ($node instanceof Node\Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    $checkDependency($node->prefix->toString() . '\\' . $use->name->toString(), $use->getStartLine());
                }
            }
            if ($node instanceof Node\Name\FullyQualified) {
                $checkDependency($node->toString(), $node->getStartLine());
            }
            if ($node instanceof Node\Scalar\String_) {
                $val = mb_ltrim($node->value, '\\');
                if (str_starts_with($val, $pkgPrefix) || str_starts_with($val, 'App\\')) {
                    $checkDependency($val, $node->getStartLine());
                }
            }

            if (! $applyAntiSlop) {
                continue;
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $fn = mb_strtolower($node->name->toString());
                if (($antiSlop['banSleep'] ?? true) && in_array($fn, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'], true)) {
                    $report('banned-footgun', $relative, $node->getStartLine(), $fn . '()', 'Imperative sleep hacks are banned in package code. Use queued backoff or explicit state transitions.');
                }
                if (($antiSlop['banEnvOutsideConfig'] ?? true) && ! $isConfigFile && $fn === 'env') {
                    $report('banned-footgun', $relative, $node->getStartLine(), 'env()', 'Never call env() outside config/. Read from config() so cached configs work in consumer apps.');
                }
                if (in_array($fn, ['dd', 'dump', 'ray', 'var_dump'], true)) {
                    $report('banned-footgun', $relative, $node->getStartLine(), $fn . '()', 'Debug dump calls are banned in shipped package code.');
                }
            }

            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $cls = mb_ltrim($node->class->toString(), '\\');
                $method = mb_strtolower($node->name->toString());
                if (($antiSlop['banModelUnguard'] ?? true) && $method === 'unguard' && str_ends_with($cls, 'Model')) {
                    $report('banned-footgun', $relative, $node->getStartLine(), $cls . '::unguard', 'Never call Model::unguard() in a package—it mutates global mass-assignment state in consumer apps.');
                }
                if (($antiSlop['banHostGlobalMutations'] ?? true) && in_array($method, ['shouldbestrict', 'preventlazyloading', 'preventsilentlydiscardingattributes', 'preventaccessingmissingattributes'], true) && str_ends_with($cls, 'Model')) {
                    $report('host-global-mutation', $relative, $node->getStartLine(), $cls . '::' . $node->name->toString(), 'Packages must NEVER mutate host app global Eloquent strictness settings.');
                }
                if (($antiSlop['banHostGlobalMutations'] ?? true) && $cls === 'Illuminate\\Support\\Facades\\DB' && $method === 'prohibitdestructivecommands') {
                    $report('host-global-mutation', $relative, $node->getStartLine(), 'DB::prohibitDestructiveCommands', 'Packages must NEVER mutate host app global DB command prohibitions.');
                }
                if (($antiSlop['banHostGlobalMutations'] ?? true) && $cls === 'Illuminate\\Support\\Facades\\Date' && $method === 'use') {
                    $report('host-global-mutation', $relative, $node->getStartLine(), 'Date::use', 'Packages must NEVER override the host app global Date factory.');
                }
                if (($antiSlop['banSleep'] ?? true) && $cls === 'Illuminate\\Support\\Sleep' && in_array($method, ['for', 'sleep', 'usleep'], true)) {
                    $report('banned-footgun', $relative, $node->getStartLine(), 'Sleep::' . $method, 'Imperative Sleep hacks are banned in package code.');
                }
            }
        }

        if ($applyAntiSlop && ! $isPlatform) {
            foreach (token_get_all($sourceCode) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text = $token[1];
                $line = $token[2];

                if (($antiSlop['banInlineSuppressions'] ?? true)
                    && preg_match('/@phpstan-ignore|phpcs:(?:disable|ignore)|@psalm-suppress/i', $text)) {
                    $report('no-suppression', $relative, $line, mb_trim(strtok($text, "\n") ?: 'suppression'), 'Inline static-analysis suppressions are banned. Fix the root cause instead.');
                }

                if (($antiSlop['commentHygiene'] ?? true)
                    && $token[0] === T_COMMENT
                    && ! preg_match('/license|copyright|spdx|https?:\/\/|prettier-ignore/i', $text)
                    && preg_match('/\b(do not remove|don\'t remove|do not touch|temporary workaround|quick fix|fine for now|too risky|hack|todo|fixme|said never)\b/i', $text)) {
                    $report('comment-hygiene', $relative, $line, mb_trim(strtok($text, "\n") ?: 'comment'), 'Workaround or claimed-constraint comments are banned. Encode invariants as types, lints, or tests.');
                }
            }
        }
    }

    $suppressible = ['feature-private', 'driver-direct'];
    foreach ($config['exceptions'] as $exception) {
        foreach (['rule', 'file', 'target', 'reason', 'owner', 'expires'] as $field) {
            if (! is_string($exception[$field] ?? null) || mb_trim($exception[$field]) === '') {
                throw new RuntimeException("Exception requires non-empty '{$field}'.");
            }
        }
        if (! in_array($exception['rule'], $suppressible, true) || strpbrk($exception['file'] . $exception['target'], '*?') !== false) {
            throw new RuntimeException("Exception for {$exception['file']} must use an allowed rule and exact file/target (no wildcards).");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']);
        if ($date === false || $date->format('Y-m-d') !== $exception['expires'] || $exception['expires'] < gmdate('Y-m-d')) {
            throw new RuntimeException("Expired or invalid exception date ({$exception['expires']}): {$exception['file']} -> {$exception['target']}");
        }
        $matched = false;
        foreach ($findings as $key => $finding) {
            if ($finding['rule'] === $exception['rule'] && $finding['file'] === $exception['file'] && $finding['target'] === $exception['target']) {
                unset($findings[$key]);
                $matched = true;
            }
        }
        if (! $matched) {
            throw new RuntimeException("Stale unused exception in tools/architecture.json — remove it: {$exception['file']} -> {$exception['target']}");
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Package architecture check error: ' . $error->getMessage() . "\n");
    exit(2);
}

$findings = array_values($findings);
if (isset($options['json'])) {
    echo json_encode([
        'schema' => 1,
        'kind' => 'package',
        'layout' => $layout,
        'namespace' => $pkgNs,
        'features' => array_keys($features),
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($findings as $finding) {
        echo "{$finding['file']}:{$finding['line']} [{$finding['rule']}] {$finding['target']}: {$finding['message']}\n";
    }
    echo count($findings) . " package architecture violation(s) checked (layout: {$layout}).\n";
}

exit($findings !== [] ? 1 : 0);
