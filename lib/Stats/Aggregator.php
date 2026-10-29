<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Stats;

use rex;
use rex_sql;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;

/**
 * Pflegt Tages- und Wochensummen sowie die Kennzahlen für die Übersicht (28 Tage gegen Vorzeitraum).
 */
final class Aggregator
{
    public static function weekStart(string $date): string
    {
        return self::shift($date, 1 - (int) date('N', (int) strtotime($date)));
    }

    /** Zählt Detailzeilen, Suchbegriffe und URLs eines Tages */
    public static function refreshDay(int $domainId, string $date): void
    {
        $sql = rex_sql::factory();
        $row = $sql->getArray(
            'SELECT COUNT(*) AS n, COALESCE(SUM(clicks), 0) AS c, COALESCE(SUM(impressions), 0) AS i,
                    COUNT(DISTINCT query_id) AS q, COUNT(DISTINCT url_id) AS u
             FROM ' . rex::getTable('vseo_sa') . ' WHERE domain_id = ? AND date = ?',
            [$domainId, $date],
        )[0];
        $sql->setQuery(
            'INSERT INTO ' . rex::getTable('vseo_sa_day') . '
                (domain_id, date, detail_rows, detail_clicks, detail_impressions, queries, urls, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE detail_rows = VALUES(detail_rows), detail_clicks = VALUES(detail_clicks),
                detail_impressions = VALUES(detail_impressions), queries = VALUES(queries), urls = VALUES(urls),
                imported_at = VALUES(imported_at)',
            [$domainId, $date, (int) $row['n'], (int) $row['c'], (int) $row['i'], (int) $row['q'], (int) $row['u']],
        );
    }

    /** @param list<string> $dates */
    public static function refreshWeeksFor(int $domainId, array $dates): void
    {
        $weeks = array_unique(array_map(self::weekStart(...), $dates));
        foreach ($weeks as $week) {
            self::refreshWeek($domainId, $week);
        }
    }

    public static function refreshWeek(int $domainId, string $week): void
    {
        $sql = rex_sql::factory();
        $end = date('Y-m-d', (int) strtotime($week . ' +6 days'));
        $t = $sql->getArray(
            'SELECT COUNT(*) AS days, COALESCE(SUM(clicks), 0) AS c, COALESCE(SUM(impressions), 0) AS i,
                    COALESCE(SUM(position_sum), 0) AS p, COALESCE(SUM(detail_rows), 0) AS dr
             FROM ' . rex::getTable('vseo_sa_day') . ' WHERE domain_id = ? AND date BETWEEN ? AND ?',
            [$domainId, $week, $end],
        )[0];
        $weekTable = rex::getTable('vseo_sa_week');
        if (0 === (int) $t['days']) {
            $sql->setQuery('DELETE FROM ' . $weekTable . ' WHERE domain_id = ? AND week = ?', [$domainId, $week]);
            return;
        }
        if ((int) $t['dr'] > 0) {
            $d = self::distinct($domainId, $week, $end);
        } else {
            // Detaildaten bereits gelöscht (Aufbewahrung) → bisherige Zählung behalten
            $old = $sql->getArray('SELECT queries, urls FROM ' . $weekTable . ' WHERE domain_id = ? AND week = ?', [$domainId, $week]);
            $d = ['queries' => (int) ($old[0]['queries'] ?? 0), 'urls' => (int) ($old[0]['urls'] ?? 0)];
        }
        $sql->setQuery(
            'INSERT INTO ' . $weekTable . ' (domain_id, week, days, clicks, impressions, position_sum, queries, urls)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE days = VALUES(days), clicks = VALUES(clicks), impressions = VALUES(impressions),
                position_sum = VALUES(position_sum), queries = VALUES(queries), urls = VALUES(urls)',
            [$domainId, $week, (int) $t['days'], (int) $t['c'], (int) $t['i'], (float) $t['p'], $d['queries'], $d['urls']],
        );
    }

    /** Alle Wochen einer Domain neu berechnen */
    public static function rebuildWeeks(int $domainId): int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT MIN(date) AS a, MAX(date) AS b FROM ' . rex::getTable('vseo_sa_day') . ' WHERE domain_id = ?',
            [$domainId],
        );
        if (!$rows[0]['a']) {
            return 0;
        }
        $n = 0;
        for ($w = self::weekStart((string) $rows[0]['a']); $w <= (string) $rows[0]['b']; $w = date('Y-m-d', (int) strtotime($w . ' +7 days'))) {
            self::refreshWeek($domainId, $w);
            ++$n;
        }
        return $n;
    }

    /**
     * Kennzahlen der letzten 28 Tage mit Daten gegen die 28 Tage davor.
     */
    public static function refreshKpi(int $domainId): void
    {
        $sql = rex_sql::factory();
        $end = $sql->getArray(
            'SELECT MAX(date) AS d FROM ' . rex::getTable('vseo_sa_day') . ' WHERE domain_id = ? AND detail_rows > 0',
            [$domainId],
        )[0]['d'] ?? null;
        if (!$end) {
            DomainRepository::patch($domainId, ['kpi' => null]);
            return;
        }
        $end = (string) $end;
        $kpi = [
            'end' => $end,
            'cur' => self::period($domainId, self::shift($end, -27), $end),
            'prev' => self::period($domainId, self::shift($end, -55), self::shift($end, -28)),
            'computed' => date('Y-m-d H:i:s'),
        ];
        DomainRepository::patch($domainId, ['kpi' => json_encode($kpi)]);
    }

    /**
     * Summen eines Zeitraums. Klicks/Impressionen/Position aus den Tagessummen,
     * Suchbegriffe/URLs als verschiedene Werte aus den Detaildaten.
     *
     * @return array{from: string, to: string, days: int, clicks: int, impressions: int, position: float|null, queries: int, urls: int}
     */
    public static function period(int $domainId, string $from, string $to): array
    {
        $t = rex_sql::factory()->getArray(
            'SELECT COUNT(*) AS days, COALESCE(SUM(clicks), 0) AS c, COALESCE(SUM(impressions), 0) AS i, COALESCE(SUM(position_sum), 0) AS p
             FROM ' . rex::getTable('vseo_sa_day') . ' WHERE domain_id = ? AND date BETWEEN ? AND ?',
            [$domainId, $from, $to],
        )[0];
        $d = self::distinct($domainId, $from, $to);
        return [
            'from' => $from,
            'to' => $to,
            'days' => (int) $t['days'],
            'clicks' => (int) $t['c'],
            'impressions' => (int) $t['i'],
            'position' => (int) $t['i'] > 0 ? round((float) $t['p'] / (int) $t['i'], 2) : null,
            'queries' => $d['queries'],
            'urls' => $d['urls'],
        ];
    }

    /** @return array{queries: int, urls: int} */
    private static function distinct(int $domainId, string $from, string $to): array
    {
        $row = rex_sql::factory()->getArray(
            'SELECT COUNT(DISTINCT query_id) AS q, COUNT(DISTINCT url_id) AS u
             FROM ' . rex::getTable('vseo_sa') . ' WHERE domain_id = ? AND date BETWEEN ? AND ?',
            [$domainId, $from, $to],
        )[0];
        return ['queries' => (int) $row['q'], 'urls' => (int) $row['u']];
    }

    public static function shift(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    }
}
