<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Source\Gsc\SearchAnalyticsSource;
use Viewsion\SeoMonitor\Stats\Compare;
use Viewsion\SeoMonitor\Stats\Period;
use Viewsion\SeoMonitor\Util\Format;

/**
 * Gewinner und Verlierer zwischen zwei Zeiträumen, je Suchbegriff oder URL.
 *
 * @var rex_addon $this
 * @var Domain $domain
 */

$period = Period::fromRequest($domain);
$dim = Compare::DIM_URL === rex_get('dim', 'string') ? Compare::DIM_URL : Compare::DIM_QUERY;
$metric = 'clicks' === rex_get('metric', 'string') ? 'clicks' : 'impressions';
$device = rex_get('device', 'int', 0);
$country = strtolower(rex_get('country', 'string', ''));
$country = 1 === preg_match('/^[a-z]{3}$/', $country) ? $country : '';
$search = mb_substr(trim(rex_get('search', 'string', '')), 0, 100);
$limit = 100;

$filter = ['device' => $device, 'country' => $country, 'search' => $search];
$rows = Compare::rows($domain->id, $dim, $period, $filter);

// CSV: alle Zeilen mit Veränderung, Gewinner zuerst
if ('csv' === rex_get('export', 'string')) {
    $changed = array_merge(Compare::sorted($rows, $metric, true), array_reverse(Compare::sorted($rows, $metric, false)));
    $labels = Compare::labels($dim, array_column($changed, 'id'));
    $out = fopen('php://temp', 'w+');
    $num = static fn (?float $v) => null === $v ? '' : str_replace('.', ',', (string) round($v, 1));
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        Compare::DIM_URL === $dim ? 'URL' : $this->i18n('query'),
        $this->i18n('kpi_clicks') . ' ' . $this->i18n('period_prev'), $this->i18n('kpi_clicks') . ' ' . $this->i18n('period_cur'), $this->i18n('kpi_clicks') . ' Δ',
        $this->i18n('kpi_impressions') . ' ' . $this->i18n('period_prev'), $this->i18n('kpi_impressions') . ' ' . $this->i18n('period_cur'), $this->i18n('kpi_impressions') . ' Δ',
        $this->i18n('kpi_position') . ' ' . $this->i18n('period_prev'), $this->i18n('kpi_position') . ' ' . $this->i18n('period_cur'),
    ], ';', '"', '');
    foreach ($changed as $r) {
        fputcsv($out, [
            $labels[$r['id']] ?? '', $r['c0'], $r['c1'], $r['c1'] - $r['c0'], $r['i0'], $r['i1'], $r['i1'] - $r['i0'], $num($r['p0']), $num($r['p1']),
        ], ';', '"', '');
    }
    rewind($out);
    $filename = preg_replace('/[^a-z0-9.-]+/i', '_', $domain->host) . '_' . $dim . '_' . $period->curFrom . '_' . $period->curTo . '.csv';
    rex_response::cleanOutputBuffers();
    rex_response::sendContentType('text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    rex_response::sendContent((string) stream_get_contents($out));
    exit;
}

$keep = array_filter(['dim' => $dim, 'metric' => $metric, 'device' => $device, 'country' => $country, 'search' => $search]);
$url = static fn (array $params = []) => Page::domainUrl($domain, 'compare', $params + $period->params() + $keep);

// Filterleiste
$tabs = '<div class="btn-group">';
foreach ([Compare::DIM_QUERY => $this->i18n('by_query'), Compare::DIM_URL => $this->i18n('by_url')] as $value => $label) {
    $tabs .= '<a class="btn btn-default' . ($value === $dim ? ' active' : '') . '" href="' . $url(['dim' => $value]) . '">' . $label . '</a>';
}
$tabs .= '</div>';

$hidden = '<input type="hidden" name="page" value="vseo/domain/compare"><input type="hidden" name="domain_id" value="' . $domain->id . '"><input type="hidden" name="dim" value="' . $dim . '">';
foreach ($period->params() as $key => $value) {
    $hidden .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
}
$deviceSelect = '<select class="form-control input-sm" name="device"><option value="0">' . $this->i18n('all_devices') . '</option>';
foreach ([1, 2, 3] as $d) {
    $deviceSelect .= '<option value="' . $d . '"' . ($d === $device ? ' selected' : '') . '>' . $this->i18n('device_' . strtolower(SearchAnalyticsSource::deviceName($d))) . '</option>';
}
$deviceSelect .= '</select>';
$countrySelect = '<select class="form-control input-sm" name="country"><option value="">' . $this->i18n('all_countries') . '</option>';
foreach (Compare::countries($domain->id, $period) as $c) {
    $countrySelect .= '<option value="' . rex_escape($c) . '"' . ($c === $country ? ' selected' : '') . '>' . rex_escape(strtoupper($c)) . '</option>';
}
$countrySelect .= '</select>';
$metricSelect = '<select class="form-control input-sm" name="metric">'
    . '<option value="impressions"' . ('impressions' === $metric ? ' selected' : '') . '>' . $this->i18n('sort_by', $this->i18n('kpi_impressions')) . '</option>'
    . '<option value="clicks"' . ('clicks' === $metric ? ' selected' : '') . '>' . $this->i18n('sort_by', $this->i18n('kpi_clicks')) . '</option></select>';
$filters = '<form class="vseo-filter form-inline" method="get" action="' . rex_url::backendController() . '">' . $hidden
    . $deviceSelect . ' ' . $countrySelect . ' ' . $metricSelect . ' '
    . '<input class="form-control input-sm" type="search" name="search" value="' . rex_escape($search) . '" placeholder="' . $this->i18n('search_placeholder') . '"> '
    . '<button class="btn btn-default btn-sm" type="submit">' . $this->i18n('apply') . '</button> '
    . '<a class="btn btn-default btn-sm" href="' . $url(['export' => 'csv']) . '"><i class="rex-icon fa-download"></i> CSV</a>'
    . '</form>';

// Zusammenfassung
$sum = Compare::summary($rows);
$noun = Compare::DIM_URL === $dim ? $this->i18n('kpi_urls') : $this->i18n('kpi_queries');
$summary = '<dl class="vseo-kpis">'
    . '<div class="vseo-kpi is-core"><dt>' . $noun . '</dt><dd><span class="vseo-kpi-value">' . Format::int($sum['cur']) . '</span>' . Format::delta($sum['cur'], $sum['prev']) . '</dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('compare_prev_count') . '</dt><dd><span class="vseo-kpi-value">' . Format::int($sum['prev']) . '</span></dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('compare_new') . '</dt><dd><span class="vseo-kpi-value">' . Format::int($sum['new']) . '</span></dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('compare_gone') . '</dt><dd><span class="vseo-kpi-value">' . Format::int($sum['gone']) . '</span></dd></div>'
    . '</dl>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('compare_title') . ' <small>' . $this->i18n('period_compare', Format::date($period->curFrom), Format::date($period->curTo), Format::date($period->prevFrom), Format::date($period->prevTo)) . '</small>', false);
$fragment->setVar('body', Page::periodForm($domain, $period, 'compare', $keep) . '<div class="vseo-toolbar">' . $tabs . '</div>' . $filters . $summary, false);
echo $fragment->parse('core/page/section.php');

// Tabellen
$winners = Compare::sorted($rows, $metric, true, $limit);
$losers = Compare::sorted($rows, $metric, false, $limit);
$labels = Compare::labels($dim, array_merge(array_column($winners, 'id'), array_column($losers, 'id')));
$crawl = Compare::DIM_URL === $dim ? Compare::crawlStatus($domain->id, array_merge(array_column($winners, 'id'), array_column($losers, 'id'))) : [];

$diff = static function (int $cur, int $prev): string {
    $d = $cur - $prev;
    return '<span class="vseo-diff ' . ($d > 0 ? 'is-up' : ($d < 0 ? 'is-down' : '')) . '">' . ($d > 0 ? '+' : '') . Format::int($d) . '</span>';
};
$table = function (array $list) use ($dim, $labels, $crawl, $diff): string {
    if (!$list) {
        return '<p class="text-muted">' . $this->i18n('compare_none') . '</p>';
    }
    $head = '<tr><th>' . (Compare::DIM_URL === $dim ? 'URL' : $this->i18n('query')) . '</th>'
        . '<th class="text-right" colspan="3">' . $this->i18n('kpi_clicks') . '</th>'
        . '<th class="text-right" colspan="3">' . $this->i18n('kpi_impressions') . '</th>'
        . '<th class="text-right" colspan="2">' . $this->i18n('kpi_position') . '</th></tr>'
        . '<tr class="vseo-subhead"><th></th>'
        . str_repeat('<th class="text-right">' . $this->i18n('period_prev') . '</th><th class="text-right">' . $this->i18n('period_cur') . '</th><th class="text-right">Δ</th>', 2)
        . '<th class="text-right">' . $this->i18n('period_prev') . '</th><th class="text-right">' . $this->i18n('period_cur') . '</th></tr>';
    $body = '';
    foreach ($list as $r) {
        $label = $labels[$r['id']] ?? '#' . $r['id'];
        $name = Compare::DIM_URL === $dim
            ? Page::urlLink($label) . ' ' . Page::statusBadge($crawl[$r['id']] ?? null)
            : rex_escape($label);
        $body .= '<tr><td class="vseo-name">' . $name . '</td>'
            . '<td class="text-right">' . Format::int($r['c0']) . '</td><td class="text-right">' . Format::int($r['c1']) . '</td><td class="text-right">' . $diff($r['c1'], $r['c0']) . '</td>'
            . '<td class="text-right">' . Format::int($r['i0']) . '</td><td class="text-right">' . Format::int($r['i1']) . '</td><td class="text-right">' . $diff($r['i1'], $r['i0']) . '</td>'
            . '<td class="text-right">' . Format::dec($r['p0']) . '</td><td class="text-right">' . Format::dec($r['p1']) . '</td></tr>';
    }
    return '<div class="table-responsive"><table class="table table-hover table-condensed vseo-table"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>';
};

foreach ([[$this->i18n('winners'), $winners], [$this->i18n('losers'), $losers]] as [$title, $list]) {
    $fragment = new rex_fragment();
    $fragment->setVar('title', $title . ' <small>' . $this->i18n('top_n', $limit) . '</small>', false);
    $fragment->setVar('content', $table($list), false);
    echo $fragment->parse('core/page/section.php');
}
