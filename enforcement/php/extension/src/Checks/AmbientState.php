<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Override;
use ArchitectureCheck\Check;
use ArchitectureCheck\Code;
use ArchitectureCheck\Place;
use ArchitectureCheck\Report;
use ArchitectureCheck\Role;
use ArchitectureCheck\Sql;

use function in_array;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function strtotime;

/**
 * State and inputs that reach code without passing through its parameters, which the guard
 * cannot see because no symbol names them: the clock read through approved date classes
 * (R04, R05), static and global state (R04–R07), superglobals and process configuration
 * (R06), file inclusion (R06), and `eval` (R02).
 */
final readonly class AmbientState implements Check
{
    private const array SUPERGLOBALS = ['$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV'];

    private const array CONFIGURATION = ['getenv', 'putenv', 'ini_get', 'ini_set', 'ini_get_all', 'set_include_path'];

    private const array CLOCK_FACTORIES = ['now', 'today', 'tomorrow', 'yesterday'];

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($place->role->isPure()) {
            $this->clock($code, $place, $namespace, $report);
        }
        $this->state($code, $place, $namespace, $report);
        $this->inputs($code, $place, $namespace, $report);
        foreach ($code->within($namespace, NodeKind::IncludeConstruct, NodeKind::IncludeOnceConstruct, NodeKind::RequireConstruct, NodeKind::RequireOnceConstruct) as $include) {
            if ($place->role !== Role::Composition) {
                $report->error(
                    $include,
                    'hidden-initialization',
                    'R06',
                    'Business code includes a file.',
                    'code receives its dependencies explicitly, never through hidden initialization.',
                    'Load classes through the autoloader and perform setup in composition.',
                );
            }
        }
        foreach ($code->within($namespace, NodeKind::EvalConstruct) as $eval) {
            $report->error(
                $eval,
                'eval',
                'R02',
                '`eval` runs code that static analysis cannot classify or check.',
                'all production code is classified, and its dependencies are visible to the checks.',
                'Write the code as classified source.',
            );
        }
    }

    /** `new DateTimeImmutable()` and similar read the current time without naming a clock. */
    private function clock(Code $code, Place $place, Node $namespace, Report $report): void
    {
        $rule = $place->role === Role::Domain ? 'R04' : 'R05';
        $reason = $place->role === Role::Domain
            ? 'Domain receives time as an explicit input; Application obtains it from an injected clock.'
            : 'published contracts contain only agreements, data, and pure behavior.';
        foreach ($code->within($namespace, NodeKind::Instantiation, NodeKind::StaticMethodCall) as $node) {
            $class = $code->name($code->unwrap($code->child($node, NodeKind::Expression)));
            if ($class === null || !$code->isA($class, 'DateTimeInterface')) {
                continue;
            }
            $arguments = $code->arguments($node);
            $format = isset($arguments[0]) ? $code->string($arguments[0]) : null;
            $reads = match (true) {
                $node->kind === NodeKind::Instantiation => $format === null || self::relative($format),
                in_array(strtolower($code->member($node) ?? ''), self::CLOCK_FACTORIES, true) => true,
                // Fields missing from the format are taken from the current time unless `!` or `|` resets them.
                strcasecmp($code->member($node) ?? '', 'createFromFormat') === 0 => $format !== null
                    && !str_contains($format, Sql::COMPUTED) && !str_contains($format, '!') && !str_contains($format, '|'),
                default => false,
            };
            if ($reads) {
                $report->error($node, 'ambient-clock', $rule, "`{$class}` reads the current time.", $reason, 'Pass the time in as a parameter.');
            }
        }
    }

    private static function relative(string $time): bool
    {
        if (str_contains($time, Sql::COMPUTED)) {
            return false;
        }
        $early = strtotime($time, 0);
        $late = strtotime($time, 2_000_000_000);

        return $early !== false && $late !== false && $early !== $late;
    }

    private function state(Code $code, Place $place, Node $namespace, Report $report): void
    {
        $rule = match ($place->role) {
            Role::Domain => 'R04',
            Role::Contracts => 'R05',
            Role::Composition => 'R06',
            default => 'R07',
        };
        $reason = match ($rule) {
            'R04' => 'Domain is free of ambient and concurrently shared state.',
            'R05' => 'published data cannot become a mutable communication channel.',
            'R06' => 'composition assembles dependencies explicitly; static state turns it into a service locator.',
            default => 'invocation state stays out of shared services, and every dependency has an explicit lifetime.',
        };
        $help = 'Hold the state in an instance whose lifetime composition declares, and pass it explicitly.';
        foreach ($code->within($namespace, NodeKind::PlainProperty, NodeKind::HookedProperty) as $property) {
            foreach ($code->children($property) as $modifier) {
                if ($modifier->kind === NodeKind::Modifier && strcasecmp($code->text($modifier), 'static') === 0) {
                    $report->error($property, 'static-state', $rule, 'Static property holds state shared by every invocation.', $reason, $help);
                }
            }
        }
        foreach ($code->within($namespace, NodeKind::Static) as $static) {
            $report->error($static, 'static-state', $rule, 'Static variable holds state shared by every invocation.', $reason, $help);
        }
        foreach ($code->within($namespace, NodeKind::Global) as $global) {
            $report->error($global, 'static-state', $rule, 'Global variable holds state shared by all code.', $reason, $help);
        }
        foreach ($code->within($namespace, NodeKind::DirectVariable) as $variable) {
            if ($code->text($variable) === '$GLOBALS') {
                $report->error($variable, 'static-state', $rule, '`$GLOBALS` holds state shared by all code.', $reason, $help);
            }
        }
    }

    /** Request and process input belong to driving adapters and composition. */
    private function inputs(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($place->role === Role::Presentation || $place->role === Role::Composition) {
            return;
        }
        $reason = 'composition configures resources and passes configuration explicitly; driving adapters decode request input.';
        $help = 'Read it in composition or a driving adapter, and pass the value in.';
        foreach ($code->within($namespace, NodeKind::DirectVariable) as $variable) {
            $name = $code->text($variable);
            if (in_array($name, self::SUPERGLOBALS, true)) {
                $report->error($variable, 'ambient-input', 'R06', "{$place->role->value} code reads `{$name}`.", $reason, $help);
            }
        }
        foreach ($code->within($namespace, NodeKind::FunctionCall) as $call) {
            $function = strtolower($code->name($code->unwrap($code->children($call)[0] ?? null)) ?? '');
            if (in_array($function, self::CONFIGURATION, true)) {
                $report->error($call, 'ambient-input', 'R06', "{$place->role->value} code calls `{$function}()`.", $reason, $help);
            }
        }
    }
}
