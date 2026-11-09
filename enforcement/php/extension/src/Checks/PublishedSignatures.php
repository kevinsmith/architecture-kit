<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
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
use function strcasecmp;

/**
 * R05 and R10 for the published surface: public method and property types of published
 * contracts, including docblock types the guard does not read, name only published types
 * and carry explicit schemas rather than `mixed`, `object`, or `stdClass`.
 */
final readonly class PublishedSignatures implements Check
{
    private const array DECLARATIONS = [NodeKind::Class_, NodeKind::Interface, NodeKind::Enum, NodeKind::Trait];

    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($place->role !== Role::Contracts) {
            return;
        }
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
                if ($method === null || $method->visibility !== Visibility::Public || !$this->local($code, $method->location)) {
                    continue;
                }
                foreach ($method->parameters as $parameter) {
                    // Promoted parameters are checked as properties.
                    if ($parameter->type !== null && !$parameter->flags->contains(MetadataFlags::PROMOTED_PROPERTY)) {
                        $this->type($parameter->type->type, $parameter->location, "parameter `{$parameter->name}` of `{$class}::{$method->originalName}()`", $report);
                    }
                }
                if ($method->returnType !== null) {
                    $this->type($method->returnType->type, $method->nameLocation ?? $method->location, "return type of `{$class}::{$method->originalName}()`", $report);
                }
            }
            foreach ($metadata->properties as $name) {
                $property = $code->codebase->getProperty($class, $name);
                $location = $property?->nameLocation ?? $property?->location;
                if ($property?->type !== null && $location !== null && $property->readVisibility === Visibility::Public && $this->local($code, $location)) {
                    $this->type($property->type->type, $location, "property `{$class}::{$name}`", $report);
                }
            }
        }
    }

    /** Inherited members are checked where they are declared. */
    private function local(Code $code, SourceLocation $location): bool
    {
        return $location->file === null || $location->file === $code->path;
    }

    private function type(Type $type, SourceLocation $at, string $member, Report $report): void
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType) {
                $place = $this->layout->place($atomic->name);
                if ($place !== null && $place->role !== Role::Contracts) {
                    $report->error(
                        $at,
                        'private-type-published',
                        'R05',
                        "The {$member} names `{$atomic->name}`, which is not published.",
                        'private domain and storage models stay out of published signatures.',
                        'Publish a contract value for it, or keep the member out of the published surface.',
                    );
                } elseif (strcasecmp($atomic->name, 'stdClass') === 0) {
                    $this->bag($at, $member, 'stdClass', $report);
                }
                foreach ($atomic->parameters ?? [] as $parameter) {
                    $this->type($parameter, $at, $member, $report);
                }
            } elseif ($atomic instanceof MixedType) {
                $this->bag($at, $member, 'mixed', $report);
            } elseif ($atomic instanceof AnyObjectType) {
                $this->bag($at, $member, 'object', $report);
            } elseif ($atomic instanceof KeyedArrayType) {
                foreach ([$atomic->keyType, $atomic->valueType, ...array_map(static fn($item): Type => $item->type, $atomic->knownItems ?? [])] as $inner) {
                    if ($inner !== null) {
                        $this->type($inner, $at, $member, $report);
                    }
                }
            } elseif ($atomic instanceof ListType) {
                foreach ([$atomic->elementType, ...array_map(static fn($element): Type => $element->type, $atomic->knownElements ?? [])] as $inner) {
                    $this->type($inner, $at, $member, $report);
                }
            } elseif ($atomic instanceof IterableType) {
                $this->type($atomic->keyType, $at, $member, $report);
                $this->type($atomic->valueType, $at, $member, $report);
            }
        }
    }

    private function bag(SourceLocation $at, string $member, string $type, Report $report): void
    {
        $report->error(
            $at,
            'unstructured-published-type',
            'R10',
            "The {$member} carries `{$type}`, which has no schema.",
            'published fields, including collection elements and map values, have explicit schemas.',
            'Declare the element or value type, such as `list<Recipient>`, or publish a value class.',
        );
    }
}
