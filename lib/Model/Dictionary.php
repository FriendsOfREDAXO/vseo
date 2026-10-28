<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

use rex;
use rex_sql;

/**
 * Wörterbücher für Suchbegriffe und URLs. Liefert zu Texten die IDs und legt fehlende an.
 */
final class Dictionary
{
    public const QUERY = 'query';
    public const URL = 'url';

    private const MAX_LENGTH = [self::QUERY => 500, self::URL => 2000];
    private const CACHE_LIMIT = 200000;

    /** @var array<string, array<string, int>> */
    private static array $cache = [self::QUERY => [], self::URL => []];

    public static function normalize(string $kind, string $value): string
    {
        return mb_substr(trim($value), 0, self::MAX_LENGTH[$kind]);
    }

    /**
     * @param list<string> $values bereits normalisierte Werte
     * @return array<string, int>
     */
    public static function ids(string $kind, array $values): array
    {
        $table = rex::getTable('vsn_seo_' . $kind);
        $result = [];
        $missing = [];
        foreach (array_unique($values) as $value) {
            if (isset(self::$cache[$kind][$value])) {
                $result[$value] = self::$cache[$kind][$value];
            } else {
                $missing[md5($value)] = $value;
            }
        }
        if (!$missing) {
            return $result;
        }
        if (count(self::$cache[$kind]) > self::CACHE_LIMIT) {
            self::$cache[$kind] = [];
        }

        $sql = rex_sql::factory();
        foreach (array_chunk($missing, 500, true) as $chunk) {
            $found = self::lookup($sql, $table, array_keys($chunk));
            $new = array_diff_key($chunk, $found);
            if ($new) {
                $rows = [];
                foreach ($new as $hash => $value) {
                    $rows[] = "(X'" . $hash . "', " . $sql->escape($value) . ')';
                }
                $sql->setQuery('INSERT IGNORE INTO ' . $table . ' (hash, `' . $kind . '`) VALUES ' . implode(',', $rows));
                $found += self::lookup($sql, $table, array_keys($new));
            }
            foreach ($found as $hash => $id) {
                $value = $chunk[$hash];
                $result[$value] = self::$cache[$kind][$value] = $id;
            }
        }
        return $result;
    }

    /**
     * @param list<string> $hashes md5 hex
     * @return array<string, int>
     */
    private static function lookup(rex_sql $sql, string $table, array $hashes): array
    {
        $in = implode(',', array_map(static fn ($h) => "X'" . $h . "'", $hashes));
        $map = [];
        foreach ($sql->getArray('SELECT id, LOWER(HEX(hash)) AS h FROM ' . $table . ' WHERE hash IN (' . $in . ')') as $row) {
            $map[(string) $row['h']] = (int) $row['id'];
        }
        return $map;
    }
}
