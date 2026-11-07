<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use UnexpectedValueException;

/** An event value or representation does not satisfy the published schema. */
final class MalformedEvent extends UnexpectedValueException {}
