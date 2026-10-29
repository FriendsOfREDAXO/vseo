<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Source\Rank;

/**
 * Ein Ergebnis des Rank-Trackings: Position eines Hosts für ein Keyword an einem Tag.
 * Entspricht einer Zeile in vseo_rank.
 */
final class RankResult
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $host,
        public readonly string $date,
        /** null = nicht in den abgefragten Ergebnissen */
        public readonly ?int $position,
        /** rankende URL des Hosts */
        public readonly ?string $url = null,
        /** z. B. organic, featured_snippet, local_pack */
        public readonly string $type = 'organic',
    ) {}
}
