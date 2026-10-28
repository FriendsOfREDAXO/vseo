<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Stats\Series;
use Viewsion\SeoMonitor\Util\Format;

/**
 * @var rex_addon $this
 * @var Domain $domain
 */

$range = rex_request('range', 'int', 12);
if (!in_array($range, [3, 6, 12, 0], true)) {
    $range = 12;
}

// Kennzahlen
$fragment = new rex_fragment();
$fragment->setVar('kpi', $domain->kpi, false);
$kpiHtml = $fragment->parse('vsn_seo/kpi.php');
$period = isset($domain->kpi['end'])
    ? $this->i18n('period_compare', Format::date($domain->kpi['cur']['from']), Format::date($domain->kpi['end']), Format::date($domain->kpi['prev']['from']), Format::date($domain->kpi['prev']['to']))
    : $this->i18n('no_data');

$fragment = new rex_fragment();
$fragment->setVar('title', rex_escape($domain->name) . ' <small>' . $period . '</small>', false);
$fragment->setVar('body', $kpiHtml . ($domain->lastError ? rex_view::error(rex_escape($domain->lastError)) : ''), false);
echo $fragment->parse('core/page/section.php');

// Verlauf
$weeks = Series::weeks($domain->id, $range);
$complete = Series::completeOnly($weeks);
$x = array_map(static fn ($w) => strtotime($w['week'] . ' 12:00:00'), $complete);
$updates = array_map(
    static fn ($u) => ['x' => strtotime($u['date'] . ' 12:00:00'), 'name' => $u['name']],
    Series::coreUpdates($complete[0]['week'] ?? null),
);
$xLabel = $this->i18n('week');
$chart = static fn (string $id, array $series, array $options = []) => '<div class="vsn-seo-chart" id="' . $id . '" data-chart="'
    . rex_escape((string) json_encode(['x' => $x, 'xLabel' => $xLabel, 'series' => $series, 'updates' => $updates] + $options)) . '"></div>';

$toolbar = '<div class="vsn-seo-toolbar"><div class="btn-group">';
foreach ([3 => $this->i18n('range_3'), 6 => $this->i18n('range_6'), 12 => $this->i18n('range_12'), 0 => $this->i18n('range_all')] as $value => $label) {
    $toolbar .= '<a class="btn btn-default' . ($value === $range ? ' active' : '') . '" href="' . Page::domainUrl($domain, 'history', ['range' => $value]) . '">' . $label . '</a>';
}
$toolbar .= '</div><label class="vsn-seo-toggle"><input type="checkbox" data-vsn-seo-updates checked> ' . $this->i18n('show_core_updates') . '</label></div>';

if (count($complete) < 2) {
    $body = $toolbar . rex_view::info($this->i18n('history_too_short'));
} else {
    $body = $toolbar
        . '<h4>' . $this->i18n('chart_rankings') . '</h4>'
        . $chart('vsn-seo-chart-rankings', [
            ['label' => $this->i18n('kpi_queries'), 'values' => array_column($complete, 'queries'), 'color' => 1],
            ['label' => $this->i18n('kpi_urls'), 'values' => array_column($complete, 'urls'), 'color' => 2, 'scale' => 'y2'],
        ])
        . '<h4>' . $this->i18n('chart_traffic') . '</h4>'
        . $chart('vsn-seo-chart-traffic', [
            ['label' => $this->i18n('kpi_clicks'), 'values' => array_column($complete, 'clicks'), 'color' => 1],
            ['label' => $this->i18n('kpi_impressions'), 'values' => array_column($complete, 'impressions'), 'color' => 3, 'scale' => 'y2'],
        ])
        . '<h4>' . $this->i18n('chart_position') . '</h4>'
        . $chart('vsn-seo-chart-position', [
            ['label' => $this->i18n('kpi_position'), 'values' => array_column($complete, 'position'), 'color' => 4, 'decimals' => 1],
        ], ['invert' => true, 'height' => 180]);
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('history_weekly'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// Tabelle (neueste zuerst), unvollständige Wochen markiert
if ($weeks) {
    $rows = '';
    $prev = null;
    foreach ($weeks as $w) {
        $cells = '<td>' . Format::date($w['week']) . ($w['days'] < 7 ? ' <span class="label label-default" title="' . $this->i18n('week_incomplete') . '">' . $w['days'] . '/7</span>' : '') . '</td>';
        foreach (['queries', 'urls', 'clicks', 'impressions'] as $key) {
            $cells .= '<td class="text-right">' . Format::int($w[$key]) . ($prev && $w['days'] >= 7 && $prev['days'] >= 7 ? ' ' . Format::delta($w[$key], $prev[$key]) : '') . '</td>';
        }
        $cells .= '<td class="text-right">' . Format::dec($w['position']) . '</td>';
        $rows = '<tr>' . $cells . '</tr>' . $rows;
        $prev = $w;
    }
    $table = '<div class="table-responsive"><table class="table table-hover table-condensed vsn-seo-table"><thead><tr>'
        . '<th>' . $this->i18n('week') . '</th><th class="text-right">' . $this->i18n('kpi_queries') . '</th><th class="text-right">' . $this->i18n('kpi_urls') . '</th>'
        . '<th class="text-right">' . $this->i18n('kpi_clicks') . '</th><th class="text-right">' . $this->i18n('kpi_impressions') . '</th><th class="text-right">' . $this->i18n('kpi_position') . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $fragment = new rex_fragment();
    $fragment->setVar('title', $this->i18n('history_table'), false);
    $fragment->setVar('content', $table, false);
    $fragment->setVar('collapse', true);
    $fragment->setVar('collapsed', true);
    echo $fragment->parse('core/page/section.php');
}
