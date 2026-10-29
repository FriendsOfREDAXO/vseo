<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Stats;

use rex;
use rex_addon;
use rex_sql;

/**
 * Löscht Detaildaten nach Ablauf der Aufbewahrungsdauer. Tages- und Wochensummen bleiben.
 */
final class Cleanup
{
    public static function runDaily(): int
    {
        $addon = rex_addon::get('vseo');
        if ((int) $addon->getConfig('cleanup_at', 0) > time() - 86400) {
            return 0;
        }
        $addon->setConfig('cleanup_at', time());
        return self::run();
    }

    public static function run(): int
    {
        $months = max(3, (int) rex_addon::get('vseo')->getConfig('retention_months', 25));
        $before = date('Y-m-d', (int) strtotime('-' . $months . ' months'));
        $sql = rex_sql::factory();
        $deleted = 0;
        // in Portionen, damit die Tabelle nicht lange gesperrt ist
        do {
            $sql->setQuery('DELETE FROM ' . rex::getTable('vseo_sa') . ' WHERE date < ? LIMIT 50000', [$before]);
            $deleted += $sql->getRows();
        } while ($sql->getRows() >= 50000);
        return $deleted;
    }
}
