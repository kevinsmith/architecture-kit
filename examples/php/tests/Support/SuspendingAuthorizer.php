<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Access\Contracts\Authorizer;
use Fiber;

/** Suspends the calling Fiber before each authorization, so other invocations run in between. */
final readonly class SuspendingAuthorizer implements Authorizer
{
    public function __construct(
        private Authorizer $inner,
    ) {}

    public function can(string $actor, string $permission, string $organization): bool
    {
        Fiber::suspend();

        return $this->inner->can($actor, $permission, $organization);
    }
}
