<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * An intent, a call array, a setting or a CLI argument failed validation.
 */
final class ValidationException extends SpendMeterException
{
    public function __construct(private string $key, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The first key that failed validation.
     */
    public function key(): string
    {
        return $this->key;
    }
}
