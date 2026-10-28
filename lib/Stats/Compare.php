<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Stats;

use rex;
use rex_sql;

/**
 * Vergleich zweier Zeiträume aus den Detaildaten, je Suchbegriff oder URL.
 */
final class Compare
{
    public const DIM_QUERY = 'query';
    public const DIM_URL = 'url';

    /**
     * @param array{device?: int, country?: string, search?: string} $filter
     * @return array<int, array{id: int, c1: int, i1: int, p1: float|null, c0: int, i0: int, p0: float|null}> nach ID
     */
    public static function rows(int $domainId, string $dim, Period $period, array $filter = []): array
    {
        $col = self::DIM_URL === $dim ? 'url_id' : 'query_id';
        $params = [
            $period->curFrom, $period->curTo, $period->curFrom, $period->curTo, $period->curFrom, $period->curTo,
            $period->prevFrom, $period->prevTo, $period->prevFrom, $period->prevTo, $period->prevFrom, $period->prevTo,
            $domainId, $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo,
        ];
        $join = '';
        $where = '';
        if (!empty($filter['device'])) {
            $where .= ' AND s.device = ?';
            $params[] = (int) $filter['device'];
        }
        if (!empty($filter['country'])) {
            $where .= ' AND s.country = ?';
            $params[] = (string) $filter['country'];
        }
        if (isset($filter['search']) && '' !== trim($filter['search'])) {
            $join = ' JOIN ' . rex::getTable('vsn_seo_' . $dim) . ' d ON d.id = s.' . $col;
            $where .= ' AND d.`' . $dim . '` LIKE ?';
            $params[] = '%' . addcslashes(trim($filter['search']), '%_\\') . '%';
        }
        $rows = rex_sql::factory()->getArray(
            'SELECT s.' . $col . ' AS id,
                SUM(IF(s.date BETWEEN ? AND ?, s.clicks, 0)) AS c1,
                SUM(IF(s.date BETWEEN ? AND ?, s.impressions, 0)) AS i1,
                SUM(IF(s.date BETWEEN ? AND ?, s.position_sum, 0)) AS p1,
                SUM(IF(s.date BETWEEN ? AND ?, s.clicks, 0)) AS c0,
                SUM(IF(s.date BETWEEN ? AND ?, s.impressions, 0)) AS i0,
                SUM(IF(s.date BETWEEN ? AND ?, s.position_sum, 0)) AS p0
             FROM ' . rex::getTable('vsn_seo_sa') . ' s' . $join . '
             WHERE s.domain_id = ? AND (s.date BETWEEN ? AND ? OR s.date BETWEEN ? AND ?)' . $where . '
             GROUP BY s.' . $col,
            $params,
        );
        $result = [];
        foreach ($rows as $r) {
            $i1 = (int) $r['i1'];
            $i0 = (int) $r['i0'];
            $result[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'c1' => (int) $r['c1'],
                'i1' => $i1,
                'p1' => $i1 > 0 ? (float) $r['p1'] / $i1 : null,
                'c0' => (int) $r['c0'],
                'i0' => $i0,
                'p0' => $i0 > 0 ? (float) $r['p0'] / $i0 : null,
            ];
        }
        return $result;
    }

    /**
     * Anzahl je Zeitraum, neu hinzugekommen, weggefallen.
     *
     * @param array<int, array{i1: int, i0: int}> $rows
     * @return array{cur: int, prev: int, new: int, gone: int}
     */
    public static function summary(array $rows): array
    {
        $s = ['cur' => 0, 'prev' => 0, 'new' => 0, 'gone' => 0];
        foreach ($rows as $r) {
            $s['cur'] += (int) ($r['i1'] > 0);
            $s['prev'] += (int) ($r['i0'] > 0);
            $s['new'] += (int) ($r['i1'] > 0 && 0 === $r['i0']);
            $s['gone'] += (int) (0 === $r['i1'] && $r['i0'] > 0);
        }
        return $s;
    }

    /**
     * Nach Differenz sortieren. $metric: clicks oder impressions.
     *
     * @template T of array{c1: int, i1: int, c0: int, i0: int}
     * @param array<int, T> $rows
     * @return list<T>
     */
    public static function sorted(array $rows, string $metric, bool $winners, int $limit = 0): array
    {
        [$a, $b] = 'clicks' === $metric ? ['c1', 'c0'] : ['i1', 'i0'];
        $rows = array_filter($rows, static fn ($r) => $winners ? $r[$a] > $r[$b] : $r[$a] < $r[$b]);
        usort($rows, static function ($x, $y) use ($a, $b, $winners) {
            $dx = $x[$a] - $x[$b];
            $dy = $y[$a] - $y[$b];
            return $winners ? $dy <=> $dx : $dx <=> $dy;
        });
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Verlorene URLs: im Vorzeitraum mindestens $minImpressions, jetzt höchstens $maxShare davon.
     *
     * @param array<int, array{id: int, c1: int, i1: int, p1: float|null, c0: int, i0: int, p0: float|null}> $rows URL-Zeilen
     * @return list<array{id: int, c1: int, i1: int, p1: float|null, c0: int, i0: int, p0: float|null}>
     */
    public static function lost(array $rows, int $minImpressions, float $maxShare): array
    {
        $lost = array_filter($rows, static fn ($r) => $r['i0'] >= $minImpressions && $r['i1'] <= $r['i0'] * $maxShare);
        usort($lost, static fn ($x, $y) => [$y['c0'], $y['i0']] <=> [$x['c0'], $x['i0']]);
        return $lost;
    }

    /**
     * Texte zu IDs aus dem Wörterbuch.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function labels(string $dim, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $result = [];
        $sql = rex_sql::factory();
        foreach (array_chunk(array_unique($ids), 1000) as $chunk) {
            foreach ($sql->getArray(
                'SELECT id, `' . $dim . '` AS v FROM ' . rex::getTable('vsn_seo_' . $dim) . ' WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')',
            ) as $row) {
                $result[(int) $row['id']] = (string) $row['v'];
            }
        }
        return $result;
    }

    /**
     * Länder mit den meisten Impressionen im Zeitraum, für den Filter.
     *
     * @return list<string>
     */
    public static function countries(int $domainId, Period $period, int $limit = 30): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT country FROM ' . rex::getTable('vsn_seo_sa') . ' WHERE domain_id = ? AND date BETWEEN ? AND ?
             GROUP BY country ORDER BY SUM(impressions) DESC LIMIT ' . $limit,
            [$domainId, $period->prevFrom < $period->curFrom ? $period->prevFrom : $period->curFrom, $period->curTo],
        );
        return array_map(static fn ($r) => (string) $r['country'], $rows);
    }

    /**
     * Crawler-Stand zu URLs.
     *
     * @param list<int> $urlIds
     * @return array<int, array{status: int|null, location: string|null, final_status: int|null, checked_at: string|null, noindex: bool, error: string|null}>
     */
    public static function crawlStatus(int $domainId, array $urlIds): array
    {
        if (!$urlIds) {
            return [];
        }
        $result = [];
        foreach (rex_sql::factory()->getArray(
            'SELECT url_id, status, location, final_status, checked_at, noindex, error FROM ' . rex::getTable('vsn_seo_crawl')
            . ' WHERE domain_id = ? AND url_id IN (' . implode(',', array_map('intval', $urlIds)) . ')',
            [$domainId],
        ) as $r) {
            $result[(int) $r['url_id']] = [
                'status' => null !== $r['checked_at'] ? (int) $r['status'] : null,
                'location' => $r['location'] ? (string) $r['location'] : null,
                'final_status' => null !== $r['final_status'] ? (int) $r['final_status'] : null,
                'checked_at' => $r['checked_at'] ? (string) $r['checked_at'] : null,
                'noindex' => (bool) $r['noindex'],
                'error' => $r['error'] ? (string) $r['error'] : null,
            ];
        }
        return $result;
    }
}
