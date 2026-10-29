<?php

declare(strict_types=1);

use FriendsOfRedaxo\VSeo\Backend\Page;
use FriendsOfRedaxo\VSeo\Model\Domain;
use FriendsOfRedaxo\VSeo\Stats\Aggregator;
use FriendsOfRedaxo\VSeo\Stats\Compare;
use FriendsOfRedaxo\VSeo\Stats\Period;
use FriendsOfRedaxo\VSeo\Util\Format;

/**
 * Matomo: Besuche aus Suchmaschinen, Einstiegsseiten aus der Suche mit Umsatz, 404-Verlauf.
 *
 * @var rex_addon $this
 * @var Domain $domain
 */

if (!$domain->hasMatomo()) {
    echo rex_view::info($this->i18n('matomo_not_linked') . (rex::getUser()?->hasPerm('vseo[settings]')
        ? ' <a href="' . rex_url::backendPage('vseo/settings/domains', ['func' => 'edit', 'id' => $domain->id]) . '">' . $this->i18n('domain_edit') . '</a>' : ''));
    return;
}

$sql = rex_sql::factory();
$period = Period::fromRequest($domain);
$money = static fn (float $v) => Format::dec($v, 2);

// Wochenverlauf (Montag), letzte 12 Monate
$weeks = $sql->getArray(
    'SELECT DATE_SUB(date, INTERVAL WEEKDAY(date) DAY) AS week, COUNT(*) AS days, SUM(visits) AS visits, SUM(search_visits) AS search, SUM(search_revenue) AS revenue,
        SUM(hits_404) AS nf, SUM(hits_404_search) AS nf_search
     FROM ' . rex::getTable('vseo_mt_day') . ' WHERE domain_id = ? AND date >= ? GROUP BY week ORDER BY week',
    [$domain->id, date('Y-m-d', strtotime('-12 months'))],
);
$weeks = array_values(array_filter($weeks, static fn ($w) => 7 === (int) $w['days']));
$x = array_map(static fn ($w) => strtotime($w['week'] . ' 12:00:00'), $weeks);
$xLabel = $this->i18n('week');
$chart = static fn (string $id, array $series, array $options = []) => '<div class="vseo-chart" id="' . $id . '" data-chart="'
    . rex_escape((string) json_encode(['x' => $x, 'xLabel' => $xLabel, 'series' => $series, 'updates' => []] + $options)) . '"></div>';

$totals = $sql->getArray(
    'SELECT SUM(IF(date BETWEEN ? AND ?, search_visits, 0)) AS s1, SUM(IF(date BETWEEN ? AND ?, search_visits, 0)) AS s0,
        SUM(IF(date BETWEEN ? AND ?, search_conversions, 0)) AS c1, SUM(IF(date BETWEEN ? AND ?, search_conversions, 0)) AS c0,
        SUM(IF(date BETWEEN ? AND ?, search_revenue, 0)) AS r1, SUM(IF(date BETWEEN ? AND ?, search_revenue, 0)) AS r0,
        SUM(IF(date BETWEEN ? AND ?, hits_404_search, 0)) AS n1, SUM(IF(date BETWEEN ? AND ?, hits_404_search, 0)) AS n0,
        MAX(date) AS last
     FROM ' . rex::getTable('vseo_mt_day') . ' WHERE domain_id = ?',
    [
        $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo,
        $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo,
        $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo,
        $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo,
        $domain->id,
    ],
)[0];
$kpi = static fn (string $label, string $value, string $delta, string $class = '') => '<div class="vseo-kpi' . $class . '"><dt>' . $label . '</dt><dd><span class="vseo-kpi-value">' . $value . '</span>' . $delta . '</dd></div>';
$kpis = '<dl class="vseo-kpis">'
    . $kpi($this->i18n('mt_search_visits'), Format::int((int) $totals['s1']), Format::delta((int) $totals['s1'], (int) $totals['s0']), ' is-core')
    . $kpi($this->i18n('mt_conversions'), Format::int((int) $totals['c1']), Format::delta((int) $totals['c1'], (int) $totals['c0']))
    . $kpi($this->i18n('mt_revenue'), $money((float) $totals['r1']), Format::delta((float) $totals['r1'], (float) $totals['r0']))
    . $kpi($this->i18n('mt_404_search'), Format::int((int) $totals['n1']), Format::delta((int) $totals['n1'], (int) $totals['n0'], true))
    . '</dl><p class="help-block">' . $this->i18n('mt_state', Format::date($totals['last'] ? (string) $totals['last'] : null), (int) $domain->matomoSiteId) . '</p>';

$body = Page::periodForm($domain, $period, 'matomo') . $kpis;
if (count($weeks) >= 2) {
    $body .= '<h4>' . $this->i18n('mt_chart_visits') . '</h4>'
        . $chart('vseo-chart-mt-visits', [
            ['label' => $this->i18n('mt_search_visits'), 'values' => array_map('intval', array_column($weeks, 'search')), 'color' => 1],
            ['label' => $this->i18n('mt_visits'), 'values' => array_map('intval', array_column($weeks, 'visits')), 'color' => 3, 'scale' => 'y2'],
        ])
        . '<h4>' . $this->i18n('mt_chart_404') . '</h4>'
        . $chart('vseo-chart-mt-404', [
            ['label' => $this->i18n('mt_404_search'), 'values' => array_map('intval', array_column($weeks, 'nf_search')), 'color' => 2],
            ['label' => $this->i18n('mt_404'), 'values' => array_map('intval', array_column($weeks, 'nf')), 'color' => 3],
        ], ['height' => 180]);
} else {
    $body .= rex_view::info($this->i18n('history_too_short'));
}

$fragment = new rex_fragment();
$fragment->setVar('title', 'Matomo <small>' . $this->i18n('period_compare', Format::date($period->curFrom), Format::date($period->curTo), Format::date($period->prevFrom), Format::date($period->prevTo)) . '</small>', false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

$section = static function (string $title, string $content): void {
    $fragment = new rex_fragment();
    $fragment->setVar('title', $title, false);
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
};
$table = static fn (string $head, string $rows) => '<div class="table-responsive"><table class="table table-hover table-condensed vseo-table"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table></div>';
$params = [$period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo, $domain->id, $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo];

// Suchmaschinen
$rows = '';
foreach ($sql->getArray(
    'SELECT engine, SUM(IF(date BETWEEN ? AND ?, visits, 0)) AS v1, SUM(IF(date BETWEEN ? AND ?, visits, 0)) AS v0 FROM ' . rex::getTable('vseo_mt_engine') . '
     WHERE domain_id = ? AND (date BETWEEN ? AND ? OR date BETWEEN ? AND ?) GROUP BY engine ORDER BY v1 DESC LIMIT 20',
    $params,
) as $r) {
    $rows .= '<tr><td>' . rex_escape((string) $r['engine']) . '</td><td class="text-right">' . Format::int((int) $r['v0']) . '</td><td class="text-right">' . Format::int((int) $r['v1']) . '</td><td class="text-right">' . Format::delta((int) $r['v1'], (int) $r['v0']) . '</td></tr>';
}
$section($this->i18n('mt_engines'), $rows
    ? $table('<tr><th>' . $this->i18n('mt_engine') . '</th><th class="text-right">' . $this->i18n('period_prev') . '</th><th class="text-right">' . $this->i18n('period_cur') . '</th><th class="text-right">Δ</th></tr>', $rows)
    : '<p class="text-muted">' . $this->i18n('no_data') . '</p>');

// Einstiegsseiten aus der Suche
$entries = $sql->getArray(
    'SELECT url_id, SUM(IF(date BETWEEN ? AND ?, entrances, 0)) AS e1, SUM(IF(date BETWEEN ? AND ?, entrances, 0)) AS e0,
        SUM(IF(date BETWEEN ? AND ?, conversions, 0)) AS c1, SUM(IF(date BETWEEN ? AND ?, revenue, 0)) AS r1
     FROM ' . rex::getTable('vseo_mt_entry') . ' WHERE domain_id = ? AND (date BETWEEN ? AND ? OR date BETWEEN ? AND ?)
     GROUP BY url_id ORDER BY r1 DESC, e1 DESC LIMIT 100',
    [$period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo, $period->curFrom, $period->curTo, $period->curFrom, $period->curTo,
        $domain->id, $period->curFrom, $period->curTo, $period->prevFrom, $period->prevTo],
);
$labels = Compare::labels(Compare::DIM_URL, array_map(static fn ($r) => (int) $r['url_id'], $entries));
$rows = '';
foreach ($entries as $r) {
    $rows .= '<tr><td class="vseo-name">' . Page::urlLink($labels[(int) $r['url_id']] ?? '#' . $r['url_id']) . '</td>'
        . '<td class="text-right">' . Format::int((int) $r['e0']) . '</td><td class="text-right">' . Format::int((int) $r['e1']) . '</td><td class="text-right">' . Format::delta((int) $r['e1'], (int) $r['e0']) . '</td>'
        . '<td class="text-right">' . Format::int((int) $r['c1']) . '</td><td class="text-right">' . $money((float) $r['r1']) . '</td></tr>';
}
$section($this->i18n('mt_entries') . ' <small>' . $this->i18n('top_n', 100) . '</small>', $rows
    ? $table('<tr><th>URL</th><th class="text-right">' . $this->i18n('mt_entrances') . ' ' . $this->i18n('period_prev') . '</th><th class="text-right">' . $this->i18n('period_cur') . '</th><th class="text-right">Δ</th>'
        . '<th class="text-right">' . $this->i18n('mt_conversions') . '</th><th class="text-right">' . $this->i18n('mt_revenue') . '</th></tr>', $rows)
    : '<p class="text-muted">' . $this->i18n('mt_entries_none') . '</p>');

// Häufigste 404-URLs mit Status aus dem Crawler
$notFound = $sql->getArray(
    'SELECT url_id, SUM(hits) AS h, SUM(hits_search) AS hs, MIN(date) AS first, MAX(date) AS last FROM ' . rex::getTable('vseo_mt_404') . '
     WHERE domain_id = ? AND date BETWEEN ? AND ? GROUP BY url_id ORDER BY hs DESC, h DESC LIMIT 100',
    [$domain->id, $period->curFrom, Aggregator::shift(date('Y-m-d'), -1)],
);
$ids = array_map(static fn ($r) => (int) $r['url_id'], $notFound);
$labels = Compare::labels(Compare::DIM_URL, $ids);
$crawl = Compare::crawlStatus($domain->id, $ids);
$rows = '';
foreach ($notFound as $r) {
    $url = $labels[(int) $r['url_id']] ?? '';
    $rows .= '<tr><td class="vseo-name">' . (str_starts_with($url, 'http') ? Page::urlLink($url) . ' ' . Page::statusBadge($crawl[(int) $r['url_id']] ?? null) : rex_escape($url)) . '</td>'
        . '<td class="text-right">' . Format::int((int) $r['hs']) . '</td><td class="text-right">' . Format::int((int) $r['h']) . '</td>'
        . '<td>' . Format::date((string) $r['first']) . ' – ' . Format::date((string) $r['last']) . '</td></tr>';
}
$section($this->i18n('mt_404_top') . ' <small>' . Format::date($period->curFrom) . ' – ' . $this->i18n('today') . '</small>', $rows
    ? $table('<tr><th>URL</th><th class="text-right">' . $this->i18n('mt_from_search') . '</th><th class="text-right">' . $this->i18n('mt_hits') . '</th><th>' . $this->i18n('mt_seen') . '</th></tr>', $rows)
    : '<p class="text-muted">' . $this->i18n('mt_404_none', rex_escape((string) $this->getConfig('matomo_404_pattern', '404'))) . '</p>');
