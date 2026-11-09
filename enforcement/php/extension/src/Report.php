<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Analyzer\LifecycleContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;

/** Reports errors that name the core rule they enforce (R17). */
final readonly class Report
{
    public function __construct(
        private LifecycleContext $context,
    ) {}

    /**
     * @param string $code issue code; Mago prefixes the plugin identifier
     * @param string $rule core rule ID, such as `R09`
     * @param string $reason why the rule forbids it
     * @param string $help how to comply
     */
    public function error(Node|SourceLocation $at, string $code, string $rule, string $message, string $reason, string $help): void
    {
        $issue = $at instanceof Node ? Issue::new($message, $at->span) : Issue::at($message, $at);
        $this->context->report(Level::Error, $code, $issue->withNote("{$rule}: {$reason}")->withHelp($help));
    }
}
