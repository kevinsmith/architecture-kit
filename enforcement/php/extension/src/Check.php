<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use Mago\Sdk\Syntax\Node;

/** A check over one classified namespace block of an analyzed file. */
interface Check
{
    public function check(Code $code, Place $place, Node $namespace, Report $report): void;
}
