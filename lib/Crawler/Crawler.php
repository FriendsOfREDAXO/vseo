<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Crawler;

use rex;
use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_sql;
use Throwable;
use Viewsion\SeoMonitor\Model\AccountRepository;
use Viewsion\SeoMonitor\Model\Dictionary;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Source\Gsc\GscClient;
use Viewsion\SeoMonitor\Util\Budget;
use Viewsion\SeoMonitor\Util\Lock;
use Viewsion\SeoMonitor\Util\Log;

/**
 * Eigener Crawler, in Etappen:
 * 1. einmal täglich robots.txt, Sitemaps (eigener Abruf und Search Console) und die URL-Liste,
 * 2. je Lauf eine begrenzte Zahl URLs prüfen, nie geprüfte und älteste zuerst,
 * 3. Duplikate (Title/Description) über die ganze Domain.
 */
final class Crawler
{
    public const LOCK = 'crawl';
    private const MAX_HOPS = 5;
    private const MAX_CHILD_SITEMAPS = 200;

    private Fetcher $fetcher;
    private ?RobotsTxt $robots = null;

    /** @var list<string> */
    private array $messages = [];

    private int $errors = 0;

    public function __construct()
    {
        $this->fetcher = new Fetcher();
    }

    public static function table(): string
    {
        return rex::getTable('vsn_seo_crawl');
    }

    /**
     * @param int|null $limit URLs je Domain, null = Einstellung
     * @param bool $refresh robots.txt und Sitemaps sofort neu laden
     */
    public function run(Budget $budget, ?int $domainId = null, ?int $limit = null, bool $refresh = false): bool
    {
        if (!Lock::acquire(self::LOCK)) {
            $this->messages[] = 'Another crawl is running.';
            return false;
        }
        try {
            $domains = null !== $domainId ? array_filter([DomainRepository::get($domainId)]) : DomainRepository::all(true);
            usort($domains, static fn (Domain $a, Domain $b) => strcmp((string) ($a->crawl['run_at'] ?? ''), (string) ($b->crawl['run_at'] ?? '')));
            $limit ??= max(1, (int) rex_addon::get('vsn_seo')->getConfig('crawl_per_run', 200));
            foreach ($domains as $domain) {
                if ($budget->exceeded()) {
                    $this->messages[] = 'Budget exceeded, continuing next run.';
                    break;
                }
                try {
                    $this->crawlDomain($domain, $budget, $limit, $refresh);
                } catch (Throwable $e) {
                    ++$this->errors;
                    $this->messages[] = $domain->name . ': ' . $e->getMessage();
                    Log::error('Crawler ' . $domain->name . ': ' . $e->getMessage());
                }
            }
        } finally {
            Lock::release(self::LOCK);
        }
        return 0 === $this->errors;
    }

    /**
     * Ausgewählte URLs sofort prüfen (z. B. verlorene URLs aus dem Backend).
     *
     * @param list<int> $urlIds
     * @return int|null geprüfte URLs, null = der Crawler läuft gerade
     */
    public function checkNow(Domain $domain, array $urlIds, Budget $budget): ?int
    {
        if (!$urlIds) {
            return 0;
        }
        if (!Lock::acquire(self::LOCK)) {
            return null;
        }
        try {
            $this->loadRobots($domain);
            $sql = rex_sql::factory();
            $values = array_map(static fn (int $id) => '(' . $domain->id . ', ' . $id . ', NOW())', $urlIds);
            // unbekannte URLs aufnehmen, damit sie Teil der Überwachung sind
            $sql->setQuery('INSERT IGNORE INTO ' . self::table() . ' (domain_id, url_id, first_seen) VALUES ' . implode(',', $values));
            $count = 0;
            foreach ($this->rows($domain, 'c.url_id IN (' . implode(',', array_map('intval', $urlIds)) . ')', count($urlIds)) as $row) {
                if ($budget->exceeded()) {
                    break;
                }
                $this->checkUrl($domain, $row);
                ++$count;
            }
            $this->duplicates($domain);
            return $count;
        } finally {
            Lock::release(self::LOCK);
        }
    }

    private function crawlDomain(Domain $domain, Budget $budget, int $limit, bool $refresh): void
    {
        $state = $domain->crawl;
        if ($refresh || ($state['site_at'] ?? '') < date('Y-m-d H:i:s', time() - 20 * 3600)) {
            $state = $this->checkSite($domain, $state);
            $state['site_at'] = date('Y-m-d H:i:s');
            $this->saveState($domain, $state);
        } else {
            $this->loadRobots($domain);
        }

        $days = max(1, (int) rex_addon::get('vsn_seo')->getConfig('crawl_interval_days', 7));
        $due = date('Y-m-d H:i:s', time() - $days * 86400 + 3600);
        $checked = 0;
        foreach ($this->rows($domain, '(c.checked_at IS NULL OR c.checked_at < ' . rex_sql::factory()->escape($due) . ')', $limit) as $row) {
            if ($budget->exceeded()) {
                break;
            }
            $this->checkUrl($domain, $row);
            ++$checked;
        }
        if ($checked) {
            $this->duplicates($domain);
        }
        $state['run_at'] = date('Y-m-d H:i:s');
        $this->saveState($domain, $state);
        $this->messages[] = sprintf('%s: %d URL(s) checked', $domain->name, $checked);
    }

    /**
     * robots.txt, Sitemaps und URL-Liste der Domain.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function checkSite(Domain $domain, array $state): array
    {
        $base = $domain->baseUrl();

        // robots.txt
        // Google folgt Weiterleitungen der robots.txt (z. B. auf www/https)
        [$res] = $this->fetcher->follow($base . 'robots.txt');
        $robotsOk = 200 === $res->status;
        $this->robots = RobotsTxt::parse($robotsOk ? $res->body : '');
        $state['robots'] = [
            'url' => $base . 'robots.txt',
            'status' => $res->status,
            'error' => $res->error,
            'sitemaps' => $this->robots->sitemaps,
            'body' => $robotsOk ? mb_substr($res->body, 0, 30000) : '',
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        $found = [];
        if (!$robotsOk) {
            $found[Issues::ROBOTS_UNREACHABLE] = $res->status ? 'HTTP ' . $res->status : (string) $res->error;
        } elseif (!$this->robots->sitemaps) {
            $found[Issues::ROBOTS_NO_SITEMAP] = '';
        }
        Issues::sync($domain->id, 0, $found, [Issues::ROBOTS_UNREACHABLE, Issues::ROBOTS_NO_SITEMAP]);

        // Sitemaps: robots.txt, eigene Angaben, Search Console; ohne Angaben /sitemap.xml
        $sources = [];
        foreach ($this->robots->sitemaps as $url) {
            $sources[$url] = 'robots';
        }
        foreach (preg_split('/\R/', $domain->sitemaps) ?: [] as $line) {
            if ('' !== ($line = trim($line))) {
                $sources[Fetcher::resolve($base, $line)] ??= 'manual';
            }
        }
        foreach ($this->gscSitemaps($domain) as $url) {
            $sources[$url] ??= 'gsc';
        }
        if (!$sources) {
            $sources[$base . 'sitemap.xml'] = 'default';
        }

        $maxUrls = max(100, (int) rex_addon::get('vsn_seo')->getConfig('crawl_max_sitemap_urls', 50000));
        $locs = [];
        $complete = true;
        $seen = [];
        foreach ($sources as $url => $source) {
            $complete = $this->readSitemap($domain, $url, $source, null, $locs, $maxUrls, $seen) && $complete;
        }
        $this->removeOldSitemaps($domain, $seen);

        // URL-Liste: Sitemap, Search Console, andere AddOns
        $this->setFlag($domain, 'in_sitemap', array_keys($locs), $complete);
        $this->setGscFlag($domain);
        $this->setFlag($domain, 'from_ext', self::extensionUrls($domain), true);
        $this->prune($domain);

        $state['sitemap_urls'] = count($locs);
        $state['sitemaps_complete'] = $complete;
        return $state;
    }

    /**
     * Sitemaps aus der Search Console speichern; liefert ihre URLs für den eigenen Abruf.
     *
     * @return list<string>
     */
    private function gscSitemaps(Domain $domain): array
    {
        if (!$domain->hasGsc() || !($account = AccountRepository::get((int) $domain->gscAccountId))) {
            return [];
        }
        try {
            $sitemaps = (new GscClient($account))->sitemaps($domain->gscProperty);
        } catch (Throwable $e) {
            $this->messages[] = $domain->name . ': ' . $e->getMessage();
            return [];
        }
        $sql = rex_sql::factory();
        $urls = [];
        foreach ($sitemaps as $s) {
            $urls[] = $s['path'];
            $sql->setQuery(
                'INSERT INTO ' . rex::getTable('vsn_seo_sitemap') . ' (domain_id, hash, url, source, gsc_checked_at, gsc_last_submitted, gsc_last_downloaded,
                    gsc_pending, gsc_errors, gsc_warnings, gsc_submitted, gsc_indexed)
                 VALUES (?, UNHEX(MD5(?)), ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gsc_checked_at = NOW(), gsc_last_submitted = VALUES(gsc_last_submitted),
                    gsc_last_downloaded = VALUES(gsc_last_downloaded), gsc_pending = VALUES(gsc_pending), gsc_errors = VALUES(gsc_errors),
                    gsc_warnings = VALUES(gsc_warnings), gsc_submitted = VALUES(gsc_submitted), gsc_indexed = VALUES(gsc_indexed)',
                [
                    $domain->id, $s['path'], $s['path'], 'gsc',
                    self::datetime($s['lastSubmitted']), self::datetime($s['lastDownloaded']),
                    (int) $s['isPending'], $s['errors'], $s['warnings'], $s['submitted'], $s['indexed'],
                ],
            );
            $urlId = Dictionary::ids(Dictionary::URL, [Dictionary::normalize(Dictionary::URL, $s['path'])]);
            Issues::sync(
                $domain->id,
                (int) reset($urlId),
                $s['errors'] > 0 ? [Issues::SITEMAP_GSC_ERRORS => $s['errors'] . ' / ' . $s['warnings']] : [],
                [Issues::SITEMAP_GSC_ERRORS],
            );
        }
        return $urls;
    }

    /**
     * Eine Sitemap abrufen und speichern; bei einem Index die Unter-Sitemaps.
     *
     * @param array<string, true> $locs gesammelte URLs
     * @param array<string, true> $seen gesehene Sitemap-URLs
     * @return bool vollständig gelesen
     */
    private function readSitemap(Domain $domain, string $url, string $source, ?int $parentId, array &$locs, int $maxUrls, array &$seen): bool
    {
        if (isset($seen[$url])) {
            return true;
        }
        $seen[$url] = true;
        [$res, $chain] = $this->fetcher->follow($url);
        $parsed = 200 === $res->status ? SitemapReader::read($res->body, $maxUrls) : ['index' => false, 'locs' => [], 'error' => null];
        $error = 200 !== $res->status ? ($res->status ? 'HTTP ' . $res->status : (string) $res->error) : $parsed['error'];
        if ($error && $chain) {
            $error = implode(' · ', $chain) . ' · ' . $error;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'INSERT INTO ' . rex::getTable('vsn_seo_sitemap') . ' (domain_id, hash, url, source, parent_id, checked_at, status, error, is_index, url_count)
             VALUES (?, UNHEX(MD5(?)), ?, ?, ?, NOW(), ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), checked_at = NOW(), status = VALUES(status), error = VALUES(error),
                is_index = VALUES(is_index), url_count = VALUES(url_count),
                source = IF(source = \'gsc\' AND VALUES(source) <> \'child\', VALUES(source), source)',
            [$domain->id, $url, $url, $source, $parentId, $res->status, $error, (int) $parsed['index'], count($parsed['locs'])],
        );
        $id = (int) $sql->getArray('SELECT id FROM ' . rex::getTable('vsn_seo_sitemap') . ' WHERE domain_id = ? AND hash = UNHEX(MD5(?))', [$domain->id, $url])[0]['id'];

        $urlId = Dictionary::ids(Dictionary::URL, [Dictionary::normalize(Dictionary::URL, $url)]);
        Issues::sync($domain->id, (int) reset($urlId), $error ? [Issues::SITEMAP_UNREACHABLE => $error] : [], [Issues::SITEMAP_UNREACHABLE]);
        if ($error) {
            return false;
        }

        $complete = true;
        if ($parsed['index']) {
            foreach (array_slice($parsed['locs'], 0, self::MAX_CHILD_SITEMAPS) as $child) {
                if (count($locs) >= $maxUrls) {
                    return false;
                }
                $complete = $this->readSitemap($domain, $child, 'child', $id, $locs, $maxUrls, $seen) && $complete;
            }
            return $complete && count($parsed['locs']) <= self::MAX_CHILD_SITEMAPS;
        }
        foreach ($parsed['locs'] as $loc) {
            if ($domain->ownsUrl($loc)) {
                $locs[$loc] = true;
            }
        }
        return count($locs) < $maxUrls;
    }

    /** @param array<string, true> $seen */
    private function removeOldSitemaps(Domain $domain, array $seen): void
    {
        $sql = rex_sql::factory();
        $old = [];
        foreach ($sql->getArray('SELECT id, url FROM ' . rex::getTable('vsn_seo_sitemap') . ' WHERE domain_id = ?', [$domain->id]) as $row) {
            if (!isset($seen[(string) $row['url']])) {
                $old[(int) $row['id']] = (string) $row['url'];
            }
        }
        if (!$old) {
            return;
        }
        $sql->setQuery('DELETE FROM ' . rex::getTable('vsn_seo_sitemap') . ' WHERE id IN (' . implode(',', array_keys($old)) . ')');
        $ids = Dictionary::ids(Dictionary::URL, array_map(static fn ($u) => Dictionary::normalize(Dictionary::URL, $u), array_values($old)));
        Issues::resolveUrls($domain->id, array_values($ids));
    }

    /**
     * Herkunfts-Flag setzen. $replace: die Liste ist vollständig, andere URLs verlieren das Flag.
     *
     * @param list<string> $urls
     */
    private function setFlag(Domain $domain, string $flag, array $urls, bool $replace): void
    {
        $sql = rex_sql::factory();
        if ($replace) {
            $sql->setQuery('UPDATE ' . self::table() . ' SET ' . $flag . ' = 0 WHERE domain_id = ?', [$domain->id]);
        }
        $urls = array_map(static fn ($u) => Dictionary::normalize(Dictionary::URL, $u), $urls);
        foreach (array_chunk($urls, 2000) as $chunk) {
            $values = array_map(static fn (int $id) => '(' . $domain->id . ', ' . $id . ', 1, NOW())', array_values(Dictionary::ids(Dictionary::URL, $chunk)));
            $sql->setQuery(
                'INSERT INTO ' . self::table() . ' (domain_id, url_id, ' . $flag . ', first_seen) VALUES ' . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE ' . $flag . ' = 1',
            );
        }
    }

    /**
     * URLs, die andere AddOns liefern (z. B. Shop-Seiten außerhalb der REDAXO-Struktur).
     * Nur URLs der Domain werden übernommen.
     *
     * @return list<string>
     */
    private static function extensionUrls(Domain $domain): array
    {
        $urls = [];
        foreach (rex_extension::registerPoint(new rex_extension_point('VSN_SEO_URL_SOURCE', self::emptyList(), ['domain' => $domain])) as $url) {
            if ($domain->ownsUrl($url)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** @return list<string> */
    private static function emptyList(): array
    {
        return [];
    }

    /** URLs mit Impressionen in den letzten Tagen (Einstellung) */
    private function setGscFlag(Domain $domain): void
    {
        $days = max(7, (int) rex_addon::get('vsn_seo')->getConfig('crawl_gsc_days', 90));
        $sql = rex_sql::factory();
        $sql->setQuery('UPDATE ' . self::table() . ' SET from_gsc = 0 WHERE domain_id = ?', [$domain->id]);
        $sql->setQuery(
            'INSERT INTO ' . self::table() . ' (domain_id, url_id, from_gsc, first_seen)
             SELECT domain_id, url_id, 1, NOW() FROM ' . rex::getTable('vsn_seo_sa') . ' WHERE domain_id = ? AND date >= ? GROUP BY domain_id, url_id
             ON DUPLICATE KEY UPDATE from_gsc = 1',
            [$domain->id, date('Y-m-d', time() - $days * 86400)],
        );
    }

    /** URLs ohne Herkunft entfallen, ihre Probleme gelten als erledigt */
    private function prune(Domain $domain): void
    {
        $sql = rex_sql::factory();
        $ids = array_map(
            static fn ($r) => (int) $r['url_id'],
            $sql->getArray('SELECT url_id FROM ' . self::table() . ' WHERE domain_id = ? AND in_sitemap = 0 AND from_gsc = 0 AND from_ext = 0', [$domain->id]),
        );
        foreach (array_chunk($ids, 1000) as $chunk) {
            Issues::resolveUrls($domain->id, $chunk);
            $sql->setQuery('DELETE FROM ' . self::table() . ' WHERE domain_id = ? AND url_id IN (' . implode(',', $chunk) . ')', [$domain->id]);
        }
    }

    /**
     * @return list<array{url_id: int, url: string, in_sitemap: bool, from_gsc: bool}>
     */
    private function rows(Domain $domain, string $where, int $limit): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT c.url_id, u.url, c.in_sitemap, c.from_gsc FROM ' . self::table() . ' c
             JOIN ' . rex::getTable('vsn_seo_url') . ' u ON u.id = c.url_id
             WHERE c.domain_id = ? AND ' . $where . '
             ORDER BY c.checked_at IS NOT NULL, c.checked_at, c.in_sitemap DESC LIMIT ' . $limit,
            [$domain->id],
        );
        return array_map(static fn ($r) => [
            'url_id' => (int) $r['url_id'],
            'url' => (string) $r['url'],
            'in_sitemap' => (bool) $r['in_sitemap'],
            'from_gsc' => (bool) $r['from_gsc'],
        ], $rows);
    }

    /** @param array{url_id: int, url: string, in_sitemap: bool, from_gsc: bool} $row */
    private function checkUrl(Domain $domain, array $row): void
    {
        $url = $row['url'];
        $res = $this->fetcher->get($url);
        $values = [
            'checked_at' => date('Y-m-d H:i:s'),
            'status' => $res->status,
            'error' => $res->error,
            'location' => $res->location,
            'hops' => 0,
            'final_status' => null,
            'content_type' => mb_substr($res->contentType, 0, 100),
            'canonical' => null,
            'robots' => null,
            'noindex' => 0,
            'blocked' => $this->robots?->isBlocked($url) ? 1 : 0,
            'indexable' => 0,
            'title' => null,
            'description' => null,
            'hreflang' => 0,
            'response_ms' => $res->ms,
        ];
        $found = [];
        $where = $row['in_sitemap'] ? ' · Sitemap' : '';

        if (0 === $res->status) {
            $found[Issues::FETCH_ERROR] = (string) $res->error;
        } elseif ($res->isRedirect()) {
            // Weiterleitung bis zum Ziel verfolgen
            $chain = [$res->status . ' → ' . $res->location];
            $final = $res;
            $hops = 0;
            while ($final->isRedirect() && $hops < self::MAX_HOPS) {
                ++$hops;
                $final = $this->fetcher->get((string) $final->location, false);
                $chain[] = $final->isRedirect() ? $final->status . ' → ' . $final->location : (string) $final->status;
            }
            $values['hops'] = $hops;
            $values['final_status'] = $final->status;
            $detail = implode(' · ', $chain);
            if ($hops >= 2) {
                $found[Issues::REDIRECT_CHAIN] = $detail;
            }
            if ($row['in_sitemap']) {
                $found[Issues::SITEMAP_REDIRECT] = $detail;
            }
            if (0 === $final->status || $final->status >= 400 || $final->isRedirect()) {
                $found[Issues::HTTP_ERROR] = $detail . $where;
            }
        } elseif ($res->status >= 400) {
            $found[Issues::HTTP_ERROR] = 'HTTP ' . $res->status . $where;
        }

        $robots = trim($res->robotsHeader);
        if (200 === $res->status && $res->isHtml()) {
            $page = PageParser::parse($res->body, $url);
            $robots = trim($robots . ($robots && $page['robots'] ? ', ' : '') . $page['robots']);
            $values['title'] = null !== $page['title'] ? mb_substr($page['title'], 0, 500) : null;
            $values['description'] = $page['description'];
            $values['canonical'] = $page['canonical'];
            $values['hreflang'] = $page['hreflang'];
            $noindex = PageParser::isNoindex($robots);
            $selfCanonical = null === $page['canonical'] || '' === $page['canonical'] || PageParser::sameUrl($page['canonical'], $url);
            if ($noindex) {
                $found[Issues::NOINDEX] = $robots . $where;
            } elseif (!$selfCanonical && $row['in_sitemap']) {
                $found[Issues::CANONICAL_OTHER] = (string) $page['canonical'];
            }
            if (!$noindex && $selfCanonical && !$values['blocked']) {
                $values['indexable'] = 1;
                $title = (string) $page['title'];
                $description = (string) $page['description'];
                if ('' === $title) {
                    $found[Issues::TITLE_MISSING] = '';
                } elseif (mb_strlen($title) < 10 || mb_strlen($title) > 70) {
                    $found[Issues::TITLE_LENGTH] = mb_strlen($title) . ': ' . $title;
                }
                if ('' === $description) {
                    $found[Issues::DESCRIPTION_MISSING] = '';
                } elseif (mb_strlen($description) < 50 || mb_strlen($description) > 160) {
                    $found[Issues::DESCRIPTION_LENGTH] = (string) mb_strlen($description);
                }
            }
            $values['noindex'] = $noindex ? 1 : 0;
        } elseif ('' !== $robots && PageParser::isNoindex($robots)) {
            $values['noindex'] = 1;
            $found[Issues::NOINDEX] = $robots . $where;
        }
        $values['robots'] = '' !== $robots ? mb_substr($robots, 0, 191) : null;
        if ($values['blocked'] && ($row['in_sitemap'] || $row['from_gsc'])) {
            $found[Issues::ROBOTS_BLOCKED] = $where ? 'Sitemap' : '';
        }

        rex_sql::factory()->setTable(self::table())->setValues($values)
            ->setWhere(['domain_id' => $domain->id, 'url_id' => $row['url_id']])->update();
        Issues::sync($domain->id, $row['url_id'], $found, Issues::URL_TYPES);
    }

    /** Gleiche Titles/Descriptions auf mehreren indexierbaren URLs */
    private function duplicates(Domain $domain): void
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT url_id, title, description FROM ' . self::table() . ' WHERE domain_id = ? AND indexable = 1',
            [$domain->id],
        );
        foreach (['title' => Issues::TITLE_DUPLICATE, 'description' => Issues::DESCRIPTION_DUPLICATE] as $field => $type) {
            $groups = [];
            foreach ($rows as $row) {
                $value = mb_strtolower(trim((string) $row[$field]));
                if ('' !== $value) {
                    $groups[$value][] = (int) $row['url_id'];
                }
            }
            $found = [];
            foreach ($groups as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $found[$id] = (string) count($ids);
                    }
                }
            }
            Issues::syncType($domain->id, $type, $found);
        }
    }

    private function loadRobots(Domain $domain): void
    {
        $this->robots = RobotsTxt::parse((string) ($domain->crawl['robots']['body'] ?? ''));
    }

    /** @param array<string, mixed> $state */
    private function saveState(Domain $domain, array $state): void
    {
        $domain->crawl = $state;
        DomainRepository::patch($domain->id, ['crawl' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    private static function datetime(?string $iso): ?string
    {
        return null !== $iso && false !== ($ts = strtotime($iso)) ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}
