<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Override;
use ArchitectureCheck\Check;
use ArchitectureCheck\Code;
use ArchitectureCheck\Layout;
use ArchitectureCheck\Place;
use ArchitectureCheck\Report;
use ArchitectureCheck\Role;

use function array_map;
use function array_merge;

/**
 * Behavior a pure role runs without naming it, so the checks on that role never read its
 * code: an implementation of a Domain interface or a subclass of a Domain class written
 * elsewhere (R04), and a callable or lazily iterated value passed into or out of Domain
 * (R04) or a published contract (R05). Psalm can admit pure closures because it checks
 * their effects; these checks cannot, so they reject them.
 */
final readonly class InjectedBehavior implements Check
{
    private const array DECLARATIONS = [NodeKind::Class_, NodeKind::Interface, NodeKind::Enum, NodeKind::Trait];

    /** Values whose code runs when called or iterated. */
    private const array DEFERRED = ['Closure', 'Traversable'];

    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($place->role !== Role::Domain) {
            $this->implementations($code, $namespace, $report);
        }
        if ($place->role->isPure()) {
            $this->signatures($code, $place, $namespace, $report);
        }
    }

    private function implementations(Code $code, Node $namespace, Report $report): void
    {
        foreach ($code->within($namespace, ...self::DECLARATIONS) as $declaration) {
            $class = $code->name($code->child($declaration, NodeKind::LocalIdentifier));
            $metadata = $class === null ? null : $code->codebase->getClassLike($class);
            if ($metadata !== null) {
                $this->ancestors($code, $declaration, "`{$class}`", array_merge($metadata->parentClasses, $metadata->parentInterfaces), $report);
            }
        }
        foreach ($code->within($namespace, NodeKind::AnonymousClass) as $anonymous) {
            $named = [];
            foreach ($code->children($anonymous) as $clause) {
                if ($clause->kind === NodeKind::Extends || $clause->kind === NodeKind::Implements) {
                    foreach ($code->within($clause, NodeKind::Identifier) as $identifier) {
                        $name = $code->name($identifier);
                        $metadata = $name === null ? null : $code->codebase->getClassLike($name);
                        $named = [...$named, ...($name === null ? [] : [$name]), ...($metadata?->parentClasses ?? []), ...($metadata?->parentInterfaces ?? [])];
                    }
                }
            }
            $this->ancestors($code, $anonymous, 'An anonymous class', $named, $report);
        }
    }

    /** @param list<string> $ancestors */
    private function ancestors(Code $code, Node $at, string $subject, array $ancestors, Report $report): void
    {
        foreach ($ancestors as $ancestor) {
            if ($this->layout->place($ancestor)?->role === Role::Domain) {
                $name = $code->codebase->getClassLike($ancestor)?->originalName ?? $ancestor;
                $report->error(
                    $at,
                    'domain-implementation',
                    'R04',
                    "{$subject} implements or extends `{$name}` outside Domain.",
                    'Domain calls the type, so its implementation runs as Domain code without Domain\'s checks.',
                    'Move the implementation into Domain, or declare the interface as an Application port and pass Domain the values it returns.',
                );

                return;
            }
        }
    }

    private function signatures(Code $code, Place $place, Node $namespace, Report $report): void
    {
        $rule = $place->role === Role::Domain ? 'R04' : 'R05';
        foreach ($code->within($namespace, ...self::DECLARATIONS) as $declaration) {
            $class = $code->name($code->child($declaration, NodeKind::LocalIdentifier));
            $metadata = $class === null ? null : $code->codebase->getClassLike($class);
            if ($class === null || $metadata === null) {
                continue;
            }
            $methods = $code->codebase->getMultipleMethods(array_map(
                static fn(string $method): MemberIdentifier => new MemberIdentifier($class, $method),
                $metadata->methods,
            ));
            foreach ($methods as $method) {
                if ($method === null || $method->visibility === Visibility::Private || !self::local($code, $method->location)) {
                    continue;
                }
                foreach ($method->parameters as $parameter) {
                    $this->type($code, $parameter->type?->type, $parameter->location, "parameter `{$parameter->name}` of `{$class}::{$method->originalName}()`", $rule, $report);
                }
                $this->type($code, $method->returnType?->type, $method->nameLocation ?? $method->location, "return type of `{$class}::{$method->originalName}()`", $rule, $report);
            }
            foreach ($metadata->properties as $name) {
                $property = $code->codebase->getProperty($class, $name);
                $location = $property?->nameLocation ?? $property?->location;
                if ($property !== null && $location !== null && $property->readVisibility !== Visibility::Private && self::local($code, $location)) {
                    $this->type($code, $property->type?->type, $location, "property `{$class}::{$name}`", $rule, $report);
                }
            }
        }
    }

    /** Inherited members are checked where they are declared. */
    private static function local(Code $code, SourceLocation $location): bool
    {
        return $location->file === null || $location->file === $code->path;
    }

    private function type(Code $code, ?Type $type, SourceLocation $at, string $member, string $rule, Report $report): void
    {
        $found = $type === null ? null : $this->deferred($code, $type);
        if ($found !== null) {
            $report->error(
                $at,
                'deferred-behavior',
                $rule,
                "The {$member} accepts or returns `{$found}`, whose code runs when it is called or iterated.",
                $rule === 'R04'
                    ? 'Domain receives values, not behavior; a callable or lazy iterator can perform I/O inside a Domain decision.'
                    : 'published contracts carry data, not behavior that runs inside the consumer.',
                'Pass the values instead, such as a `list<T>` that Application has already loaded.',
            );
        }
    }

    /** Returns the first callable or traversable type, including collection elements. */
    private function deferred(Code $code, Type $type): ?string
    {
        foreach ($type->atomicTypes as $atomic) {
            $inner = [];
            if ($atomic instanceof CallableType) {
                return 'callable';
            } elseif ($atomic instanceof IterableType) {
                return 'iterable';
            } elseif ($atomic instanceof NamedObjectType) {
                foreach (self::DEFERRED as $deferred) {
                    if ($code->isA($atomic->name, $deferred)) {
                        return $atomic->name;
                    }
                }
                $inner = $atomic->parameters ?? [];
            } elseif ($atomic instanceof KeyedArrayType) {
                $inner = [$atomic->keyType, $atomic->valueType, ...array_map(static fn($item): Type => $item->type, $atomic->knownItems ?? [])];
            } elseif ($atomic instanceof ListType) {
                $inner = [$atomic->elementType, ...array_map(static fn($element): Type => $element->type, $atomic->knownElements ?? [])];
            }
            foreach ($inner as $element) {
                $found = $element === null ? null : $this->deferred($code, $element);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
