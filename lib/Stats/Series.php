<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Stats;

use rex;
use rex_sql;

/**
 * Zeitreihen für Diagramme aus den Wochensummen.
 */
final class Series
{
    /**
     * @param int $months 0 = alles
     * @return list<array{week: string, days: int, clicks: int, impressions: int, position: float|null, queries: int, urls: int}>
     */
    public static function weeks(int $domainId, int $months = 0): array
    {
        $params = [$domainId];
        $where = 'domain_id = ?';
        if ($months > 0) {
            $where .= ' AND week >= ?';
            $params[] = Aggregator::weekStart(date('Y-m-d', (int) strtotime('-' . $months . ' months')));
        }
        $rows = rex_sql::factory()->getArray(
            'SELECT week, days, clicks, impressions, position_sum, queries, urls FROM ' . rex::getTable('vseo_sa_week')
            . ' WHERE ' . $where . ' ORDER BY week',
            $params,
        );
        return array_map(static fn (array $r) => [
            'week' => (string) $r['week'],
            'days' => (int) $r['days'],
            'clicks' => (int) $r['clicks'],
            'impressions' => (int) $r['impressions'],
            'position' => (int) $r['impressions'] > 0 ? round((float) $r['position_sum'] / (int) $r['impressions'], 2) : null,
            'queries' => (int) $r['queries'],
            'urls' => (int) $r['urls'],
        ], $rows);
    }

    /**
     * Unvollständige Randwochen (Beginn des Backfills, laufende Woche) verzerren den Verlauf.
     *
     * @template T of array{days: int}
     * @param list<T> $weeks
     * @return list<T>
     */
    public static function completeOnly(array $weeks): array
    {
        while ($weeks && $weeks[0]['days'] < 7) {
            array_shift($weeks);
        }
        while ($weeks && end($weeks)['days'] < 7) {
            array_pop($weeks);
        }
        return $weeks;
    }

    /** @return list<array{date: string, date_end: string|null, name: string}> */
    public static function coreUpdates(?string $from = null): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT date, date_end, name FROM ' . rex::getTable('vseo_core_update') . ' WHERE date >= ? ORDER BY date',
            [$from ?? '1970-01-01'],
        );
        return array_map(static fn (array $r) => ['date' => (string) $r['date'], 'date_end' => $r['date_end'] ? (string) $r['date_end'] : null, 'name' => (string) $r['name']], $rows);
    }

    /**
     * Kleines Inline-SVG ohne JavaScript. Farbe über currentColor.
     *
     * @param list<int|float> $values
     */
    public static function sparkline(array $values, int $width = 120, int $height = 28): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $min = min($values);
        $max = max($values);
        $range = $max - $min ?: 1;
        $points = [];
        foreach ($values as $i => $value) {
            $x = round($i / ($n - 1) * ($width - 2) + 1, 1);
            $y = round($height - 1 - ($value - $min) / $range * ($height - 2), 1);
            $points[] = $x . ',' . $y;
        }
        return '<svg class="vseo-spark" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" aria-hidden="true">'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" points="' . implode(' ', $points) . '"/></svg>';
    }
}
