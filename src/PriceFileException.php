<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * A price file or price row failed validation. Carries every error found.
 */
final class PriceFileException extends SpendMeterException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private array $errors)
    {
        parent::__construct('Price file rejected: ' . implode('; ', array_slice($errors, 0, 5)));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
