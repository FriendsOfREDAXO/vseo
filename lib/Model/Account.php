<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Model;

use FriendsOfRedaxo\VSeo\Util\Crypto;

/**
 * Ein Zugang zu einer Datenquelle. Die Zugangsdaten bleiben verschlüsselt, bis sie gebraucht werden.
 */
final class Account
{
    public const TYPE_GSC = 'gsc';
    public const TYPE_MATOMO = 'matomo';

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        /** Anzeigewert ohne Geheimnisse: Dienstkonto-E-Mail bzw. Matomo-URL */
        public readonly string $info,
        private readonly string $encrypted,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['type'], (string) $row['name'], (string) $row['info'], (string) $row['credentials']);
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        if ('' === $this->encrypted) {
            return [];
        }
        $data = json_decode(Crypto::decrypt($this->encrypted), true);
        return is_array($data) ? $data : [];
    }

    public function label(): string
    {
        return $this->name . ('' !== $this->info ? ' (' . $this->info . ')' : '');
    }
}
