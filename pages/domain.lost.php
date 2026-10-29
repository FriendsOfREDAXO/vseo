<?php

declare(strict_types=1);

use FriendsOfRedaxo\VSeo\Backend\Page;
use FriendsOfRedaxo\VSeo\Crawler\Crawler;
use FriendsOfRedaxo\VSeo\Model\Domain;
use FriendsOfRedaxo\VSeo\Stats\Compare;
use FriendsOfRedaxo\VSeo\Stats\Period;
use FriendsOfRedaxo\VSeo\Util\Budget;
use FriendsOfRedaxo\VSeo\Util\Format;

/**
 * Verlorene URLs: im Vorzeitraum mit Impressionen, jetzt (fast) keine. Daneben der Statuscode aus dem Crawler.
 *
 * @var rex_addon $this
 * @var Domain $domain
 */

$period = Period::fromRequest($domain);
$min = max(1, rex_get('min', 'int', 10));
$share = max(0, min(50, rex_get('share', 'int', 10)));
$keep = ['min' => $min, 'share' => $share];
$csrf = rex_csrf_token::factory('vseo_lost');

$lost = Compare::lost(Compare::rows($domain->id, Compare::DIM_URL, $period), $min, $share / 100);

// Sofort prüfen: die ersten 50 verlorenen URLs
if ('post' === rex_request_method() && 'check' === rex_post('func', 'string')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        // ungeprüfte zuerst, dann die am längsten nicht geprüften
        $known = Compare::crawlStatus($domain->id, array_column($lost, 'id'));
        $queue = array_column($lost, 'id');
        usort($queue, static fn (int $a, int $b) => (string) ($known[$a]['checked_at'] ?? '') <=> (string) ($known[$b]['checked_at'] ?? ''));
        $count = (new Crawler())->checkNow($domain, array_slice($queue, 0, 50), new Budget(Page::WEB_BUDGET));
        echo null === $count ? rex_view::warning($this->i18n('crawl_busy')) : rex_view::success($this->i18n('lost_checked', $count));
    }
}

$ids = array_column($lost, 'id');
$labels = Compare::labels(Compare::DIM_URL, array_slice($ids, 0, 500));
$crawl = Compare::crawlStatus($domain->id, array_slice($ids, 0, 500));

// Zusammenfassung nach Statuscode
$groups = ['ok' => 0, 'redirect' => 0, 'error' => 0, 'unchecked' => 0];
$lostClicks = 0;
$lostImpressions = 0;
foreach ($lost as $r) {
    $lostClicks += $r['c0'] - $r['c1'];
    $lostImpressions += $r['i0'] - $r['i1'];
}
foreach (array_slice($ids, 0, 500) as $id) {
    $status = $crawl[$id]['status'] ?? null;
    $key = null === $status ? 'unchecked' : (0 === $status || $status >= 400 ? 'error' : ($status >= 300 ? 'redirect' : 'ok'));
    ++$groups[$key];
}

$settings = '<form class="vseo-filter form-inline" method="get" action="' . rex_url::backendController() . '">'
    . '<input type="hidden" name="page" value="vseo/domain/lost"><input type="hidden" name="domain_id" value="' . $domain->id . '">';
foreach ($period->params() as $key => $value) {
    $settings .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
}
$settings .= '<label>' . $this->i18n('lost_min') . ' <input class="form-control input-sm" type="number" name="min" min="1" value="' . $min . '"></label> '
    . '<label>' . $this->i18n('lost_share') . ' <input class="form-control input-sm" type="number" name="share" min="0" max="50" value="' . $share . '"> %</label> '
    . '<button class="btn btn-default btn-sm" type="submit">' . $this->i18n('apply') . '</button></form>';

$summary = '<dl class="vseo-kpis">'
    . '<div class="vseo-kpi is-core"><dt>' . $this->i18n('lost_count') . '</dt><dd><span class="vseo-kpi-value">' . Format::int(count($lost)) . '</span></dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('lost_clicks') . '</dt><dd><span class="vseo-kpi-value">' . Format::int($lostClicks) . '</span></dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('lost_impressions') . '</dt><dd><span class="vseo-kpi-value">' . Format::int($lostImpressions) . '</span></dd></div>'
    . '<div class="vseo-kpi"><dt>' . $this->i18n('lost_by_status') . '</dt><dd class="vseo-status-sum">'
    . '<span class="label label-danger">' . $groups['error'] . ' ' . $this->i18n('status_error') . '</span> '
    . '<span class="label label-warning">' . $groups['redirect'] . ' ' . $this->i18n('status_redirect') . '</span> '
    . '<span class="label label-success">' . $groups['ok'] . ' ' . $this->i18n('status_ok') . '</span> '
    . '<span class="label label-default">' . $groups['unchecked'] . ' ' . $this->i18n('not_checked') . '</span></dd></div>'
    . '</dl>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('lost_title') . ' <small>' . $this->i18n('period_compare', Format::date($period->curFrom), Format::date($period->curTo), Format::date($period->prevFrom), Format::date($period->prevTo)) . '</small>', false);
$fragment->setVar('body', Page::periodForm($domain, $period, 'lost', $keep) . $settings . '<p class="help-block">' . $this->i18n('lost_notice') . '</p>' . $summary, false);
echo $fragment->parse('core/page/section.php');

if (!$lost) {
    echo rex_view::success($this->i18n('lost_none'));
    return;
}

$rows = '';
foreach (array_slice($lost, 0, 500) as $r) {
    $rows .= '<tr><td class="vseo-name">' . Page::urlLink($labels[$r['id']] ?? '#' . $r['id']) . '</td>'
        . '<td>' . Page::statusBadge($crawl[$r['id']] ?? null) . '</td>'
        . '<td class="text-right">' . Format::int($r['c0']) . '</td><td class="text-right">' . Format::int($r['c1']) . '</td>'
        . '<td class="text-right">' . Format::int($r['i0']) . '</td><td class="text-right">' . Format::int($r['i1']) . '</td>'
        . '<td class="text-right">' . Format::dec($r['p0']) . '</td></tr>';
}
$table = '<div class="table-responsive"><table class="table table-hover table-condensed vseo-table"><thead>'
    . '<tr><th>URL</th><th>' . $this->i18n('status_now') . '</th>'
    . '<th class="text-right" colspan="2">' . $this->i18n('kpi_clicks') . '</th><th class="text-right" colspan="2">' . $this->i18n('kpi_impressions') . '</th>'
    . '<th class="text-right">' . $this->i18n('kpi_position') . '</th></tr>'
    . '<tr class="vseo-subhead"><th></th><th></th>'
    . str_repeat('<th class="text-right">' . $this->i18n('period_prev') . '</th><th class="text-right">' . $this->i18n('period_cur') . '</th>', 2)
    . '<th class="text-right">' . $this->i18n('period_prev') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

$button = '<form method="post" action="' . Page::domainUrl($domain, 'lost', $period->params() + $keep) . '">' . $csrf->getHiddenField()
    . '<input type="hidden" name="func" value="check"><button class="btn btn-default" type="submit"><i class="rex-icon fa-refresh"></i> ' . $this->i18n('lost_check_now') . '</button></form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('lost_list') . (count($lost) > 500 ? ' <small>' . $this->i18n('top_n', 500) . '</small>' : ''), false);
$fragment->setVar('content', $table, false);
$fragment->setVar('buttons', $button, false);
echo $fragment->parse('core/page/section.php');
