<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Util;

use rex;
use rex_sql;

/**
 * Sperre gegen parallele Läufe über MySQL GET_LOCK. Endet der Prozess, gibt MySQL die Sperre frei.
 */
final class Lock
{
    public static function acquire(string $name): bool
    {
        $row = rex_sql::factory()->getArray('SELECT GET_LOCK(?, 0) AS l', [self::name($name)]);
        return 1 === (int) ($row[0]['l'] ?? 0);
    }

    public static function release(string $name): void
    {
        rex_sql::factory()->setQuery('SELECT RELEASE_LOCK(?)', [self::name($name)]);
    }

    private static function name(string $name): string
    {
        // pro Installation eindeutig, MySQL erlaubt max. 64 Zeichen
        return 'vseo_' . substr(md5(rex::getProperty('db')[1]['name'] . rex::getTablePrefix()), 0, 12) . '_' . substr($name, 0, 40);
    }
}
