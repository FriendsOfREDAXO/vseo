<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Alert;

use rex;
use rex_sql;

/**
 * Warnungen mit Verlauf. Eine Warnung entsteht je Domain, Regel und Anlass (ref) nur einmal.
 */
final class AlertRepository
{
    public const INFO = 1;
    public const WARNING = 2;
    public const CRITICAL = 3;

    public static function table(): string
    {
        return rex::getTable('vseo_alert');
    }

    /**
     * Der Text eigener Regeln entsteht erst beim Anzeigen aus $params (in der Sprache des Nutzers);
     * $message ist der fertige Text für Warnungen anderer AddOns.
     *
     * @param list<scalar|null> $params
     * @return bool true, wenn die Warnung neu ist
     */
    public static function raise(int $domainId, string $rule, string $ref, int $severity, string $message, array $params = [], ?float $value = null, ?float $baseline = null): bool
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'INSERT IGNORE INTO ' . self::table() . ' (domain_id, rule, ref, severity, message, params, value, baseline, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$domainId, mb_substr($rule, 0, 40), mb_substr($ref, 0, 100), max(1, min(3, $severity)), mb_substr($message, 0, 500), $params ? json_encode($params) : null, $value, $baseline],
        );
        return $sql->getRows() > 0;
    }

    /**
     * @param list<int>|null $domainIds null = alle
     * @return list<array<string, mixed>>
     */
    public static function find(?array $domainIds, bool $unseenOnly, int $limit = 500, ?string $since = null): array
    {
        if ([] === $domainIds) {
            return [];
        }
        $where = ['1'];
        $params = [];
        if (null !== $domainIds) {
            $where[] = 'domain_id IN (' . implode(',', array_map('intval', $domainIds)) . ')';
        }
        if ($unseenOnly) {
            $where[] = 'seen_at IS NULL';
        }
        if (null !== $since) {
            $where[] = 'created_at >= ?';
            $params[] = $since;
        }
        return rex_sql::factory()->getArray(
            'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
            $params,
        );
    }

    /**
     * Höchste Schwere ungesehener Warnungen je Domain (für die Ampel).
     *
     * @return array<int, int>
     */
    public static function unseenSeverity(): array
    {
        $result = [];
        foreach (rex_sql::factory()->getArray('SELECT domain_id, MAX(severity) AS s FROM ' . self::table() . ' WHERE seen_at IS NULL GROUP BY domain_id') as $row) {
            $result[(int) $row['domain_id']] = (int) $row['s'];
        }
        return $result;
    }

    /** @param list<int> $domainIds Domains, die der Nutzer sehen darf */
    public static function markSeen(array $domainIds, ?int $id, string $user): int
    {
        if (!$domainIds) {
            return 0;
        }
        $sql = rex_sql::factory();
        $where = 'seen_at IS NULL AND domain_id IN (' . implode(',', array_map('intval', $domainIds)) . ')';
        $params = [date('Y-m-d H:i:s'), $user];
        if (null !== $id) {
            $where .= ' AND id = ?';
            $params[] = $id;
        }
        $sql->setQuery('UPDATE ' . self::table() . ' SET seen_at = ?, seen_by = ? WHERE ' . $where, $params);
        return $sql->getRows();
    }

    /** @param list<int> $ids */
    public static function markMailed(array $ids): void
    {
        if ($ids) {
            rex_sql::factory()->setQuery('UPDATE ' . self::table() . ' SET mailed_at = NOW() WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }
}
