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

use function explode;
use function in_array;
use function ltrim;
use function strcasecmp;
use function strtolower;

/**
 * R13: no external effect runs inside a transaction. Work passed to a configured transaction
 * method must be an inline closure, and the closure may not call a configured effect.
 */
final readonly class TransactionEffects implements Check
{
    private const array METHOD_CALLS = [NodeKind::MethodCall, NodeKind::NullSafeMethodCall, NodeKind::StaticMethodCall];

    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if ($this->layout->transactions === []) {
            return;
        }
        foreach ($code->within($namespace, ...self::METHOD_CALLS) as $call) {
            $transaction = $this->transaction($code, $call);
            if ($transaction === null) {
                continue;
            }
            foreach ($code->arguments($call) as $argument) {
                if (in_array($argument->kind, [NodeKind::Closure, NodeKind::ArrowFunction], true)) {
                    $this->effects($code, $argument, $transaction, $report);
                } elseif ($code->isCallable($argument)) {
                    $report->error(
                        $argument,
                        'transaction-callback',
                        'R13',
                        "Work for `{$transaction}` is a callback defined elsewhere.",
                        'effects inside a transaction are verifiable only in an inline closure.',
                        "Pass an inline closure to `{$transaction}`.",
                    );
                }
            }
        }
    }

    private function transaction(Code $code, Node $call): ?string
    {
        $member = $code->member($call);
        if ($member === null) {
            return null;
        }
        foreach ($this->layout->transactions as $transaction) {
            [$class, $method] = explode('::', $transaction, 2) + ['', ''];
            if (strcasecmp($member, $method) !== 0) {
                continue;
            }
            foreach ($code->receivers($call) as $receiver) {
                if ($code->isA($receiver, $class)) {
                    return $transaction;
                }
            }
        }

        return null;
    }

    private function effects(Code $code, Node $callback, string $transaction, Report $report): void
    {
        foreach ($code->within($callback, NodeKind::FunctionCall, ...self::METHOD_CALLS) as $call) {
            $effect = $this->effect($code, $call);
            if ($effect !== null) {
                $report->error(
                    $call,
                    'transaction-effect',
                    'R13',
                    "External effect `{$effect}` runs inside the `{$transaction}` transaction.",
                    'external effects run only after the triggering transaction commits.',
                    'Record a delivery intent, such as an outbox row, in the transaction and perform the effect after commit.',
                );
            }
        }
    }

    private function effect(Code $code, Node $call): ?string
    {
        if ($call->kind === NodeKind::FunctionCall) {
            $function = strtolower($code->name($code->unwrap($code->children($call)[0] ?? null)) ?? '');
            foreach ($this->layout->effects as $effect) {
                if (strtolower(ltrim($effect, '\\')) === $function) {
                    return $effect;
                }
            }

            return null;
        }
        foreach ($code->receivers($call) as $receiver) {
            foreach ($this->layout->effects as $effect) {
                if ($code->isA($receiver, $effect)) {
                    return $effect . '::' . ($code->member($call) ?? '');
                }
            }
        }

        return null;
    }
}
