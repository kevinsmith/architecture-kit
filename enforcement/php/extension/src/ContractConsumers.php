<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Override;

use function array_change_key_case;
use function array_filter;
use function array_keys;
use function array_pop;
use function array_values;
use function strtolower;

/**
 * R05: a published contract has a demonstrated external consumer. A contract counts as
 * consumed when production code in another module, other than composition, references it,
 * or when a consumed contract of the same owner references it. Tests are not analyzed and
 * do not count.
 */
final readonly class ContractConsumers implements AfterAnalysisHook
{
    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $contracts = [];
        foreach ($context->codebase->getClassLikeNames() as $name) {
            $place = $this->layout->place($name);
            if ($place?->role === Role::Contracts) {
                $contracts[strtolower($name)] = $place->module;
            }
        }
        $names = array_keys($contracts);
        $consumed = [];
        $referencedBy = [];
        $references = $context->analysis->references->getMultipleReferencesTo($names);
        foreach ($names as $index => $contract) {
            $owner = $contracts[$contract] ?? null;
            foreach ($references[$index] ?? [] as $reference) {
                $symbol = $reference->source->symbol;
                $origin = strtolower($symbol instanceof MemberIdentifier ? $symbol->class : $symbol ?? '');
                $place = $this->layout->place($origin);
                if ($place === null || $place->role === Role::Composition) {
                    continue;
                }
                if ($place->module !== $owner) {
                    $consumed[$contract] = true;
                } elseif (isset($contracts[$origin])) {
                    $referencedBy[$origin][$contract] = true;
                }
            }
        }
        // A consumed contract's published surface consumes the contracts it references.
        $pending = array_keys($consumed);
        while (($contract = array_pop($pending)) !== null) {
            foreach (array_keys($referencedBy[$contract] ?? []) as $referenced) {
                if (!isset($consumed[$referenced])) {
                    $consumed[$referenced] = true;
                    $pending[] = $referenced;
                }
            }
        }
        $exempt = array_change_key_case($this->layout->externalConsumers);
        $report = new Report($context);
        foreach (array_values(array_filter($names, static fn(string $name): bool => !isset($consumed[$name]) && !isset($exempt[$name]))) as $name) {
            $metadata = $context->codebase->getClassLike($name);
            if ($metadata === null) {
                continue;
            }
            $module = $contracts[$name];
            $report->error(
                $metadata->nameLocation ?? $metadata->location,
                'unconsumed-contract',
                'R05',
                "Published `{$metadata->originalName}` has no consumer outside {$module}.",
                'a contract is shared only for a demonstrated cross-module need: one provider and one external consumer.',
                "Move it into {$module}'s Application or Domain, or record why its consumer is outside this codebase in the architecture mapping.",
            );
        }
    }
}
