<?php

declare(strict_types=1);

namespace App\Billing\Application;

use RuntimeException;

/** Aborts a unit of work whose conditional write lost to a concurrent change. */
final class ConcurrentChange extends RuntimeException {}
