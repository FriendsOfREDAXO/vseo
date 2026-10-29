<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Source;

use rex_extension;
use rex_extension_point;
use FriendsOfRedaxo\VSeo\Source\Gsc\InspectionSource;
use FriendsOfRedaxo\VSeo\Source\Gsc\SearchAnalyticsSource;
use FriendsOfRedaxo\VSeo\Source\Matomo\MatomoSource;

final class DataSources
{
    /**
     * Andere AddOns hängen über VSEO_DATA_SOURCES eigene Quellen (DataSourceInterface) an die Liste an.
     *
     * @return array<string, DataSourceInterface>
     */
    public static function all(): array
    {
        /** @var list<DataSourceInterface> $defaults */
        $defaults = [new SearchAnalyticsSource(), new MatomoSource(), new InspectionSource()];
        $result = [];
        foreach (rex_extension::registerPoint(new rex_extension_point('VSEO_DATA_SOURCES', $defaults)) as $source) {
            $result[$source->key()] = $source;
        }
        return $result;
    }

    /**
     * Rank-Tracker anderer AddOns (VSEO_RANK_TRACKERS). Das AddOn selbst bringt keinen mit.
     *
     * @return array<string, Rank\RankTrackerInterface>
     */
    public static function rankTrackers(): array
    {
        $result = [];
        foreach (rex_extension::registerPoint(new rex_extension_point('VSEO_RANK_TRACKERS', self::noTrackers())) as $tracker) {
            $result[$tracker->key()] = $tracker;
        }
        return $result;
    }

    /** @return list<Rank\RankTrackerInterface> */
    private static function noTrackers(): array
    {
        return [];
    }
}
