<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

use rex;
use rex_sql;
use Viewsion\SeoMonitor\Util\Crypto;

final class AccountRepository
{
    public static function table(): string
    {
        return rex::getTable('vseo_account');
    }

    /** @return list<Account> */
    public static function all(?string $type = null): array
    {
        $sql = rex_sql::factory();
        $rows = null === $type
            ? $sql->getArray('SELECT * FROM ' . self::table() . ' ORDER BY type, name')
            : $sql->getArray('SELECT * FROM ' . self::table() . ' WHERE type = ? ORDER BY name', [$type]);
        return array_map(Account::fromRow(...), $rows);
    }

    public static function get(?int $id): ?Account
    {
        if (!$id) {
            return null;
        }
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . self::table() . ' WHERE id = ?', [$id]);
        return $rows ? Account::fromRow($rows[0]) : null;
    }

    /**
     * @param array<string, mixed>|null $credentials null = unverändert lassen
     */
    public static function save(?int $id, string $type, string $name, string $info, ?array $credentials): int
    {
        $sql = rex_sql::factory()->setTable(self::table());
        $sql->setValue('type', $type);
        $sql->setValue('name', $name);
        $sql->setValue('info', $info);
        if (null !== $credentials) {
            $sql->setValue('credentials', Crypto::encrypt((string) json_encode($credentials)));
        }
        $user = rex::getUser()?->getLogin() ?? 'console';
        $sql->addGlobalUpdateFields($user);
        if ($id) {
            $sql->setWhere(['id' => $id])->update();
            return $id;
        }
        $sql->addGlobalCreateFields($user);
        $sql->insert();
        return (int) $sql->getLastId();
    }

    public static function delete(int $id): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE id = ?', [$id]);
        rex_sql::factory()->setQuery('UPDATE ' . DomainRepository::table() . ' SET gsc_account_id = NULL WHERE gsc_account_id = ?', [$id]);
        rex_sql::factory()->setQuery('UPDATE ' . DomainRepository::table() . ' SET matomo_account_id = NULL WHERE matomo_account_id = ?', [$id]);
    }
}
