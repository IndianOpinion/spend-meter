<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use RuntimeException;

/**
 * Base class for every exception spend-meter throws internally.
 *
 * Public entry points (the pre-spend filter, the record and release actions
 * and their function forms) never let these escape to the caller.
 */
class SpendMeterException extends RuntimeException
{
}
