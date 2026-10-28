<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Crawler;

use rex;
use rex_i18n;
use rex_sql;

/**
 * Problemliste mit Verlauf. Ein Problem ist offen, bis eine Prüfung es nicht mehr findet;
 * taucht es wieder auf, beginnt ein neuer Zeitraum.
 */
final class Issues
{
    // Domain (url_id 0) bzw. Sitemap-URL
    public const ROBOTS_UNREACHABLE = 'robots_unreachable';
    public const ROBOTS_NO_SITEMAP = 'robots_no_sitemap';
    public const SITEMAP_UNREACHABLE = 'sitemap_unreachable';
    public const SITEMAP_GSC_ERRORS = 'sitemap_gsc_errors';
    // je URL
    public const HTTP_ERROR = 'http_error';
    public const FETCH_ERROR = 'fetch_error';
    public const REDIRECT_CHAIN = 'redirect_chain';
    public const SITEMAP_REDIRECT = 'sitemap_redirect';
    public const NOINDEX = 'noindex';
    public const ROBOTS_BLOCKED = 'robots_blocked';
    public const CANONICAL_OTHER = 'canonical_other';
    public const TITLE_MISSING = 'title_missing';
    public const TITLE_LENGTH = 'title_length';
    public const DESCRIPTION_MISSING = 'description_missing';
    public const DESCRIPTION_LENGTH = 'description_length';
    public const TITLE_DUPLICATE = 'title_duplicate';
    public const DESCRIPTION_DUPLICATE = 'description_duplicate';

    /** Typen, die eine URL-Prüfung selbst feststellt (und damit auch auflöst) */
    public const URL_TYPES = [
        self::HTTP_ERROR, self::FETCH_ERROR, self::REDIRECT_CHAIN, self::SITEMAP_REDIRECT, self::NOINDEX, self::ROBOTS_BLOCKED,
        self::CANONICAL_OTHER, self::TITLE_MISSING, self::TITLE_LENGTH, self::DESCRIPTION_MISSING, self::DESCRIPTION_LENGTH,
    ];

    /** Schwere: 3 kritisch, 2 Warnung, 1 Hinweis */
    public const SEVERITY = [
        self::ROBOTS_UNREACHABLE => 3,
        self::SITEMAP_UNREACHABLE => 3,
        self::HTTP_ERROR => 3,
        self::FETCH_ERROR => 2,
        self::ROBOTS_BLOCKED => 2,
        self::NOINDEX => 2,
        self::SITEMAP_GSC_ERRORS => 2,
        self::SITEMAP_REDIRECT => 2,
        self::REDIRECT_CHAIN => 2,
        self::CANONICAL_OTHER => 2,
        self::ROBOTS_NO_SITEMAP => 1,
        self::TITLE_MISSING => 2,
        self::TITLE_LENGTH => 1,
        self::DESCRIPTION_MISSING => 1,
        self::DESCRIPTION_LENGTH => 1,
        self::TITLE_DUPLICATE => 1,
        self::DESCRIPTION_DUPLICATE => 1,
    ];

    public static function table(): string
    {
        return rex::getTable('vsn_seo_issue');
    }

    public static function label(string $type): string
    {
        $key = 'vsn_seo_issue_' . $type;
        return rex_i18n::hasMsg($key) ? rex_i18n::msg($key) : $type;
    }

    public static function severity(string $type): int
    {
        return self::SEVERITY[$type] ?? 1;
    }

    /**
     * Stand einer URL abgleichen: gefundene Probleme öffnen bzw. bestätigen, andere aus $scope auflösen.
     *
     * @param array<string, string> $found Typ => Detail
     * @param list<string> $scope Typen, über die diese Prüfung entscheidet
     */
    public static function sync(int $domainId, int $urlId, array $found, array $scope): void
    {
        $sql = rex_sql::factory();
        $now = date('Y-m-d H:i:s');
        foreach ($found as $type => $detail) {
            $sql->setQuery(
                'INSERT INTO ' . self::table() . ' (domain_id, url_id, type, detail, first_seen, last_seen, resolved_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE detail = VALUES(detail), last_seen = VALUES(last_seen),
                    first_seen = IF(resolved_at IS NULL, first_seen, VALUES(first_seen)), resolved_at = NULL',
                [$domainId, $urlId, $type, mb_substr($detail, 0, 500), $now, $now],
            );
        }
        $resolve = array_values(array_diff($scope, array_keys($found)));
        if ($resolve) {
            $sql->setQuery(
                'UPDATE ' . self::table() . ' SET resolved_at = ? WHERE domain_id = ? AND url_id = ? AND resolved_at IS NULL
                 AND type IN (' . $sql->in($resolve) . ')',
                [$now, $domainId, $urlId],
            );
        }
    }

    /**
     * Für einen Typ, der über alle URLs der Domain hinweg ermittelt wird (Duplikate):
     * gefundene öffnen, alle übrigen offenen dieses Typs auflösen.
     *
     * @param array<int, string> $found url_id => Detail
     */
    public static function syncType(int $domainId, string $type, array $found): void
    {
        foreach ($found as $urlId => $detail) {
            self::sync($domainId, $urlId, [$type => $detail], []);
        }
        $sql = rex_sql::factory();
        $where = 'domain_id = ? AND type = ? AND resolved_at IS NULL';
        if ($found) {
            $where .= ' AND url_id NOT IN (' . implode(',', array_map('intval', array_keys($found))) . ')';
        }
        $sql->setQuery('UPDATE ' . self::table() . ' SET resolved_at = ? WHERE ' . $where, [date('Y-m-d H:i:s'), $domainId, $type]);
    }

    /**
     * Offene Probleme von URLs auflösen, die nicht mehr überwacht werden
     *
     * @param list<int> $urlIds
     */
    public static function resolveUrls(int $domainId, array $urlIds): void
    {
        if (!$urlIds) {
            return;
        }
        rex_sql::factory()->setQuery(
            'UPDATE ' . self::table() . ' SET resolved_at = ? WHERE domain_id = ? AND resolved_at IS NULL
             AND url_id IN (' . implode(',', array_map('intval', $urlIds)) . ')',
            [date('Y-m-d H:i:s'), $domainId],
        );
    }

    /**
     * Offene Probleme je Typ.
     *
     * @return array<string, int>
     */
    public static function openCounts(int $domainId): array
    {
        $counts = [];
        foreach (rex_sql::factory()->getArray(
            'SELECT type, COUNT(*) AS c FROM ' . self::table() . ' WHERE domain_id = ? AND resolved_at IS NULL GROUP BY type',
            [$domainId],
        ) as $row) {
            $counts[(string) $row['type']] = (int) $row['c'];
        }
        uksort($counts, static fn ($a, $b) => [self::severity($b), $a] <=> [self::severity($a), $b]);
        return $counts;
    }
}
