<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

use rex;
use rex_sql;
use rex_user;

final class DomainRepository
{
    /** @var array<int, Domain>|null */
    private static ?array $cache = null;

    public static function table(): string
    {
        return rex::getTable('vsn_seo_domain');
    }

    /** @return array<int, Domain> */
    public static function all(bool $onlyActive = false): array
    {
        if (null === self::$cache) {
            self::$cache = [];
            foreach (rex_sql::factory()->getArray('SELECT * FROM ' . self::table() . ' ORDER BY name') as $row) {
                $domain = Domain::fromRow($row);
                self::$cache[$domain->id] = $domain;
            }
        }
        return $onlyActive ? array_filter(self::$cache, static fn (Domain $d) => $d->active) : self::$cache;
    }

    /** @return array<int, Domain> */
    public static function visibleFor(?rex_user $user): array
    {
        if (!$user) {
            return [];
        }
        return array_filter(self::all(), static fn (Domain $d) => $d->visibleFor($user));
    }

    public static function get(int $id): ?Domain
    {
        return self::all()[$id] ?? null;
    }

    public static function findByHost(string $host): ?Domain
    {
        foreach (self::all() as $domain) {
            if (0 === strcasecmp($domain->host, $host)) {
                return $domain;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $values Spaltennamen der Tabelle */
    public static function save(?int $id, array $values): int
    {
        $sql = rex_sql::factory()->setTable(self::table())->setValues($values);
        $user = rex::getUser()?->getLogin() ?? 'console';
        $sql->addGlobalUpdateFields($user);
        if ($id) {
            $sql->setWhere(['id' => $id])->update();
        } else {
            $sql->addGlobalCreateFields($user)->insert();
            $id = (int) $sql->getLastId();
        }
        self::$cache = null;
        return $id;
    }

    /**
     * Nur einzelne Felder ändern, ohne updatedate (Importstand etc.)
     *
     * @param array<string, mixed> $values
     */
    public static function patch(int $id, array $values): void
    {
        rex_sql::factory()->setTable(self::table())->setValues($values)->setWhere(['id' => $id])->update();
        self::$cache = null;
    }

    public static function delete(int $id): void
    {
        $sql = rex_sql::factory();
        foreach (['vsn_seo_sa', 'vsn_seo_sa_day', 'vsn_seo_sa_week', 'vsn_seo_crawl', 'vsn_seo_issue', 'vsn_seo_sitemap', 'vsn_seo_alert',
            'vsn_seo_mt_day', 'vsn_seo_mt_engine', 'vsn_seo_mt_entry', 'vsn_seo_mt_404', 'vsn_seo_inspect', 'vsn_seo_inspect_day'] as $table) {
            $sql->setQuery('DELETE FROM ' . rex::getTable($table) . ' WHERE domain_id = ?', [$id]);
        }
        $sql->setQuery('DELETE r FROM ' . rex::getTable('vsn_seo_rank') . ' r JOIN ' . rex::getTable('vsn_seo_rank_keyword') . ' k ON k.id = r.keyword_id WHERE k.domain_id = ?', [$id]);
        $sql->setQuery('DELETE FROM ' . rex::getTable('vsn_seo_rank_keyword') . ' WHERE domain_id = ?', [$id]);
        $sql->setQuery('DELETE FROM ' . self::table() . ' WHERE id = ?', [$id]);
        self::$cache = null;
    }

    /** Alle Daten der Domain verwerfen und den Import neu starten */
    public static function resetData(int $id): void
    {
        $sql = rex_sql::factory();
        foreach (['vsn_seo_sa', 'vsn_seo_sa_day', 'vsn_seo_sa_week'] as $table) {
            $sql->setQuery('DELETE FROM ' . rex::getTable($table) . ' WHERE domain_id = ?', [$id]);
        }
        self::patch($id, ['backfill_cursor' => null, 'forward_until' => null, 'kpi' => null, 'last_error' => null]);
    }
}
