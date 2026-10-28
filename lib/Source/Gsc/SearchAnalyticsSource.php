<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source\Gsc;

use rex;
use rex_addon;
use rex_i18n;
use rex_sql;
use RuntimeException;
use Viewsion\SeoMonitor\Model\AccountRepository;
use Viewsion\SeoMonitor\Model\Dictionary;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Source\DataSourceInterface;
use Viewsion\SeoMonitor\Stats\Aggregator;
use Viewsion\SeoMonitor\Util\Budget;

/**
 * Import der Search-Analytics-Daten.
 *
 * - Vorwärts: die letzten reload_days Tage werden bei jedem Lauf neu geladen (Google liefert mit Verzug).
 * - Backfill: läuft rückwärts bis import_from, Stand in backfill_cursor, damit Cronjobs in Etappen arbeiten.
 */
final class SearchAnalyticsSource implements DataSourceInterface
{
    private const DEVICES = ['DESKTOP' => 1, 'MOBILE' => 2, 'TABLET' => 3];
    private const BLOCK_DAYS = 30;

    /** @var array<int, GscClient> */
    private array $clients = [];

    public function key(): string
    {
        return 'gsc';
    }

    public function label(): string
    {
        return rex_i18n::msg('vsn_seo_source_gsc');
    }

    public function supports(Domain $domain): bool
    {
        return $domain->hasGsc();
    }

    public static function deviceName(int $device): string
    {
        return (string) (array_search($device, self::DEVICES, true) ?: '?');
    }

    public function import(Domain $domain, Budget $budget): array
    {
        $addon = rex_addon::get('vsn_seo');
        $today = date('Y-m-d');
        $messages = [];
        $touched = [];

        // 1. Vorwärts
        $start = Aggregator::shift($today, -max(1, (int) $addon->getConfig('reload_days', 5)));
        // nach einer Pause (Cronjob lief nicht) ab dem letzten vollständigen Tag nachholen
        if (null !== $domain->forwardUntil && Aggregator::shift($domain->forwardUntil, 1) < $start) {
            $start = Aggregator::shift($domain->forwardUntil, 1);
        }
        $end = Aggregator::shift($today, -1);
        $done = $this->importRange($domain, $start, $end, $budget, false);
        if ($done) {
            $domain->forwardUntil = max($done);
            DomainRepository::patch($domain->id, ['forward_until' => $domain->forwardUntil]);
        }
        $touched = array_merge($touched, $done);
        $messages[] = sprintf('%s: %d day(s) %s … %s', $domain->name, count($done), $start, $end);

        // 2. Backfill
        if (null === $domain->importFrom) {
            $domain->importFrom = date('Y-m-d', (int) strtotime('-' . (int) $addon->getConfig('backfill_months', 16) . ' months'));
            DomainRepository::patch($domain->id, ['import_from' => $domain->importFrom]);
        }
        if (!$budget->exceeded() && !$domain->backfillDone()) {
            $cursor = $domain->backfillCursor ?? Aggregator::shift($start, -1);
            $from = $cursor;
            while ($cursor >= $domain->importFrom && !$budget->exceeded()) {
                $blockStart = max($domain->importFrom, Aggregator::shift($cursor, -(self::BLOCK_DAYS - 1)));
                $done = $this->importRange($domain, $blockStart, $cursor, $budget, true);
                $touched = array_merge($touched, $done);
                if (!$done) {
                    break;
                }
                $cursor = Aggregator::shift(min($done), -1);
                DomainRepository::patch($domain->id, ['backfill_cursor' => $cursor]);
            }
            $domain->backfillCursor = $cursor;
            $messages[] = sprintf('%s: backfill %s → %s%s', $domain->name, $from, $cursor, $domain->backfillDone() ? ' (done)' : '');
        }

        if ($touched) {
            Aggregator::refreshWeeksFor($domain->id, $touched);
            Aggregator::refreshKpi($domain->id);
        }
        return $messages;
    }

    /**
     * Lädt einen Zeitraum: zuerst die Tagessummen in einer Abfrage, danach die Details je Tag.
     *
     * @param bool $descending Backfill arbeitet vom neuesten zum ältesten Tag
     * @return list<string> fertig importierte Tage
     */
    public function importRange(Domain $domain, string $from, string $to, Budget $budget, bool $descending): array
    {
        if ($from > $to) {
            return [];
        }
        $client = $this->client($domain);
        $totals = $this->importTotals($client, $domain, $from, $to);

        $days = [];
        for ($d = $from; $d <= $to; $d = Aggregator::shift($d, 1)) {
            $days[] = $d;
        }
        if ($descending) {
            $days = array_reverse($days);
        }

        $done = [];
        foreach ($days as $day) {
            if ($budget->exceeded()) {
                break;
            }
            // Tage ohne Impressionen brauchen keine Detailabfrage
            if (($totals[$day] ?? 0) > 0) {
                $this->importDay($client, $domain, $day);
            }
            $done[] = $day;
        }
        return $done;
    }

    /**
     * @return array<string, int> Impressionen je Tag
     */
    private function importTotals(GscClient $client, Domain $domain, string $from, string $to): array
    {
        $rows = $client->searchAnalytics($domain->gscProperty, $this->body($from, $to, ['date'], 0));
        $result = [];
        $values = [];
        foreach ($rows as $row) {
            $date = (string) $row['keys'][0];
            $impressions = (int) $row['impressions'];
            $result[$date] = $impressions;
            $values[] = sprintf(
                "(%d, '%s', %d, %d, %F, NOW())",
                $domain->id,
                $date,
                (int) $row['clicks'],
                $impressions,
                (float) $row['position'] * $impressions,
            );
        }
        if ($values) {
            rex_sql::factory()->setQuery(
                'INSERT INTO ' . rex::getTable('vsn_seo_sa_day') . ' (domain_id, date, clicks, impressions, position_sum, imported_at)
                 VALUES ' . implode(',', $values) . '
                 ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions),
                    position_sum = VALUES(position_sum), imported_at = VALUES(imported_at)',
            );
        }
        return $result;
    }

    private function importDay(GscClient $client, Domain $domain, string $date): void
    {
        $rowLimit = max(1000, min(25000, (int) rex_addon::get('vsn_seo')->getConfig('row_limit', 25000)));
        $table = rex::getTable('vsn_seo_sa');
        $sql = rex_sql::factory();
        // Tag komplett ersetzen; scheitert eine Seite, bleibt der alte Stand erhalten
        $sql->transactional(function () use ($sql, $client, $domain, $date, $table, $rowLimit) {
            $sql->setQuery('DELETE FROM ' . $table . ' WHERE domain_id = ? AND date = ?', [$domain->id, $date]);
            $startRow = 0;
            do {
                $rows = $client->searchAnalytics(
                    $domain->gscProperty,
                    $this->body($date, $date, ['query', 'page', 'device', 'country'], $rowLimit, $startRow),
                );
                $this->insertRows($sql, $table, $domain->id, $date, $rows);
                $startRow += $rowLimit;
            } while (count($rows) >= $rowLimit);
        });
        Aggregator::refreshDay($domain->id, $date);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(rex_sql $sql, string $table, int $domainId, string $date, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $queries = [];
        $urls = [];
        foreach ($rows as $i => $row) {
            $rows[$i]['keys'][0] = Dictionary::normalize(Dictionary::QUERY, (string) $row['keys'][0]);
            $rows[$i]['keys'][1] = Dictionary::normalize(Dictionary::URL, (string) $row['keys'][1]);
            $queries[] = $rows[$i]['keys'][0];
            $urls[] = $rows[$i]['keys'][1];
        }
        $queryIds = Dictionary::ids(Dictionary::QUERY, $queries);
        $urlIds = Dictionary::ids(Dictionary::URL, $urls);

        foreach (array_chunk($rows, 1000) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                [$query, $url, $device, $country] = $row['keys'];
                $country = strtolower((string) $country);
                if (1 !== preg_match('/^[a-z]{3}$/', $country)) {
                    $country = 'zzz';
                }
                $impressions = (int) $row['impressions'];
                $values[] = sprintf(
                    "(%d, '%s', %d, %d, %d, '%s', %d, %d, %F)",
                    $domainId,
                    $date,
                    $queryIds[$query],
                    $urlIds[$url],
                    self::DEVICES[strtoupper((string) $device)] ?? 0,
                    $country,
                    (int) $row['clicks'],
                    $impressions,
                    (float) $row['position'] * $impressions,
                );
            }
            $sql->setQuery(
                'INSERT INTO ' . $table . ' (domain_id, date, query_id, url_id, device, country, clicks, impressions, position_sum)
                 VALUES ' . implode(',', $values) . '
                 ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks), impressions = impressions + VALUES(impressions),
                    position_sum = position_sum + VALUES(position_sum)',
            );
        }
    }

    /**
     * @param list<string> $dimensions
     * @return array<string, mixed>
     */
    private function body(string $from, string $to, array $dimensions, int $rowLimit, int $startRow = 0): array
    {
        $body = [
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => $dimensions,
            'type' => 'web',
            'dataState' => 'all' === rex_addon::get('vsn_seo')->getConfig('data_state') ? 'all' : 'final',
        ];
        if ($rowLimit > 0) {
            $body['rowLimit'] = $rowLimit;
            $body['startRow'] = $startRow;
        } else {
            $body['rowLimit'] = 25000;
        }
        return $body;
    }


    private function client(Domain $domain): GscClient
    {
        $id = (int) $domain->gscAccountId;
        if (!isset($this->clients[$id])) {
            $account = AccountRepository::get($id);
            if (!$account) {
                throw new RuntimeException('Search Console account #' . $id . ' not found');
            }
            $this->clients[$id] = new GscClient($account);
        }
        return $this->clients[$id];
    }
}
