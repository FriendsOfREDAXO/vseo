<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Crawler\Crawler;
use Viewsion\SeoMonitor\Crawler\Issues;
use Viewsion\SeoMonitor\Crawler\PageParser;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Source\Gsc\InspectionSource;
use Viewsion\SeoMonitor\Util\Budget;
use Viewsion\SeoMonitor\Util\Format;

/**
 * Technik: robots.txt, Sitemaps, Stand des Crawlers und Problemliste mit Verlauf.
 *
 * @var rex_addon $this
 * @var Domain $domain
 */

$csrf = rex_csrf_token::factory('vsn_seo_technical');
$type = rex_get('type', 'string', '');
$type = isset(Issues::SEVERITY[$type]) ? $type : '';
$showResolved = rex_get('resolved', 'bool', false);

if ('post' === rex_request_method() && 'crawl' === rex_post('func', 'string')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $crawler = new Crawler();
        $crawler->run(new Budget(Page::WEB_BUDGET), $domain->id, null, true);
        // Meldungen des Crawlers sind technische Texte (englisch), daher als Info
        echo rex_view::info(rex_escape(implode(' · ', $crawler->messages())));
        $domain = DomainRepository::get($domain->id) ?? $domain;
    }
}

$sql = rex_sql::factory();
$state = $domain->crawl;

// robots.txt
$robots = (array) ($state['robots'] ?? []);
if (!$robots) {
    $robotsHtml = '<p class="text-muted">' . $this->i18n('crawl_never') . '</p>';
} else {
    $status = (int) ($robots['status'] ?? 0);
    $robotsHtml = '<p>' . Page::urlLink((string) $robots['url']) . ' '
        . '<span class="label ' . (200 === $status ? 'label-success' : 'label-danger') . '">' . ($status ?: rex_escape((string) ($robots['error'] ?? '–'))) . '</span> '
        . '<small class="text-muted">' . $this->i18n('checked_at', Format::date((string) $robots['checked_at'])) . '</small></p>';
    $listed = (array) ($robots['sitemaps'] ?? []);
    $robotsHtml .= '<p>' . $this->i18n('robots_sitemaps') . ': ' . ($listed ? implode(', ', array_map(static fn ($u) => Page::urlLink((string) $u), $listed)) : '<strong>' . $this->i18n('none') . '</strong>') . '</p>';
}

// Sitemaps
$sitemaps = $sql->getArray('SELECT * FROM ' . rex::getTable('vsn_seo_sitemap') . ' WHERE domain_id = ? ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, url', [$domain->id]);
if ($sitemaps) {
    $rows = '';
    foreach ($sitemaps as $s) {
        $status = null !== $s['status'] ? (int) $s['status'] : null;
        $badge = null === $status ? '<span class="label label-default">–</span>'
            : '<span class="label ' . (200 === $status && !$s['error'] ? 'label-success' : 'label-danger') . '" title="' . rex_escape((string) $s['error']) . '">' . ($status ?: '!') . ($s['error'] && 200 === $status ? ' ' . rex_escape((string) $s['error']) : '') . '</span>';
        $gsc = null !== $s['gsc_checked_at']
            ? Format::int(null !== $s['gsc_submitted'] ? (int) $s['gsc_submitted'] : null)
                . ((int) $s['gsc_errors'] ? ' <span class="label label-danger">' . (int) $s['gsc_errors'] . ' ' . $this->i18n('errors') . '</span>' : '')
                . ((int) $s['gsc_warnings'] ? ' <span class="label label-warning">' . (int) $s['gsc_warnings'] . ' ' . $this->i18n('warnings') . '</span>' : '')
                . ((int) $s['gsc_pending'] ? ' <span class="label label-default">' . $this->i18n('pending') . '</span>' : '')
            : '–';
        $rows .= '<tr' . ($s['parent_id'] ? ' class="vsn-seo-child"' : '') . '><td class="vsn-seo-name">' . Page::urlLink((string) $s['url']) . ($s['is_index'] ? ' <span class="label label-info">Index</span>' : '') . '</td>'
            . '<td>' . $this->i18n('sitemap_source_' . $s['source']) . '</td>'
            . '<td>' . $badge . '</td>'
            . '<td class="text-right">' . Format::int(null !== $s['url_count'] ? (int) $s['url_count'] : null) . '</td>'
            . '<td class="text-right">' . $gsc . '</td>'
            . '<td>' . Format::date($s['gsc_last_downloaded'] ? (string) $s['gsc_last_downloaded'] : null) . '</td></tr>';
    }
    $sitemapHtml = '<div class="table-responsive"><table class="table table-condensed vsn-seo-table"><thead><tr><th>Sitemap</th><th>' . $this->i18n('origin') . '</th><th>' . $this->i18n('status') . '</th>'
        . '<th class="text-right">' . $this->i18n('sitemap_entries') . '</th><th class="text-right">' . $this->i18n('sitemap_gsc_submitted') . '</th><th>' . $this->i18n('sitemap_gsc_downloaded') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    if (isset($state['sitemaps_complete']) && !$state['sitemaps_complete']) {
        $sitemapHtml .= rex_view::warning($this->i18n('sitemaps_incomplete'));
    }
} else {
    $sitemapHtml = '<p class="text-muted">' . $this->i18n('crawl_never') . '</p>';
}

// Stand des Crawlers
$c = $sql->getArray(
    'SELECT COUNT(*) AS total, SUM(checked_at IS NOT NULL) AS checked, SUM(in_sitemap) AS sitemap, SUM(from_gsc) AS gsc,
        SUM(checked_at IS NOT NULL AND status BETWEEN 200 AND 299) AS s2, SUM(status BETWEEN 300 AND 399) AS s3,
        SUM(status BETWEEN 400 AND 499) AS s4, SUM(status >= 500) AS s5, SUM(checked_at IS NOT NULL AND status = 0) AS s0,
        SUM(noindex) AS noindex, SUM(indexable) AS indexable, MAX(checked_at) AS last
     FROM ' . Crawler::table() . ' WHERE domain_id = ?',
    [$domain->id],
)[0];
$stat = static fn (string $label, mixed $value, string $class = '') => '<div class="vsn-seo-kpi' . $class . '"><dt>' . $label . '</dt><dd><span class="vsn-seo-kpi-value">' . Format::int((int) $value) . '</span></dd></div>';
$crawlHtml = '<dl class="vsn-seo-kpis">'
    . $stat($this->i18n('crawl_total'), $c['total'], ' is-core')
    . $stat($this->i18n('crawl_checked'), $c['checked'])
    . $stat($this->i18n('crawl_in_sitemap'), $c['sitemap'])
    . $stat($this->i18n('crawl_from_gsc'), $c['gsc'])
    . $stat('2xx', $c['s2'])
    . $stat('3xx', $c['s3'])
    . $stat('4xx', $c['s4'], (int) $c['s4'] ? ' is-bad' : '')
    . $stat('5xx / ' . $this->i18n('no_response'), (int) $c['s5'] + (int) $c['s0'], (int) $c['s5'] + (int) $c['s0'] ? ' is-bad' : '')
    . $stat('noindex', $c['noindex'])
    . $stat($this->i18n('crawl_indexable'), $c['indexable'])
    . '</dl><p class="help-block">' . $this->i18n('crawl_state', Format::date($state['site_at'] ?? null), Format::date($c['last'] ? (string) $c['last'] : null)) . '</p>';

$button = '<form method="post" action="' . Page::domainUrl($domain, 'technical') . '">' . $csrf->getHiddenField()
    . '<input type="hidden" name="func" value="crawl"><button class="btn btn-default" type="submit"><i class="rex-icon fa-refresh"></i> ' . $this->i18n('crawl_now') . '</button></form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('technical_title'), false);
$fragment->setVar('body', '<div class="row"><div class="col-md-5"><h4>robots.txt</h4>' . $robotsHtml . '</div><div class="col-md-7"><h4>' . $this->i18n('crawler') . '</h4>' . $crawlHtml . '</div></div>'
    . '<h4>' . $this->i18n('sitemaps') . '</h4>' . $sitemapHtml, false);
$fragment->setVar('buttons', $button, false);
echo $fragment->parse('core/page/section.php');

// Indexstatus der Stichprobe (URL Inspection)
$inspectDays = max(1, (int) $this->getConfig('inspect_interval_days', 14));
$sample = $sql->getArray(
    'SELECT i.*, u.url FROM ' . InspectionSource::table() . ' i JOIN ' . rex::getTable('vsn_seo_url') . ' u ON u.id = i.url_id
     WHERE i.domain_id = ? AND i.inspected_at >= ? ORDER BY i.verdict = \'PASS\', i.reason = \'top\', i.inspected_at DESC',
    [$domain->id, date('Y-m-d H:i:s', time() - $inspectDays * 86400)],
);
if ($sample) {
    $valid = array_filter($sample, static fn ($r) => null === $r['error']);
    $indexed = count(array_filter($valid, static fn ($r) => InspectionSource::isIndexed((string) $r['verdict'])));
    $canonicalOther = array_filter($valid, static fn ($r) => $r['google_canonical'] && $r['user_canonical'] && !PageParser::sameUrl((string) $r['google_canonical'], (string) $r['user_canonical']));
    $history = $sql->getArray('SELECT date, sample, indexed FROM ' . rex::getTable('vsn_seo_inspect_day') . ' WHERE domain_id = ? ORDER BY date DESC LIMIT 2', [$domain->id]);
    $share = $valid ? $indexed / count($valid) * 100 : null;
    $prevShare = isset($history[1]) && (int) $history[1]['sample'] > 0 ? (int) $history[1]['indexed'] / (int) $history[1]['sample'] * 100 : null;
    $inspectHtml = '<dl class="vsn-seo-kpis">'
        . '<div class="vsn-seo-kpi is-core"><dt>' . $this->i18n('inspect_share') . '</dt><dd><span class="vsn-seo-kpi-value">' . Format::dec($share) . ' %</span>' . Format::delta($share, $prevShare) . '</dd></div>'
        . '<div class="vsn-seo-kpi"><dt>' . $this->i18n('inspect_sample') . '</dt><dd><span class="vsn-seo-kpi-value">' . Format::int(count($valid)) . '</span></dd></div>'
        . '<div class="vsn-seo-kpi"><dt>' . $this->i18n('inspect_not_indexed') . '</dt><dd><span class="vsn-seo-kpi-value">' . Format::int(count($valid) - $indexed) . '</span></dd></div>'
        . '<div class="vsn-seo-kpi"><dt>' . $this->i18n('inspect_canonical_other') . '</dt><dd><span class="vsn-seo-kpi-value">' . Format::int(count($canonicalOther)) . '</span></dd></div>'
        . '</dl>';
    $rows = '';
    foreach ($sample as $r) {
        $ok = InspectionSource::isIndexed((string) $r['verdict']);
        if ($ok && !in_array($r, $canonicalOther, true)) {
            continue;
        }
        $canonical = $r['google_canonical'] && !PageParser::sameUrl((string) $r['google_canonical'], (string) $r['url'])
            ? '<br><small class="text-muted">Google-canonical: ' . rex_escape((string) $r['google_canonical']) . '</small>' : '';
        $rows .= '<tr><td class="vsn-seo-name">' . Page::urlLink((string) $r['url']) . $canonical . '</td>'
            . '<td><span class="label ' . ($ok ? 'label-success' : ($r['error'] ? 'label-default' : 'label-warning')) . '">' . rex_escape($r['error'] ? $this->i18n('error') : ((string) $r['verdict'] ?: '–')) . '</span> '
            . rex_escape((string) ($r['error'] ?: $r['coverage'])) . '</td>'
            . '<td>' . $this->i18n('inspect_reason_' . $r['reason']) . '</td>'
            . '<td>' . Format::date($r['last_crawl'] ? (string) $r['last_crawl'] : null) . '</td>'
            . '<td>' . Format::date((string) $r['inspected_at']) . '</td></tr>';
    }
    $inspectHtml .= $rows
        ? '<div class="table-responsive"><table class="table table-hover table-condensed vsn-seo-table"><thead><tr><th>URL</th><th>' . $this->i18n('inspect_state') . '</th><th>' . $this->i18n('inspect_reason') . '</th><th>' . $this->i18n('inspect_last_crawl') . '</th><th>' . $this->i18n('inspect_checked') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : rex_view::success($this->i18n('inspect_all_indexed'));
} else {
    $inspectHtml = '<p class="text-muted">' . ($domain->hasGsc() && (int) $this->getConfig('inspect_per_day', 50) > 0 ? $this->i18n('inspect_none') : $this->i18n('inspect_off')) . '</p>';
}
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('inspect_title') . ' <small>' . $this->i18n('inspect_period', $inspectDays) . '</small>', false);
$fragment->setVar('body', $inspectHtml, false);
echo $fragment->parse('core/page/section.php');

// Probleme: Übersicht je Typ
$counts = Issues::openCounts($domain->id);
$typeLinks = '<a class="btn btn-default btn-sm' . ('' === $type ? ' active' : '') . '" href="' . Page::domainUrl($domain, 'technical', $showResolved ? ['resolved' => 1] : []) . '">' . $this->i18n('all') . ' <span class="badge">' . array_sum($counts) . '</span></a> ';
foreach ($counts as $t => $count) {
    $sev = Issues::severity($t);
    $typeLinks .= '<a class="btn btn-default btn-sm vsn-seo-sev-' . $sev . ($t === $type ? ' active' : '') . '" href="' . Page::domainUrl($domain, 'technical', ['type' => $t] + ($showResolved ? ['resolved' => 1] : [])) . '">'
        . rex_escape(Issues::label($t)) . ' <span class="badge">' . Format::int($count) . '</span></a> ';
}
$toggle = '<a class="btn btn-link btn-sm" href="' . Page::domainUrl($domain, 'technical', ('' !== $type ? ['type' => $type] : []) + ($showResolved ? [] : ['resolved' => 1])) . '">'
    . ($showResolved ? $this->i18n('issues_hide_resolved') : $this->i18n('issues_show_resolved')) . '</a>';

$where = 'i.domain_id = ?';
$params = [$domain->id];
if ('' !== $type) {
    $where .= ' AND i.type = ?';
    $params[] = $type;
}
$where .= $showResolved ? ' AND (i.resolved_at IS NULL OR i.resolved_at >= ?)' : ' AND i.resolved_at IS NULL';
if ($showResolved) {
    $params[] = date('Y-m-d H:i:s', strtotime('-30 days'));
}
$issues = $sql->getArray(
    'SELECT i.*, u.url FROM ' . Issues::table() . ' i LEFT JOIN ' . rex::getTable('vsn_seo_url') . ' u ON u.id = i.url_id
     WHERE ' . $where . ' ORDER BY i.resolved_at IS NOT NULL, i.first_seen DESC, i.id DESC LIMIT 1000',
    $params,
);
// kritische zuerst
usort($issues, static fn ($a, $b) => [null !== $a['resolved_at'], -Issues::severity((string) $a['type'])] <=> [null !== $b['resolved_at'], -Issues::severity((string) $b['type'])]);

if ($issues) {
    $rows = '';
    foreach ($issues as $i) {
        $sev = Issues::severity((string) $i['type']);
        $resolved = null !== $i['resolved_at'];
        $rows .= '<tr class="' . ($resolved ? 'vsn-seo-resolved' : '') . '">'
            . '<td><span class="vsn-seo-sev vsn-seo-sev-' . $sev . '" title="' . $this->i18n('severity_' . $sev) . '"></span> ' . rex_escape(Issues::label((string) $i['type'])) . '</td>'
            . '<td class="vsn-seo-name">' . ($i['url'] ? Page::urlLink((string) $i['url']) : rex_escape($domain->host)) . ($i['detail'] ? '<br><small class="text-muted">' . rex_escape((string) $i['detail']) . '</small>' : '') . '</td>'
            . '<td>' . Format::date((string) $i['first_seen']) . '</td>'
            . '<td>' . ($resolved ? '<span class="label label-success">' . $this->i18n('resolved_at', Format::date((string) $i['resolved_at'])) . '</span>' : Format::date((string) $i['last_seen'])) . '</td></tr>';
    }
    $issueHtml = '<div class="table-responsive"><table class="table table-hover table-condensed vsn-seo-table"><thead><tr><th>' . $this->i18n('issue') . '</th><th>URL</th><th>' . $this->i18n('issue_since') . '</th><th>' . $this->i18n('issue_last_seen') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    if (1000 === count($issues)) {
        $issueHtml .= '<p class="help-block">' . $this->i18n('top_n', 1000) . '</p>';
    }
} else {
    $issueHtml = rex_view::success($this->i18n('issues_none'));
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('issues'), false);
$fragment->setVar('body', '<div class="vsn-seo-issue-types">' . $typeLinks . $toggle . '</div>' . $issueHtml, false);
echo $fragment->parse('core/page/section.php');
