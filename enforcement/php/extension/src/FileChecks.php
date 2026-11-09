<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Analyzer\AfterFileAnalysisContext;
use Mago\Sdk\Analyzer\AfterFileAnalysisHook;
use Mago\Sdk\Syntax\NodeKind;
use Override;

/** Runs every per-file check on each classified namespace block. */
final readonly class FileChecks implements AfterFileAnalysisHook
{
    /**
     * @param list<Check> $checks
     */
    public function __construct(
        private Layout $layout,
        private array $checks,
    ) {}

    #[Override]
    public function getRequirements(): array
    {
        return [];
    }

    #[Override]
    public function afterFileAnalysis(AfterFileAnalysisContext $context): void
    {
        $code = new Code($context->analysis, $context->codebase);
        $report = new Report($context);
        foreach ($code->source->getNodes(NodeKind::Namespace) as $namespace) {
            $place = $this->layout->place($code->name($code->child($namespace, NodeKind::Identifier)) ?? '');
            if ($place === null) {
                continue;
            }
            foreach ($this->checks as $check) {
                $check->check($code, $place, $namespace, $report);
            }
        }
    }
}
