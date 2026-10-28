<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Install;

use rex;
use rex_sql;
use rex_sql_column as Col;
use rex_sql_index as Idx;
use rex_sql_table;

/**
 * Tabellen des AddOns. ensure() ist idempotent und wird von install.php und update.php aufgerufen.
 */
final class Schema
{
    /** @var list<string> */
    private const TABLES = [
        'vsn_seo_account',
        'vsn_seo_domain',
        'vsn_seo_query',
        'vsn_seo_url',
        'vsn_seo_sa',
        'vsn_seo_sa_day',
        'vsn_seo_sa_week',
        'vsn_seo_core_update',
        'vsn_seo_crawl',
        'vsn_seo_issue',
        'vsn_seo_sitemap',
        'vsn_seo_alert',
        'vsn_seo_mt_day',
        'vsn_seo_mt_engine',
        'vsn_seo_mt_entry',
        'vsn_seo_mt_404',
        'vsn_seo_inspect',
        'vsn_seo_inspect_day',
        'vsn_seo_rank_keyword',
        'vsn_seo_rank',
    ];

    public static function ensure(): void
    {
        // Zugänge (Search Console, Matomo, …); credentials sind verschlüsselt
        rex_sql_table::get(rex::getTable('vsn_seo_account'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('type', 'varchar(20)'))
            ->ensureColumn(new Col('name', 'varchar(191)'))
            ->ensureColumn(new Col('info', 'varchar(255)', false, ''))
            ->ensureColumn(new Col('credentials', 'text'))
            ->ensureGlobalColumns()
            ->ensureIndex(new Idx('type', ['type']))
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_domain'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('name', 'varchar(191)'))
            ->ensureColumn(new Col('host', 'varchar(191)'))
            ->ensureColumn(new Col('yrewrite_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_account_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_property', 'varchar(255)', false, ''))
            ->ensureColumn(new Col('matomo_account_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('matomo_site_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('sitemaps', 'text', true))
            ->ensureColumn(new Col('alert_emails', 'text', true))
            ->ensureColumn(new Col('import_from', 'date', true))
            ->ensureColumn(new Col('forward_until', 'date', true))
            ->ensureColumn(new Col('backfill_cursor', 'date', true))
            ->ensureColumn(new Col('status', 'tinyint(1)', false, '1'))
            ->ensureColumn(new Col('last_import', 'datetime', true))
            ->ensureColumn(new Col('last_error', 'text', true))
            ->ensureColumn(new Col('kpi', 'text', true))
            ->ensureColumn(new Col('crawl', 'text', true))
            ->ensureColumn(new Col('matomo', 'text', true))
            ->ensureGlobalColumns()
            ->ensureIndex(new Idx('host', ['host'], Idx::UNIQUE))
            ->ensure();

        // Wörterbücher: Suchbegriffe und URLs werden per ID referenziert
        rex_sql_table::get(rex::getTable('vsn_seo_query'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('hash', 'binary(16)'))
            ->ensureColumn(new Col('query', 'varchar(500)'))
            ->ensureIndex(new Idx('hash', ['hash'], Idx::UNIQUE))
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_url'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('hash', 'binary(16)'))
            ->ensureColumn(new Col('url', 'text'))
            ->ensureIndex(new Idx('hash', ['hash'], Idx::UNIQUE))
            ->ensure();

        // Search-Analytics-Detaildaten (date × query × page × device × country)
        // position_sum = Position × Impressionen, damit sich Durchschnitte über beliebige Zeiträume bilden lassen
        rex_sql_table::get(rex::getTable('vsn_seo_sa'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('query_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('device', 'tinyint(3) unsigned'))
            ->ensureColumn(new Col('country', 'char(3)'))
            ->ensureColumn(new Col('clicks', 'int(10) unsigned'))
            ->ensureColumn(new Col('impressions', 'int(10) unsigned'))
            ->ensureColumn(new Col('position_sum', 'float'))
            ->setPrimaryKey(['domain_id', 'date', 'query_id', 'url_id', 'device', 'country'])
            ->ensureIndex(new Idx('domain_url', ['domain_id', 'url_id', 'date']))
            ->ensureIndex(new Idx('domain_query', ['domain_id', 'query_id', 'date']))
            ->ensure();

        // Tagessummen: clicks/impressions/position_sum aus der Abfrage ohne query/page (vollständig),
        // detail_* und queries/urls aus den Detaildaten
        rex_sql_table::get(rex::getTable('vsn_seo_sa_day'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('clicks', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('impressions', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('position_sum', 'double', false, '0'))
            ->ensureColumn(new Col('detail_rows', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('detail_clicks', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('detail_impressions', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('queries', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('urls', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('imported_at', 'datetime', true))
            ->setPrimaryKey(['domain_id', 'date'])
            ->ensure();

        // Wochensummen (Montag), queries/urls = verschiedene Suchbegriffe/URLs mit Impressionen in der Woche
        rex_sql_table::get(rex::getTable('vsn_seo_sa_week'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('week', 'date'))
            ->ensureColumn(new Col('days', 'tinyint(3) unsigned', false, '0'))
            ->ensureColumn(new Col('clicks', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('impressions', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('position_sum', 'double', false, '0'))
            ->ensureColumn(new Col('queries', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('urls', 'int(10) unsigned', false, '0'))
            ->setPrimaryKey(['domain_id', 'week'])
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_core_update'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('date_end', 'date', true))
            ->ensureColumn(new Col('name', 'varchar(191)'))
            ->ensureIndex(new Idx('date', ['date']))
            ->ensure();

        // Crawler: letzter geprüfter Stand je URL. Herkunft als Flags, damit verschwundene URLs entfallen
        rex_sql_table::get(rex::getTable('vsn_seo_crawl'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('in_sitemap', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('from_gsc', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('from_ext', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('first_seen', 'datetime'))
            ->ensureColumn(new Col('checked_at', 'datetime', true))
            ->ensureColumn(new Col('status', 'smallint(5) unsigned', true))
            ->ensureColumn(new Col('error', 'varchar(255)', true))
            ->ensureColumn(new Col('location', 'text', true))
            ->ensureColumn(new Col('hops', 'tinyint(3) unsigned', false, '0'))
            ->ensureColumn(new Col('final_status', 'smallint(5) unsigned', true))
            ->ensureColumn(new Col('content_type', 'varchar(100)', true))
            ->ensureColumn(new Col('canonical', 'text', true))
            ->ensureColumn(new Col('robots', 'varchar(191)', true))
            ->ensureColumn(new Col('noindex', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('blocked', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('indexable', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('title', 'varchar(500)', true))
            ->ensureColumn(new Col('description', 'varchar(1000)', true))
            ->ensureColumn(new Col('hreflang', 'smallint(5) unsigned', false, '0'))
            ->ensureColumn(new Col('response_ms', 'int(10) unsigned', true))
            ->setPrimaryKey(['domain_id', 'url_id'])
            ->ensureIndex(new Idx('checked', ['domain_id', 'checked_at']))
            ->ensure();

        // Probleme mit Verlauf: seit wann, zuletzt gesehen, behoben am. url_id 0 = die Domain selbst
        rex_sql_table::get(rex::getTable('vsn_seo_issue'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('type', 'varchar(40)'))
            ->ensureColumn(new Col('detail', 'varchar(500)', false, ''))
            ->ensureColumn(new Col('first_seen', 'datetime'))
            ->ensureColumn(new Col('last_seen', 'datetime'))
            ->ensureColumn(new Col('resolved_at', 'datetime', true))
            ->ensureIndex(new Idx('issue', ['domain_id', 'url_id', 'type'], Idx::UNIQUE))
            ->ensureIndex(new Idx('open', ['domain_id', 'resolved_at', 'type']))
            ->ensure();

        // Sitemaps: eigener Abruf und Angaben der Search Console
        rex_sql_table::get(rex::getTable('vsn_seo_sitemap'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('hash', 'binary(16)'))
            ->ensureColumn(new Col('url', 'text'))
            ->ensureColumn(new Col('source', 'varchar(20)', false, ''))
            ->ensureColumn(new Col('parent_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('checked_at', 'datetime', true))
            ->ensureColumn(new Col('status', 'smallint(5) unsigned', true))
            ->ensureColumn(new Col('error', 'varchar(255)', true))
            ->ensureColumn(new Col('is_index', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Col('url_count', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_checked_at', 'datetime', true))
            ->ensureColumn(new Col('gsc_last_submitted', 'datetime', true))
            ->ensureColumn(new Col('gsc_last_downloaded', 'datetime', true))
            ->ensureColumn(new Col('gsc_pending', 'tinyint(1)', true))
            ->ensureColumn(new Col('gsc_errors', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_warnings', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_submitted', 'int(10) unsigned', true))
            ->ensureColumn(new Col('gsc_indexed', 'int(10) unsigned', true))
            ->ensureIndex(new Idx('sitemap', ['domain_id', 'hash'], Idx::UNIQUE))
            ->ensure();

        // Warnungen; ref verhindert, dass dieselbe Warnung (Regel + Woche/Anlass) mehrfach entsteht
        rex_sql_table::get(rex::getTable('vsn_seo_alert'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('rule', 'varchar(40)'))
            ->ensureColumn(new Col('ref', 'varchar(100)', false, ''))
            ->ensureColumn(new Col('severity', 'tinyint(1) unsigned', false, '2'))
            ->ensureColumn(new Col('message', 'varchar(500)', false, ''))
            ->ensureColumn(new Col('params', 'text', true))
            ->ensureColumn(new Col('value', 'double', true))
            ->ensureColumn(new Col('baseline', 'double', true))
            ->ensureColumn(new Col('created_at', 'datetime'))
            ->ensureColumn(new Col('mailed_at', 'datetime', true))
            ->ensureColumn(new Col('seen_at', 'datetime', true))
            ->ensureColumn(new Col('seen_by', 'varchar(191)', true))
            ->ensureIndex(new Idx('alert', ['domain_id', 'rule', 'ref'], Idx::UNIQUE))
            ->ensureIndex(new Idx('open', ['seen_at', 'severity']))
            ->ensure();

        // Matomo: Tageswerte, Suchmaschinen, Einstiegsseiten aus der Suche, 404-Seiten
        rex_sql_table::get(rex::getTable('vsn_seo_mt_day'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('visits', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('search_visits', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('search_conversions', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('search_revenue', 'decimal(14,2)', false, '0.00'))
            ->ensureColumn(new Col('hits_404', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('hits_404_search', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('imported_at', 'datetime', true))
            ->setPrimaryKey(['domain_id', 'date'])
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_mt_engine'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('engine', 'varchar(64)'))
            ->ensureColumn(new Col('visits', 'int(10) unsigned', false, '0'))
            ->setPrimaryKey(['domain_id', 'date', 'engine'])
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_mt_entry'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('entrances', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('bounces', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('conversions', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('revenue', 'decimal(14,2)', false, '0.00'))
            ->setPrimaryKey(['domain_id', 'date', 'url_id'])
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_mt_404'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('hits', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('hits_search', 'int(10) unsigned', false, '0'))
            ->setPrimaryKey(['domain_id', 'date', 'url_id'])
            ->ensure();

        // URL Inspection: letzter Stand je URL der Stichprobe und Tageswerte für den Verlauf
        rex_sql_table::get(rex::getTable('vsn_seo_inspect'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('reason', 'varchar(20)', false, ''))
            ->ensureColumn(new Col('inspected_at', 'datetime'))
            ->ensureColumn(new Col('verdict', 'varchar(20)', false, ''))
            ->ensureColumn(new Col('coverage', 'varchar(191)', false, ''))
            ->ensureColumn(new Col('indexing_state', 'varchar(40)', false, ''))
            ->ensureColumn(new Col('robots_state', 'varchar(40)', false, ''))
            ->ensureColumn(new Col('fetch_state', 'varchar(40)', false, ''))
            ->ensureColumn(new Col('google_canonical', 'text', true))
            ->ensureColumn(new Col('user_canonical', 'text', true))
            ->ensureColumn(new Col('last_crawl', 'datetime', true))
            ->ensureColumn(new Col('error', 'varchar(255)', true))
            ->setPrimaryKey(['domain_id', 'url_id'])
            ->ensureIndex(new Idx('inspected', ['domain_id', 'inspected_at']))
            ->ensure();

        // Rank-Tracking (nur Datenstruktur, siehe Source\Rank\RankTrackerInterface)
        rex_sql_table::get(rex::getTable('vsn_seo_rank_keyword'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('query_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('country', 'char(2)', false, 'de'))
            ->ensureColumn(new Col('device', 'varchar(10)', false, 'desktop'))
            ->ensureColumn(new Col('competitors', 'text', true))
            ->ensureColumn(new Col('status', 'tinyint(1)', false, '1'))
            ->ensureIndex(new Idx('keyword', ['domain_id', 'query_id', 'country', 'device'], Idx::UNIQUE))
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_rank'))
            ->ensureColumn(new Col('keyword_id', 'int(10) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('host', 'varchar(191)'))
            ->ensureColumn(new Col('position', 'smallint(5) unsigned', true))
            ->ensureColumn(new Col('url_id', 'int(10) unsigned', true))
            ->ensureColumn(new Col('type', 'varchar(30)', false, 'organic'))
            ->setPrimaryKey(['keyword_id', 'date', 'host'])
            ->ensure();

        rex_sql_table::get(rex::getTable('vsn_seo_inspect_day'))
            ->ensureColumn(new Col('domain_id', 'smallint(5) unsigned'))
            ->ensureColumn(new Col('date', 'date'))
            ->ensureColumn(new Col('inspected', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('sample', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new Col('indexed', 'int(10) unsigned', false, '0'))
            ->setPrimaryKey(['domain_id', 'date'])
            ->ensure();
    }

    /**
     * Bekannte Google-Updates (Beginn des Rollouts). Nur beim ersten Installieren, danach im Backend pflegbar.
     */
    public static function seedCoreUpdates(): void
    {
        $table = rex::getTable('vsn_seo_core_update');
        $sql = rex_sql::factory();
        if ((int) $sql->getArray('SELECT COUNT(*) AS c FROM ' . $table)[0]['c'] > 0) {
            return;
        }
        $updates = [
            ['2022-05-25', '2022-06-09', 'May 2022 Core Update'],
            ['2022-08-25', '2022-09-09', 'Helpful Content Update'],
            ['2022-09-12', '2022-09-26', 'September 2022 Core Update'],
            ['2022-10-19', '2022-10-21', 'October 2022 Spam Update'],
            ['2022-12-05', '2023-01-12', 'December 2022 Helpful Content Update'],
            ['2022-12-14', '2023-01-12', 'December 2022 Link Spam Update'],
            ['2023-03-15', '2023-03-28', 'March 2023 Core Update'],
            ['2023-08-22', '2023-09-07', 'August 2023 Core Update'],
            ['2023-09-14', '2023-09-28', 'September 2023 Helpful Content Update'],
            ['2023-10-05', '2023-10-19', 'October 2023 Core Update'],
            ['2023-11-02', '2023-11-28', 'November 2023 Core Update'],
            ['2024-03-05', '2024-04-19', 'March 2024 Core Update'],
            ['2024-06-20', '2024-06-27', 'June 2024 Spam Update'],
            ['2024-08-15', '2024-09-03', 'August 2024 Core Update'],
            ['2024-11-11', '2024-12-05', 'November 2024 Core Update'],
            ['2024-12-12', '2024-12-18', 'December 2024 Core Update'],
            ['2024-12-19', '2024-12-26', 'December 2024 Spam Update'],
            ['2025-03-13', '2025-03-27', 'March 2025 Core Update'],
            ['2025-06-30', '2025-07-17', 'June 2025 Core Update'],
            ['2025-08-26', '2025-09-22', 'August 2025 Spam Update'],
        ];
        foreach ($updates as [$date, $end, $name]) {
            $sql->setTable($table)->setValues(['date' => $date, 'date_end' => $end, 'name' => $name])->insert();
        }
    }

    public static function drop(): void
    {
        foreach (self::TABLES as $table) {
            rex_sql_table::get(rex::getTable($table))->drop();
        }
    }
}
