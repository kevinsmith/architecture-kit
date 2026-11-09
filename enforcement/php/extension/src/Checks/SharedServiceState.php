<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Override;
use ArchitectureCheck\Check;
use ArchitectureCheck\Code;
use ArchitectureCheck\Place;
use ArchitectureCheck\Report;
use ArchitectureCheck\Role;

use function array_map;
use function in_array;

/**
 * R07 for instance state: Application and Presentation objects are shared services that
 * long-lived runtimes reuse across invocations, so state they hold outlives the invocation
 * that wrote it. Their properties are `readonly` and hold no known mutable container.
 * Domain may mutate state supplied to it (R04), and Infrastructure adapters may hold shared
 * state, such as a cache or pool, whose concurrency safety stays a review obligation.
 */
final readonly class SharedServiceState implements Check
{
    /** Built-in classes and interfaces whose instances change after construction. */
    private const array MUTABLE = [
        'ArrayAccess', 'ArrayObject', 'ArrayIterator', 'SplDoublyLinkedList', 'SplHeap', 'SplPriorityQueue',
        'SplFixedArray', 'SplObjectStorage', 'WeakMap', 'stdClass', 'Ds\Collection',
    ];

    private const string REASON = 'invocation state stays out of shared services, which long-lived runtimes reuse across invocations.';

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if (!in_array($place->role, [Role::Application, Role::Presentation], true)) {
            return;
        }
        foreach ($code->within($namespace, NodeKind::Class_, NodeKind::Trait) as $declaration) {
            $class = $code->name($code->child($declaration, NodeKind::LocalIdentifier));
            $metadata = $class === null ? null : $code->codebase->getClassLike($class);
            if ($class === null || $metadata === null) {
                continue;
            }
            $readonly = $metadata->flags->contains(MetadataFlags::READONLY);
            foreach ($metadata->properties as $name) {
                $property = $code->codebase->getProperty($class, $name);
                $location = $property?->nameLocation ?? $property?->location;
                // Inherited properties are checked where they are declared; static state has its own check.
                if ($property === null || $location === null || ($location->file !== null && $location->file !== $code->path)
                    || $property->flags->contains(MetadataFlags::STATIC) || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)) {
                    continue;
                }
                if (!$readonly && !$property->flags->contains(MetadataFlags::READONLY)) {
                    $report->error(
                        $location,
                        'writable-property',
                        'R07',
                        "Property `{$class}::{$name}` can be reassigned after construction.",
                        self::REASON,
                        'Make the property `readonly` and pass invocation state as a parameter, or give the object an invocation-scoped lifetime in composition.',
                    );
                }
                $container = $property->type === null ? null : $this->container($code, $property->type->type);
                if ($container !== null) {
                    $report->error(
                        $location,
                        'mutable-container',
                        'R07',
                        "Property `{$class}::{$name}` holds `{$container}`, which can change after construction.",
                        self::REASON,
                        'Hold only ports, configuration, and immutable values; pass invocation state as a parameter, or move shared state into an Infrastructure adapter that is safe for concurrent use.',
                    );
                }
            }
        }
    }

    /** Returns the first known mutable container a type may hold, including collection elements. */
    private function container(Code $code, Type $type): ?string
    {
        foreach ($type->atomicTypes as $atomic) {
            $inner = [];
            if ($atomic instanceof NamedObjectType) {
                foreach (self::MUTABLE as $mutable) {
                    if ($code->isA($atomic->name, $mutable)) {
                        return $atomic->name;
                    }
                }
                $inner = $atomic->parameters ?? [];
            } elseif ($atomic instanceof KeyedArrayType) {
                $inner = [$atomic->keyType, $atomic->valueType, ...array_map(static fn($item): Type => $item->type, $atomic->knownItems ?? [])];
            } elseif ($atomic instanceof ListType) {
                $inner = [$atomic->elementType, ...array_map(static fn($element): Type => $element->type, $atomic->knownElements ?? [])];
            } elseif ($atomic instanceof IterableType) {
                $inner = [$atomic->keyType, $atomic->valueType];
            }
            foreach ($inner as $element) {
                $found = $element === null ? null : $this->container($code, $element);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
