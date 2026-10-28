<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Alert\AlertRepository;
use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Stats\Series;
use Viewsion\SeoMonitor\Util\Format;

/** @var rex_addon $this */

$domains = array_filter(DomainRepository::visibleFor(rex::getUser()), static fn ($d) => $d->active);
if (!$domains) {
    echo rex_view::info($this->i18n('overview_empty') . (rex::getUser()?->hasPerm('vsn_seo[settings]')
        ? ' <a href="' . rex_url::backendPage('vsn_seo/settings/domains') . '">' . $this->i18n('domains') . '</a>' : ''));
    return;
}

$alertSeverity = AlertRepository::unseenSeverity();
$tiles = '';
foreach ($domains as $domain) {
    $weeks = Series::completeOnly(Series::weeks($domain->id, 6));
    $kpi = $domain->kpi;

    // Ampel: ungesehene Warnungen (kritisch = rot), Importfehler, veraltete Daten
    $stale = !isset($kpi['end']) || $kpi['end'] < date('Y-m-d', strtotime('-7 days'));
    $severity = $alertSeverity[$domain->id] ?? 0;
    if (AlertRepository::CRITICAL === $severity || $domain->lastError) {
        $light = 'is-red';
    } elseif ($severity > 0 || $stale) {
        $light = 'is-yellow';
    } else {
        $light = 'is-green';
    }
    $lightTitle = $severity ? $this->i18n('light_alerts') : ($domain->lastError ?: ($stale ? $this->i18n('light_stale') : $this->i18n('light_ok')));

    $fragment = new rex_fragment();
    $fragment->setVar('kpi', $kpi, false);
    $fragment->setVar('only', ['queries', 'urls', 'clicks', 'impressions'], false);

    $tiles .= '<a class="vsn-seo-tile" href="' . Page::domainUrl($domain) . '">'
        . '<header><span class="vsn-seo-light ' . $light . '" title="' . rex_escape($lightTitle) . '"></span>'
        . '<strong>' . rex_escape($domain->name) . '</strong>'
        . ($domain->name !== $domain->host ? ' <small>' . rex_escape($domain->host) . '</small>' : '') . '</header>'
        . $fragment->parse('vsn_seo/kpi.php')
        . '<div class="vsn-seo-sparks">'
        . '<div class="is-core" title="' . $this->i18n('kpi_queries') . '">' . Series::sparkline(array_column($weeks, 'queries')) . '<small>' . $this->i18n('kpi_queries') . '</small></div>'
        . '<div title="' . $this->i18n('kpi_clicks') . '">' . Series::sparkline(array_column($weeks, 'clicks')) . '<small>' . $this->i18n('kpi_clicks') . '</small></div>'
        . '</div>'
        . '<footer>' . (isset($kpi['end']) ? $this->i18n('period_until', Format::date($kpi['cur']['from'] ?? null), Format::date($kpi['end'])) : $this->i18n('no_data')) . '</footer>'
        . '</a>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('overview_title'), false);
$fragment->setVar('body', '<div class="vsn-seo-tiles">' . $tiles . '</div>', false);
echo $fragment->parse('core/page/section.php');
