<?php

declare(strict_types=1);

namespace PackageArchitecture\StaticAnalysis;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** @implements Rule<Node\Expr> */
final class PackageOwnedWriteRule implements Rule
{
    private string $layout = 'single';

    private string $sourceDir = 'src';

    private string $pkgNs = '';

    /** @var array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>, extensions: array<string, list<string>>} */
    private array $catalog = ['features' => [], 'owners' => [], 'writes' => [], 'extensions' => []];

    private readonly string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = ($projectRoot !== null && mb_trim($projectRoot) !== '')
            ? $projectRoot
            : dirname(__DIR__, 2);

        $cfgPath = mb_rtrim($this->projectRoot, '/') . '/tools/architecture.json';
        if (is_file($cfgPath)) {
            $cfg = json_decode((string) file_get_contents($cfgPath), true);
            if (is_array($cfg)) {
                $this->layout = ($cfg['layout'] ?? 'single') === 'modular' ? 'modular' : 'single';
                $this->sourceDir = mb_trim((string) ($cfg['sourceDir'] ?? 'src'), '/');
                $this->pkgNs = mb_trim((string) ($cfg['namespace'] ?? ''), '\\');
            }
        }

        if ($this->layout === 'modular' && $this->pkgNs !== '') {
            $catalogClass = $this->pkgNs . '\\Platform\\FeatureCatalog';
            $catalogFile = mb_rtrim($this->projectRoot, '/') . '/' . $this->sourceDir . '/Platform/FeatureCatalog.php';
            if (! class_exists($catalogClass) && is_file($catalogFile)) {
                require_once $catalogFile;
            }
            if (class_exists($catalogClass)) {
                $this->catalog = $catalogClass::scan($this->projectRoot, $this->pkgNs, $this->sourceDir);
            }
        }
    }

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\MethodCall
            && ! $node instanceof Node\Expr\NullsafeMethodCall
            && ! $node instanceof Node\Expr\StaticCall) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = mb_strtolower($node->name->toString());
        $type = $node instanceof Node\Expr\StaticCall
            ? ($node->class instanceof Node\Name ? new ObjectType($scope->resolveName($node->class)) : $scope->getType($node->class))
            : $scope->getType($node->var);

        $dbMethods = [
            'save', 'savequietly', 'saveorfail', 'push', 'pushquietly',
            'create', 'createquietly', 'forcecreate', 'forcecreatequietly',
            'update', 'updatequietly', 'updateorcreate', 'firstorcreate', 'createorfirst',
            'delete', 'deletequietly', 'destroy', 'forcedestroy', 'forcedelete', 'restore',
            'increment', 'decrement', 'touch', 'insert', 'insertgetid', 'insertorignore', 'upsert', 'truncate',
            'attach', 'detach', 'sync', 'syncwithoutdetaching', 'toggle', 'statement', 'unprepared', 'affectingstatement',
        ];
        $storeMethods = [
            'put', 'putfile', 'putfileas', 'set', 'setex', 'setnx', 'mset', 'hset', 'hdel', 'del',
            'write', 'writestream', 'delete', 'deletedirectory', 'makedirectory',
            'copy', 'move', 'append', 'prepend', 'forget', 'flush', 'forever', 'remember', 'rememberforever', 'pull',
        ];

        $isDatabase = $this->isA($type, [
            'Illuminate\Database\Eloquent\Model',
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Eloquent\Relations\Relation',
            'Illuminate\Database\Query\Builder',
            'Illuminate\Database\ConnectionInterface',
            'Illuminate\Support\Facades\DB',
        ]);
        $isStore = $this->isA($type, [
            'Illuminate\Contracts\Cache\Repository',
            'Illuminate\Contracts\Cache\Store',
            'Illuminate\Contracts\Filesystem\Filesystem',
            'Illuminate\Redis\Connections\Connection',
            'Illuminate\Support\Facades\Cache',
            'Illuminate\Support\Facades\Storage',
            'Illuminate\Support\Facades\Redis',
        ]);

        if (! ($isDatabase && in_array($method, $dbMethods, true)) && ! ($isStore && in_array($method, $storeMethods, true))) {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        $relative = mb_ltrim(mb_substr($file, mb_strlen(str_replace('\\', '/', $this->projectRoot))), '/');

        if (! str_starts_with($relative, $this->sourceDir . '/') || str_contains($relative, '/Database/Migrations/')) {
            return [];
        }

        $srcRel = mb_substr($relative, mb_strlen($this->sourceDir) + 1);

        if ($this->layout === 'single') {
            if (str_starts_with($srcRel, 'Actions/') || str_starts_with($srcRel, 'Persistence/') || str_starts_with($srcRel, 'Drivers/')) {
                return [];
            }

            return [$this->error(
                "Storage mutation in '{$relative}' is outside a write owner. Move DB/Cache/Storage writes into {$this->sourceDir}/Actions/ (or {$this->sourceDir}/Persistence/) and call it from thin Commands/EntryPoints (Single-Writer Law).",
                'packageArchitecture.writeLocation'
            )];
        }

        $feature = preg_match('~^Features/([^/]+)/~', $srcRel, $m) ? $m[1] : null;
        $class = $scope->getClassReflection()?->getName();
        $declaredResources = $class === null ? [] : ($this->catalog['writes'][$class] ?? []);
        $resource = $isDatabase ? $this->tableResource($node) : $this->storeResource($node);
        $owner = $resource === null ? null : ($this->catalog['owners'][$resource] ?? null);

        if ($feature === null && $owner === null) {
            if (str_starts_with($srcRel, 'Drivers/') || str_starts_with($srcRel, 'Persistence/')) {
                return [];
            }

            return [$this->error(
                "Storage mutation in '{$relative}' belongs inside an owning Feature's Actions/Persistence class declared in feature.json.",
                'packageArchitecture.writeLocation'
            )];
        }

        if ($owner !== null && $owner !== $feature) {
            return [$this->error(
                "Durable resource '{$resource}' belongs to package feature '{$owner}'. Call {$this->pkgNs}\\Features\\{$owner}\\Api\\ instead.",
                'packageArchitecture.foreignWrite'
            )];
        }

        if ($resource !== null && ! in_array($resource, $declaredResources, true)) {
            return [$this->error(
                "Class '{$class}' is not listed as a writer for '{$resource}' in feature.json. Delegate to an owning Action or declare it.",
                'packageArchitecture.writeOwner'
            )];
        }

        if ($resource === null) {
            $prefix = $isDatabase ? 'table:' : 'store:';
            $allowed = array_filter($declaredResources, static fn (string $v): bool => str_starts_with($v, $prefix));
            if ($allowed === []) {
                return [$this->error(
                    "Unregistered storage write in '{$relative}'. Move mutations into a named Actions/ or Persistence/ class listed in feature.json.",
                    'packageArchitecture.writeOwner'
                )];
            }
        }

        return [];
    }

    /** @param list<string> $bases */
    private function isA(Type $type, array $bases): bool
    {
        foreach ($type->getObjectClassNames() as $class) {
            foreach ($bases as $base) {
                if ((new ObjectType($base))->isSuperTypeOf(new ObjectType($class))->yes()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tableResource(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier
                && in_array(mb_strtolower($node->name->toString()), ['table', 'from'], true)
                && isset($node->args[0])
                && $node->args[0]->value instanceof Node\Scalar\String_) {
                return 'table:' . $node->args[0]->value->value;
            }
            if ($node instanceof Node\Expr\StaticCall) {
                break;
            }
            $node = $node->var;
        }

        return null;
    }

    private function storeResource(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier
                && in_array(mb_strtolower($node->name->toString()), ['disk', 'store', 'connection'], true)
                && isset($node->args[0])
                && $node->args[0]->value instanceof Node\Scalar\String_) {
                return 'store:' . $node->args[0]->value->value;
            }
            if ($node instanceof Node\Expr\StaticCall) {
                break;
            }
            $node = $node->var;
        }

        return null;
    }

    private function error(string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->build();
    }
}
