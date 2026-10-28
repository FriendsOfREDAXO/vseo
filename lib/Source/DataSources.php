<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source;

use rex_extension;
use rex_extension_point;
use Viewsion\SeoMonitor\Source\Gsc\SearchAnalyticsSource;

final class DataSources
{
    /**
     * Andere AddOns hängen über VSN_SEO_DATA_SOURCES eigene Quellen (DataSourceInterface) an die Liste an.
     *
     * @return array<string, DataSourceInterface>
     */
    public static function all(): array
    {
        /** @var list<DataSourceInterface> $defaults */
        $defaults = [new SearchAnalyticsSource()];
        $result = [];
        foreach (rex_extension::registerPoint(new rex_extension_point('VSN_SEO_DATA_SOURCES', $defaults)) as $source) {
            $result[$source->key()] = $source;
        }
        return $result;
    }
}
