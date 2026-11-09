<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysis;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function array_map;
use function file_get_contents;
use function in_array;
use function ltrim;
use function strcasecmp;
use function str_replace;
use function stripcslashes;
use function strtolower;
use function substr;

/**
 * One analyzed file: its syntax, source text, and inferred types. After-file hooks receive
 * syntax without source text, so the text is read from disk; the worker runs from the
 * configuration's directory, where analyzed paths are relative.
 */
final class Code
{
    private const array WRAPPERS = [NodeKind::Expression, NodeKind::Literal, NodeKind::Parenthesized, NodeKind::CompositeString, NodeKind::StringPart];

    public readonly SourceFile $source;

    public readonly string $path;

    private readonly string $text;

    /** @var array<string, list<string>> lower-case class-like => lower-case names of it and its ancestors */
    private array $lineage = [];

    public function __construct(
        private readonly FileAnalysis $analysis,
        public readonly Codebase $codebase,
    ) {
        $this->source = $analysis->getSourceFile();
        $this->path = $analysis->file;
        $this->text = (string) file_get_contents($analysis->file);
    }

    /**
     * Returns descendants of the given kinds, in source order.
     *
     * @return list<Node>
     */
    public function within(Node $scope, NodeKind ...$kinds): array
    {
        $nodes = [];
        foreach ($this->source->getDescendants($scope) as $node) {
            if (in_array($node->kind, $kinds, true)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public function type(Node $node): ?Type
    {
        return $this->analysis->getExpressionType($node);
    }

    /**
     * Returns the class-likes an expression's inferred type may hold.
     *
     * @return list<string>
     */
    public function classes(Node $expression): array
    {
        $classes = [];
        foreach ($this->type($expression)?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof NamedObjectType) {
                $classes[] = $atomic->name;
            }
        }

        return $classes;
    }

    /**
     * Returns the class-likes a call is made on: the named class of a static call or the
     * inferred type of the receiver.
     *
     * @return list<string>
     */
    public function receivers(Node $call): array
    {
        $receiver = $this->children($call)[0] ?? null;
        if ($receiver === null) {
            return [];
        }
        if ($call->kind === NodeKind::StaticMethodCall) {
            $class = $this->name($this->unwrap($receiver));

            return $class === null ? [] : [$class];
        }

        return $this->classes($receiver);
    }

    /** Whether a class-like is the given one or extends or implements it. */
    public function isA(string $class, string $ancestor): bool
    {
        $key = strtolower(ltrim($class, '\\'));
        if (!isset($this->lineage[$key])) {
            $metadata = $this->codebase->getClassLike($key);
            $this->lineage[$key] = array_map(
                strtolower(...),
                [$key, ...($metadata?->parentClasses ?? []), ...($metadata?->parentInterfaces ?? [])],
            );
        }

        return in_array(strtolower(ltrim($ancestor, '\\')), $this->lineage[$key], true);
    }

    /** Whether an expression's inferred type is a string, such as a class or callable name. */
    public function isString(Node $expression): bool
    {
        foreach ($this->type($expression)?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof ScalarType && in_array($atomic->kind, [ScalarTypeKind::String, ScalarTypeKind::ClassLikeString], true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether an expression's inferred type is callable. */
    public function isCallable(Node $expression): bool
    {
        foreach ($this->type($expression)?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof CallableType || ($atomic instanceof NamedObjectType && strcasecmp($atomic->name, 'Closure') === 0)) {
                return true;
            }
        }

        return false;
    }

    public function text(Node $node): string
    {
        return substr($this->text, $node->span->start, $node->span->length());
    }

    /** @return list<Node> */
    public function children(Node $node): array
    {
        return $this->source->getChildren($node);
    }

    public function child(Node $node, NodeKind ...$kinds): ?Node
    {
        foreach ($this->children($node) as $child) {
            if (in_array($child->kind, $kinds, true)) {
                return $child;
            }
        }

        return null;
    }

    /** Skips the expression wrappers around a value. */
    public function unwrap(?Node $node): ?Node
    {
        while ($node !== null && $node->kind === NodeKind::Expression) {
            $node = $this->children($node)[0] ?? null;
        }

        return $node;
    }

    /** Returns the resolved name of a class-like, function, or namespace reference. */
    public function name(?Node $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $resolved = $this->source->getResolvedName($node);
        if ($resolved !== null) {
            return ltrim($resolved->name, '\\');
        }
        $keyword = strtolower($this->text($node));
        if (in_array($keyword, ['self', 'static'], true)) {
            return $this->enclosingClass($node);
        }

        return null;
    }

    /** Returns the name of the class-like declaration that contains a node. */
    public function enclosingClass(Node $node): ?string
    {
        foreach ($this->source->getAncestors($node) as $ancestor) {
            if (in_array($ancestor->kind, [NodeKind::Class_, NodeKind::Interface, NodeKind::Enum, NodeKind::Trait], true)) {
                return $this->name($this->child($ancestor, NodeKind::LocalIdentifier));
            }
        }

        return null;
    }

    /**
     * Returns the arguments' value nodes, in source order.
     *
     * @return list<Node>
     */
    public function arguments(Node $call): array
    {
        $values = [];
        foreach ($this->children($this->child($call, NodeKind::ArgumentList) ?? $call) as $argument) {
            $variant = $this->children($argument)[0] ?? null;
            if ($variant !== null) {
                $parts = $this->children($variant);
                $value = $this->unwrap($parts[$variant->kind === NodeKind::NamedArgument ? 1 : 0] ?? null);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /** Returns a call's member name, such as `run` in `$store->run(...)`. */
    public function member(Node $call): ?string
    {
        $selector = $this->child($call, NodeKind::ClassLikeMemberSelector);
        $identifier = $selector === null ? null : $this->child($selector, NodeKind::LocalIdentifier);

        return $identifier === null ? null : $this->text($identifier);
    }

    /**
     * Evaluates a string expression, replacing computed parts with Sql::COMPUTED.
     * Follows concatenation, interpolation, heredoc and nowdoc parts, and class constants.
     */
    public function string(Node $node): string
    {
        return match ($node->kind) {
            NodeKind::LiteralString => self::decode($this->text($node)),
            NodeKind::LiteralStringPart => $this->text($node),
            NodeKind::DocumentString, NodeKind::InterpolatedString => $this->parts($node),
            NodeKind::Binary => $this->concatenation($node),
            NodeKind::Access => $this->string($this->children($node)[0]),
            NodeKind::ClassConstantAccess => $this->constant($node),
            default => in_array($node->kind, self::WRAPPERS, true) && ($child = $this->children($node)[0] ?? null) !== null
                ? $this->string($child)
                : Sql::COMPUTED,
        };
    }

    /** Returns the outermost string expression that contains a literal part. */
    public function stringRoot(Node $node): Node
    {
        while (($parent = $this->source->getParent($node)) !== null) {
            $concatenation = $parent->kind === NodeKind::Binary && $this->operator($parent) === '.';
            if (!$concatenation && !in_array($parent->kind, [...self::WRAPPERS, NodeKind::DocumentString, NodeKind::InterpolatedString], true)) {
                break;
            }
            $node = $parent;
        }

        return $node;
    }

    public static function decode(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return match ($literal[0] ?? '') {
            "'" => str_replace(['\\\\', "\\'"], ['\\', "'"], $inner),
            '"' => stripcslashes($inner),
            default => $literal,
        };
    }

    private function parts(Node $node): string
    {
        $value = '';
        foreach ($this->children($node) as $part) {
            $content = $this->children($part)[0] ?? $part;
            $value .= $content->kind === NodeKind::LiteralStringPart ? $this->text($content) : Sql::COMPUTED;
        }

        return $value;
    }

    private function concatenation(Node $node): string
    {
        [$left, , $right] = $this->children($node) + [null, null, null];
        if ($left === null || $right === null || $this->operator($node) !== '.') {
            return Sql::COMPUTED;
        }

        return $this->string($left) . $this->string($right);
    }

    private function operator(Node $binary): ?string
    {
        $operator = $this->child($binary, NodeKind::BinaryOperator);

        return $operator === null ? null : $this->text($operator);
    }

    private function constant(Node $access): string
    {
        [$class, $selector] = $this->children($access) + [null, null];
        $class = $this->name($this->unwrap($class) ?? $access);
        $identifier = $selector === null ? null : $this->child($selector, NodeKind::LocalIdentifier);
        if ($class === null || $identifier === null) {
            return Sql::COMPUTED;
        }
        $constant = $this->codebase->getClassConstant($class, $this->text($identifier));

        return $constant?->inferredType?->getLiteralString() ?? $constant?->type?->type->getLiteralString() ?? Sql::COMPUTED;
    }
}
