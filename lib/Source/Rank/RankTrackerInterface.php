<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Source\Rank;

use FriendsOfRedaxo\VSeo\Model\Domain;

/**
 * Schnittstelle für externe SERP-Dienste (z. B. DataForSEO): Positionen einer festen Keyword-Liste
 * für die eigene Domain und ihre Wettbewerber, wöchentlich abgefragt.
 *
 * Version 1 enthält nur Schnittstelle und Datenstruktur (Tabellen vseo_rank_keyword und vseo_rank).
 * Ein AddOn liefert seine Implementierung über den Extension Point VSEO_RANK_TRACKERS:
 *
 *     rex_extension::register('VSEO_RANK_TRACKERS', static function (rex_extension_point $ep) {
 *         $trackers = $ep->getSubject();
 *         $trackers[] = new MyDataForSeoTracker();
 *         return $trackers;
 *     });
 */
interface RankTrackerInterface
{
    /** Kurzer, eindeutiger Schlüssel, z. B. "dataforseo" */
    public function key(): string;

    public function label(): string;

    /** Ist der Dienst eingerichtet (Zugang hinterlegt)? */
    public function isConfigured(): bool;

    /**
     * Positionen abfragen.
     *
     * @param list<string> $keywords
     * @param list<string> $competitors Hosts der Wettbewerber, z. B. "www.example.org"
     * @param string $country ISO-3166-1 alpha-2, z. B. "de"
     * @param string $device desktop oder mobile
     * @return list<RankResult> je Keyword und Host (eigene Domain und Wettbewerber) ein Ergebnis, auch ohne Ranking
     */
    public function fetch(Domain $domain, array $keywords, array $competitors, string $country, string $device): array;
}
