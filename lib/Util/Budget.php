<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Util;

/**
 * Zeitbudget eines Laufs, damit Cronjobs in Etappen arbeiten. 0 = unbegrenzt.
 */
final class Budget
{
    private float $start;

    public function __construct(private readonly int $seconds)
    {
        $this->start = microtime(true);
    }

    /** @phpstan-impure */
    public function exceeded(): bool
    {
        return $this->seconds > 0 && (microtime(true) - $this->start) >= $this->seconds;
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->start;
    }
}
