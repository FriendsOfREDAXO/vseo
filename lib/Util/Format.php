<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Util;

use rex_escape;
use rex_i18n;

final class Format
{
    public static function int(int|float|null $value): string
    {
        if (null === $value) {
            return '–';
        }
        return self::de() ? number_format((float) $value, 0, ',', '.') : number_format((float) $value, 0, '.', ',');
    }

    public static function dec(float|null $value, int $decimals = 1): string
    {
        if (null === $value) {
            return '–';
        }
        return self::de() ? number_format($value, $decimals, ',', '.') : number_format($value, $decimals, '.', ',');
    }

    public static function date(?string $date): string
    {
        if (!$date) {
            return '–';
        }
        $ts = strtotime($date);
        return false === $ts ? $date : date(self::de() ? 'd.m.Y' : 'Y-m-d', $ts);
    }

    /**
     * Veränderung in Prozent als Badge. $inverse: kleiner ist besser (Position).
     */
    public static function delta(float|int|null $current, float|int|null $previous, bool $inverse = false): string
    {
        if (null === $current || null === $previous || 0.0 === (float) $previous) {
            return '<span class="vseo-delta">–</span>';
        }
        $pct = ((float) $current - (float) $previous) / (float) $previous * 100;
        $good = $inverse ? $pct < 0 : $pct > 0;
        $class = abs($pct) < 0.5 ? 'is-neutral' : ($good ? 'is-up' : 'is-down');
        return '<span class="vseo-delta ' . $class . '">' . ($pct > 0 ? '+' : '') . rex_escape(self::dec($pct)) . ' %</span>';
    }

    private static function de(): bool
    {
        return str_starts_with(rex_i18n::getLocale(), 'de');
    }
}
