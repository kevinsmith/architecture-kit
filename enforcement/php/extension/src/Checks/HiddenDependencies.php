<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Override;
use ArchitectureCheck\Check;
use ArchitectureCheck\Code;
use ArchitectureCheck\Layout;
use ArchitectureCheck\Place;
use ArchitectureCheck\Report;
use ArchitectureCheck\Role;

use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function strtolower;

/**
 * R02 and R03: the guard checks dependencies written as symbols. A class or function named in
 * a string, or chosen at runtime, is a dependency it cannot see. Composition may depend on
 * anything, so it is exempt.
 */
final readonly class HiddenDependencies implements Check
{
    private const string SYMBOL = '~^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+(?:::[A-Za-z_][A-Za-z0-9_]*)?$~';

    private const string REASON = 'the guard checks only dependencies written as symbols.';

    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($place->role === Role::Composition) {
            return;
        }
        foreach ($code->within($namespace, NodeKind::LiteralString) as $literal) {
            $value = Code::decode($code->text($literal));
            if (preg_match(self::SYMBOL, $value) !== 1) {
                continue;
            }
            $symbol = ltrim(explode('::', $value, 2)[0], '\\');
            if (!$this->layout->contains($symbol)) {
                continue;
            }
            if ($code->codebase->classLikeExists($symbol) || $code->codebase->functionExists($symbol)) {
                $report->error(
                    $literal,
                    'string-dependency',
                    'R03',
                    "A string names `{$symbol}`.",
                    self::REASON,
                    'Write `Name::class` or a first-class callable such as `name(...)`, so the guard checks the dependency.',
                );
            }
        }
        foreach ($code->within($namespace, NodeKind::Instantiation, NodeKind::StaticMethodCall, NodeKind::StaticPropertyAccess, NodeKind::ClassConstantAccess, NodeKind::FunctionCall) as $node) {
            $target = $code->unwrap($code->children($node)[$node->kind === NodeKind::Instantiation ? 1 : 0] ?? null);
            if ($target === null || in_array($target->kind, [NodeKind::Identifier, NodeKind::Keyword, NodeKind::AnonymousClass, NodeKind::Closure, NodeKind::ArrowFunction], true)) {
                continue;
            }
            // `$value::class` reads a name; it does not depend on one.
            if ($node->kind === NodeKind::ClassConstantAccess && strtolower($code->text($node)) === strtolower($code->text($target)) . '::class') {
                continue;
            }
            if ($node->kind === NodeKind::Instantiation || $code->isString($target)) {
                $report->error(
                    $node,
                    'dynamic-dependency',
                    'R03',
                    'The class or function used here is chosen at runtime.',
                    self::REASON,
                    'Inject the object, or a factory for it, from composition.',
                );
            }
        }
    }
}
