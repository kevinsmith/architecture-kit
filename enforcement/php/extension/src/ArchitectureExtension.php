<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Override;
use ArchitectureCheck\Checks\AmbientState;
use ArchitectureCheck\Checks\HiddenDependencies;
use ArchitectureCheck\Checks\PublishedSignatures;
use ArchitectureCheck\Checks\SharedServiceState;
use ArchitectureCheck\Checks\TableOwnership;
use ArchitectureCheck\Checks\TransactionEffects;

/**
 * Mago analyzer plugin for the architecture rules the guard cannot express. Its issues are
 * ordinary analyzer issues, so `mago analyze` and the analyzer baseline cover them.
 */
final readonly class ArchitectureExtension implements Plugin
{
    private function __construct(
        private Layout $layout,
    ) {}

    public static function create(Layout $layout): Extension
    {
        return new Extension(
            identifier: 'example/architecture-check',
            name: 'Architecture checks',
            version: '1.0.0',
            analyzerPlugins: [new self($layout)],
        );
    }

    #[Override]
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: 'architecture',
            name: 'Architecture',
            description: 'Checks the architecture rules that the guard cannot express.',
        );
    }

    #[Override]
    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterFileAnalysisHook(new FileChecks($this->layout, [
            new TableOwnership($this->layout),
            new TransactionEffects($this->layout),
            new PublishedSignatures($this->layout),
            new AmbientState(),
            new SharedServiceState(),
            new HiddenDependencies($this->layout),
        ]));
        $registry->registerAfterAnalysisHook(new ContractConsumers($this->layout));
    }
}
