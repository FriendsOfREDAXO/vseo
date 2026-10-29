<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source\Matomo;

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
 * Import aus Matomo je Tag: Besuche aus Suchmaschinen, Einstiegsseiten aus der Suche
 * (mit Umsatz, falls Ecommerce aktiv ist) und 404-Seiten nach einstellbarem Muster.
 * Wie bei der Search Console: die letzten Tage neu laden, ältere Tage rückwärts nachholen.
 */
final class MatomoSource implements DataSourceInterface
{
    private const SEARCH = 'referrerType==search';

    /** @var array<int, MatomoClient> */
    private array $clients = [];

    public function key(): string
    {
        return 'matomo';
    }

    public function label(): string
    {
        return rex_i18n::msg('vseo_source_matomo');
    }

    public function supports(Domain $domain): bool
    {
        return $domain->hasMatomo();
    }

    public function import(Domain $domain, Budget $budget): array
    {
        $addon = rex_addon::get('vseo');
        $state = $domain->matomo;
        $today = date('Y-m-d');
        $yesterday = Aggregator::shift($today, -1);
        $messages = [];

        // 1. Vorwärts: die letzten Tage (Matomo archiviert den Vortag oft erst nachts)
        $start = Aggregator::shift($today, -max(1, (int) $addon->getConfig('reload_days', 5)));
        if (isset($state['forward_until']) && Aggregator::shift((string) $state['forward_until'], 1) < $start) {
            $start = Aggregator::shift((string) $state['forward_until'], 1);
        }
        $done = 0;
        for ($day = $start; $day <= $yesterday && !$budget->exceeded(); $day = Aggregator::shift($day, 1)) {
            $this->importDay($domain, $day, $state);
            $state['forward_until'] = $day;
            ++$done;
        }
        $messages[] = sprintf('%s: Matomo %d day(s) %s … %s', $domain->name, $done, $start, $yesterday);

        // 2. Rückwärts bis matomo_backfill_days
        $from = Aggregator::shift($today, -max(7, (int) $addon->getConfig('matomo_backfill_days', 90)));
        $cursor = (string) ($state['backfill_cursor'] ?? Aggregator::shift($start, -1));
        $count = 0;
        while ($cursor >= $from && !$budget->exceeded()) {
            $this->importDay($domain, $cursor, $state);
            $cursor = Aggregator::shift($cursor, -1);
            $state['backfill_cursor'] = $cursor;
            ++$count;
        }
        if ($count) {
            $messages[] = sprintf('%s: Matomo backfill %d day(s) → %s', $domain->name, $count, $cursor);
        }

        $state['imported_at'] = date('Y-m-d H:i:s');
        DomainRepository::patch($domain->id, ['matomo' => json_encode($state)]);
        $domain->matomo = $state;
        return $messages;
    }

    /** @param array<string, mixed> $state */
    private function importDay(Domain $domain, string $date, array &$state): void
    {
        $client = $this->client($domain);
        $site = ['idSite' => (int) $domain->matomoSiteId, 'period' => 'day', 'date' => $date];
        $sql = rex_sql::factory();

        // Besuche gesamt und aus Suchmaschinen (inkl. Ziele/Umsatz, wenn vorhanden)
        $visits = (int) ($client->call('VisitsSummary.get', $site)['nb_visits'] ?? 0);
        $search = ['nb_visits' => 0, 'nb_conversions' => 0, 'revenue' => 0];
        foreach ($client->call('Referrers.getReferrerType', $site) as $row) {
            if (is_array($row) && self::SEARCH === ($row['segment'] ?? '')) {
                $search = $row;
            }
        }

        // Suchmaschinen
        $engines = [];
        foreach ($client->call('Referrers.getSearchEngines', $site + ['filter_limit' => 50]) as $row) {
            if (is_array($row) && isset($row['label'])) {
                $engines[] = [mb_substr((string) $row['label'], 0, 64), (int) ($row['nb_visits'] ?? 0)];
            }
        }

        // Einstiegsseiten aus der Suche; Umsatz je Einstiegsseite über die Seiten-Zuordnung von Goals (Matomo 5), sonst ohne
        $entries = [];
        foreach ($client->call('Actions.getEntryPageUrls', $site + ['segment' => self::SEARCH, 'flat' => 1, 'filter_limit' => 1000]) as $row) {
            $url = is_array($row) ? self::rowUrl($row, $domain) : null;
            if (null !== $url) {
                $entries[$url] = ['entrances' => (int) ($row['entry_nb_visits'] ?? 0), 'bounces' => (int) ($row['entry_bounce_count'] ?? 0), 'conversions' => 0, 'revenue' => 0.0];
            }
        }
        if (!isset($state['ecommerce']) || $state['ecommerce']) {
            try {
                foreach ($client->call('Goals.getPagesEntry', $site + ['segment' => self::SEARCH, 'idGoal' => 'ecommerceOrder', 'flat' => 1, 'filter_limit' => 1000]) as $row) {
                    $url = is_array($row) ? self::rowUrl($row, $domain) : null;
                    if (null !== $url && isset($entries[$url])) {
                        $entries[$url]['conversions'] = (int) ($row['entry_nb_conversions'] ?? 0);
                        $entries[$url]['revenue'] = (float) ($row['entry_revenue'] ?? 0);
                    }
                }
                $state['ecommerce'] = true;
            } catch (RuntimeException) {
                // ältere Matomo-Version oder kein Ecommerce: künftig nicht mehr fragen
                $state['ecommerce'] = false;
            }
        }

        // 404-Seiten
        $notFound = $this->notFound($client, $site, $domain);

        $sql->transactional(function () use ($sql, $domain, $date, $visits, $search, $engines, $entries, $notFound) {
            foreach (['vseo_mt_engine', 'vseo_mt_entry', 'vseo_mt_404'] as $table) {
                $sql->setQuery('DELETE FROM ' . rex::getTable($table) . ' WHERE domain_id = ? AND date = ?', [$domain->id, $date]);
            }
            $sql->setQuery(
                'REPLACE INTO ' . rex::getTable('vseo_mt_day') . ' (domain_id, date, visits, search_visits, search_conversions, search_revenue, hits_404, hits_404_search, imported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $domain->id, $date, $visits, (int) ($search['nb_visits'] ?? 0), (int) ($search['nb_conversions'] ?? 0), round((float) ($search['revenue'] ?? 0), 2),
                    array_sum(array_column($notFound, 'hits')), array_sum(array_column($notFound, 'hits_search')),
                ],
            );
            foreach ($engines as [$engine, $count]) {
                $sql->setQuery('INSERT INTO ' . rex::getTable('vseo_mt_engine') . ' (domain_id, date, engine, visits) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE visits = visits + VALUES(visits)', [$domain->id, $date, $engine, $count]);
            }
            $this->insertUrls($sql, 'vseo_mt_entry', $domain->id, $date, $entries);
            $this->insertUrls($sql, 'vseo_mt_404', $domain->id, $date, $notFound);
        });
    }

    /**
     * 404-Seiten nach Muster: Seitentitel (z. B. „404/URL = …“ oder „404 Not Found“) oder URL (z. B. /404/URL=…).
     *
     * @param array<string, scalar> $site
     * @return array<string, array{hits: int, hits_search: int}>
     */
    private function notFound(MatomoClient $client, array $site, Domain $domain): array
    {
        $addon = rex_addon::get('vseo');
        $pattern = trim((string) $addon->getConfig('matomo_404_pattern', '404'));
        if ('' === $pattern) {
            return [];
        }
        $method = 'url' === $addon->getConfig('matomo_404_source') ? 'Actions.getPageUrls' : 'Actions.getPageTitles';
        $result = [];
        foreach (['hits' => [], 'hits_search' => ['segment' => self::SEARCH]] as $key => $extra) {
            foreach ($client->call($method, $site + $extra + ['flat' => 1, 'filter_pattern' => $pattern, 'filter_limit' => 500]) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = self::notFoundUrl($row, $domain, 'Actions.getPageTitles' === $method);
                $result[$url] ??= ['hits' => 0, 'hits_search' => 0];
                $result[$url][$key] += (int) ($row['nb_hits'] ?? $row['nb_visits'] ?? 0);
            }
        }
        return $result;
    }

    /**
     * Angefragte URL einer 404-Zeile. Das übliche Tracking setzt den Titel auf
     * „404/URL = %2Fpfad/From = …“; sonst die URL der Zeile oder der Titel selbst.
     *
     * @param array<mixed> $row
     */
    private static function notFoundUrl(array $row, Domain $domain, bool $isTitle): string
    {
        $label = (string) ($row['label'] ?? '');
        if (preg_match('~URL\s*=\s*([^/]+?)(?:/From\s*=|$)~', $label, $m)) {
            $path = rawurldecode(trim($m[1]));
            return str_starts_with($path, 'http') ? $path : rtrim($domain->baseUrl(), '/') . '/' . ltrim($path, '/');
        }
        if (isset($row['url']) && '' !== $row['url']) {
            return (string) $row['url'];
        }
        // ein reiner Seitentitel („404 - Seite nicht gefunden“) enthält keine URL: der Titel selbst steht für alle Aufrufe
        return $isTitle ? mb_substr(trim($label), 0, 500) : (self::rowUrl($row, $domain) ?? mb_substr(trim($label), 0, 500));
    }

    /** @param array<mixed> $row */
    private static function rowUrl(array $row, Domain $domain): ?string
    {
        if (isset($row['url']) && '' !== $row['url']) {
            return (string) $row['url'];
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ('' === $label || str_starts_with($label, 'Others') || str_starts_with($label, '-1')) {
            return null;
        }
        return rtrim($domain->baseUrl(), '/') . '/' . ltrim($label, '/');
    }

    /** @param array<string, array<string, int|float>> $rows URL => Werte */
    private function insertUrls(rex_sql $sql, string $table, int $domainId, string $date, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $normalized = [];
        foreach ($rows as $url => $values) {
            $normalized[Dictionary::normalize(Dictionary::URL, $url)] = $values;
        }
        $ids = Dictionary::ids(Dictionary::URL, array_keys($normalized));
        $columns = array_keys(reset($normalized));
        foreach (array_chunk($normalized, 500, true) as $chunk) {
            $values = [];
            foreach ($chunk as $url => $row) {
                $values[] = '(' . $domainId . ", '" . $date . "', " . (int) $ids[$url] . ', ' . implode(', ', array_map(static fn ($v) => is_float($v) ? sprintf('%F', $v) : (int) $v, $row)) . ')';
            }
            $update = implode(', ', array_map(static fn ($c) => $c . ' = ' . $c . ' + VALUES(' . $c . ')', $columns));
            $sql->setQuery('INSERT INTO ' . rex::getTable($table) . ' (domain_id, date, url_id, ' . implode(', ', $columns) . ') VALUES '
                . implode(',', $values) . ' ON DUPLICATE KEY UPDATE ' . $update);
        }
    }

    private function client(Domain $domain): MatomoClient
    {
        $id = (int) $domain->matomoAccountId;
        if (!isset($this->clients[$id])) {
            $account = AccountRepository::get($id);
            if (!$account) {
                throw new RuntimeException('Matomo account #' . $id . ' not found');
            }
            $this->clients[$id] = new MatomoClient($account);
        }
        return $this->clients[$id];
    }
}
