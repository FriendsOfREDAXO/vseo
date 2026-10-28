<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source\Gsc;

use rex;
use rex_addon;
use rex_i18n;
use rex_sql;
use RuntimeException;
use Viewsion\SeoMonitor\Model\AccountRepository;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Source\DataSourceInterface;
use Viewsion\SeoMonitor\Stats\Compare;
use Viewsion\SeoMonitor\Stats\Period;
use Viewsion\SeoMonitor\Util\Budget;

/**
 * Tägliche Stichprobe über die URL Inspection API: zuerst URLs mit starkem Verlust,
 * dann neue URLs aus der Sitemap, dann die Top-URLs nach Klicks.
 * Gespeichert werden Indexstatus, Google-canonical und letzter Crawl.
 */
final class InspectionSource implements DataSourceInterface
{
    public const REASON_LOSS = 'loss';
    public const REASON_NEW = 'new';
    public const REASON_TOP = 'top';

    public function key(): string
    {
        return 'gsc_inspect';
    }

    public function label(): string
    {
        return rex_i18n::msg('vsn_seo_source_inspect');
    }

    public function supports(Domain $domain): bool
    {
        return $domain->hasGsc() && (int) rex_addon::get('vsn_seo')->getConfig('inspect_per_day', 50) > 0;
    }

    public static function table(): string
    {
        return rex::getTable('vsn_seo_inspect');
    }

    /** In der Stichprobe als indexiert gilt, was Google mit PASS bewertet */
    public static function isIndexed(string $verdict): bool
    {
        return 'PASS' === $verdict;
    }

    public function import(Domain $domain, Budget $budget): array
    {
        $perDay = min(2000, (int) rex_addon::get('vsn_seo')->getConfig('inspect_per_day', 50));
        $sql = rex_sql::factory();
        $today = date('Y-m-d');
        $done = (int) $sql->getArray(
            'SELECT COUNT(*) AS c FROM ' . self::table() . ' WHERE domain_id = ? AND inspected_at >= ?',
            [$domain->id, $today . ' 00:00:00'],
        )[0]['c'];
        $open = $perDay - $done;
        if ($open <= 0 || $budget->exceeded()) {
            return [];
        }

        $account = AccountRepository::get((int) $domain->gscAccountId);
        if (!$account) {
            throw new RuntimeException('Search Console account #' . $domain->gscAccountId . ' not found');
        }
        $client = new GscClient($account);
        $count = 0;
        $error = null;
        foreach ($this->candidates($domain, $open) as $urlId => [$url, $reason]) {
            if ($budget->exceeded()) {
                break;
            }
            try {
                $result = $client->inspect($domain->gscProperty, $url);
                $this->store($domain->id, $urlId, $reason, $result, null);
            } catch (RuntimeException $e) {
                // Kontingent erschöpft oder Property nicht berechtigt: für heute aufhören
                $error = $e->getMessage();
                if (str_contains($error, 'uota') || str_contains($error, 'permission')) {
                    break;
                }
                $this->store($domain->id, $urlId, $reason, [], mb_substr($error, 0, 255));
            }
            ++$count;
        }
        self::snapshot($domain->id);
        if ($error && 0 === $count) {
            throw new RuntimeException($error);
        }
        return [sprintf('%s: %d URL(s) inspected', $domain->name, $count)];
    }

    /**
     * Kandidaten in fester Reihenfolge; ausgelassen wird, was im Prüfabstand schon inspiziert wurde.
     *
     * @return array<int, array{string, string}> url_id => [URL, Anlass]
     */
    private function candidates(Domain $domain, int $limit): array
    {
        $days = max(1, (int) rex_addon::get('vsn_seo')->getConfig('inspect_interval_days', 14));
        $sql = rex_sql::factory();
        $recent = [];
        foreach ($sql->getArray(
            'SELECT url_id FROM ' . self::table() . ' WHERE domain_id = ? AND inspected_at >= ?',
            [$domain->id, date('Y-m-d H:i:s', time() - $days * 86400)],
        ) as $row) {
            $recent[(int) $row['url_id']] = true;
        }

        $result = [];
        $add = static function (array $ids, string $reason) use (&$result, &$recent, $limit): void {
            foreach ($ids as $id) {
                if (count($result) >= $limit) {
                    return;
                }
                if (!isset($recent[$id]) && !isset($result[$id])) {
                    $result[$id] = $reason;
                }
            }
        };

        // 1. starker Verlust in den letzten 28 Tagen
        $lost = Compare::lost(Compare::rows($domain->id, Compare::DIM_URL, Period::preset($domain, 28)), 10, 0.3);
        $add(array_column($lost, 'id'), self::REASON_LOSS);

        // 2. neue URLs aus der Sitemap
        $add(array_map('intval', array_column($sql->getArray(
            'SELECT url_id FROM ' . rex::getTable('vsn_seo_crawl') . ' WHERE domain_id = ? AND in_sitemap = 1 AND first_seen >= ? ORDER BY first_seen DESC LIMIT 2000',
            [$domain->id, date('Y-m-d H:i:s', time() - 14 * 86400)],
        ), 'url_id')), self::REASON_NEW);

        // 3. Top-URLs nach Klicks, dann Impressionen
        $to = Period::lastDay($domain);
        $add(array_map('intval', array_column($sql->getArray(
            'SELECT url_id FROM ' . rex::getTable('vsn_seo_sa') . ' WHERE domain_id = ? AND date BETWEEN ? AND ?
             GROUP BY url_id ORDER BY SUM(clicks) DESC, SUM(impressions) DESC LIMIT 3000',
            [$domain->id, date('Y-m-d', (int) strtotime($to . ' -27 days')), $to],
        ), 'url_id')), self::REASON_TOP);

        $labels = Compare::labels(Compare::DIM_URL, array_keys($result));
        $out = [];
        foreach ($result as $id => $reason) {
            // nur URLs der Property; Domain-Properties umfassen auch Subdomains
            if (isset($labels[$id]) && $domain->ownsUrl($labels[$id])) {
                $out[$id] = [$labels[$id], $reason];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $r indexStatusResult */
    private function store(int $domainId, int $urlId, string $reason, array $r, ?string $error): void
    {
        $lastCrawl = isset($r['lastCrawlTime']) && false !== ($ts = strtotime((string) $r['lastCrawlTime'])) ? date('Y-m-d H:i:s', $ts) : null;
        rex_sql::factory()->setQuery(
            'REPLACE INTO ' . self::table() . ' (domain_id, url_id, reason, inspected_at, verdict, coverage, indexing_state, robots_state, fetch_state,
                google_canonical, user_canonical, last_crawl, error) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $domainId, $urlId, $reason,
                mb_substr((string) ($r['verdict'] ?? ''), 0, 20),
                mb_substr((string) ($r['coverageState'] ?? ''), 0, 191),
                mb_substr((string) ($r['indexingState'] ?? ''), 0, 40),
                mb_substr((string) ($r['robotsTxtState'] ?? ''), 0, 40),
                mb_substr((string) ($r['pageFetchState'] ?? ''), 0, 40),
                isset($r['googleCanonical']) ? (string) $r['googleCanonical'] : null,
                isset($r['userCanonical']) ? (string) $r['userCanonical'] : null,
                $lastCrawl,
                $error,
            ],
        );
    }

    /** Tageswert: Stichprobe im Prüfabstand und Anteil indexierter URLs darin */
    public static function snapshot(int $domainId): void
    {
        $days = max(1, (int) rex_addon::get('vsn_seo')->getConfig('inspect_interval_days', 14));
        $sql = rex_sql::factory();
        $row = $sql->getArray(
            'SELECT COUNT(*) AS sample, SUM(verdict = \'PASS\') AS indexed, SUM(inspected_at >= ?) AS today
             FROM ' . self::table() . ' WHERE domain_id = ? AND inspected_at >= ? AND error IS NULL',
            [date('Y-m-d') . ' 00:00:00', $domainId, date('Y-m-d H:i:s', time() - $days * 86400)],
        )[0];
        $sql->setQuery(
            'REPLACE INTO ' . rex::getTable('vsn_seo_inspect_day') . ' (domain_id, date, inspected, sample, indexed) VALUES (?, ?, ?, ?, ?)',
            [$domainId, date('Y-m-d'), (int) $row['today'], (int) $row['sample'], (int) $row['indexed']],
        );
    }
}
