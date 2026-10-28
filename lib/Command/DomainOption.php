<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Command;

use Viewsion\SeoMonitor\Model\DomainRepository;

final class DomainOption
{
    /**
     * @return int|null|false null = alle, false = unbekannt
     */
    public static function resolve(mixed $value): int|null|false
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $value = (string) $value;
        if (ctype_digit($value)) {
            return DomainRepository::get((int) $value)->id ?? false;
        }
        return DomainRepository::findByHost($value)->id ?? false;
    }
}
